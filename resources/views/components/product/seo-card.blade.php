@props(['product'])
@php
    $url = route('product.show', $product->slug);

    // Normalize attributes: supports both [{name,value}] and {"key":"value"} formats
    $attrs = [];
    $raw = is_array($product->attributes) ? $product->attributes : [];
    foreach (array_slice($raw, 0, 3, true) as $key => $val) {
        if (is_array($val)) {
            $k = $val['name'] ?? $val['k'] ?? '';
            $v = $val['value'] ?? $val['v'] ?? '';
        } else {
            $k = is_string($key) ? $key : '';
            $v = is_string($val) ? $val : '';
        }
        if ($k !== '' && $v !== '') {
            $attrs[] = ['k' => $k, 'v' => $v];
        }
    }
@endphp
<article class="ah-seo-card">
    <a href="{{ $url }}" aria-label="{{ $product->name }}" class="ah-seo-card-image">
        @if(!empty($product->main_image))
            <img src="{{ asset('storage/' . ($product->main_image_webp ?? $product->main_image)) }}"
                 alt="{{ $product->main_image_alt ?? $product->name }}"
                 width="300" height="300"
                 loading="lazy" decoding="async">
        @else
            <div class="ah-placeholder" aria-hidden="true">Фото</div>
        @endif
    </a>
    <div class="ah-seo-card-body">
        <div class="ah-seo-card-top">
            @if(!empty($product->brand))
                <span class="ah-seo-card-brand">{{ $product->brand->name }}</span>
            @endif
            <span @class(['ah-seo-card-stock', 'is-in-stock' => $product->in_stock])>
                <span aria-hidden="true">●</span>
                {{ $product->in_stock ? 'В наличии' : 'Нет в наличии' }}
            </span>
        </div>

        <h3 class="ah-seo-card-name">
            <a href="{{ $url }}">{{ $product->name }}</a>
        </h3>

        @if($attrs)
        <dl class="ah-seo-card-specs">
            @foreach($attrs as $attr)
            <div>
                <dt>{{ $attr['k'] }}</dt>
                <dd>{{ $attr['v'] }}</dd>
            </div>
            @endforeach
        </dl>
        @endif

        @if(!empty($product->short_description))
        <p class="ah-seo-card-desc">{{ Str::limit(strip_tags($product->short_description), 120) }}</p>
        @endif

        <div class="ah-seo-card-footer">
            <span class="ah-seo-card-price">{{ number_format($product->price, 0, '.', ' ') }} ₸</span>
            <a href="{{ $url }}" class="ah-button ah-button-orange ah-seo-card-cta">Посмотреть товар</a>
        </div>
    </div>
</article>
