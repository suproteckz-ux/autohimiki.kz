<?php

// Read-only public HTTP inventory. Does not boot Laravel or connect to a DB.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\ProductUrls\ProductSlugAllocator;

$base = 'https://autohimiki.kz';
$out = dirname(__DIR__).'/docs/URL02_PUBLIC_MANIFEST';
$get = static function (string $url): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Autohimiki-URL02-read-only-audit']);
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    if ($body === false || $status !== 200) {
        throw new RuntimeException('Public inventory failed: '.$url.' HTTP '.$status);
    }

    return [$body, $status];
};
[$xml] = $get($base.'/sitemap-products.xml');
$sitemap = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
$all = $rows = [];
foreach ($sitemap->url as $node) {
    $url = (string) $node->loc;
    if (! str_starts_with($url, $base.'/product/')) {
        throw new RuntimeException('Unexpected sitemap origin/path');
    }
    $all[] = (object) ['slug' => basename($url)];
}
$technical = array_values(array_filter($all, fn ($p) => str_starts_with($p->slug, 'onec-')));
if (count($technical) !== 140) {
    throw new RuntimeException('STOP: published onec count changed to '.count($technical));
}
foreach ($technical as $i => $product) {
    $url = $base.'/product/'.$product->slug;
    [$html, $status] = $get($url);
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
    $xpath = new DOMXPath($doc);
    $data = null;
    foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
        $decoded = json_decode($script->textContent, true);
        if (($decoded['@type'] ?? null) === 'Product') {
            $data = $decoded;
        }
    }
    if (! is_string($data['sku'] ?? null) || ! is_string($data['name'] ?? null)) {
        throw new RuntimeException('Missing product identity: '.$url);
    }
    $canonical = $xpath->evaluate('string(//link[@rel="canonical"]/@href)');
    $rows[] = ['sku' => $data['sku'], 'name' => $data['name'], 'old_url' => $url,
        'old_slug' => $product->slug, 'http_status' => $status,
        'observed_canonical' => $canonical, 'canonical_status' => $canonical === $url ? 'public_self_canonical; stored_value_unknown' : 'REVIEW_custom_canonical',
        'existing_redirect_status' => 'old_URL_200_no_redirect; history_DB_unknown',
        'migration_status' => 'PROPOSED_ONLY; requires_authoritative_DB_dry_run'];
    if (($i + 1) % 20 === 0) {
        echo 'Read '.($i + 1)."/140 public products\n";
    }
    usleep(100000);
}
usort($rows, fn ($a, $b) => strcmp($a['sku'], $b['sku']));
if (count(array_unique(array_column($rows, 'sku'))) !== count($rows)) {
    throw new RuntimeException('Duplicate public SKU; review required');
}
$allocator = new ProductSlugAllocator;
$reserved = $allocator->reserved($all, []);
foreach ($rows as &$row) {
    try {
        $generated = $allocator->generate($row['name'], $reserved);
        $row['new_slug'] = $generated['slug'];
        $row['new_url'] = $base.'/product/'.$generated['slug'];
        $row['collision_status'] = 'public_set:'.$generated['collision_status'].'; drafts/history_UNKNOWN';
    } catch (RuntimeException $e) {
        $row['new_slug'] = $row['new_url'] = null;
        $row['collision_status'] = $e->getMessage();
        $row['migration_status'] = 'BLOCKED';
    }
}
unset($row);
$manifest = ['scope' => 'PUBLIC_SITEMAP_MIGRATION_SET', 'captured_at_utc' => gmdate(DATE_ATOM),
    'sitemap' => $base.'/sitemap-products.xml', 'sitemap_sha256' => hash('sha256', $xml),
    'total_public_products' => count($all), 'onec_candidates' => count($rows),
    'preserved_readable_slugs' => array_values(array_map(fn ($p) => $p->slug, array_filter($all, fn ($p) => ! str_starts_with($p->slug, 'onec-')))),
    'inactive_drafts' => 'NOT INVENTORIED; no database connection',
    'warning' => 'Proposals reserve public slugs only. Production DB dry-run is authoritative for current/draft slugs, history, canonical and eligibility.',
    'rows' => $rows];
file_put_contents($out.'.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$csv = fopen($out.'.csv', 'wb');
fputcsv($csv, array_keys($rows[0]), ',', '"', '');
foreach ($rows as $row) {
    fputcsv($csv, $row, ',', '"', '');
}
fclose($csv);
echo 'Complete: '.count($rows).' rows in '.$out.".{json,csv}\n";
