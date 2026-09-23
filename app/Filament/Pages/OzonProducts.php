<?php

namespace App\Filament\Pages;

use App\Models\OzonProductLink;
use App\Models\Product;
use App\Services\Ozon\OzonAdmin;
use App\Services\Ozon\OzonConnectionResponsePreview;
use App\Services\Ozon\OzonExporter;
use App\Services\Ozon\OzonPayload;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

class OzonProducts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Ozon';

    protected static ?string $navigationLabel = 'Товары';

    protected static ?string $title = 'Ozon — товары сайта';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.ozon-products';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Locked]
    public array $reports = [];

    // Tracks which action produced the current $reports ('check' vs others).
    // Used by the blade to choose placement (above vs below the table) and display style.
    #[Locked]
    public string $lastAction = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        $safe = fn ($state) => app(OzonConnectionResponsePreview::class)->message($state);

        return $table->query(Product::query()->with(['category', 'images', 'ozonLink']))
            ->defaultSort('id', 'desc')->paginated([10, 25])->defaultPaginationPageOption(10)->selectCurrentPageOnly()->maxSelectableRecords(10)
            ->columns([
                // Always visible — designed for 1366–1920 px at 100% zoom (full-width page).
                ImageColumn::make('local_photo')->label('Фото')->size(48)
                    ->getStateUsing(fn (Product $record) => app(OzonPayload::class)->images($record)[0] ?? null),
                TextColumn::make('sku')->label('SKU')->searchable()
                    ->description(fn (Product $record) => $safe($record->name))
                    ->formatStateUsing($safe)->wrap()->grow(false),
                TextColumn::make('price')->label('Цена')->suffix(' ₸')->grow(false)
                    ->description(fn (Product $record) => 'Ост: '.$record->quantity),
                TextColumn::make('content_summary')->label('Контент')
                    ->html()->grow(false)
                    ->getStateUsing(fn (Product $record) => implode('<br>', [
                        'Фото: '.($record->main_image
                            ? '<span class="text-green-700 dark:text-green-400">✓</span>'
                            : '<span class="text-red-600 dark:text-red-400">✗</span>')
                        .' <small>+'.$record->images->count().'</small>',
                        'Опис: '.(trim((string) $record->description) !== ''
                            ? '<span class="text-green-700 dark:text-green-400">✓</span>'
                            : '<span class="text-amber-600 dark:text-amber-400">—</span>'),
                        'Атр: '.count($record->getAttribute('attributes') ?? []),
                    ])),
                TextColumn::make('ozonLink.status')->label('Ozon')->badge()
                    ->placeholder('—')
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success',
                        'exported', 'requires_manual_review', 'ready' => 'warning',
                        'error' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => OzonProductLink::statusLabels()[$state] ?? $safe($state))
                    ->description(fn (Product $record) => $record->ozonLink?->last_error
                        ? $safe(mb_substr((string) $record->ozonLink->last_error, 0, 60))
                        : null),
                // Hidden — togglable via column picker.
                TextColumn::make('name')->label('Название (полное)')->searchable()->wrap()->limit(80)
                    ->formatStateUsing($safe)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category.name')->label('Категория (local)')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ozonLink.ozon_status')->label('Import status')->formatStateUsing($safe)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ozonLink.ozon_product_id')->label('Product ID')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ozonLink.import_task_id')->label('Task ID')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ozonLink.last_status_check_at')->label('Проверено')->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ozonLink.exported_at')->label('Отправлено')->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ozonLink.last_stock_sync_at')->label('Остаток sync')->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('ozon_status')->label('Ozon status')->options(['unlinked' => 'Не отправлен', ...OzonProductLink::statusLabels()])
                    ->query(function (Builder $query, array $data) {
                        if (($data['value'] ?? '') === 'unlinked') {
                            $query->whereDoesntHave('ozonLink');
                        } elseif ($data['value'] ?? '') {
                            $query->whereHas('ozonLink', fn (Builder $q) => $q->where('status', $data['value']));
                        }
                    }),
                SelectFilter::make('photo')->label('Фото')->options(['yes' => 'С фото', 'no' => 'Без фото'])
                    ->query(function (Builder $query, array $data) {
                        if (! ($data['value'] ?? '')) {
                            return;
                        }
                        $has = fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNotNull('main_image')->where('main_image', '!=', '')->where('main_image', '!=', '0'))->orWhereHas('images');
                        $data['value'] === 'yes' ? $query->where($has) : $query->whereNot($has);
                    }),
                SelectFilter::make('description')->label('Описание')->options(['yes' => 'С описанием', 'no' => 'Без описания'])
                    ->query(function (Builder $query, array $data) {
                        if (($data['value'] ?? '') === 'yes') {
                            $query->whereNotNull('description')->where('description', '!=', '');
                        } elseif (($data['value'] ?? '') === 'no') {
                            $query->where(fn (Builder $q) => $q->whereNull('description')->orWhere('description', ''));
                        }
                    }),
                SelectFilter::make('stock')->label('Наличие')->options(['yes' => 'В наличии', 'no' => 'Нет в наличии'])
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] ?? '') {
                            $query->where('quantity', $data['value'] === 'yes' ? '>' : '<=', 0);
                        }
                    }),
            ])
            ->recordActions([
                $this->readAction('check', 'Проверить', fn (Product $record) => app(OzonAdmin::class)->check($record)),
                ActionGroup::make([
                    $this->writeAction('send', 'Отправить в Ozon', fn (Product $record) => app(OzonAdmin::class)->send($record))
                        ->modalHeading(fn (Product $record) => 'Отправить товар SKU '.$safe($record->sku).' в Ozon?')
                        ->modalDescription('Будет создана одна задача импорта. Цена отправляется только при первичном создании. Публикация и остатки выполняются отдельно.')
                        ->visible(fn (Product $record) => app(OzonAdmin::class)->canSend($record)),
                    $this->readAction('status', 'Проверить статус', fn (Product $record) => app(OzonAdmin::class)->status($record, true))
                        ->visible(fn (Product $record) => (bool) $record->ozonLink?->import_task_id),
                    $this->readAction('details', 'Результат Ozon', fn (Product $record) => app(OzonAdmin::class)->status($record))
                        ->visible(fn (Product $record) => (bool) $record->ozonLink),
                    $this->readAction('images', 'Проверить фото', fn (Product $record) => app(OzonAdmin::class)->images($record)),
                    $this->writeAction('confirm', 'Подтвердить публикацию', fn (Product $record) => app(OzonAdmin::class)->confirm($record))
                        ->modalDescription('Подтверждаю, что этот товар опубликован в кабинете Ozon. Последующее обновление остатка сделает товар доступным для продажи.')
                        ->visible(fn (Product $record) => $record->ozonLink?->ozon_product_id && in_array($record->ozonLink?->status, ['requires_manual_review', 'ready'], true)),
                    $this->writeAction('stock', 'Обновить остаток', fn (Product $record) => app(OzonAdmin::class)->stock($record))
                        ->visible(fn (Product $record) => config('ozon.enabled') && $record->ozonLink?->status === 'published' && $record->ozonLink?->publication_confirmed_at),
                ])->label('Действия')->button(),
            ])
            ->toolbarActions([BulkActionGroup::make([
                $this->bulkAction('check', 'Проверить выбранные'),
                $this->bulkAction('status', 'Проверить статусы выбранных'),
                $this->bulkAction('stock', 'Обновить остатки выбранных опубликованных')->visible((bool) config('ozon.enabled')),
            ])]);
    }

    private function readAction(string $name, string $label, \Closure $run): Action
    {
        return Action::make($name)->label($label)
            ->action(function (Product $record) use ($name, $run) {
                $this->lastAction = $name;
                $this->run($run, $record);
            });
    }

    private function writeAction(string $name, string $label, \Closure $run): Action
    {
        return Action::make($name)->label($label)->requiresConfirmation()->action(function (Product $record) use ($name, $run) {
            $this->lastAction = $name;
            $this->run($run, $record);
        });
    }

    private function run(\Closure $run, Product $record): void
    {
        app(OzonAdmin::class)->authorize();
        try {
            $this->reports = [$run($record)];
        } catch (\Throwable $e) {
            $this->reports = [['Ошибка' => app(OzonExporter::class)->safeError($e)]];
            Notification::make()->title('Действие не выполнено')->body($this->reports[0]['Ошибка'])->danger()->send();
        }
    }

    private function bulkAction(string $name, string $label): BulkAction
    {
        return BulkAction::make('selected_'.$name)->label($label)->requiresConfirmation()
            ->modalDescription('Не более 10 выбранных товаров за один запуск. Для остатков допускаются только подтверждённые опубликованные карточки; остальные будут пропущены с пояснением.')
            ->action(function (Collection $records) use ($name) {
                app(OzonAdmin::class)->authorize();
                if ($records->count() > OzonAdmin::MAX_SELECTED) {
                    Notification::make()->title('Выберите не более 10 товаров')->warning()->send();

                    return;
                }
                $this->reports = app(OzonAdmin::class)->selected($records->modelKeys(), $name);
            })->deselectRecordsAfterCompletion();
    }
}
