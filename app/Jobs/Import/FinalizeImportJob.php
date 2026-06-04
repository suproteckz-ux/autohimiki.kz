<?php

namespace App\Jobs\Import;

use App\Models\ImportBatch;
use App\Services\CacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FinalizeImportJob
 *
 * Завершение импорта:
 * - Ставит статус batch в 'done'
 * - Сбрасывает кэш каталога и sitemap
 * - Логирует статистику
 * - Обновляет прогресс в Cache до 100%
 *
 * ИСПРАВЛЕНИЕ LA-2:
 * Класс перенесён из FinalizeAndDownloadJobs.php в отдельный файл.
 * Laravel autoloader ожидает один класс на файл.
 * Два класса в одном файле → Class not found при dispatch().
 */
class FinalizeImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int $batchId
    ) {}

    public function handle(): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);

        // Проверяем — не завершён ли уже
        if ($batch->status === 'done') {
            Log::info("FinalizeImportJob #{$this->batchId}: already done, skipping");
            return;
        }

        // Если не все чанки обработаны — откладываем финализацию
        if (
            $batch->total_chunks > 0
            && isset($batch->processed_chunks)
            && $batch->processed_chunks < $batch->total_chunks
        ) {
            $remaining = $batch->total_chunks - $batch->processed_chunks;
            Log::info("FinalizeJob #{$this->batchId}: {$remaining} chunks pending, rescheduling");

            self::dispatch($this->batchId)
                ->onQueue('imports-low')
                ->delay(now()->addSeconds(30));
            return;
        }

        // Финализируем
        $batch->update([
            'status'      => 'done',
            'finished_at' => now(),
        ]);

        // Сбрасываем весь кэш сайта
        CacheService::forgetAll();

        // Финальное обновление прогресса
        \Illuminate\Support\Facades\Cache::put(
            "import_progress_{$this->batchId}",
            [
                'total'     => 100,
                'processed' => 100,
                'percent'   => 100,
                'status'    => 'done',
            ],
            3600
        );

        $batch->refresh();

        Log::info("Import #{$this->batchId} completed", [
            'type'       => $batch->type,
            'file'       => $batch->filename,
            'total_rows' => $batch->total_rows,
            'created'    => $batch->created_count,
            'updated'    => $batch->updated_count,
            'skipped'    => $batch->skipped_count,
            'not_found'  => $batch->not_found_count,
            'errors'     => $batch->error_count,
            'duration'   => $batch->duration,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        ImportBatch::where('id', $this->batchId)->update([
            'status'      => 'failed',
            'finished_at' => now(),
        ]);

        Log::error("FinalizeImportJob #{$this->batchId} failed permanently", [
            'error' => $e->getMessage(),
        ]);
    }
}
