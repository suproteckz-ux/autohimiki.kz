@props(['product'])
@php
    $wa = \App\Services\CacheService::setting('whatsapp', '');
    $waMsg = urlencode('Хочу заказать: ' . $product->name . ' — autohimiki.kz');
@endphp
<article class="ah-product-card">
    <a href="{{ route('product.show', $product->slug) }}" class="ah-product-image" aria-label="{{ $product->name }}">
        @if(!empty($product->main_image))
            <img src="{{ asset('storage/' . ($product->main_image_webp ?? $product->main_image)) }}" alt="{{ $product->main_image_alt ?? $product->name }}" loading="lazy" width="320" height="320">
        @else<div class="ah-placeholder" aria-hidden="true">Фото появится</div>@endif
        <div class="ah-product-badges">
            @if(!empty($product->is_hit))<span class="ah-badge">Хит</span>@endif
            @if(!empty($product->is_new))<span class="ah-badge ah-badge-dark">Новинка</span>@endif
            @if(!empty($product->old_price) && $product->old_price > $product->price)<span class="ah-badge">−{{ round(($product->old_price - $product->price) / $product->old_price * 100) }}%</span>@endif
        </div>
    </a>
    <div class="ah-product-copy">
        @if(!empty($product->brand))<p class="ah-product-brand">{{ $product->brand->name }}</p>@endif
        <a class="ah-product-name" href="{{ route('product.show', $product->slug) }}">{{ $product->name }}</a>
        <p class="ah-stock {{ ($product->in_stock ?? true) ? '' : 'ah-stock-empty' }}"><span aria-hidden="true">●</span> {{ ($product->in_stock ?? true) ? 'В наличии' : 'Нет в наличии' }}</p>
        <div class="ah-product-price"><strong>{{ number_format($product->price, 0, '.', ' ') }} ₸</strong>@if(!empty($product->old_price) && $product->old_price > $product->price)<del>{{ number_format($product->old_price, 0, '.', ' ') }} ₸</del>@endif</div>
        @if($wa)<a href="https://wa.me/{{ $wa }}?text={{ $waMsg }}" target="_blank" rel="noopener" class="ah-product-cta"><span aria-hidden="true">↗</span> Купить в WhatsApp</a>
        @else<a href="{{ route('product.show', $product->slug) }}" class="ah-product-cta">Подробнее <span aria-hidden="true">→</span></a>@endif
    </div>
</article>
