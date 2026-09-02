<?php

namespace App\Services\Kaspi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class KaspiProductionCandidateClient
{
    public function fetch(array $options = []): array
    {
        $url = KaspiUrlRules::base().'/api/internal/kaspi-content/candidates';
        $token = (string) config('services.kaspi.internal_api_token');
        if ($token === '') {
            throw new \RuntimeException('kaspi_internal_api_token_missing');
        }
        $remaining = min(100, max(1, (int) ($options['limit'] ?? 25)));
        $cursor = (int) ($options['cursor'] ?? 0);
        $result = [];
        $seen = [];
        do {
            $query = ['limit' => $remaining, 'cursor' => $cursor];
            if (isset($options['sku'])) {
                $query['sku'] = trim($options['sku']);
            }
            try {
                $response = Http::connectTimeout(5)->timeout(30)->withoutRedirecting()
                    ->acceptJson()->withToken($token)->get($url, $query);
            } catch (ConnectionException) {
                throw new \RuntimeException('candidate_connection_failed_or_timeout');
            }
            if (! $response->successful()) {
                throw new \RuntimeException('candidate_http_'.$response->status());
            }
            $body = $response->json();
            if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])
                || ! array_is_list($body['data']) || ! array_key_exists('next_cursor', $body)
                || count($body['data']) > $remaining) {
                throw new \RuntimeException('candidate_invalid_json');
            }
            $next = $body['next_cursor'];
            if ($next !== null && (! is_int($next) || $next <= $cursor || $body['data'] === [])) {
                throw new \RuntimeException('candidate_invalid_cursor');
            }
            foreach ($body['data'] as $row) {
                if (! is_array($row) || ! is_string($row['sku'] ?? null) || trim($row['sku']) === ''
                    || ! is_string($row['name'] ?? null) || ! is_string($row['storefront_url'] ?? null)
                    || ! KaspiUrlRules::storefront($row['storefront_url']) || isset($seen['sku:'.$row['sku']])
                    || (isset($query['sku']) && $row['sku'] !== $query['sku'])) {
                    throw new \RuntimeException('candidate_invalid_row');
                }
                $seen['sku:'.$row['sku']] = true;
                // Forward only public fields; never forward credentials or arbitrary API data to Node.
                $result[] = array_intersect_key($row, array_flip(['sku', 'name', 'storefront_url']));
                $remaining--;
            }
            $cursor = $next;
        } while ($cursor !== null && $remaining > 0);

        return $result;
    }
}
