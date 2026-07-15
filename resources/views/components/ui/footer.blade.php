@php
    $phone     = \App\Services\CacheService::setting('phone', '');
    $whatsapp  = \App\Services\CacheService::setting('whatsapp', '');
    $address   = \App\Services\CacheService::setting('address', 'Алматы, Казахстан');
    $instagram = \App\Services\CacheService::setting('instagram', '');
    $email     = \App\Services\CacheService::setting('email', '');
    $siteName  = config('app.name', 'Autohimiki.kz');
@endphp

<footer class="bg-gray-900 text-gray-400 mt-auto">
    <div class="container mx-auto px-4 py-12">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-10">

            {{-- Бренд --}}
            <div class="lg:col-span-1">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 mb-4">
                    <div class="w-8 h-8 bg-amber-500 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <span class="text-white font-bold text-lg">{{ $siteName }}</span>
                </a>
                <p class="text-sm leading-relaxed mb-4">
                    Автохимия и детейлинг в Алматы. Проверенные бренды, широкий ассортимент, быстрая консультация.
                </p>
                {{-- Соцсети --}}
                <div class="flex items-center gap-3">
                    @if($instagram)
                    <a href="{{ $instagram }}" target="_blank" rel="noopener"
                       class="w-9 h-9 bg-gray-800 hover:bg-amber-500 rounded-lg flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4 text-gray-400 hover:text-white" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                        </svg>
                    </a>
                    @endif
                    @if($whatsapp)
                    <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"
                       class="w-9 h-9 bg-gray-800 hover:bg-green-600 rounded-lg flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/>
                        </svg>
                    </a>
                    @endif
                </div>
            </div>

            {{-- Каталог --}}
            <div>
                <h4 class="text-white font-semibold mb-4 text-sm uppercase tracking-wide">Каталог</h4>
                <ul class="space-y-2.5 text-sm">
                    <li><a href="{{ route('catalog') }}" class="hover:text-amber-400 transition-colors">Все товары</a></li>
                    <li><a href="{{ route('brands') }}"  class="hover:text-amber-400 transition-colors">Бренды</a></li>
                    <li><a href="{{ route('blog') }}"    class="hover:text-amber-400 transition-colors">Блог</a></li>
                    <li><a href="{{ route('search') }}"  class="hover:text-amber-400 transition-colors">Поиск</a></li>
                </ul>
            </div>

            {{-- Покупателям --}}
            <div>
                <h4 class="text-white font-semibold mb-4 text-sm uppercase tracking-wide">Покупателям</h4>
                <ul class="space-y-2.5 text-sm">
                    <li><span class="text-gray-500">Доставка по Алматы</span></li>
                    <li><span class="text-gray-500">Самовывоз</span></li>
                    <li><span class="text-gray-500">Консультация бесплатно</span></li>
                    @if($whatsapp)
                    <li>
                        <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"
                           class="text-green-400 hover:text-green-300 transition-colors font-medium">
                            Написать в WhatsApp →
                        </a>
                    </li>
                    @endif
                </ul>
            </div>

            {{-- Контакты --}}
            <div>
                <h4 class="text-white font-semibold mb-4 text-sm uppercase tracking-wide">Контакты</h4>
                <ul class="space-y-3 text-sm">
                    @if($address)
                    <li class="flex items-start gap-2">
                        <svg class="w-4 h-4 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>{{ $address }}</span>
                    </li>
                    @endif
                    @if($phone)
                    <li class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                        <a href="tel:{{ preg_replace('/\D/', '', $phone) }}"
                           class="hover:text-amber-400 transition-colors">{{ $phone }}</a>
                    </li>
                    @endif
                    @if($email)
                    <li class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <a href="mailto:{{ $email }}" class="hover:text-amber-400 transition-colors">{{ $email }}</a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>

        {{-- Нижняя полоса --}}
        <div class="border-t border-gray-800 pt-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-600">
            <p>© {{ date('Y') }} {{ $siteName }}. Все права защищены.</p>
            <p>Автохимия в Алматы, Казахстан</p>
        </div>
    </div>
</footer>
