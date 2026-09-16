<?php

namespace App\Console\Commands;

use App\Services\ProductUrls\ProductUrlMigration;
use Illuminate\Console\Command;

class ClearProductUrlCache extends Command
{
    protected $signature = 'products:url-cache-clear';

    protected $description = 'Clear only product/homepage/sitemap and redirect caches after URL changes';

    public function handle(): int
    {
        ProductUrlMigration::invalidate();
        $this->info('Product URL caches cleared.');

        return self::SUCCESS;
    }
}
