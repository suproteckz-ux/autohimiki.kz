<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\OzonProductLink;
use App\Services\Ozon\OzonExporter;
use Illuminate\Console\Command;

class OzonSyncStocks extends Command
{
    protected $signature = 'ozon:sync-stocks {--category= : Exact local slug} {--sku=} {--dry-run}';

    protected $description = 'Sync Product.quantity only for manually confirmed published links';

    public function handle(OzonExporter $exporter): int
    {
        $category = Category::where('slug', $this->option('category'))->first();
        if (! $category) {
            $this->error('Exact --category slug required');

            return self::FAILURE;
        }
        // Include inactive linked products too: clearing quantity=0 must remain possible.
        $query = $category->products()->orderBy('id');
        if ($this->option('sku') !== null) {
            $query->where('sku', $this->option('sku'));
        }
        $updated = $skipped = $failed = 0;
        foreach ($query->cursor() as $product) {
            $link = OzonProductLink::where('local_product_id', $product->id)->first();
            try {
                if ($this->option('dry-run')) {
                    $this->line("SKU {$product->sku}: quantity=".max(0, (int) $product->quantity).'; status='.($link?->status ?? 'unlinked'));
                    $skipped++;
                } elseif (! $link || ! $exporter->stock($product, $link)) {
                    $skipped++;
                } else {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $message = $exporter->safeError($e);
                $link?->update(['last_error' => $message]);
                $this->error("SKU {$product->sku}: {$message}");
            }
        }
        $this->line("Stock updated: {$updated}; Skipped: {$skipped}; Failed: {$failed}; Price updates: 0");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
