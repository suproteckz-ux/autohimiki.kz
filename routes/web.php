<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeoPageController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

// ── Главная ───────────────────────────────────────────────────────
Route::get('/', [HomeController::class, 'index'])->name('home');

// ── Поиск ─────────────────────────────────────────────────────────
Route::middleware('throttle:search')
    ->get('/search', [SearchController::class, 'index'])
    ->name('search');

// ── Заявка ────────────────────────────────────────────────────────
Route::middleware('throttle:leads')
    ->post('/lead', [LeadController::class, 'store'])
    ->name('lead.store');

// ── Каталог ───────────────────────────────────────────────────────
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog');

Route::get('/catalog/{category}', [CatalogController::class, 'category'])
    ->name('catalog.category')
    ->where('category', '[a-z0-9][a-z0-9\-]*');

Route::get('/catalog/{parent}/{child}', [CatalogController::class, 'subcategory'])
    ->name('catalog.subcategory')
    ->where(['parent' => '[a-z0-9][a-z0-9\-]*', 'child' => '[a-z0-9][a-z0-9\-]*']);

// ── Товары ────────────────────────────────────────────────────────
Route::get('/product/{product}', [ProductController::class, 'show'])
    ->name('product.show')
    ->where('product', '[a-z0-9][a-z0-9\-]*');

// ── Бренды ────────────────────────────────────────────────────────
Route::get('/brand', [BrandController::class, 'index'])->name('brands');
Route::get('/brand/{brand}', [BrandController::class, 'show'])
    ->name('brand.show')
    ->where('brand', '[a-z0-9][a-z0-9\-]*');

// ── Блог ──────────────────────────────────────────────────────────
Route::get('/blog', [BlogController::class, 'index'])->name('blog');
Route::get('/blog/{post}', [BlogController::class, 'show'])
    ->name('blog.show')
    ->where('post', '[a-z0-9][a-z0-9\-]*');

// ── Health check ──────────────────────────────────────────────────
Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        return response()->json([
            'status' => 'ok',
            'db'     => 'connected',
            'time'   => now()->toIso8601String(),
        ]);
    } catch (\Throwable $e) {
        return response()->json(['status' => 'error', 'db' => 'failed'], 503);
    }
})->middleware('throttle:60,1');

// ── Sitemap ───────────────────────────────────────────────────────
Route::get('/sitemap.xml',              [SitemapController::class, 'index']);
Route::get('/sitemap-products.xml',     [SitemapController::class, 'products']);
Route::get('/sitemap-categories.xml',   [SitemapController::class, 'categories']);
Route::get('/sitemap-brands.xml',       [SitemapController::class, 'brands']);
Route::get('/sitemap-blog.xml',         [SitemapController::class, 'blog']);
Route::get('/sitemap-seo-pages.xml',    [SitemapController::class, 'seoPages']);
Route::get('/sitemap-seo-filters.xml',  [SitemapController::class, 'seoFilters']);

// ── Robots.txt ────────────────────────────────────────────────────
// ИСПРАВЛЕНИЕ SEO-1:
// Ранее генерировался через Blade-шаблон → {{ config('app.url') }} экранировался
// в &amp; если URL содержит спецсимволы → битая ссылка в robots.txt.
// Теперь генерируется строкой напрямую — без Blade, без экранирования.
Route::get('/robots.txt', function () {
    $appUrl = config('app.url');

    $lines = [
        'User-agent: *',
        '',
        '# Закрытые разделы',
        'Disallow: /admin',
        'Disallow: /admin/',
        'Disallow: /lead',
        'Disallow: /health',
        'Disallow: /livewire/',
        'Disallow: /_ignition/',
        '',
        '# Поиск — noindex + закрыт',
        'Disallow: /search',
        'Disallow: /search?*',
        '',
        '# GET-параметры фильтров (создают дубли)',
        'Disallow: /*?brand=*',
        'Disallow: /*?price_min=*',
        'Disallow: /*?price_max=*',
        'Disallow: /*?in_stock=*',
        'Disallow: /*?sort=*',
        '',
        '# Разрешаем',
        'Allow: /catalog/',
        'Allow: /product/',
        'Allow: /brand/',
        'Allow: /blog/',
        'Allow: /sitemap*.xml',
        '',
        "Sitemap: {$appUrl}/sitemap.xml",
        '',
        '# Задержка для Яндекс',
        'User-agent: Yandex',
        'Crawl-delay: 1',
        "Sitemap: {$appUrl}/sitemap.xml",
        '',
        'User-agent: Googlebot',
        'Crawl-delay: 0',
        "Sitemap: {$appUrl}/sitemap.xml",
    ];

    $content = implode("\n", $lines);

    return response($content, 200, [
        'Content-Type'  => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'public, max-age=86400',
    ]);
});

// ── SEO-фильтры /{category}/{brand} ──────────────────────────────
// ВАЖНО: стоит ПЕРЕД SEO-страницами (два сегмента — конкретнее)
Route::get('/{categorySlug}/{brandSlug}', [SeoPageController::class, 'filter'])
    ->name('seo.filter')
    ->where([
        'categorySlug' => '[a-z0-9][a-z0-9\-]*',
        'brandSlug'    => '[a-z0-9][a-z0-9\-]*',
    ]);

// ── SEO-страницы /{slug} — ВСЕГДА ПОСЛЕДНИМ ──────────────────────
Route::get('/{slug}', [SeoPageController::class, 'show'])
    ->name('seo.page')
    ->where('slug', '[a-z0-9][a-z0-9\-]*');
