<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'Товары';

    protected static ?string $navigationLabel = 'Товары';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Товар / SKU')
                    ->description(fn (Product $record): string => $record->sku)
                    ->searchable(['name', 'sku'])
                    ->sortable()
                    ->limit(80)
                    ->tooltip(fn (Product $record): string => $record->name)
                    ->grow(),
                SelectColumn::make('category_id')
                    ->label('Категория')
                    ->options(fn (): array => Category::query()->ordered()->pluck('name', 'id')->all())
                    ->searchableOptions()
                    ->rules(['required', 'exists:categories,id'])
                    ->selectablePlaceholder(false)
                    ->disabled(fn (Product $record): bool => ! static::canEdit($record))
                    ->updateStateUsing(function (Product $record, mixed $state): mixed {
                        try {
                            $record->updateOrFail(['category_id' => (int) $state]);

                            Notification::make()
                                ->success()
                                ->title('Категория товара изменена.')
                                ->send();

                            return $record->category_id;
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->danger()
                                ->title('Не удалось изменить категорию.')
                                ->send();

                            return $record->getOriginal('category_id');
                        }
                    })
                    ->width('15rem'),
                TextInputColumn::make('price')
                    ->label('Цена')
                    ->type('number')
                    ->rules(['numeric', 'min:0'])
                    ->disabled(fn (Product $record): bool => ! static::canEdit($record))
                    ->width('8rem'),
                TextInputColumn::make('quantity')
                    ->label('Ост.')
                    ->type('number')
                    ->rules(['integer', 'min:0'])
                    ->disabled(fn (Product $record): bool => ! static::canEdit($record))
                    ->width('6rem'),
                ToggleColumn::make('in_stock')
                    ->label('Нал.')
                    ->disabled(fn (Product $record): bool => ! static::canEdit($record)),
                ToggleColumn::make('is_active')
                    ->label('Акт.')
                    ->disabled(fn (Product $record): bool => ! static::canEdit($record)),
                ToggleColumn::make('is_hit')
                    ->label('Хит')
                    ->disabled(fn (Product $record): bool => ! static::canEdit($record)),
            ])
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('changeCategory')
                        ->label('Перенести в категорию')
                        ->icon('heroicon-o-folder-arrow-down')
                        ->schema([
                            Select::make('category_id')
                                ->label('Категория')
                                ->options(fn (): array => Category::query()->ordered()->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->required(),
                        ])
                        ->modalHeading('Перенести выбранные товары')
                        ->modalCancelActionLabel('Отмена')
                        ->modalSubmitActionLabel('Перенести')
                        ->action(function (Collection $records, array $data): void {
                            $count = Product::query()
                                ->whereKey($records->modelKeys())
                                ->update(['category_id' => (int) $data['category_id']]);

                            Notification::make()->success()
                                ->title("Категория изменена для {$count} товаров.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('activate')
                        ->label('Сделать активными')
                        ->icon('heroicon-o-eye')
                        ->action(fn (Collection $records) => static::setActive($records, true))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label('Сделать неактивными')
                        ->icon('heroicon-o-eye-slash')
                        ->action(fn (Collection $records) => static::setActive($records, false))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->label('Удалить')
                        ->modalHeading('Удалить выбранные товары?')
                        ->modalDescription(fn (Collection $records): string => 'Выбрано товаров: '.$records->count())
                        ->visible(fn (): bool => Gate::allows('delete-content')),
                ]),
            ]);
    }

    private static function setActive(Collection $records, bool $active): void
    {
        $count = Product::query()
            ->whereKey($records->modelKeys())
            ->update(['is_active' => $active]);

        Notification::make()->success()
            ->title(($active ? 'Активировано' : 'Деактивировано')." товаров: {$count}")
            ->send();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    public static function canDelete($record): bool
    {
        return Gate::allows('delete-content');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
        ];
    }
}
