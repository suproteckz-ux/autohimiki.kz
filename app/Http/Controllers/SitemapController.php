<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SeoFilter;
use App\Models\SeoPage;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * SitemapController
 *
 * Генерирует набор sitemap-файлов.
 * Все sitemap кэшируются через CacheService::TTL_SITEMAP.
 *
 * ИСПРАВЛЕНИЕ LA-1:
 * Глобальная функция esc() удалена — была вне класса, вызывала Fatal error
 * при повторной загрузке файла. Заменена на приватный метод escapeXml().
 *
 * ИСПРАВЛЕНИЕ SEO-2:
 * Вместо now() используется реальный updated_at последней записи.
 */
class SitemapController extends Controller
{
    private const TTL = 7200; // 2 часа

    // ── Индексный sitemap ──────────────────────────────────────────

    public function index(): Response
    {
        $xml = Cache::remember('sitemap.index', self::TTL, function () {
            $files = [
                'sitemap-categories.xml',
                'sitemap-products.xml',
                'sitemap-brands.xml',
                'sitemap-seo-pages.xml',
                'sitemap-seo-filters.xml',
                'sitemap-blog.xml',
            ];

            // SEO-2: реальная дата последнего изменения, не now()
            $lastUpdated = $this->getLastUpdated();

            $items = '';
            foreach ($files as $file) {
                $items .= sprintf(
                    '<sitemap><loc>%s/%s</loc><lastmod>%s</lastmod></sitemap>',
                    $this->escapeXml(config('app.url')),
                    $file,
                    $lastUpdated->toAtomString()
                );
            }

            return '<?xml version="1.0" encoding="UTF-8"?>'
                . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                . $items
                . '</sitemapindex>';
        });

        return $this->xmlResponse($xml);
    }

    // ── Категории ─────────────────────────────────────────────────

    public function categories(): Response
    {
        $xml = Cache::remember('sitemap.categories', self::TTL, function () {
            $items = $this->urlEntry(
                loc:        url('/catalog'),
                lastmod:    Category::active()->max('updated_at'),
                changefreq: 'weekly',
                priority:   '0.9'
            );

            Category::active()
                ->select(['id', 'slug', 'parent_id', 'updated_at'])
                ->with('parent:id,slug')
                ->ordered()
                ->each(function (Category $cat) use (&$items) {
                    $url = $cat->parent
                        ? url("/catalog/{$cat->parent->slug}/{$cat->slug}")
                        : url("/catalog/{$cat->slug}");

                    $items .= $this->urlEntry(
                        loc:        $url,
                        lastmod:    $cat->updated_at,
                        changefreq: 'weekly',
                        priority:   $cat->parent ? '0.7' : '0.9'
                    );
                });

            return $this->wrapUrlset($items);
        });

        return $this->xmlResponse($xml);
    }

    // ── Товары ────────────────────────────────────────────────────

    public function products(): Response
    {
        $xml = Cache::remember('sitemap.products', self::TTL, function () {
            $items = '';

            Product::active()
                ->select(['id', 'slug', 'name', 'main_image', 'updated_at'])
                ->orderBy('id')
                ->chunk(500, function ($products) use (&$items) {
                    foreach ($products as $product) {
                        $imageTag = '';
                        if ($product->main_image) {
                            $imageTag = sprintf(
                                '<image:image><image:loc>%s</image:loc><image:title>%s</image:title></image:image>',
                                $this->escapeXml(asset('storage/' . $product->main_image)),
                                $this->escapeXml($product->name)
                            );
                        }

                        $items .= $this->urlEntry(
                            loc:        url("/product/{$product->slug}"),
                            lastmod:    $product->updated_at,
                            changefreq: 'weekly',
                            priority:   '0.8',
                            extra:      $imageTag
                        );
                    }
                });

            return $this->wrapUrlset($items, imageNs: true);
        });

        return $this->xmlResponse($xml);
    }

    // ── Бренды ────────────────────────────────────────────────────

    public function brands(): Response
    {
        $xml = Cache::remember('sitemap.brands', self::TTL, function () {
            $items = $this->urlEntry(
                loc:        url('/brand'),
                lastmod:    Brand::active()->max('updated_at'),
                changefreq: 'monthly',
                priority:   '0.7'
            );

            Brand::active()
                ->select(['id', 'slug', 'updated_at'])
                ->whereHas('products')
                ->each(function (Brand $brand) use (&$items) {
                    $items .= $this->urlEntry(
                        loc:        url("/brand/{$brand->slug}"),
                        lastmod:    $brand->updated_at,
                        changefreq: 'weekly',
                        priority:   '0.7'
                    );
                });

            return $this->wrapUrlset($items);
        });

        return $this->xmlResponse($xml);
    }

    // ── SEO-страницы ──────────────────────────────────────────────

