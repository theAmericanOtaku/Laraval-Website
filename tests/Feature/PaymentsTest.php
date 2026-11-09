<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStripe;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FakeStripe::uninstall();
        parent::tearDown();
    }

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

    private function stripeOrder(string $status = 'pending'): Order
    {
        return Order::create(['provider' => 'stripe', 'provider_ref' => 'cs_test_1', 'product' => 'coffee', 'amount' => 300, 'currency' => 'usd', 'status' => $status]);
    }

    /** Send a webhook signed exactly the way Stripe signs it. */
    private function sendStripeEvent(string $type, string $paymentStatus)
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $payload = json_encode([
            'id' => 'evt_1', 'object' => 'event', 'type' => $type,
            'data' => ['object' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'payment_status' => $paymentStatus]],
        ]);
        $t = time();
        $sig = "t=$t,v1=".hash_hmac('sha256', "$t.$payload", 'whsec_test');

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_completed_webhook_marks_card_payment_paid(): void
    {
        $order = $this->stripeOrder();
        $this->sendStripeEvent('checkout.session.completed', 'paid')->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_completed_webhook_for_delayed_payment_stays_pending(): void
    {
        $order = $this->stripeOrder();
        $this->sendStripeEvent('checkout.session.completed', 'unpaid')->assertOk();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_async_payment_succeeded_marks_order_paid(): void
    {
        $order = $this->stripeOrder();
        $this->sendStripeEvent('checkout.session.async_payment_succeeded', 'paid')->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_async_payment_failed_marks_order_failed(): void
    {
        $order = $this->stripeOrder();
        $this->sendStripeEvent('checkout.session.async_payment_failed', 'unpaid')->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
    }

    public function test_late_or_duplicate_event_never_downgrades_a_paid_order(): void
    {
        $order = $this->stripeOrder('paid');
        $this->sendStripeEvent('checkout.session.completed', 'unpaid')->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_unused_event_types_are_acknowledged_and_ignored(): void
    {
        $order = $this->stripeOrder();
        $this->sendStripeEvent('checkout.session.expired', 'unpaid')->assertOk();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_success_page_shows_status_but_does_not_mark_order_paid(): void
    {
        config(['services.stripe.secret' => 'rk_test_fake']);
        $order = $this->stripeOrder();

        FakeStripe::install([
            'GET /v1/checkout/sessions/cs_test_1' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'payment_status' => 'paid'],
        ]);

        $this->get('/checkout/stripe/success?session_id=cs_test_1')
            ->assertRedirect(route('orders.show', [$order, 'confirming' => 1]))
            ->assertSessionHas('notice');

        $this->assertSame('pending', $order->fresh()->status);
        $this->get(route('orders.show', [$order, 'confirming' => 1]))->assertSee('http-equiv="refresh"', false);
    }

    public function test_stripe_webhook_rejects_bad_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $order = $this->stripeOrder();
        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad'], '{}')->assertStatus(400);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_cancel_marks_pending_order_cancelled(): void
    {
        $order = Order::create(['provider' => 'stripe', 'product' => 'coffee', 'amount' => 300, 'currency' => 'usd']);
        $this->get("/checkout/cancel/{$order->id}")->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
