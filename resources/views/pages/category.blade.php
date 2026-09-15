@php
    $entity    = $seoFilter ?? null;
    $seoTitle  = $entity?->seoTitle() ?? $category->seoTitle();
    $seoDesc   = $entity?->seoDescription() ?? $category->seoDescription();
    $seoH1Text = $entity?->seoH1() ?? $category->seoH1();
    $ogImage = $category->image
        ? asset('storage/' . ($category->image_webp ?? $category->image))
        : asset('img/og-default.jpg');
    $ogType = 'website';
    $selectedBrand = ($brands ?? collect())->firstWhere('id', (int) request('brand'));
    $filterCount = collect(['brand', 'price_min', 'price_max', 'in_stock'])
        ->filter(fn ($key) => request()->filled($key))
        ->count();
@endphp

@extends('layouts.app')

@section('title', $seoTitle)
@section('description', $seoDesc)

@if($noindex ?? false)
@section('noindex', true)
@endif

@section('canonical')
<link rel="canonical" href="{{ $canonical }}">
@if(($currentPage ?? 1) > 1 && $products->previousPageUrl())
    <link rel="prev" href="{{ $products->previousPageUrl() }}">
@endif
@if($products->hasMorePages())
    <link rel="next" href="{{ $products->nextPageUrl() }}">
@endif
@endsection