    public function seoPages(): Response
    {
        $xml = Cache::remember('sitemap.seo_pages', self::TTL, function () {
            // SEO-2: реальная дата последнего изменения товара для главной
            $lastProduct = Product::active()
                ->latest('updated_at')
                ->value('updated_at');

            $items = $this->urlEntry(
                loc:        url('/'),
                lastmod:    $lastProduct,
                changefreq: 'daily',
                priority:   '1.0'
            );

            $items .= $this->urlEntry(
                loc:        url('/blog'),
                lastmod:    BlogPost::active()->max('updated_at'),
                changefreq: 'daily',
                priority:   '0.8'
            );

            SeoPage::active()
                ->select(['id', 'slug', 'updated_at'])
                ->each(function (SeoPage $page) use (&$items) {
                    $items .= $this->urlEntry(
                        loc:        url("/{$page->slug}"),
                        lastmod:    $page->updated_at,
                        changefreq: 'monthly',
                        priority:   '0.8'
                    );
                });

            return $this->wrapUrlset($items);
        });

        return $this->xmlResponse($xml);
    }

    // ── SEO-фильтры ───────────────────────────────────────────────

    public function seoFilters(): Response
    {
        $xml = Cache::remember('sitemap.seo_filters', self::TTL, function () {
            $items = '';

            SeoFilter::indexed()
                ->select(['id', 'slug', 'category_id', 'brand_id', 'updated_at'])
                ->with(['category:id,slug', 'brand:id,slug'])
                ->each(function (SeoFilter $filter) use (&$items) {
                    if (! $filter->category || ! $filter->brand) {
                        return;
                    }

                    $items .= $this->urlEntry(
                        loc:        url("/{$filter->category->slug}/{$filter->brand->slug}"),
                        lastmod:    $filter->updated_at,
                        changefreq: 'weekly',
                        priority:   '0.7'
                    );
                });

            return $this->wrapUrlset($items);
        });

        return $this->xmlResponse($xml);
    }

    // ── Блог ──────────────────────────────────────────────────────

    public function blog(): Response
    {
        $xml = Cache::remember('sitemap.blog', self::TTL, function () {
            $items = '';

            BlogPost::active()
                ->select(['id', 'slug', 'title', 'cover_image', 'published_at', 'updated_at'])
                ->latest('published_at')
                ->each(function (BlogPost $post) use (&$items) {
                    $imageTag = '';
                    if ($post->cover_image) {
                        $imageTag = sprintf(
                            '<image:image><image:loc>%s</image:loc><image:title>%s</image:title></image:image>',
                            $this->escapeXml(asset('storage/' . $post->cover_image)),
                            $this->escapeXml($post->title)
                        );
                    }

                    $items .= $this->urlEntry(
                        loc:        url("/blog/{$post->slug}"),
                        lastmod:    $post->updated_at,
                        changefreq: 'monthly',
                        priority:   '0.6',
                        extra:      $imageTag
                    );
                });

            return $this->wrapUrlset($items, imageNs: true);
        });

        return $this->xmlResponse($xml);
    }

    // ══════════════════════════════════════════════════════════════
    // Приватные вспомогательные методы
    // ══════════════════════════════════════════════════════════════

    /**
     * ИСПРАВЛЕНИЕ LA-1:
     * Экранирование для XML — приватный метод вместо глобальной функции.
     * Глобальная esc() вызывала Fatal error при повторной загрузке файла.
     */
    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * ИСПРАВЛЕНИЕ SEO-2:
     * Возвращает реальную дату последнего изменения контента сайта.
     * Используется в индексном sitemap.
     */
    private function getLastUpdated(): Carbon
    {
        $dates = array_filter([
            Product::active()->max('updated_at'),
            Category::active()->max('updated_at'),
            Brand::active()->max('updated_at'),
        ]);

        if (empty($dates)) {
            return now()->subDay();
        }

        return Carbon::parse(max($dates));
    }

    /**
     * Формирует XML-блок <url>.
     */
    private function urlEntry(
        string      $loc,
        mixed       $lastmod    = null,
        string      $changefreq = 'weekly',
        string      $priority   = '0.5',
        string      $extra      = ''
    ): string {
        // Нормализуем lastmod в Carbon
        if (is_string($lastmod) && $lastmod) {
            $lastmod = Carbon::parse($lastmod);
        } elseif (! $lastmod instanceof Carbon) {
            $lastmod = now()->subDay();
        }

        return sprintf(
            '<url><loc>%s</loc><lastmod>%s</lastmod><changefreq>%s</changefreq><priority>%s</priority>%s</url>',
            $this->escapeXml($loc),
            $lastmod->toAtomString(),
            $changefreq,
            $priority,
            $extra
        );
    }

    /**
     * Оборачивает элементы в <urlset>.
     */
    private function wrapUrlset(string $items, bool $imageNs = false): string
    {
        $ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        if ($imageNs) {
            $ns .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><urlset %s>%s</urlset>',
            $ns,
            $items
        );
    }

    /**
     * HTTP-ответ с XML Content-Type и кэш-заголовками.
     */
    private function xmlResponse(string $xml): Response
    {
        return response($xml, 200, [
            'Content-Type'  => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
