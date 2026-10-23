@php
    $phone       = \App\Services\CacheService::setting('phone', '');
    $whatsapp    = \App\Services\CacheService::setting('whatsapp', '');
    $cartCount   = array_sum(session('cart', []));
    try {
        $menuCats = \App\Services\CacheService::homepageCategories();
    } catch (\Throwable $e) {
        $menuCats = collect();
    }
@endphp
<header class="ah-header"
        x-data="{ mobileOpen: false }"
        @keydown.escape.window="if (mobileOpen) { mobileOpen = false; $refs.menuButton.focus() }">

    {{-- Topbar (hidden on mobile ≤ 768px) --}}
    <div class="ah-topbar">
        <div class="ah-container">
            <span>Алматы · Автохимия и детейлинг</span>
            <nav class="ah-topbar-nav" aria-label="Дополнительная навигация">
                <a href="{{ route('brands') }}">Бренды</a>
                <a href="{{ route('blog') }}">Блог</a>
                <a href="{{ route('home') }}#advantages">О нас</a>
                <a href="#store-contacts">Контакты</a>
            </nav>
            <span>Пн–Пт: 09:00–18:00 · Сб: 11:00–16:00 · Вс: выходной</span>
        </div>
    </div>

    {{-- Main header row --}}
    <div class="ah-container ah-header-main">

        {{-- Menu button (first on mobile via order:-2 in CSS) --}}
        <button type="button"
                class="ah-menu-button"
                x-ref="menuButton"
                @click="mobileOpen = true"
                :aria-expanded="mobileOpen.toString()"
                aria-controls="mobile-navigation"
                aria-label="Открыть меню">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        {{-- Logo (second on mobile via order:-1 in CSS) --}}
        <a class="ah-logo" href="{{ route('home') }}" aria-label="Autohimiki.kz — главная">
            <x-ui.brand-mark />
            <span>AUTOHIMIKI<span class="ah-orange">.KZ</span><small>АВТОХИМИЯ И ДЕТЕЙЛИНГ</small></span>
        </a>

        {{-- Search (wraps to second row on mobile) --}}
        <div class="ah-header-search"><x-ui.search /></div>

        {{-- Right actions --}}
        <div class="ah-header-actions">

            {{-- Desktop only: phone number text --}}
            @if($phone)
            <a class="ah-header-phone" href="tel:{{ preg_replace('/\D/', '', $phone) }}">{{ $phone }}</a>
            @endif

            {{-- Mobile: phone icon (44×44) --}}
            @if($phone)
            <a class="ah-header-phone-icon"
               href="tel:{{ preg_replace('/\D/', '', $phone) }}"
               aria-label="Позвонить: {{ $phone }}">
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
            </a>
            @endif

            {{-- Desktop: WhatsApp button --}}
            @if($whatsapp)
            <a class="ah-button ah-button-wa ah-header-wa"
               href="https://wa.me/{{ $whatsapp }}"
               target="_blank" rel="noopener"
               aria-label="Написать в WhatsApp">
                <span class="ah-header-wa-dot" aria-hidden="true"></span>
                <span class="ah-header-wa-arrow" aria-hidden="true">↗</span>
                <span class="ah-wa-label">WhatsApp</span>
            </a>
            @endif

            {{-- Cart with icon + counter --}}
            <a class="ah-header-cart"
               href="{{ route('cart.index') }}"
               aria-label="Корзина, товаров: {{ $cartCount }}">
                {{-- Desktop: text label --}}
                <span class="ah-cart-label">Корзина</span>
                <span class="ah-cart-text-count">({{ $cartCount }})</span>
                {{-- Mobile: SVG icon --}}
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <path d="M16 10a4 4 0 0 1-8 0"/>
                </svg>
                @if($cartCount > 0)
                <span class="ah-header-cart-count" aria-hidden="true">{{ $cartCount }}</span>
                @endif
            </a>

        </div>
    </div>

    {{-- Mobile backdrop --}}
    <div class="ah-mobile-menu-backdrop"
         :class="{ 'is-open': mobileOpen }"
         @click="mobileOpen = false"
         aria-hidden="true"
         x-cloak></div>

    {{-- Mobile menu — bottom sheet --}}
    <nav id="mobile-navigation"
         class="ah-mobile-menu"
         :class="{ 'is-open': mobileOpen }"
         aria-label="Мобильная навигация"
         :aria-hidden="(!mobileOpen).toString()"
         x-cloak>

        <div class="ah-mobile-menu-head">
            <h2>Меню</h2>
            <button type="button"
                    class="ah-mobile-menu-close"
                    @click="mobileOpen = false; $refs.menuButton.focus()"
                    aria-label="Закрыть меню">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="ah-mobile-menu-cats">
            <a href="{{ route('catalog') }}">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                </svg>
                Все товары
            </a>
            @foreach($menuCats as $cat)
            <a href="{{ route('catalog.category', $cat->slug) }}">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 18l6-6-6-6"/>
                </svg>
                {{ $cat->name }}
            </a>
            @endforeach
        </div>

        <div class="ah-mobile-menu-contacts">
            <h3>Контакты</h3>
            @if($phone)
            <a href="tel:{{ preg_replace('/\D/', '', $phone) }}">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
                {{ $phone }}
            </a>
            @endif
            @if($whatsapp)
            <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
                WhatsApp
            </a>
            @endif
        </div>
    </nav>

</header>
