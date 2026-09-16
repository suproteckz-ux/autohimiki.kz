<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\ProductUrls\ProductSlugAllocator;
use App\Services\ProductUrls\ProductUrlMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductObserver
{
    public function saving(Product $product): void
    {
        if (! empty($product->slug)) {
            $product->slug = Str::lower($product->slug);
        }
        try {
            // Imports still create inactive drafts. Later name changes never
            // enter this publication transition.
            $willBeActive = $product->exists ? $product->is_active : ($product->is_active ?? true);
            if ($willBeActive && (! $product->exists || ! $product->getRawOriginal('is_active'))
                && str_starts_with((string) $product->slug, 'onec-')) {
                [$products, $redirects] = app(ProductUrlMigration::class)->snapshot(true);
                $allocator = app(ProductSlugAllocator::class);
                $reserved = $allocator->reserved($products, $redirects);
                $technicalSlug = $product->slug;
                $product->slug = $allocator->generate($product->name, $reserved)['slug'];
                // A brand-new active model has no updating event to reconcile canonical.
                if (! $product->exists && $product->canonical_url !== null && $product->canonical_url !== '') {
                    $base = app(ProductUrlMigration::class)->base();
                    if (! in_array($product->canonical_url, [$base.'/product/'.$technicalSlug, '/product/'.$technicalSlug], true)) {
                        throw new \RuntimeException('custom_canonical_requires_review');
                    }
                    $product->canonical_url = $base.'/product/'.$product->slug;
                }
            }
            if ((! $product->exists || $product->isDirty('slug')) && ! ProductSlugAllocator::valid((string) $product->slug)) {
                throw new \RuntimeException('Slug: только латинские буквы, цифры и одиночные дефисы; максимум 255 символов.');
            }
            if (! $product->exists) {
                [$products, $redirects] = app(ProductUrlMigration::class)->snapshot(true);
                $reserved = app(ProductSlugAllocator::class)->reserved($products, $redirects);
                if (isset($reserved[$product->slug])) {
                    throw new \RuntimeException('Этот адрес зарезервирован историей перенаправлений.');
                }
            }
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['slug' => $e->getMessage()]);
        }
        if ((! $product->exists || $product->isDirty('name')) && empty($product->main_image_alt) && ! empty($product->name)) {
            $brandName = $product->brand?->name ?? '';
            $product->main_image_alt = trim($product->name.($brandName ? " — {$brandName}" : ''));
        }
    }

    public function updating(Product $product): void
    {
        if (! $product->isDirty('slug')) {
            return;
        }
        try {
            $service = app(ProductUrlMigration::class);
            [$products, $redirects] = $service->snapshot(true);
            $original = (object) $product->getRawOriginal();
            $original->canonical_url = $product->canonical_url;
            $change = $service->change($original, $product->slug, $products, $redirects);
            $product->canonical_url = $change['canonical_after'];
            $service->writeRedirects($original->slug, $product->slug, $change['history_ids']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['slug' => $e->getMessage()]);
        }
    }

    public function saved(Product $product): void
    {
        DB::afterCommit(fn () => ProductUrlMigration::invalidate());
    }

    public function deleted(Product $product): void
    {
        DB::afterCommit(fn () => ProductUrlMigration::invalidate());
    }
}
