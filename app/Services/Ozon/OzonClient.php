<?php

namespace App\Services\Ozon;

use App\Models\OzonCategoryMapping;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OzonClient
{
    public const BASE_URL = 'https://api-seller.ozon.ru';

    private const SELLER_ENDPOINT = '/v1/seller/info';

    private const WAREHOUSE_ENDPOINT = '/v2/warehouse/list';

    private const CATEGORY_TREE_ENDPOINT = '/v1/description-category/tree';

    // Deliberate allow-list: no category tree, price update or content re-import.
    private const PATHS = ['/v3/product/list', '/v3/product/import', '/v1/product/import/info', '/v2/products/stocks', '/v1/description-category/attribute'];

    private array $annotationIds = [];

    public function diagnostics(): array
    {
        // The same constants build the real read-only requests. No HTTP or DB access.
        return [
            'base_host' => self::BASE_URL,
            'seller_endpoint' => self::SELLER_ENDPOINT,
            'seller_effective_url' => self::BASE_URL.self::SELLER_ENDPOINT,
            'warehouse_endpoint' => self::WAREHOUSE_ENDPOINT,
            'warehouse_effective_url' => self::BASE_URL.self::WAREHOUSE_ENDPOINT,
            'client_id_configured' => trim((string) config('ozon.client_id')) !== '',
            'api_key_configured' => trim((string) config('ozon.api_key')) !== '',
        ];
    }

    public function testConnection(?callable $onHttpError = null): string
    {
        try {
            $this->sellerInfo($onHttpError);

            return 'OK';
        } catch (\RuntimeException $error) {
            return $error->getMessage();
        }
    }

    public function sellerInfo(?callable $onHttpError = null): array
    {
        $data = $this->diagnosticRead(self::SELLER_ENDPOINT, [], $onHttpError);
        if (empty($data['company']) && empty($data['result'])) {
            throw new \RuntimeException('invalid seller response (HTTP 200)');
        }

        return $data;
    }

    public function warehouses(string $cursor = ''): array
    {
        // One explicit page; no automatic full scan or database mirror.
        $data = $this->diagnosticRead(self::WAREHOUSE_ENDPOINT, ['limit' => 100, 'cursor' => $cursor]);
        if (! isset($data['warehouses']) || ! is_array($data['warehouses']) || ! array_is_list($data['warehouses'])) {
            throw new \RuntimeException('invalid warehouse response (HTTP 200)');
        }
        if (($data['has_next'] ?? false) && (! is_string($data['cursor'] ?? null) || $data['cursor'] === '' || $data['cursor'] === $cursor)) {
            throw new \RuntimeException('invalid warehouse cursor (HTTP 200)');
        }
        foreach ($data['warehouses'] as $item) {
            if (! is_array($item) || ! is_scalar($item['warehouse_id'] ?? null)) {
                throw new \RuntimeException('invalid warehouse item (HTTP 200)');
            }
        }

        return $data;
    }

    public function categoryTree(): array
    {
        if (trim((string) config('ozon.client_id')) === '' || trim((string) config('ozon.api_key')) === '') {
            throw new \RuntimeException('credentials missing');
        }
        try {
            $response = Http::withHeaders(['Client-Id' => config('ozon.client_id'), 'Api-Key' => config('ozon.api_key')])
                ->acceptJson()->connectTimeout(10)->timeout(config('ozon.timeout'))
                ->withOptions(['allow_redirects' => false])
                ->withBody('{"language":"DEFAULT"}', 'application/json')
                ->post(self::BASE_URL.self::CATEGORY_TREE_ENDPOINT);
        } catch (ConnectionException) {
            throw new \RuntimeException('network error');
        } catch (\Throwable) {
            throw new \RuntimeException('network error');
        }
        $status = $response->status();
        $data = $response->json();
        $reason = match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 429 => 'rate limited',
            $status >= 500 => 'API unavailable',
            ! $response->successful() => 'API request rejected',
            ! is_array($data) => 'invalid JSON response',
            default => null,
        };
        if ($reason !== null) {
            throw new \RuntimeException($reason.' (HTTP '.$status.')');
        }

        return $data;
    }

    public function categoryBranch(int $id): array
    {
        if (trim((string) config('ozon.client_id')) === '' || trim((string) config('ozon.api_key')) === '') {
            throw new \RuntimeException('credentials missing');
        }
        $body = (string) json_encode(['description_category_id' => $id, 'language' => 'DEFAULT']);
        try {
            $response = Http::withHeaders(['Client-Id' => config('ozon.client_id'), 'Api-Key' => config('ozon.api_key')])
                ->acceptJson()->connectTimeout(10)->timeout(config('ozon.timeout'))
                ->withOptions(['allow_redirects' => false])
                ->withBody($body, 'application/json')
                ->post(self::BASE_URL.self::CATEGORY_TREE_ENDPOINT);
        } catch (ConnectionException) {
            throw new \RuntimeException('network error');
        } catch (\Throwable) {
            throw new \RuntimeException('network error');
        }
        $status = $response->status();
        $data = $response->json();
        $reason = match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 429 => 'rate limited',
            $status >= 500 => 'API unavailable',
            ! $response->successful() => 'API request rejected',
            ! is_array($data) => 'invalid JSON response',
            default => null,
        };
        if ($reason !== null) {
            throw new \RuntimeException($reason.' (HTTP '.$status.')');
        }

        return $data;
    }

    public function safeDisplay(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        foreach ([config('ozon.api_key'), config('ozon.client_id')] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $text), 0, 500);
    }

    private function diagnosticRead(string $path, array $payload = [], ?callable $onHttpError = null): array
    {
        if (! in_array($path, [self::SELLER_ENDPOINT, self::WAREHOUSE_ENDPOINT], true)) {
            throw new \RuntimeException('read-only endpoint not allowed');
        }
        if (trim((string) config('ozon.client_id')) === '' || trim((string) config('ozon.api_key')) === '') {
            throw new \RuntimeException('credentials missing');
        }
        // Source: autohimiya-laravel, fixes 6a9543c and 413e96e. No DB writes/retries.
        try {
            $request = Http::withHeaders(['Client-Id' => config('ozon.client_id'), 'Api-Key' => config('ozon.api_key')])
                ->acceptJson()->connectTimeout(10)->timeout(config('ozon.timeout'))
                ->withOptions(['allow_redirects' => false]);
            $response = $path === self::SELLER_ENDPOINT
                ? $request->withBody('{}', 'application/json')->post(self::BASE_URL.$path)
                : $request->asJson()->post(self::BASE_URL.$path, $payload);
        } catch (ConnectionException $error) {
            $timeout = str_contains(strtolower($error->getMessage()), 'timed out') || str_contains($error->getMessage(), 'cURL error 28');
            throw new \RuntimeException($timeout ? 'timeout' : 'network error');
        } catch (\Throwable) {
            throw new \RuntimeException('network error');
        }
        $status = $response->status();
        if ($onHttpError !== null && $response->failed()) {
            $onHttpError((new OzonConnectionResponsePreview)->build($response));
        }
        $data = $response->json();
        $businessError = is_array($data) ? ($data['message'] ?? $data['error'] ?? null) : null;
        $reason = match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 429 => 'rate limited',
            $status >= 500 => 'API unavailable',
            ! $response->successful() => 'API request rejected',
            ! is_array($data) => 'invalid JSON response',
            ! empty($businessError) => 'API business error',
            default => null,
        };
        if ($reason !== null) {
            if ($status === 400 && is_string($businessError)) {
                $reason = match (true) {
                    str_contains(strtolower($businessError), 'obsolete method') => 'obsolete method',
                    str_contains(strtolower($businessError), 'proto: syntax error') => 'invalid JSON object contract',
                    default => $reason,
                };
            }
            throw new \RuntimeException($reason.' (HTTP '.$status.')');
        }

        return $data;
    }

    public function annotationId(OzonCategoryMapping $mapping): int
    {
        $this->assertEnabled();
        if (! $mapping->enabled || (int) $mapping->ozon_description_category_id <= 0 || (int) $mapping->ozon_type_id <= 0) {
            throw new \RuntimeException('ozon_mapping_missing');
        }
        $key = config('ozon.client_id').':'.$mapping->ozon_description_category_id.':'.$mapping->ozon_type_id;
        if (isset($this->annotationIds[$key])) {
            return $this->annotationIds[$key];
        }
        $data = $this->request('/v1/description-category/attribute', [
            'description_category_id' => (int) $mapping->ozon_description_category_id,
            'type_id' => (int) $mapping->ozon_type_id, 'language' => 'DEFAULT',
        ]);
        if (! isset($data['result']) || ! is_array($data['result'])) {
            throw new \RuntimeException('ozon_annotation_invalid_response');
        }
        // Only retain the annotation ID in memory for this command. No taxonomy storage.
        foreach (['аннотация', 'описание товара'] as $name) {
            foreach ($data['result'] ?? [] as $attribute) {
                if (! is_array($attribute) || mb_strtolower(trim((string) ($attribute['name'] ?? ''))) !== $name) {
                    continue;
                }
                $id = $attribute['id'] ?? $attribute['attribute_id'] ?? null;
                if (is_numeric($id) && (int) $id > 0 && empty($attribute['dictionary_id']) && empty($attribute['complex_id'])) {
                    return $this->annotationIds[$key] = (int) $id;
                }
            }
        }
        throw new \RuntimeException('ozon_annotation_missing: targeted mapping attribute not found');
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
            if (! empty($data['message']) || ! empty($data['error'])) {
                throw new \RuntimeException('ozon_business_error');
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
