<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared manual/automatic core: create unpublished products, update commercial fields only. */
class PriceStockUpdater
{
    public function __construct(private readonly ImportBatch $batch) {}

    public function planRow(array $row, bool $lock = false): array
    {
        $sku = $row['sku'] ?? null;
        if (! is_string($sku) || trim($sku) === '') {
            throw new \RuntimeException('Commercial SKU must be a non-empty string.');
        }
        $sku = trim($sku);
        $query = DB::table('products')->where('sku', $sku);
        $products = ($lock ? $query->lockForUpdate() : $query)->get();
        $product = $products->first();
        $diagnostics = [];
        $price = $quantity = null;
        foreach (['price', 'quantity'] as $field) {
            try {
                $$field = CommercialValues::$field($row[$field] ?? null);
                if ($$field === null) {
                    $diagnostics[] = "Blank {$field}; quantity forced to zero and availability disabled.";
                }
            } catch (\InvalidArgumentException $e) {
                $diagnostics[] = "{$field}: ".$e->getMessage().'; quantity forced to zero and availability disabled.';
            }
        }
        $base = ['sku' => $sku, 'row_number' => (int) ($row['__row'] ?? 0),
            'invalid_price' => $price === null, 'invalid_quantity' => $quantity === null];
        if ($products->count() > 1 || ($product && $product->sku !== $sku)) {
            return $base + ['status' => 'conflict', 'product_id' => null, 'before' => null, 'after' => null,
                'diagnostics' => [...$diagnostics, "Conflicting target SKU: {$sku}"]];
        }
        $before = $product ? $this->values($product) : null;
        $validStock = $price !== null && $quantity !== null;
        $after = ['price' => $price ?? ($before['price'] ?? '0.00'),
            'quantity' => $validStock ? $quantity : 0, 'in_stock' => $validStock && $quantity > 0];
        if (! $product) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255) {
                return $base + ['status' => 'conflict', 'product_id' => null, 'before' => null, 'after' => null,
                    'diagnostics' => [...$diagnostics, "Missing or oversized name for new SKU: {$sku}"]];
            }

            return $base + ['status' => 'created_planned', 'name' => $name, 'product_id' => null,
                'before' => null, 'after' => $after, 'diagnostics' => $diagnostics] + $after;
        }

        return $base + ['status' => $before === $after ? 'unchanged' : 'updated',
            'product_id' => $product->id, 'before' => $before, 'after' => $after, 'diagnostics' => $diagnostics];
    }

    public function planMissing(array $rows, bool $lock = false): array
    {
        $present = [];
        foreach ($rows as $row) {
            $present['sku:'.$row['sku']] = true;
        }
        $query = DB::table('products')->select(['id', 'sku', 'price', 'quantity', 'in_stock'])->orderBy('id');
        $products = ($lock ? $query->lockForUpdate() : $query)->get();
        $plans = [];
        foreach ($products as $product) {
            if (isset($present['sku:'.$product->sku])) {
                continue;
            }
            $before = $this->values($product);
            $after = ['price' => $before['price'], 'quantity' => 0, 'in_stock' => false];
            if ($before !== $after) {
                $plans[] = ['sku' => $product->sku, 'product_id' => $product->id, 'row_number' => 0,
                    'status' => 'missing_from_snapshot', 'before' => $before, 'after' => $after,
                    'diagnostics' => ['Absent from explicitly confirmed full snapshot; price preserved.']];
            }
        }

        return $plans;
    }

    public function applyMissing(array $plans): void
    {
        $this->requireTransaction();
        foreach ($plans as $plan) {
            if ($this->hasReceipt($plan['sku'])) {
                continue;
            }
            // Missing rows write stock only; price remains in the audit snapshot.
            DB::table('products')->where('id', $plan['product_id'])->update([
                'quantity' => $plan['after']['quantity'], 'in_stock' => $plan['after']['in_stock'],
            ]);
            $this->receipt($plan);
        }
    }

    public function processChunk(array $rows): array
    {
        $this->requireTransaction();
        $stats = ['created' => 0, 'updated' => 0, 'not_found' => 0, 'errors' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            if ($this->hasReceipt($row['sku'])) {
                $stats['skipped']++;

                continue;
            }
            $plan = $this->planRow($row, true);
            if ($plan['status'] === 'conflict') {
                throw new \RuntimeException(implode(' ', $plan['diagnostics']));
            }
            if ($plan['status'] === 'created_planned') {
                $categoryId = DB::table('categories')->where('slug', 'bez-kategorii')->value('id');
                $categoryId ??= DB::table('categories')->insertGetId([
                    'slug' => 'bez-kategorii', 'name' => 'Без категории', 'is_active' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                do {
                    $slug = 'onec-'.Str::uuid();
                } while (DB::table('products')->where('slug', $slug)->exists());
                $plan['product_id'] = DB::table('products')->insertGetId($plan['after'] + [
                    'sku' => $plan['sku'], 'name' => $plan['name'], 'slug' => $slug,
                    'category_id' => $categoryId, 'is_active' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $plan['status'] = 'created';
                $stats['created']++;
            } elseif ($plan['status'] === 'updated') {
                // Query Builder avoids observers AND updated_at changes.
                DB::table('products')->where('id', $plan['product_id'])->update($plan['after']);
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
            foreach ($plan['diagnostics'] as $message) {
                ImportError::create(['import_batch_id' => $this->batch->id,
                    'row_number' => $plan['row_number'], 'sku' => $plan['sku'],
                    'message' => $message, 'row_data' => $row]);
            }
            $stats['errors'] += count($plan['diagnostics']) > 0 ? 1 : 0;
            $this->receipt($plan);
        }
        foreach (['created' => 'created_count', 'updated' => 'updated_count', 'not_found' => 'not_found_count', 'errors' => 'error_count', 'skipped' => 'skipped_count'] as $key => $field) {
            DB::table('import_batches')->where('id', $this->batch->id)->increment($field, $stats[$key]);
        }

        return $stats;
    }

    private function values(object $product): array
    {
        return ['price' => number_format((float) $product->price, 2, '.', ''),
            'quantity' => (int) $product->quantity, 'in_stock' => (bool) $product->in_stock];
    }

    private function hasReceipt(string $sku): bool
    {
        return DB::table('import_commercial_rows')->where('onec_file_id', $this->batch->onec_file_id)->where('sku', $sku)->exists();
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0 || ! $this->batch->onec_file_id) {
            throw new \LogicException('Commercial writes require the locked file-ledger transaction.');
        }
    }

    private function receipt(array $plan): void
    {
        DB::table('import_commercial_rows')->insert([
            'onec_file_id' => $this->batch->onec_file_id, 'import_batch_id' => $this->batch->id,
            'product_id' => $plan['product_id'], 'sku' => $plan['sku'], 'row_number' => $plan['row_number'],
            'status' => $plan['status'], 'before_values' => $plan['before'] === null ? null : json_encode($plan['before']),
            'after_values' => json_encode($plan['after']), 'diagnostics' => json_encode($plan['diagnostics']),
            'created_at' => now(),
        ]);
    }
}
