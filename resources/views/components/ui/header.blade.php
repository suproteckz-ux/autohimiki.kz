@php
    $phone = \App\Services\CacheService::setting('phone', '');
    $whatsapp = \App\Services\CacheService::setting('whatsapp', '');
@endphp
<header class="ah-header" x-data="{ mobileOpen: false }" @keydown.escape.window="if (mobileOpen) { mobileOpen = false; $refs.menuButton.focus() }">
    <div class="ah-topbar"><div class="ah-container"><span>Алматы · Автохимия и детейлинг</span><span>Доставка по Казахстану</span><span>Ежедневно 9:00–20:00</span></div></div>
    <div class="ah-container ah-header-main">
        <a class="ah-logo" href="{{ route('home') }}" aria-label="Autohimiki.kz — главная"><x-ui.brand-mark /><span>AUTOHIMIKI<span class="ah-orange">.KZ</span><small>АВТОХИМИЯ И ДЕТЕЙЛИНГ</small></span></a>
        <div class="ah-header-search"><x-ui.search /></div>
        <div class="ah-header-actions">
            <a class="ah-header-cart" href="{{ route('cart.index') }}" aria-label="Корзина, товаров: {{ array_sum(session('cart', [])) }}"><svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3h2l3 12h11l2-9H6M9 20h.01M18 20h.01" stroke-linecap="round"/></svg><span class="ah-cart-label">Корзина</span><span>({{ array_sum(session('cart', [])) }})</span></a>
            @if($phone)<a class="ah-header-phone" href="tel:{{ preg_replace('/\D/', '', $phone) }}">{{ $phone }}</a>@endif
            @if($whatsapp)<a class="ah-button ah-button-wa ah-header-wa" href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Написать в WhatsApp"><span class="ah-header-wa-dot" aria-hidden="true"></span><span class="ah-header-wa-arrow" aria-hidden="true">↗</span><span class="ah-wa-label">WhatsApp</span></a>@endif
            <button type="button" class="ah-menu-button" x-ref="menuButton" @click="mobileOpen = !mobileOpen" :aria-expanded="mobileOpen.toString()" aria-controls="mobile-navigation" aria-label="Открыть меню"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        </div>
    </div>
    <nav class="ah-container ah-desktop-nav" aria-label="Основная навигация">
        <a class="ah-catalog-link" href="{{ route('catalog') }}"><span aria-hidden="true">☰</span> Каталог товаров</a>
        <a href="{{ route('brands') }}">Бренды</a><a href="{{ route('blog') }}">Блог</a><a href="{{ route('home') }}#advantages">Почему мы</a><a href="#store-contacts">Контакты</a>
        <span class="ah-nav-note">Подберём уход для вашего авто</span>
    </nav>
    <nav id="mobile-navigation" class="ah-mobile-menu" x-cloak x-show="mobileOpen" aria-label="Мобильная навигация" @click.outside="mobileOpen = false">
        <a href="{{ route('catalog') }}">Каталог товаров</a><a href="{{ route('brands') }}">Бренды</a><a href="{{ route('blog') }}">Блог</a><a href="#store-contacts" @click="mobileOpen = false">Контакты</a>
        @if($phone)<a href="tel:{{ preg_replace('/\D/', '', $phone) }}">{{ $phone }}</a>@endif
    </nav>
</header>
