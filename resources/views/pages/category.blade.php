@php
    $entity    = $seoFilter ?? null;
    $seoTitle  = $entity?->seoTitle() ?? $category->seoTitle();
    $seoDesc   = $entity?->seoDescription() ?? $category->seoDescription();
    $seoH1Text = $entity?->seoH1()  ?? $category->seoH1();

    // og:image
    $ogImage = $category->image
        ? asset('storage/' . ($category->image_webp ?? $category->image))
        : asset('img/og-default.jpg');
    $ogType  = 'website';
@endphp

@extends('layouts.app')

@section('title', $seoTitle)
@section('description', $seoDesc)

{{-- noindex при фильтрах и сортировке (не SEO-фильтры) --}}
@if($noindex ?? false)
@section('noindex', true)
@endif

@section('canonical')
<link rel="canonical" href="{{ $canonical }}">

{{-- rel prev/next для пагинации --}}
@if(($currentPage ?? 1) > 1 && $products->previousPageUrl())
    <link rel="prev" href="{{ $products->previousPageUrl() }}">
@endif
@if($products->hasMorePages())
    <link rel="next" href="{{ $products->nextPageUrl() }}">
@endif
@endsection

@section('breadcrumbs')
<x-ui.breadcrumbs :items="$breadcrumbs ?? []"/>
@endsection

