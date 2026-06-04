<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * CacheService
 *
 * Централизованное управление кэшем сайта.
 * Единое место для TTL, ключей и инвалидации.
 *
 * Используем file-driver (из .env). Если на сервере есть Redis —
 * меняем CACHE_STORE=redis в .env, код не меняется.
 */
class CacheService
{
    // ── TTL (секунды) ─────────────────────────────────────────────

    public const TTL_SETTINGS   = 86400;  // 24 часа — настройки меняются редко
    public const TTL_HOMEPAGE   = 3600;   // 1 час  — главная с хитами/новинками
    public const TTL_CATEGORY   = 7200;   // 2 часа — список категорий
    public const TTL_BRANDS     = 86400;  // 24 часа — бренды меняются редко
    public const TTL_SEO_PAGE   = 86400;  // 24 часа — SEO-страницы статичны
    public const TTL_SITEMAP    = 7200;   // 2 часа  — sitemap
    public const TTL_REDIRECTS  = 600;    // 10 минут — редиректы

    // ── Ключи кэша ────────────────────────────────────────────────

    public const KEY_SETTINGS        = 'site_settings';
    public const KEY_HOMEPAGE_CATS   = 'homepage_categories';
    public const KEY_HOMEPAGE_HITS   = 'homepage_hits';
    public const KEY_HOMEPAGE_NEW    = 'homepage_new_products';
    public const KEY_HOMEPAGE_BRANDS = 'homepage_brands';
    public const KEY_ALL_CATEGORIES  = 'all_active_categories';
    public const KEY_ALL_BRANDS      = 'all_active_brands';
    public const KEY_REDIRECTS       = 'active_redirects';
    public const KEY_SITEMAP_INDEX   = 'sitemap.index';
    public const KEY_SITEMAP_PRODUCTS   = 'sitemap.products';
    public const KEY_SITEMAP_CATEGORIES = 'sitemap.categories';
    public const KEY_SITEMAP_BRANDS     = 'sitemap.brands';
    public const KEY_SITEMAP_BLOG       = 'sitemap.blog';
    public const KEY_SITEMAP_SEO_PAGES  = 'sitemap.seo_pages';
    public const KEY_SITEMAP_SEO_FILTERS= 'sitemap.seo_filters';

    // ── Методы получения данных ───────────────────────────────────

    public static function settings(): array
    {
        return Cache::remember(
            self::KEY_SETTINGS,
            self::TTL_SETTINGS,
            fn () => \App\Models\Setting::all()->pluck('value', 'key')->toArray()
        );
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        return static::settings()[$key] ?? $default;
    }

    public static function homepageCategories(): \Illuminate\Support\Collection
    {
        return Cache::remember(
            self::KEY_HOMEPAGE_CATS,
            self::TTL_HOMEPAGE,
            fn () => \App\Models\Category::active()
                ->root()
                ->ordered()
                ->withCount('products')
                ->with('children:id,parent_id,name,slug,image,image_webp,sort_order')
                ->get(['id', 'name', 'slug', 'image', 'image_webp', 'sort_order'])
        );
    }

    public static function homepageHits(): \Illuminate\Support\Collection
    {
        return Cache::remember(
            self::KEY_HOMEPAGE_HITS,
            self::TTL_HOMEPAGE,
            fn () => \App\Models\Product::hits()
                ->with(['brand:id,name,slug', 'category:id,name,slug'])
                ->orderBy('sort_order')
                ->limit(8)
                ->get([
                    'id', 'name', 'slug', 'price', 'old_price',
                    'main_image', 'main_image_webp', 'main_image_alt',
                    'in_stock', 'is_new', 'is_hit', 'brand_id', 'category_id',
                ])
        );
    }

