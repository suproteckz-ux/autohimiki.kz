@extends('layouts.app')

@section('title', 'Автохимия в Алматы — купить автохимию | ' . config('app.name'))
@section('description', 'Интернет-магазин автохимии в Алматы. Автошампуни, полироли, антидождь, детейлинг. Бесплатная консультация, доставка по Казахстану.')

@section('canonical')
<link rel="canonical" href="{{ url('/') }}">
@endsection

@section('content')

{{-- ═══════════════════════════════════════════════════════════
     HERO
═══════════════════════════════════════════════════════════ --}}
<section class="relative bg-gray-900 overflow-hidden">
    {{-- Декоративный фон --}}
    <div class="absolute inset-0 opacity-10">
        <div class="absolute top-0 right-0 w-96 h-96 bg-amber-500 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>
        <div class="absolute bottom-0 left-0 w-80 h-80 bg-blue-600 rounded-full blur-3xl translate-y-1/2 -translate-x-1/2"></div>
    </div>

    <div class="container mx-auto px-4 py-16 md:py-24 relative">
        <div class="max-w-2xl">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-amber-500/20 border border-amber-500/30
                        rounded-full text-amber-400 text-xs font-semibold mb-6 uppercase tracking-wide">
                <span class="w-1.5 h-1.5 bg-amber-400 rounded-full animate-pulse"></span>
                Алматы · Автохимия · Детейлинг
            </div>

            <h1 class="text-4xl md:text-5xl lg:text-6xl font-black text-white leading-tight mb-6">
                Автохимия<br>
                <span class="text-amber-400">для вашего авто</span>
            </h1>

            <p class="text-lg text-gray-400 mb-8 leading-relaxed max-w-xl">
                Автошампуни, полироли, антидождь, детейлинг.<br>
                Проверенные бренды. Консультация бесплатно.
            </p>

            <div class="flex flex-col sm:flex-row gap-3">
                <a href="{{ route('catalog') }}"
                   class="inline-flex items-center justify-center gap-2 px-6 py-3.5
                          bg-amber-500 hover:bg-amber-400 text-white font-bold rounded-xl
                          transition-all hover:shadow-lg hover:shadow-amber-500/30 text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    Перейти в каталог
                </a>
                <button @click="$dispatch('open-lead-modal')"
                        class="inline-flex items-center justify-center gap-2 px-6 py-3.5
                               border border-gray-600 hover:border-gray-400 text-gray-300
                               hover:text-white font-semibold rounded-xl transition-all text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                    Получить консультацию
                </button>
            </div>
        </div>
    </div>

    {{-- Статистика --}}
    <div class="border-t border-gray-800">
        <div class="container mx-auto px-4 py-5">
            <div class="grid grid-cols-3 gap-4 md:grid-cols-3 max-w-lg">
                @foreach([
                    ['800+', 'товаров'],
                    ['30+',  'брендов'],
                    ['5 лет','на рынке'],
                ] as [$num, $label])
                <div class="text-center">
                    <div class="text-xl font-black text-amber-400">{{ $num }}</div>
                    <div class="text-xs text-gray-500">{{ $label }}</div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════
     КАТЕГОРИИ
═══════════════════════════════════════════════════════════ --}}
@if(isset($categories) && $categories->count())
<section class="bg-white py-12">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between mb-7">
            <h2 class="text-2xl font-bold text-gray-900">Категории товаров</h2>
            <a href="{{ route('catalog') }}"
               class="text-sm text-amber-600 hover:text-amber-500 font-medium transition-colors">
                Все категории →
            </a>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            @foreach($categories as $category)
            <a href="{{ $category->url ?? url('/catalog/' . $category->slug) }}"
               class="group flex flex-col items-center gap-3 p-4 bg-gray-50 border border-gray-100
                      rounded-2xl hover:border-amber-300 hover:bg-amber-50 hover:shadow-md
                      transition-all duration-200 text-center">

                {{-- Иконка / заглушка --}}
                @if($category->image)
                <img src="{{ asset('storage/' . ($category->image_webp ?? $category->image)) }}"
                     alt="{{ $category->name }}" loading="lazy"
                     class="w-12 h-12 object-contain">
                @else
                <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center
                            group-hover:bg-amber-200 transition-colors">
                    <svg class="w-6 h-6 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
                @endif

                <span class="text-xs font-semibold text-gray-700 group-hover:text-amber-700
                             transition-colors leading-tight">
                    {{ $category->name }}
                </span>

                @if(isset($category->products_count) && $category->products_count > 0)
                <span class="text-xs text-gray-400">{{ $category->products_count }}</span>
                @endif
            </a>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ═══════════════════════════════════════════════════════════
     ХИТЫ ПРОДАЖ
