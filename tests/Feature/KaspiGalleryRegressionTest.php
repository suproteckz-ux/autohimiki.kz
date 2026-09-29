<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiContentRefreshService;
use App\Services\Kaspi\KaspiEnrichmentParser;
use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiLocalNodeProcessRunner;
use App\Services\Kaspi\KaspiLocalUrlResolver;
use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiSecureImageDownloader;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class KaspiGalleryRegressionTest extends TestCase
{
    private const URL = 'https://kaspi.kz/shop/p/ma-fra-trjapka-dp-470-1-sht-169459233/';

    private function images(): array
    {
        return array_map(fn ($n) => 'https://resources.cdn-kaspi.kz/img/m/p/gallery-test-'.$n.'.png', range(1, 7));
    }

    // Synthetic fixture: reproduces incomplete BACKEND data, not a captured live Kaspi page.
    private function html(string $source): string
    {
        $images = $this->images();
        $item = ['card' => ['id' => '169459233', 'title' => 'DP-470'],
            'primaryImage' => ['large' => $images[0]], 'description' => 'Микрофибра для автомобиля.',
            'specifications' => [['features' => [['name' => 'Цвет', 'featureValues' => [['value' => 'серый']]]]]]];
        if ($source === 'backend') {
            $item['galleryImages'] = array_map(fn ($url) => ['large' => $url, 'small' => $url.'?format=gallery-small'], $images);
        }
        $html = '<body><script>BACKEND.components.item = '.json_encode($item).';</script>';
        if ($source === 'json') {
            $html .= '<script type="application/ld+json">'.json_encode(['@type' => 'Product', 'url' => self::URL, 'image' => $images]).'</script>';
            $html .= '<script type="application/ld+json">'.json_encode(['@type' => 'Product', 'url' => 'https://kaspi.kz/shop/p/other-123/', 'image' => 'https://resources.cdn-kaspi.kz/img/m/p/other.png']).'</script>';
        }
        if ($source === 'dom') {
            $html .= '<div class="item__gallery">';
            foreach ($images as $url) {
                $html .= '<img data-src="'.$url.'?format=gallery-small">';
            }
            $html .= '</div>';
        }

        return $html.'<img src="https://resources.cdn-kaspi.kz/img/m/p/recommendation.png"></body>';
    }

    public function test_complete_backend_gallery_keeps_seven_unique_photos(): void
    {
        $parsed = app(KaspiEnrichmentParser::class)->parse($this->html('backend'), self::URL, true);
        $this->assertSame($this->images(), $parsed['images']);
    }

    public function test_primary_backend_image_does_not_hide_matching_json_gallery(): void
    {
        $parsed = app(KaspiEnrichmentParser::class)->parse($this->html('json'), self::URL, true);
        $this->assertSame($this->images(), $parsed['images']);
        $this->assertStringContainsString('Микрофибра', $parsed['description']);
        $this->assertSame([['name' => 'Цвет', 'value' => 'серый']], $parsed['attributes']);
    }

    public function test_primary_backend_image_does_not_hide_scoped_dom_gallery(): void
    {
        $parsed = app(KaspiEnrichmentParser::class)->parse($this->html('dom'), self::URL);
        $this->assertCount(7, $parsed['images']);
        $this->assertSame(array_map(fn ($url) => parse_url($url, PHP_URL_PATH), $this->images()), array_map(fn ($url) => parse_url($url, PHP_URL_PATH), $parsed['images']));
    }

    public function test_force_refresh_preserves_seven_photos_through_bridge_endpoint_storage_and_storefront(): void
    {
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php',
            '2025_01_012_create_redirects_table.php', '2025_01_013_create_settings_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.kaspi.production_base_url' => 'https://autohimiki.kz', 'services.kaspi.internal_api_token' => 'local-test-secret',
            'services.kaspi.merchant_id' => 'test-merchant', 'services.kaspi.city_id' => 'test-city']);
        Sleep::fake();
        Storage::fake('public');
        $db = DB::class;
        $db::table('categories')->insert(['id' => 1, 'name' => 'Test category', 'slug' => 'test-category']);
        $db::table('products')->insert(['id' => 612, 'sku' => 'РТ-00001534', 'slug' => 'gallery-regression', 'name' => 'Local product name',
            'category_id' => 1, 'price' => 1500, 'quantity' => 7, 'is_active' => true, 'attributes' => '{}',
            'meta_title' => 'Protected SEO', 'description' => 'Old description']);
        $before = (array) $db::table('products')->find(612);
        $guard = \Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed');
        $this->app->instance(KaspiLocalBrowserGuard::class, $guard);
        $resolver = \Mockery::mock(KaspiLocalUrlResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['status' => 'resolved', 'sku' => 'РТ-00001534',
            'storefront_url' => 'https://autohimiki.kz/product/gallery-regression', 'kaspi_url' => self::URL, 'widget_verified' => true]);
        $this->app->instance(KaspiLocalUrlResolver::class, $resolver);
        $runner = \Mockery::mock(KaspiLocalNodeProcessRunner::class);
        $runner->shouldReceive('collect')->andReturn(['exit_code' => 0, 'stdout' => json_encode(['status' => 'ok', 'captcha' => false,
            'http_status' => 200, 'final_url' => self::URL, 'html' => $this->html('json')])]);
        $this->app->instance(KaspiLocalNodeProcessRunner::class, $runner);
        $downloader = \Mockery::mock(KaspiSecureImageDownloader::class);
        $paths = [];
        foreach ($this->images() as $index => $url) {
            $image = imagecreatetruecolor(1, 1);
            imagesetpixel($image, 0, 0, imagecolorallocate($image, 20 * $index, 50, 100));
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $hash = hash('sha256', $bytes);
            $paths[] = 'products/kaspi/612/'.$hash.'.png';
            $downloader->shouldReceive('download')->with($url)->twice()->andReturn(['bytes' => $bytes, 'hash' => $hash, 'extension' => 'png']);
        }
        $this->app->instance(KaspiSecureImageDownloader::class, $downloader);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertSame('https://autohimiki.kz/api/internal/kaspi-content/import', strtok($request->url(), '?'));
            $this->withToken('local-test-secret');
            if ($request->method() === 'GET') {
                $response = $this->getJson('/api/internal/kaspi-content/import?sku='.rawurlencode('РТ-00001534').'&force_content_refresh=true');
            } else {
                $this->assertCount(7, $request['content']['images']);
                $response = $this->postJson('/api/internal/kaspi-content/import', $request->data());
                $response->assertJsonPath('gallery_added', 6);
            }
            $response->assertOk();

            return Http::response($response->json());
        });
        $bridge = app(KaspiProductionBridgeService::class);
        foreach (['imported', 'unchanged'] as $expected) {
            $candidate = app(KaspiContentRefreshService::class)->preview('РТ-00001534');
            $prepared = $bridge->prepareRefreshCandidate($candidate, false, function ($event, $parsed) {
                if ($event === 'parsed') {
                    $this->assertCount(7, $parsed['images']);
                }
            });
            $this->assertSame(7, $prepared['preview']['kaspi_images_parsed']);
            $this->assertSame(7, $prepared['preview']['images_to_send']);
            $result = $bridge->send($prepared['payload']);
            $this->assertSame($expected, $result['status']);
            $this->assertSame(7, $result['images_sent']);
            $this->assertSame(7, $result['images_stored']);
            $after = (array) $db::table('products')->find(612);
            $this->assertSame($paths[0], $after['main_image']);
            $this->assertSame(array_slice($paths, 1), $db::table('product_images')->orderBy('sort_order')->pluck('path')->all());
            $this->assertSame(range(0, 5), $db::table('product_images')->orderBy('sort_order')->pluck('sort_order')->all());
            $this->assertStringContainsString('Микрофибра', $after['description']);
            $this->assertSame(['Цвет' => 'серый'], json_decode($after['attributes'], true));
            foreach (array_diff(array_keys($before), ['main_image', 'main_image_webp', 'description', 'attributes']) as $key) {
                $this->assertSame($before[$key], $after[$key], 'Protected field: '.$key);
            }
        }
        Http::assertSentCount(4);
        $html = $this->get('/product/gallery-regression')->assertOk()->getContent();
        $this->assertSame(7, substr_count($html, 'data-gallery-thumbnail'));
        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }
}
