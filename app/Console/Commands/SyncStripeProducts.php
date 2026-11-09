<?php

namespace App\Console\Commands;

use App\Support\StripeCatalog;
use Illuminate\Console\Command;

class SyncStripeProducts extends Command
{
    protected $signature = 'stripe:sync-products';

    protected $description = 'Create or update the Stripe Products and Prices for the items in config/shop.php';

    public function handle(): int
    {
        try {
            $catalog = new StripeCatalog(StripeCatalog::client());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = [];

        foreach (config('shop.products') as $key => $product) {
            $rows[] = [$product['name'], StripeCatalog::lookupKey($key), $catalog->sync($key)];
        }

        $this->table(['Product', 'Lookup key', 'Result'], $rows);
        $this->info('Done. You can see these under Product catalogue in your Stripe Dashboard.');

        return self::SUCCESS;
    }
}
