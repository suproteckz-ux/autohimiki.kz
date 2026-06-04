<x-filament-panels::page>

{{-- ══════════════════════════════════════════════════════════════
     Индикатор шагов
══════════════════════════════════════════════════════════════ --}}
@if($step <= 4)
<div class="mb-6 flex items-center gap-0">
    @foreach([
        1 => 'Файл',
        2 => 'Просмотр',
        3 => 'Режим',
        4 => 'Маппинг',
    ] as $num => $label)
    <div class="flex items-center {{ $num < 4 ? 'flex-1' : '' }}">
        <div class="flex flex-col items-center">
            <div @class([
                'w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition-colors',
                'bg-primary-500 text-white'  => $step >= $num,
                'bg-gray-200 text-gray-500'  => $step < $num,
            ])>
                @if($step > $num)
                    ✓
                @else
                    {{ $num }}
                @endif
            </div>
            <span class="text-xs mt-1 {{ $step >= $num ? 'text-primary-600 font-medium' : 'text-gray-400' }}">
                {{ $label }}
            </span>
        </div>
        @if($num < 4)
        <div class="flex-1 h-0.5 mx-2 {{ $step > $num ? 'bg-primary-500' : 'bg-gray-200' }}"></div>
        @endif
    </div>
    @endforeach
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════
     ШАГ 1: Загрузка файла
══════════════════════════════════════════════════════════════ --}}
@if($step === 1)
<x-filament::section>
    <x-slot name="heading">Шаг 1 — Загрузка файла</x-slot>
    <x-slot name="description">
        Поддерживаются форматы: XLS, XLSX, CSV. Максимальный размер: 50 MB.
    </x-slot>

    <div class="space-y-4">
        {{-- Загрузка файла --}}
        <div x-data="{ uploading: false }">
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Файл импорта
            </label>
            <input type="file"
                   wire:model="filePath"
                   accept=".xls,.xlsx,.csv"
                   class="block w-full text-sm text-gray-500
                          file:mr-4 file:py-2 file:px-4
                          file:rounded-lg file:border-0
                          file:text-sm file:font-semibold
                          file:bg-primary-50 file:text-primary-700
                          hover:file:bg-primary-100 cursor-pointer">
            <p class="text-xs text-gray-400 mt-1">XLS, XLSX или CSV до 50 MB</p>
        </div>

        {{-- Подсказка по формату 1С --}}
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm">
            <p class="font-semibold text-blue-800 mb-2">📋 Ожидаемый формат файла 1С:</p>
            <table class="w-full text-xs text-blue-700">
                <thead>
                    <tr class="border-b border-blue-200">
                        <th class="text-left py-1 pr-4">Ед. изм.</th>
                        <th class="text-left py-1 pr-4">Номенклатура</th>
                        <th class="text-left py-1 pr-4">Номенклатура.Код</th>
                        <th class="text-left py-1 pr-4">Остаток на складе</th>
                        <th class="text-left py-1">Розничная цена</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="py-1 pr-4">шт</td>
                        <td class="py-1 pr-4">BOMBA FOAM 2G (4 л) Ma-Fra</td>
                        <td class="py-1 pr-4 font-mono">РТ-00001272</td>
                        <td class="py-1 pr-4">17</td>
                        <td class="py-1">7300</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex justify-end">
            <x-filament::button wire:click="uploadFile" size="lg">
                Следующий шаг →
            </x-filament::button>
        </div>
    </div>
</x-filament::section>
@endif

