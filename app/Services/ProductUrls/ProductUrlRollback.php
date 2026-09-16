<?php

namespace App\Services\ProductUrls;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductUrlRollback
{
    public function __construct(private readonly ProductUrlMigration $migration) {}

    public function plan(array $receipt, ?array $snapshot = null): array
    {
        $snapshot ??= $this->migration->snapshot();
        if (($receipt['status'] ?? null) !== 'committed' || ($receipt['base_url'] ?? null) !== $this->migration->base()
            || ! isset($receipt['url_state_after_sha256'], $receipt['url_fields_before'], $receipt['rows'])
            || count($receipt['rows']) !== ($receipt['count'] ?? -1)
            || ! hash_equals($receipt['url_state_after_sha256'], ProductUrlMigration::urlStateHash($snapshot))) {
            throw new RuntimeException('rollback_receipt_or_URL_state_changed; review_required');
        }
        $before = array_column($receipt['url_fields_before'], null, 'id');
        $products = array_column($snapshot[0], null, 'id');
        $rows = [];
        foreach ($receipt['rows'] as $row) {
            $product = $products[$row['id']] ?? null;
            if (! $product || ! $product->is_active || $product->slug !== $row['new_slug']
                || ! ProductSlugAllocator::technical($row['old_slug']) || ! ProductSlugAllocator::valid($row['new_slug'])
                || ($before[$row['id']]['slug'] ?? null) !== $row['old_slug']) {
                throw new RuntimeException('rollback_product_changed; review_required');
            }
            $rows[] = ['id' => $row['id'], 'sku' => $product->sku, 'from_slug' => $row['new_slug'],
                'restore_slug' => $row['old_slug'], 'canonical_restore' => $before[$row['id']]['canonical_url'],
                'history_ids' => $row['history_ids']];
        }
        $plan = ['status' => 'rollback_dry_run_no_changes', 'count' => count($rows), 'rows' => $rows];
        $plan['approval'] = hash('sha256', json_encode([$receipt, $snapshot, $plan], JSON_THROW_ON_ERROR));

        return $plan;
    }

    public function execute(array $receipt, string $approval): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('rollback_requires_its_own_transaction');
        }
        $this->migration->assertStorage();
        $result = DB::transaction(function () use ($receipt, $approval) {
            $plan = $this->plan($receipt, $this->migration->snapshot(true));
            if (! hash_equals($plan['approval'], $approval)) {
                throw new RuntimeException('rollback_approval_missing_or_stale');
            }
            foreach ($plan['rows'] as $row) {
                // Retain the newly exposed address as a direct 301, never turn it into a 404.
                $deleted = DB::table('redirects')->where('from_url', '/product/'.$row['restore_slug'])
                    ->where('to_url', '/product/'.$row['from_slug'])->delete();
                if ($deleted !== 1) {
                    throw new RuntimeException('rollback_forward_redirect_changed');
                }
                DB::table('products')->where('id', $row['id'])->update([
                    'slug' => $row['restore_slug'], 'canonical_url' => $row['canonical_restore'],
                ]);
                $this->migration->writeRedirects($row['from_slug'], $row['restore_slug'], $row['history_ids']);
            }
            $plan['status'] = 'rollback_committed';

            return $plan;
        });
        try {
            ProductUrlMigration::invalidate();
            $result['cache_status'] = 'cleared';
        } catch (\Throwable) {
            $result['cache_status'] = 'FAILED: run products:url-cache-clear';
        }

        return $result;
    }
}
