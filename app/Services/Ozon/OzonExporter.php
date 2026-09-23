<?php

namespace App\Services\Ozon;

use App\Models\OzonCategoryMapping;
use App\Models\OzonProductLink;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OzonExporter
{
    public function __construct(private OzonClient $client, private OzonPayload $payload) {}

    public function export(Product $product, OzonCategoryMapping $mapping): string
    {
        $this->client->assertEnabled();
        $existing = OzonProductLink::where('local_product_id', $product->id)->first();
        if ($existing) {
            if ($existing->offer_id !== $product->sku) {
                throw new \RuntimeException('sku_changed: preserve original offer_id and reconcile manually');
            }

            return 'existing';
        }
        $this->payload->validate($product);
        if (config('ozon.vat') === null || config('ozon.vat') === '') {
            throw new \RuntimeException('ozon_vat_missing: do not invent tax data');
        }
        $remoteId = $this->client->find($product->sku);
        if ($remoteId !== null) {
            OzonProductLink::firstOrCreate(['local_product_id' => $product->id], [
                'offer_id' => $product->sku, 'ozon_product_id' => $remoteId,
                'status' => 'requires_manual_review', 'desired_quantity' => $this->payload->quantity($product),
            ]);

            return 'existing';
        }
        $annotationId = $this->payload->description($product) !== '' ? $this->client->annotationId($mapping) : null;
        $item = $this->payload->create($product, $mapping, $annotationId);
        // Unique constraints + committed claim BEFORE HTTP protect concurrent runs and crashes.
        $claimed = DB::table('ozon_product_links')->insertOrIgnore([
            'local_product_id' => $product->id, 'offer_id' => $product->sku,
            'status' => 'pending', 'desired_quantity' => $this->payload->quantity($product),
            'create_attempted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $claimed) {
            return 'existing';
        }
        $link = OzonProductLink::where('local_product_id', $product->id)->firstOrFail();
        try {
            $result = $this->client->request('/v3/product/import', ['items' => [$item]]);
            $taskId = $result['result']['task_id'] ?? $result['task_id'] ?? null;
            if (! is_numeric($taskId) || (int) $taskId <= 0) {
                throw new \RuntimeException('ozon_import_task_missing: reconcile manually');
            }
            $link->update(['import_task_id' => $taskId, 'status' => 'exported', 'exported_at' => now()]);

            return 'imported';
        } catch (\Throwable $e) {
            $link->update(['status' => 'error', 'last_error' => $this->safeError($e)]);
            throw new \RuntimeException($this->safeError($e));
        }
    }

    public function refresh(OzonProductLink $link): void
    {
        if (! $link->import_task_id) {
            throw new \RuntimeException('no_import_task: reconcile offer_id in seller cabinet');
        }
        $link->update(['last_status_check_at' => now()]);
        try {
            $data = $this->client->request('/v1/product/import/info', ['task_id' => (int) $link->import_task_id]);
        } catch (\Throwable $e) {
            $link->update(['last_error' => $this->safeError($e)]);
            throw new \RuntimeException($this->safeError($e));
        }
        foreach ($data['result']['items'] ?? [] as $item) {
            if (($item['offer_id'] ?? null) !== $link->offer_id) {
                continue;
            }
            $safe = app(OzonConnectionResponsePreview::class);
            $message = $safe->message(array_intersect_key($item, array_flip(['errors', 'message', 'validation_result'])));
            $link->update(['ozon_status' => mb_substr($safe->message($item['status'] ?? 'unknown'), 0, 100),
                'ozon_status_message' => $message === '[]' ? null : $message]);
            if (! empty($item['errors'])) {
                $link->update(['status' => 'error', 'last_error' => 'ozon_import_item_errors: '.$message]);

                return;
            }
            if (($item['status'] ?? null) === 'imported' && (int) ($item['product_id'] ?? 0) > 0) {
                $link->update(['ozon_product_id' => $item['product_id'],
                    'status' => $link->publication_confirmed_at ? 'published' : 'requires_manual_review',
                    'last_content_sync_at' => now(), 'last_error' => null]);

                return;
            }
            // Processing is not success. Do not invent a product ID or a draft status.
            $status = in_array($item['status'] ?? '', ['pending', 'processing'], true) ? 'processing' : 'error';
            $link->update(['status' => $status, 'last_error' => $status === 'error' ? 'ozon_import_processing_or_rejected: inspect seller cabinet' : null]);

            return;
        }
        $link->update(['last_error' => 'ozon_import_item_missing']);
        throw new \RuntimeException('ozon_import_item_missing');
    }

    public function confirmPublished(OzonProductLink $link): void
    {
        if (! $link->ozon_product_id || ! in_array($link->status, ['requires_manual_review', 'ready', 'published'], true)) {
            throw new \RuntimeException('ozon_verified_product_required');
        }
        $link->update(['status' => 'published', 'publication_confirmed_at' => now()]);
    }

    public function stock(Product $product, OzonProductLink $link): bool
    {
        if ($link->offer_id !== $product->sku) {
            throw new \RuntimeException('sku_changed');
        }
        if ($link->status !== 'published' || ! $link->publication_confirmed_at || ! $link->ozon_product_id) {
            return false;
        }
        if ((int) config('ozon.warehouse_id') <= 0) {
            throw new \RuntimeException('ozon_warehouse_missing');
        }
        $quantity = $this->payload->quantity($product);
        $data = $this->client->request('/v2/products/stocks', ['stocks' => [[
            'offer_id' => $link->offer_id, 'product_id' => (int) $link->ozon_product_id,
            'stock' => $quantity, 'warehouse_id' => (int) config('ozon.warehouse_id'),
        ]]]);
        foreach ($data['result'] ?? [] as $item) {
            if (($item['offer_id'] ?? null) === $link->offer_id && ($item['updated'] ?? false) === true && empty($item['errors'])) {
                $link->update(['desired_quantity' => $quantity, 'last_stock_sync_at' => now(), 'last_error' => null]);

                return true;
            }
        }
        throw new \RuntimeException('ozon_stock_not_updated: inspect seller cabinet');
    }

    public function safeError(\Throwable $error): string
    {
        // Only our fixed diagnostic strings may reach the DB / terminal.
        $message = $error->getMessage();
        if (get_class($error) !== \RuntimeException::class || ! preg_match('/^(ozon_|missing_|offer_id_|sku_changed|no_import_task)/', $message)) {
            return 'ozon_operation_failed';
        }
        foreach ([config('ozon.api_key'), config('ozon.client_id')] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return mb_substr($message, 0, 500);
    }
}
