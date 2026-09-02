<!DOCTYPE html>
<html lang="ru" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    {{-- viewport: height=device-height предотвращает CLS при появлении адресной строки --}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $pageTitle  = trim(View::yieldContent('title'))       ?: config('app.name');
        $pageDesc   = trim(View::yieldContent('description')) ?: '';
        $ogImageUrl = $ogImage ?? asset('img/og-default.jpg');
        $ogType     = $ogType  ?? 'website';
        $settings   = \App\Services\CacheService::settings();
    @endphp

    {{-- ── Critical meta (первыми для скорости парсинга) ─────── --}}
    <title>{{ $pageTitle }}</title>

    @if($pageDesc)
    <meta name="description" content="{{ $pageDesc }}">
    @endif

    {{-- Canonical --}}
    @hasSection('canonical')
        @yield('canonical')
    @else
        <link rel="canonical" href="{{ url()->current() }}">
    @endif

    {{-- Robots --}}
    @hasSection('noindex')
        <meta name="robots" content="noindex, nofollow">
    @else
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
    @endif

    {{-- ── Open Graph ──────────────────────────────────────────── --}}
    <meta property="og:site_name"    content="Autohimiki.kz">
    <meta property="og:type"         content="{{ $ogType }}">
    <meta property="og:title"        content="{{ $pageTitle }}">
    <meta property="og:description"  content="{{ $pageDesc }}">
    <meta property="og:image"        content="{{ $ogImageUrl }}">
    <meta property="og:image:width"  content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url"          content="{{ url()->current() }}">
    <meta property="og:locale"       content="ru_RU">
    @yield('og_override')

    {{-- Twitter Card --}}
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDesc }}">
    <meta name="twitter:image"       content="{{ $ogImageUrl }}">

    {{-- ── Favicon ────────────────────────────────────────────── --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
    @if($settings['favicon'] ?? false)
    <link rel="icon" href="{{ asset('storage/' . $settings['favicon']) }}" type="image/png">
    @endif

    {{-- ── PRECONNECT (ускоряем DNS для внешних ресурсов) ─────── --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    {{-- ── LCP ОПТИМИЗАЦИЯ: preload критичных ресурсов ───────── --}}
    {{-- CSS загружается первым, блокирует рендер меньше --}}
    @vite(['resources/css/app.css'])

    {{-- Шрифт: display=swap предотвращает FOIT --}}
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    {{-- Preload главного изображения (только если передано из контроллера) --}}
    @if(isset($preloadImage))
    <link rel="preload"
          href="{{ $preloadImage }}"
          as="image"
          fetchpriority="high">
    @endif

    {{-- Preload логотипа если он WebP --}}
    @if($settings['logo'] ?? false)
    <link rel="preload"
          href="{{ asset('storage/' . $settings['logo']) }}"
          as="image"
          fetchpriority="high">
    @endif
</head>
{{-- bg-white явно устанавливает фон — предотвращает CLS flash --}}
<body class="min-h-screen flex flex-col bg-white text-gray-800"
      style="background-color: #fff">

    {{-- header имеет min-height чтобы предотвратить CLS при загрузке --}}
    @include('components.ui.header')

    @hasSection('breadcrumbs')
    {{-- Фиксированная высота хлебных крошек предотвращает CLS --}}
    <div class="bg-gray-50 border-b border-gray-100" style="min-height: 36px">
        <div class="container mx-auto px-4 py-2">
            @yield('breadcrumbs')
        </div>
    </div>
    @endif

    <main class="flex-grow">
        @yield('content')
    </main>

    @include('components.ui.footer')
    @include('components.ui.whatsapp-button')
    @include('components.ui.lead-modal')

    {{-- Schema —всегда в конце --}}
    @include('components.schema.local-business')
    @yield('schema')

    {{-- JS загружается последним — не блокирует рендер --}}
    @vite(['resources/js/app.js'])

    @include('components.ui.analytics')
    @stack('scripts')
</body>
</html>
