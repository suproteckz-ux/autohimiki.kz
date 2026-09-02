<?php

namespace App\Jobs\Import;

use App\Models\ImportBatch;
use App\Services\Import\FullProductImporter;
use App\Services\Import\PriceStockUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ImportChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $batchId, public readonly array $chunk,
        public readonly int $chunkIndex, public readonly int $totalChunks) {}

    public function handle(): void
    {
        // Reject obsolete independently queued chunks after deployment.
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Run chunks through ProcessImportJob/CommercialImportRunner, never independently.');
        }
        $batch = ImportBatch::whereKey($this->batchId)->lockForUpdate()->firstOrFail();
        if ($batch->status !== 'processing') {
            return;
        }
        if (DB::table('import_chunks')->where('import_batch_id', $this->batchId)->where('chunk_index', $this->chunkIndex)->exists()) {
            return;
        }
        if ($this->chunkIndex < 0 || $this->chunkIndex >= $batch->total_chunks || $this->totalChunks !== (int) $batch->total_chunks) {
            throw new \LogicException('Invalid import chunk index/count.');
        }
        if ($batch->type === 'prices_only') {
            (new PriceStockUpdater($batch))->processChunk($this->chunk);
        } else {
            $result = (new FullProductImporter($batch))->processChunk($this->chunk);
            foreach ($result['image_queue'] ?? [] as $item) {
                DB::afterCommit(fn () => DownloadImageJob::dispatch($item['product_id'], $item['image_url'], $item['batch_id'])->onQueue('imports-low'));
            }
        }
        DB::table('import_chunks')->insert(['import_batch_id' => $this->batchId, 'chunk_index' => $this->chunkIndex]);
        DB::table('import_batches')->where('id', $this->batchId)->increment('processed_chunks');
    }
}
