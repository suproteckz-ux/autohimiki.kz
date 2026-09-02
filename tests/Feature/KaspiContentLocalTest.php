<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiEnrichmentParser;
use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiLocalNodeProcessRunner;
use App\Services\Kaspi\KaspiLocalPageCollector;
use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiSingleProductPolicy as Policy;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class KaspiContentLocalTest extends TestCase
{
    private function html(): string
    {
        $item = ['card' => ['id' => '142775620', 'title' => 'Mitsuji cleaner'],
            'descriptions' => [['text' => '<p>Useful cleaner description</p>']],
            'galleryImages' => [['large' => 'https://resources.cdn-kaspi.kz/img/m/p/one.bin?format=gallery-large', 'small' => 'https://resources.cdn-kaspi.kz/img/m/p/one.bin?format=gallery-small']],
            'specifications' => [['features' => [['name' => 'Объем', 'featureValues' => [['value' => '0.65 л']]]]]]];

        return '<html><body><h1>DOM fallback title</h1><script>BACKEND.components.item = '.json_encode($item).';</script></body></html>';
    }

    public function test_backend_fixture_and_inert_description(): void
    {
        $result = app(KaspiEnrichmentParser::class)->parse($this->html(), Policy::URL);
        $this->assertSame('Mitsuji cleaner', $result['title']);
        $this->assertSame('<p>Useful cleaner description</p>', $result['description']);
        $this->assertSame([['name' => 'Объем', 'value' => '0.65 л']], $result['attributes']);
        $this->assertCount(1, $result['images']);
        $this->assertTrue($result['backend_item_found']);
        $this->assertSame(['url', 'title', 'description', 'images', 'attributes', 'backend_item_found'], array_keys($result));
        $this->assertSame('<p>&lt;script&gt;bad&lt;/script&gt;</p>', Policy::description('<p>&lt;script&gt;bad&lt;/script&gt;</p>'));
    }

    public function test_empty_captcha_wrong_product_and_missing_images_fail(): void
    {
        foreach (['' => 'parser_empty_or_invalid_html', '<body>CAPTCHA</body>' => 'captcha_detected',
            '<body><h1>Product</h1></body>' => 'parser_images_missing', str_replace('142775620', '123456', $this->html()) => 'wrong_product'] as $html => $reason) {
            try {
                app(KaspiEnrichmentParser::class)->parse($html, Policy::URL);
                $this->fail('Expected failure');
            } catch (\RuntimeException $e) {
                $this->assertSame($reason, $e->getMessage());
            }
        }
    }

    public function test_collector_rejects_captcha_even_status_ok_and_does_not_print_raw_output(): void
    {
        $guard = Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed')->once();
        $runner = Mockery::mock(KaspiLocalNodeProcessRunner::class);
        $runner->shouldReceive('collect')->with(['url' => Policy::URL, 'headless' => 'false'])->once()->andReturn(['exit_code' => 0, 'stdout' => json_encode(['status' => 'ok', 'captcha' => true, 'html' => $this->html()])]);
        $collector = new KaspiLocalPageCollector($guard, $runner, new KaspiEnrichmentParser);
        $this->expectExceptionMessage('captcha_detected');
        $collector->collectUrl(Policy::URL);
    }

    public function test_collector_guard_prevents_node(): void
    {
        config(['services.kaspi.local_browser_enabled' => false]);
        $runner = Mockery::mock(KaspiLocalNodeProcessRunner::class);
        $runner->shouldNotReceive('collect');
        $collector = new KaspiLocalPageCollector(new KaspiLocalBrowserGuard, $runner, new KaspiEnrichmentParser);
        $this->expectException(\RuntimeException::class);
        $collector->collectUrl(Policy::URL);
    }

    public function test_missing_or_other_sku_refused_before_any_work(): void
    {
        Http::preventStrayRequests();
        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldNotReceive('prepare');
        $bridge->shouldNotReceive('send');
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);
        $this->artisan('kaspi:push-production')->expectsOutput('sku_not_allowed_for_kaspi_1c')->assertFailed();
        $this->artisan('kaspi:push-production', ['--sku' => '680'])->expectsOutput('sku_not_allowed_for_kaspi_1c')->assertFailed();
    }

    public function test_dry_run_never_sends_and_live_command_sends_prepared_payload(): void
    {
        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldReceive('prepare')->with(Policy::SKU, true)->twice()->andReturn(['preview' => ['sku' => Policy::SKU], 'payload' => ['sku' => Policy::SKU]]);
        $bridge->shouldReceive('send')->with(['sku' => Policy::SKU])->once()->andReturn(['status' => 'imported']);
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);
        $this->artisan('kaspi:push-production', ['--sku' => Policy::SKU, '--dry-run' => true, '--debug' => true])->assertSuccessful();
        $this->artisan('kaspi:push-production', ['--sku' => Policy::SKU, '--debug' => true])->assertSuccessful();
    }

    public function test_json_ld_fallback_excludes_other_products_and_unrelated_dom_images(): void
    {
        $json = ['@graph' => [
            ['@type' => 'Product', 'url' => Policy::URL, 'name' => 'Correct product', 'description' => 'Clean content', 'image' => 'https://resources.cdn-kaspi.kz/img/m/p/correct.png', 'offers' => ['price' => 500]],
            ['@type' => 'Product', 'url' => str_replace('142775620', '123', Policy::URL), 'name' => 'Wrong product', 'image' => 'https://resources.cdn-kaspi.kz/img/m/p/wrong.png'],
        ]];
        $html = '<body><script type="application/ld+json">'.json_encode($json).'</script><img src="https://resources.cdn-kaspi.kz/img/m/p/recommendation.png"></body>';
        $parsed = app(KaspiEnrichmentParser::class)->parse($html, Policy::URL);
        $this->assertSame('Correct product', $parsed['title']);
        $this->assertSame(['https://resources.cdn-kaspi.kz/img/m/p/correct.png'], $parsed['images']);
        $this->assertArrayNotHasKey('offers', $parsed);
        $this->assertFalse(Policy::imageUrl('https://resources.cdn-kaspi.kz/img/m/p/%2e%2e/private.png'));
    }
}
