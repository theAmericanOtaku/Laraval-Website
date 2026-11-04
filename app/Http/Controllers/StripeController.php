<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe Checkout flow:
 *  1. We create a Checkout Session on our server (secret key never leaves the server).
 *  2. We redirect the customer to Stripe's hosted payment page.
 *  3. Stripe redirects back to success_url / cancel_url.
 *  4. (Recommended) Stripe also POSTs a webhook — the reliable source of truth.
 */
class StripeController extends Controller
{
    private function stripe(): StripeClient
    {
        abort_unless(config('services.stripe.secret'), 500, 'Set STRIPE_SECRET in .env');

        return new StripeClient(config('services.stripe.secret'));
    }

    public function checkout(string $productKey)
    {
        $product = config("shop.products.$productKey") ?? abort(404);
        $currency = config('shop.currency');

        $order = Order::create([
            'provider' => 'stripe',
            'product' => $productKey,
            'amount' => $product['price'],
            'currency' => $currency,
        ]);

        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $product['price'],
                    'product_data' => ['name' => $product['name']],
                ],
            ]],
            'client_reference_id' => (string) $order->id,
            'metadata' => ['order_id' => $order->id],
            // Stripe replaces {CHECKOUT_SESSION_ID} with the real id on redirect.
            'success_url' => route('stripe.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('checkout.cancel', $order),
        ]);

        $order->update(['provider_ref' => $session->id, 'payload' => $session->toArray()]);

        return redirect()->away($session->url);
    }

    public function success(Request $request)
    {
        // Never trust the redirect alone — fetch the session from Stripe to confirm payment.
        $session = $this->stripe()->checkout->sessions->retrieve($request->query('session_id'));
        $order = Order::where('provider_ref', $session->id)->firstOrFail();

        if ($session->payment_status === 'paid') {
            $order->update(['status' => 'paid', 'payload' => $session->toArray()]);
        }

        return redirect()->route('orders.show', $order);
    }

    public function webhook(Request $request)
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                config('services.stripe.webhook_secret'),
            );
        } catch (\UnexpectedValueException|SignatureVerificationException $e) {
            return response('Invalid signature', 400);
        }

        Log::info('Stripe webhook: '.$event->type);

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            Order::where('provider_ref', $session->id)->update([
                'status' => $session->payment_status === 'paid' ? 'paid' : 'pending',
                'payload' => json_encode($session->toArray()),
            ]);
        }

        return response('ok');
    }
}
