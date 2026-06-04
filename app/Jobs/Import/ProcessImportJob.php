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
 * Точка входа для импорта. Получает batch-запись,
 * читает файл, разбивает на чанки по 100 строк,
 * диспатчит ImportChunkJob для каждого чанка.
 *
 * Обновляет прогресс в Cache для отображения в UI.
 */
class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;    // Без повторов — пользователь запускает заново
    public int $timeout = 120;  // 2 минуты на разбивку файла

    private const CHUNK_SIZE = 100; // Строк в одном чанке

    public function __construct(
        public readonly int $batchId
    ) {}

    public function handle(ImportFileParser $parser, ColumnMapper $mapper): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);

        Log::info("Import #{$this->batchId} started", [
            'type' => $batch->type,
            'file' => $batch->filename,
        ]);

        try {
            // Обновляем статус
            $batch->update([
                'status'     => 'processing',
                'started_at' => now(),
            ]);

            // Парсим файл
            $rows = $parser->parse($batch->filepath);

            if (empty($rows)) {
                $batch->update([
                    'status'      => 'failed',
                    'finished_at' => now(),
                ]);
                Log::warning("Import #{$this->batchId}: файл пустой или не удалось прочитать");
                return;
            }

            // Применяем маппинг колонок
            $columnMap = $batch->column_map ?? [];
            if (empty($columnMap)) {
                // Авто-определение маппинга
                $fileColumns = ! empty($rows) ? array_keys($rows[0]) : [];
                $columnMap   = $mapper->autoDetect($fileColumns, $batch->type);
                $batch->update(['column_map' => $columnMap]);
            }

            $mappedRows = $mapper->map($rows, $columnMap);

            // Обновляем total_rows
            $totalRows   = count($mappedRows);
            $chunks      = array_chunk($mappedRows, self::CHUNK_SIZE);
            $totalChunks = count($chunks);

            $batch->update(['total_rows' => $totalRows]);

            // Инициализируем прогресс в кэше
            Cache::put($batch->progressCacheKey(), [
                'total'     => $totalChunks,
                'processed' => 0,
                'percent'   => 0,
                'status'    => 'processing',
            ], 7200);

            Log::info("Import #{$this->batchId}: {$totalRows} строк → {$totalChunks} чанков");

            // Диспатчим чанки с задержкой, чтобы не перегружать очередь
            foreach ($chunks as $chunkIndex => $chunk) {
                ImportChunkJob::dispatch(
                    batchId:    $this->batchId,
                    chunk:      $chunk,
                    chunkIndex: $chunkIndex,
                    totalChunks: $totalChunks
                )
                ->onQueue('imports')
                ->delay(now()->addSeconds($chunkIndex)); // 1 сек между чанками
            }

            // FinalizeImportJob запускается после всех чанков
            FinalizeImportJob::dispatch($this->batchId)
                ->onQueue('imports-low')
                ->delay(now()->addSeconds($totalChunks + 10));

        } catch (\Throwable $e) {
            $batch->update([
                'status'      => 'failed',
                'finished_at' => now(),
            ]);

            Cache::put($batch->progressCacheKey(), [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ], 3600);

            Log::error("Import #{$this->batchId} failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        ImportBatch::where('id', $this->batchId)->update([
            'status'      => 'failed',
            'finished_at' => now(),
        ]);

        Log::error("ProcessImportJob #{$this->batchId} failed permanently", [
            'error' => $e->getMessage(),
        ]);
    }
}
