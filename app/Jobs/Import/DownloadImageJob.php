<?php

namespace App\Jobs\Import;

use App\Models\ImportError;
use App\Models\Product;
use App\Services\Import\ImageDownloader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DownloadImageJob
 *
 * Асинхронная загрузка изображения для товара.
 * Запускается из FullProductImporter после создания/обновления товара.
 *
 * ИСПРАВЛЕНИЕ LA-2:
 * Класс перенесён из FinalizeAndDownloadJobs.php в отдельный файл.
 * Laravel autoloader ожидает один класс на файл.
 */
class DownloadImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;
    public int $backoff = 15;

    public function __construct(
        public readonly int    $productId,
        public readonly string $imageUrl,
        public readonly int    $batchId
    ) {}

    public function handle(ImageDownloader $downloader): void
    {
        $success = $downloader->download($this->productId, $this->imageUrl);

        if (! $success) {
            Log::warning("DownloadImageJob: не удалось загрузить изображение", [
                'product_id' => $this->productId,
                'url'        => $this->imageUrl,
                'batch_id'   => $this->batchId,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("DownloadImageJob: окончательный сбой", [
            'product_id' => $this->productId,
            'url'        => $this->imageUrl,
            'error'      => $e->getMessage(),
        ]);

        // Записываем в import_errors для отображения в отчёте
        try {
            ImportError::create([
                'import_batch_id' => $this->batchId,
                'row_number'      => 0,
                'sku'             => Product::find($this->productId)?->sku,
                'message'         => "Не удалось загрузить изображение: {$this->imageUrl}. "
                                   . "Добавьте изображение вручную в карточку товара.",
                'row_data'        => ['image_url' => $this->imageUrl],
            ]);

            DB::table('import_batches')
                ->where('id', $this->batchId)
                ->increment('error_count');
        } catch (\Throwable $recordError) {
            Log::error("DownloadImageJob: не удалось записать ошибку", [
                'error' => $recordError->getMessage(),
            ]);
        }
    }
}
