<?php

namespace Tests\Feature;

use App\Services\Ozon\OzonClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OzonConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ozon.enabled' => false, 'ozon.client_id' => 'test-client', 'ozon.api_key' => 'connection-secret']);
        Http::preventStrayRequests();
        Log::spy();
    }

    public function test_connection_works_when_export_disabled_using_only_one_bounded_read(): void
    {
        Http::fake(['https://api-seller.ozon.ru/v1/seller/info' => Http::response(['company' => ['name' => 'Seller']])]);
        $this->assertSame(0, Artisan::call('ozon:test-connection'));
        $this->assertSame('Ozon connection: OK', trim(Artisan::output()));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-seller.ozon.ru/v1/seller/info'
            && $request->method() === 'POST'
            && $request->body() === '{}'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('Api-Key', 'connection-secret')
            && $request->hasHeader('Client-Id', 'test-client'));
        $this->assertFalse(config('ozon.enabled'));
        $this->assertNoSecretOrLogs();
    }

    #[DataProvider('errors')]
    public function test_http_errors_are_safe_and_not_retried(int $status, string $message): void
    {
        Http::fake(['https://api-seller.ozon.ru/v1/seller/info' => Http::response(['message' => 'connection-secret'], $status)]);
        $this->assertSame(1, Artisan::call('ozon:test-connection'));
        $this->assertSame('Ozon connection: '.$message, trim(Artisan::output()));
        Http::assertSentCount(1);
        $this->assertNoSecretOrLogs();
    }

    public static function errors(): array
    {
        return [[401, 'unauthorized (HTTP 401)'], [403, 'forbidden (HTTP 403)'],
            [429, 'rate limited (HTTP 429)'], [500, 'API unavailable (HTTP 500)'],
            [502, 'API unavailable (HTTP 502)'], [503, 'API unavailable (HTTP 503)'],
            [302, 'API request rejected (HTTP 302)'], [200, 'API business error (HTTP 200)']];
    }

    public function test_missing_credentials_does_not_send_request(): void
    {
        Http::fake();
        foreach (['ozon.client_id', 'ozon.api_key'] as $key) {
            config(['ozon.client_id' => 'test-client', 'ozon.api_key' => 'connection-secret', $key => ' ']);
            $this->assertSame(1, Artisan::call('ozon:test-connection'));
            $this->assertSame('Ozon connection: credentials missing', trim(Artisan::output()));
        }
        Http::assertNothingSent();
        $this->assertNoSecretOrLogs();
    }

    public function test_network_exception_does_not_leak_secret(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection-secret'));
        $this->assertSame(1, Artisan::call('ozon:test-connection'));
        $this->assertSame('Ozon connection: network error', trim(Artisan::output()));
        $this->assertNoSecretOrLogs();
    }

    public function test_connection_check_does_not_unlock_writes_or_category_tree(): void
    {
        Http::fake(['https://api-seller.ozon.ru/v1/seller/info' => Http::response(['company' => ['name' => 'Seller']])]);
        $client = app(OzonClient::class);
        $this->assertSame('OK', $client->testConnection());
        foreach (['/v3/product/import', '/v2/products/stocks', '/v1/product/import/prices', '/v1/description-category/tree'] as $path) {
            try {
                $client->request($path, []);
                $this->fail('Disabled integration must reject requests');
            } catch (\RuntimeException $exception) {
                $this->assertStringStartsWith('ozon_disabled:', $exception->getMessage());
            }
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->url() !== 'https://api-seller.ozon.ru/v1/seller/info');
    }

    private function assertNoSecretOrLogs(?string $output = null): void
    {
        $this->assertStringNotContainsString('connection-secret', $output ?? Artisan::output());
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_timeout_is_distinct_from_network_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out connection-secret'));
        $this->assertSame(1, Artisan::call('ozon:test-connection'));
        $this->assertSame('Ozon connection: timeout', trim(Artisan::output()));
        $this->assertNoSecretOrLogs();
    }

    public function test_seller_info_prints_only_redacted_selected_fields(): void
    {
        Http::fake(['*/v1/seller/info' => Http::response(['company' => ['name' => 'Seller connection-secret'], 'api_key' => 'connection-secret'])]);
        $this->assertSame(0, Artisan::call('ozon:seller-info'));
        $output = Artisan::output();
        $this->assertStringContainsString('Company: Seller [redacted]', $output);
        $this->assertNoSecretOrLogs($output);
    }

    public function test_warehouse_v2_contract_statuses_and_explicit_cursor_without_auto_pagination(): void
    {
        Http::fakeSequence()->push(['warehouses' => [
            ['warehouse_id' => 10, 'name' => 'Main connection-secret', 'status' => 'ACTIVE'],
            ['warehouse_id' => 20, 'name' => 'Paused', 'status' => ['state' => 'INACTIVE']],
            ['warehouse_id' => 30, 'name' => 'Archived', 'is_archived' => true],
        ], 'cursor' => 'page-2', 'has_next' => true])->push(['warehouses' => [], 'has_next' => false, 'cursor' => '']);
        config(['ozon.warehouse_id' => 99]);
        $this->assertSame(0, Artisan::call('ozon:warehouses'));
        $output = Artisan::output();
        $this->assertStringContainsString('page-2', $output);
        $this->assertStringContainsString('Paused', $output);
        $this->assertStringContainsString('no', $output);
        $this->assertSame(99, config('ozon.warehouse_id'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/warehouse/list') && $r['limit'] === 100 && $r['cursor'] === '' && $r->method() === 'POST');
        $this->assertNoSecretOrLogs($output);
        $this->assertSame(0, Artisan::call('ozon:warehouses', ['--cursor' => 'page-2']));
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['cursor'] === 'page-2');
        $this->assertNoSecretOrLogs();
    }

    public function test_malformed_warehouse_schema_and_cursor_fail_without_writes(): void
    {
        Http::fakeSequence()->push(['result' => []])
            ->push(['warehouses' => [], 'has_next' => true, 'cursor' => ''])
            ->push(['warehouses' => ['not-an-item'], 'has_next' => false]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(1, Artisan::call('ozon:warehouses'));
            $this->assertStringContainsString('invalid warehouse', Artisan::output());
        }
        Http::assertSentCount(3);
    }

    public function test_obsolete_and_json_object_errors_are_distinguished_without_raw_body(): void
    {
        Http::fakeSequence()->push(['message' => 'obsolete method connection-secret'], 400)
            ->push(['message' => 'proto: syntax error unexpected token [ connection-secret'], 400)
            ->push(['result' => []], 200)->push('not-json connection-secret', 200);
        foreach (['obsolete method (HTTP 400)', 'invalid JSON object contract (HTTP 400)', 'invalid seller response (HTTP 200)', 'invalid JSON response (HTTP 200)'] as $expected) {
            $this->assertSame(1, Artisan::call('ozon:test-connection'));
            $this->assertSame('Ozon connection: '.$expected, trim(Artisan::output()));
            $this->assertNoSecretOrLogs();
        }
    }
}
