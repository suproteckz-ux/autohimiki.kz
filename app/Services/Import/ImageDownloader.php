<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

/**
 * ImageDownloader
 *
 * Скачивает изображение по URL, сохраняет оригинал,
 * конвертирует в WebP и создаёт запись product_images.
 *
 * Если изображение уже существует (сравниваем по URL/имени) — не дублирует.
 */
class ImageDownloader
{
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
    private const TIMEOUT        = 30;                // секунд
    private const WEBP_QUALITY   = 85;
    private const MAX_DIMENSION  = 1200;              // px (ширина/высота)

    /**
     * Скачивает и сохраняет изображение для товара.
     *
     * @param  int    $productId
     * @param  string $imageUrl
     * @param  bool   $setAsMain  Установить как главное изображение
     * @return bool   Успешно ли
     */
    public function download(int $productId, string $imageUrl, bool $setAsMain = false): bool
    {
        $product = Product::find($productId);
        if (! $product) {
            Log::warning("ImageDownloader: товар #{$productId} не найден");
            return false;
        }

        try {
            // Скачиваем файл
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => 'Autohimiki-Import/1.0'])
                ->get($imageUrl);

            if (! $response->successful()) {
                Log::warning("ImageDownloader: не удалось скачать {$imageUrl}", [
                    'status' => $response->status(),
                ]);
                return false;
            }

            // Проверяем размер
            $content = $response->body();
            if (strlen($content) > self::MAX_SIZE_BYTES) {
                Log::warning("ImageDownloader: файл слишком большой ({$imageUrl})");
                return false;
            }

            // Определяем расширение
            $contentType = $response->header('Content-Type') ?? 'image/jpeg';
            $extension   = $this->extensionFromMime($contentType);

            if (! $extension) {
                Log::warning("ImageDownloader: неизвестный MIME-тип {$contentType}");
                return false;
            }

            // Генерируем имена файлов
            $baseName  = Str::uuid()->toString();
            $origPath  = "products/{$baseName}.{$extension}";
            $webpPath  = "products/{$baseName}.webp";

            // Сохраняем оригинал
            Storage::disk('public')->put($origPath, $content);

            // Конвертируем в WebP
            $this->convertToWebP(
                Storage::disk('public')->path($origPath),
                Storage::disk('public')->path($webpPath)
            );

            // Определяем sort_order (максимальный + 1)
            $sortOrder = ProductImage::where('product_id', $productId)->max('sort_order') + 1;

            // Создаём запись галереи
            $productImage = ProductImage::create([
                'product_id' => $productId,
                'path'       => $origPath,
                'path_webp'  => $webpPath,
                'alt'        => $product->name,
                'sort_order' => $sortOrder,
            ]);

            // Устанавливаем как главное если нужно или у товара нет изображения
            if ($setAsMain || empty($product->main_image)) {
                Product::where('id', $productId)->update([
                    'main_image'      => $origPath,
                    'main_image_webp' => $webpPath,
                    'main_image_alt'  => $product->name,
                ]);
            }

            Log::info("ImageDownloader: изображение сохранено для товара #{$productId}", [
                'url'  => $imageUrl,
                'path' => $origPath,
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error("ImageDownloader: ошибка для товара #{$productId}", [
                'url'   => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Конвертирует изображение в WebP.
     */
    private function convertToWebP(string $sourcePath, string $targetPath): void
    {
        $image = Image::read($sourcePath);

        // Уменьшаем если слишком большое
        if ($image->width() > self::MAX_DIMENSION || $image->height() > self::MAX_DIMENSION) {
            $image->scaleDown(self::MAX_DIMENSION, self::MAX_DIMENSION);
        }

        $image->toWebp(self::WEBP_QUALITY)->save($targetPath);
    }

    /**
     * Определяет расширение по MIME-типу.
     */
    private function extensionFromMime(string $mime): ?string
    {
        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png')  => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif')  => 'gif',
            default                     => null,
        };
    }
}
