<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class KaspiProductionImportService
{
    public function __construct(private readonly KaspiSecureImageDownloader $downloader) {}

    private function product(bool $lock = false): object
    {
        $query = DB::table('products')->where('sku', KaspiSingleProductPolicy::SKU);
        if ($lock) {
            $query->lockForUpdate();
        }
        $product = $query->first();
        if (! $product || $product->sku !== KaspiSingleProductPolicy::SKU || ! $product->is_active) {
            throw new \RuntimeException('active_product_not_found', 404);
        }
        if ($product->slug !== KaspiSingleProductPolicy::SLUG) {
            throw new \RuntimeException('storefront_mismatch', 409);
        }

        return $product;
    }

    public function preview(mixed $sku): array
    {
        KaspiSingleProductPolicy::assertSku($sku);
        $product = $this->product();

        return ['sku' => $product->sku, 'main_image_action' => $this->exists($product->main_image) ? 'preserve' : 'replace_broken_or_empty',
            'description_action' => trim((string) $product->description) === '' ? 'fill_if_collected' : 'preserve',
            'existing_description_length' => mb_strlen((string) $product->description),
            'gallery_count' => DB::table('product_images')->where('product_id', $product->id)->count(),
            'gallery_additions' => 'up_to_image_count; exact count after content-hash deduplication'];
    }

    private function exists(?string $path): bool
    {
        return is_string($path) && $path !== '' && ! str_contains($path, '..') && ! preg_match('#^(?:/|[a-z]+:)|\\\\#i', $path)
            && Storage::disk('public')->exists($path);
    }

    public function import(array $payload): array
    {
        KaspiSingleProductPolicy::assertSku($payload['sku'] ?? null);
        $lock = Cache::lock('kaspi-1c-import-'.KaspiSingleProductPolicy::SKU, 300);
        if (! $lock->get()) {
            throw new \RuntimeException('import_in_progress', 409);
        }
        $created = [];
        try {
            return DB::transaction(function () use ($payload, &$created): array {
                $product = $this->product(true);
                $disk = Storage::disk('public');
                $gallery = DB::table('product_images')->where('product_id', $product->id)->get();
                $paths = $gallery->pluck('path')->all();
                $existing = [];
                foreach (array_unique([$product->main_image, ...$paths]) as $path) {
                    if ($this->exists($path)) {
                        $stream = $disk->readStream($path);
                        if (! is_resource($stream)) {
                            throw new \RuntimeException('image_storage_read_failed', 500);
                        }
                        $hash = hash_init('sha256');
                        hash_update_stream($hash, $stream);
                        fclose($stream);
                        $existing[hash_final($hash)] = $path;
                    }
                }
                $changes = [];
                $replace = ! $this->exists($product->main_image);
                $added = 0;
                $order = (int) ($gallery->max('sort_order') ?? -1);
                foreach ($payload['content']['images'] as $index => $url) {
                    $image = $this->downloader->download($url);
                    $path = $existing[$image['hash']] ?? 'products/kaspi/'.KaspiSingleProductPolicy::SKU.'/'.$image['hash'].'.'.$image['extension'];
                    if (! $disk->exists($path)) {
                        $created[] = $path;
                        if (! $disk->put($path, $image['bytes'])) {
                            throw new \RuntimeException('image_storage_write_failed', 500);
                        }
                    } elseif (! isset($existing[$image['hash']]) && hash('sha256', $disk->get($path)) !== $image['hash']) {
                        throw new \RuntimeException('image_storage_collision', 409);
                    }
                    $existing[$image['hash']] = $path;
                    if (! in_array($path, $paths, true)) {
                        if (++$order > 65535) {
                            throw new \RuntimeException('gallery_order_limit', 409);
                        }
                        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => $path, 'path_webp' => null,
                            'alt' => $product->name, 'sort_order' => $order]);
                        $paths[] = $path;
                        $added++;
                    }
                    if ($index === 0 && $replace) {
                        $changes += ['main_image' => $path, 'main_image_webp' => null];
                    }
                }
                $description = $payload['content']['description'];
                if (trim((string) $product->description) === '' && trim((string) $description) !== '') {
                    $changes['description'] = $description;
                }
                // Clear only a broken derivative reference; never remove or overwrite the old file.
                if (! $replace && $product->main_image_webp && ! $this->exists($product->main_image_webp)) {
                    $changes['main_image_webp'] = null;
                }
                if ($changes !== []) {
                    DB::table('products')->where('id', $product->id)->update($changes);
                }

                return ['status' => $changes !== [] || $added > 0 ? 'imported' : 'unchanged', 'sku' => $product->sku,
                    'description' => isset($changes['description']) ? 'updated' : 'preserved',
                    'description_reason' => trim((string) $product->description) !== '' ? 'existing_nonempty' : (isset($changes['description']) ? 'existing_empty' : 'collected_empty'),
                    'main_image' => $replace ? 'replaced' : 'preserved', 'gallery_added' => $added, 'attributes' => 'diagnostic_only'];
            }, 1);
        } catch (\Throwable $e) {
            // Only paths created by this attempt; manual files and pre-existing hash files are never deleted.
            foreach ($created as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
