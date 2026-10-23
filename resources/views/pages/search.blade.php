@extends('layouts.app')

@section('title', ($query ? 'Поиск: ' . $query . ' | ' : 'Поиск | ') . 'Autohimiki.kz')
@section('description', $query ? 'Результаты поиска по запросу «' . $query . '» на Autohimiki.kz — автохимия и детейлинг в Алматы.' : 'Поиск по каталогу автохимии Autohimiki.kz')

@section('noindex', true)

@section('content')
@php
    $col = $products ?? collect();
    $total = method_exists($col, 'total') ? $col->total() : $col->count();
    $hasResults = ($products ?? collect())->count() > 0;
    try {
        $categories = \App\Models\Category::whereNull('parent_id')->where('is_active', true)->limit(8)->get();
    } catch (\Throwable $_e) {
        $categories = collect();
    }
@endphp

<div class="ah-search-page">
    <div class="ah-container">

        {{-- Search hero --}}
        <div class="ah-search-hero">
            <nav class="ah-breadcrumbs" aria-label="Хлебные крошки">
                <a href="{{ route('home') }}">Главная</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">Поиск</span>
            </nav>
            @if($query)
                <h1>Поиск: <em>{{ $query }}</em></h1>
                <p class="ah-search-count">
                    @if($hasResults)
                        Найдено {{ $total }} {{ trans_choice('товар|товара|товаров', $total) }}
                    @else
                        По запросу «{{ $query }}» ничего не найдено
                    @endif
                </p>
            @else
                <h1>Поиск по каталогу</h1>
            @endif
        </div>

        {{-- Search form --}}
        <form action="{{ route('search') }}" method="GET" class="ah-search-form-wrap" role="search">
            <div class="ah-search-field">
                <label for="search-q" class="sr-only">Поиск товаров</label>
                <input id="search-q" type="search" name="q" value="{{ $query ?? '' }}"
                       class="ah-input ah-search-input"
                       placeholder="Например: Liqui Moly, очиститель стёкол..."
                       autocomplete="off" autofocus>
                <button type="submit" class="ah-button ah-button-orange">
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                    Найти
                </button>
            </div>
        </form>

        {{-- Results --}}
        @if($hasResults)
            <div class="ah-search-results">
                @foreach($products as $product)
                    <x-product.card :product="$product" layout="grid"/>
                @endforeach
            </div>

            @if(method_exists($products, 'hasPages') && $products->hasPages())
                <nav class="ah-pagination" aria-label="Страницы результатов">
                    @if($products->onFirstPage())
                        <span aria-disabled="true">←</span>
                    @else
                        <a href="{{ $products->previousPageUrl() }}" rel="prev" aria-label="Предыдущая страница">←</a>
                    @endif

                    @foreach($products->getUrlRange(max(1, $products->currentPage()-2), min($products->lastPage(), $products->currentPage()+2)) as $page => $url)
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

        @elseif($query)
            {{-- Empty state --}}
            <div class="ah-search-empty">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                     stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                <h2>Ничего не найдено</h2>
                <p>По запросу «{{ $query }}» товаров не нашлось.<br>Попробуйте другие слова или выберите категорию:</p>
                @if($categories->count())
                    <div class="ah-search-empty-cats">
                        @foreach($categories as $cat)
                            <a href="{{ $cat->url }}">{{ $cat->name }}</a>
                        @endforeach
                        <a href="{{ route('catalog') }}">Весь каталог →</a>
                    </div>
                @endif
            </div>
        @else
            {{-- No query yet — show categories --}}
            @if($categories->count())
                <div class="ah-search-empty-cats">
                    @foreach($categories as $cat)
                        <a href="{{ $cat->url }}">{{ $cat->name }}</a>
                    @endforeach
                    <a href="{{ route('catalog') }}">Весь каталог →</a>
                </div>
            @endif
        @endif

    </div>
</div>
@endsection