{{-- ══════════════════════════════════════════════════════════════
     ШАГ 2: Предпросмотр
══════════════════════════════════════════════════════════════ --}}
@if($step === 2)
<x-filament::section>
    <x-slot name="heading">Шаг 2 — Предпросмотр файла</x-slot>
    <x-slot name="description">
        Файл: <strong>{{ $fileName }}</strong> |
        Строк: <strong>{{ $totalRows }}</strong> |
        Колонок: <strong>{{ count($fileColumns) }}</strong>
    </x-slot>

    {{-- Найденные колонки --}}
    <div class="mb-4">
        <p class="text-sm font-medium text-gray-700 mb-2">Обнаруженные колонки:</p>
        <div class="flex flex-wrap gap-2">
            @foreach($fileColumns as $col)
            <span class="px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-xs font-mono">
                {{ $col }}
            </span>
            @endforeach
        </div>
    </div>

    {{-- Таблица предпросмотра --}}
    @if(!empty($previewRows))
    <div class="overflow-x-auto rounded-xl border border-gray-200">
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500">#</th>
                    @foreach($fileColumns as $col)
                    <th class="px-3 py-2 text-left text-gray-700 font-semibold whitespace-nowrap">
                        {{ $col }}
                    </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($previewRows as $i => $row)
                <tr class="{{ $i % 2 === 0 ? 'bg-white' : 'bg-gray-50' }}">
                    <td class="px-3 py-1.5 text-gray-400">{{ $i + 1 }}</td>
                    @foreach($fileColumns as $col)
                    <td class="px-3 py-1.5 text-gray-700 whitespace-nowrap max-w-xs truncate">
                        {{ $row[$col] ?? '—' }}
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($totalRows > 20)
    <p class="text-xs text-gray-400 mt-2 text-right">
        Показано 20 из {{ $totalRows }} строк
    </p>
    @endif
    @endif

    <div class="flex justify-between mt-4">
        <x-filament::button color="gray" wire:click="backToStep1">
            ← Назад
        </x-filament::button>
        <x-filament::button wire:click="confirmPreview" size="lg">
            Выглядит верно →
        </x-filament::button>
    </div>
</x-filament::section>
@endif

{{-- ══════════════════════════════════════════════════════════════
     ШАГ 3: Выбор режима импорта
══════════════════════════════════════════════════════════════ --}}
@if($step === 3)
<x-filament::section>
    <x-slot name="heading">Шаг 3 — Режим импорта</x-slot>

    <div class="space-y-4">
        {{-- Режим: prices_only --}}
        <label class="block cursor-pointer">
            <div @class([
                'p-5 border-2 rounded-xl transition-all',
                'border-primary-500 bg-primary-50' => $importMode === 'prices_only',
                'border-gray-200 hover:border-gray-300' => $importMode !== 'prices_only',
            ])>
                <div class="flex items-start gap-3">
                    <input type="radio"
                           wire:model.live="importMode"
                           value="prices_only"
                           class="mt-1 text-primary-500">
                    <div>
                        <p class="font-bold text-gray-900">
                            ⚡ Обновление из 1С
                            <span class="ml-2 text-xs font-normal text-green-600 bg-green-100 px-2 py-0.5 rounded-full">
                                Рекомендуется для ежедневного обновления
                            </span>
                        </p>
                        <p class="text-sm text-gray-600 mt-1">
                            Обновляет <strong>только цену, остаток и наличие</strong> по SKU.
                            Название, описание, SEO, изображения — не меняются.
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded">✓ price</span>
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded">✓ quantity</span>
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded">✓ in_stock</span>
                            <span class="bg-red-100 text-red-600 px-2 py-0.5 rounded">✗ name</span>
                            <span class="bg-red-100 text-red-600 px-2 py-0.5 rounded">✗ slug</span>
                            <span class="bg-red-100 text-red-600 px-2 py-0.5 rounded">✗ SEO</span>
                            <span class="bg-red-100 text-red-600 px-2 py-0.5 rounded">✗ images</span>
                        </div>
                        <p class="text-xs text-amber-600 mt-2">
                            ⚠️ Если SKU не найден — записывается ошибка, товар не создаётся
                        </p>
                    </div>
                </div>
            </div>
        </label>

        {{-- Режим: full --}}
        <label class="block cursor-pointer">
            <div @class([
                'p-5 border-2 rounded-xl transition-all',
                'border-primary-500 bg-primary-50' => $importMode === 'full',
                'border-gray-200 hover:border-gray-300' => $importMode !== 'full',
            ])>
                <div class="flex items-start gap-3">
                    <input type="radio"
                           wire:model.live="importMode"
                           value="full"
                           class="mt-1 text-primary-500">
                    <div>
                        <p class="font-bold text-gray-900">
                            📦 Полный импорт
                        </p>
                        <p class="text-sm text-gray-600 mt-1">
                            Создаёт новые товары и обновляет существующие по всем полям.
                            Используется для первоначального наполнения каталога.
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded">✓ create</span>
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded">✓ update</span>
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded">✓ all fields</span>
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded">✓ images</span>
                        </div>
                    </div>
                </div>
            </div>
        </label>
    </div>

    <div class="flex justify-between mt-6">
        <x-filament::button color="gray" wire:click="backToStep2">
            ← Назад
        </x-filament::button>
        <x-filament::button wire:click="selectMode" size="lg">
            Настроить колонки →
        </x-filament::button>
    </div>
