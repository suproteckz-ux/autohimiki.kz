<?php
namespace App\Filament\Traits;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
trait HasFaqRepeater
{
    protected static function faqRepeater(): Section
    {
        return Section::make('FAQ')->icon('heroicon-o-question-mark-circle')
            ->collapsible()->collapsed()
            ->schema([
                Repeater::make('faq')->label('')
                    ->schema([
                        TextInput::make('question')->label('Вопрос')->required(),
                        Textarea::make('answer')->label('Ответ')->required()->rows(3),
                    ])
                    ->addActionLabel('Добавить вопрос')->defaultItems(0)->reorderable(),
            ]);
    }
}
