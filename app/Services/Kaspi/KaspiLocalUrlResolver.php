<?php

namespace App\Services\Kaspi;

class KaspiLocalUrlResolver
{
    public function __construct(private readonly KaspiLocalNodeProcessRunner $runner, private readonly KaspiLocalBrowserGuard $guard) {}

    public function resolve(array $candidate, bool $debug = false): array
    {
        $this->guard->assertAllowed();
        if (! is_string($candidate['sku'] ?? null) || trim($candidate['sku']) === ''
            || ! KaspiUrlRules::storefront((string) ($candidate['storefront_url'] ?? ''))) {
            throw new \RuntimeException('resolver_invalid_candidate');
        }
        $base = ['sku' => $candidate['sku'], 'storefront_url' => $candidate['storefront_url'], 'kaspi_url' => null];
        $process = $this->runner->run(['url' => $candidate['storefront_url'], 'sku' => $candidate['sku'],
            'merchant' => (string) config('services.kaspi.merchant_id'), 'city' => (string) config('services.kaspi.city_id'),
            'headless' => 'false']);
        $payload = json_decode(ltrim((string) ($process['stdout'] ?? ''), "\xEF\xBB\xBF"), true);
        $diagnostics = [];
        if ($debug) {
            // Deliberately do not print untrusted stdout/stderr, headers, URLs with queries or secrets.
            $diagnostics = ['exit_code' => $process['exit_code'] ?? null,
                'stderr_bytes' => strlen((string) ($process['stderr'] ?? ''))];
        }
        if ($process['timeout'] ?? false) {
            $status = 'timeout';
        } elseif (! is_array($payload) || ! is_string($payload['status'] ?? null) || ! is_bool($payload['captcha'] ?? null)) {
            $status = 'malformed_node_output';
        } elseif ($payload['captcha']) {
            $status = 'captcha_detected';
        } elseif (($payload['status'] ?? '') === 'resolved') {
            $url = KaspiUrlRules::product((string) ($payload['url'] ?? ''));
            if (($process['exit_code'] ?? 1) !== 0) {
                $status = 'browser_error';
            } elseif (! $url) {
                $status = 'invalid_kaspi_url';
            } else {
                return array_replace($base, ['kaspi_url' => $url, 'status' => 'resolved', 'diagnostics' => $diagnostics]);
            }
        } else {
            $status = in_array($payload['status'], ['widget_not_found', 'widget_mismatch', 'iframe_not_loaded',
                'timeout', 'ambiguous_urls', 'invalid_kaspi_url', 'storefront_unavailable', 'kaspi_url_not_opened',
                'browser_error', 'local_browser_disabled'], true) ? $payload['status'] : 'browser_error';
        }
        $diagnostics['reason'] = $status;

        return $base + ['status' => $status, 'diagnostics' => $diagnostics];
    }
}
