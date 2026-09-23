@extends('layouts.app')

@section('title', $product->seoTitle())
@section('description', $product->seoDescription())

@section('canonical')
<link rel="canonical" href="{{ $product->seoCanonical() }}">
@endsection

@section('breadcrumbs')
<x-ui.breadcrumbs :items="$breadcrumbs"/>
@endsection

@section('content')
@php $wa = \App\Services\CacheService::setting('whatsapp', ''); @endphp

<div class="ah-product-page">
<div class="ah-container ah-product-shell">

    {{-- ═══════════════════════════════════════════════════
         Основная секция: фото + информация
    ═══════════════════════════════════════════════════ --}}
    <div class="ah-product-detail">

        {{-- Фото --}}
        @php
            $gallery = [];
            $seenImagePaths = [];
            $publicDisk = \Illuminate\Support\Facades\Storage::disk('public');
            $imageExists = static fn ($path) => is_string($path) && trim($path) !== ''
                && !str_contains($path, '..') && !preg_match('#^(?:/|[a-z]+:)|\\\\#i', $path)
                && $publicDisk->exists($path);
            $addImage = static function ($path, $webp, $alt) use (&$gallery, &$seenImagePaths, $imageExists, $product) {
                if (!$imageExists($path) || isset($seenImagePaths[$path])) {
                    return;
                }
                $seenImagePaths[$path] = true;
                $gallery[] = [
                    'src' => asset('storage/' . $path),
                    'webp' => $imageExists($webp) ? asset('storage/' . $webp) : null,
                    'alt' => trim((string) $alt) !== '' ? $alt : $product->name,
                ];
            };
            $addImage($product->main_image, $product->main_image_webp, $product->main_image_alt);
            foreach ($product->images as $image) {
                $addImage($image->path, $image->path_webp, $image->alt);
            }
        @endphp
        <div class="ah-detail-gallery" data-product-gallery x-data="{ selected: 0, images: {{ \Illuminate\Support\Js::from($gallery) }} }">
            <div class="ah-detail-gallery-stage">
                @if(count($gallery))
                <picture>
                    <source @if($gallery[0]['webp']) srcset="{{ $gallery[0]['webp'] }}" @endif
                            :srcset="images[selected].webp || ''" type="image/webp">
                    <img src="{{ $gallery[0]['src'] }}" :src="images[selected].src"
                         alt="{{ $gallery[0]['alt'] }}" :alt="images[selected].alt"
                         class="ah-detail-main-image"
                         width="600" height="600"
                         fetchpriority="high">
                </picture>
                {{-- Бейджи --}}
                <div class="ah-detail-badges">
                    @if($product->is_new)
                    <span class="ah-badge ah-badge-dark">Новинка</span>
                    @endif
                    @if($product->is_hit)
                    <span class="ah-badge">Хит</span>
                    @endif
                    @if($product->hasDiscount())
                    <span class="ah-badge ah-detail-sale-badge">
                        −{{ $product->discount_percent }}%
                    </span>
                    @endif
                </div>
                @else
                <div class="ah-detail-placeholder">
                    <svg class="w-20 h-20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="0.8"
                              d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <p class="text-sm text-gray-300">Фото появится скоро</p>
                </div>
                @endif
            </div>
            @if(count($gallery) > 1)
            <div class="ah-detail-thumbnails" role="group" aria-label="Фотографии товара">
                @foreach($gallery as $index => $image)
                <button type="button" data-gallery-thumbnail @click="selected = {{ $index }}"
                        aria-label="Показать фото {{ $index + 1 }}" aria-pressed="{{ $index === 0 ? 'true' : 'false' }}"
                        :aria-pressed="selected === {{ $index }}"
                        class="ah-detail-thumbnail"
                        :class="selected === {{ $index }} ? 'is-active' : ''">
                    <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}"
                         class="w-full h-full object-contain" width="80" height="80" loading="lazy">
                </button>
                @endforeach
            </div>
            @endif
        </div>

        {{-- Информация --}}
        <div class="ah-product-summary">

            {{-- Бренд --}}
            @if($product->brand)
            <a href="{{ $product->brand->url ?? route('brand.show', $product->brand->slug) }}"
               class="ah-product-detail-brand">
                {{ $product->brand->name }}
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
            @endif

            {{-- Название --}}
            <h1 class="ah-product-detail-title">
                {{ $product->seoH1() }}
            </h1>

            {{-- Статус + SKU --}}
            <div class="ah-product-meta">
                @if($product->is_active && $product->quantity > 0)
                <span class="ah-product-availability is-in-stock">
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                    В наличии
                </span>
                @else
                <span class="ah-product-availability">
                    <span class="w-2 h-2 bg-gray-300 rounded-full"></span>
                    Нет в наличии
                </span>
                @endif
                @if($product->sku)
                <span class="ah-product-sku">
                    Арт: {{ $product->sku }}
                </span>
                @endif
                @if($product->category)
                <a href="{{ $product->category->url }}"
                   class="ah-product-category-link">
                    {{ $product->category->name }}
                </a>
                @endif
            </div>

            {{-- Цена --}}
            <div class="ah-detail-price-row">
                <span class="ah-detail-price">
                    {{ number_format($product->price, 0, '.', ' ') }} ₸
                </span>
                @if($product->hasDiscount())
                <div class="ah-detail-discount">
                    <span>
                        {{ number_format($product->old_price, 0, '.', ' ') }} ₸
                    </span>
                    <b>
                        Скидка {{ $product->discount_percent }}%
                    </b>
                </div>
                @endif
            </div>

            {{-- Кнопки покупки --}}
            <div class="ah-detail-actions">
                @if($wa)
                <a href="https://wa.me/{{ $wa }}?text={{ urlencode('Хочу заказать: ' . $product->name . ' - ' . url()->current()) }}"
                   target="_blank" rel="noopener"
                   class="ah-button ah-detail-wa">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/>
                    </svg>
                    Купить через WhatsApp
                </a>
                @endif
                <form method="POST" action="{{ route('cart.store') }}" class="ah-add-to-cart">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <button type="submit" class="ah-button ah-button-orange" @disabled(!$product->is_active || $product->quantity <= 0)>{{ $product->is_active && $product->quantity > 0 ? 'Добавить в корзину' : 'Нет в наличии' }}</button>
                </form>
            </div>
            @include('components.ui.cart-feedback')

            <x-kaspi.credit-button :product="$product" />

            {{-- Краткое описание --}}
            @if($product->short_description)
            <div class="ah-product-short-description">
                {{ $product->short_description }}
            </div>
            @endif

            {{-- Доставка --}}
            <div class="ah-product-benefits">
                @foreach([
                    ['M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'Оригинальная продукция', 'text-green-600'],
                    ['M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z', 'Доставка по Алматы', 'text-amber-600'],
                    ['M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'Консультация бесплатно', 'text-blue-600'],
                ] as [$icon, $text, $color])
                <div>
                    <svg class="w-4 h-4 {{ $color }} flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
                    </svg>
                    {{ $text }}
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════
         Описание + характеристики
    ═══════════════════════════════════════════════════ --}}
    @php
        $displayAttributes = array_filter((array) $product->attributes, static fn ($value, $name) =>
            trim((string) $name) !== '' && is_scalar($value) && trim((string) $value) !== '', ARRAY_FILTER_USE_BOTH);
    @endphp
    @if($product->description || $product->usage_instructions || $displayAttributes)
    <div class="ah-product-content-card">
        @if($product->description)
        <section>
            <h2>Описание</h2>
            <div class="prose prose-sm prose-gray max-w-none">
                {!! $product->description !!}
            </div>
        </section>
        @endif
        @if($displayAttributes)
        <section data-product-characteristics>
            <h2>Характеристики</h2>
            <table>
                <tbody class="divide-y divide-gray-50">
                    @foreach($displayAttributes as $name => $value)
                    <tr>
                        <th scope="row" class="w-1/2 sm:w-48 py-2.5 pr-4 align-top font-normal text-gray-500 break-words">{{ $name }}</th>
                        <td class="py-2.5 text-gray-900 font-medium break-words">{{ $value }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif
        @if($product->usage_instructions)
        <section>
            <h2>Применение</h2>
            <div class="prose prose-sm prose-gray max-w-none">
                {!! $product->usage_instructions !!}
            </div>
        </section>
        @endif
    </div>
    @endif
    {{-- ═══════════════════════════════════════════════════
         Похожие товары
    ═══════════════════════════════════════════════════ --}}
    @if($related->count())
    <section class="ah-product-related">
        <x-ui.section-heading title="Похожие товары" />
        <div class="ah-product-grid">
            @foreach($related as $rel)
                <x-product.card :product="$rel"/>
            @endforeach
        </div>
    </section>
    @endif
</div>
</div>
@endsection

@section('schema')
@php
    $productSchema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $product->name,
        'description' => $product->seoDescription(),
        'offers'      => [
            '@type'         => 'Offer',
            'priceCurrency' => 'KZT',
            'price'         => (string) $product->price,
            'availability'  => $product->in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url'           => url()->current(),
        ],
    ];
    if ($product->main_image) $productSchema['image'] = asset('storage/' . $product->main_image);
    if ($product->sku) $productSchema['sku'] = $product->sku;
    if ($product->brand) $productSchema['brand'] = ['@type' => 'Brand', 'name' => $product->brand->name];
@endphp
<script type="application/ld+json">
{!! json_encode($productSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endsection
