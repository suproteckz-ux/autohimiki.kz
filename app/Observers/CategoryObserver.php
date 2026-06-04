<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Redirect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * CategoryObserver
 *
 * При смене slug категории создаёт 301-редирект.
 * Обрабатывает как корневые, так и дочерние категории.
 */
class CategoryObserver
{
    public function saving(Category $category): void
    {
        if (! empty($category->slug)) {
            $category->slug = Str::lower($category->slug);
        }
    }

    public function updating(Category $category): void
    {
        if (! $category->isDirty('slug')) {
            return;
        }

        $oldSlug = $category->getOriginal('slug');
        $newSlug = $category->slug;

        if (empty($oldSlug) || $oldSlug === $newSlug) {
            return;
        }

        // Определяем тип URL (корневая или подкатегория)
        $parent = $category->parent ?? Category::find($category->getOriginal('parent_id'));

        if ($parent) {
            // Подкатегория: /catalog/{parent}/{child}
            $oldUrl = "/catalog/{$parent->slug}/{$oldSlug}";
            $newUrl = "/catalog/{$parent->slug}/{$newSlug}";
        } else {
            // Корневая: /catalog/{slug}
            $oldUrl = "/catalog/{$oldSlug}";
            $newUrl = "/catalog/{$newSlug}";
        }

        Redirect::updateOrCreate(
            ['from_url' => $oldUrl],
            ['to_url' => $newUrl, 'is_active' => true]
        );

        // Разворачиваем цепочки редиректов
        Redirect::where('to_url', $oldUrl)
            ->where('is_active', true)
            ->update(['to_url' => $newUrl]);

        Cache::forget('active_redirects');
    }

    public function saved(Category $category): void
    {
        Cache::forget('sitemap.categories');
        Cache::forget('sitemap.index');
    }

    public function deleted(Category $category): void
    {
        Cache::forget('sitemap.categories');
        Cache::forget('sitemap.index');
    }
}
