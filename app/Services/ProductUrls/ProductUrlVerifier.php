<?php

namespace App\Services\ProductUrls;

use App\Http\Controllers\SitemapController;
use App\Models\Product;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ProductUrlVerifier
{
    /** SELECTs and uncached application rendering only; never dispatch the HTTP kernel. */
    public function verifyCurrentState(): array
    {
        $base = app(ProductUrlMigration::class)->base();
        $manifest = json_decode(file_get_contents(base_path('docs/URL02_PUBLIC_MANIFEST.json')), true, 512, JSON_THROW_ON_ERROR);
        $expected = $manifest['rows'] ?? [];
        if (! $expected || count($expected) !== ($manifest['onec_candidates'] ?? null)
            || ($manifest['sitemap'] ?? null) !== $base.'/sitemap-products.xml') {
            throw new RuntimeException('invalid_manifest_or_origin');
        }
        $baseline = [];
        $skus = [];
        foreach ($expected as $row) {
            if (! ProductSlugAllocator::technical($row['old_slug'] ?? '') || ! is_string($row['sku'] ?? null)
                || $row['sku'] === '' || ($row['old_url'] ?? null) !== $base.'/product/'.$row['old_slug']
                || isset($baseline['/product/'.$row['old_slug']]) || isset($skus[$row['sku']])) {
                throw new RuntimeException('invalid_manifest_identity');
            }
            $baseline['/product/'.$row['old_slug']] = $row['sku'];
            $skus[$row['sku']] = true;
        }
        [$products, $redirects] = app(ProductUrlMigration::class)->snapshot();
        $bySlug = [];
        $bySku = [];
        $edges = [];
        $sources = $baseline;
        $result = ['status' => 'ok', 'mode' => 'current_state', 'expected_redirects' => count($baseline),
            'active_onec_slugs' => 0, 'redirects_checked' => 0, 'redirect_errors' => 0,
            'canonical_errors' => 0, 'sitemap_errors' => 0, 'duplicate_slugs' => 0,
            'invalid_active_slugs' => 0, 'identity_errors' => 0, 'chains' => 0,
            'self_redirects' => 0, 'loops' => 0, 'failed' => 0,
            'identity_check' => 'public_manifest_sku_to_current_product; original_id_and_protected_hash_require_receipt',
            'sitemap_check' => 'uncached_application_xml', 'http_checked' => false];
        $failures = [];
        $fail = function (string $counter, string $code, string $key) use (&$result, &$failures): void {
            $result[$counter]++;
            $failures[] = ['key' => $key, 'failure' => $code];
        };
        foreach ($products as $p) {
            $bySlug[strtolower($p->slug)][] = $p;
            $bySku[$p->sku][] = $p;
            if ($p->is_active && str_starts_with(strtolower($p->slug), 'onec-')) {
                $fail('active_onec_slugs', 'active_technical_slug', (string) $p->id);
            }
            if ($p->is_active && ! ProductSlugAllocator::valid($p->slug)) {
                $fail('invalid_active_slugs', 'invalid_active_slug', (string) $p->id);
            }
        }
        foreach ($bySlug as $slug => $group) {
            if (count($group) > 1) {
                $fail('duplicate_slugs', 'duplicate_slug', $slug);
            }
        }
        foreach ($redirects as $r) {
            if ($r->is_active) {
                $edges[$r->from_url] = $r->to_url;
            }
            if (str_starts_with($r->from_url, '/product/onec-') && ! array_key_exists($r->from_url, $sources)) {
                $sources[$r->from_url] = null;
            }
        }
        // Parse the actual sitemap builder, but do not call Cache::remember or HTTP.
        $dom = new DOMDocument;
        $xml = app(SitemapController::class)->productXml();
        $locs = [];
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            $fail('sitemap_errors', 'invalid_sitemap_xml', 'sitemap');
        } else {
            foreach ((new DOMXPath($dom))->query('//*[local-name()="url"]/*[local-name()="loc"]') as $node) {
                $locs[] = $node->textContent;
                if (str_starts_with($node->textContent, $base.'/product/onec-')) {
                    $fail('sitemap_errors', 'old_url_in_sitemap', $node->textContent);
                }
            }
        }
        foreach ($sources as $from => $sku) {
            $result['redirects_checked']++;
            $to = $edges[$from] ?? null;
            $target = $to === null ? null : $this->localPath($to, $base);
            if ($to === null) {
                $fail('redirect_errors', 'missing_or_inactive_redirect', $from);
            }
            $product = null;
            if ($sku !== null) {
                $matches = $bySku[$sku] ?? [];
                if (count($matches) === 1) {
                    $product = $matches[0];
                } else {
                    $fail('identity_errors', 'manifest_sku_missing_or_ambiguous', $from);
                }
            } elseif ($target !== null && str_starts_with($target, '/product/')) {
                $matches = $bySlug[substr($target, 9)] ?? [];
                $product = count($matches) === 1 ? $matches[0] : null;
            }
            $current = $product ? '/product/'.$product->slug : null;
            if (! $product || ! $product->is_active || ! ProductSlugAllocator::valid($product->slug)
                || str_starts_with($product->slug, 'onec-') || $target !== $current) {
                $fail('redirect_errors', 'target_must_be_current_readable_active_product', $from);
            }
            if ($target === $from) {
                $fail('self_redirects', 'self_redirect', $from);
            }
            if ($target !== null && isset($edges[$target])) {
                $fail('chains', 'redirect_chain', $from);
            }
            $seen = [];
            $cursor = $from;
            while ($cursor !== null && isset($edges[$cursor])) {
                if (isset($seen[$cursor])) {
                    $fail('loops', 'redirect_loop', $from);
                    break;
                }
                $seen[$cursor] = true;
                $cursor = $this->localPath($edges[$cursor], $base);
            }
            if ($product) {
                $url = $base.$current;
                // Use the same canonical method as pages.product, without saving a model.
                $model = (new Product)->newFromBuilder((array) $product);
                if ($model->seoCanonical() !== $url) {
                    $fail('canonical_errors', 'canonical_mismatch', $from);
                }
                if (isset($edges[$current])) {
                    $fail('redirect_errors', 'current_product_shadowed_by_redirect', $current);
                }
                if (count(array_keys($locs, $url, true)) !== 1) {
                    $fail('sitemap_errors', 'current_url_missing_or_duplicate', $url);
                }
            }
        }
        $result['failed'] = count($failures);
        $result['status'] = $failures ? 'failed' : 'ok';
        if ($failures) {
            $result['failures'] = $failures;
        }

        return $result;
    }

    private function localPath(string $url, string $base): ?string
    {
        $path = str_starts_with($url, $base.'/') ? substr($url, strlen($base)) : $url;

        return preg_match('~^/[a-z0-9/-]+$~D', $path) ? $path : null;
    }

    /** Read-only DB/HTTP checks. Redirect following is deliberately disabled. */
    public function verify(array $receipt): array
    {
        $base = app(ProductUrlMigration::class)->base();
        if (($receipt['status'] ?? null) !== 'committed' || ($receipt['base_url'] ?? null) !== $base
            || ! is_array($receipt['rows'] ?? null) || count($receipt['rows']) !== ($receipt['count'] ?? -1)) {
            throw new RuntimeException('valid_committed_receipt_for_this_origin_required');
        }
        $client = Http::withoutRedirecting()->connectTimeout(5)->timeout(20);
        $sitemap = $client->get($base.'/sitemap-products.xml');
        $dom = new DOMDocument;
        if ($sitemap->status() !== 200 || ! @$dom->loadXML($sitemap->body(), LIBXML_NONET)) {
            throw new RuntimeException('product_sitemap_unavailable_or_invalid');
        }
        $locs = [];
        foreach ((new DOMXPath($dom))->query('//*[local-name()="url"]/*[local-name()="loc"]') as $node) {
            $locs[] = $node->textContent;
        }
        $results = [];
        foreach ($receipt['rows'] as $row) {
            $failures = [];
            try {
                foreach (['old', 'new'] as $side) {
                    if (! ProductSlugAllocator::valid($row[$side.'_slug'])
                        || $row[$side.'_url'] !== $base.'/product/'.$row[$side.'_slug']) {
                        throw new RuntimeException('invalid_receipt_URL');
                    }
                }
                $old = $client->get($row['old_url']);
                if ($old->status() !== 301 || ! in_array($old->header('Location'), [$row['new_url'], '/product/'.$row['new_slug']], true)) {
                    $failures[] = 'old_must_301_directly_to_new';
                }
                $new = $client->get($row['new_url']);
                if ($new->status() !== 200 || $new->header('Location')) {
                    $failures[] = 'new_must_200_without_redirect';
                }
                $html = new DOMDocument;
                @$html->loadHTML('<?xml encoding="UTF-8">'.$new->body(), LIBXML_NONET);
                $xpath = new DOMXPath($html);
                if ($xpath->query('//link[@rel="canonical"]')->length !== 1
                    || $xpath->evaluate('string(//link[@rel="canonical"]/@href)') !== $row['new_url']) {
                    $failures[] = 'canonical_mismatch';
                }
                if (stripos($xpath->evaluate('string(//meta[@name="robots"]/@content)').$new->header('X-Robots-Tag'), 'noindex') !== false) {
                    $failures[] = 'new_is_noindex';
                }
                if (count(array_keys($locs, $row['new_url'], true)) !== 1 || in_array($row['old_url'], $locs, true)) {
                    $failures[] = 'sitemap_mismatch';
                }
                $product = DB::table('products')->find($row['id']);
                if (! $product || $product->slug !== $row['new_slug']
                    || ! hash_equals($row['protected_sha256'], ProductUrlMigration::protectedHash($product))) {
                    $failures[] = 'product_identity_or_protected_data_changed';
                }
            } catch (\Throwable) {
                $failures[] = 'verification_request_or_receipt_failed';
            }
            $results[] = ['sku' => $row['sku'], 'old_url' => $row['old_url'], 'new_url' => $row['new_url'], 'failures' => $failures];
        }

        return ['checked' => count($results), 'failed' => count(array_filter($results, fn ($r) => $r['failures'] !== [])), 'rows' => $results];
    }
}
