@extends('layouts.app')
@section('title', 'Автохимия в Алматы — купить автохимию | ' . config('app.name'))
@section('description', 'Интернет-магазин автохимии в Алматы. Автошампуни, полироли, антидождь, детейлинг. Бесплатная консультация, доставка по Казахстану.')
@section('canonical')
<link rel="canonical" href="{{ url('/') }}">
@endsection
@section('content')
@php
    $wa = \App\Services\CacheService::setting('whatsapp', '');
@endphp
<div class="ah-home">
<section class="ah-hero">
    <div class="ah-container ah-hero-grid">
        <div class="ah-hero-copy">
            <p class="ah-eyebrow">Алматы · Автохимия · Детейлинг</p>
            <h1>Автохимия<br><span>для вашего авто</span></h1>
            <p class="ah-hero-description">Автошампуни, полироли, антидождь, детейлинг.<br>Проверенные бренды. Подберём средства для ухода бесплатно.</p>
            <div class="ah-hero-actions"><a class="ah-button ah-button-orange" href="{{ route('catalog') }}">Перейти в каталог <span aria-hidden="true">→</span></a>
                @if($hits->count())<a class="ah-hero-secondary" href="#home-hits">Популярные товары</a>@endif
            </div>
            <div class="ah-stats">@foreach([['800+', 'товаров'], ['30+', 'брендов'], ['5 лет', 'на рынке']] as [$value, $label])<div><strong>{{ $value }}</strong><span>{{ $label }}</span></div>@endforeach</div>
        </div>
        <div class="ah-hero-visual">
            <picture class="ah-hero-media">
                <source media="(max-width: 768px)" srcset="{{ asset('images/hero/autohimiki-hero-mobile.webp') }}" type="image/webp">
                <img src="{{ asset('images/hero/autohimiki-hero.webp') }}"
                     alt="Автохимия и средства для профессионального ухода за автомобилем"
                     width="1031" height="580"
                     loading="eager" decoding="async" fetchpriority="high">
            </picture>
        </div>
    </div>
</section>
@if($categories->count())
<section class="ah-section ah-section-muted" id="home-categories"><div class="ah-container">
    <x-ui.section-heading title="Категории товаров" :href="route('catalog')" link="Все категории" />
    <div class="ah-category-grid ah-home-category-grid">@foreach($categories as $category)<x-category.card :category="$category" variant="handoff" />@endforeach</div>
    <a class="ah-all-categories" href="{{ route('catalog') }}">Все {{ $categories->count() }} категорий <span aria-hidden="true">→</span></a>
</div></section>
@endif
@if($hits->count())
<section class="ah-section" id="home-hits"><div class="ah-container">
    <x-ui.section-heading title="Хиты продаж" :href="route('catalog')" />
    <div class="ah-product-grid ah-home-product-grid">@foreach($hits as $product)<x-product.card :product="$product" variant="handoff" />@endforeach</div>
</div></section>
@endif
<section class="ah-trust" id="advantages"><div class="ah-container ah-trust-grid">
    @foreach([['Оригинальная продукция', 'Только сертифицированные товары от официальных поставщиков'], ['Доставка по Алматы', 'Быстрая доставка по городу или самовывоз'], ['Бесплатная консультация', 'Подберём подходящий продукт под ваш автомобиль'], ['Работаем 7 дней', 'Ежедневно с 9:00 до 20:00, без выходных']] as [$title, $text])<div><h2>{{ $title }}</h2><p>{{ $text }}</p></div>@endforeach
</div></section>
@if($newProducts->count())
<section class="ah-section ah-section-muted" id="home-new"><div class="ah-container">
    <x-ui.section-heading title="Новинки" :href="route('catalog')" link="Смотреть каталог" />
    <div class="ah-product-grid">@foreach($newProducts as $product)<x-product.card :product="$product" />@endforeach</div>
</div></section>
@endif
@if($brands->count())
<section class="ah-section ah-brands"><div class="ah-container">
    <x-ui.section-heading title="Популярные бренды" :href="route('brands')" link="Все бренды" />
    <div class="ah-brand-grid">@foreach($brands as $brand)<a href="{{ $brand->url ?? url('/brand/' . $brand->slug) }}">@if($brand->logo)<img src="{{ asset('storage/' . ($brand->logo_webp ?? $brand->logo)) }}" alt="{{ $brand->name }}" loading="lazy" width="140" height="50">@else<span>{{ $brand->name }}</span>@endif</a>@endforeach</div>
</div></section>
@endif
<section class="ah-section ah-seo"><div class="ah-container ah-seo-grid">
    <div><h2>Автохимия в Алматы — купить с доставкой по Казахстану</h2><p>Автохимия и детейлинг в Алматы. Проверенные бренды, широкий ассортимент, быстрая консультация.</p><p>В каталоге — автошампуни, полироли, антидождь и средства для детейлинга. Выбирайте товары по категории и бренду, смотрите актуальные цены и наличие в карточках.</p><p>Нужна помощь с выбором? Бесплатно подберём подходящий продукт для вашего автомобиля. Доступна доставка по Алматы или самовывоз.</p><a href="{{ route('catalog') }}">Перейти в каталог <span aria-hidden="true">→</span></a></div>
    @if($categories->count())<aside><h3>Популярные разделы</h3>@foreach($categories->take(6) as $category)<a href="{{ $category->url }}">{{ $category->name }} <span aria-hidden="true">↗</span></a>@endforeach</aside>@endif
</div></section>
<section class="ah-consult"><div class="ah-container"><div><h2>Нужна консультация?</h2><p>Напишите нам — подберём подходящий продукт для вашего автомобиля.</p></div>@if($wa)<a class="ah-button ah-button-deep-green" href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener">Написать в WhatsApp ↗</a>@else<button type="button" class="ah-button ah-button-dark" x-data @click="$dispatch('open-lead-modal')">Получить консультацию</button>@endif</div></section>
</div>
@endsection
