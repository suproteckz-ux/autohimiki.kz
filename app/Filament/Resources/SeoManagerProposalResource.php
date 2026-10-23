<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SeoManagerProposalResource\Pages\ListSeoManagerProposals;
use App\Models\SeoManagerProposal;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SeoManagerProposalResource extends Resource
{
    protected static ?string $model = SeoManagerProposal::class;

    protected static ?string $modelLabel = 'SEO-предложение';

    protected static ?string $pluralModelLabel = 'SEO-предложения';

    protected static ?string $navigationLabel = 'SEO-предложения';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-light-bulb';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
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
            TextInput::make('target_type')->label('Тип страницы')->disabled(),
            TextInput::make('target_id')->label('ID в каталоге')->disabled(),
            Textarea::make('proposal.reason')->label('Основание')->disabled()->columnSpanFull(),
            Textarea::make('proposal.meta_title')->label('Предлагаемый Title')->disabled()->columnSpanFull(),
            Textarea::make('proposal.meta_description')->label('Предлагаемый Description')->disabled()->columnSpanFull(),
            Textarea::make('proposal.h1')->label('Предлагаемый H1')->disabled()->columnSpanFull(),
            Textarea::make('proposal.seo_text')->label('Предлагаемый текст HTML')->disabled()->rows(15)->columnSpanFull()
                ->helperText('Это предложение. Проверенный текст переносится в существующую страницу редактором вручную.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('target_type')->label('Тип')->searchable(),
            TextColumn::make('target_id')->label('ID страницы')->sortable(),
            TextColumn::make('proposal.meta_title')->label('Предложение')->limit(70),
            TextColumn::make('created_at')->label('Создано')->dateTime()->sortable(),
        ])->recordActions([ViewAction::make()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSeoManagerProposals::route('/')];
    }
}
