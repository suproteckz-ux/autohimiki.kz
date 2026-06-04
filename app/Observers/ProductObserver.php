<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\Redirect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * ProductObserver
 *
 * Решает три задачи:
 * 1. Приводит slug к lowercase перед сохранением.
 * 2. При смене slug — создаёт 301-редирект со старого URL на новый.
 * 3. После изменения/удаления — сбрасывает кэш sitemap и редиректов.
 */
class ProductObserver
{
    /**
     * Вызывается ДО сохранения (и create, и update).
     * Нормализуем slug — всегда lowercase.
     */
    public function saving(Product $product): void
    {
        if (! empty($product->slug)) {
            $product->slug = Str::lower($product->slug);
        }

        // Авто-генерация alt изображения если пустой
        if (empty($product->main_image_alt) && ! empty($product->name)) {
            $brandName = $product->brand?->name ?? '';
            $product->main_image_alt = trim($product->name . ($brandName ? " — {$brandName}" : ''));
        }
    }

    /**
     * Вызывается ДО UPDATE (не затрагивает create).
     * Если slug изменился — фиксируем старый для редиректа.
     */
    public function updating(Product $product): void
    {
        if (! $product->isDirty('slug')) {
            return;
        }

        $oldSlug = $product->getOriginal('slug');
        $newSlug = $product->slug; // уже нормализован в saving()

        if (empty($oldSlug) || $oldSlug === $newSlug) {
            return;
        }

        $oldUrl = "/product/{$oldSlug}";
        $newUrl = "/product/{$newSlug}";

        // Создаём или обновляем редирект
        Redirect::updateOrCreate(
            ['from_url' => $oldUrl],
            ['to_url' => $newUrl, 'is_active' => true]
        );

        // Если существует цепочка редиректов, ведущих на oldUrl — обновляем их
        // (A→B→C превращаем в A→C)
        Redirect::where('to_url', $oldUrl)
            ->where('is_active', true)
            ->update(['to_url' => $newUrl]);

        // Сбрасываем кэш редиректов
        Cache::forget('active_redirects');
    }

    /**
     * После сохранения — сбрасываем кэш sitemap.
     */
    public function saved(Product $product): void
    {
        $this->forgetSitemapCache();
    }

    /**
     * После удаления — сбрасываем кэш sitemap.
     */
    public function deleted(Product $product): void
    {
        $this->forgetSitemapCache();
    }

    private function forgetSitemapCache(): void
    {
        Cache::forget('sitemap.products');
        Cache::forget('sitemap.index');
    }
}
