<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportError;
use Illuminate\Support\Facades\DB;

/** Shared manual/automatic prices_only core. Never creates or edits product content. */
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
        if ($products->count() > 1) {
            throw new \RuntimeException("Conflicting target SKU: {$sku}");
        }
        $product = $products->first();
        $diagnostics = [];
        $price = $quantity = null;
        foreach (['price', 'quantity'] as $field) {
            try {
                $$field = CommercialValues::$field($row[$field] ?? null);
                if ($$field === null) {
                    $diagnostics[] = "Blank {$field}; existing value preserved.";
                }
            } catch (\InvalidArgumentException $e) {
                $diagnostics[] = "{$field}: ".$e->getMessage();
            }
        }
        $base = ['sku' => $sku, 'row_number' => (int) ($row['__row'] ?? 0), 'diagnostics' => $diagnostics];
        // Do not let database collation silently match a different SKU.
        if (! $product || $product->sku !== $sku) {
            return $base + ['status' => 'not_found', 'product_id' => null, 'before' => null, 'after' => null];
        }
        $before = ['price' => number_format((float) $product->price, 2, '.', ''),
            'quantity' => (int) $product->quantity, 'in_stock' => (bool) $product->in_stock];
        $after = $before;
        if ($price !== null) {
            $after['price'] = $price;
        }
        if ($quantity !== null) {
            $after['quantity'] = $quantity;
            $after['in_stock'] = $quantity > 0;
        }

        return $base + ['status' => $before === $after ? 'unchanged' : 'updated',
            'product_id' => $product->id, 'before' => $before, 'after' => $after];
    }

    public function processChunk(array $rows): array
    {
        if (DB::transactionLevel() === 0 || ! $this->batch->onec_file_id) {
            throw new \LogicException('Commercial writes require the locked file-ledger transaction.');
        }
        $stats = ['updated' => 0, 'not_found' => 0, 'errors' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            if (DB::table('import_commercial_rows')->where('onec_file_id', $this->batch->onec_file_id)
                ->where('sku', $row['sku'])->exists()) {
                $stats['skipped']++;

                continue;
            }
            $plan = $this->planRow($row, true);
            if ($plan['status'] === 'updated') {
                // Query Builder avoids observers AND updated_at changes.
                DB::table('products')->where('id', $plan['product_id'])->update($plan['after']);
                $stats['updated']++;
            } elseif ($plan['status'] === 'not_found') {
                $stats['not_found']++;
                $plan['diagnostics'][] = 'Unknown exact SKU; product not created.';
            } else {
                $stats['skipped']++;
            }
            foreach ($plan['diagnostics'] as $message) {
                ImportError::create(['import_batch_id' => $this->batch->id,
                    'row_number' => $plan['row_number'], 'sku' => $plan['sku'],
                    'message' => $message, 'row_data' => $row]);
            }
            $stats['errors'] += count($plan['diagnostics']) > 0 ? 1 : 0;
            DB::table('import_commercial_rows')->insert([
                'onec_file_id' => $this->batch->onec_file_id, 'import_batch_id' => $this->batch->id,
                'product_id' => $plan['product_id'], 'sku' => $plan['sku'], 'row_number' => $plan['row_number'],
                'status' => $plan['status'], 'before_values' => json_encode($plan['before']),
                'after_values' => json_encode($plan['after']), 'diagnostics' => json_encode($plan['diagnostics']),
                'created_at' => now(),
            ]);
        }
        foreach (['updated' => 'updated_count', 'not_found' => 'not_found_count', 'errors' => 'error_count', 'skipped' => 'skipped_count'] as $key => $field) {
            DB::table('import_batches')->where('id', $this->batch->id)->increment($field, $stats[$key]);
        }

        return $stats;
    }
}
