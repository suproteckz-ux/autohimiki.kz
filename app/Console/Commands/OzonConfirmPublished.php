<?php

namespace App\Console\Commands;

use App\Models\OzonProductLink;
use Illuminate\Console\Command;

class OzonConfirmPublished extends Command
{
    protected $signature = 'ozon:confirm-published {sku} {--confirmed-in-cabinet : Operator has manually published and verified this exact SKU}';

    protected $description = 'Record manual publication and enable later stock sync; does not publish on Ozon';

    public function handle(): int
    {
        $link = OzonProductLink::where('offer_id', $this->argument('sku'))->first();
        if (! $this->option('confirmed-in-cabinet') || ! $link?->ozon_product_id || ! in_array($link->status, ['requires_manual_review', 'published'], true)) {
            $this->error('Verified Ozon product ID and --confirmed-in-cabinet required');

            return self::FAILURE;
        }
        $link->update(['status' => 'published', 'publication_confirmed_at' => now()]);
        $this->info('Manual publication recorded; subsequent stock sync can make this product available for sale.');

        return self::SUCCESS;
    }
}
