<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ProductsExport
 *
 * Экспортирует все активные товары в XLSX.
 * Поля: SKU, название, бренд, категория, цена, остаток, наличие, URL.
 */
class ProductsExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithTitle
{
    public function __construct(
        private readonly bool $activeOnly = true
    ) {}

    public function title(): string
    {
        return 'Товары';
    }

    public function collection(): Collection
    {
        $query = Product::with(['brand:id,name', 'category:id,name'])
            ->orderBy('category_id')
            ->orderBy('name');

        if ($this->activeOnly) {
            $query->active();
        }

        return $query->get([
            'id', 'sku', 'name', 'brand_id', 'category_id',
            'price', 'old_price', 'quantity', 'in_stock', 'slug',
        ]);
    }

    public function headings(): array
    {
        return [
            'SKU (Артикул)',
            'Название',
            'Бренд',
            'Категория',
            'Цена (тг)',
            'Старая цена (тг)',
            'Остаток',
            'В наличии',
            'URL',
        ];
    }

    /**
     * @param  Product $product
     */
    public function map($product): array
    {
        return [
            $product->sku,
            $product->name,
            $product->brand?->name ?? '',
            $product->category?->name ?? '',
            $product->price,
            $product->old_price ?? '',
            $product->quantity,
            $product->in_stock ? 'Да' : 'Нет',
            url("/product/{$product->slug}"),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // Жирный заголовок
            1 => [
                'font' => ['bold' => true, 'size' => 11],
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFF59E0B'], // primary-500
                ],
            ],
        ];
    }
}