@section('content')
<div class="container mx-auto px-4 py-8">

    {{-- SEO-текст сверху --}}
    @if($entity?->seo_text || $category->seo_text_top)
    <div class="prose prose-sm prose-gray max-w-none mb-6 text-gray-600">
        {!! $entity?->seo_text ?? $category->seo_text_top !!}
    </div>
    @endif

    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-6">
        {{ $seoH1Text }}
    </h1>

    {{-- Подкатегории (только для корневых категорий) --}}
    @if(! ($parent ?? null) && ($category->children?->count() ?? 0) > 0)
    <div class="flex flex-wrap gap-2 mb-6">
        @foreach($category->children as $child)
        <a href="{{ $child->url }}"
           class="px-4 py-2 bg-white border border-gray-200 rounded-full text-sm
                  font-medium text-gray-700 hover:border-primary-400
                  hover:text-primary-600 transition-colors">
            {{ $child->name }}
        </a>
        @endforeach
    </div>
    @endif

    <div class="flex flex-col lg:flex-row gap-6">

        {{-- ── SIDEBAR: ФИЛЬТРЫ ─────────────────────────────── --}}
        <aside class="lg:w-56 flex-shrink-0" x-data="{ open: false }">

            {{-- Кнопка фильтров на мобильном --}}
            <button @click="open = !open"
                    class="lg:hidden w-full flex items-center justify-between
                           px-4 py-3 bg-white border border-gray-200 rounded-xl
                           mb-3 font-medium text-sm">
                <span class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/>
                    </svg>
                    Фильтры
                </span>
                <svg class="w-4 h-4 text-gray-400 transition-transform duration-200"
                     :class="open ? 'rotate-180' : ''"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <form method="GET"
                  id="filter-form"
                  class="space-y-4"
                  :class="open ? 'block' : 'hidden lg:block'">

                {{-- Бренды --}}
                @if(($brands ?? collect())->count())
                <div class="bg-white border border-gray-100 rounded-xl p-4">
                    <h3 class="font-semibold text-gray-900 mb-3 text-sm">Бренд</h3>
                    <div class="space-y-2 max-h-52 overflow-y-auto pr-1">
                        @foreach($brands as $brand)
                        <label class="flex items-center gap-2.5 cursor-pointer group">
                            <input type="radio"
                                   name="brand"
                                   value="{{ $brand->id }}"
                                   {{ request('brand') == $brand->id ? 'checked' : '' }}
                                   class="w-4 h-4 text-primary-500
                                          focus:ring-primary-400 cursor-pointer">
                            <span class="text-sm text-gray-700 group-hover:text-primary-600
                                         transition-colors leading-tight">
                                {{ $brand->name }}
                            </span>
                        </label>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Цена --}}
                @if(($priceRange ?? null) && $priceRange->max_price > 0)
                <div class="bg-white border border-gray-100 rounded-xl p-4">
                    <h3 class="font-semibold text-gray-900 mb-3 text-sm">Цена, ₸</h3>
                    <div class="flex items-center gap-2">
                        <input type="number"
                               name="price_min"
                               value="{{ request('price_min', (int) $priceRange->min_price) }}"
                               min="0"
                               placeholder="от"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg
                                      text-sm focus:outline-none focus:ring-1
                                      focus:ring-primary-400">
                        <span class="text-gray-300 text-lg">—</span>
                        <input type="number"
                               name="price_max"
                               value="{{ request('price_max', (int) $priceRange->max_price) }}"
                               min="0"
                               placeholder="до"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg
                                      text-sm focus:outline-none focus:ring-1
                                      focus:ring-primary-400">
                    </div>
                </div>
                @endif

                {{-- Наличие --}}
                <div class="bg-white border border-gray-100 rounded-xl p-4">
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox"
                               name="in_stock"
                               value="1"
                               {{ request('in_stock') ? 'checked' : '' }}
                               class="w-4 h-4 text-primary-500
                                      focus:ring-primary-400 rounded cursor-pointer">
                        <span class="text-sm font-medium text-gray-700">
                            Только в наличии
                        </span>
                    </label>
                </div>

                {{-- Кнопки --}}
                <button type="submit" class="btn-primary w-full justify-center">
                    Применить
                </button>

                @if(request()->hasAny(['brand', 'price_min', 'price_max', 'in_stock']))
                <a href="{{ url()->current() }}"
                   class="block text-center text-sm text-gray-400
                          hover:text-gray-600 transition-colors">
                    × Сбросить фильтры
                </a>
                @endif
            </form>
        </aside>

        {{-- ── ТОВАРЫ ─────────────────────────────────────────── --}}
        <div class="flex-1 min-w-0">

            {{-- Сортировка + счётчик --}}
            <div class="flex items-center justify-between mb-4 gap-4 flex-wrap">
                <p class="text-sm text-gray-500">
                    Найдено:
                    <span class="font-semibold text-gray-900">
                        {{ $products->total() }}
                    </span>
                    {{ trans_choice('товар|товара|товаров', $products->total()) }}
                </p>

                <select onchange="window.location = this.value"
                        class="text-sm border border-gray-200 rounded-lg px-3 py-2
                               focus:outline-none focus:ring-1 focus:ring-primary-400
                               cursor-pointer">
                    @foreach([
                        'default'    => 'По умолчанию',
                        'price_asc'  => 'Цена: по возрастанию',
                        'price_desc' => 'Цена: по убыванию',
                        'new'        => 'Сначала новые',
                        'popular'    => 'Популярные',
                    ] as $val => $label)
                    <option value="{{ request()->fullUrlWithQuery(['sort' => $val, 'page' => null]) }}"
                            {{ ($sort ?? 'default') === $val ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                    @endforeach
                </select>
            </div>

            @if($products->count())
            {{-- Сетка товаров --}}
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($products as $product)
                    <x-product.card :product="$product"/>
                @endforeach
            </div>

            {{-- Пагинация --}}
            @if($products->hasPages())
            <div class="mt-8">
                {{ $products->links() }}
            </div>
            @endif

            @else
            {{-- Пустой результат --}}
            <div class="text-center py-16">
                <div class="text-5xl mb-4">🔍</div>
                <p class="text-lg font-medium text-gray-600 mb-2">
                    Товары не найдены
                </p>
                <p class="text-sm text-gray-400 mb-6">
                    Попробуйте изменить параметры фильтра
                </p>
                <a href="{{ url()->current() }}" class="btn-primary">
                    Сбросить фильтры
                </a>
            </div>
            @endif
        </div>
    </div>

    {{-- SEO-текст снизу --}}
    @if($category->seo_text_bottom)
    <div class="mt-12 pt-8 border-t border-gray-100
                prose prose-sm prose-gray max-w-none text-gray-600">
        {!! $category->seo_text_bottom !!}
    </div>
    @endif

</div>
@endsection

@section('schema')
{{-- BreadcrumbList для категории --}}
<x-schema.breadcrumbs :items="$breadcrumbs ?? []"/>
@endsection
