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
        Http::fake(['https://api-seller.ozon.ru/v3/product/list' => Http::response(['result' => ['items' => [], 'total' => 0]])]);
        $this->assertSame(0, Artisan::call('ozon:test-connection'));
        $this->assertSame('Ozon connection: OK', trim(Artisan::output()));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-seller.ozon.ru/v3/product/list'
            && $request->method() === 'POST'
            && $request->data() === ['filter' => ['visibility' => 'ALL'], 'limit' => 1]
            && $request->hasHeader('Api-Key', 'connection-secret')
            && $request->hasHeader('Client-Id', 'test-client'));
        $this->assertFalse(config('ozon.enabled'));
        $this->assertNoSecretOrLogs();
    }

    #[DataProvider('errors')]
    public function test_http_errors_are_safe_and_not_retried(int $status, string $message): void
    {
        Http::fake(['https://api-seller.ozon.ru/v3/product/list' => Http::response(['message' => 'connection-secret'], $status)]);
        $this->assertSame(1, Artisan::call('ozon:test-connection'));
        $this->assertSame('Ozon connection: '.$message, trim(Artisan::output()));
        Http::assertSentCount(1);
        $this->assertNoSecretOrLogs();
    }

    public static function errors(): array
    {
        return [[401, 'unauthorized'], [403, 'forbidden'], [429, 'API unavailable'], [500, 'API unavailable'], [302, 'API unavailable'], [200, 'API unavailable']];
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
        $this->assertSame('Ozon connection: API unavailable', trim(Artisan::output()));
        $this->assertNoSecretOrLogs();
    }

    public function test_connection_check_does_not_unlock_writes_or_category_tree(): void
    {
        Http::fake(['https://api-seller.ozon.ru/v3/product/list' => Http::response(['result' => ['items' => []]])]);
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
        Http::assertNotSent(fn ($request) => $request->url() !== 'https://api-seller.ozon.ru/v3/product/list');
    }

    private function assertNoSecretOrLogs(): void
    {
        $this->assertStringNotContainsString('connection-secret', Artisan::output());
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }
}
