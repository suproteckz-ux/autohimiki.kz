<x-filament-panels::page>

<x-filament::section>
    <x-slot name="heading">Экспорт товаров в Excel</x-slot>
    <x-slot name="description">
        Скачать список товаров в формате XLSX для анализа или импорта в другие системы.
    </x-slot>

    <div class="space-y-6">

        {{-- Настройки экспорта --}}
        <div class="space-y-3">
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="checkbox"
                       wire:model.live="activeOnly"
                       class="w-4 h-4 text-primary-500 rounded focus:ring-primary-400">
                <div>
                    <span class="text-sm font-medium text-gray-800">Только активные товары</span>
                    <p class="text-xs text-gray-500">Снимите галочку для экспорта всех товаров включая неактивные</p>
                </div>
            </label>
        </div>

        {{-- Описание полей экспорта --}}
        <div class="bg-gray-50 rounded-xl p-4">
            <p class="text-sm font-medium text-gray-700 mb-3">Поля в файле экспорта:</p>
            <div class="flex flex-wrap gap-2">
                @foreach(['SKU (Артикул)', 'Название', 'Бренд', 'Категория', 'Цена (тг)', 'Старая цена (тг)', 'Остаток', 'В наличии', 'URL'] as $field)
                <span class="px-3 py-1 bg-white border border-gray-200 rounded-full text-xs text-gray-700 font-mono">
                    {{ $field }}
                </span>
                @endforeach
            </div>
        </div>

        {{-- Кнопка скачивания --}}
        <div>
            <x-filament::button
                wire:click="exportProducts"
                size="lg"
                icon="heroicon-o-arrow-down-tray">
                Скачать Excel
            </x-filament::button>
            <p class="text-xs text-gray-400 mt-2">
                Формат: XLSX • Кодировка: UTF-8
            </p>
        </div>

    </div>
</x-filament::section>

</x-filament-panels::page>
