<?php

namespace App\Jobs\Import;

use App\Models\ImportBatch;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportFileParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ProcessImportJob
 *
 * Читает файл чанками по 100 строк и диспатчит ImportChunkJob.
 *
 * Использует ImportFileParser → FastExcel (без ext-gd).
 */
class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    private const CHUNK_SIZE = 100;

    public function __construct(
        public readonly int $batchId
    ) {}

    public function handle(ImportFileParser $parser, ColumnMapper $mapper): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);

        // Проверка на параллельный запуск
        $lock = Cache::lock('import_running', 600);
        if (! $lock->get()) {
            $this->release(300);
            Log::info("Import #{$this->batchId}: отложен, другой импорт выполняется");
            return;
        }

        try {
            $batch->update(['status' => 'processing', 'started_at' => now()]);

            // Парсим файл (FastExcel — без ext-gd)
            $rows = $parser->parse($batch->filepath);

            if (empty($rows)) {
                $batch->update(['status' => 'failed', 'finished_at' => now()]);
                Log::warning("Import #{$this->batchId}: файл пустой");
                return;
            }

            // Маппинг колонок
            $columnMap = $batch->column_map ?? [];
            if (empty($columnMap)) {
                $fileColumns = array_keys($rows[0]);
                $columnMap   = $mapper->autoDetect($fileColumns, $batch->type);
                $batch->update(['column_map' => $columnMap]);
            }

            $mappedRows  = $mapper->map($rows, $columnMap);

            // Дедупликация SKU
            $mappedRows  = $this->deduplicateBySkus($mappedRows);

            $totalRows   = count($mappedRows);
            $chunks      = array_chunk($mappedRows, self::CHUNK_SIZE);
            $totalChunks = count($chunks);

            $batch->update([
                'total_rows'   => $totalRows,
                'total_chunks' => $totalChunks,
            ]);

            Cache::put($batch->progressCacheKey(), [
                'total'     => $totalChunks,
                'processed' => 0,
                'percent'   => 0,
                'status'    => 'processing',
            ], 7200);

            foreach ($chunks as $index => $chunk) {
                ImportChunkJob::dispatch(
                    batchId:    $this->batchId,
                    chunk:      $chunk,
                    chunkIndex: $index,
                    totalChunks: $totalChunks
                )->onQueue('imports');
            }

            FinalizeImportJob::dispatch($this->batchId)
                ->onQueue('imports-low')
                ->delay(now()->addSeconds($totalChunks + 10));

        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'finished_at' => now()]);
            Cache::put($batch->progressCacheKey(), ['status' => 'failed', 'error' => $e->getMessage()], 3600);
            Log::error("Import #{$this->batchId} failed", ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function deduplicateBySkus(array $rows): array
    {
        $seen  = [];
        $dupes = 0;

        foreach ($rows as $row) {
            $sku = trim($row['sku'] ?? '');
            if ($sku) {
                $seen[$sku] = $row; // последнее вхождение побеждает
            } else {
                $seen[] = $row; // строки без SKU добавляем как есть
            }
        }

        if ($dupes > 0) {
            Log::warning("Import #{$this->batchId}: обнаружено {$dupes} дублирующихся SKU");
        }

        return array_values($seen);
    }

    public function failed(\Throwable $e): void
    {
        ImportBatch::where('id', $this->batchId)
            ->update(['status' => 'failed', 'finished_at' => now()]);
    }
}
