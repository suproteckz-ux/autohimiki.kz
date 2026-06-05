<?php

namespace App\Exports;

use App\Models\Product;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Support\Collection;

/**
 * ProductsExport
 *
 * Экспортирует товары в XLSX-файл.
 *
 * ИЗМЕНЕНИЕ: заменён maatwebsite/excel на rap2hpoutre/fast-excel.
 * FastExcel не требует ext-gd, использует openspout для записи XLSX.
 *
 * Поля: SKU, название, бренд, категория, цена, остаток, наличие, URL.
 */
class ProductsExport
{
    public function __construct(
        private readonly bool $activeOnly = true
    ) {}

    /**
     * Генерирует XLSX и возвращает путь к временному файлу.
     * Вызывающий код отдаёт файл через response()->download().
     */
    public function download(string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = $this->buildRows();

        return (new FastExcel($rows))->download($filename);
    }

    /**
     * Сохраняет XLSX в указанный путь (для тестов и фоновых задач).
     */
    public function store(string $path): void
    {
        $rows = $this->buildRows();
        (new FastExcel($rows))->export($path);
    }

    /**
     * Формирует коллекцию строк для экспорта.
     */
    private function buildRows(): Collection
    {
        $query = Product::with(['brand:id,name', 'category:id,name'])
            ->orderBy('category_id')
            ->orderBy('name');

        if ($this->activeOnly) {
            $query->active();
        }

        return $query
            ->get(['id', 'sku', 'name', 'brand_id', 'category_id',
                   'price', 'old_price', 'quantity', 'in_stock', 'slug'])
            ->map(fn (Product $p) => [
                'SKU (Артикул)'   => $p->sku,
                'Название'        => $p->name,
                'Бренд'           => $p->brand?->name ?? '',
                'Категория'       => $p->category?->name ?? '',
                'Цена (тг)'       => $p->price,
                'Старая цена (тг)'=> $p->old_price ?? '',
                'Остаток'         => $p->quantity,
                'В наличии'       => $p->in_stock ? 'Да' : 'Нет',
                'URL'             => url("/product/{$p->slug}"),
            ]);
    }
}
