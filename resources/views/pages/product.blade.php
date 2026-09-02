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

<div class="container mx-auto px-4 py-8">

    {{-- ═══════════════════════════════════════════════════
         Основная секция: фото + информация
    ═══════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 mb-12">

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
        <div data-product-gallery x-data="{ selected: 0, images: {{ \Illuminate\Support\Js::from($gallery) }} }">
            <div class="aspect-square bg-gray-50 rounded-2xl overflow-hidden border border-gray-100 relative">
                @if(count($gallery))
                <picture>
                    <source @if($gallery[0]['webp']) srcset="{{ $gallery[0]['webp'] }}" @endif
                            :srcset="images[selected].webp || ''" type="image/webp">
                    <img src="{{ $gallery[0]['src'] }}" :src="images[selected].src"
                         alt="{{ $gallery[0]['alt'] }}" :alt="images[selected].alt"
                         class="w-full h-full object-contain p-6"
                         width="600" height="600"
                         fetchpriority="high">
                </picture>
                {{-- Бейджи --}}
                <div class="absolute top-4 left-4 flex flex-col gap-1.5">
                    @if($product->is_new)
                    <span class="px-3 py-1 bg-blue-500 text-white text-xs font-bold rounded-full">Новинка</span>
                    @endif
                    @if($product->is_hit)
                    <span class="px-3 py-1 bg-amber-500 text-white text-xs font-bold rounded-full">Хит</span>
                    @endif
                    @if($product->hasDiscount())
                    <span class="px-3 py-1 bg-red-500 text-white text-xs font-bold rounded-full">
                        −{{ $product->discount_percent }}%
                    </span>
                    @endif
                </div>
                @else
                <div class="w-full h-full flex flex-col items-center justify-center gap-4 text-gray-200">
                    <svg class="w-20 h-20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="0.8"
                              d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <p class="text-sm text-gray-300">Фото появится скоро</p>
                </div>
                @endif
            </div>
            @if(count($gallery) > 1)
            <div class="flex gap-2 mt-3 overflow-x-auto pb-1" role="group" aria-label="Фотографии товара">
                @foreach($gallery as $index => $image)
                <button type="button" data-gallery-thumbnail @click="selected = {{ $index }}"
                        aria-label="Показать фото {{ $index + 1 }}" aria-pressed="{{ $index === 0 ? 'true' : 'false' }}"
                        :aria-pressed="selected === {{ $index }}"
                        class="shrink-0 w-20 h-20 rounded-lg border-2 bg-white p-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                        :class="selected === {{ $index }} ? 'border-blue-500' : 'border-gray-200'">
                    <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}"
                         class="w-full h-full object-contain" width="80" height="80" loading="lazy">
                </button>
                @endforeach
            </div>
            @endif
        </div>

        {{-- Информация --}}
        <div class="flex flex-col">

            {{-- Бренд --}}
            @if($product->brand)
            <a href="{{ $product->brand->url ?? route('brand.show', $product->brand->slug) }}"
               class="inline-flex items-center gap-1 text-sm text-amber-600 font-semibold
                      hover:text-amber-500 mb-3 w-fit">
                {{ $product->brand->name }}
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
            @endif

            {{-- Название --}}
            <h1 class="text-2xl md:text-3xl font-bold text-gray-900 leading-snug mb-4">
                {{ $product->seoH1() }}
            </h1>

            {{-- Статус + SKU --}}
            <div class="flex flex-wrap items-center gap-3 mb-5">
                @if($product->in_stock)
                <span class="flex items-center gap-1.5 text-sm font-semibold text-green-600">
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                    В наличии
                </span>
                @else
                <span class="flex items-center gap-1.5 text-sm font-medium text-gray-400">
                    <span class="w-2 h-2 bg-gray-300 rounded-full"></span>
                    Нет в наличии
                </span>
                @endif
                @if($product->sku)
                <span class="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded">
                    Арт: {{ $product->sku }}
                </span>
                @endif
                @if($product->category)
                <a href="{{ $product->category->url }}"
                   class="text-xs text-gray-500 hover:text-amber-600 transition-colors">
                    {{ $product->category->name }}
                </a>
                @endif
            </div>

            {{-- Цена --}}
            <div class="flex items-baseline gap-4 mb-7 pb-7 border-b border-gray-100">
                <span class="text-4xl font-black text-gray-900">
                    {{ number_format($product->price, 0, '.', ' ') }} ₸
                </span>
                @if($product->hasDiscount())
                <div class="flex flex-col">
                    <span class="text-lg text-gray-400 line-through">
                        {{ number_format($product->old_price, 0, '.', ' ') }} ₸
                    </span>
                    <span class="text-xs text-red-500 font-semibold">
                        Скидка {{ $product->discount_percent }}%
                    </span>
                </div>
                @endif
            </div>

            {{-- Кнопки покупки --}}
            <div class="flex flex-col sm:flex-row gap-3 mb-6">
                @if($wa)
                <a href="https://wa.me/{{ $wa }}?text={{ urlencode('Хочу заказать: ' . $product->name . ' - ' . url()->current()) }}"
                   target="_blank" rel="noopener"
                   class="flex-1 flex items-center justify-center gap-2.5 py-4 bg-green-500
                          hover:bg-green-600 text-white font-bold rounded-xl transition-all
                          hover:shadow-lg hover:shadow-green-500/20">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/>
                    </svg>
                    Купить через WhatsApp
                </a>
                @endif
                <button @click="$dispatch('open-lead-modal', {product: '{{ addslashes($product->name) }}', source: 'product'})"
                        class="flex-1 sm:flex-none flex items-center justify-center gap-2 px-6 py-4
                               border-2 border-amber-400 text-amber-600 font-bold rounded-xl
                               hover:bg-amber-50 transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                    Заказать звонок
                </button>
            </div>

            <x-kaspi.credit-button :product="$product" />

            {{-- Краткое описание --}}
            @if($product->short_description)
            <div class="text-sm text-gray-600 leading-relaxed bg-gray-50 rounded-xl p-4">
                {{ $product->short_description }}
            </div>
            @endif

            {{-- Доставка --}}
            <div class="mt-5 flex flex-col gap-2">
                @foreach([
                    ['M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'Оригинальная продукция', 'text-green-600'],
                    ['M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z', 'Доставка по Алматы', 'text-amber-600'],
                    ['M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'Консультация бесплатно', 'text-blue-600'],
                ] as [$icon, $text, $color])
                <div class="flex items-center gap-2 text-xs text-gray-500">
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
    <div class="mb-12 bg-white rounded-2xl border border-gray-100 overflow-hidden divide-y divide-gray-100">
        @if($product->description)
        <section class="p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4">Описание</h2>
            <div class="prose prose-sm prose-gray max-w-none">
                {!! $product->description !!}
            </div>
        </section>
        @endif
        @if($displayAttributes)
        <section class="p-6" data-product-characteristics>
            <h2 class="text-xl font-bold text-gray-900 mb-4">Характеристики</h2>
            <table class="w-full text-sm text-left">
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
        <section class="p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4">Применение</h2>
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
    <div>
        <h2 class="text-xl font-bold text-gray-900 mb-6">Похожие товары</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($related as $rel)
                <x-product.card :product="$rel"/>
            @endforeach
        </div>
    </div>
    @endif
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
