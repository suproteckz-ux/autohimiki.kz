<?php

namespace App\Services\ProductUrls;

use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductUrlMigration
{
    public function __construct(private readonly ProductSlugAllocator $allocator) {}

    public function base(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        if (! preg_match('~^https?://[^/\s?#]+$~D', $base)) {
            throw new RuntimeException('APP_URL_must_be_the_canonical_origin');
        }

        return $base;
    }

    public function snapshot(bool $lock = false): array
    {
        $products = DB::table('products')->orderBy('id');
        $redirects = DB::table('redirects')->orderBy('id');
        if ($lock) {
            $products->lockForUpdate();
            $redirects->lockForUpdate();
        }

        return [$products->get()->all(), $redirects->get()->all()];
    }

    public function plan(?array $snapshot = null): array
    {
        [$products, $redirects] = $snapshot ?? $this->snapshot();
        $reserved = $this->allocator->reserved($products, $redirects);
        $candidates = array_values(array_filter($products, fn ($p) => $p->is_active && str_starts_with($p->slug, 'onec-')));
        // Same bytewise SKU order in DB dry-run and the public manifest.
        usort($candidates, fn ($a, $b) => strcmp($a->sku, $b->sku) ?: $a->id <=> $b->id);
        $rows = [];
        foreach ($candidates as $product) {
            $row = ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
                'protected_sha256' => self::protectedHash($product),
                'old_slug' => $product->slug, 'old_url' => $this->base().'/product/'.$product->slug,
                'new_slug' => null, 'new_url' => null, 'canonical_status' => 'not_checked',
                'redirect_status' => 'not_checked', 'collision_status' => 'not_checked', 'eligible' => false];
            try {
                if (! ProductSlugAllocator::technical($product->slug)) {
                    throw new RuntimeException('invalid_onec_uuid');
                }
                $generated = $this->allocator->generate($product->name, $reserved);
                $row['new_slug'] = $generated['slug'];
                $row['new_url'] = $this->base().'/product/'.$generated['slug'];
                $row['collision_status'] = $generated['collision_status'];
                $change = $this->change($product, $generated['slug'], $products, $redirects);
                $row = array_replace($row, $change, ['eligible' => true]);
            } catch (RuntimeException $e) {
                $row['error'] = $e->getMessage();
                if (str_contains($row['error'], 'canonical')) {
                    $row['canonical_status'] = 'REVIEW_custom_canonical';
                } elseif (str_contains($row['error'], 'history') || str_contains($row['error'], 'redirect')) {
                    $row['redirect_status'] = 'CONFLICT: '.$row['error'];
                }
            }
            $rows[] = $row;
        }
        $plan = ['version' => 1, 'base_url' => $this->base(), 'scope' => 'ACTIVE_DATABASE_PRODUCTS',
            'count' => count($rows), 'blocked' => count(array_filter($rows, fn ($r) => ! $r['eligible'])), 'rows' => $rows];
        // Binds approval to all source rows, not just candidate count. No dry-run writes.
        $plan['approval'] = hash('sha256', json_encode([$plan, $products, $redirects], JSON_THROW_ON_ERROR));

        return $plan;
    }

    /** Pure transition validation, also used for explicitly edited product slugs. */
    public function change(object $product, string $slug, array $products, array $redirects): array
    {
        $old = '/product/'.$product->slug;
        $new = '/product/'.$slug;
        if ($old === $new || ! ProductSlugAllocator::valid($slug)) {
            throw new RuntimeException('invalid_or_self_redirect');
        }
        $reserved = $this->allocator->reserved($products, $redirects);
        if (isset($reserved[strtolower($slug)])) {
            throw new RuntimeException('current_or_historical_slug_reserved');
        }
        $canonical = $product->canonical_url;
        if ($canonical === null || $canonical === '') {
            $canonicalStatus = 'automatic';
        } elseif ($canonical === $this->base().$old || $canonical === $old) {
            $canonical = $this->base().$new;
            $canonicalStatus = 'old_self_canonical_updated';
        } else {
            throw new RuntimeException('custom_canonical_requires_review');
        }
        foreach ($redirects as $r) {
            if ($this->path($r->from_url) === $old) {
                throw new RuntimeException('old_url_already_has_redirect_'.$r->id);
            }
        }
        // Walk backwards: only paths proven to terminate at this exact current URL.
        $targets = [$old => true];
        $history = [];
        do {
            $added = false;
            foreach ($redirects as $r) {
                if (isset($history[$r->id]) || ! isset($targets[$this->path($r->to_url) ?? ''])) {
                    continue;
                }
                $from = $this->path($r->from_url);
                if ($from === null || $r->from_url !== $from || $from === $new) {
                    throw new RuntimeException('ambiguous_history_'.$r->id);
                }
                foreach ($products as $p) {
                    if ($from === '/product/'.$p->slug) {
                        throw new RuntimeException('history_shadows_current_product_'.$p->id);
                    }
                }
                $targets[$from] = true;
                $history[$r->id] = $r->id;
                $added = true;
            }
        } while ($added);

        return ['canonical_status' => $canonicalStatus, 'canonical_after' => $canonical,
            'redirect_status' => 'create_301; flatten_'.count($history), 'history_ids' => array_values($history)];
    }

    private function path(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['host'])) {
            $base = parse_url($this->base());
            $scheme = strtolower($parts['scheme'] ?? 'https');
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            if (strcasecmp($parts['host'], $base['host']) !== 0 || ! in_array($scheme, ['http', 'https'], true)
                || ! in_array($port, [80, 443], true)) {
                return null;
            }
        } elseif (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        // Query/fragment, percent-encoded slug and trailing slash still target
        // the same product. Resolve these incoming aliases directly as well.
        // Noncanonical source keys are rejected by change(), never silently fixed.
        $path = rawurldecode($parts['path'] ?? '');

        return str_starts_with($path, '/') ? (rtrim($path, '/') ?: '/') : null;
    }

    public function writeRedirects(string $oldSlug, string $newSlug, array $history): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('URL_changes_require_transaction');
        }
        DB::table('redirects')->insert(['from_url' => '/product/'.$oldSlug, 'to_url' => '/product/'.$newSlug,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        if ($history !== []) {
            DB::table('redirects')->whereIn('id', $history)->update(['to_url' => '/product/'.$newSlug, 'updated_at' => now()]);
        }
    }

    public function execute(string $approval, ?int $expected = null): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('run_migration_outside_an_existing_transaction');
        }
        $this->assertStorage();
        $result = DB::transaction(function () use ($approval, $expected) {
            $snapshot = $this->snapshot(true);
            $plan = $this->plan($snapshot);
            if ($plan['blocked'] || ($expected !== null && $expected !== $plan['count'])) {
                throw new RuntimeException('plan_blocked_or_candidate_count_changed; rerun_dry_run');
            }
            if (! hash_equals($plan['approval'], $approval)) {
                throw new RuntimeException('dry_run_approval_missing_or_stale; rerun_dry_run');
            }
            foreach ($plan['rows'] as $row) {
                // Intentionally bypass model observers: change ONLY URL fields, preserve timestamps/content.
                $count = DB::table('products')->where('id', $row['id'])->where('slug', $row['old_slug'])
                    ->update(['slug' => $row['new_slug'], 'canonical_url' => $row['canonical_after']]);
                if ($count !== 1) {
                    throw new RuntimeException('product_changed_during_migration');
                }
                $this->writeRedirects($row['old_slug'], $row['new_slug'], $row['history_ids']);
            }
            $plan['status'] = 'committed';
            $plan['url_fields_before'] = array_map(fn ($p) => ['id' => $p->id, 'slug' => $p->slug, 'canonical_url' => $p->canonical_url], $snapshot[0]);
            $plan['redirects_before'] = $snapshot[1];
            $plan['url_state_after_sha256'] = self::urlStateHash($this->snapshot());

            return $plan;
        });
        // A cache failure must not be mistaken for a database rollback.
        try {
            self::invalidate();
            $result['cache_status'] = 'cleared';
        } catch (\Throwable $e) {
            $result['cache_status'] = 'FAILED: rerun products:url-cache-clear before reopening traffic';
        }

        return $result;
    }

    public function assertStorage(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $tables = DB::select("SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('products', 'redirects')");
            if (count($tables) !== 2 || array_filter($tables, fn ($t) => strcasecmp($t->engine, 'InnoDB') !== 0)) {
                throw new RuntimeException('products_and_redirects_must_use_InnoDB');
            }
        } elseif ($driver !== 'sqlite') {
            throw new RuntimeException('unsupported_database_driver');
        }
        foreach (['products' => 'slug', 'redirects' => 'from_url'] as $table => $column) {
            $indexes = DB::connection()->getSchemaBuilder()->getIndexes($table);
            if (! array_filter($indexes, fn ($i) => $i['unique'] && $i['columns'] === [$column])) {
                throw new RuntimeException('missing_unique_index_'.$table.'_'.$column);
            }
        }
    }

    public static function invalidate(): void
    {
        CacheService::forgetProducts();
        CacheService::forgetRedirects();
    }

    public static function protectedHash(object $product): string
    {
        $fields = (array) $product;
        unset($fields['slug'], $fields['canonical_url']);
        ksort($fields);

        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    public static function urlStateHash(array $snapshot): string
    {
        [$products, $redirects] = $snapshot;
        $urls = array_map(fn ($p) => ['id' => $p->id, 'slug' => $p->slug, 'canonical_url' => $p->canonical_url], $products);

        return hash('sha256', json_encode([$urls, $redirects], JSON_THROW_ON_ERROR));
    }
}
