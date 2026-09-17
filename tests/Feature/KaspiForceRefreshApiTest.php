<?php

namespace Tests\Feature;

use App\Services\CacheService;
use App\Services\Kaspi\KaspiContentRefreshService;
use App\Services\Kaspi\KaspiRefreshPolicy;
use App\Services\Kaspi\KaspiSecureImageDownloader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KaspiForceRefreshApiTest extends TestCase
{
    private const API = '/api/internal/kaspi-content/import';

    private const CDN = 'https://resources.cdn-kaspi.kz/img/m/p/';

    private int $id;

    private string $old;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.kaspi.production_base_url' => 'https://autohimiki.kz', 'services.kaspi.internal_api_token' => 'test-secret',
            'services.kaspi.merchant_id' => 'merchant', 'services.kaspi.city_id' => 'city']);
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php', '2025_01_003_create_products_table.php',
            '2025_01_004_create_product_images_table.php', '2025_01_012_create_redirects_table.php', '2025_01_014_create_import_batches_table.php',
            '2026_09_02_000001_add_commercial_import_ledger.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        DB::table('categories')->insert(['id' => 1, 'name' => 'Category', 'slug' => 'category']);
        DB::table('brands')->insert(['id' => 1, 'name' => 'Brand', 'slug' => 'brand']);
        $this->id = DB::table('products')->insertGetId(['sku' => 'sku-1', 'name' => 'Manual', 'slug' => 'manual', 'category_id' => 1, 'brand_id' => 1,
            'price' => 500, 'old_price' => 600, 'quantity' => 7, 'in_stock' => true, 'is_active' => true, 'is_new' => true, 'is_hit' => true, 'is_popular' => true,
            'description' => 'Old description', 'attributes' => '{"Цвет":"old","Объем":"stale","sku":"system-value","Нестандартная характеристика":"stale"}',
            'canonical_url' => 'https://autohimiki.kz/product/manual', 'meta_title' => 'SEO title', 'meta_description' => 'SEO description',
            'h1' => 'H1', 'seo_text' => 'SEO text', 'short_description' => 'Short', 'usage_instructions' => 'Usage', 'faq' => '["FAQ"]',
            'main_image_alt' => 'Manual alt', 'views' => 19, 'sort_order' => 2, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-02-01 00:00:00']);
        Storage::fake('public');
        $this->old = 'products/kaspi/'.$this->id.'/'.hash('sha256', $this->image(0)).'.png';
        Storage::disk('public')->put($this->old, $this->image(0));
        Storage::disk('public')->put('manual-gallery.png', $this->image(4));
        DB::table('products')->where('id', $this->id)->update(['main_image' => $this->old, 'main_image_webp' => 'old-derivative.webp']);
        DB::table('product_images')->insert(['product_id' => $this->id, 'path' => 'manual-gallery.png', 'sort_order' => 9]);
        DB::table('redirects')->insert(['from_url' => '/product/old', 'to_url' => '/product/manual']);
        $file = DB::table('onec_files')->insertGetId(['sha256' => str_repeat('a', 64), 'filename' => 'one.xlsx', 'source_mtime' => 1, 'total_rows' => 1]);
        $batch = DB::table('import_batches')->insertGetId(['filename' => 'one.xlsx', 'filepath' => 'protected', 'source' => 'onec', 'onec_file_id' => $file]);
        DB::table('import_commercial_rows')->insert(['onec_file_id' => $file, 'import_batch_id' => $batch, 'product_id' => $this->id,
            'sku' => 'sku-1', 'row_number' => 1, 'status' => 'updated', 'diagnostics' => '[]', 'created_at' => '2025-01-01 00:00:00']);
        // No Ozon schema exists in this project. A sentinel demonstrates unrelated-table isolation.
        Schema::create('ozon_records', function (Blueprint $t) {
            $t->id();
            $t->string('mapping');
        });
        DB::table('ozon_records')->insert(['mapping' => 'untouched']);
        Http::preventStrayRequests();
        $this->app->instance(KaspiSecureImageDownloader::class, new class extends KaspiSecureImageDownloader
        {
            protected function networkOptions(): array
            {
                return [];
            }
        });
        Http::fake(fn ($request) => Http::response($this->image(str_contains($request->url(), 'two') ? 2 : (str_contains($request->url(), 'three') ? 3 : 1))));
        $this->withToken('test-secret');
        Cache::put(CacheService::KEY_HOMEPAGE_HITS, 'old');
        Cache::put(CacheService::KEY_HOMEPAGE_NEW, 'old');
        Cache::put(CacheService::KEY_SITEMAP_PRODUCTS, 'old-image-url');
        Cache::put(CacheService::KEY_SETTINGS, 'preserve');
        Cache::put(CacheService::KEY_REDIRECTS, 'preserve');
    }

    private function image(int $color): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagesetpixel($image, 0, 0, imagecolorallocate($image, $color * 40, 0, 0));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function payload(): array
    {
        $product = DB::table('products')->where('id', $this->id)->first();

        return ['version' => 1, 'force_content_refresh' => true, 'product_id' => $this->id,
            'state_fingerprint' => KaspiRefreshPolicy::state($product)['state_fingerprint'], 'sku' => 'sku-1',
            'storefront_url' => 'https://autohimiki.kz/product/manual', 'kaspi_url' => 'https://kaspi.kz/shop/p/cleaner-123/',
            'source' => ['collector' => 'local-playwright', 'resolver_verified' => true, 'captcha' => false, 'merchant_id' => 'merchant', 'city_id' => 'city'],
            'content' => ['title' => 'Kaspi title', 'description' => '<p onclick="bad()">New description</p>',
                'images' => [self::CDN.'one.png', self::CDN.'two.png', self::CDN.'three.png', self::CDN.'duplicate.png'],
                'attributes' => [['name' => 'Цвет', 'value' => 'New']]]];
    }

    private function snapshot(): array
    {
        $tables = [];
        foreach (['products', 'product_images', 'redirects', 'onec_files', 'import_batches', 'import_commercial_rows', 'import_run_locks', 'ozon_records'] as $table) {
            $tables[$table] = DB::table($table)->get()->toJson();
        }
        $files = [];
        foreach (Storage::disk('public')->allFiles() as $path) {
            $files[$path] = Storage::disk('public')->get($path);
        }
        ksort($files);

        return [$tables, $files];
    }

    public function test_full_replacement_order_dedupe_protected_fields_records_cleanup_cache_and_repeat(): void
    {
        $before = $this->snapshot();
        $productBefore = (array) DB::table('products')->first();
        $this->postJson(self::API, $this->payload())->assertOk()->assertJsonPath('status', 'imported')->assertJsonPath('cleanup_warnings', []);
        $product = (array) DB::table('products')->first();
        foreach ($productBefore as $key => $value) {
            if (! in_array($key, ['description', 'attributes', 'main_image', 'main_image_webp'], true)) {
                $this->assertSame($value, $product[$key], $key);
            }
        }
        $this->assertSame('<p>New description</p>', $product['description']);
        $this->assertSame(['Цвет' => 'New'], json_decode($product['attributes'], true));
        $this->assertStringContainsString(hash('sha256', $this->image(1)), $product['main_image']);
        $gallery = DB::table('product_images')->orderBy('sort_order')->get();
        $this->assertCount(2, $gallery);
        $this->assertStringContainsString(hash('sha256', $this->image(2)), $gallery[0]->path);
        $this->assertStringContainsString(hash('sha256', $this->image(3)), $gallery[1]->path);
        $this->assertSame(0, $gallery[0]->sort_order);
        $this->assertSame(1, $gallery[1]->sort_order);
        $this->assertNull($product['main_image_webp']);
        Storage::disk('public')->assertMissing($this->old);
        Storage::disk('public')->assertExists('manual-gallery.png');
        foreach ($before[0] as $table => $value) {
            if (! in_array($table, ['products', 'product_images'], true)) {
                $this->assertSame($value, DB::table($table)->get()->toJson(), $table);
            }
        }
        $this->assertNull(Cache::get(CacheService::KEY_HOMEPAGE_HITS));
        $this->assertNull(Cache::get(CacheService::KEY_HOMEPAGE_NEW));
        $this->assertNull(Cache::get(CacheService::KEY_SITEMAP_PRODUCTS));
        $this->assertSame('preserve', Cache::get(CacheService::KEY_SETTINGS));
        $this->assertSame('preserve', Cache::get(CacheService::KEY_REDIRECTS));
        $after = $this->snapshot();
        $this->postJson(self::API, $this->payload())->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame($after, $this->snapshot());
    }

    public static function requestedSkus(): array
    {
        return [['РТ-00001286'], ['РТ-00000960'], ['РТ-00001093']];
    }

    public function test_explicit_file_execution_clears_description_updates_media_attributes_and_preserves_protected_data(): void
    {
        $before = $this->snapshot();
        $productBefore = (array) DB::table('products')->first();
        $payload = $this->payload();
        $payload['allow_empty_description'] = true;
        $payload['content']['description'] = '<script>empty()</script>';
        $this->postJson(self::API, $payload)->assertOk()->assertJsonPath('status', 'imported');
        $product = (array) DB::table('products')->first();
        $this->assertSame('', $product['description']);
        $this->assertSame(['Цвет' => 'New'], json_decode($product['attributes'], true));
        $this->assertStringContainsString(hash('sha256', $this->image(1)), $product['main_image']);
        $this->assertNull($product['main_image_webp']);
        $gallery = DB::table('product_images')->orderBy('sort_order')->get();
        $this->assertCount(2, $gallery);
        $this->assertStringContainsString(hash('sha256', $this->image(2)), $gallery[0]->path);
        $this->assertStringContainsString(hash('sha256', $this->image(3)), $gallery[1]->path);
        foreach ($productBefore as $key => $value) {
            if (! in_array($key, ['description', 'attributes', 'main_image', 'main_image_webp'], true)) {
                $this->assertSame($value, $product[$key], $key);
            }
        }
        foreach ($before[0] as $table => $value) {
            if (! in_array($table, ['products', 'product_images'], true)) {
                $this->assertSame($value, DB::table($table)->get()->toJson(), $table);
            }
        }
    }

    public static function invalidEmptyDescriptionOptIns(): array
    {
        return [[true, 'true'], [true, 1], [true, null], [false, true]];
    }

    #[DataProvider('invalidEmptyDescriptionOptIns')]
    public function test_empty_description_opt_in_requires_force_and_strict_boolean(bool $force, mixed $flag): void
    {
        $payload = $this->payload();
        $payload['force_content_refresh'] = $force;
        $payload['allow_empty_description'] = $flag;
        $before = $this->snapshot();
        $this->postJson(self::API, $payload)->assertUnprocessable()->assertJsonPath('error', 'invalid_payload');
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    #[DataProvider('requestedSkus')]
    public function test_ordinary_unfamiliar_characteristics_replace_entire_object(string $sku): void
    {
        // Illustrative fixtures, not captured production payloads.
        DB::table('products')->where('id', $this->id)->update(['sku' => $sku]);
        $payload = $this->payload();
        $payload['sku'] = $sku;
        $expected = [];
        foreach (['Бренд', 'Тип', 'Объем', 'Назначение', 'Страна производства', 'Форма выпуска',
            'Цвет', 'Материал', 'Аромат', 'Особенности', 'Комплектация', 'Применение',
            'Совместимость с покрытиями', 'type'] as $name) {
            $expected[$name] = 'Новое значение';
        }
        $payload['content']['attributes'] = array_map(
            fn ($name, $value) => ['name' => '  '.$name.'  ', 'value' => ' '.$value.' '],
            array_keys($expected), array_values($expected));
        $this->postJson(self::API, $payload)->assertOk()->assertJsonPath('status', 'imported');
        $this->assertSame($expected, json_decode(DB::table('products')->value('attributes'), true));
    }

    public static function invalidContent(): array
    {
        return ['empty description' => ['description', '', 'empty_description'],
            'sanitized empty' => ['description', '<script>bad()</script>', 'empty_description'],
            'empty attributes' => ['attributes', [], 'empty_attributes'],
            'malformed attributes' => ['attributes', 'invalid', 'invalid_payload'],
            'empty value' => ['attributes', [['name' => 'Цвет', 'value' => '']], 'attributes_invalid'],
            'system incoming key' => ['attributes', [['name' => ' PRODUCT_ID ', 'value' => 'v']], 'commercial_attribute_not_allowed'],
            'no images' => ['images', [], 'no_images'],
            'too many images' => ['images', array_map(fn ($n) => self::CDN.$n.'.png', range(1, 13)), 'image_limit_exceeded'],
            'too many attributes' => ['attributes', array_fill(0, 81, ['name' => 'Цвет', 'value' => 'v']), 'attribute_limit_exceeded']];
    }

    #[DataProvider('invalidContent')]
    public function test_incomplete_or_invalid_content_changes_nothing(string $field, mixed $value, string $reason): void
    {
        $payload = $this->payload();
        $payload['content'][$field] = $value;
        $before = $this->snapshot();
        $this->postJson(self::API, $payload)->assertUnprocessable()->assertJsonPath('error', $reason);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('old', Cache::get(CacheService::KEY_HOMEPAGE_HITS));
        Http::assertNothingSent();
    }

    public static function malformedAttributes(): array
    {
        return [['{"":"value"}'], ['["value"]'], ['{"Цвет":{"nested":true}}'], ['{"Цвет":"a"," цвет ":"b"}'], ['invalid']];
    }

    #[DataProvider('malformedAttributes')]
    public function test_malformed_existing_attributes_skip_whole_product(string $json): void
    {
        DB::table('products')->where('id', $this->id)->update(['attributes' => $json]);
        $before = $this->snapshot();
        $this->postJson(self::API, $this->payload())->assertUnprocessable()->assertJsonPath('error', 'attributes_invalid');
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_second_download_failure_removes_staged_files_without_changes_or_cache_invalidation(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()->push($this->image(1))->push('failed', 503)]);
        $before = $this->snapshot();
        $this->postJson(self::API, $this->payload())->assertUnprocessable()->assertJsonPath('error', 'image_http_failed');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('old', Cache::get(CacheService::KEY_HOMEPAGE_HITS));
    }

    public function test_invalid_image_is_rejected_without_changes(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('<html>not an image</html>')]);
        $before = $this->snapshot();
        $this->postJson(self::API, $this->payload())->assertUnprocessable()->assertJsonPath('error', 'image_invalid_mime');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_storage_failure_preserves_all_old_content(): void
    {
        $disk = Storage::disk('public');
        $before = $this->snapshot();
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('put')->andReturn(false);
        Storage::set('public', $mock);
        $this->postJson(self::API, $this->payload())->assertUnprocessable()->assertJsonPath('error', 'image_storage_write_failed');
        Storage::set('public', $disk);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_database_failure_rolls_back_gallery_and_cleans_prepared_files(): void
    {
        DB::unprepared("CREATE TRIGGER reject_gallery BEFORE INSERT ON product_images BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $before = $this->snapshot();
        $this->postJson(self::API, $this->payload())->assertStatus(500);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_shared_owned_file_is_never_deleted(): void
    {
        DB::table('products')->insert(['sku' => 'other', 'slug' => 'other', 'name' => 'Other', 'category_id' => 1, 'main_image_webp' => $this->old]);
        $this->postJson(self::API, $this->payload())->assertOk();
        Storage::disk('public')->assertExists($this->old);
    }

    public function test_cleanup_failure_is_separate_from_successful_commit(): void
    {
        $this->app->instance(KaspiContentRefreshService::class, new class(app(KaspiSecureImageDownloader::class)) extends KaspiContentRefreshService
        {
            protected function deleteOwnedUnreferenced(string $path, int $id): void
            {
                throw new \RuntimeException('disk failure secret');
            }
        });
        $this->postJson(self::API, $this->payload())->assertOk()->assertJsonPath('status', 'imported')
            ->assertJsonPath('cleanup_warnings', ['obsolete_media_cleanup_failed']);
        $this->assertSame('<p>New description</p>', DB::table('products')->value('description'));
    }

    public function test_state_identity_and_lock_drift_fail_before_download(): void
    {
        $payload = $this->payload();
        DB::table('products')->where('id', $this->id)->update(['description' => 'Edited without timestamp']);
        $this->postJson(self::API, $payload)->assertStatus(409)->assertJsonPath('error', 'state_changed');
        $payload = $this->payload();
        $payload['product_id']++;
        $this->postJson(self::API, $payload)->assertStatus(409)->assertJsonPath('error', 'identity_changed');
        $lock = Cache::lock('kaspi-1c-import-'.hash('sha256', 'sku-1'), 300);
        $lock->get();
        try {
            $this->postJson(self::API, $this->payload())->assertStatus(409)->assertJsonPath('error', 'import_locked');
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_state_is_rechecked_after_download_and_new_files_are_removed(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $payload = $this->payload();
        Http::fake(function () {
            DB::table('products')->where('id', $this->id)->update(['description' => 'Concurrent edit']);

            return Http::response($this->image(1));
        });
        $oldFiles = $this->snapshot()[1];
        $this->postJson(self::API, $payload)->assertStatus(409)->assertJsonPath('error', 'state_changed');
        $this->assertSame('Concurrent edit', DB::table('products')->value('description'));
        $this->assertSame($oldFiles, $this->snapshot()[1]);
        $this->assertSame('manual-gallery.png', DB::table('product_images')->value('path'));
    }

    public function test_force_selection_and_preview_are_read_only_and_complete_products_included(): void
    {
        $before = $this->snapshot();
        $this->getJson('/api/internal/kaspi-content/candidates')->assertOk()->assertJsonCount(0, 'data');
        $row = $this->getJson('/api/internal/kaspi-content/candidates?force_content_refresh=true')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $preview = $this->getJson(self::API.'?sku=sku-1&force_content_refresh=true')->assertOk()->json();
        $this->assertSame($row['state_fingerprint'], $preview['state_fingerprint']);
        $this->assertSame(2, $preview['current_photo_count']);
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
        $this->assertSame('old', Cache::get(CacheService::KEY_HOMEPAGE_HITS));
        DB::table('products')->where('id', $this->id)->update(['is_active' => false]);
        $this->getJson('/api/internal/kaspi-content/candidates?force_content_refresh=true')->assertJsonCount(0, 'data');
    }

    public static function malformedFlags(): array
    {
        return [['true'], [1], [0], [null], [[]]];
    }

    #[DataProvider('malformedFlags')]
    public function test_force_flag_must_be_a_json_boolean(mixed $flag): void
    {
        $payload = $this->payload();
        $payload['force_content_refresh'] = $flag;
        $this->postJson(self::API, $payload)->assertUnprocessable()->assertJsonPath('error', 'invalid_force_flag');
        Http::assertNothingSent();
    }

    public function test_absent_and_false_use_normal_preserve_append_merge_behavior(): void
    {
        $payload = $this->payload();
        unset($payload['force_content_refresh'], $payload['product_id'], $payload['state_fingerprint']);
        $payload['content']['attributes'][] = ['name' => 'Особенности', 'value' => 'Added'];
        $this->postJson(self::API, $payload)->assertOk()->assertJsonPath('main_image', 'preserved')->assertJsonPath('description', 'preserved');
        $this->assertSame($this->old, DB::table('products')->value('main_image'));
        $this->assertSame('Old description', DB::table('products')->value('description'));
        $attributes = json_decode(DB::table('products')->value('attributes'), true);
        $this->assertSame('old', $attributes['Цвет']);
        $this->assertSame('stale', $attributes['Объем']);
        $this->assertSame('Added', $attributes['Особенности']);
        $this->assertSame(4, DB::table('product_images')->count());
        $before = $this->snapshot();
        $payload['force_content_refresh'] = false;
        $this->postJson(self::API, $payload)->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_force_security_and_protected_field_injection(): void
    {
        $payload = $this->payload();
        $before = $this->snapshot();
        $this->withToken('bad')->postJson(self::API, $payload)->assertUnauthorized();
        $this->withToken('test-secret');
        $this->app->instance('env', 'production');
        $this->postJson('http://localhost'.self::API, $payload)->assertForbidden();
        $this->app->instance('env', 'testing');
        foreach (['merchant_id', 'city_id'] as $key) {
            $bad = $payload;
            $bad['source'][$key] = 'wrong';
            $this->postJson(self::API, $bad)->assertUnprocessable();
        }
        foreach (['sku' => 'other', 'storefront_url' => 'https://autohimiki.kz/product/other'] as $key => $value) {
            $bad = $payload;
            $bad[$key] = $value;
            $this->postJson(self::API, $bad)->assertStatus(409);
        }
        foreach (['price', 'name', 'slug', 'category_id', 'updated_at', 'onec_id', 'ozon_id', 'meta_title'] as $key) {
            foreach (['root', 'content', 'source'] as $where) {
                $bad = $payload;
                if ($where === 'root') {
                    $bad[$key] = 'bad';
                } else {
                    $bad[$where][$key] = 'bad';
                }
                $this->postJson(self::API, $bad)->assertUnprocessable();
            }
        }
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_payload_limit_is_reported_without_truncation(): void
    {
        $payload = $this->payload();
        $payload['content']['description'] = str_repeat('x', 140000);
        $this->postJson(self::API, $payload)->assertUnprocessable()->assertJsonPath('error', 'payload_too_large');
        Http::assertNothingSent();
    }
}
