<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ImageDownloader
 *
 * Скачивает изображение по URL, сохраняет оригинал,
 * и по возможности конвертирует в WebP.
 *
 * ИЗМЕНЕНИЕ: WebP-конвертация теперь graceful-degradation:
 * - Если доступен Imagick → конвертируем в WebP через Imagick
 * - Если доступен GD     → конвертируем в WebP через GD
 * - Если ни один не доступен → сохраняем только оригинал, WebP не создаём
 * Это позволяет работать на shared-хостинге без ext-gd в CLI.
 */
class ImageDownloader
{
    private const CONNECT_TIMEOUT = 5;
    private const READ_TIMEOUT    = 20;
    private const MAX_SIZE_BYTES  = 10 * 1024 * 1024; // 10 MB
    private const WEBP_QUALITY    = 85;
    private const MAX_DIMENSION   = 1200;

    public function download(int $productId, string $imageUrl, bool $setAsMain = false): bool
    {
        $product = Product::find($productId);
        if (! $product) {
            Log::warning("ImageDownloader: товар #{$productId} не найден");
            return false;
        }

        // Валидация URL
        if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            Log::warning("ImageDownloader: невалидный URL", ['url' => $imageUrl]);
            return false;
        }

        // SSRF-защита
        $host = parse_url($imageUrl, PHP_URL_HOST);
        if ($host && $this->isInternalHost($host)) {
            Log::warning("ImageDownloader: заблокирован внутренний адрес", ['host' => $host]);
            return false;
        }

        try {
            // HEAD-запрос для быстрой проверки
            $head = Http::timeout(self::CONNECT_TIMEOUT)->head($imageUrl);
            if (! $head->successful()) {
                Log::warning("ImageDownloader: URL недоступен (HEAD {$head->status()})", ['url' => $imageUrl]);
                return false;
            }

            // Скачиваем
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::READ_TIMEOUT)
                ->withHeaders(['User-Agent' => 'Autohimiki-Import/1.0'])
                ->get($imageUrl);

            if (! $response->successful()) {
                return false;
            }

            $content = $response->body();

            if (strlen($content) > self::MAX_SIZE_BYTES) {
                Log::warning("ImageDownloader: файл слишком большой", ['url' => $imageUrl]);
                return false;
            }

            if (! $this->isValidImageContent($content)) {
                Log::warning("ImageDownloader: контент не является изображением", ['url' => $imageUrl]);
                return false;
            }

            // Определяем расширение
            $contentType = $response->header('Content-Type') ?? 'image/jpeg';
            $extension   = $this->extensionFromMime($contentType) ?? 'jpg';

            $baseName = Str::uuid()->toString();
            $origPath = "products/{$baseName}.{$extension}";

            // Сохраняем оригинал
            Storage::disk('public')->put($origPath, $content);

            // Пробуем создать WebP (graceful — если нет нужного расширения, не падаем)
            $webpPath = $this->tryConvertToWebP(
                Storage::disk('public')->path($origPath),
                "products/{$baseName}.webp"
            );

            // Запись в БД
            $sortOrder = ProductImage::where('product_id', $productId)->max('sort_order') + 1;

            ProductImage::create([
                'product_id' => $productId,
                'path'       => $origPath,
                'path_webp'  => $webpPath,
                'alt'        => $product->name,
                'sort_order' => $sortOrder,
            ]);

            // Главное изображение
            if ($setAsMain || empty($product->main_image)) {
                Product::where('id', $productId)->update([
                    'main_image'      => $origPath,
                    'main_image_webp' => $webpPath,
                    'main_image_alt'  => $product->name,
                ]);
            }

            return true;

        } catch (\Throwable $e) {
            Log::error("ImageDownloader: ошибка", [
                'product_id' => $productId,
                'url'        => $imageUrl,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Пытается конвертировать изображение в WebP.
     * Возвращает путь к WebP-файлу или null если конвертация невозможна.
     *
     * Порядок попытки: Imagick → GD → null (не конвертируем).
     */
    private function tryConvertToWebP(string $sourcePath, string $targetStoragePath): ?string
    {
        $targetPath = Storage::disk('public')->path($targetStoragePath);

        // Попытка 1: Imagick (доступен на большинстве хостингов)
        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($sourcePath);
                $imagick->setImageFormat('webp');
                $imagick->setImageCompressionQuality(self::WEBP_QUALITY);

                // Уменьшаем если слишком большое
                $w = $imagick->getImageWidth();
                $h = $imagick->getImageHeight();
                if ($w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION) {
                    $imagick->scaleImage(self::MAX_DIMENSION, self::MAX_DIMENSION, true);
                }

                $imagick->writeImage($targetPath);
                $imagick->destroy();

                return $targetStoragePath;
            } catch (\Throwable $e) {
                Log::warning("ImageDownloader: Imagick WebP failed", ['error' => $e->getMessage()]);
            }
        }

        // Попытка 2: GD
        if (extension_loaded('gd') && function_exists('imagewebp')) {
            try {
                $info = getimagesize($sourcePath);
                if (! $info) return null;

                $src = match ($info[2]) {
                    IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
                    IMAGETYPE_PNG  => imagecreatefrompng($sourcePath),
                    IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
                    default        => null,
                };

                if (! $src) return null;

                $w = imagesx($src);
                $h = imagesy($src);

                if ($w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION) {
                    $ratio  = min(self::MAX_DIMENSION / $w, self::MAX_DIMENSION / $h);
                    $newW   = (int) ($w * $ratio);
                    $newH   = (int) ($h * $ratio);
                    $resized = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                    imagedestroy($src);
                    $src = $resized;
                }

                imagewebp($src, $targetPath, self::WEBP_QUALITY);
                imagedestroy($src);

                return $targetStoragePath;
            } catch (\Throwable $e) {
                Log::warning("ImageDownloader: GD WebP failed", ['error' => $e->getMessage()]);
            }
        }

        // Ни Imagick ни GD не доступны — WebP не создаём, работаем с оригиналом
        Log::info("ImageDownloader: WebP недоступен (нет Imagick/GD), сохранён только оригинал");
        return null;
    }

    private function isValidImageContent(string $content): bool
    {
        $h = substr($content, 0, 12);
        return str_starts_with($h, "\xFF\xD8\xFF")          // JPEG
            || str_starts_with($h, "\x89PNG")               // PNG
            || (str_starts_with($h, 'RIFF') && str_contains(substr($h, 8, 4), 'WEBP')) // WebP
            || str_starts_with($h, 'GIF8');                 // GIF
    }

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

    private function isInternalHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        // Для доменов — простая проверка на localhost
        return in_array(strtolower($host), ['localhost', '::1'], true);
    }
}
