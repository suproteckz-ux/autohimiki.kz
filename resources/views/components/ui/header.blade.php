@php
    $phone    = \App\Services\CacheService::setting('phone', '');
    $whatsapp = \App\Services\CacheService::setting('whatsapp', '');
    $siteName = config('app.name', 'Autohimiki.kz');
@endphp

<header x-data="{ mobileOpen: false }" class="bg-gray-900 shadow-xl sticky top-0 z-50">

    {{-- Верхняя полоса --}}
    <div class="border-b border-gray-800">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-14 gap-4">

                {{-- Логотип --}}
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 flex-shrink-0 group">
                    <div class="w-8 h-8 bg-amber-500 rounded-lg flex items-center justify-center
                                group-hover:bg-amber-400 transition-colors">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                  d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <span class="text-white font-bold text-lg tracking-tight">{{ $siteName }}</span>
                </a>

                {{-- Поиск (десктоп) --}}
                <form action="{{ route('search') }}" method="GET"
                      class="flex-1 max-w-lg hidden md:flex">
                    <div class="flex w-full">
                        <input type="search" name="q"
                               value="{{ request('q') }}"
                               placeholder="Поиск автохимии, бренда, артикула..."
                               class="flex-1 px-4 py-2.5 bg-gray-800 border border-gray-700 rounded-l-xl
                                      text-sm text-white placeholder-gray-500
                                      focus:outline-none focus:border-amber-500 transition-colors">
                        <button type="submit"
                                class="px-4 py-2.5 bg-amber-500 hover:bg-amber-400 rounded-r-xl transition-colors">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </button>
                    </div>
                </form>

                {{-- Контакты --}}
                <div class="flex items-center gap-2">
                    @if($phone)
                    <a href="tel:{{ preg_replace('/\D/', '', $phone) }}"
                       class="hidden lg:flex items-center gap-1.5 text-sm font-medium text-gray-300
                              hover:text-amber-400 transition-colors whitespace-nowrap">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                        {{ $phone }}
                    </a>
                    @endif

                    @if($whatsapp)
                    <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"
                       class="flex items-center gap-1.5 px-3 py-2 bg-green-600 hover:bg-green-500
                              text-white text-sm font-semibold rounded-lg transition-colors">
                        <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/>
                        </svg>
                        <span class="hidden sm:inline">WhatsApp</span>
                    </a>
                    @endif

                    {{-- Бургер мобильный --}}
                    <button @click="mobileOpen = !mobileOpen"
                            class="md:hidden p-2 text-gray-400 hover:text-white transition-colors rounded-lg
                                   hover:bg-gray-800">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path x-show="!mobileOpen" stroke-linecap="round" stroke-linejoin="round"
                                  stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            <path x-show="mobileOpen" stroke-linecap="round" stroke-linejoin="round"
                                  stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Навигация десктоп --}}
    <div class="container mx-auto px-4">
        <nav class="hidden md:flex items-center gap-1 h-11">
            @foreach([
                [route('catalog'), 'Каталог', 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
                [route('brands'),  'Бренды',  'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z'],
                [route('blog'),    'Блог',    'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z'],
            ] as [$href, $label, $icon])
            <a href="{{ $href }}"
               class="flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-gray-400
                      hover:text-amber-400 hover:bg-gray-800 rounded-lg transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
                </svg>
                {{ $label }}
            </a>
            @endforeach
        </nav>
    </div>

    {{-- Мобильное меню --}}
    <div x-show="mobileOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-end="opacity-0"
         class="md:hidden border-t border-gray-800 pb-4">

        <form action="{{ route('search') }}" method="GET" class="px-4 pt-4 pb-3">
            <div class="flex">
                <input type="search" name="q" placeholder="Поиск..."
                       class="flex-1 px-4 py-3 bg-gray-800 border border-gray-700 rounded-l-xl
                              text-sm text-white placeholder-gray-500 focus:outline-none focus:border-amber-500">
                <button type="submit" class="px-4 bg-amber-500 rounded-r-xl">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </button>
            </div>
        </form>

        <div class="px-4 space-y-1">
            @foreach([[route('catalog'),'Каталог'],[route('brands'),'Бренды'],[route('blog'),'Блог']] as [$href,$label])
            <a href="{{ $href }}" @click="mobileOpen = false"
               class="block px-4 py-3 text-gray-300 hover:text-white hover:bg-gray-800 rounded-xl transition-colors font-medium">
                {{ $label }}
            </a>
            @endforeach

            @if($phone)
            <a href="tel:{{ preg_replace('/\D/', '', $phone) }}"
               class="flex items-center gap-2 px-4 py-3 text-amber-400 font-semibold">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                {{ $phone }}
            </a>
            @endif
        </div>
    </div>
</header>
