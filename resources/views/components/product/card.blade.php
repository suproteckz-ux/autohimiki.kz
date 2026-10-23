@props(['product', 'layout' => 'grid'])
@php
    $wa     = \App\Services\CacheService::setting('whatsapp', '');
    $waMsg  = urlencode('Хочу заказать: ' . $product->name . ' — autohimiki.kz');
    $inStock = ($product->is_active ?? true) && (($product->quantity ?? 0) > 0);

    // Volume attribute — supports {key:val} and [{name,value}] formats
    $attrs  = (array)($product->attributes ?? []);
    $volume = null;
    foreach ($attrs as $k => $v) {
        if (is_string($k) && in_array(mb_strtolower(trim($k)), ['объём','объем'])) {
            $volume = is_scalar($v) ? trim((string)$v) : null;
            break;
        }
        if (is_array($v) && isset($v['name']) && in_array(mb_strtolower(trim((string)$v['name'])), ['объём','объем'])) {
            $volume = isset($v['value']) && is_scalar($v['value']) ? trim((string)$v['value']) : null;
            break;
        }
    }
@endphp
<article class="ah-product-card ah-pc ah-pc--{{ $layout }}">

    {{-- Photo link --}}
    <a href="{{ route('product.show', $product->slug) }}"
       class="ah-pc__img" tabindex="-1" aria-hidden="true">
        @if(!empty($product->main_image))
            <img src="{{ asset('storage/' . ($product->main_image_webp ?? $product->main_image)) }}"
                 alt="{{ $product->main_image_alt ?? $product->name }}"
                 loading="lazy" width="300" height="300">
        @else
            <div class="ah-pc__placeholder" aria-hidden="true">
                @if(!empty($product->brand))<b>{{ $product->brand->name }}</b>@endif
                <span>Фото скоро</span>
            </div>
        @endif
        <div class="ah-product-badges" aria-hidden="true">
            @if(!empty($product->is_hit))<span class="ah-badge">Хит</span>@endif
            @if(!empty($product->is_new))<span class="ah-badge ah-badge-dark">Новинка</span>@endif
            @if(!empty($product->old_price) && $product->old_price > $product->price)
                <span class="ah-badge">−{{ round(($product->old_price - $product->price) / $product->old_price * 100) }}%</span>
            @endif
        </div>
    </a>

    {{-- Body --}}
    <div class="ah-pc__body">
        @if(!empty($product->brand))<p class="ah-pc__brand">{{ $product->brand->name }}</p>@endif
        <a class="ah-pc__name" href="{{ route('product.show', $product->slug) }}">{{ $product->name }}</a>
        @if($volume)<p class="ah-pc__attr">Объём: {{ $volume }}</p>@endif
        <p class="ah-pc__stock {{ $inStock ? 'is-in' : 'is-out' }}">
            <span aria-hidden="true">●</span> {{ $inStock ? 'В наличии' : 'Нет в наличии' }}
        </p>
        <div class="ah-pc__footer">
            <div class="ah-pc__price">
                <strong>{{ number_format($product->price, 0, '.', ' ') }} ₸</strong>
                @if(!empty($product->old_price) && $product->old_price > $product->price)
                    <del>{{ number_format($product->old_price, 0, '.', ' ') }} ₸</del>
                @endif
            </div>
            <div class="ah-pc__actions">
                @if($inStock)
                    <form method="POST" action="{{ route('cart.store') }}" class="ah-pc__cart">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        <button type="submit" class="ah-pc__btn"
                                aria-label="Добавить {{ $product->name }} в корзину">
                            <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2.5"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="9" cy="21" r="1"/>
                                <circle cx="20" cy="21" r="1"/>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                            </svg>
                            В корзину
                        </button>
                    </form>
                    @if($wa)
                    <a href="https://wa.me/{{ $wa }}?text={{ $waMsg }}"
                       target="_blank" rel="noopener"
                       class="ah-pc__wa"
                       aria-label="Заказать {{ $product->name }} через WhatsApp">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                        </svg>
                    </a>
                    @endif
                @else
                    @if($wa)
                    <a href="https://wa.me/{{ $wa }}?text={{ $waMsg }}"
                       target="_blank" rel="noopener"
                       class="ah-pc__btn ah-pc__btn--outline">
                        Уточнить наличие
                    </a>
                    @else
                    <a href="{{ route('product.show', $product->slug) }}"
                       class="ah-pc__btn ah-pc__btn--outline">
                        Подробнее →
                    </a>
                    @endif
                @endif
            </div>
        </div>
    </div>
</article>
