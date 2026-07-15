@extends('layouts.app')

@section('title', 'Автохимия в Алматы — купить автохимию | Autohimiki.kz')
@section('description', 'Интернет-магазин автохимии в Алматы. Автошампуни, полироли, антидождь, детейлинг. Проверенные бренды. Консультация бесплатно. Доставка по Казахстану.')

{{-- Canonical: главная всегда = корень без trailing slash --}}
@section('canonical')
<link rel="canonical" href="{{ url('/') }}">
@endsection

@php
    $ogImage = asset('img/og-default.jpg');
    $ogType  = 'website';
@endphp

@section('content')

{{-- ── HERO ─────────────────────────────────────────────────── --}}
<section class="bg-gradient-to-br from-gray-900 to-gray-800 text-white">
    <div class="container mx-auto px-4 py-16 md:py-24">
        <div class="max-w-2xl">
            <h1 class="text-3xl md:text-5xl font-bold leading-tight mb-4">
                Автохимия<br>
                <span class="text-primary-400">в Алматы</span>
            </h1>
            <p class="text-lg text-gray-300 mb-8 leading-relaxed">
                Проверенные бренды для ухода за автомобилем.
                Автошампуни, полироли, антидождь, детейлинг.
                Консультация по подбору — бесплатно.
            </p>
            <div class="flex flex-col sm:flex-row gap-3">
                <a href="{{ route('catalog') }}"
                   class="btn-primary text-center justify-center">
                    Перейти в каталог
                </a>
                <button @click="$dispatch('open-lead-modal')"
                        class="inline-flex items-center justify-center gap-2
                               border border-white/30 text-white hover:bg-white/10
                               font-semibold px-6 py-3 rounded-xl transition-colors">
                    Получить консультацию
                </button>
            </div>

            {{-- УТП --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-10">
                @foreach([
                    ['icon' => '🏆', 'text' => 'Оригинальные бренды'],
                    ['icon' => '🚚', 'text' => 'Доставка по Казахстану'],
                    ['icon' => '💬', 'text' => 'Консультация бесплатно'],
                    ['icon' => '✅', 'text' => 'Самовывоз из Алматы'],
                ] as $utp)
                <div class="flex items-center gap-2 text-sm text-gray-300">
                    <span class="text-xl">{{ $utp['icon'] }}</span>
                    <span>{{ $utp['text'] }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ── КАТЕГОРИИ ────────────────────────────────────────────── --}}
<section class="container mx-auto px-4 py-12">
    <h2 class="text-2xl font-bold text-gray-900 mb-6">Категории товаров</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
        @foreach($categories as $category)
        <a href="{{ $category->url }}"
           class="flex flex-col items-center gap-2 p-4 bg-white border border-gray-100
                  rounded-2xl hover:border-primary-300 hover:shadow-md
                  transition-all duration-200 text-center group">
            @if($category->image)
            <img src="{{ asset('storage/' . ($category->image_webp ?? $category->image)) }}"
                 alt="{{ $category->name }}"
                 loading="lazy"
                 width="64" height="64"
                 class="w-14 h-14 object-contain">
            @else
            <div class="w-14 h-14 bg-primary-50 rounded-xl flex items-center justify-center text-2xl">
                🧴
            </div>
            @endif
            <span class="text-sm font-medium text-gray-700 group-hover:text-primary-600
                         transition-colors leading-tight">
                {{ $category->name }}
            </span>
            @if($category->products_count)
            <span class="text-xs text-gray-400">{{ $category->products_count }} товаров</span>
            @endif
        </a>
        @endforeach
    </div>
</section>

{{-- ── ХИТЫ ПРОДАЖ ─────────────────────────────────────────── --}}
@if($hits->count())
<section class="bg-gray-50 py-12">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold text-gray-900">🔥 Хиты продаж</h2>
            <a href="{{ route('catalog') }}"
               class="text-sm text-primary-600 hover:underline font-medium">
                Все товары →
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

{{-- ── НОВИНКИ ──────────────────────────────────────────────── --}}
@if($newProducts->count())
<section class="container mx-auto px-4 py-12">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-900">✨ Новинки</h2>
        <a href="{{ route('catalog') }}"
           class="text-sm text-primary-600 hover:underline font-medium">
            Все товары →
        </a>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        @foreach($newProducts as $product)
            <x-product.card :product="$product"/>
        @endforeach
    </div>
</section>
@endif

{{-- ── БРЕНДЫ ───────────────────────────────────────────────── --}}
@if($brands->count())
<section class="bg-gray-50 py-12">
    <div class="container mx-auto px-4">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Популярные бренды</h2>
        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-4">
            @foreach($brands as $brand)
            <a href="{{ $brand->url }}"
               class="flex items-center justify-center p-4 bg-white border border-gray-100
                      rounded-xl hover:border-primary-300 hover:shadow-sm
                      transition-all duration-200 aspect-square">
                @if($brand->logo)
                <img src="{{ asset('storage/' . ($brand->logo_webp ?? $brand->logo)) }}"
                     alt="{{ $brand->name }}"
                     loading="lazy"
                     width="80" height="80"
                     class="max-h-12 max-w-full object-contain
                            grayscale hover:grayscale-0 transition-all duration-200">
                @else
                <span class="text-sm font-semibold text-gray-600 text-center leading-tight">
                    {{ $brand->name }}
                </span>
                @endif
            </a>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ── ПРЕИМУЩЕСТВА ─────────────────────────────────────────── --}}
