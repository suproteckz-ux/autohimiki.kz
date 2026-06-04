{{--
    resources/views/components/product/image.blade.php

    Оптимизированное изображение товара:
    - Явные width/height → браузер резервирует место → CLS = 0
    - loading="lazy" для изображений ниже fold
    - loading="eager" + fetchpriority="high" для LCP-изображения
    - <picture> с WebP + JPEG fallback
    - aspect-ratio через Tailwind: aspect-square

    Параметры:
    @param Product $product
    @param bool    $isLCP     — главное изображение страницы (eager + preload)
    @param int     $width     — ширина в px (для srcset)
    @param string  $class     — доп. классы
--}}

@props([
    'product',
    'isLcp'  => false,
    'width'  => 320,
    'height' => 320,
    'class'  => '',
])

@php
    $src    = $product->main_image
        ? asset('storage/' . $product->main_image)
        : asset('img/no-photo.svg');

    $srcWebp = $product->main_image_webp
        ? asset('storage/' . $product->main_image_webp)
        : null;

    $alt     = $product->main_image_alt ?: $product->name;
    $loading = $isLcp ? 'eager' : 'lazy';
    $fetch   = $isLcp ? 'high'  : 'auto';
@endphp

{{-- <picture> позволяет браузеру выбрать WebP если поддерживается --}}
<picture>
    @if($srcWebp)
    <source srcset="{{ $srcWebp }}" type="image/webp">
    @endif
    <img
        src="{{ $src }}"
        alt="{{ $alt }}"
        width="{{ $width }}"
        height="{{ $height }}"
        loading="{{ $loading }}"
        fetchpriority="{{ $fetch }}"
        decoding="{{ $isLcp ? 'sync' : 'async' }}"
        class="w-full h-full object-contain {{ $class }}"
    >
</picture>
