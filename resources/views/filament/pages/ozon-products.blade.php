<x-filament-panels::page>

    {{-- Check result panel: ABOVE the table so it's always in the viewport.
         wire:key gives Livewire a stable DOM anchor — prevents the morphing bug where
         a newly-true @if block fails to insert into the DOM after a table action fires. --}}
    <div wire:key="check-result-panel">
        @if($reports && $lastAction === 'check')
            @php $report = $reports[0]; $ready = ($report['Готов к отправке'] ?? '') === 'READY TO SEND'; @endphp
            <x-filament::section>
                <x-slot name="heading">
                    <span class="flex items-center gap-2">
                        Результат проверки:
                        <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">{{ $report['SKU'] ?? '' }}</code>
                        @if($ready)
                            <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-900 dark:text-green-200">
                                ✓ READY TO SEND
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-900 dark:text-red-200">
                                ✗ NOT READY TO SEND
                            </span>
                        @endif
                    </span>
                </x-slot>
                @include('filament.ozon.check-result', ['report' => $report])
            </x-filament::section>
        @endif
    </div>

    <x-filament::section heading="Ozon → Товары">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Проверить → отправить один товар → проверить статус → опубликовать в Ozon → подтвердить публикацию → обновить остаток.
            Все товары идут в категорию «Очистители салона». Не более 10 товаров за один bulk-запуск.
        </p>
    </x-filament::section>

    {{ $this->table }}

    {{-- Results of non-check actions (status, send, stock etc.) shown below the table. --}}
    <div wire:key="action-result-panel">
        @if($reports && $lastAction !== 'check')
            <x-filament::section heading="Результат: {{ $lastAction }}">
                @include('filament.ozon.report', ['reports' => $reports])
            </x-filament::section>
        @endif
    </div>

</x-filament-panels::page>
