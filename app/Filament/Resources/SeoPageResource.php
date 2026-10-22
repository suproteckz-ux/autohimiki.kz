<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SeoPageResource\Pages\CreateSeoPage;
use App\Filament\Resources\SeoPageResource\Pages\EditSeoPage;
use App\Filament\Resources\SeoPageResource\Pages\ListSeoPages;
use App\Filament\Traits\HasFaqRepeater;
use App\Filament\Traits\HasSeoFields;
use App\Models\SeoPage;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SeoPageResource extends Resource
{
    use HasFaqRepeater;
    use HasSeoFields;

    protected static ?string $model = SeoPage::class;

    protected static ?string $modelLabel = 'SEO-страница';

    protected static ?string $pluralModelLabel = 'SEO-страницы';

    protected static ?string $navigationLabel = 'SEO-страницы';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основное')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Заголовок страницы (title-tag)')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('slug')
                            ->label('URL (slug)')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Часть URL после домена: autohimiki.kz/slug'),
                        Toggle::make('is_active')
                            ->label('Активна')
                            ->default(true),
                    ]),

                Section::make('Контент')
                    ->schema([
                        TextInput::make('h1')
                            ->label('H1 (заголовок страницы)')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('intro')
                            ->label('Вводный абзац (под H1)')
                            ->rows(3)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        RichEditor::make('seo_text')
                            ->label('Текст статьи (HTML)')
                            ->toolbarButtons([
                                'bold', 'italic', 'link',
                                'bulletList', 'orderedList',
                                'h2', 'h3',
                                'blockquote', 'codeBlock',
                                'table',
                            ])
                            ->columnSpanFull(),
                    ]),

                Section::make('Связанные товары')
                    ->schema([
                        Select::make('products')
                            ->label('Товары (отображаются в блоке «Рекомендуемые»)')
                            ->multiple()
                            ->relationship('products', 'name')
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),
                    ]),

                static::faqRepeater(),

                static::seoSection(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->grow(),
                TextColumn::make('slug')
                    ->label('URL')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),
                TextColumn::make('products_count')
                    ->label('Товары')
                    ->counts('products')
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Активна'),
                TextColumn::make('updated_at')
                    ->label('Обновлена')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('view')
                    ->label('На сайте')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (SeoPage $record): string => url('/' . $record->slug))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeoPages::route('/'),
            'create' => CreateSeoPage::route('/create'),
            'edit' => EditSeoPage::route('/{record}/edit'),
        ];
    }

    // h1 lives in the Контент section above; exclude it here to avoid a duplicate field.
    protected static function seoSection(): Section
    {
        return Section::make('SEO-настройки')
            ->icon('heroicon-o-magnifying-glass')
            ->collapsible()->collapsed()->columns(2)
            ->schema([
                TextInput::make('meta_title')
                    ->label('Meta Title')
                    ->live(debounce: 300)
                    ->hint(fn ($state) => strlen($state ?? '') . ' / 60')
                    ->hintColor(fn ($state) => strlen($state ?? '') > 60 ? 'danger' : 'gray')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('meta_description')
                    ->label('Meta Description')
                    ->live(debounce: 300)
                    ->hint(fn ($state) => strlen($state ?? '') . ' / 160')
                    ->hintColor(fn ($state) => strlen($state ?? '') > 160 ? 'danger' : 'gray')
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),
                TextInput::make('canonical_url')
                    ->label('Canonical URL')
                    ->url()
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }
}
