<?php

namespace App\Filament\Pages;

use App\Exports\ProductsExport;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * ExportPage — страница экспорта товаров в Excel.
 * Использует ProductsExport → FastExcel → XLSX без ext-gd.
 */
class ExportPage extends Page
{
    protected static string | \BackedEnum | null $navigationIcon  = 'heroicon-o-arrow-down-tray';
    protected static string | \UnitEnum | null $navigationGroup = 'Импорт';
    protected static ?string $navigationLabel = 'Экспорт товаров';
    protected static ?string $title           = 'Экспорт товаров';
    protected static ?int    $navigationSort  = 3;

    protected string $view = 'filament.pages.export-page';

    public bool $activeOnly = true;

    public function exportProducts(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = 'products_' . now()->format('Y-m-d_H-i') . '.xlsx';

        Notification::make()
            ->title('Файл формируется...')
            ->success()
            ->send();

        return (new ProductsExport($this->activeOnly))->download($filename);
    }
}
