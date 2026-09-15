@extends('layouts.app')

@section('title', 'Catalog | Autohimiki.kz')
@section('canonical')
<link rel="canonical" href="{{ $canonical }}">
@endsection

@section('content')
<div class="ah-catalog-index">
    <header class="ah-catalog-hero">
        <div class="ah-container">
            <nav class="ah-breadcrumbs" aria-label="Хлебные крошки"><a href="{{ route('home') }}">Главная</a><span aria-hidden="true">/</span><span aria-current="page">Каталог</span></nav>
            <p class="ah-catalog-kicker">Каталог товаров</p>
            <h1>Автохимия и детейлинг</h1>
            <p class="ah-catalog-intro">Выберите категорию товаров для ухода, защиты и профессионального детейлинга автомобиля.</p>
        </div>
    </header>
    <section class="ah-section ah-section-muted">
        <div class="ah-container">
            <x-ui.section-heading title="Категории товаров"/>
            <div class="ah-category-grid ah-catalog-category-grid">
                @forelse($categories as $category)
                    <x-category.card :category="$category"/>
                @empty
                    <p class="ah-catalog-empty">Категории пока не добавлены.</p>
                @endforelse
            </div>
        </div>
    </section>
</div>
@endsection
