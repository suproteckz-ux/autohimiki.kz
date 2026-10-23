@php
    $phone     = \App\Services\CacheService::setting('phone', '');
    $whatsapp  = \App\Services\CacheService::setting('whatsapp', '');
    $address   = \App\Services\CacheService::setting('address', 'Алматы, Казахстан');
    $instagram = \App\Services\CacheService::setting('instagram', '');
    $email     = \App\Services\CacheService::setting('email', '');
    $cartCount = array_sum(session('cart', []));
@endphp

<footer class="ah-footer" id="store-contacts">
    <div class="ah-container">
        <div class="ah-footer-grid">

            {{-- About column (always visible) --}}
            <div class="ah-footer-about">
                <a class="ah-logo" href="{{ route('home') }}">
                    <x-ui.brand-mark />
                    <span>AUTOHIMIKI<span class="ah-orange">.KZ</span></span>
                </a>
                <p>Автохимия и детейлинг в Алматы.<br>
                   Проверенные бренды, широкий ассортимент, быстрая консультация.</p>
            </div>

            {{-- Catalog column (accordion on mobile) --}}
            <details class="ah-footer-group" open>
                <summary class="ah-footer-group-toggle">
                    <h2>Каталог</h2>
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </summary>
                <div class="ah-footer-group-content">
                    <nav aria-label="Каталог в подвале">
                        <a href="{{ route('catalog') }}">Все товары</a>
                        <a href="{{ route('brands') }}">Бренды</a>
                        <a href="{{ route('blog') }}">Блог</a>
                    </nav>
                </div>
            </details>

            {{-- Buyers column (accordion on mobile) --}}
            <details class="ah-footer-group" open>
                <summary class="ah-footer-group-toggle">
                    <h2>Покупателям</h2>
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </summary>
                <div class="ah-footer-group-content">
                    <p>Доставка по Алматы</p>
                    <p>Самовывоз</p>
                    <p>Консультация бесплатно</p>
                    @if($whatsapp)
                    <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">Написать в WhatsApp ↗</a>
                    @endif
                </div>
            </details>

            {{-- Contacts column (always open, including on mobile) --}}
            <details class="ah-footer-group ah-footer-contacts-group" open>
                <summary class="ah-footer-group-toggle">
                    <h2>Контакты</h2>
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </summary>
                <div class="ah-footer-group-content">
                    <address>
                        @if($address)<p>{{ $address }}</p>@endif
                        @if($phone)<a href="tel:{{ preg_replace('/\D/', '', $phone) }}">{{ $phone }}</a>@endif
                        @if($email)<a href="mailto:{{ $email }}">{{ $email }}</a>@endif
                        @if($instagram)<a href="{{ $instagram }}" target="_blank" rel="noopener">Instagram ↗</a>@endif
                    </address>
                </div>
            </details>

        </div>

        <div class="ah-footer-bottom">
            <span>© {{ date('Y') }} {{ config('app.name') }}. Все права защищены.</span>
            <span>Автохимия в Алматы, Казахстан</span>
        </div>
    </div>
</footer>

{{-- Bottom navigation — visible ≤ 1023px --}}
<nav class="ah-bottom-nav" aria-label="Быстрая навигация">

    {{-- Главная --}}
    <a href="{{ route('home') }}"
       @if(request()->routeIs('home')) aria-current="page" @endif>
        <svg aria-hidden="true" viewBox="0 0 24 24">
            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        <span class="ah-nav-label">Главная</span>
    </a>

    {{-- Каталог --}}
    <a href="{{ route('catalog') }}"
       @if(request()->routeIs('catalog') || request()->routeIs('category.*')) aria-current="page" @endif>
        <svg aria-hidden="true" viewBox="0 0 24 24">
            <rect x="3" y="3" width="7" height="7" rx="1"/>
            <rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="14" y="14" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/>
        </svg>
        <span class="ah-nav-label">Каталог</span>
    </a>

    {{-- Корзина (N) --}}
    <a href="{{ route('cart.index') }}"
       @if(request()->routeIs('cart.index')) aria-current="page" @endif
       aria-label="Корзина, товаров: {{ $cartCount }}">
        <svg aria-hidden="true" viewBox="0 0 24 24">
            <circle cx="9" cy="21" r="1"/>
            <circle cx="20" cy="21" r="1"/>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
        @if($cartCount > 0)
        <span class="ah-nav-count" aria-hidden="true">{{ $cartCount }}</span>
        @endif
        <span class="ah-nav-label">Корзина</span>
    </a>

    {{-- WhatsApp / Контакты --}}
    @if($whatsapp)
    <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"
       aria-label="Написать в WhatsApp">
        <svg aria-hidden="true" viewBox="0 0 24 24">
            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
        </svg>
        <span class="ah-nav-label">WhatsApp</span>
    </a>
    @else
    <a href="#store-contacts">
        <svg aria-hidden="true" viewBox="0 0 24 24">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
        </svg>
        <span class="ah-nav-label">Контакты</span>
    </a>
    @endif

</nav>
