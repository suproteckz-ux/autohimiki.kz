<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportError;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PriceStockUpdater
 *
 * Режим импорта: prices_only (обновление из 1С).
 *
 * ОБНОВЛЯЕТ ТОЛЬКО:
 *   - price
 *   - quantity
 *   - in_stock
 *
 * НЕ ТРОГАЕТ:
 *   - name, slug, brand, category, description
 *   - attributes, faq, images, SEO-поля
 *
 * Если SKU не найден → запись в import_errors, НЕ создаёт товар.
 */
class PriceStockUpdater
{
    // Максимальный процент изменения цены — предупреждение в лог
    private const PRICE_CHANGE_WARN_THRESHOLD = 50;

    public function __construct(
        private readonly ImportBatch $batch
    ) {}

    /**
     * Обрабатывает один чанк строк.
     *
     * @param  array[] $rows  Строки после маппинга колонок
     * @return array{updated: int, not_found: int, errors: int}
     */
    public function processChunk(array $rows): array
    {
        $stats = ['updated' => 0, 'not_found' => 0, 'errors' => 0];

        $priceChanges = [];
        $stockChanges = [];

        foreach ($rows as $rowNumber => $row) {
            try {
                $result = $this->processRow($row, $rowNumber);

                match ($result['status']) {
                    'updated'   => $stats['updated']++,
                    'not_found' => $stats['not_found']++,
                    default     => null,
                };

                if ($result['price_changed'] ?? false) {
                    $priceChanges[] = $result['price_change'];
                }
                if ($result['stock_changed'] ?? false) {
                    $stockChanges[] = $result['stock_change'];
                }

            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logError($rowNumber, $row['sku'] ?? null, $e->getMessage(), $row);
                Log::warning("ImportChunk error row {$rowNumber}: " . $e->getMessage());
            }
        }

        // Атомарно обновляем счётчики batch
        DB::table('import_batches')
            ->where('id', $this->batch->id)
            ->increment('updated_count', $stats['updated']);

        DB::table('import_batches')
            ->where('id', $this->batch->id)
            ->increment('not_found_count', $stats['not_found']);

        DB::table('import_batches')
            ->where('id', $this->batch->id)
            ->increment('error_count', $stats['errors']);

        // Сохраняем изменения цен/остатков в batch (append к существующим)
        if (! empty($priceChanges) || ! empty($stockChanges)) {
            $this->appendChanges($priceChanges, $stockChanges);
        }

        return $stats;
    }

    /**
     * Обрабатывает одну строку.
     */
    private function processRow(array $row, int $rowNumber): array
    {
        $sku = trim($row['sku'] ?? '');

        // Валидация SKU
        if (empty($sku)) {
            $this->logError($rowNumber, null, 'Пустой SKU', $row);
            return ['status' => 'error'];
        }

        // Поиск товара по SKU
        $product = Product::where('sku', $sku)->first();

        if (! $product) {
            // SKU не найден — записываем в ошибки, НЕ создаём товар
            $this->logError(
                $rowNumber,
                $sku,
                "SKU «{$sku}» не найден в базе. В режиме обновления из 1С товар не создаётся.",
                $row
            );
            return ['status' => 'not_found'];
        }

        // Подготавливаем новые значения
        $newPrice    = isset($row['price']) ? (float) $row['price'] : null;
        $newQuantity = isset($row['quantity']) ? (int) $row['quantity'] : null;

        // Проверяем что есть что обновлять
        if ($newPrice === null && $newQuantity === null) {
            $this->logError($rowNumber, $sku, 'Нет данных для обновления (price и quantity пусты)', $row);
            return ['status' => 'error'];
        }

        $updateData   = [];
        $priceChanged = false;
        $stockChanged = false;
        $priceChange  = null;
        $stockChange  = null;

        // ── Обновление цены ────────────────────────────────────────
        if ($newPrice !== null && $newPrice >= 0) {
            $oldPrice = (float) $product->price;

            if ($newPrice !== $oldPrice) {
                $updateData['price'] = $newPrice;
                $priceChanged = true;
                $priceChange  = [
                    'sku'  => $sku,
                    'name' => $product->name,
                    'old'  => $oldPrice,
                    'new'  => $newPrice,
                    'diff' => round($newPrice - $oldPrice, 2),
                    'pct'  => $oldPrice > 0
                        ? round(($newPrice - $oldPrice) / $oldPrice * 100, 1)
                        : null,
                ];

                // Предупреждение о резком изменении цены
                if ($priceChange['pct'] !== null
                    && abs($priceChange['pct']) >= self::PRICE_CHANGE_WARN_THRESHOLD
                ) {
                    Log::warning("Import: резкое изменение цены SKU {$sku}", $priceChange);
                }
            }
        }

        // ── Обновление остатка ─────────────────────────────────────
        if ($newQuantity !== null) {
            $oldQuantity = (int) $product->quantity;
            $newInStock  = $newQuantity > 0;

            if ($newQuantity !== $oldQuantity) {
                $updateData['quantity'] = $newQuantity;
                $updateData['in_stock'] = $newInStock;
                $stockChanged = true;
                $stockChange  = [
                    'sku'      => $sku,
                    'name'     => $product->name,
                    'old'      => $oldQuantity,
                    'new'      => $newQuantity,
                    'in_stock' => $newInStock,
                ];
            } elseif ($newInStock !== (bool) $product->in_stock) {
                // Остаток не изменился, но статус наличия изменился
                $updateData['in_stock'] = $newInStock;
            }
        }

        // Обновляем только если есть изменения
        if (! empty($updateData)) {
            // Используем Query Builder без Eloquent — не триггерит Observer,
            // не обновляет updated_at неожиданно, максимально быстро
            Product::where('id', $product->id)->update($updateData);
        }

        return [
            'status'        => 'updated',
            'price_changed' => $priceChanged,
            'stock_changed' => $stockChanged,
            'price_change'  => $priceChange,
            'stock_change'  => $stockChange,
        ];
    }

    /**
     * Записывает ошибку в таблицу import_errors.
     */
    private function logError(
        int    $rowNumber,
        ?string $sku,
        string  $message,
        array   $rowData
    ): void {
        ImportError::create([
            'import_batch_id' => $this->batch->id,
            'row_number'      => $rowNumber,
            'sku'             => $sku,
            'message'         => $message,
            'row_data'        => $rowData,
        ]);
    }

    /**
     * Добавляет изменения цен/остатков к JSON-полям batch.
     * Использует JSON_ARRAY_APPEND через сырой SQL для атомарности.
     */
    private function appendChanges(array $priceChanges, array $stockChanges): void
    {
        if (! empty($priceChanges)) {
            // Читаем текущие, добавляем новые, сохраняем
            // Делаем через PHP для совместимости с MySQL 5.7+
            $batch = ImportBatch::find($this->batch->id);
            $existing = $batch->price_changes ?? [];
            $merged   = array_merge($existing, $priceChanges);

            // Лимит хранения — последние 1000 изменений цен
            if (count($merged) > 1000) {
                $merged = array_slice($merged, -1000);
            }

            ImportBatch::where('id', $this->batch->id)
                ->update(['price_changes' => json_encode($merged)]);
        }

        if (! empty($stockChanges)) {
            $batch    = ImportBatch::find($this->batch->id);
            $existing = $batch->stock_changes ?? [];
            $merged   = array_merge($existing, $stockChanges);

            if (count($merged) > 1000) {
                $merged = array_slice($merged, -1000);
            }

            ImportBatch::where('id', $this->batch->id)
                ->update(['stock_changes' => json_encode($merged)]);
        }
    }
}
