<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiProductionCandidateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KaspiCandidatesApiTest extends TestCase
{
    private const ENDPOINT = '/api/internal/kaspi-content/candidates';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::disk('public')->put('manual.jpg', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jF1sAAAAASUVORK5CYII='));
        config(['services.kaspi.internal_api_token' => 'test-only-secret',
            'services.kaspi.production_base_url' => 'https://autohimiki.kz']);
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('categories')->insert(['id' => 1, 'name' => 'Category', 'slug' => 'category']);
    }

    private function product(string $sku, array $overrides = []): int
    {
        return DB::table('products')->insertGetId(array_replace(['category_id' => 1, 'sku' => $sku,
            'name' => 'Manual name', 'slug' => 'product-'.bin2hex(random_bytes(4)), 'is_active' => true,
            'price' => 5500, 'quantity' => 7, 'in_stock' => true, 'main_image' => null,
            'description' => 'Manual description', 'attributes' => '{"manual":"value"}',
            'meta_title' => 'SEO', 'updated_at' => '2026-01-01 00:00:00'], $overrides));
    }

    public function test_unauthorized_and_unconfigured_token(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->withToken('wrong')->getJson(self::ENDPOINT)->assertUnauthorized();
        config(['services.kaspi.internal_api_token' => '']);
        $this->withToken('test-only-secret')->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_active_missing_content_exact_sku_and_read_only_response(): void
    {
        $id = $this->product('РТ-00001158');
        $this->product('000Ab  БК', ['main_image' => 'manual.jpg', 'description' => null]);
        $this->product('hidden', ['is_active' => false]);
        $this->product('   ');
        $complete = $this->product('complete', ['main_image' => 'manual.jpg']);
        DB::table('product_images')->insert(['product_id' => $complete, 'path' => 'manual.jpg']);
        $this->product('bad-slug', ['slug' => '']);
        DB::table('product_images')->insert(['product_id' => $id, 'path' => 'manual-gallery.jpg']);
        $before = DB::table('products')->get()->toJson();
        $images = DB::table('product_images')->get()->toJson();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(update|insert|delete)/i', $query->sql) && str_contains($query->sql, 'product')) {
                $writes[] = $query->sql;
            }
        });
        $response = $this->withToken('test-only-secret')->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['РТ-00001158', '000Ab  БК'], array_column($response->json('data'), 'sku'));
        $this->assertSame(['sku', 'name', 'storefront_url', 'has_images', 'has_main_image', 'gallery_count', 'has_description', 'has_attributes', 'main_image_file_exists'], array_keys($response->json('data.0')));
        $this->assertStringStartsWith('https://autohimiki.kz/product/', $response->json('data.0.storefront_url'));
        $this->assertNull($response->json('next_cursor'));
        $this->assertSame([], $writes);
        $this->assertSame($before, DB::table('products')->get()->toJson());
        $this->assertSame($images, DB::table('product_images')->get()->toJson());
        $this->withToken('test-only-secret')->getJson(self::ENDPOINT.'?sku='.rawurlencode('РТ-00001158'))->assertJsonCount(1, 'data');
        $this->withToken('test-only-secret')->getJson(self::ENDPOINT.'?sku='.rawurlencode('рт-00001158'))->assertJsonCount(0, 'data');
    }

    public function test_cursor_pagination_and_invalid_parameters(): void
    {
        $first = $this->product('first');
        $this->product('excluded', ['is_active' => false]);
        $this->product('second');
        $one = $this->withToken('test-only-secret')->getJson(self::ENDPOINT.'?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($first, $one->json('next_cursor'));
        $two = $this->getJson(self::ENDPOINT.'?limit=1&cursor='.$first)->assertOk()->assertJsonPath('data.0.sku', 'second');
        $this->assertNull($two->json('next_cursor'));
        foreach (['limit=0', 'limit=101', 'limit=abc', 'cursor=-1', 'sku[]=x', 'sku='] as $query) {
            $this->getJson(self::ENDPOINT.'?'.$query)->assertUnprocessable();
        }
    }

    public function test_production_requires_https_and_does_not_trust_spoofed_forwarded_proto(): void
    {
        $this->app->instance('env', 'production');
        $this->withToken('test-only-secret')->getJson('http://localhost'.self::ENDPOINT)->assertForbidden();
        $this->withHeader('X-Forwarded-Proto', 'https')->getJson('http://localhost'.self::ENDPOINT)->assertForbidden();
        $this->getJson('https://localhost'.self::ENDPOINT)->assertOk();
    }

    public function test_rate_limit_is_60_requests_per_minute(): void
    {
        $this->withToken('test-only-secret');
        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::ENDPOINT)->assertOk();
        }
        $this->getJson(self::ENDPOINT)->assertStatus(429);
    }

    public function test_exact_sku_overrides_content_filter_with_gallery_without_writes_or_image_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        foreach (['РТ-00000074', 'РТ-00001158'] as $sku) {
            $id = $this->product($sku, ['main_image' => 'manual.jpg', 'description' => 'Filled description']);
            DB::table('product_images')->insert([
                ['product_id' => $id, 'path' => 'one.jpg'],
                ['product_id' => $id, 'path' => 'two.jpg'],
            ]);
        }
        $before = DB::table('products')->get()->toJson();
        $images = DB::table('product_images')->get()->toJson();
        $this->withToken('test-only-secret')->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(0, 'data');
        foreach (['РТ-00000074', 'РТ-00001158'] as $sku) {
            $response = $this->getJson(self::ENDPOINT.'?sku='.rawurlencode($sku).'&limit=1')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', $sku)
                ->assertJsonPath('data.0.has_main_image', true)
                ->assertJsonPath('data.0.gallery_count', 2)
                ->assertJsonPath('data.0.has_description', true);
            $this->assertSame(['sku', 'name', 'storefront_url', 'has_images', 'has_main_image', 'gallery_count', 'has_description', 'has_attributes', 'main_image_file_exists'], array_keys($response->json('data.0')));
        }
        $this->assertSame($before, DB::table('products')->get()->toJson());
        $this->assertSame($images, DB::table('product_images')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_exact_sku_does_not_override_active_existence_or_routable_slug(): void
    {
        $this->product('inactive', ['is_active' => false]);
        $this->product('no-slug', ['slug' => '']);
        $this->withToken('test-only-secret');
        foreach (['inactive', 'nonexistent', 'no-slug'] as $sku) {
            $this->getJson(self::ENDPOINT.'?sku='.$sku)->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_mass_policy_includes_empty_attributes_empty_gallery_and_broken_main(): void
    {
        $complete = ['main_image' => 'manual.jpg', 'description' => 'Filled', 'attributes' => '{"manual":"yes"}'];
        foreach (['complete' => [], 'attributes-empty' => ['attributes' => '{}'], 'gallery-empty' => [],
            'file-missing' => ['main_image' => 'absent.jpg'], 'file-broken' => ['main_image' => 'broken.jpg'],
            'description-empty' => ['description' => '']] as $sku => $overrides) {
            $id = $this->product($sku, array_replace($complete, $overrides));
            if ($sku !== 'gallery-empty') {
                DB::table('product_images')->insert(['product_id' => $id, 'path' => 'manual.jpg']);
            }
        }
        Storage::disk('public')->put('broken.jpg', '<html>not an image</html>');
        $response = $this->withToken('test-only-secret')->getJson(self::ENDPOINT)->assertOk();
        $this->assertSame(['attributes-empty', 'gallery-empty', 'file-missing', 'file-broken', 'description-empty'], array_column($response->json('data'), 'sku'));
        $this->assertFalse($response->json('data.0.has_attributes'));
        $this->assertFalse($response->json('data.3.main_image_file_exists'));
    }

    public function test_all_forward_traversal_does_not_skip_products_removed_from_candidate_set_by_import(): void
    {
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids['sku'.$n] = $this->product('sku'.$n);
        }
        $service = app(KaspiProductionCandidateService::class);
        $guard = \Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed');
        $this->app->instance(KaspiLocalBrowserGuard::class, $guard);
        $cursors = [];
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($service, &$cursors) {
            $this->assertSame('GET', $request->method());
            $cursors[] = $request['cursor'];

            return Http::response($service->list(['cursor' => $request['cursor'], 'limit' => 2]));
        });
        $processed = [];
        $bridge = \Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldReceive('prepareCandidate')->andReturnUsing(function ($row, $debug, $resolved) {
            $resolved();

            return ['preview' => [], 'payload' => $row];
        });
        $bridge->shouldReceive('send')->andReturnUsing(function ($row) use (&$processed, $ids) {
            $processed[] = $row['sku'];
            // Simulate the real import's eligibility changes before the next page is fetched.
            DB::table('products')->where('id', $ids[$row['sku']])->update(['main_image' => 'manual.jpg']);
            DB::table('product_images')->insert(['product_id' => $ids[$row['sku']], 'path' => 'manual.jpg']);

            return ['sku' => $row['sku'], 'status' => 'imported'];
        });
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);
        $this->assertSame(0, Artisan::call('kaspi:push-production', ['--all' => true]));
        $this->assertSame(array_keys($ids), $processed);
        $this->assertSame([0, $ids['sku2'], $ids['sku4']], $cursors);
        $this->assertSame([], $service->list()['data']);
    }
}
