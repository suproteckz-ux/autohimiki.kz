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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ImportChunkJob
 *
 * Обрабатывает один чанк (100 строк) импорта.
 * Выбирает правильный сервис по типу импорта batch.
 * Обновляет прогресс в кэше.
 */
class ImportChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;   // 3 попытки при ошибке очереди
    public int $timeout = 120; // 2 минуты на чанк
    public int $backoff = 30;  // 30 сек между попытками

    public function __construct(
        public readonly int   $batchId,
        public readonly array $chunk,
        public readonly int   $chunkIndex,
        public readonly int   $totalChunks
    ) {}

    public function handle(): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);

        // Если batch уже failed — не обрабатываем
        if ($batch->status === 'failed') {
            return;
        }

        try {
            if ($batch->type === 'prices_only') {
                // ── Режим 1С: только цена + остаток ──────────────
                $updater = new PriceStockUpdater($batch);
                $updater->processChunk($this->chunk);

            } else {
                // ── Полный импорт ─────────────────────────────────
                $importer = new FullProductImporter($batch);
                $result   = $importer->processChunk($this->chunk);

                // Отправляем изображения на загрузку
                foreach ($result['image_queue'] ?? [] as $item) {
                    DownloadImageJob::dispatch(
                        productId: $item['product_id'],
                        imageUrl:  $item['image_url'],
                        batchId:   $item['batch_id']
                    )->onQueue('imports-low');
                }
            }

        } catch (\Throwable $e) {
            Log::error("ImportChunkJob #{$this->batchId} chunk {$this->chunkIndex} error", [
                'error' => $e->getMessage(),
            ]);
            throw $e; // Позволяем очереди повторить попытку
        } finally {
            // Обновляем прогресс в кэше независимо от результата
            $this->updateProgress();
        }
    }

    /**
     * Обновляет прогресс в кэше.
     */
    private function updateProgress(): void
    {
        $cacheKey = "import_progress_{$this->batchId}";
        $current  = Cache::get($cacheKey, [
            'total'     => $this->totalChunks,
            'processed' => 0,
            'percent'   => 0,
            'status'    => 'processing',
        ]);

        $processed = ($current['processed'] ?? 0) + 1;
        $percent   = $this->totalChunks > 0
            ? (int) round($processed / $this->totalChunks * 100)
            : 100;

        Cache::put($cacheKey, [
            'total'     => $this->totalChunks,
            'processed' => $processed,
            'percent'   => min($percent, 99), // 100% только после FinalizeImportJob
            'status'    => 'processing',
        ], 7200);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ImportChunkJob #{$this->batchId} chunk {$this->chunkIndex} permanently failed", [
            'error' => $e->getMessage(),
        ]);
    }
}
