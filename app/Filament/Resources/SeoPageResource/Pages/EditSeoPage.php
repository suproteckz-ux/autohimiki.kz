<?php

namespace App\Filament\Resources\SeoPageResource\Pages;

use App\Filament\Resources\SeoPageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditSeoPage extends EditRecord
{
    protected static string $resource = SeoPageResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
