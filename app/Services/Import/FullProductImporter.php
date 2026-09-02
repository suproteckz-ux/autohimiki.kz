<?php

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportError;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FullProductImporter
 *
 * Режим импорта: full (полный импорт).
 *
 * Если SKU существует — обновляет все переданные поля товара.
 * Если SKU новый — создаёт товар.
 *
 * НИКОГДА не меняет автоматически:
 *   - slug (только при создании нового товара)
 *   - SEO-поля (meta_title, meta_description, h1, seo_text) если уже заполнены
 */
class FullProductImporter
{
    public function __construct(
        private readonly ImportBatch $batch
    ) {}

    /**
     * Обрабатывает один чанк строк.
     *
     * @param  array[] $rows        Строки после маппинга
     * @param  array   $imageQueue  Список {product_id => image_url} для отложенной загрузки
     * @return array{created: int, updated: int, skipped: int, errors: int, image_queue: array}
     */
    public function processChunk(array $rows): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'image_queue' => []];

        foreach ($rows as $rowNumber => $row) {
            try {
                $result = $this->processRow($row, $rowNumber);

                match ($result['status']) {
                    'created' => $stats['created']++,
                    'updated' => $stats['updated']++,
                    'skipped' => $stats['skipped']++,
                    default   => null,
                };

                // Добавляем в очередь загрузки изображений
                if (! empty($result['image_queue_item'])) {
                    $stats['image_queue'][] = $result['image_queue_item'];
                }

            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logError($rowNumber, $row['sku'] ?? null, $e->getMessage(), $row);
                Log::error("FullImport error row {$rowNumber}: " . $e->getMessage(), [
                    'row'   => $row,
                    'batch' => $this->batch->id,
                ]);
            }
        }

        // Обновляем счётчики batch атомарно
        DB::table('import_batches')
            ->where('id', $this->batch->id)
            ->update([
                'created_count' => DB::raw("created_count + {$stats['created']}"),
                'updated_count' => DB::raw("updated_count + {$stats['updated']}"),
                'skipped_count' => DB::raw("skipped_count + {$stats['skipped']}"),
                'error_count'   => DB::raw("error_count + {$stats['errors']}"),
            ]);

        return $stats;
    }

    /**
     * Обрабатывает одну строку.
     */
    private function processRow(array $row, int $rowNumber): array
    {
        $sku = trim($row['sku'] ?? '');

        if (empty($sku)) {
            $this->logError($rowNumber, null, 'Пустой SKU — строка пропущена', $row);
            return ['status' => 'skipped'];
        }

        // Ищем существующий товар
        $product    = Product::where('sku', $sku)->first();
        $isNew      = $product === null;
        $imageQueue = null;

        if ($isNew) {
            // ── Создание нового товара ─────────────────────────────

            // Название обязательно для нового товара
            $name = trim($row['name'] ?? '');
            if (empty($name)) {
                $this->logError($rowNumber, $sku, 'Нет названия для нового товара', $row);
                return ['status' => 'skipped'];
            }

            // Разрешаем категорию (создаём если нет)
            $categoryId = $this->resolveCategory($row['category'] ?? null);
            if (! $categoryId) {
                $this->logError($rowNumber, $sku, 'Не удалось определить категорию', $row);
                return ['status' => 'skipped'];
            }

            $product = Product::create([
                'name'              => $name,
                'slug'              => $this->generateUniqueSlug($name),
                'sku'               => $sku,
                'category_id'       => $categoryId,
                'brand_id'          => $this->resolveBrand($row['brand'] ?? null),
                // Only 1C establishes commercial values.
                'price'             => 0,
                'old_price'         => isset($row['old_price']) ? (float) $row['old_price'] : null,
                'quantity'          => 0,
                'in_stock'          => false,
                'short_description' => $row['short_description'] ?? null,
                'description'       => $row['description'] ?? null,
                'meta_title'        => $row['meta_title'] ?? null,
                'meta_description'  => $row['meta_description'] ?? null,
                'is_active'         => true,
            ]);

            $status = 'created';

        } else {
            // ── Обновление существующего товара ───────────────────

            $updateData = [];

            // Название (обновляем только если передано)
            if (! empty($row['name'])) {
                $updateData['name'] = trim($row['name']);
                // slug НЕ меняем — Observer создаст редирект если понадобится
            }

            // Цена и остаток
            if (isset($row['old_price'])) {
                $updateData['old_price'] = (float) $row['old_price'];
            }

            // Описание (обновляем только если передано)
            if (! empty($row['short_description'])) {
                $updateData['short_description'] = $row['short_description'];
            }
            if (! empty($row['description'])) {
                $updateData['description'] = $row['description'];
            }

            // SEO-поля: обновляем только если поле в БД ещё пустое
            // (не перезатираем ручные правки)
            foreach (['meta_title', 'meta_description'] as $seoField) {
                if (! empty($row[$seoField]) && empty($product->$seoField)) {
                    $updateData[$seoField] = $row[$seoField];
                }
            }

            // Категория и бренд (только если переданы)
            if (! empty($row['category'])) {
                $catId = $this->resolveCategory($row['category']);
                if ($catId) {
                    $updateData['category_id'] = $catId;
                }
            }
            if (! empty($row['brand'])) {
                $brandId = $this->resolveBrand($row['brand']);
                if ($brandId) {
                    $updateData['brand_id'] = $brandId;
                }
            }

            if (! empty($updateData)) {
                Product::where('id', $product->id)->update($updateData);
            }

            $status = 'updated';
        }

        // Изображение — отправляем в очередь если есть URL
        if (! empty($row['image_url'])) {
            $imageQueue = [
                'product_id' => $product->id,
                'image_url'  => $row['image_url'],
                'batch_id'   => $this->batch->id,
            ];
        }

        return [
            'status'           => $status,
            'image_queue_item' => $imageQueue,
        ];
    }

    /**
     * Находит или создаёт категорию по имени.
     */
    private function resolveCategory(?string $name): ?int
    {
        if (empty($name)) {
            // Используем категорию "Без категории" как fallback
            $cat = Category::firstOrCreate(
                ['slug' => 'bez-kategorii'],
                ['name' => 'Без категории', 'is_active' => false]
            );
            return $cat->id;
        }

        $name = trim($name);
        $cat  = Category::where('name', $name)->first();

        if (! $cat) {
            $cat = Category::create([
                'name'      => $name,
                'slug'      => $this->generateUniqueCategorySlug($name),
                'is_active' => true,
            ]);
            Log::info("Import: создана новая категория «{$name}»");
        }

        return $cat->id;
    }

    /**
     * Находит или создаёт бренд по имени.
     */
    private function resolveBrand(?string $name): ?int
    {
        if (empty($name)) {
            return null;
        }

        $name  = trim($name);
        $brand = Brand::where('name', $name)->first();

        if (! $brand) {
            $brand = Brand::create([
                'name'      => $name,
                'slug'      => Str::slug($name),
                'is_active' => true,
            ]);
            Log::info("Import: создан новый бренд «{$name}»");
        }

        return $brand->id;
    }

    /**
     * Генерирует уникальный slug для товара.
     */
    private function generateUniqueSlug(string $name): string
    {
        $base  = Str::lower(Str::slug($name));
        $slug  = $base;
        $count = 1;

        while (Product::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$count}";
            $count++;
        }

        return $slug;
    }

    /**
     * Генерирует уникальный slug для категории.
     */
    private function generateUniqueCategorySlug(string $name): string
    {
        $base  = Str::lower(Str::slug($name));
        $slug  = $base;
        $count = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$count}";
            $count++;
        }

        return $slug;
    }

    /**
     * Записывает ошибку.
     */
    private function logError(int $rowNumber, ?string $sku, string $message, array $row): void
    {
        ImportError::create([
            'import_batch_id' => $this->batch->id,
            'row_number'      => $rowNumber,
            'sku'             => $sku,
            'message'         => $message,
            'row_data'        => $row,
        ]);
    }
}
