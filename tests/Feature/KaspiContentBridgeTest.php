<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiLocalNodeProcessRunner;
use App\Services\Kaspi\KaspiLocalPageCollector;
use App\Services\Kaspi\KaspiLocalUrlResolver;
use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiProductionCandidateClient;
use App\Services\Kaspi\KaspiProductionPayloadValidator;
use App\Services\Kaspi\KaspiSingleProductPolicy as Policy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

class KaspiContentBridgeTest extends TestCase
{
    private function setupBridge(bool $captcha = false, string $sku = Policy::SKU, string $storefront = Policy::STOREFRONT, string $url = Policy::URL, string $id = '142775620'): void
    {
        Sleep::fake();
        config(['services.kaspi.internal_api_token' => 'test-php-only-secret', 'services.kaspi.production_base_url' => 'https://autohimiki.kz',
            'services.kaspi.merchant_id' => 'Avtoximiya', 'services.kaspi.city_id' => '750000000']);
        $guard = Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed');
        $this->app->instance(KaspiLocalBrowserGuard::class, $guard);
        $runner = Mockery::mock(KaspiLocalNodeProcessRunner::class);
        $runner->shouldReceive('run')->once()->with(['url' => $storefront, 'sku' => $sku, 'merchant' => 'Avtoximiya', 'city' => '750000000', 'headless' => 'false'])
            ->andReturn(['exit_code' => 0, 'stdout' => json_encode(['status' => 'resolved', 'captcha' => false, 'url' => $url])]);
        $html = '<body><script>BACKEND.components.item = '.json_encode(['card' => ['id' => $id, 'title' => 'Cleaner'],
            'description' => 'Useful cleaner', 'primaryImage' => ['large' => 'https://resources.cdn-kaspi.kz/img/m/p/test.png']]).';</script></body>';
        $runner->shouldReceive('collect')->once()->with(['url' => $url, 'headless' => 'false'])
            ->andReturn(['exit_code' => 0, 'stdout' => json_encode(['status' => 'ok', 'captcha' => $captcha, 'html' => $html, 'http_status' => 200, 'final_url' => $url])]);
        $this->app->instance(KaspiLocalNodeProcessRunner::class, $runner);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($sku, $storefront) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-php-only-secret'));
            if (str_contains($request->url(), '/candidates')) {
                $this->assertSame($sku, $request['sku']);

                return Http::response(['data' => [['sku' => $sku, 'name' => 'Manual name', 'storefront_url' => $storefront]], 'next_cursor' => null]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['sku' => $sku, 'main_image_action' => 'replace_broken_or_empty', 'description_action' => 'preserve', 'existing_description_length' => 25]);
            }
            $this->assertSame($sku, $request['sku']);
            $this->assertSame(['title', 'description', 'images', 'attributes'], array_keys($request['content']));

            return Http::response(['sku' => $sku, 'status' => 'imported', 'description' => 'preserved', 'main_image' => 'replaced', 'gallery_added' => 1]);
        });
    }

    public function test_complete_pipeline_preview_then_post_with_token_only_in_php(): void
    {
        $this->setupBridge();
        $bridge = app(KaspiProductionBridgeService::class);
        $prepared = $bridge->prepare(Policy::SKU, true);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame('replace_broken_or_empty', $prepared['preview']['main_image_action']);
        $this->assertSame('preserve', $prepared['preview']['description_action']);
        $this->assertSame('merge_preserve_existing', $prepared['preview']['attributes_policy']);
        $this->assertSame([], $prepared['payload']['content']['attributes']);
        $result = $bridge->send($prepared['payload']);
        $this->assertSame('imported', $result['status']);
        Http::assertSentCount(3);
    }

    public function test_captcha_stops_pipeline_before_preview_or_import(): void
    {
        $this->setupBridge(true);
        try {
            app(KaspiProductionBridgeService::class)->prepare(Policy::SKU);
            $this->fail('Expected CAPTCHA failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('captcha_detected', $e->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_shared_resolver_failure_reason_survives_push_without_collection_or_post(): void
    {
        Sleep::fake();
        config(['services.kaspi.production_base_url' => 'https://autohimiki.kz',
            'services.kaspi.merchant_id' => 'Avtoximiya', 'services.kaspi.city_id' => '750000000']);
        Http::preventStrayRequests();
        Http::fake();
        $guard = Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed');
        $client = Mockery::mock(KaspiProductionCandidateClient::class);
        $candidate = ['sku' => Policy::SKU, 'name' => 'Manual name', 'storefront_url' => Policy::STOREFRONT];
        $client->shouldReceive('fetch')->with(['sku' => Policy::SKU, 'limit' => 1])->times(3)->andReturn([$candidate]);
        $runner = Mockery::mock(KaspiLocalNodeProcessRunner::class);
        foreach (['iframe_not_loaded', 'widget_not_found', 'timeout'] as $status) {
            $runner->shouldReceive('run')->once()->ordered()->with(['url' => Policy::STOREFRONT, 'sku' => Policy::SKU,
                'merchant' => 'Avtoximiya', 'city' => '750000000', 'headless' => 'false'])
                ->andReturn(['exit_code' => 1, 'stdout' => json_encode(['status' => $status, 'captcha' => false, 'url' => null])]);
        }
        $collector = Mockery::mock(KaspiLocalPageCollector::class);
        $collector->shouldNotReceive('collectUrl');
        $resolver = new KaspiLocalUrlResolver($runner, $guard);
        $bridge = new KaspiProductionBridgeService($guard, $client, $resolver, $collector, app(KaspiProductionPayloadValidator::class));
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);
        foreach (['iframe_not_loaded', 'widget_not_found', 'timeout'] as $status) {
            $this->artisan('kaspi:push-production', ['--sku' => Policy::SKU, '--dry-run' => true, '--debug' => true])
                ->expectsOutput('resolver_not_verified_'.$status)->assertFailed();
        }
        Http::assertNothingSent();
    }

    public function test_arbitrary_sku_uses_same_resolver_collector_validation_and_http_flow(): void
    {
        $sku = 'РТ-00000074';
        $url = 'https://kaspi.kz/shop/p/other-cleaner-987654/';
        $this->setupBridge(false, $sku, 'https://autohimiki.kz/product/other-cleaner', $url, '987654');
        $bridge = app(KaspiProductionBridgeService::class);
        $prepared = $bridge->prepare($sku);
        $this->assertSame($sku, $prepared['payload']['sku']);
        $this->assertSame($url, $prepared['payload']['kaspi_url']);
        $this->assertSame('imported', $bridge->send($prepared['payload'])['status']);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(1);
    }
}
