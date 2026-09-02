<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiSecureImageDownloader;
use App\Services\Kaspi\KaspiSingleProductPolicy as Policy;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KaspiImportApiTest extends TestCase
{
    private const ENDPOINT = '/api/internal/kaspi-content/import';

    private const IMAGE = 'https://resources.cdn-kaspi.kz/img/m/p/test.png';

    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kaspi.internal_api_token' => 'test-secret', 'services.kaspi.merchant_id' => 'Avtoximiya', 'services.kaspi.city_id' => '750000000']);
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php', '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('categories')->insert(['id' => 1, 'name' => 'Manual category', 'slug' => 'manual']);
        $this->id = DB::table('products')->insertGetId(['sku' => Policy::SKU, 'slug' => Policy::SLUG, 'name' => 'Manual name', 'category_id' => 1,
            'is_active' => true, 'price' => 5000, 'quantity' => 9, 'in_stock' => true, 'main_image' => 'missing.jpg', 'main_image_webp' => 'missing.webp',
            'description' => 'Manual description', 'attributes' => '{"manual":"preserved"}', 'meta_title' => 'SEO', 'updated_at' => '2026-01-01 00:00:00']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->app->instance(KaspiSecureImageDownloader::class, new class extends KaspiSecureImageDownloader
        {
            protected function networkOptions(): array
            {
                return [];
            }
        });
    }

    private function bytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jF1sAAAAASUVORK5CYII=');
    }

    private function payload(): array
    {
        return ['version' => 1, 'sku' => Policy::SKU, 'storefront_url' => Policy::STOREFRONT, 'kaspi_url' => Policy::URL,
            'source' => ['collector' => 'local-playwright', 'resolver_verified' => true, 'captcha' => false, 'merchant_id' => 'Avtoximiya', 'city_id' => '750000000'],
            'content' => ['title' => 'Kaspi title', 'description' => '<p>Kaspi description</p>', 'images' => [self::IMAGE]]];
    }

    public function test_auth_https_and_read_only_preview(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())->assertUnauthorized();
        $this->withToken('wrong')->postJson(self::ENDPOINT, $this->payload())->assertUnauthorized();
        $this->app->instance('env', 'production');
        $this->withToken('test-secret')->postJson('http://localhost'.self::ENDPOINT, $this->payload())->assertForbidden();
        $before = DB::table('products')->first();
        $this->getJson('https://localhost'.self::ENDPOINT.'?sku='.Policy::SKU)->assertOk()->assertJsonPath('main_image_action', 'replace_broken_or_empty')->assertJsonPath('description_action', 'preserve');
        $this->assertEquals($before, DB::table('products')->first());
        Http::assertNothingSent();
    }

    public function test_forbidden_unknown_inactive_and_wrong_storefront(): void
    {
        $payload = $this->payload();
        $payload['sku'] = 'other';
        $this->withToken('test-secret')->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonPath('error', 'sku_not_allowed_for_kaspi_1c');
        DB::table('products')->where('id', $this->id)->update(['is_active' => false]);
        $this->postJson(self::ENDPOINT, $this->payload())->assertNotFound();
        DB::table('products')->where('id', $this->id)->update(['is_active' => true, 'slug' => 'wrong']);
        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(409);
        DB::table('products')->delete();
        $this->postJson(self::ENDPOINT, $this->payload())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_malformed_commercial_and_nested_unknown_fields_fail(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withToken('test-secret');
        foreach (['price', 'quantity', 'in_stock', 'slug', 'category_id', 'meta_title', 'is_active', 'onec_id', 'updated_at', 'name'] as $field) {
            foreach (['root', 'content', 'source'] as $location) {
                $payload = $this->payload();
                if ($location === 'root') {
                    $payload[$field] = 1;
                } else {
                    $payload[$location][$field] = 1;
                }
                $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable();
            }
        }
        foreach ([[], ['sku' => Policy::SKU], array_replace($this->payload(), ['version' => 2]), array_replace($this->payload(), ['content' => 'invalid'])] as $payload) {
            $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable();
        }
        $payload = $this->payload();
        $payload['content']['images'] = [['url' => self::IMAGE, 'price' => 1]];
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_broken_main_replaced_all_other_fields_preserved_and_repeat_idempotent(): void
    {
        Http::fake(['*' => Http::response($this->bytes(), 200, ['Content-Type' => 'image/png'])]);
        $before = (array) DB::table('products')->first();
        $this->withToken('test-secret')->postJson(self::ENDPOINT, $this->payload())->assertOk()->assertJsonPath('main_image', 'replaced')->assertJsonPath('description', 'preserved')->assertJsonPath('gallery_added', 1);
        $after = (array) DB::table('products')->first();
        foreach ($before as $key => $value) {
            if (! in_array($key, ['main_image', 'main_image_webp'], true)) {
                $this->assertSame($value, $after[$key], $key);
            }
        }
        Storage::disk('public')->assertExists($after['main_image']);
        $this->assertNull($after['main_image_webp']);
        $this->assertNull(DB::table('product_images')->value('path_webp'));
        $snapshot = DB::table('product_images')->get()->toJson();
        $this->postJson(self::ENDPOINT, $this->payload())->assertOk()->assertJsonPath('status', 'unchanged')->assertJsonPath('gallery_added', 0);
        $this->assertSame($snapshot, DB::table('product_images')->get()->toJson());
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_valid_manual_main_and_gallery_preserved_hash_dedupes_different_urls(): void
    {
        Storage::disk('public')->put('manual.png', $this->bytes());
        DB::table('products')->where('id', $this->id)->update(['main_image' => 'manual.png', 'main_image_webp' => null]);
        DB::table('product_images')->insert(['product_id' => $this->id, 'path' => 'manual.png', 'alt' => 'Manual alt', 'sort_order' => 10]);
        Http::fake(['*' => Http::response($this->bytes())]);
        $payload = $this->payload();
        $payload['content']['images'][] = 'https://resources.cdn-kaspi.kz/img/m/p/another.png';
        $this->withToken('test-secret')->postJson(self::ENDPOINT, $payload)->assertOk()->assertJsonPath('gallery_added', 0)->assertJsonPath('main_image', 'preserved');
        $this->assertSame('manual.png', DB::table('products')->value('main_image'));
        $this->assertSame('Manual alt', DB::table('product_images')->value('alt'));
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_empty_description_filled_and_html_sanitized(): void
    {
        DB::table('products')->where('id', $this->id)->update(['description' => null]);
        Http::fake(['*' => Http::response($this->bytes())]);
        $payload = $this->payload();
        $payload['content']['description'] = '<p onclick="bad()" style="display:none">Good</p><script>bad()</script><img src=x onerror=bad()>';
        $this->withToken('test-secret')->postJson(self::ENDPOINT, $payload)->assertOk()->assertJsonPath('description', 'updated');
        $this->assertSame('<p>Good</p>', DB::table('products')->value('description'));
    }

    public function test_second_download_failure_rolls_back_database_and_new_files_only(): void
    {
        Storage::disk('public')->put('manual.txt', 'keep');
        Http::fake(['*' => Http::sequence()->push($this->bytes())->push('unavailable', 503)]);
        $payload = $this->payload();
        $payload['content']['images'][] = 'https://resources.cdn-kaspi.kz/img/m/p/second.png';
        $before = DB::table('products')->first();
        $this->withToken('test-secret')->postJson(self::ENDPOINT, $payload)->assertUnprocessable();
        $this->assertEquals($before, DB::table('products')->first());
        $this->assertSame(0, DB::table('product_images')->count());
        $this->assertSame(['manual.txt'], Storage::disk('public')->allFiles());
    }

    public function test_html_mime_oversize_redirect_and_unexpected_host_rejected(): void
    {
        $this->withToken('test-secret');
        Http::fake(['*' => Http::sequence()->push('<html>CAPTCHA</html>', 200, ['Content-Type' => 'image/png'])->push(str_repeat('x', KaspiSecureImageDownloader::MAX_BYTES + 1))->push('', 302, ['Location' => 'https://localhost/private'])]);
        foreach (range(1, 3) as $unused) {
            $this->postJson(self::ENDPOINT, $this->payload())->assertUnprocessable();
        }
        $payload = $this->payload();
        $payload['content']['images'] = ['https://resources.cdn-kaspi.kz.evil.test/img/m/p/test.png'];
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable();
        $this->assertSame('missing.jpg', DB::table('products')->value('main_image'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, DB::table('product_images')->count());
    }

    public function test_import_rate_limit(): void
    {
        $this->withToken('test-secret');
        for ($i = 0; $i < 6; $i++) {
            $this->getJson(self::ENDPOINT.'?sku='.Policy::SKU)->assertOk();
        }
        $this->getJson(self::ENDPOINT.'?sku='.Policy::SKU)->assertStatus(429);
    }

    public function test_identity_checks_exact_raw_sku_and_concurrent_import_lock(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withToken('test-secret');
        foreach (['sku' => ' '.Policy::SKU.' ', 'kaspi_url' => str_replace('142775620', '123', Policy::URL), 'storefront_url' => Policy::STOREFRONT.'-other'] as $key => $value) {
            $this->postJson(self::ENDPOINT, array_replace($this->payload(), [$key => $value]))->assertUnprocessable();
        }
        foreach (['captcha' => true, 'resolver_verified' => false, 'merchant_id' => 'wrong', 'city_id' => 'wrong'] as $key => $value) {
            $payload = $this->payload();
            $payload['source'][$key] = $value;
            $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable();
        }
        $lock = Cache::lock('kaspi-1c-import-'.Policy::SKU, 300);
        $this->assertTrue($lock->get());
        try {
            $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(409)->assertJsonPath('error', 'import_in_progress');
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_valid_manual_main_kept_while_new_gallery_added_and_db_failure_cleans_files(): void
    {
        Storage::disk('public')->put('manual.png', 'manual image bytes');
        DB::table('products')->where('id', $this->id)->update(['main_image' => 'manual.png', 'main_image_webp' => null]);
        Http::fake(['*' => Http::response($this->bytes())]);
        $this->withToken('test-secret')->postJson(self::ENDPOINT, $this->payload())->assertOk()->assertJsonPath('gallery_added', 1)->assertJsonPath('main_image', 'preserved');
        $this->assertSame('manual.png', DB::table('products')->value('main_image'));
        $this->assertSame('manual image bytes', Storage::disk('public')->get('manual.png'));
    }

    public function test_database_insert_failure_removes_new_file(): void
    {
        Http::fake(['*' => Http::response($this->bytes())]);
        DB::statement("CREATE TRIGGER reject_gallery BEFORE INSERT ON product_images BEGIN SELECT RAISE(ABORT, 'test insert failure'); END");
        $before = DB::table('products')->first();
        $this->withToken('test-secret')->postJson(self::ENDPOINT, $this->payload())->assertStatus(500)->assertJsonPath('error', 'import_failed');
        $this->assertEquals($before, DB::table('products')->first());
        $this->assertSame(0, DB::table('product_images')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
