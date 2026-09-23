<?php

namespace App\Filament\Pages;

use App\Services\Ozon\OzonAdmin;
use App\Services\Ozon\OzonAdminSettings;
use App\Services\Ozon\OzonClient;
use App\Services\Ozon\OzonConnectionResponsePreview;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class OzonDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Ozon';

    protected static ?string $navigationLabel = 'Подключение и настройки';

    protected static ?string $title = 'Ozon — подключение и настройки';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.ozon-dashboard';

    public array $categoryFormState = [];

    // Always empty — pairs are dispatched to the browser, never stored in Livewire snapshot.
    #[Locked]
    public array $categoryTypeOptions = [];

    #[Locked]
    public int $categoryTypePairsCount = 0;

    #[Locked]
    public array $warehouses = [];

    #[Locked]
    public string $warehouseMessage = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        app(OzonAdmin::class)->authorize();
        $settings = app(OzonAdminSettings::class)->read();
        $catId = (string) ($settings['description_category_id'] ?? '');
        $typeId = (string) ($settings['type_id'] ?? '');

        $this->categoryForm->fill([
            'taxonomyMode' => 'taxonomy',
            'categoryTypeKey' => ($catId && $typeId) ? "{$catId}|{$typeId}" : '',
            'categoryId' => $catId,
            'typeId' => $typeId,
        ]);
    }

    protected function getForms(): array
    {
        return ['categoryForm'];
    }

    public function categoryForm(Schema $form): Schema
    {
        return $form->schema([
            Select::make('taxonomyMode')
                ->label('Режим category/type')
                ->options(['taxonomy' => 'Выбрать из Ozon', 'manual' => 'Ввести вручную'])
                ->default('taxonomy')
                ->live()
                ->required(),
            // Value is set by the Alpine picker in the blade via $wire.set().
            // Hidden keeps it in Filament form state so saveCategory() can read it.
            Hidden::make('categoryTypeKey'),
            TextInput::make('categoryId')
                ->label('description_category_id')
                ->numeric()
                ->nullable()
                ->visible(fn (Get $get) => $get('taxonomyMode') === 'manual')
                ->required(fn (Get $get) => $get('taxonomyMode') === 'manual'),
            TextInput::make('typeId')
                ->label('type_id')
                ->numeric()
                ->nullable()
                ->visible(fn (Get $get) => $get('taxonomyMode') === 'manual')
                ->required(fn (Get $get) => $get('taxonomyMode') === 'manual'),
        ])->statePath('categoryFormState');
    }

    public function loadCategoryOptions(): void
    {
        app(OzonAdmin::class)->authorize();
        // Full Ozon category tree JSON can be several MB; json_decode may exceed the default
        // web memory_limit (128 M) while CLI runs without a limit — confirmed production OOM cause.
        ini_set('memory_limit', '256M');
        try {
            $pairs = app(OzonClient::class)->categoryTypePairs();
            $count = count($pairs);
            // Pairs are dispatched to the browser as a one-time event.
            // They live only in Alpine x-data (JS memory) for the lifetime of the page —
            // never written to session, cache, DB, or disk.
            $this->dispatch('ozon-category-pairs', pairs: $pairs, count: $count);
            unset($pairs);
            $this->categoryTypePairsCount = $count;
            if ($count === 0) {
                Notification::make()->title('Список категорий пуст')->warning()->send();
            } else {
                Notification::make()->title('Загружено '.$count.' пар категория/тип')->success()->send();
            }
        } catch (\Throwable $e) {
            $this->categoryTypePairsCount = 0;
            $label = get_class($e).': '.$e->getMessage();
            $preview = app(OzonConnectionResponsePreview::class)->message($label);
            Notification::make()
                ->title('Не удалось загрузить категории Ozon. Можно ввести ID вручную.')
                ->body($preview)->danger()->send();
        }
    }

    public function saveCategory(): void
    {
        app(OzonAdmin::class)->authorize();
        $state = $this->categoryFormState;
        $mode = $state['taxonomyMode'] ?? 'taxonomy';

        if ($mode === 'taxonomy') {
            $key = (string) ($state['categoryTypeKey'] ?? '');
            $parts = explode('|', $key, 2);
            if (count($parts) !== 2 || (int) $parts[0] <= 0 || (int) $parts[1] <= 0) {
                Notification::make()->title('Выберите категорию из списка или переключитесь на ручной ввод')->warning()->send();

                return;
            }
            app(OzonAdminSettings::class)->saveCategory((int) $parts[0], (int) $parts[1]);
        } else {
            $catId = (int) ($state['categoryId'] ?? 0);
            $typeId = (int) ($state['typeId'] ?? 0);
            $this->validate([
                'categoryFormState.categoryId' => 'required|integer|min:1',
                'categoryFormState.typeId' => 'required|integer|min:1',
            ]);
            app(OzonAdminSettings::class)->saveCategory($catId, $typeId);
        }
        Notification::make()->title('Общая категория сохранена')->success()->send();
    }

    public function checkConnection(): void
    {
        app(OzonAdmin::class)->authorize();
        try {
            $data = app(OzonClient::class)->sellerInfo();
            $seller = data_get($data, 'company.name') ?? data_get($data, 'result.company.name') ?? '';
            app(OzonAdminSettings::class)->connection(true, 'Подключение успешно', is_string($seller) ? $seller : '');
        } catch (\Throwable $e) {
            $message = $e instanceof \RuntimeException ? app(OzonConnectionResponsePreview::class)->message($e->getMessage()) : 'Ошибка подключения';
            app(OzonAdminSettings::class)->connection(false, $message);
        }
    }

    public function checkWarehouses(): void
    {
        app(OzonAdmin::class)->authorize();
        $this->warehouses = [];
        try {
            $data = app(OzonClient::class)->warehouses();
            foreach ($data['warehouses'] as $item) {
                $state = is_array($item['status'] ?? null) ? ($item['status']['state'] ?? '') : ($item['status'] ?? '');
                $this->warehouses[] = app(OzonAdmin::class)->sanitize(['ID' => $item['warehouse_id'], 'Название' => $item['name'] ?? '',
                    'Активен' => empty($item['is_archived']) && ! in_array(strtoupper((string) $state), ['DISABLED', 'ARCHIVED', 'INACTIVE'], true) ? 'Да' : 'Нет']);
            }
            $this->warehouseMessage = 'Прочитана одна страница складов. Рабочий склад задаётся OZON_WAREHOUSE_ID; настройки не изменены.';
        } catch (\Throwable $e) {
            $this->warehouseMessage = $e instanceof \RuntimeException ? app(OzonConnectionResponsePreview::class)->message($e->getMessage()) : 'Ошибка проверки склада';
        }
    }

    protected function getViewData(): array
    {
        return ['settings' => app(OzonAdminSettings::class)->read(), 'diagnostics' => app(OzonClient::class)->diagnostics(),
            'warehouseId' => app(OzonConnectionResponsePreview::class)->message(config('ozon.warehouse_id') ?: 'Не настроен'),
            'writesEnabled' => (bool) config('ozon.enabled')];
    }
}
