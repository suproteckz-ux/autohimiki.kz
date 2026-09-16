<?php

namespace App\Services\ProductUrls;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ProductUrlVerifier
{
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
