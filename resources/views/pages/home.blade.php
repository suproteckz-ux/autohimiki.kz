@extends('layouts.app')
@section('title', 'Автохимия в Алматы — купить автокосметику | Autohimiki.kz')
@section('description', 'Автохимия и автокосметика в Алматы в интернет-магазине Autohimiki.kz. Средства для ухода за кузовом, салоном и автомобилем. Самовывоз, доставка по Алматы и Казахстану.')
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
            <h1>Автохимия и автокосметика <span>в Алматы</span></h1>
            <p class="ah-hero-description">Средства для ухода за кузовом и салоном, автошампуни, очистители, полироли, присадки и профессиональная автохимия.</p>
            <p class="ah-hero-availability">В наличии в Алматы · Доставка по городу и Казахстану</p>
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
    @foreach([['Оригинальная продукция', 'Автохимия и автокосметика проверенных производителей.'], ['Доставка по Алматы', 'Доставка по городу или самовывоз из нашего магазина. Отправляем заказы по Казахстану.'], ['Поможем с выбором', 'Подскажем, какое средство подойдет для конкретной задачи и как правильно его использовать.'], ['Магазин в Алматы', 'Пн–Пт: 09:00–18:00 · Сб: 11:00–16:00 · Вс: выходной']] as [$title, $text])<div><h2>{{ $title }}</h2><p>{{ $text }}</p></div>@endforeach
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
<section class="ah-section ah-seo" id="home-seo"><div class="ah-container ah-seo-grid">
    <div>
        <h2>Интернет-магазин автохимии и автокосметики в Алматы</h2>
        <p>Autohimiki.kz — магазин автохимии и средств для ухода за автомобилем в Алматы. В каталоге представлены товары для самостоятельного ухода за автомобилем, профессионального детейлинга, мойки и технического обслуживания.</p>
        <p>У нас можно подобрать <x-home.category-link :categories="$categories" name="Автошампуни">автошампуни</x-home.category-link>, очистители кузова и салона, средства для ухода за кожей и пластиком, <x-home.category-link :categories="$categories" name="Полироли">полироли</x-home.category-link>, защитные составы, <x-home.category-link :categories="$categories" name="Смазки">смазки</x-home.category-link>, <x-home.category-link :categories="$categories" name="Присадки">присадки</x-home.category-link>, средства для <x-home.category-link :categories="$categories" name="Раскоксовка двигателя">раскоксовки двигателя</x-home.category-link>, очистители топливной системы, <x-home.category-link :categories="$categories" name="Ароматизаторы">ароматизаторы</x-home.category-link> и другую автохимию.</p>
        <h3>Автохимия для разных задач</h3>
        <p>Каталог разделен по назначению, чтобы нужное средство было проще найти. Для ухода за автомобилем представлены составы для мойки, очистки и защиты кузова, стекол, пластика, кожи и других поверхностей. Для технического обслуживания можно подобрать присадки, очистители, смазки и специализированную автомобильную химию.</p>
        <p>В ассортименте представлены продукты Shine Systems, Ma-Fra, Turtle Wax, LAVR, Eikosha и других производителей.</p>
        <h3>Купить автохимию в Алматы</h3>
        <p>Товары можно заказать через интернет-магазин Autohimiki.kz. В Алматы доступны самовывоз и доставка по городу. Заказы в другие города Казахстана отправляем Казпочтой.</p>
        <p>Если покупатель не знает, какое средство подойдет для конкретной задачи, он может обратиться к нам за консультацией по выбору и применению продукции.</p>
        <a href="{{ route('catalog') }}">Перейти в каталог <span aria-hidden="true">→</span></a>
    </div>
    @if($categories->count())<aside><h3>Популярные разделы</h3>@foreach($categories->take(6) as $category)<a href="{{ $category->url }}">{{ $category->name }} <span aria-hidden="true">↗</span></a>@endforeach</aside>@endif
</div></section>
<section class="ah-section ah-home-business" aria-labelledby="home-business-title"><div class="ah-container">
    <h2 id="home-business-title">Работаем с юридическими лицами</h2>
    <p>Autohimiki.kz работает с организациями и индивидуальными предпринимателями. Предоставляем необходимые бухгалтерские документы.</p>
    <p><strong>Работаем с НДС. Цены на сайте указаны с учетом НДС.</strong></p>
    <p>Для оформления заказа от юридического лица клиент может <a href="#store-contacts">связаться с магазином</a> для получения документов и согласования заказа.</p>
</div></section>
<section class="ah-consult"><div class="ah-container"><div><h2>Нужна консультация?</h2><p>Напишите нам — подберём подходящий продукт для вашего автомобиля.</p></div>@if($wa)<a class="ah-button ah-button-deep-green" href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener">Написать в WhatsApp ↗</a>@else<button type="button" class="ah-button ah-button-dark" x-data @click="$dispatch('open-lead-modal')">Получить консультацию</button>@endif</div></section>
</div>
@endsection
