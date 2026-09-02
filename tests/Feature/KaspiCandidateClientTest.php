<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiProductionCandidateClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KaspiCandidateClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kaspi.internal_api_token' => 'test-only-secret',
            'services.kaspi.production_base_url' => 'https://autohimiki.kz']);
        Http::preventStrayRequests();
    }

    private function row(string $sku): array
    {
        return ['sku' => $sku, 'name' => 'Product', 'storefront_url' => 'https://autohimiki.kz/product/test'];
    }

    public function test_bearer_https_exact_sku_and_pagination(): void
    {
        Http::fake(['https://autohimiki.kz/api/internal/kaspi-content/candidates*' => Http::sequence()
            ->push(['data' => [$this->row('РТ-00001158')], 'next_cursor' => 10])
            ->push(['data' => [$this->row('000Ab  БК')], 'next_cursor' => null])]);
        $result = app(KaspiProductionCandidateClient::class)->fetch(['limit' => 2]);
        $this->assertSame(['РТ-00001158', '000Ab  БК'], array_column($result, 'sku'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-only-secret')
            && $request->method() === 'GET' && $request['cursor'] === 10 && $request['limit'] === 1);
    }

    public function test_exact_sku_filter_is_sent_and_response_is_verified(): void
    {
        Http::fake(['*' => Http::response(['data' => [$this->row('РТ-00001158')], 'next_cursor' => null])]);
        $result = app(KaspiProductionCandidateClient::class)->fetch(['sku' => 'РТ-00001158', 'limit' => 1]);
        $this->assertSame('РТ-00001158', $result[0]['sku']);
        Http::assertSent(fn ($request) => $request['sku'] === 'РТ-00001158');
    }

    public function test_errors_do_not_echo_response_or_token(): void
    {
        Http::fake(['*' => Http::sequence()->push(['secret' => 'test-only-secret'], 401)
            ->push([], 302, ['Location' => 'https://evil.test'])->push([], 500)]);
        foreach ([401, 302, 500] as $status) {
            try {
                app(KaspiProductionCandidateClient::class)->fetch();
                $this->fail('Expected HTTP failure');
            } catch (\RuntimeException $e) {
                $this->assertSame('candidate_http_'.$status, $e->getMessage());
            }
        }
    }

    public function test_malformed_and_unsafe_responses_fail_closed(): void
    {
        $sequence = Http::sequence();
        Http::fake(['*' => $sequence]);
        foreach ([['wrong' => []], ['data' => [], 'next_cursor' => 1],
            ['data' => [['sku' => 'sku', 'name' => 'Name', 'storefront_url' => 'https://evil.test/product/a']], 'next_cursor' => null],
            ['data' => [$this->row('a'), $this->row('a')], 'next_cursor' => null]] as $body) {
            $sequence->push($body);
            try {
                app(KaspiProductionCandidateClient::class)->fetch();
                $this->fail('Expected schema failure');
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('candidate_invalid_', $e->getMessage());
            }
        }
    }

    public function test_connection_timeout_and_http_configuration(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame(30, $options['timeout']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);
            throw new ConnectionException('untrusted error with test-only-secret');
        });
        $this->expectExceptionMessage('candidate_connection_failed_or_timeout');
        app(KaspiProductionCandidateClient::class)->fetch();
    }

    public function test_missing_token_and_insecure_base_do_not_send_request(): void
    {
        Http::fake();
        foreach ([['services.kaspi.internal_api_token' => ''],
            ['services.kaspi.internal_api_token' => 'token', 'services.kaspi.production_base_url' => 'http://autohimiki.kz']] as $config) {
            config($config);
            try {
                app(KaspiProductionCandidateClient::class)->fetch();
                $this->fail('Expected configuration error');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        Http::assertNothingSent();
    }
}
