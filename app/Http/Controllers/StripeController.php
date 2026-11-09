<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\StripeCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe Checkout flow:
 *  0. Once: `php artisan stripe:sync-products` creates each product and its price in Stripe.
 *  1. We create a Checkout Session for that price on our server (the API key never leaves it).
 *  2. We redirect the customer to Stripe's hosted payment page.
 *  3. Stripe redirects back to success_url / cancel_url. This page only SHOWS the result.
 *  4. Stripe POSTs a signed webhook. The webhook is the ONLY place an order is marked
 *     paid or failed, because the customer might close the tab before reaching step 3.
 */
class StripeController extends Controller
{
    /**
     * Labels every session this flow creates, so you can find them in the Stripe Dashboard
     * and compare checkout flows. The random suffix keeps it unique to this app.
     */
    private const INTEGRATION_IDENTIFIER = 'payments-lab-hosted-checkout-qhvtmzrk';

    private function stripe(): StripeClient
    {
        abort_unless(config('services.stripe.secret'), 500, 'Set STRIPE_SECRET in .env');

        return StripeCatalog::client();
    }

    public function checkout(string $productKey)
    {
        $product = config("shop.products.$productKey") ?? abort(404);
        $stripe = $this->stripe();

        // Use the Product and Price stored in Stripe (created by `php artisan stripe:sync-products`).
        try {
            $priceId = (new StripeCatalog($stripe))->priceIdFor($productKey);
        } catch (RuntimeException $e) {
            return redirect()->route('shop')->with('error', $e->getMessage());
        }

        $order = Order::create([
            'provider' => 'stripe',
            'product' => $productKey,
            'amount' => $product['price'],
            'currency' => config('shop.currency'),
        ]);

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'client_reference_id' => (string) $order->id,
            'metadata' => ['order_id' => $order->id],
            'integration_identifier' => self::INTEGRATION_IDENTIFIER,
            // Stripe replaces {CHECKOUT_SESSION_ID} with the real id on redirect.
            'success_url' => route('stripe.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('checkout.cancel', $order),
        ]);

        $order->update(['provider_ref' => $session->id, 'payload' => $session->toArray()]);

        return redirect()->away($session->url);
    }

    /**
     * Where Stripe sends the customer after paying. Read-only: it asks Stripe for the
     * session's status to show the customer, but never changes the order. That is the
     * webhook's job.
     */
    public function success(Request $request)
    {
        $session = $this->stripe()->checkout->sessions->retrieve($request->query('session_id'));
        $order = Order::where('provider', 'stripe')->where('provider_ref', $session->id)->firstOrFail();

        $message = $session->payment_status === 'unpaid'
            ? 'Stripe has not received the money yet. Some payment methods take a while to settle.'
            : 'Stripe says this payment went through.';

        if ($order->status === 'pending') {
            $message .= ' This order is marked paid once the webhook arrives Running locally? Keep stripe listen running so the webhook can reach this site.';
        }

        return redirect()->route('orders.show', [$order, 'confirming' => 1])->with('notice', $message);
    }

    public function webhook(Request $request)
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (\UnexpectedValueException|SignatureVerificationException $e) {
            return response('Invalid signature', 400);
        }

        Log::info('Stripe webhook: '.$event->type);

        $session = $event->data->object;

        match ($event->type) {
            // Card payments arrive here already paid. Delayed methods (e.g. bank debits)
            // arrive here 'unpaid' and are settled later by one of the two events below.
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $session->payment_status !== 'unpaid'
                ? $this->markOrder($session, 'paid')
                : $this->markOrder($session, 'pending'),
            'checkout.session.async_payment_failed' => $this->markOrder($session, 'failed'),
            default => null, // Ignore events we don't use.
        };

        // Always answer 2xx quickly, or Stripe retries the event.
        return response('ok');
    }

    private function markOrder(object $session, string $status): void
    {
        $order = Order::where('provider', 'stripe')->where('provider_ref', $session->id)->first();

        if (! $order) {
            Log::warning("Stripe webhook for unknown session {$session->id}");

            return;
        }

        // Events can arrive more than once and out of order: never downgrade a paid order.
        if ($order->status === 'paid') {
            return;
        }

        $order->update(['status' => $status, 'payload' => $session->toArray()]);
    }
}
