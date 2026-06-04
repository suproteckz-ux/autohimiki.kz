<?php

namespace App\Filament\Pages;

use App\Jobs\Import\ProcessImportJob;
use App\Models\ImportBatch;
use App\Models\ImportColumnTemplate;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportFileParser;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ImportWizardPage
 *
 * Мастер импорта товаров. 4 шага:
 *   1. Загрузка файла
 *   2. Предпросмотр + обнаружение колонок
 *   3. Выбор режима (prices_only | full)
 *   4. Маппинг колонок + запуск
 */
class ImportWizardPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationGroup = 'Импорт';
    protected static ?string $navigationLabel = 'Импорт товаров';
    protected static ?string $title           = 'Импорт товаров';
    protected static ?int    $navigationSort  = 1;

    protected static string $view = 'filament.pages.import-wizard';

    // ── Состояние мастера ─────────────────────────────────────────

    public int    $step        = 1;
    public ?string $filePath   = null;   // путь в storage
    public string  $fileName   = '';     // оригинальное имя файла
    public array   $previewRows = [];    // первые 20 строк
    public array   $fileColumns = [];    // заголовки колонок файла
    public int     $totalRows  = 0;      // всего строк в файле
    public string  $importMode = 'prices_only'; // 'prices_only' | 'full'
    public array   $columnMap  = [];     // маппинг: поле_системы → колонка_файла
    public ?int    $batchId    = null;   // ID созданного batch
    public array   $progress   = ['percent' => 0, 'status' => 'idle'];
    public ?int    $templateId = null;   // выбранный шаблон маппинга

    // ── Системные поля всех полей системы для маппинга ───────────

    private function getSystemFields(): array
    {
        return $this->importMode === 'full'
            ? ColumnMapper::FULL_FIELDS
            : ColumnMapper::PRICES_ONLY_FIELDS;
    }

    // ════════════════════════════════════════════════════════════
    // ШАГ 1: Загрузка файла
    // ════════════════════════════════════════════════════════════

    public function uploadFile(): void
    {
        $this->validate([
            'filePath' => 'required|string',
        ]);

        $this->step = 2;
        $this->parsePreview();
    }

    private function parsePreview(): void
    {
        try {
            $parser = new ImportFileParser();

            $allRows          = $parser->parse($this->filePath);
            $this->totalRows  = count($allRows);
            $this->fileColumns = $parser->getColumns($this->filePath);
            $this->previewRows = array_slice($allRows, 0, 20);

            // Авто-определение маппинга
            $mapper          = new ColumnMapper();
            $this->columnMap = $mapper->autoDetect($this->fileColumns, $this->importMode);

        } catch (\Throwable $e) {
            Notification::make()
                ->title('Ошибка чтения файла')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->step = 1;
        }
    }

    // ════════════════════════════════════════════════════════════
    // ШАГ 2: Предпросмотр → переход к шагу 3
    // ════════════════════════════════════════════════════════════

    public function confirmPreview(): void
    {
        if (empty($this->fileColumns)) {
            Notification::make()
                ->title('Файл пустой или не удалось прочитать колонки')
                ->danger()
                ->send();
            return;
        }

        $this->step = 3;
    }

    public function backToStep1(): void
    {
        $this->step = 1;
        // Удаляем загруженный файл
        if ($this->filePath) {
            Storage::disk('public')->delete($this->filePath);
            $this->filePath = null;
        }
    }

    // ════════════════════════════════════════════════════════════
    // ШАГ 3: Выбор режима
    // ════════════════════════════════════════════════════════════

    public function selectMode(): void
    {
        // Обновляем авто-маппинг с учётом выбранного режима
        $mapper          = new ColumnMapper();
        $this->columnMap = $mapper->autoDetect($this->fileColumns, $this->importMode);

        // Загружаем шаблон по умолчанию для этого типа
        $defaultTemplate = ImportColumnTemplate::getDefault($this->importMode);
        if ($defaultTemplate) {
            $this->columnMap  = $defaultTemplate->column_map;
            $this->templateId = $defaultTemplate->id;
        }

        $this->step = 4;
    }

    public function backToStep2(): void
    {
        $this->step = 2;
    }

    // ════════════════════════════════════════════════════════════
    // ШАГ 4: Маппинг колонок + запуск
    // ════════════════════════════════════════════════════════════

    /**
     * Загружает шаблон маппинга по ID.
     */
    public function loadTemplate(int $templateId): void
    {
        $template = ImportColumnTemplate::find($templateId);
        if ($template) {
            $this->columnMap  = $template->column_map;
            $this->templateId = $templateId;
        }
    }

    /**
     * Сохраняет текущий маппинг как шаблон.
     */
    public function saveTemplate(string $templateName): void
    {
        if (empty($templateName)) {
            return;
        }

        ImportColumnTemplate::create([
            'name'       => $templateName,
            'type'       => $this->importMode,
            'column_map' => $this->columnMap,
            'is_default' => false,
        ]);

        Notification::make()
            ->title("Шаблон «{$templateName}» сохранён")
            ->success()
            ->send();
    }

    /**
     * Устанавливает шаблон по умолчанию.
     */
    public function setDefaultTemplate(): void
    {
        // Сбрасываем предыдущий default
        ImportColumnTemplate::where('type', $this->importMode)
            ->update(['is_default' => false]);

        ImportColumnTemplate::where('id', $this->templateId)
            ->update(['is_default' => true]);

        Notification::make()
            ->title('Шаблон установлен по умолчанию')
            ->success()
            ->send();
    }

    /**
     * Валидирует маппинг и запускает импорт.
     */
    public function startImport(): void
    {
        // Обязательные поля для каждого режима
        $required = $this->importMode === 'prices_only'
            ? ['sku', 'price']
            : ['sku', 'name'];

        foreach ($required as $field) {
            if (empty($this->columnMap[$field])) {
                Notification::make()
                    ->title("Не заполнено обязательное поле маппинга: {$field}")
                    ->danger()
                    ->send();
                return;
            }
        }

        try {
            // Создаём batch-запись
            $batch = ImportBatch::create([
                'type'       => $this->importMode,
                'filename'   => $this->fileName,
                'filepath'   => $this->filePath,
                'column_map' => $this->columnMap,
                'status'     => 'pending',
                'user_id'    => Auth::id(),
            ]);

            $this->batchId = $batch->id;

            // Диспатчим в очередь
            ProcessImportJob::dispatch($batch->id)->onQueue('imports-high');

            // Переходим к экрану прогресса
            $this->step     = 5;
            $this->progress = ['percent' => 0, 'status' => 'processing'];

            Notification::make()
                ->title('Импорт запущен')
                ->body("Файл: {$this->fileName}. Строк: {$this->totalRows}")
                ->success()
                ->send();

            Log::info("Import batch #{$batch->id} dispatched", [
                'type' => $this->importMode,
                'rows' => $this->totalRows,
                'user' => Auth::id(),
            ]);

        } catch (\Throwable $e) {
            Notification::make()
                ->title('Не удалось запустить импорт')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    // ════════════════════════════════════════════════════════════
    // ШАГ 5: Прогресс (polling)
    // ════════════════════════════════════════════════════════════

    /**
     * Опрашивается из Alpine.js каждые 3 секунды.
     */
    public function pollProgress(): void
    {
        if (! $this->batchId) {
            return;
        }

        $this->progress = Cache::get(
            "import_progress_{$this->batchId}",
            ['percent' => 0, 'status' => 'processing']
        );

        // Если завершён — загружаем итоговую статистику
        if (($this->progress['status'] ?? '') === 'done') {
            $this->progress['batch'] = ImportBatch::find($this->batchId)?->toArray();
        }
    }

    public function resetWizard(): void
    {
        $this->step         = 1;
        $this->filePath     = null;
        $this->fileName     = '';
        $this->previewRows  = [];
        $this->fileColumns  = [];
        $this->totalRows    = 0;
        $this->importMode   = 'prices_only';
        $this->columnMap    = [];
        $this->batchId      = null;
        $this->progress     = ['percent' => 0, 'status' => 'idle'];
        $this->templateId   = null;
    }
}
