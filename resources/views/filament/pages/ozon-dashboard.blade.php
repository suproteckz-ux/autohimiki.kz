<x-filament-panels::page>
    <x-filament::section heading="Подключение Ozon Seller">
        <div class="space-y-3">
            <p><strong>API:</strong> {{ isset($settings['checked_at']) ? ($settings['connection_ok'] ? '✓ Подключено' : 'Ошибка') : 'Ещё не проверено из админки' }}</p>
            @if(isset($settings['checked_at']))
                <p>{{ $settings['connection_message'] }} · {{ $settings['checked_at'] }}</p>
            @endif
            <p><strong>Seller:</strong> {{ $settings['seller'] ?? 'NetBazar (подтверждён пользователем на production)' }}</p>
            <p><strong>Client ID:</strong> {{ config('ozon.client_id') ? 'Настроен' : 'Не настроен' }} · <strong>API Key:</strong> {{ config('ozon.api_key') ? 'Настроен' : 'Не настроен' }}</p>
            <p>{{ $writesEnabled ? 'Ручная отправка и остатки разрешены настройкой OZON_ENABLED.' : 'OZON_ENABLED=false — отправка и остатки заблокированы. Локальная проверка и проверка подключения доступны.' }}</p>
            <x-filament::button wire:click="checkConnection" wire:loading.attr="disabled">Проверить подключение</x-filament::button>
            <p wire:loading wire:target="checkConnection">Проверяем подключение…</p>
        </div>
    </x-filament::section>
    <x-filament::section heading="Рабочий склад">
        <div class="space-y-3">
            <p><strong>Warehouse ID для остатков:</strong> {{ $warehouseId }}</p>
            <p>Подтверждён пользователем на production: <strong>Муратбаева 138</strong>, ID <strong>1020005000312240</strong>, активен.</p>
            @if((string) config('ozon.warehouse_id') !== '1020005000312240')
                <p>Текущая настройка отличается от подтверждённого склада. Перед обновлением остатков проверьте OZON_WAREHOUSE_ID.</p>
            @endif
            <x-filament::button color="gray" wire:click="checkWarehouses" wire:loading.attr="disabled">Проверить склады</x-filament::button>
            <p>{{ $warehouseMessage }}</p>
            @include('filament.ozon.report', ['reports' => $warehouses])
        </div>
    </x-filament::section>
    <x-filament::section heading="Общая категория — Очистители салона">
        <form wire:submit="saveCategory" class="space-y-4">
            <p>Все выбранные товары из любых локальных категорий используют эту пару. Укажите подтверждённые ID категории и типа; без них отправка невозможна.</p>
            <label class="block">description_category_id
                <x-filament::input.wrapper><x-filament::input type="text" inputmode="numeric" wire:model="categoryId" /></x-filament::input.wrapper>
            </label>
            @error('categoryId') <p class="text-danger-600">{{ $message }}</p> @enderror
            <label class="block">type_id
                <x-filament::input.wrapper><x-filament::input type="text" inputmode="numeric" wire:model="typeId" /></x-filament::input.wrapper>
            </label>
            @error('typeId') <p class="text-danger-600">{{ $message }}</p> @enderror
            <x-filament::button type="submit" wire:loading.attr="disabled">Сохранить категорию</x-filament::button>
        </form>
    </x-filament::section>
    <x-filament::button tag="a" :href="\App\Filament\Pages\OzonProducts::getUrl()">Перейти к товарам</x-filament::button>
</x-filament-panels::page>
