<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayPal Orders v2 flow (plain REST calls, no SDK, so you can see every step):
 *  1. Get an OAuth access token using client id + secret.
 *  2. POST /v2/checkout/orders  -> returns an "approve" link.
 *  3. Redirect the buyer to that link; they log in with a SANDBOX personal account.
 *  4. PayPal redirects back to return_url?token=ORDER_ID.
 *  5. POST /v2/checkout/orders/{id}/capture to actually take the money.
 */
class PayPalController extends Controller
{
    private function paypal(): PendingRequest
    {
        $cfg = config('services.paypal');
        abort_unless($cfg['client_id'] && $cfg['secret'], 500, 'Set PAYPAL_CLIENT_ID and PAYPAL_SECRET in .env');

        $token = Http::asForm()
            ->withBasicAuth($cfg['client_id'], $cfg['secret'])
            ->post($cfg['base_url'].'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw()
            ->json('access_token');

        return Http::baseUrl($cfg['base_url'])->withToken($token)->acceptJson();
    }

    public function checkout(string $productKey)
    {
        $product = config("shop.products.$productKey") ?? abort(404);
        $currency = strtoupper(config('shop.currency'));

        $order = Order::create([
            'provider' => 'paypal',
            'product' => $productKey,
            'amount' => $product['price'],
            'currency' => $currency,
        ]);

        $response = $this->paypal()->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $order->id,
                'description' => $product['name'],
                'amount' => [
                    'currency_code' => $currency,
                    'value' => number_format($product['price'] / 100, 2, '.', ''), // PayPal wants "15.00"
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'return_url' => route('paypal.return'),
                        'cancel_url' => route('checkout.cancel', $order),
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ],
        ])->throw()->json();

        $order->update(['provider_ref' => $response['id'], 'payload' => $response]);

        $approveUrl = collect($response['links'])->firstWhere('rel', 'payer-action')['href'];

        return redirect()->away($approveUrl);
    }

    public function return(Request $request)
    {
        $order = Order::where('provider', 'paypal')
            ->where('provider_ref', $request->query('token'))
            ->firstOrFail();

        $capture = $this->paypal()
            ->withBody('{}', 'application/json')
            ->post("/v2/checkout/orders/{$order->provider_ref}/capture")
            ->json();

        $order->update([
            'status' => ($capture['status'] ?? null) === 'COMPLETED' ? 'paid' : 'failed',
            'payload' => $capture,
        ]);

        return redirect()->route('orders.show', $order);
    }
}
