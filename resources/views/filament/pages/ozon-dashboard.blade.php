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
    <x-filament::section heading="НДС для товаров Ozon">
        <div class="space-y-3">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Единая ставка НДС для всех экспортируемых товаров. Передаётся в поле <code>vat</code> запроса <code>v3/product/import</code>.
                Хранится в настройках; после сохранения применяется при следующей проверке и отправке.
            </p>
            @if(!empty($settings['vat']))
                <p class="text-sm"><strong>Текущая ставка:</strong> {{ \App\Services\Ozon\OzonAdminSettings::VAT_RATES[$settings['vat']] ?? $settings['vat'] }}</p>
            @else
                <p class="text-sm font-semibold text-red-600 dark:text-red-400">Ставка НДС не настроена — dry-run покажет ошибку ozon_vat_missing.</p>
            @endif
            {{ $this->vatForm }}
            <x-filament::button wire:click="saveVat" wire:loading.attr="disabled" wire:target="saveVat">
                Сохранить НДС
            </x-filament::button>
        </div>
    </x-filament::section>
    <x-filament::section heading="Общая категория Ozon">
        <div class="space-y-4">
            <p>Все товары (Очистители салона) отправляются в одну пару description_category_id / type_id. Выберите из дерева Ozon или введите ID вручную. Сохраняются только эти два поля.</p>
            <p><strong>Категория Ozon:</strong> {{ \App\Services\Ozon\OzonAdminSettings::CATEGORY }}</p>
            @if(!empty($settings['description_category_id']))
                <p><strong>Текущая:</strong> category_id={{ $settings['description_category_id'] }} / type_id={{ $settings['type_id'] ?? '?' }}</p>
            @endif
            <div class="flex items-center gap-3">
                <x-filament::button color="gray" wire:click="loadCategoryOptions" wire:loading.attr="disabled" wire:target="loadCategoryOptions">
                    Загрузить список из Ozon
                </x-filament::button>
                <span wire:loading wire:target="loadCategoryOptions" class="text-sm text-gray-500">Загрузка категорий и типов Ozon…</span>
            </div>
            {{ $this->categoryForm }}
            {{-- Alpine picker: pairs arrive as a one-time browser event and live only in JS memory.
                 Nothing is written to session, cache, DB, or disk. --}}
            @if (($this->categoryFormState['taxonomyMode'] ?? 'taxonomy') === 'taxonomy')
            <div
                x-data="{
                    pairs: {},
                    count: 0,
                    search: '',
                    results: [],
                    selectedKey: '',
                    open: false,
                    init() {
                        const saved = @js($this->categoryFormState['categoryTypeKey'] ?? '');
                        if (saved.includes('|')) {
                            this.selectedKey = saved;
                            const p = saved.split('|');
                            this.search = 'ID ' + p[0] + ' / Тип ' + p[1];
                        }
                        $wire.on('ozon-category-pairs', ({pairs, count}) => {
                            this.pairs = pairs ?? {};
                            this.count = count ?? 0;
                            if (this.selectedKey && this.pairs[this.selectedKey]) {
                                this.search = this.pairs[this.selectedKey];
                            }
                            this.doFilter();
                        });
                    },
                    doFilter() {
                        const entries = Object.entries(this.pairs);
                        const term = this.search.toLowerCase();
                        this.results = (term
                            ? entries.filter(([, v]) => v.toLowerCase().includes(term))
                            : entries
                        ).slice(0, 50);
                    },
                    pick(key, label) {
                        this.selectedKey = key;
                        this.search = label;
                        this.open = false;
                        $wire.set('categoryFormState.categoryTypeKey', key);
                    },
                    onInput() {
                        if (this.selectedKey) {
                            this.selectedKey = '';
                            $wire.set('categoryFormState.categoryTypeKey', '');
                        }
                        this.doFilter();
                        this.open = Object.keys(this.pairs).length > 0;
                    }
                }"
            >
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                    Категория и тип Ozon
                </label>
                <div class="relative">
                    <input
                        type="text"
                        x-model="search"
                        @input="onInput()"
                        @focus="open = !selectedKey && Object.keys(pairs).length > 0"
                        @keydown.escape="open = false"
                        placeholder="Введите название для поиска…"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                    />
                    <div
                        x-show="open && results.length > 0"
                        @click.outside="open = false"
                        x-transition
                        class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:bg-gray-800 dark:border-gray-600"
                    >
                        <template x-for="[key, label] in results" :key="key">
                            <div
                                @click="pick(key, label)"
                                :class="key === selectedKey ? 'bg-primary-50 font-medium dark:bg-primary-900' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"
                                class="cursor-pointer px-3 py-2 text-sm"
                                x-text="label"
                            ></div>
                        </template>
                    </div>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    <span x-show="selectedKey">Выбрано: <code x-text="selectedKey" class="font-mono"></code></span>
                    <span x-show="!selectedKey && count > 0" x-text="count + ' пар загружено. Введите для поиска.'"></span>
                    <span x-show="count === 0">Нажмите «Загрузить список из Ozon» для получения актуального списка.</span>
                </p>
            </div>
            @endif
            <x-filament::button wire:click="saveCategory" wire:loading.attr="disabled" wire:target="saveCategory">
                Сохранить категорию
            </x-filament::button>
        </div>
    </x-filament::section>
    <x-filament::button tag="a" :href="\App\Filament\Pages\OzonProducts::getUrl()">Перейти к товарам</x-filament::button>
</x-filament-panels::page>
