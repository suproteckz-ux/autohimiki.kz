<?php

namespace App\Services\Kaspi;

use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Force branch of the existing importer. No resolver or parser runs here. */
class KaspiContentRefreshService
{
    public function __construct(private readonly KaspiSecureImageDownloader $downloader) {}

    public function preview(string $sku): array
    {
        $product = DB::table('products')->where('sku', $sku)->first();
        if (! $product || $product->sku !== $sku || ! $product->is_active) {
            throw new \RuntimeException('identity_changed', 409);
        }
        $reason = null;
        try {
            KaspiRefreshPolicy::existing($product->attributes);
        } catch (\RuntimeException $e) {
            $reason = $e->getMessage();
        }

        return KaspiRefreshPolicy::state($product) + ['sku' => $sku, 'name' => $product->name,
            'storefront_url' => KaspiUrlRules::base().'/product/'.rawurlencode($product->slug),
            'attributes_safe' => $reason === null, 'reason' => $reason];
    }

    private function check(array $payload, bool $lock = false): object
    {
        $query = DB::table('products')->where('id', $payload['product_id']);
        $product = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $product || $product->sku !== $payload['sku'] || ! $product->is_active
            || trim((string) $product->slug) === '' || str_contains($product->slug, '/')
            || $payload['storefront_url'] !== KaspiUrlRules::base().'/product/'.rawurlencode($product->slug)) {
            throw new \RuntimeException('identity_changed', 409);
        }
        $galleryQuery = DB::table('product_images')->where('product_id', $product->id)->orderBy('id');
        $gallery = ($lock ? $galleryQuery->lockForUpdate() : $galleryQuery)->get()->map(fn ($row) => (array) $row)->all();
        if (! hash_equals(KaspiRefreshPolicy::state($product, $gallery)['state_fingerprint'], $payload['state_fingerprint'])) {
            throw new \RuntimeException('state_changed', 409);
        }
        KaspiRefreshPolicy::existing($product->attributes);

        return $product;
    }

    public function import(array $payload): array
    {
        // Also enforce the force contract for direct service calls.
        $payload = app(KaspiProductionPayloadValidator::class)->validate($payload);
        if (! KaspiRefreshPolicy::force($payload)) {
            throw new \RuntimeException('invalid_force_flag', 422);
        }
        $lock = Cache::lock('kaspi-1c-import-'.hash('sha256', $payload['sku']), 300);
        if (! $lock->get()) {
            throw new \RuntimeException('import_locked', 409);
        }
        $created = [];
        $committed = false;
        $started = microtime(true);
        try {
            $this->check($payload);
            $disk = Storage::disk('public');
            $paths = [];
            // Stage immutable, product-owned hash files. Do not reuse uncertain/manual paths.
            // Every URL is downloaded, including duplicates: a failed image fails the whole product.
            foreach ($payload['content']['images'] as $url) {
                $image = $this->downloader->download($url);
                $path = 'products/kaspi/'.$payload['product_id'].'/'.$image['hash'].'.'.$image['extension'];
                if (! $disk->exists($path)) {
                    $created[] = $path;
                    if (! $disk->put($path, $image['bytes'])) {
                        throw new \RuntimeException('image_storage_write_failed', 422);
                    }
                } elseif (hash('sha256', $disk->get($path)) !== $image['hash']) {
                    throw new \RuntimeException('image_storage_collision', 409);
                }
                $paths[$image['hash']] = $path;
            }
            $paths = array_values($paths);
            // Twelve 15s downloads fit within the existing 300s lease. Refuse late commits.
            if (microtime(true) - $started > 240 || ! $lock->isOwnedByCurrentProcess()) {
                throw new \RuntimeException('import_locked', 409);
            }
            $obsolete = [];
            $result = DB::transaction(function () use ($payload, $paths, &$obsolete) {
                $product = $this->check($payload, true);
                $gallery = DB::table('product_images')->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get();
                $obsolete = array_filter([$product->main_image, $product->main_image_webp,
                    ...$gallery->pluck('path')->all(), ...$gallery->pluck('path_webp')->all()]);
                $attributes = [];
                foreach ($payload['content']['attributes'] as $attribute) {
                    $attributes[$attribute['name']] = $attribute['value'];
                }
                $changes = ['description' => $payload['content']['description'],
                    'attributes' => json_encode((object) $attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'main_image' => $paths[0], 'main_image_webp' => null];
                $changes = array_filter($changes, fn ($value, $key) => $value !== $product->$key, ARRAY_FILTER_USE_BOTH);
                $remaining = array_slice($paths, 1);
                $galleryChanged = $gallery->pluck('path')->all() !== $remaining
                    || $gallery->contains(fn ($row) => $row->path_webp !== null);
                if ($galleryChanged) {
                    DB::table('product_images')->where('product_id', $product->id)->delete();
                    foreach ($remaining as $order => $path) {
                        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => $path,
                            'path_webp' => null, 'alt' => $product->name, 'sort_order' => $order]);
                    }
                }
                if ($changes !== []) {
                    // Intentional strict allowlist; no Eloquent save, timestamps or URL observers.
                    DB::table('products')->where('id', $product->id)->update($changes);
                }

                return ['sku' => $product->sku, 'product_id' => (int) $product->id,
                    'status' => $changes !== [] || $galleryChanged ? 'imported' : 'unchanged',
                    'reason' => null, 'gallery_added' => count($remaining), 'description' => 'updated',
                    'attributes' => 'replaced', 'main_image' => 'replaced'];
            }, 1);
            $committed = true;
            $warnings = [];
            try {
                CacheService::forgetProductContent();
            } catch (\Throwable) {
                $warnings[] = 'cache_invalidation_failed';
            }
            foreach (array_diff(array_unique($obsolete), $paths) as $path) {
                try {
                    $this->deleteOwnedUnreferenced($path, $payload['product_id']);
                } catch (\Throwable) {
                    $warnings[] = 'obsolete_media_cleanup_failed';
                }
            }

            return $result + ['cleanup_warnings' => array_values(array_unique($warnings))];
        } catch (\Throwable $e) {
            if (! $committed) {
                $cleanupFailed = false;
                foreach ($created as $path) {
                    try {
                        $this->deleteOwnedUnreferenced($path, $payload['product_id']);
                    } catch (\Throwable) {
                        $cleanupFailed = true;
                    }
                }
                if ($cleanupFailed) {
                    throw new \RuntimeException('image_cleanup_failed', 422, $e);
                }
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    protected function deleteOwnedUnreferenced(string $path, int $id): void
    {
        // Only our immutable files have demonstrable ownership. No manual files or symlinks.
        if (! preg_match('#^products/kaspi/'.preg_quote((string) $id, '#').'/[a-f0-9]{64}\.(?:jpg|png|webp)$#D', $path)) {
            return;
        }
        $disk = Storage::disk('public');
        if (is_link($disk->path($path)) || is_link(dirname($disk->path($path)))) {
            return;
        }
        if (DB::table('products')->where('main_image', $path)->orWhere('main_image_webp', $path)->exists()
            || DB::table('product_images')->where('path', $path)->orWhere('path_webp', $path)->exists()) {
            return;
        }
        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new \RuntimeException('obsolete_media_cleanup_failed');
        }
    }
}