</x-filament::section>
@endif

{{-- ══════════════════════════════════════════════════════════════
     ШАГ 4: Маппинг колонок
══════════════════════════════════════════════════════════════ --}}
@if($step === 4)
<x-filament::section>
    <x-slot name="heading">Шаг 4 — Сопоставление колонок</x-slot>
    <x-slot name="description">
        Режим: <strong>{{ $importMode === 'prices_only' ? 'Обновление из 1С' : 'Полный импорт' }}</strong>.
        Сопоставьте колонки файла с полями системы.
    </x-slot>

    {{-- Шаблоны маппинга --}}
    @php
        $templates = \App\Models\ImportColumnTemplate::where('type', $importMode)->get();
    @endphp
    @if($templates->count())
    <div class="mb-5 p-4 bg-gray-50 rounded-xl">
        <p class="text-sm font-medium text-gray-700 mb-2">Сохранённые шаблоны:</p>
        <div class="flex flex-wrap gap-2">
            @foreach($templates as $tpl)
            <button wire:click="loadTemplate({{ $tpl->id }})"
                    @class([
                        'px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                        'border-primary-500 bg-primary-50 text-primary-700' => $templateId === $tpl->id,
                        'border-gray-200 text-gray-700 hover:border-gray-300' => $templateId !== $tpl->id,
                    ])>
                {{ $tpl->name }}
                @if($tpl->is_default)
                    <span class="text-xs text-green-600">(по умолч.)</span>
                @endif
            </button>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Таблица маппинга --}}
    <div class="space-y-3">
        @foreach($this->getSystemFields() as $systemField => $variants)
        @php
            $isRequired = in_array($systemField, ['sku', 'price', 'name']);
            $label = match($systemField) {
                'sku'               => 'SKU / Артикул',
                'name'              => 'Название товара',
                'price'             => 'Цена',
                'old_price'         => 'Старая цена',
                'quantity'          => 'Остаток',
                'category'          => 'Категория',
                'brand'             => 'Бренд',
                'unit'              => 'Единица измерения',
                'short_description' => 'Краткое описание',
                'description'       => 'Описание',
                'image_url'         => 'URL изображения',
                'meta_title'        => 'Meta Title',
                'meta_description'  => 'Meta Description',
                default             => $systemField,
            };
        @endphp
        <div class="flex items-center gap-4">
            <div class="w-48 flex-shrink-0">
                <span class="text-sm font-medium text-gray-800">
                    {{ $label }}
                    @if($isRequired)
                        <span class="text-red-500">*</span>
                    @endif
                </span>
            </div>
            <div class="flex-1">
                <select wire:model.live="columnMap.{{ $systemField }}"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2
                               focus:outline-none focus:ring-1 focus:ring-primary-400">
                    <option value="">— не использовать —</option>
                    @foreach($fileColumns as $col)
                    <option value="{{ $col }}"
                            {{ ($columnMap[$systemField] ?? '') === $col ? 'selected' : '' }}>
                        {{ $col }}
                    </option>
                    @endforeach
                </select>
            </div>
            {{-- Индикатор авто-определения --}}
            @if(!empty($columnMap[$systemField]))
            <span class="text-xs text-green-600 flex-shrink-0">✓ определено</span>
            @elseif($isRequired)
            <span class="text-xs text-red-500 flex-shrink-0">обязательно</span>
            @endif
        </div>
        @endforeach
    </div>

    {{-- Сохранение шаблона --}}
    <div class="mt-6 pt-4 border-t border-gray-100"
         x-data="{ saving: false, templateName: '' }">
        <button @click="saving = !saving"
                class="text-sm text-gray-500 hover:text-gray-700">
            + Сохранить текущий маппинг как шаблон
        </button>
        <div x-show="saving" class="mt-3 flex gap-2">
            <input type="text"
                   x-model="templateName"
                   placeholder="Название шаблона"
                   class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm
                          focus:outline-none focus:ring-1 focus:ring-primary-400">
            <button @click="$wire.saveTemplate(templateName); saving = false"
                    class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm
                           hover:bg-primary-600">
                Сохранить
            </button>
        </div>
    </div>

    <div class="flex justify-between mt-6">
        <x-filament::button color="gray" wire:click="backToStep2">
            ← Назад
        </x-filament::button>
        <x-filament::button
            wire:click="startImport"
            size="lg"
            color="{{ $importMode === 'prices_only' ? 'success' : 'primary' }}">
            🚀 Запустить импорт ({{ $totalRows }} строк)
        </x-filament::button>
    </div>
