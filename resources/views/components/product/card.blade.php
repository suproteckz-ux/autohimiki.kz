@props(['product'])

@php
    $wa = \App\Services\CacheService::setting('whatsapp', '');
    $waMsg = urlencode('Хочу заказать: ' . $product->name . ' — autohimiki.kz');
@endphp

<div class="group bg-white rounded-2xl border border-gray-100 overflow-hidden
            hover:border-amber-200 hover:shadow-lg transition-all duration-300
            flex flex-col">

    {{-- Изображение --}}
    <a href="{{ route('product.show', $product->slug) }}" class="block relative aspect-square bg-gray-50 overflow-hidden">
        @if(!empty($product->main_image))
            <img src="{{ asset('storage/' . ($product->main_image_webp ?? $product->main_image)) }}"
                 alt="{{ $product->main_image_alt ?? $product->name }}"
                 loading="lazy"
                 width="320" height="320"
                 class="w-full h-full object-contain p-3
                        group-hover:scale-105 transition-transform duration-500">
        @else
            <div class="w-full h-full flex flex-col items-center justify-center gap-2 text-gray-200">
                <svg class="w-14 h-14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                          d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                <span class="text-xs text-gray-300">Фото появится</span>
            </div>
        @endif

        {{-- Бейджи --}}
        <div class="absolute top-2 left-2 flex flex-col gap-1">
            @if(!empty($product->is_new))
                <span class="text-xs font-bold px-2 py-0.5 bg-blue-500 text-white rounded-full">Новинка</span>
            @endif
            @if(!empty($product->is_hit))
                <span class="text-xs font-bold px-2 py-0.5 bg-amber-500 text-white rounded-full">Хит</span>
            @endif
            @if(!empty($product->old_price) && $product->old_price > $product->price)
                @php $disc = round(($product->old_price - $product->price) / $product->old_price * 100) @endphp
                <span class="text-xs font-bold px-2 py-0.5 bg-red-500 text-white rounded-full">−{{ $disc }}%</span>
            @endif
        </div>

        {{-- Наличие --}}
        <div class="absolute bottom-2 right-2">
            @if($product->in_stock ?? true)
                <span class="text-xs font-medium px-2 py-0.5 bg-green-100 text-green-700 rounded-full">
                    В наличии
                </span>
            @else
                <span class="text-xs font-medium px-2 py-0.5 bg-gray-100 text-gray-400 rounded-full">
                    Нет в наличии
                </span>
            @endif
        </div>
    </a>

    {{-- Контент --}}
    <div class="p-3 flex flex-col flex-1">
        {{-- Бренд --}}
        @if(!empty($product->brand))
            <p class="text-xs text-amber-600 font-medium mb-1 truncate">{{ $product->brand->name }}</p>
        @endif

        {{-- Название --}}
        <a href="{{ route('product.show', $product->slug) }}"
           class="text-sm font-semibold text-gray-900 leading-snug mb-2 line-clamp-2
                  hover:text-amber-600 transition-colors flex-1">
            {{ $product->name }}
        </a>

        {{-- Цена --}}
        <div class="flex items-baseline gap-2 mb-3">
            <span class="text-base font-bold text-gray-900">
                {{ number_format($product->price, 0, '.', ' ') }} ₸
            </span>
            @if(!empty($product->old_price) && $product->old_price > $product->price)
            <span class="text-xs text-gray-400 line-through">
                {{ number_format($product->old_price, 0, '.', ' ') }} ₸
            </span>
            @endif
        </div>

        {{-- Кнопка WhatsApp --}}
        @if($wa)
        <a href="https://wa.me/{{ $wa }}?text={{ $waMsg }}"
           target="_blank" rel="noopener"
           class="flex items-center justify-center gap-1.5 w-full py-2 bg-green-500 hover:bg-green-600
                  text-white text-xs font-semibold rounded-xl transition-colors">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/>
            </svg>
            Купить в WhatsApp
        </a>
        @else
        <a href="{{ route('product.show', $product->slug) }}"
           class="flex items-center justify-center w-full py-2 bg-amber-500 hover:bg-amber-600
                  text-white text-xs font-semibold rounded-xl transition-colors">
            Подробнее →
        </a>
        @endif
    </div>
</div>
