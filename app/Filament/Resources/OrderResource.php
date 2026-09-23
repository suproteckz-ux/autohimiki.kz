<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $modelLabel = 'заказ';

    protected static ?string $pluralModelLabel = 'Заказы';

    protected static ?string $navigationLabel = 'Заказы';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('№ заказа'),
            TextEntry::make('created_at')->label('Дата')->dateTime('d.m.Y H:i'),
            TextEntry::make('name')->label('Клиент'),
            TextEntry::make('phone')->label('Телефон'),
            TextEntry::make('city')->label('Город'),
            TextEntry::make('address')->label('Адрес')->placeholder('Самовывоз'),
            TextEntry::make('delivery_method')->label('Способ получения')->formatStateUsing(fn ($state) => Order::DELIVERY[$state] ?? $state),
            TextEntry::make('comment')->label('Комментарий')->placeholder('—'),
            TextEntry::make('consented_at')->label('Согласие на обработку данных')->dateTime('d.m.Y H:i'),
            RepeatableEntry::make('items')->label('Товары')->columnSpanFull()->schema([
                TextEntry::make('sku')->label('SKU'),
                TextEntry::make('name')->label('Название'),
                TextEntry::make('quantity')->label('Количество'),
                TextEntry::make('price')->label('Цена')->money('KZT'),
                TextEntry::make('subtotal')->label('Сумма')->money('KZT'),
            ])->columns(5),
            TextEntry::make('total')->label('Итого без доставки')->money('KZT'),
            Select::make('status')->label('Статус')->options(Order::STATUSES)->required()->in(array_keys(Order::STATUSES)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('id')->label('№')->sortable()->searchable(),
            TextColumn::make('created_at')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('name')->label('Клиент')->searchable(),
            TextColumn::make('phone')->label('Телефон')->searchable(),
            TextColumn::make('city')->label('Город')->searchable(),
            TextColumn::make('delivery_method')->label('Получение')->formatStateUsing(fn ($state) => Order::DELIVERY[$state] ?? $state),
            TextColumn::make('total')->label('Сумма')->money('KZT')->sortable(),
            TextColumn::make('status')->label('Статус')->formatStateUsing(fn ($state) => Order::STATUSES[$state] ?? $state)->badge(),
        ])->filters([SelectFilter::make('status')->label('Статус')->options(Order::STATUSES)])
            ->recordActions([EditAction::make()->label('Открыть')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'edit' => EditOrder::route('/{record}/edit')];
    }
}
