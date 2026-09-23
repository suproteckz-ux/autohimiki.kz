<?php

namespace Tests\Feature;

use App\Services\Ozon\OzonClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OzonDiagnoseTest extends TestCase
{
    public function test_diagnose_prints_exact_effective_urls_without_network_database_or_secrets(): void
    {
        config(['ozon.enabled' => false, 'ozon.client_id' => 'private-client', 'ozon.api_key' => 'private-api-secret']);
        Http::preventStrayRequests();
        Http::fake();
        Log::spy();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->assertSame(0, Artisan::call('ozon:diagnose'));
        $output = Artisan::output();
        foreach ([
            'Base host: https://api-seller.ozon.ru',
            'Seller endpoint: /v1/seller/info',
            'Seller effective URL: https://api-seller.ozon.ru/v1/seller/info',
            'Warehouse endpoint: /v2/warehouse/list',
            'Warehouse effective URL: https://api-seller.ozon.ru/v2/warehouse/list',
            'Seller method: POST', 'Seller body: {}', 'Client ID: yes', 'API key: yes',
        ] as $line) {
            $this->assertStringContainsString($line, $output);
        }
        foreach (['private-client', 'private-api-secret', '/v1/v1/', '/api/v1/', '/v1/warehouse/list'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }
        $this->assertSame([], $queries);
        Http::assertNothingSent();
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_missing_or_blank_credentials_are_only_reported_as_no(): void
    {
        Http::fake();
        config(['ozon.client_id' => null, 'ozon.api_key' => ' ']);
        $this->assertSame(0, Artisan::call('ozon:diagnose'));
        $output = Artisan::output();
        $this->assertStringContainsString('Client ID: no', $output);
        $this->assertStringContainsString('API key: no', $output);
        Http::assertNothingSent();
    }

    public function test_unused_base_url_env_and_config_cannot_change_actual_read_urls(): void
    {
        $previous = getenv('OZON_BASE_URL');
        try {
            putenv('OZON_BASE_URL=https://private-api-secret@example.invalid/api/v1');
            config(['ozon.base_url' => 'https://example.invalid/v1', 'ozon.client_id' => 'private-client', 'ozon.api_key' => 'private-api-secret']);
            Http::preventStrayRequests();
            Http::fake([
                'https://api-seller.ozon.ru/v1/seller/info' => Http::response(['company' => ['name' => 'Seller']]),
                'https://api-seller.ozon.ru/v2/warehouse/list' => Http::response(['warehouses' => [], 'has_next' => false]),
            ]);
            $client = app(OzonClient::class);
            $info = $client->diagnostics();
            $client->sellerInfo();
            $client->warehouses();
            Http::assertSentCount(2);
            Http::assertSent(fn ($r) => $r->url() === $info['seller_effective_url'] && $r->method() === 'POST'
                && $r->body() === '{}' && $r->hasHeader('Content-Type', 'application/json')
                && $r->hasHeader('Client-Id', 'private-client') && $r->hasHeader('Api-Key', 'private-api-secret'));
            Http::assertSent(fn ($r) => $r->url() === $info['warehouse_effective_url'] && $r->method() === 'POST'
                && $r->data() === ['limit' => 100, 'cursor' => '']);
            $this->assertSame(0, Artisan::call('ozon:diagnose'));
            $output = Artisan::output();
            $this->assertStringNotContainsString('example.invalid', $output);
            $this->assertStringNotContainsString('private-api-secret', $output);
            $this->assertStringContainsString('OZON_BASE_URL is not used', $output);
            Http::assertSentCount(2);
        } finally {
            putenv($previous === false ? 'OZON_BASE_URL' : 'OZON_BASE_URL='.$previous);
        }
    }
}
