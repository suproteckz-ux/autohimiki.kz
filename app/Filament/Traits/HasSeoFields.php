<?php

namespace App\Filament\Traits;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

trait HasSeoFields
{
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
                TextInput::make('h1')
                    ->label('H1 (заголовок страницы)')
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('canonical_url')
                    ->label('Canonical URL')
                    ->url()
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }
}