═══════════════════════════════════════════════════════════ --}}
@if(isset($hits) && $hits->count())
<section class="bg-gray-50 py-12">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between mb-7">
            <div class="flex items-center gap-3">
                <div class="w-1 h-7 bg-amber-500 rounded-full"></div>
                <h2 class="text-2xl font-bold text-gray-900">🔥 Хиты продаж</h2>
            </div>
            <a href="{{ route('catalog') }}" class="text-sm text-amber-600 hover:text-amber-500 font-medium">
                Смотреть все →
            </a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($hits as $product)
                <x-product.card :product="$product"/>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ═══════════════════════════════════════════════════════════
     ПРЕИМУЩЕСТВА
═══════════════════════════════════════════════════════════ --}}
<section class="bg-white py-12">
    <div class="container mx-auto px-4">
        <h2 class="text-2xl font-bold text-gray-900 text-center mb-8">Почему выбирают нас</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach([
                ['M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', 'Оригинальная продукция', 'Только сертифицированные товары от официальных поставщиков'],
                ['M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z M15 11a3 3 0 11-6 0 3 3 0 016 0z', 'Доставка по Алматы', 'Быстрая доставка по городу или самовывоз'],
                ['M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z', 'Бесплатная консультация', 'Подберём подходящий продукт под ваш автомобиль'],
                ['M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'Работаем 7 дней', 'Ежедневно с 9:00 до 20:00, без выходных'],
            ] as [$icon, $title, $desc])
            <div class="flex flex-col items-center text-center p-5 rounded-2xl bg-gray-50">
                <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center mb-4">
                    <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $icon }}"/>
                    </svg>
                </div>
                <h3 class="font-bold text-gray-900 mb-1.5 text-sm">{{ $title }}</h3>
                <p class="text-xs text-gray-500 leading-relaxed">{{ $desc }}</p>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════
     НОВИНКИ
═══════════════════════════════════════════════════════════ --}}
@if(isset($newProducts) && $newProducts->count())
<section class="bg-gray-50 py-12">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between mb-7">
            <div class="flex items-center gap-3">
                <div class="w-1 h-7 bg-blue-500 rounded-full"></div>
                <h2 class="text-2xl font-bold text-gray-900">✨ Новинки</h2>
            </div>
            <a href="{{ route('catalog') }}" class="text-sm text-amber-600 hover:text-amber-500 font-medium">
                Все новинки →
            </a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($newProducts as $product)
                <x-product.card :product="$product"/>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ═══════════════════════════════════════════════════════════
     БРЕНДЫ
═══════════════════════════════════════════════════════════ --}}
@if(isset($brands) && $brands->count())
<section class="bg-white py-12">
    <div class="container mx-auto px-4">
        <h2 class="text-2xl font-bold text-gray-900 text-center mb-8">Популярные бренды</h2>
        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
            @foreach($brands as $brand)
            <a href="{{ $brand->url ?? url('/brand/' . $brand->slug) }}"
               class="flex items-center justify-center p-4 bg-gray-50 border border-gray-100
                      rounded-xl hover:border-amber-300 hover:shadow-sm hover:bg-amber-50
                      transition-all aspect-square group">
                @if($brand->logo)
                <img src="{{ asset('storage/' . ($brand->logo_webp ?? $brand->logo)) }}"
                     alt="{{ $brand->name }}" loading="lazy"
                     class="max-h-10 object-contain grayscale group-hover:grayscale-0 transition-all">
                @else
                <span class="text-xs font-bold text-gray-600 group-hover:text-amber-700 text-center">
                    {{ $brand->name }}
                </span>
                @endif
            </a>
            @endforeach
        </div>
        <div class="text-center mt-6">
            <a href="{{ route('brands') }}"
               class="inline-flex items-center gap-2 px-6 py-3 border border-gray-200
                      hover:border-amber-400 rounded-xl text-sm font-medium text-gray-700
                      hover:text-amber-600 transition-all">
                Все бренды →
            </a>
        </div>
    </div>
</section>
@endif

{{-- ═══════════════════════════════════════════════════════════
     CTA — WhatsApp
═══════════════════════════════════════════════════════════ --}}
@php $wa = \App\Services\CacheService::setting('whatsapp', ''); @endphp
@if($wa)
<section class="bg-gray-900 py-12">
    <div class="container mx-auto px-4 text-center">
        <div class="max-w-xl mx-auto">
            <div class="w-16 h-16 bg-green-500 rounded-2xl flex items-center justify-center mx-auto mb-5">
                <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/>
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-white mb-3">Нужна консультация?</h2>
            <p class="text-gray-400 mb-6">Напишите нам в WhatsApp — подберём подходящий продукт для вашего автомобиля.</p>
            <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 px-8 py-4 bg-green-500 hover:bg-green-400
                      text-white font-bold rounded-xl transition-all hover:shadow-lg
                      hover:shadow-green-500/30">
                Написать в WhatsApp
            </a>
        </div>
    </div>
</section>
@endif

@endsection
