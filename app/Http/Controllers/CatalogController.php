<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SeoFilter;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    private const PER_PAGE = 24;

    // ──────────────────────────────────────────────────────────────
    // /catalog — список всех корневых категорий
    // ──────────────────────────────────────────────────────────────

    public function index()
    {
        $categories = Category::active()
            ->root()
            ->ordered()
            ->withCount('products')
            ->with('children')
            ->get();

        return view('pages.catalog', [
            'categories' => $categories,
            'canonical'  => url('/catalog'),
            'noindex'    => false,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // /catalog/{category}
    // ──────────────────────────────────────────────────────────────

    public function category(Request $request, string $categorySlug)
    {
        $category = Category::active()
            ->where('slug', $categorySlug)
            ->whereNull('parent_id')
            ->with('children')
            ->firstOrFail();

        return $this->renderCategoryPage($request, $category);
    }

    // ──────────────────────────────────────────────────────────────
    // /catalog/{parent}/{child}
    // ──────────────────────────────────────────────────────────────

    public function subcategory(Request $request, string $parentSlug, string $childSlug)
    {
        $parent = Category::active()
            ->where('slug', $parentSlug)
            ->whereNull('parent_id')
            ->firstOrFail();

        $category = Category::active()
            ->where('slug', $childSlug)
            ->where('parent_id', $parent->id)
            ->firstOrFail();

        $category->setRelation('parent', $parent);

        return $this->renderCategoryPage($request, $category, $parent);
    }

    // ──────────────────────────────────────────────────────────────
    // Общая логика рендера страницы категории
    // ──────────────────────────────────────────────────────────────

    private function renderCategoryPage(
        Request   $request,
        Category  $category,
        ?Category $parent = null
    ) {
        // ID текущей категории + всех дочерних
        $categoryIds = collect([$category->id]);
        if ($category->children && $category->children->isNotEmpty()) {
            $categoryIds = $categoryIds->merge($category->children->pluck('id'));
        }

        // Бренды с товарами в этой категории (для фильтра)
        $brands = Brand::active()
            ->whereHas('products', fn ($q) =>
                $q->active()->whereIn('category_id', $categoryIds)
            )
            ->ordered()
            ->get();

        // Базовый запрос товаров
        $query = Product::active()
            ->whereIn('category_id', $categoryIds)
            ->with(['brand:id,name,slug', 'category:id,name,slug,parent_id']);

        // ── Применяем фильтры ─────────────────────────────────────

        $hasFilters = false;

        if ($request->filled('brand')) {
            $query->where('brand_id', (int) $request->brand);
            $hasFilters = true;
        }
        if ($request->filled('in_stock')) {
            $query->where('in_stock', true);
            $hasFilters = true;
        }
        if ($request->filled('price_min')) {
            $query->where('price', '>=', (float) $request->price_min);
            $hasFilters = true;
        }
        if ($request->filled('price_max')) {
            $query->where('price', '<=', (float) $request->price_max);
            $hasFilters = true;
        }

        // ── Сортировка ────────────────────────────────────────────

        $sort        = $request->get('sort', 'default');
        $hasSort     = $sort !== 'default';

        match ($sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'new'        => $query->latest(),
            'popular'    => $query->orderByDesc('views'),
            default      => $query->orderBy('sort_order')->orderByDesc('is_hit'),
        };

        $products = $query->paginate(self::PER_PAGE)->withQueryString();

        // ── Ценовой диапазон для слайдера ─────────────────────────

        $priceRange = Product::active()
            ->whereIn('category_id', $categoryIds)
            ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
            ->first();

        // ── SEO-фильтр (если подходит) ────────────────────────────

        $seoFilter = null;
        if ($request->filled('brand') && ! $hasSort) {
            $brand = Brand::find((int) $request->brand);
            if ($brand) {
                $seoFilter = SeoFilter::active()
                    ->where('category_id', $category->id)
                    ->where('brand_id', $brand->id)
                    ->first();
            }
        }

        // ── Canonical и noindex ───────────────────────────────────

        // Базовый URL категории (без параметров)
        $baseUrl = $parent
            ? url("/catalog/{$parent->slug}/{$category->slug}")
            : url("/catalog/{$category->slug}");

        $currentPage = (int) $request->get('page', 1);

        if ($seoFilter) {
            // SEO-фильтр: canonical на страницу SEO-фильтра, индексируем
            $canonical = url("/{$category->slug}/{$seoFilter->brand->slug}");
            $noindex   = false;
        } elseif ($hasFilters || $hasSort) {
            // Обычный фильтр/сортировка: noindex + canonical на базовый URL
            $canonical = $baseUrl;
            $noindex   = true;
        } elseif ($currentPage > 1) {
            // Страница пагинации 2+: canonical на себя (каждая страница уникальна)
            $canonical = $products->url($currentPage);
            $noindex   = false;
        } else {
            // Чистая страница категории
            $canonical = $baseUrl;
            $noindex   = false;
        }

        // ── Хлебные крошки ────────────────────────────────────────

        $breadcrumbs = array_values(array_filter([
            ['name' => 'Каталог', 'url' => route('catalog')],
            $parent ? ['name' => $parent->name, 'url' => url("/catalog/{$parent->slug}")] : null,
            ['name' => $category->name, 'url' => $baseUrl],
        ]));

        return view('pages.category', compact(
            'category',
            'parent',
            'products',
            'brands',
            'priceRange',
            'seoFilter',
            'sort',
            'canonical',
            'noindex',
            'currentPage',
            'breadcrumbs'
        ));
    }
}