<section class="container mx-auto px-4 py-12">
    <h2 class="text-2xl font-bold text-gray-900 mb-8 text-center">Почему выбирают нас</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        @foreach([
            ['icon' => '🏅', 'title' => 'Только оригиналы',
             'text' => 'Работаем с проверенными поставщиками. Все товары сертифицированы.'],
            ['icon' => '📞', 'title' => 'Консультация',
             'text' => 'Поможем подобрать автохимию под ваш автомобиль и задачу.'],
            ['icon' => '🚀', 'title' => 'Быстрая доставка',
             'text' => 'Доставка по Алматы и всему Казахстану. Самовывоз из магазина.'],
            ['icon' => '💰', 'title' => 'Честные цены',
             'text' => 'Регулярно обновляем цены. Никаких скрытых наценок.'],
        ] as $adv)
        <div class="text-center p-6 bg-white border border-gray-100 rounded-2xl">
            <div class="text-4xl mb-3">{{ $adv['icon'] }}</div>
            <h3 class="font-bold text-gray-900 mb-2">{{ $adv['title'] }}</h3>
            <p class="text-sm text-gray-500 leading-relaxed">{{ $adv['text'] }}</p>
        </div>
        @endforeach
    </div>
</section>

{{-- ── SEO-ТЕКСТ ────────────────────────────────────────────── --}}
<section class="container mx-auto px-4 py-8 max-w-4xl">
    <div class="prose prose-gray max-w-none text-sm text-gray-600">
        <h2 class="text-xl font-bold text-gray-900">Автохимия в Алматы</h2>
        <p>
            Интернет-магазин Autohimiki.kz — ваш надёжный источник качественной автохимии
            в Алматы и по всему Казахстану. Мы предлагаем широкий ассортимент товаров для
            ухода за автомобилем: автошампуни для бесконтактной и ручной мойки, полироли
            и защитные покрытия, антидождь для стёкол, очистители дисков, чернители шин,
            уход за кожаным и пластиковым салоном, микрофибру и аксессуары для детейлинга.
        </p>
        <p>
            В нашем каталоге собраны лучшие мировые бренды: Meguiar's, Koch Chemie,
            Shine Systems, Turtle Wax, Ma-Fra, Grass, Sonax и другие. Каждый товар
            проходит проверку качества. Консультируем по подбору бесплатно — просто
            напишите в WhatsApp или оставьте заявку.
        </p>
    </div>
</section>

{{-- ── FAQ ────────────────────────────────────────────────────── --}}
<section class="bg-gray-50 py-12">
    <div class="container mx-auto px-4 max-w-3xl">
        <h2 class="text-2xl font-bold text-gray-900 mb-6 text-center">
            Часто задаваемые вопросы
        </h2>
        <div class="space-y-3" x-data="{ open: null }">
            @php
            $faqs = [
                ['q' => 'Как сделать заказ?',
                 'a' => 'Нажмите «Купить через WhatsApp» на странице товара или оставьте заявку. Мы свяжемся с вами в течение 15 минут в рабочее время.'],
                ['q' => 'Есть ли доставка по Казахстану?',
                 'a' => 'Да, доставляем по всему Казахстану через транспортные компании. Стоимость и сроки рассчитываются индивидуально — уточните в WhatsApp.'],
                ['q' => 'Как вернуть товар?',
                 'a' => 'Принимаем возврат в течение 14 дней при наличии чека и оригинальной упаковки согласно законодательству Казахстана.'],
                ['q' => 'Есть ли самовывоз?',
                 'a' => 'Да, самовывоз из нашего магазина в Алматы. Адрес и часы работы указаны в разделе контактов внизу страницы.'],
                ['q' => 'Как правильно выбрать автохимию?',
                 'a' => 'Напишите нам в WhatsApp — расскажите об автомобиле и задаче, и мы порекомендуем подходящий продукт бесплатно.'],
            ];
            @endphp

            @foreach($faqs as $i => $faq)
            <div class="bg-white rounded-xl border border-gray-100">
                <button @click="open = open === {{ $i }} ? null : {{ $i }}"
                        class="w-full flex items-center justify-between px-5 py-4
                               text-left font-medium text-gray-900">
                    <span>{{ $faq['q'] }}</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform duration-200
                                flex-shrink-0 ml-3"
                         :class="open === {{ $i }} ? 'rotate-180' : ''"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="open === {{ $i }}"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="px-5 pb-4 text-sm text-gray-600 leading-relaxed">
                    {{ $faq['a'] }}
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

@endsection

{{-- ── Schema.org ───────────────────────────────────────────── --}}
@section('schema')
{{-- FAQPage — для FAQ-блока на главной --}}
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
        {
            "@type": "Question",
            "name": "Как сделать заказ?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "Нажмите «Купить через WhatsApp» на странице товара или оставьте заявку. Мы свяжемся с вами в течение 15 минут в рабочее время."
            }
        },
        {
            "@type": "Question",
            "name": "Есть ли доставка по Казахстану?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "Да, доставляем по всему Казахстану через транспортные компании. Стоимость и сроки рассчитываются индивидуально."
            }
        },
        {
            "@type": "Question",
            "name": "Есть ли самовывоз?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "Да, самовывоз из нашего магазина в Алматы. Адрес и часы работы указаны в разделе контактов."
            }
        },
        {
            "@type": "Question",
            "name": "Как правильно выбрать автохимию?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "Напишите нам в WhatsApp — расскажите об автомобиле и задаче, и мы порекомендуем подходящий продукт бесплатно."
            }
        }
    ]
}
</script>
@endsection
