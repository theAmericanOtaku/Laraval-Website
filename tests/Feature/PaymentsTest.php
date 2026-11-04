<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_page_lists_products(): void
    {
        $this->get('/')->assertOk()->assertSee('PHP Ebook')->assertSee('Pay with Stripe');
    }

    public function test_paypal_create_and_capture_flow(): void
    {
        config(['services.paypal.client_id' => 'id', 'services.paypal.secret' => 'secret']);

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-token']),
            '*/v2/checkout/orders/PP123/capture' => Http::response(['id' => 'PP123', 'status' => 'COMPLETED']),
            '*/v2/checkout/orders' => Http::response([
                'id' => 'PP123',
                'status' => 'PAYER_ACTION_REQUIRED',
                'links' => [['rel' => 'payer-action', 'href' => 'https://sandbox.paypal.com/approve?token=PP123']],
            ]),
        ]);

        $this->post('/checkout/paypal/ebook')->assertRedirect('https://sandbox.paypal.com/approve?token=PP123');
        $this->assertDatabaseHas('orders', ['provider_ref' => 'PP123', 'amount' => 1500, 'status' => 'pending']);

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v2/checkout/orders')
            && $req['purchase_units'][0]['amount']['value'] === '15.00');

        $order = Order::first();
        $this->get('/checkout/paypal/return?token=PP123')->assertRedirect(route('orders.show', $order));
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_stripe_webhook_marks_order_paid(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $order = Order::create(['provider' => 'stripe', 'provider_ref' => 'cs_test_1', 'product' => 'coffee', 'amount' => 300, 'currency' => 'usd']);

        $payload = json_encode([
            'id' => 'evt_1', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'payment_status' => 'paid']],
        ]);
        $t = time();
        $sig = "t=$t,v1=".hash_hmac('sha256', "$t.$payload", 'whsec_test');

        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_stripe_webhook_rejects_bad_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad'], '{}')->assertStatus(400);
    }

    public function test_cancel_marks_pending_order_cancelled(): void
    {
        $order = Order::create(['provider' => 'stripe', 'product' => 'coffee', 'amount' => 300, 'currency' => 'usd']);
        $this->get("/checkout/cancel/{$order->id}")->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
