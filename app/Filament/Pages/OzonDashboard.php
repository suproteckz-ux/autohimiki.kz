<?php

namespace App\Filament\Pages;

use App\Services\Ozon\OzonAdmin;
use App\Services\Ozon\OzonAdminSettings;
use App\Services\Ozon\OzonClient;
use App\Services\Ozon\OzonConnectionResponsePreview;
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

    #[Locked]
    public array $categoryTypeOptions = [];

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
            Select::make('categoryTypeKey')
                ->label('Категория и тип Ozon')
                ->options(fn () => $this->categoryTypeOptions)
                ->searchable()
                ->getOptionLabelUsing(function (string $value): string {
                    if (isset($this->categoryTypeOptions[$value])) {
                        return $this->categoryTypeOptions[$value];
                    }
                    $parts = explode('|', $value, 2);

                    return count($parts) === 2 ? "ID {$parts[0]} / Тип {$parts[1]}" : $value;
                })
                ->helperText(fn () => $this->categoryTypeOptions === []
                    ? 'Нажмите «Загрузить список из Ozon» для получения актуального списка пар категория/тип.'
                    : count($this->categoryTypeOptions).' пар загружено.')
                ->visible(fn (Get $get) => ($get('taxonomyMode') ?? 'taxonomy') === 'taxonomy')
                ->required(fn (Get $get) => ($get('taxonomyMode') ?? 'taxonomy') === 'taxonomy'),
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
        try {
            $this->categoryTypeOptions = app(OzonClient::class)->categoryTypePairs();
            if ($this->categoryTypeOptions === []) {
                Notification::make()->title('Список категорий пуст')->warning()->send();
            } else {
                Notification::make()->title('Загружено '.count($this->categoryTypeOptions).' пар категория/тип')->success()->send();
            }
        } catch (\Throwable $e) {
            $this->categoryTypeOptions = [];
            $msg = $e instanceof \RuntimeException
                ? app(OzonConnectionResponsePreview::class)->message($e->getMessage())
                : 'Ошибка загрузки категорий';
            Notification::make()
                ->title('Не удалось загрузить категории Ozon. Можно ввести ID вручную.')
                ->body($msg)->danger()->send();
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
