<?php

namespace App\Filament\Pages;

use App\Exports\ProductsExport;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ExportPage
 *
 * Страница экспорта товаров в XLSX.
 */
class ExportPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationGroup = 'Импорт';
    protected static ?string $navigationLabel = 'Экспорт товаров';
    protected static ?string $title           = 'Экспорт товаров';
    protected static ?int    $navigationSort  = 3;

    protected static string $view = 'filament.pages.export-page';

    public bool $activeOnly = true;

    /**
     * Скачивает Excel-файл с товарами.
     */
    public function exportProducts(): BinaryFileResponse
    {
        $filename = 'products_' . now()->format('Y-m-d_H-i') . '.xlsx';

        Notification::make()
            ->title('Файл экспорта формируется...')
            ->success()
            ->send();

        return Excel::download(
            new ProductsExport($this->activeOnly),
            $filename
        );
    }
}
