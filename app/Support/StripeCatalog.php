<?php

namespace App\Support;

use RuntimeException;
use Stripe\Price;
use Stripe\StripeClient;

/**
 * Keeps the products in config/shop.php in step with Products and Prices in Stripe.
 *
 * Each Stripe Price gets a "lookup key" (for example payments_lab_coffee). That lets
 * the app find the right price by name, so you never copy price IDs (price_...) around,
 * and every Stripe account that runs this app gets its own prices.
 */
class StripeCatalog
{
    public function __construct(private StripeClient $stripe) {}

    /** Build a client from STRIPE_SECRET, failing with a helpful message if it's missing. */
    public static function client(): StripeClient
    {
        $secret = config('services.stripe.secret');

        if (blank($secret)) {
            throw new RuntimeException('Set STRIPE_SECRET in .env');
        }

        return new StripeClient($secret);
    }

    public static function lookupKey(string $productKey): string
    {
        return 'payments_lab_'.$productKey;
    }

    /** The active Stripe Price for a product, or null if it hasn't been synced yet. */
    public function findPrice(string $productKey): ?Price
    {
        $prices = $this->stripe->prices->all([
            'lookup_keys' => [self::lookupKey($productKey)],
            'active' => true,
            'limit' => 1,
        ]);

        return $prices->data[0] ?? null;
    }

    /**
     * The price ID to put in a Checkout Session. Refuses to use a Stripe price that no
     * longer matches config/shop.php, so the order we save matches what Stripe charges.
     */
    public function priceIdFor(string $productKey): string
    {
        $product = config("shop.products.$productKey");
        $price = $this->findPrice($productKey);

        if (! $price || ! $this->matches($price, $product)) {
            throw new RuntimeException(
                "The Stripe price for \"{$product['name']}\" is missing or out of date. Run: php artisan stripe:sync-products"
            );
        }

        return $price->id;
    }

    /**
     * Create or update a Stripe Product and Price for one product in config/shop.php.
     *
     * @return string 'created', 'updated' or 'unchanged'
     */
    public function sync(string $productKey): string
    {
        $product = config("shop.products.$productKey");
        $existing = $this->findPrice($productKey);

        if ($existing && $this->matches($existing, $product)) {
            return 'unchanged';
        }

        // Prices can't be edited in Stripe, so a new amount means a new Price on the same Product.
        $stripeProductId = $existing
            ? $existing->product
            : $this->stripe->products->create([
                'name' => $product['name'],
                'description' => $product['description'],
                'metadata' => ['payments_lab_key' => $productKey],
            ])->id;

        $price = $this->stripe->prices->create([
            'product' => $stripeProductId,
            'unit_amount' => $product['price'],
            'currency' => config('shop.currency'),
            'lookup_key' => self::lookupKey($productKey),
            'transfer_lookup_key' => true, // Move the lookup key off the old price, if any.
        ]);

        $this->stripe->products->update($stripeProductId, ['default_price' => $price->id]);

        if ($existing) {
            $this->stripe->prices->update($existing->id, ['active' => false]);
        }

        return $existing ? 'updated' : 'created';
    }

    private function matches(Price $price, array $product): bool
    {
        return $price->unit_amount === $product['price']
            && $price->currency === strtolower(config('shop.currency'));
    }
}
