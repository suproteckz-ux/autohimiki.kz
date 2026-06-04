<?php
namespace App\Filament\Traits;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
trait HasSeoFields
{
    protected static function seoSection(): Section
    {
        return Section::make('SEO')
            ->icon('heroicon-o-magnifying-glass')
            ->collapsible()->collapsed()->columns(2)
            ->schema([
                TextInput::make('meta_title')->label('Meta Title')->maxLength(70)->columnSpanFull(),
                Textarea::make('meta_description')->label('Meta Description')->rows(2)->maxLength(200)->columnSpanFull(),
                TextInput::make('h1')->label('H1')->maxLength(255)->columnSpanFull(),
                TextInput::make('canonical_url')->label('Canonical URL')->url()->maxLength(500)->columnSpanFull(),
            ]);
    }
}
