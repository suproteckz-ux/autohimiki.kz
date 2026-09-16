@php
    $phone = \App\Services\CacheService::setting('phone', '');
    $whatsapp = \App\Services\CacheService::setting('whatsapp', '');
    $address = \App\Services\CacheService::setting('address', 'Алматы, Казахстан');
    $instagram = \App\Services\CacheService::setting('instagram', '');
    $email = \App\Services\CacheService::setting('email', '');
@endphp
<footer class="ah-footer" id="store-contacts">
    <div class="ah-container">
        <div class="ah-footer-grid">
            <div class="ah-footer-about"><a class="ah-logo" href="{{ route('home') }}"><x-ui.brand-mark /><span>AUTOHIMIKI<span class="ah-orange">.KZ</span></span></a><p>Автохимия и детейлинг в Алматы.<br>Проверенные бренды, широкий ассортимент, быстрая консультация.</p></div>
            <div><h2>Каталог</h2><nav aria-label="Каталог в подвале"><a href="{{ route('catalog') }}">Все товары</a><a href="{{ route('brands') }}">Бренды</a><a href="{{ route('blog') }}">Блог</a></nav></div>
            <div><h2>Покупателям</h2><p>Доставка по Алматы</p><p>Самовывоз</p><p>Консультация бесплатно</p>@if($whatsapp)<a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">Написать в WhatsApp ↗</a>@endif</div>
            <div><h2>Контакты</h2><address>@if($address)<p>{{ $address }}</p>@endif @if($phone)<a href="tel:{{ preg_replace('/\D/', '', $phone) }}">{{ $phone }}</a>@endif @if($email)<a href="mailto:{{ $email }}">{{ $email }}</a>@endif @if($instagram)<a href="{{ $instagram }}" target="_blank" rel="noopener">Instagram ↗</a>@endif</address></div>
        </div>
        <div class="ah-footer-bottom"><span>© {{ date('Y') }} {{ config('app.name') }}. Все права защищены.</span><span>Автохимия в Алматы, Казахстан</span></div>
    </div>
</footer>
<nav class="ah-bottom-nav" aria-label="Быстрая навигация">
    <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif><span aria-hidden="true">⌂</span>Главная</a>
    <a href="{{ route('catalog') }}" @if(request()->routeIs('catalog*')) aria-current="page" @endif><span aria-hidden="true">▦</span>Каталог</a>
    <a href="{{ route('search') }}" @if(request()->routeIs('search')) aria-current="page" @endif><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg>Поиск</a>
    @if($whatsapp)<a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"><span aria-hidden="true">↗</span>WhatsApp</a>@else<a href="#store-contacts"><span aria-hidden="true">☏</span>Контакты</a>@endif
</nav>