</x-filament::section>
@endif

{{-- ══════════════════════════════════════════════════════════════
     ШАГ 5: Прогресс и результат
══════════════════════════════════════════════════════════════ --}}
@if($step === 5)
<x-filament::section
    x-data="{ polling: true }"
    x-init="
        if(polling) {
            let interval = setInterval(() => {
                $wire.pollProgress();
                if($wire.progress.status === 'done' || $wire.progress.status === 'failed') {
                    clearInterval(interval);
                    polling = false;
                }
            }, 3000);
        }
    ">

    @if(($progress['status'] ?? '') === 'done')
    {{-- ── Результат ──────────────────────────────────────────── --}}
    <x-slot name="heading">
        ✅ Импорт завершён
    </x-slot>

    @php $batch = \App\Models\ImportBatch::find($batchId); @endphp
    @if($batch)
    <div class="space-y-4">
        {{-- Счётчики --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach([
                ['label' => 'Строк обработано', 'value' => $batch->total_rows, 'color' => 'gray'],
                ['label' => 'Создано', 'value' => $batch->created_count, 'color' => 'green'],
                ['label' => 'Обновлено', 'value' => $batch->updated_count, 'color' => 'blue'],
                ['label' => 'Пропущено', 'value' => $batch->skipped_count, 'color' => 'yellow'],
                ['label' => 'Не найдено (SKU)', 'value' => $batch->not_found_count, 'color' => 'orange'],
                ['label' => 'Ошибок', 'value' => $batch->error_count, 'color' => 'red'],
                ['label' => 'Время', 'value' => $batch->duration, 'color' => 'gray'],
            ] as $stat)
            <div class="bg-{{ $stat['color'] }}-50 border border-{{ $stat['color'] }}-100
                        rounded-xl p-4 text-center">
                <p class="text-2xl font-bold text-{{ $stat['color'] }}-700">
                    {{ $stat['value'] ?? 0 }}
                </p>
                <p class="text-xs text-{{ $stat['color'] }}-600 mt-1">{{ $stat['label'] }}</p>
            </div>
            @endforeach
        </div>

        {{-- Изменения цен --}}
        @if(!empty($batch->price_changes))
        <div class="mt-4">
            <h3 class="font-semibold text-gray-800 mb-2">
                Изменения цен ({{ count($batch->price_changes) }})
            </h3>
            <div class="overflow-x-auto rounded-xl border border-gray-200 max-h-64 overflow-y-auto">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-3 py-2 text-left">SKU</th>
                            <th class="px-3 py-2 text-left">Товар</th>
                            <th class="px-3 py-2 text-right">Было</th>
                            <th class="px-3 py-2 text-right">Стало</th>
                            <th class="px-3 py-2 text-right">Изменение</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach(array_slice($batch->price_changes, 0, 100) as $change)
                        <tr>
                            <td class="px-3 py-1.5 font-mono text-gray-500">{{ $change['sku'] }}</td>
                            <td class="px-3 py-1.5 text-gray-700 truncate max-w-xs">{{ $change['name'] }}</td>
                            <td class="px-3 py-1.5 text-right text-gray-500">
                                {{ number_format($change['old'], 0, '.', ' ') }} ₸
                            </td>
                            <td class="px-3 py-1.5 text-right font-medium text-gray-900">
                                {{ number_format($change['new'], 0, '.', ' ') }} ₸
                            </td>
                            <td @class([
                                'px-3 py-1.5 text-right font-medium',
                                'text-red-600' => $change['diff'] > 0,
                                'text-green-600' => $change['diff'] < 0,
                            ])>
                                {{ $change['diff'] > 0 ? '+' : '' }}{{ number_format($change['diff'], 0, '.', ' ') }} ₸
                                @if($change['pct'] !== null)
                                <span class="text-gray-400">({{ $change['pct'] }}%)</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- Ошибки --}}
        @if($batch->error_count > 0 || $batch->not_found_count > 0)
        <div class="mt-4">
            <a href="/admin/import-errors?batch={{ $batchId }}"
               class="inline-flex items-center gap-2 text-sm text-red-600 hover:underline">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Просмотреть ошибки ({{ $batch->error_count + $batch->not_found_count }})
            </a>
        </div>
        @endif
    </div>
    @endif

    <div class="mt-6 flex gap-3">
        <x-filament::button wire:click="resetWizard">
            Новый импорт
        </x-filament::button>
        <a href="/admin/import-batches" class="text-sm text-gray-500 hover:underline self-center">
            История импортов →
        </a>
    </div>

    @elseif(($progress['status'] ?? '') === 'failed')
    {{-- ── Ошибка ──────────────────────────────────────────────── --}}
    <x-slot name="heading">❌ Импорт завершился с ошибкой</x-slot>

    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
        {{ $progress['error'] ?? 'Неизвестная ошибка. Проверьте логи.' }}
    </div>

    <div class="mt-4">
        <x-filament::button wire:click="resetWizard" color="gray">
            Попробовать снова
        </x-filament::button>
    </div>

    @else
    {{-- ── Процесс ─────────────────────────────────────────────── --}}
    <x-slot name="heading">⏳ Импорт выполняется...</x-slot>

    <div class="space-y-4">
        <div class="flex items-center gap-3">
            <div class="flex-1 bg-gray-200 rounded-full h-3 overflow-hidden">
                <div class="bg-primary-500 h-3 rounded-full transition-all duration-500"
                     style="width: {{ $progress['percent'] ?? 0 }}%">
                </div>
            </div>
            <span class="text-sm font-bold text-gray-700 w-12 text-right">
                {{ $progress['percent'] ?? 0 }}%
            </span>
        </div>

        <p class="text-sm text-gray-500 text-center">
            Файл обрабатывается в фоне. Не закрывайте страницу.<br>
            Прогресс обновляется каждые 3 секунды.
        </p>

        <div class="flex justify-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-500"></div>
        </div>
    </div>
    @endif
</x-filament::section>
@endif

</x-filament-panels::page>
