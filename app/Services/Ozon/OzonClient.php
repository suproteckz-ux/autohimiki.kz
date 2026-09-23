<?php

namespace App\Services\Ozon;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OzonClient
{
    // Deliberate allow-list: no category tree, price update or content re-import.
    private const PATHS = ['/v3/product/list', '/v3/product/import', '/v1/product/import/info', '/v2/products/stocks'];

    public function testConnection(): string
    {
        if (trim((string) config('ozon.client_id')) === '' || trim((string) config('ozon.api_key')) === '') {
            return 'credentials missing';
        }
        // Fixed read-only endpoint, one bounded request. No configurable bypass for writes.
        try {
            $response = Http::withHeaders(['Client-Id' => config('ozon.client_id'), 'Api-Key' => config('ozon.api_key')])
                ->acceptJson()->connectTimeout(10)->timeout(config('ozon.timeout'))
                ->withOptions(['allow_redirects' => false])
                ->post('https://api-seller.ozon.ru/v3/product/list', [
                    'filter' => ['visibility' => 'ALL'], 'limit' => 1,
                ]);
            if ($response->status() === 401) {
                return 'unauthorized';
            }
            if ($response->status() === 403) {
                return 'forbidden';
            }
            if ($response->successful() && is_array($response->json('result.items'))) {
                return 'OK';
            }
        } catch (\Throwable) {
            // Do not expose the request, response body or exception (may contain credentials).
        }

        return 'API unavailable';
    }

    public function assertEnabled(): void
    {
        if (! config('ozon.enabled')) {
            throw new \RuntimeException('ozon_disabled: verify import flow and authorize first test before enabling');
        }
        if (! config('ozon.client_id') || ! config('ozon.api_key')) {
            throw new \RuntimeException('ozon_credentials_missing');
        }
    }

    public function request(string $path, array $payload): array
    {
        $this->assertEnabled();
        if (! in_array($path, self::PATHS, true)) {
            throw new \RuntimeException('ozon_endpoint_not_allowed');
        }
        // Import is an upsert. Retrying an ambiguous response could resend a price.
        $attempts = $path === '/v3/product/import' ? 1 : max(1, (int) config('ozon.attempts'));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withHeaders(['Client-Id' => config('ozon.client_id'), 'Api-Key' => config('ozon.api_key')])
                    ->acceptJson()->connectTimeout(10)->timeout(config('ozon.timeout'))
                    ->withOptions(['allow_redirects' => false])
                    ->post('https://api-seller.ozon.ru'.$path, $payload);
            } catch (ConnectionException) {
                if ($attempt < $attempts) {
                    $this->backoff($attempt);

                    continue;
                }
                throw new \RuntimeException('ozon_connection_failed; reconcile before any new import');
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt < $attempts) {
                $this->backoff($attempt, $response->header('Retry-After'));

                continue;
            }
            // Never propagate response bodies, headers, requests or original exceptions.
            if (! $response->successful()) {
                throw new \RuntimeException('ozon_http_'.$response->status());
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new \RuntimeException('ozon_invalid_response');
            }

            return $data;
        }
        throw new \RuntimeException('ozon_request_failed');
    }

    private function backoff(int $attempt, ?string $retryAfter = null): void
    {
        $delay = (int) config('ozon.backoff_ms') * (2 ** ($attempt - 1));
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            $delay = max($delay, min(30, (int) $retryAfter) * 1000);
        }
        usleep(min(30000, $delay) * 1000);
    }

    public function find(string $offerId): ?int
    {
        $data = $this->request('/v3/product/list', ['filter' => ['offer_id' => [$offerId], 'visibility' => 'ALL'], 'limit' => 100]);
        $result = $data['result'] ?? null;
        if (! is_array($result) || ! isset($result['items'], $result['total']) || ! is_array($result['items'])) {
            throw new \RuntimeException('ozon_lookup_incomplete');
        }
        foreach ($result['items'] as $item) {
            if (($item['offer_id'] ?? null) === $offerId && (int) ($item['product_id'] ?? 0) > 0) {
                return (int) $item['product_id'];
            }
        }
        if ((int) $result['total'] !== 0 || $result['items'] !== []) {
            throw new \RuntimeException('ozon_lookup_incomplete');
        }

        return null;
    }
}
