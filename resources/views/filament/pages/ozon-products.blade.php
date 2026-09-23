<x-filament-panels::page>
    <x-filament::section heading="Ручная работа с Ozon">
        <p>Проверить → отправить один товар → проверить статус → доработать и опубликовать в Ozon → подтвердить публикацию → обновить остаток.</p>
        <p>Все товары идут в «Очистители салона». Task ID означает только принятие задачи. Выбирайте не более 10 товаров для проверки или остатков.</p>
    </x-filament::section>
    {{ $this->table }}
    @if($reports)
        <x-filament::section heading="Результат последнего действия">
            @include('filament.ozon.report', ['reports' => $reports])
        </x-filament::section>
    @endif
</x-filament-panels::page>
