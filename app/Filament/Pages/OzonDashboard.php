<?php

namespace App\Filament\Pages;

use App\Services\Ozon\OzonAdmin;
use App\Services\Ozon\OzonAdminSettings;
use App\Services\Ozon\OzonClient;
use App\Services\Ozon\OzonConnectionResponsePreview;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class OzonDashboard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';
    protected static string|\UnitEnum|null $navigationGroup = 'Ozon';
    protected static ?string $navigationLabel = 'Подключение и настройки';
    protected static ?string $title = 'Ozon — подключение и настройки';
    protected static ?int $navigationSort = 1;
    protected string $view = 'filament.pages.ozon-dashboard';

    public string $categoryId = '';
    public string $typeId = '';
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
        $this->categoryId = (string) ($settings['description_category_id'] ?? '');
        $this->typeId = (string) ($settings['type_id'] ?? '');
    }

    public function saveCategory(): void
    {
        app(OzonAdmin::class)->authorize();
        $this->validate(['categoryId' => 'required|integer|min:1|max:9223372036854775807', 'typeId' => 'required|integer|min:1|max:9223372036854775807']);
        app(OzonAdminSettings::class)->saveCategory((int) $this->categoryId, (int) $this->typeId);
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
