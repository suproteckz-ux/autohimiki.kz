<?php

namespace App\Observers;

use App\Models\Brand;
use App\Models\Redirect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BrandObserver
{
    public function saving(Brand $brand): void
    {
        if (! empty($brand->slug)) {
            $brand->slug = Str::lower($brand->slug);
        }
    }

    public function updating(Brand $brand): void
    {
        if (! $brand->isDirty('slug')) {
            return;
        }

        $oldSlug = $brand->getOriginal('slug');
        $newSlug = $brand->slug;

        if (empty($oldSlug) || $oldSlug === $newSlug) {
            return;
        }

        $oldUrl = "/brand/{$oldSlug}";
        $newUrl = "/brand/{$newSlug}";

        Redirect::updateOrCreate(
            ['from_url' => $oldUrl],
            ['to_url' => $newUrl, 'is_active' => true]
        );

        Redirect::where('to_url', $oldUrl)
            ->where('is_active', true)
            ->update(['to_url' => $newUrl]);

        Cache::forget('active_redirects');
    }

    public function saved(Brand $brand): void
    {
        Cache::forget('sitemap.brands');
        Cache::forget('sitemap.index');
    }

    public function deleted(Brand $brand): void
    {
        Cache::forget('sitemap.brands');
        Cache::forget('sitemap.index');
    }
}
