<?php

namespace App\Jobs\Import;

use App\Models\ImportBatch;
use App\Services\CacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $batchId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $batch = ImportBatch::whereKey($this->batchId)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'processing') {
                return;
            }
            $receipts = DB::table('import_chunks')->where('import_batch_id', $batch->id)->count();
            if ((int) $batch->processed_chunks !== (int) $batch->total_chunks || $receipts !== (int) $batch->total_chunks) {
                throw new \RuntimeException('Cannot finalize incomplete import chunks.');
            }
            $summary = ['status' => 'done', 'finished_at' => now()];
            if ($batch->source === 'onec') {
                // Retain the existing admin preview; the unabridged rollback journal is relational.
                $prices = $stocks = [];
                $changes = DB::table('import_commercial_rows as r')->leftJoin('products as p', 'p.id', '=', 'r.product_id')
                    ->where('r.import_batch_id', $batch->id)->where('r.status', 'updated')
                    ->orderBy('r.id')->limit(1000)->get(['r.*', 'p.name']);
                foreach ($changes as $change) {
                    $before = json_decode($change->before_values, true);
                    $after = json_decode($change->after_values, true);
                    $base = ['sku' => $change->sku, 'name' => $change->name];
                    if ($before['price'] !== $after['price']) {
                        $prices[] = $base + ['old' => $before['price'], 'new' => $after['price'],
                            'diff' => (float) $after['price'] - (float) $before['price']];
                    }
                    if ($before['quantity'] !== $after['quantity'] || $before['in_stock'] !== $after['in_stock']) {
                        $stocks[] = $base + ['old' => $before['quantity'], 'new' => $after['quantity'], 'in_stock' => $after['in_stock']];
                    }
                }
                $summary += ['price_changes' => $prices, 'stock_changes' => $stocks];
            }
            $batch->update($summary);
            DB::afterCommit(function () use ($batch) {
                try {
                    CacheService::forgetAll();
                    Cache::put($batch->progressCacheKey(), ['status' => 'done', 'percent' => 100,
                        'total' => $batch->total_chunks, 'processed' => $batch->processed_chunks], 3600);
                } catch (\Throwable $e) {
                    Log::error('Import committed; cache invalidation failed', ['batch' => $batch->id, 'error' => $e->getMessage()]);
                }
            });
        });
    }
}
