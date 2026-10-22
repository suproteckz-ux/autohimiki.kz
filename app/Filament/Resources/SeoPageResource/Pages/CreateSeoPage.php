<?php

namespace App\Filament\Resources\SeoPageResource\Pages;

use App\Filament\Resources\SeoPageResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateSeoPage extends CreateRecord
{
    protected static string $resource = SeoPageResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;
}
