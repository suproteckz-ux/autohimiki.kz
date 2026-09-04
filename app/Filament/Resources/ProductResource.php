<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\ViewColumn;
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

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Товар')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Название')->required()->maxLength(255),
                        TextInput::make('sku')->label('SKU')->required()->unique(ignoreRecord: true)->maxLength(255),
                        TextInput::make('slug')->label('Slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                        Select::make('category_id')
                            ->label('Категория')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('brand_id')
                            ->label('Бренд')
                            ->relationship('brand', 'name')
                            ->searchable()
                            ->preload(),
                        FileUpload::make('main_image')
                            ->label('Главное изображение')
                            ->disk('public')
                            ->directory('products')
                            ->image(),
                    ]),
                Section::make('Коммерческие поля')
                    ->columns(4)
                    ->schema([
                        TextInput::make('price')->label('Цена')->numeric()->minValue(0)->required(),
                        TextInput::make('quantity')->label('Остаток')->numeric()->minValue(0)->required(),
                        Toggle::make('in_stock')->label('В наличии'),
                        Toggle::make('is_active')->label('Активен'),
                        Toggle::make('is_hit')->label('Хит'),
                    ]),
                Section::make('Контент')
                    ->schema([
                        Textarea::make('short_description')->label('Краткое описание')->rows(3),
                        Textarea::make('description')->label('Описание')->rows(8),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ViewColumn::make('product_summary')
                    ->label('Товар / SKU')
                    ->view('filament.tables.columns.product-summary')
                    ->searchable(['name', 'sku'])
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('name', $direction))
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
                    ->width('13rem'),
                TextInputColumn::make('price')
                    ->label('Цена')
                    ->type('number')
                    ->rules(['numeric', 'min:0'])
                    ->disabled(fn (Product $record): bool => ! static::canEdit($record))
                    ->width('7rem'),
                TextInputColumn::make('quantity')
                    ->label('Ост.')
                    ->type('number')
                    ->rules(['integer', 'min:0'])
                    ->disabled(fn (Product $record): bool => ! static::canEdit($record))
                    ->width('5rem'),
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
            ->recordActions([
                EditAction::make()
                    ->label('Редактировать')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->iconButton(),
                Action::make('storefront')
                    ->label('Открыть на витрине')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Product $record): string => $record->url)
                    ->openUrlInNewTab()
                    ->iconButton(),
                DeleteAction::make()
                    ->label('Удалить')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Gate::allows('delete-content')),
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
        return auth()->user()?->isManager() ?? false;
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
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
