<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class KaspiProductionBridgeService
{
    public function __construct(private readonly KaspiLocalBrowserGuard $guard, private readonly KaspiProductionCandidateClient $candidates,
        private readonly KaspiLocalUrlResolver $resolver, private readonly KaspiLocalPageCollector $collector,
        private readonly KaspiProductionPayloadValidator $validator) {}

    private ?float $lastRequestAt = null;

    public function prepareRefreshCandidate(array $candidate, bool $debug, callable $progress): array
    {
        $candidate['_force'] = true;
        $candidate['_progress'] = $progress;

        return $this->prepareCandidate($candidate, $debug);
    }

    public function prepare(string $sku, bool $debug = false): array
    {
        KaspiSingleProductPolicy::assertSku($sku);
        $this->guard->assertAllowed();
        if (KaspiUrlRules::base() !== 'https://autohimiki.kz') {
            throw new \RuntimeException('production_base_mismatch');
        }
        $rows = $this->candidates->fetch(['sku' => $sku, 'limit' => 1]);
        if (count($rows) !== 1 || $rows[0]['sku'] !== $sku) {
            throw new \RuntimeException('candidate_identity_mismatch');
        }

        return $this->prepareCandidate($rows[0], $debug);
    }

    public function prepareCandidate(array $candidate, bool $debug = false, ?callable $onResolved = null): array
    {
        $force = ($candidate['_force'] ?? false) === true;
        $progress = $candidate['_progress'] ?? static function () {};
        $sku = $candidate['sku'];
        KaspiSingleProductPolicy::assertSku($sku);
        $this->guard->assertAllowed();
        if (KaspiUrlRules::base() !== 'https://autohimiki.kz') {
            throw new \RuntimeException('production_base_mismatch');
        }
        if (! config('services.kaspi.merchant_id') || ! config('services.kaspi.city_id')) {
            throw new \RuntimeException('widget_configuration_missing');
        }
        if (! KaspiUrlRules::storefront($candidate['storefront_url'])) {
            throw new \RuntimeException('candidate_identity_mismatch');
        }
        $resolved = $this->resolver->resolve($candidate, $debug);
        if ($force && (($resolved['widget_verified'] ?? false) || ($resolved['status'] ?? '') === 'resolved')) {
            $progress('with_kaspi_source', []);
        }
        if (($resolved['status'] ?? '') !== 'resolved') {
            $status = $resolved['status'] ?? '';
            $reason = in_array($status, ['widget_not_found', 'widget_mismatch', 'iframe_not_loaded',
                'timeout', 'captcha_detected', 'ambiguous_urls', 'invalid_kaspi_url', 'storefront_unavailable',
                'kaspi_url_not_opened', 'browser_error', 'local_browser_disabled', 'malformed_node_output'], true) ? $status : 'unknown';
            throw new \RuntimeException('resolver_not_verified_'.$reason);
        }
        if (($resolved['sku'] ?? '') !== $sku
            || ($resolved['storefront_url'] ?? '') !== $candidate['storefront_url'] || ! KaspiUrlRules::product((string) ($resolved['kaspi_url'] ?? ''))) {
            throw new \RuntimeException('resolver_not_verified');
        }
        if ($onResolved) {
            $onResolved();
        }
        if ($force) {
            $progress('resolved', ['url' => $resolved['kaspi_url']]);
        }
        $parsed = $force ? $this->collector->collectRefreshUrl($resolved['kaspi_url']) : $this->collector->collectUrl($resolved['kaspi_url']);
        if ($force && $parsed['url'] !== $resolved['kaspi_url']) {
            throw new \RuntimeException('wrong_product');
        }
        if ($force) {
            $progress('parsed', $parsed);
            if (($parsed['refresh_attribute_issues'] ?? []) !== []) {
                throw new \RuntimeException('attributes_invalid', 422);
            }
        }
        $extra = $force ? ['force_content_refresh' => true, 'product_id' => $candidate['product_id'], 'state_fingerprint' => $candidate['state_fingerprint']] : [];
        $payload = $this->validator->validate($extra + ['version' => 1, 'sku' => $sku, 'storefront_url' => $candidate['storefront_url'],
            'kaspi_url' => $parsed['url'], 'content' => ['title' => $parsed['title'], 'description' => $parsed['description'], 'images' => $parsed['images'], 'attributes' => $parsed['attributes']],
            'source' => ['collector' => 'local-playwright', 'resolver_verified' => true, 'captcha' => false,
                'merchant_id' => (string) config('services.kaspi.merchant_id'), 'city_id' => (string) config('services.kaspi.city_id')]]);
        $state = $this->request('GET', ['sku' => $sku] + ($force ? ['force_content_refresh' => 'true'] : []));
        if ($force) {
            if (($state['sku'] ?? null) !== $sku || ($state['product_id'] ?? null) !== $candidate['product_id']
                || ($state['storefront_url'] ?? null) !== $candidate['storefront_url']) {
                throw new \RuntimeException('identity_changed', 409);
            }
            if (($state['state_fingerprint'] ?? null) !== $candidate['state_fingerprint']) {
                throw new \RuntimeException('state_changed', 409);
            }
            if (($state['attributes_safe'] ?? null) !== true) {
                throw new \RuntimeException('attributes_invalid', 422);
            }

            return ['payload' => $payload, 'preview' => $state];
        }
        if (($state['sku'] ?? '') !== $sku || ! in_array($state['main_image_action'] ?? '', ['preserve', 'replace_broken_or_empty'], true)
            || ! in_array($state['description_action'] ?? '', ['preserve', 'fill_if_collected'], true)) {
            throw new \RuntimeException('invalid_preview_response');
        }

        return ['payload' => $payload, 'preview' => ['sku' => $sku, 'kaspi_url' => $parsed['url'], 'title' => $parsed['title'],
            'description_length' => mb_strlen((string) $parsed['description']), 'image_count' => count($parsed['images']),
            'attribute_count' => count($payload['content']['attributes']), 'attributes_policy' => 'merge_preserve_existing',
            'main_image_action' => $state['main_image_action'], 'description_action' => $state['description_action'],
            'existing_description_length' => $state['existing_description_length'] ?? null,
            'gallery_additions_planned' => '0..'.count($parsed['images']).' (content-hash deduplication on server)']];
    }

    public function send(array $payload): array
    {
        $this->guard->assertAllowed();
        $payload = $this->validator->validate($payload);
        $result = $this->request('POST', $payload);
        if (($result['sku'] ?? '') !== $payload['sku'] || ! in_array($result['status'] ?? '', ['imported', 'unchanged'], true)) {
            throw new \RuntimeException('invalid_import_response_check_before_retry');
        }

        if (($payload['force_content_refresh'] ?? false) === true) {
            if (($result['product_id'] ?? null) !== $payload['product_id'] || ! is_array($result['cleanup_warnings'] ?? null)) {
                throw new \RuntimeException('invalid_import_response_check_before_retry');
            }

            return ['status' => $result['status'], 'sku' => $result['sku'], 'product_id' => $result['product_id'], 'reason' => null,
                'cleanup_warnings' => array_values(array_intersect($result['cleanup_warnings'], ['cache_invalidation_failed', 'obsolete_media_cleanup_failed']))];
        }

        return array_intersect_key($result, array_flip(['status', 'sku', 'description', 'description_reason', 'main_image', 'gallery_added', 'attributes', 'attributes_added']));
    }

    private function request(string $method, array $data): array
    {
        if (KaspiUrlRules::base() !== 'https://autohimiki.kz') {
            throw new \RuntimeException('production_base_mismatch');
        }
        $token = (string) config('services.kaspi.internal_api_token');
        if (trim($token) === '') {
            throw new \RuntimeException('internal_api_token_missing');
        }
        // Six requests/minute on production, shared by GET and POST. Never retry an uncertain POST.
        if ($this->lastRequestAt !== null) {
            $milliseconds = (int) ceil(max(0, 11 - (microtime(true) - $this->lastRequestAt)) * 1000);
            if ($milliseconds > 0) {
                Sleep::for($milliseconds)->milliseconds();
            }
        }
        $this->lastRequestAt = microtime(true);
        try {
            $client = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(210)->withoutRedirecting();
            $url = KaspiUrlRules::base().'/api/internal/kaspi-content/import';
            $response = $method === 'GET' ? $client->get($url, $data) : $client->post($url, $data);
        } catch (\Throwable) {
            throw new \RuntimeException($method === 'POST' ? 'import_transport_failed_check_before_retry' : 'preview_transport_failed');
        }
        if (! $response->successful()) {
            if (in_array($data['force_content_refresh'] ?? false, [true, 'true'], true)) {
                $reason = $response->json('error');
                if (is_string($reason) && in_array($reason, KaspiRefreshPolicy::REASONS, true)) {
                    throw new \RuntimeException($reason, $response->status());
                }
            }
            throw new \RuntimeException(strtolower($method).'_import_http_'.$response->status());
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException('import_invalid_response');
        }

        return $body;
    }
}
