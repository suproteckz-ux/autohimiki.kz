@php
    $errors   = array_filter((array) ($report['Ошибки'] ?? []));
    $warnings = array_filter((array) ($report['Предупреждения'] ?? []));
    $safe     = fn ($v) => app(\App\Services\Ozon\OzonConnectionResponsePreview::class)->message($v ?? '—');
@endphp

<div class="space-y-4 text-sm">

    {{-- Product + content summary --}}
    <div class="grid grid-cols-2 gap-x-6 gap-y-2 lg:grid-cols-3">

        <div class="col-span-full text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Товар</div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">SKU:</span>
            <span class="ml-1 font-mono">{{ $safe($report['SKU'] ?? null) }}</span></div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">Название:</span>
            <span class="ml-1">{{ $safe($report['Название'] ?? null) }}</span></div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">Локальная категория:</span>
            <span class="ml-1">{{ $safe($report['Локальная категория'] ?? null) }}</span></div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">Цена:</span>
            <span class="ml-1">{{ $safe($report['Цена сайта'] ?? null) }}</span></div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">Остаток:</span>
            <span class="ml-1">{{ $safe($report['Остаток (после публикации)'] ?? null) }}</span></div>

        <div class="col-span-full mt-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Контент</div>

        <div>
            <span class="font-medium text-gray-700 dark:text-gray-300">Главное фото:</span>
            @php $hasPhoto = ($report['Главное фото'] ?? '') === 'Есть'; @endphp
            <span class="ml-1 {{ $hasPhoto ? 'text-green-600 dark:text-green-400' : 'font-semibold text-red-600 dark:text-red-400' }}">
                {{ $safe($report['Главное фото'] ?? null) }}
            </span>
        </div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">Галерея:</span>
            <span class="ml-1">{{ $safe($report['Фото галерея'] ?? null) }}</span></div>

        <div>
            <span class="font-medium text-gray-700 dark:text-gray-300">Описание:</span>
            @php $hasDesc = str_starts_with((string)($report['Описание'] ?? ''), 'Есть'); @endphp
            <span class="ml-1 {{ $hasDesc ? '' : 'text-amber-600 dark:text-amber-400' }}">
                {{ $safe($report['Описание'] ?? null) }}
            </span>
        </div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">Характеристики:</span>
            <span class="ml-1">{{ $safe($report['Характеристики'] ?? null) }}</span></div>

        <div class="col-span-full mt-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Ozon категория</div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">description_category_id:</span>
            <span class="ml-1 font-mono">{{ $safe($report['description_category_id'] ?? null) }}</span></div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">type_id:</span>
            <span class="ml-1 font-mono">{{ $safe($report['type_id'] ?? null) }}</span></div>

        <div><span class="font-medium text-gray-700 dark:text-gray-300">Категория Ozon:</span>
            <span class="ml-1">{{ $safe($report['Категория Ozon'] ?? null) }}</span></div>

    </div>

    {{-- Blocking errors --}}
    @if($errors)
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950">
            <p class="mb-1 font-semibold text-red-800 dark:text-red-200">Блокирующие ошибки ({{ count($errors) }}):</p>
            <ul class="list-inside list-disc space-y-0.5 text-red-700 dark:text-red-300">
                @foreach($errors as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Warnings --}}
    @if($warnings)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950">
            <p class="mb-1 font-semibold text-amber-800 dark:text-amber-200">Предупреждения ({{ count($warnings) }}):</p>
            <ul class="list-inside list-disc space-y-0.5 text-amber-700 dark:text-amber-300">
                @foreach($warnings as $warn)
                    <li>{{ $warn }}</li>
                @endforeach
            </ul>
        </div>
    @endif

</div>
