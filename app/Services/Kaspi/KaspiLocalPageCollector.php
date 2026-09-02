<?php

namespace App\Services\Kaspi;

class KaspiLocalPageCollector
{
    public function __construct(private readonly KaspiLocalBrowserGuard $guard, private readonly KaspiLocalNodeProcessRunner $runner, private readonly KaspiEnrichmentParser $parser) {}

    public function collectUrl(string $url): array
    {
        $this->guard->assertAllowed();
        if (KaspiUrlRules::product($url) !== $url) {
            throw new \RuntimeException('wrong_product');
        }
        $process = $this->runner->collect(['url' => $url, 'headless' => 'false']);
        if ($process['timeout'] ?? false) {
            throw new \RuntimeException('collector_timeout');
        }
        $result = json_decode(ltrim((string) ($process['stdout'] ?? ''), "\xEF\xBB\xBF"), true);
        if (! is_array($result)) {
            throw new \RuntimeException('collector_invalid_json');
        }
        if (($result['captcha'] ?? null) !== false) {
            throw new \RuntimeException('captcha_detected');
        }
        if (($result['status'] ?? '') !== 'ok' || ($process['exit_code'] ?? 1) !== 0) {
            $reason = $result['reason'] ?? '';
            throw new \RuntimeException(in_array($reason, ['wrong_product', 'local_browser_disabled', 'collector_timeout', 'collector_empty_or_unavailable', 'collector_html_too_large'], true) ? $reason : 'collector_failed');
        }
        if (($result['final_url'] ?? '') !== $url || ($result['http_status'] ?? 0) < 200 || ($result['http_status'] ?? 0) >= 300) {
            throw new \RuntimeException('wrong_product');
        }

        return $this->parser->parse((string) ($result['html'] ?? ''), $url);
    }
}
