<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripe;
use Tests\TestCase;

class StripeCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'rk_test_fake']);
    }

    protected function tearDown(): void
    {
        FakeStripe::uninstall();
        parent::tearDown();
    }

    /** A Stripe price list response holding the given prices. */
    private static function priceList(array $prices = []): array
    {
        return ['object' => 'list', 'data' => $prices, 'has_more' => false, 'url' => '/v1/prices'];
    }

    private static function price(string $id, int $amount, string $product = 'prod_1'): array
    {
        return ['id' => $id, 'object' => 'price', 'unit_amount' => $amount, 'currency' => 'usd', 'product' => $product, 'active' => true];
    }

    public function test_checkout_uses_the_stripe_price_and_tags_the_session(): void
    {
        $fake = FakeStripe::install([
            'GET /v1/prices' => self::priceList([self::price('price_ebook', 1500)]),
            'POST /v1/checkout/sessions' => ['id' => 'cs_test_9', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_9'],
        ]);

        $this->post('/checkout/stripe/ebook')->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_9');

        $this->assertSame(['payments_lab_ebook'], $fake->sent('GET /v1/prices')[0]['params']['lookup_keys']);

        $params = $fake->sent('POST /v1/checkout/sessions')[0]['params'];
        $this->assertSame([['price' => 'price_ebook', 'quantity' => 1]], $params['line_items']);
        $this->assertSame('payments-lab-hosted-checkout-qhvtmzrk', $params['integration_identifier']);
        $this->assertArrayNotHasKey('payment_method_types', $params); // Dynamic payment methods.

        $this->assertDatabaseHas('orders', ['provider_ref' => 'cs_test_9', 'amount' => 1500, 'status' => 'pending']);
    }

    public function test_checkout_explains_what_to_do_when_products_are_not_synced(): void
    {
        $fake = FakeStripe::install(['GET /v1/prices' => self::priceList()]);

        $this->post('/checkout/stripe/ebook')
            ->assertRedirect(route('shop'))
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'php artisan stripe:sync-products'));

        $this->assertSame(0, Order::count());
        $this->assertSame([], $fake->sent('POST /v1/checkout/sessions'));
    }

    public function test_checkout_refuses_a_stripe_price_that_no_longer_matches_the_shop(): void
    {
        FakeStripe::install(['GET /v1/prices' => self::priceList([self::price('price_old', 999)])]);

        $this->post('/checkout/stripe/ebook')->assertRedirect(route('shop'))->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }

    // Note: the Stripe SDK sends booleans as the strings 'true' / 'false', as Stripe's API expects.
    public function test_sync_creates_missing_products_and_prices(): void
    {
        $fake = FakeStripe::install([
            'GET /v1/prices' => self::priceList(),
            'POST /v1/products' => fn ($p) => ['id' => 'prod_'.$p['metadata']['payments_lab_key'], 'object' => 'product'],
            'POST /v1/prices' => fn ($p) => self::price('price_'.$p['lookup_key'], $p['unit_amount'], $p['product']),
            'POST /v1/products/prod_coffee' => ['id' => 'prod_coffee', 'object' => 'product'],
            'POST /v1/products/prod_ebook' => ['id' => 'prod_ebook', 'object' => 'product'],
            'POST /v1/products/prod_tshirt' => ['id' => 'prod_tshirt', 'object' => 'product'],
        ]);

        $this->artisan('stripe:sync-products')->assertSuccessful()->expectsOutputToContain('created');

        $this->assertCount(3, $fake->sent('POST /v1/products'));
        $ebookPrice = collect($fake->sent('POST /v1/prices'))->firstWhere('params.lookup_key', 'payments_lab_ebook')['params'];
        $this->assertSame(['product' => 'prod_ebook', 'unit_amount' => 1500, 'currency' => 'usd', 'lookup_key' => 'payments_lab_ebook', 'transfer_lookup_key' => 'true'], $ebookPrice);
        $this->assertSame('price_payments_lab_ebook', $fake->sent('POST /v1/products/prod_ebook')[0]['params']['default_price']);
    }

    public function test_sync_leaves_matching_prices_alone(): void
    {
        $fake = FakeStripe::install([
            'GET /v1/prices' => fn ($p) => self::priceList([self::price('price_x', config('shop.products.'.substr($p['lookup_keys'][0], 13).'.price'))]),
        ]);

        $this->artisan('stripe:sync-products')->assertSuccessful()->expectsOutputToContain('unchanged');
        $this->assertCount(3, $fake->requests); // Only the three lookups, nothing created.
    }

    public function test_sync_replaces_a_price_when_the_amount_changes(): void
    {
        config(['shop.products' => ['ebook' => config('shop.products.ebook')]]); // Just one product.
        $fake = FakeStripe::install([
            'GET /v1/prices' => self::priceList([self::price('price_old', 999, 'prod_ebook')]),
            'POST /v1/prices' => self::price('price_new', 1500, 'prod_ebook'),
            'POST /v1/products/prod_ebook' => ['id' => 'prod_ebook', 'object' => 'product'],
            'POST /v1/prices/price_old' => self::price('price_old', 999, 'prod_ebook'),
        ]);

        $this->artisan('stripe:sync-products')->assertSuccessful()->expectsOutputToContain('updated');

        $this->assertSame([], $fake->sent('POST /v1/products')); // Same product, no new one.
        $this->assertSame('prod_ebook', $fake->sent('POST /v1/prices')[0]['params']['product']);
        $this->assertSame('price_new', $fake->sent('POST /v1/products/prod_ebook')[0]['params']['default_price']);
        $this->assertSame(['active' => 'false'], $fake->sent('POST /v1/prices/price_old')[0]['params']);
    }

    public function test_sync_explains_a_missing_key(): void
    {
        config(['services.stripe.secret' => null]);
        $this->artisan('stripe:sync-products')->assertFailed()->expectsOutputToContain('STRIPE_SECRET');
    }
}