@section('content')
<div class="ah-catalog-page" x-data="{ filtersOpen: false }" @keydown.escape.window="if (filtersOpen) { filtersOpen = false; $refs.filterButton.focus() }">
    <header class="ah-catalog-hero">
        <div class="ah-container">
            <x-ui.breadcrumbs :items="$breadcrumbs ?? []"/>
            <p class="ah-catalog-kicker">Каталог · {{ $products->total() }} {{ trans_choice('товар|товара|товаров', $products->total()) }}</p>
            <h1>{{ $seoH1Text }}</h1>
            @if($entity?->seo_text || $category->seo_text_top)
                <div class="ah-catalog-intro">{!! $entity?->seo_text ?? $category->seo_text_top !!}</div>
            @elseif($seoDesc)
                <p class="ah-catalog-intro">{{ $seoDesc }}</p>
            @endif

            @if(! ($parent ?? null) && ($category->children?->count() ?? 0) > 0)
                <nav class="ah-subcategories" aria-label="Подкатегории">
                    @foreach($category->children as $child)
                        <a href="{{ $child->url }}">{{ $child->name }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
    </header>

    <div class="ah-catalog-body">
        <div class="ah-container">
            <div class="ah-catalog-toolbar">
                <button type="button" class="ah-filter-trigger" x-ref="filterButton"
                        @click="filtersOpen = true; $nextTick(() => $refs.filterClose.focus())"
                        :aria-expanded="filtersOpen.toString()" aria-controls="catalog-filters">
                    <span aria-hidden="true">☷</span> Фильтры
                    @if($filterCount)<b>{{ $filterCount }}</b>@endif
                </button>

                <p class="ah-results-count">Найдено <strong>{{ $products->total() }}</strong> {{ trans_choice('товар|товара|товаров', $products->total()) }}</p>

                <label class="ah-sort-control">
                    <span>Сортировка</span>
                    <select aria-label="Сортировка товаров" onchange="window.location = this.value">
                        @foreach([
                            'default' => 'По умолчанию',
                            'price_asc' => 'Сначала дешевле',
                            'price_desc' => 'Сначала дороже',
                            'new' => 'Сначала новые',
                            'popular' => 'Популярные',
                        ] as $val => $label)
                            <option value="{{ request()->fullUrlWithQuery(['sort' => $val, 'page' => null]) }}" {{ ($sort ?? 'default') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            @if($filterCount)
                <div class="ah-active-filters" aria-label="Активные фильтры">
                    <span>Выбрано:</span>
                    @if($selectedBrand)<b>{{ $selectedBrand->name }}</b>@endif
                    @if(request('price_min'))<b>от {{ number_format((float) request('price_min'), 0, '.', ' ') }} ₸</b>@endif
                    @if(request('price_max'))<b>до {{ number_format((float) request('price_max'), 0, '.', ' ') }} ₸</b>@endif
                    @if(request('in_stock'))<b>В наличии</b>@endif
                    <a href="{{ url()->current() }}">Сбросить всё</a>
                </div>
            @endif

            <div class="ah-catalog-layout">
                <div class="ah-filter-backdrop" x-cloak x-show="filtersOpen" x-transition.opacity @click="filtersOpen = false"></div>
                <aside id="catalog-filters" class="ah-filter-panel" :class="filtersOpen ? 'is-open' : ''" aria-label="Фильтры товаров">
                    <div class="ah-filter-heading">
                        <h2>Фильтры</h2>
                        <button type="button" class="ah-filter-close" x-ref="filterClose" @click="filtersOpen = false" aria-label="Закрыть фильтры">×</button>
                        @if($filterCount)<a href="{{ url()->current() }}">Сбросить</a>@endif
                    </div>

                    <form method="GET" class="ah-filter-form">
                        @if(($sort ?? 'default') !== 'default')
                            <input type="hidden" name="sort" value="{{ $sort }}">
                        @endif

                        @if(($priceRange ?? null) && $priceRange->max_price > 0)
                            <fieldset class="ah-filter-group">
                                <legend>Цена, ₸</legend>
                                <div class="ah-price-fields">
                                    <label><span>От</span><input type="number" name="price_min" value="{{ request('price_min') }}" min="0" placeholder="{{ (int) $priceRange->min_price }}"></label>
                                    <label><span>До</span><input type="number" name="price_max" value="{{ request('price_max') }}" min="0" placeholder="{{ (int) $priceRange->max_price }}"></label>
                                </div>
                                <p>{{ number_format((float) $priceRange->min_price, 0, '.', ' ') }} — {{ number_format((float) $priceRange->max_price, 0, '.', ' ') }} ₸</p>
                            </fieldset>
                        @endif

                        @if(($brands ?? collect())->count())
                            <fieldset class="ah-filter-group">
                                <legend>Бренд</legend>
                                <div class="ah-filter-options">
                                    @foreach($brands as $brand)
                                        <label>
                                            <input type="radio" name="brand" value="{{ $brand->id }}" {{ request('brand') == $brand->id ? 'checked' : '' }}>
                                            <span>{{ $brand->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif

                        <fieldset class="ah-filter-group ah-stock-filter">
                            <legend>Наличие</legend>
                            <label><input type="checkbox" name="in_stock" value="1" {{ request('in_stock') ? 'checked' : '' }}><span>Только в наличии</span></label>
                        </fieldset>

                        <div class="ah-filter-actions">
                            @if($filterCount)<a href="{{ url()->current() }}">Сбросить</a>@endif
                            <button type="submit">Показать товары</button>
                        </div>
                    </form>
                </aside>

                <section class="ah-catalog-products" aria-label="Товары категории">
                    @if($products->count())
                        <div class="ah-product-grid ah-catalog-product-grid">
                            @foreach($products as $product)
                                <x-product.card :product="$product"/>
                            @endforeach
                        </div>

                        @if($products->hasPages())
                            <nav class="ah-pagination" aria-label="Страницы каталога">
                                @if($products->onFirstPage())
                                    <span aria-disabled="true">←</span>
                                @else
                                    <a href="{{ $products->previousPageUrl() }}" rel="prev" aria-label="Предыдущая страница">←</a>
                                @endif

                                @foreach($products->getUrlRange(max(1, $products->currentPage() - 2), min($products->lastPage(), $products->currentPage() + 2)) as $page => $url)
                                    @if($page === $products->currentPage())
                                        <span class="is-current" aria-current="page">{{ $page }}</span>
                                    @else
                                        <a href="{{ $url }}" aria-label="Страница {{ $page }}">{{ $page }}</a>
                                    @endif
                                @endforeach

                                @if($products->hasMorePages())
                                    <a href="{{ $products->nextPageUrl() }}" rel="next" aria-label="Следующая страница">→</a>
                                @else
                                    <span aria-disabled="true">→</span>
                                @endif
                            </nav>
                        @endif
                    @else
                        <div class="ah-empty-state">
                            <span aria-hidden="true">⌕</span>
                            <h2>Товары не найдены</h2>
                            <p>Попробуйте изменить параметры фильтра.</p>
                            <a href="{{ url()->current() }}">Сбросить фильтры</a>
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </div>

    @if($category->seo_text_bottom)
        <section class="ah-category-seo">
            <div class="ah-container">
                <h2>{{ $category->name }}: выбор и применение</h2>
                <div class="ah-category-seo-copy">{!! $category->seo_text_bottom !!}</div>
            </div>
        </section>
    @endif
</div>
@endsection

@section('schema')
<x-schema.breadcrumbs :items="$breadcrumbs ?? []"/>
@endsection