    public static function homepageNewProducts(): \Illuminate\Support\Collection
    {
        return Cache::remember(
            self::KEY_HOMEPAGE_NEW,
            self::TTL_HOMEPAGE,
            fn () => \App\Models\Product::new()
                ->with(['brand:id,name,slug', 'category:id,name,slug'])
                ->latest()
                ->limit(8)
                ->get([
                    'id', 'name', 'slug', 'price', 'old_price',
                    'main_image', 'main_image_webp', 'main_image_alt',
                    'in_stock', 'is_new', 'is_hit', 'brand_id', 'category_id',
                ])
        );
    }

    public static function homepageBrands(): \Illuminate\Support\Collection
    {
        return Cache::remember(
            self::KEY_HOMEPAGE_BRANDS,
            self::TTL_BRANDS,
            fn () => \App\Models\Brand::active()
                ->ordered()
                ->whereHas('products')
                ->limit(12)
                ->get(['id', 'name', 'slug', 'logo', 'logo_webp'])
        );
    }

    public static function allBrands(): \Illuminate\Support\Collection
    {
        return Cache::remember(
            self::KEY_ALL_BRANDS,
            self::TTL_BRANDS,
            fn () => \App\Models\Brand::active()
                ->ordered()
                ->withCount('products')
                ->get(['id', 'name', 'slug', 'logo', 'logo_webp'])
        );
    }

    public static function redirects(): array
    {
        return Cache::remember(
            self::KEY_REDIRECTS,
            self::TTL_REDIRECTS,
            fn () => \App\Models\Redirect::active()
                ->pluck('to_url', 'from_url')
                ->toArray()
        );
    }

    // ── Инвалидация ───────────────────────────────────────────────

    /** Сброс всего кэша главной страницы */
    public static function forgetHomepage(): void
    {
        Cache::forget(self::KEY_HOMEPAGE_CATS);
        Cache::forget(self::KEY_HOMEPAGE_HITS);
        Cache::forget(self::KEY_HOMEPAGE_NEW);
        Cache::forget(self::KEY_HOMEPAGE_BRANDS);
    }

    /** Сброс кэша каталога */
    public static function forgetCatalog(): void
    {
        Cache::forget(self::KEY_ALL_CATEGORIES);
        Cache::forget(self::KEY_HOMEPAGE_CATS);
        Cache::forget(self::KEY_SITEMAP_CATEGORIES);
        Cache::forget(self::KEY_SITEMAP_INDEX);
    }

    /** Сброс кэша товаров */
    public static function forgetProducts(): void
    {
        self::forgetHomepage();
        Cache::forget(self::KEY_SITEMAP_PRODUCTS);
        Cache::forget(self::KEY_SITEMAP_INDEX);
    }

    /** Сброс кэша брендов */
    public static function forgetBrands(): void
    {
        Cache::forget(self::KEY_ALL_BRANDS);
        Cache::forget(self::KEY_HOMEPAGE_BRANDS);
        Cache::forget(self::KEY_SITEMAP_BRANDS);
        Cache::forget(self::KEY_SITEMAP_INDEX);
    }

    /** Сброс настроек */
    public static function forgetSettings(): void
    {
        Cache::forget(self::KEY_SETTINGS);
    }

    /** Сброс всего sitemap */
    public static function forgetSitemap(): void
    {
        Cache::forget(self::KEY_SITEMAP_INDEX);
        Cache::forget(self::KEY_SITEMAP_PRODUCTS);
        Cache::forget(self::KEY_SITEMAP_CATEGORIES);
        Cache::forget(self::KEY_SITEMAP_BRANDS);
        Cache::forget(self::KEY_SITEMAP_BLOG);
        Cache::forget(self::KEY_SITEMAP_SEO_PAGES);
        Cache::forget(self::KEY_SITEMAP_SEO_FILTERS);
    }

    /** Сброс редиректов */
    public static function forgetRedirects(): void
    {
        Cache::forget(self::KEY_REDIRECTS);
    }

    /** Полный сброс кэша сайта (после импорта) */
    public static function forgetAll(): void
    {
        self::forgetHomepage();
        self::forgetCatalog();
        self::forgetProducts();
        self::forgetBrands();
        self::forgetSitemap();
        self::forgetSettings();
        self::forgetRedirects();
    }
}
