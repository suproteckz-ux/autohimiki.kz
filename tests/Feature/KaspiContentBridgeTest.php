<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiLocalNodeProcessRunner;
use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiSingleProductPolicy as Policy;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class KaspiContentBridgeTest extends TestCase
{
    private function setupBridge(bool $captcha = false): void
    {
        config(['services.kaspi.internal_api_token' => 'test-php-only-secret', 'services.kaspi.production_base_url' => 'https://autohimiki.kz',
            'services.kaspi.merchant_id' => 'Avtoximiya', 'services.kaspi.city_id' => '750000000']);
        $guard = Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed');
        $this->app->instance(KaspiLocalBrowserGuard::class, $guard);
        $runner = Mockery::mock(KaspiLocalNodeProcessRunner::class);
        $runner->shouldReceive('run')->once()->with(['url' => Policy::STOREFRONT, 'sku' => Policy::SKU, 'merchant' => 'Avtoximiya', 'city' => '750000000', 'headless' => 'false'])
            ->andReturn(['exit_code' => 0, 'stdout' => json_encode(['status' => 'resolved', 'captcha' => false, 'url' => Policy::URL])]);
        $html = '<body><script>BACKEND.components.item = '.json_encode(['card' => ['id' => '142775620', 'title' => 'Cleaner'],
            'description' => 'Useful cleaner', 'primaryImage' => ['large' => 'https://resources.cdn-kaspi.kz/img/m/p/test.png']]).';</script></body>';
        $runner->shouldReceive('collect')->once()->with(['url' => Policy::URL, 'headless' => 'false'])
            ->andReturn(['exit_code' => 0, 'stdout' => json_encode(['status' => 'ok', 'captcha' => $captcha, 'html' => $html, 'http_status' => 200, 'final_url' => Policy::URL])]);
        $this->app->instance(KaspiLocalNodeProcessRunner::class, $runner);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-php-only-secret'));
            if (str_contains($request->url(), '/candidates')) {
                $this->assertSame(Policy::SKU, $request['sku']);

                return Http::response(['data' => [['sku' => Policy::SKU, 'name' => 'Manual name', 'storefront_url' => Policy::STOREFRONT]], 'next_cursor' => null]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['sku' => Policy::SKU, 'main_image_action' => 'replace_broken_or_empty', 'description_action' => 'preserve', 'existing_description_length' => 25]);
            }
            $this->assertSame(Policy::SKU, $request['sku']);
            $this->assertSame(['title', 'description', 'images'], array_keys($request['content']));

            return Http::response(['sku' => Policy::SKU, 'status' => 'imported', 'description' => 'preserved', 'main_image' => 'replaced', 'gallery_added' => 1]);
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
}
