<?php

namespace Tests\Feature;

use App\Models\OzonCategoryMapping;
use App\Models\OzonProductLink;
use App\Models\Product;
use App\Services\Ozon\OzonClient;
use App\Services\Ozon\OzonExporter;
use App\Services\Ozon\OzonPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OzonExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php',
            '2026_09_23_000001_create_ozon_export_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Очистители салона', 'slug' => 'interior'],
            ['id' => 2, 'name' => 'Other', 'slug' => 'other'],
        ]);
        config(['app.url' => 'https://autohimiki.kz', 'ozon.enabled' => true,
            'ozon.api_key' => 'secret-test-key', 'ozon.client_id' => 'test-client',
            'ozon.vat' => '0', 'ozon.warehouse_id' => 99, 'ozon.backoff_ms' => 0]);
        Http::preventStrayRequests();
        $this->mapping();
    }

    private function mapping(): OzonCategoryMapping
    {
        return OzonCategoryMapping::firstOrCreate(['local_category_id' => 1], [
            'ozon_description_category_id' => 123, 'ozon_type_id' => 456, 'enabled' => true,
        ]);
    }

    private function product(string $sku = 'SKU-001', array $extra = []): Product
    {
        $id = DB::table('products')->insertGetId(array_merge([
            'category_id' => 1, 'sku' => $sku, 'slug' => $sku, 'name' => 'Очиститель салона',
            'price' => 1500, 'quantity' => 7, 'is_active' => true, 'description' => 'Описание товара',
            'attributes' => json_encode(['Объём' => '500 мл'], JSON_UNESCAPED_UNICODE),
            'main_image' => 'catalog/main.jpg',
        ], $extra));

        return Product::findOrFail($id);
    }

    private function link(Product $product, array $extra = []): OzonProductLink
    {
        return OzonProductLink::create(array_merge([
            'local_product_id' => $product->id, 'offer_id' => $product->sku,
            'ozon_product_id' => 1001, 'status' => 'requires_manual_review',
        ], $extra));
    }

    private function fakeCreate(): void
    {
        Http::fake([
            '*/v3/product/list' => Http::response(['result' => ['items' => [], 'total' => 0]]),
            '*/v3/product/import' => Http::response(['result' => ['task_id' => 987]]),
        ]);
    }

    public function test_only_active_products_in_exact_category_are_imported(): void
    {
        $this->fakeCreate();
        $this->product();
        $this->product('OTHER', ['category_id' => 2]);
        $this->product('INACTIVE', ['is_active' => false]);
        $this->artisan('ozon:export-category --category=interior')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/product/import') && $r['items'][0]['offer_id'] === 'SKU-001');
        $this->assertSame(1, OzonProductLink::count());
    }

    public function test_payload_preserves_sku_name_description_attributes_images_and_mapping(): void
    {
        $product = $this->product('РТ-0001');
        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => 'gallery/two.jpg']);
        $item = app(OzonPayload::class)->create($product, $this->mapping());
        $this->assertSame('РТ-0001', $item['offer_id']);
        $this->assertSame($product->name, $item['name']);
        $this->assertSame('1500.00', $item['price']);
        $this->assertSame(123, $item['description_category_id']);
        $this->assertSame(456, $item['type_id']);
        $this->assertSame('https://autohimiki.kz/storage/catalog/main.jpg', $item['primary_image']);
        $this->assertContains('https://autohimiki.kz/storage/gallery/two.jpg', $item['images']);
        $this->assertSame("Описание товара\n\nХарактеристики:\nОбъём: 500 мл", $item['attributes'][0]['values'][0]['value']);
        foreach (['stock', 'quantity', 'country', 'certificate', 'tn_ved'] as $key) {
            $this->assertArrayNotHasKey($key, $item);
        }
    }

    public function test_foreign_images_are_excluded(): void
    {
        $product = $this->product('FOREIGN', ['main_image' => 'https://kaspi.kz/img/test.jpg']);
        $this->assertSame([], app(OzonPayload::class)->images($product));
    }

    public function test_dry_run_makes_no_http_requests_or_writes(): void
    {
        Http::fake();
        $this->product();
        config(['ozon.enabled' => false, 'ozon.api_key' => null]);
        $this->artisan('ozon:export-category --category=interior --dry-run')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame(0, OzonProductLink::count());
    }

    public function test_missing_mapping_is_reported_without_network(): void
    {
        Http::fake();
        $this->product();
        OzonCategoryMapping::query()->delete();
        $this->artisan('ozon:export-category --category=interior --dry-run')->expectsOutputToContain('Ozon mapping: MISSING')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_bad_product_does_not_abort_category_and_missing_content_is_allowed(): void
    {
        $this->fakeCreate();
        $this->product('BAD', ['price' => 0]);
        $this->product('GOOD', ['description' => null, 'main_image' => null]);
        $this->artisan('ozon:export-category --category=interior')->assertFailed();
        $this->assertSame('GOOD', OzonProductLink::sole()->offer_id);
        Http::assertSentCount(2);
    }

    public function test_local_existing_link_never_receives_price_or_content_update(): void
    {
        Http::fake();
        $product = $this->product();
        $this->link($product);
        DB::table('products')->where('id', $product->id)->update(['price' => 9999, 'description' => 'Changed']);
        $this->artisan('ozon:export-category --category=interior')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_remote_existing_product_is_linked_without_sending_price(): void
    {
        $product = $this->product();
        Http::fake(['*/v3/product/list' => Http::response(['result' => ['items' => [['offer_id' => $product->sku, 'product_id' => 42]], 'total' => 1]])]);
        $this->artisan('ozon:export-category --category=interior')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertEquals(42, OzonProductLink::sole()->ozon_product_id);
    }

    public function test_ambiguous_import_is_not_retried_and_next_run_never_resends_price(): void
    {
        $this->product();
        Http::fake([
            '*/v3/product/list' => Http::response(['result' => ['items' => [], 'total' => 0]]),
            '*/v3/product/import' => Http::response(['message' => 'unknown'], 503),
        ]);
        $this->artisan('ozon:export-category --category=interior')->assertFailed();
        $this->artisan('ozon:export-category --category=interior')->assertFailed();
        Http::assertSentCount(2);
        $this->assertSame('error', OzonProductLink::sole()->status);
        $this->assertNotNull(OzonProductLink::sole()->create_attempted_at);
    }

    public function test_unknown_lookup_response_never_allows_import(): void
    {
        $this->product();
        Http::fake(['*/v3/product/list' => Http::response(['result' => []])]);
        $this->artisan('ozon:export-category --category=interior')->assertFailed();
        Http::assertSentCount(1);
        $this->assertSame(0, OzonProductLink::count());
    }

    public function test_stock_is_held_before_publication_even_with_positive_quantity(): void
    {
        Http::fake();
        $this->link($this->product());
        $this->artisan('ozon:sync-stocks --category=interior')->expectsOutputToContain('Stock updated: 0')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_published_stocks_use_product_quantity_including_zero_and_negative_without_price(): void
    {
        Http::fake(fn ($r) => Http::response(['result' => [['offer_id' => $r['stocks'][0]['offer_id'], 'updated' => true, 'errors' => []]]]));
        foreach ([7, 0, -3] as $i => $quantity) {
            $product = $this->product('STOCK-'.$i);
            $product->quantity = $quantity;
            $link = $this->link($product, ['status' => 'published', 'publication_confirmed_at' => now()]);
            $this->assertTrue(app(OzonExporter::class)->stock($product, $link));
            $this->assertSame(max(0, $quantity), $link->fresh()->desired_quantity);
            $this->assertNotNull($link->fresh()->last_stock_sync_at);
        }
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r['stocks'][0]['stock'] === 0);
        foreach (Http::recorded() as [$request]) {
            $this->assertStringEndsWith('/v2/products/stocks', $request->url());
            $this->assertArrayNotHasKey('price', $request['stocks'][0]);
            $this->assertSame(99, $request['stocks'][0]['warehouse_id']);
        }
    }

    public function test_stock_item_error_is_not_success_and_next_product_continues(): void
    {
        foreach (['FIRST', 'SECOND'] as $sku) {
            $this->link($this->product($sku), ['status' => 'published', 'publication_confirmed_at' => now()]);
        }
        Http::fakeSequence()->push(['result' => [['offer_id' => 'FIRST', 'updated' => false, 'errors' => [['code' => 'x']]]]])
            ->push(['result' => [['offer_id' => 'SECOND', 'updated' => true, 'errors' => []]]]);
        $this->artisan('ozon:sync-stocks --category=interior')->assertFailed();
        $this->assertNull(OzonProductLink::where('offer_id', 'FIRST')->first()->last_stock_sync_at);
        $this->assertNotNull(OzonProductLink::where('offer_id', 'SECOND')->first()->last_stock_sync_at);
    }

    public function test_read_requests_retry_429_and_5xx_with_bounded_attempts(): void
    {
        Http::fakeSequence()->push([], 429)->push([], 503)->push(['result' => ['items' => [], 'total' => 0]]);
        $this->assertNull(app(OzonClient::class)->find('SKU'));
        Http::assertSentCount(3);
    }

    public function test_api_key_and_response_body_are_not_logged_or_persisted(): void
    {
        Log::spy();
        $this->product();
        Http::fake([
            '*/v3/product/list' => Http::response(['result' => ['items' => [], 'total' => 0]]),
            '*/v3/product/import' => Http::response(['message' => 'secret-test-key'], 400),
        ]);
        $this->artisan('ozon:export-category --category=interior')->expectsOutputToContain('ozon_http_400')->assertFailed();
        $this->assertStringNotContainsString('secret-test-key', OzonProductLink::sole()->last_error);
        foreach (['debug', 'info', 'warning', 'error', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_category_tree_and_price_endpoints_are_not_callable(): void
    {
        Http::fake();
        foreach (['/v1/description-category/tree', '/v1/product/import/prices', '/v2/product/import'] as $path) {
            try {
                app(OzonClient::class)->request($path, []);
                $this->fail('Endpoint should be rejected');
            } catch (\RuntimeException $e) {
                $this->assertSame('ozon_endpoint_not_allowed', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_import_task_is_reconciled_without_price_or_stock_writes(): void
    {
        $link = $this->link($this->product(), ['ozon_product_id' => null, 'import_task_id' => 987, 'status' => 'exported']);
        Http::fake(['*/v1/product/import/info' => Http::response(['result' => ['items' => [['offer_id' => $link->offer_id, 'product_id' => 1001, 'status' => 'imported', 'errors' => []]]]])]);
        $this->artisan('ozon:refresh-imports --sku=SKU-001')->assertSuccessful();
        $this->assertSame('requires_manual_review', $link->fresh()->status);
        $this->assertNull($link->fresh()->publication_confirmed_at);
        Http::assertSentCount(1);
    }

    public function test_import_item_error_and_processing_are_not_created_cards(): void
    {
        $link = $this->link($this->product(), ['ozon_product_id' => null, 'import_task_id' => 987, 'status' => 'exported']);
        Http::fakeSequence()->push(['result' => ['items' => [['offer_id' => $link->offer_id, 'product_id' => 0, 'status' => 'pending', 'errors' => []]]]])
            ->push(['result' => ['items' => [['offer_id' => $link->offer_id, 'product_id' => 0, 'errors' => [['message' => 'secret-test-key']]]]]]);
        app(OzonExporter::class)->refresh($link);
        $this->assertSame('exported', $link->fresh()->status);
        app(OzonExporter::class)->refresh($link);
        $this->assertSame('error', $link->fresh()->status);
        $this->assertStringNotContainsString('secret-test-key', $link->fresh()->last_error);
    }

    public function test_single_sku_and_limit_controls(): void
    {
        $this->fakeCreate();
        $this->product('ONE');
        $this->product('TWO');
        $this->artisan('ozon:export-category --category=interior --sku=TWO --limit=1')->assertSuccessful();
        $this->assertSame('TWO', OzonProductLink::sole()->offer_id);
    }

    public function test_changed_sku_is_not_reimported(): void
    {
        Http::fake();
        $product = $this->product();
        $this->link($product, ['offer_id' => 'OLD-SKU']);
        $this->artisan('ozon:export-category --category=interior')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_disabled_api_never_sends_products(): void
    {
        Http::fake();
        config(['ozon.enabled' => false]);
        $this->product();
        $this->artisan('ozon:export-category --category=interior')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_mapping_is_saved_locally_without_api(): void
    {
        Http::fake();
        $this->artisan('ozon:map-category interior 765 432 --enable')->assertSuccessful();
        $this->assertEquals(765, $this->mapping()->ozon_description_category_id);
        Http::assertNothingSent();
    }

    public function test_publication_requires_explicit_operator_confirmation(): void
    {
        Http::fake();
        $link = $this->link($this->product());
        $this->artisan('ozon:confirm-published SKU-001')->assertFailed();
        $this->assertNull($link->fresh()->publication_confirmed_at);
        $this->artisan('ozon:confirm-published SKU-001 --confirmed-in-cabinet')->assertSuccessful();
        $this->assertSame('published', $link->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_image_checks_use_no_seller_credentials_and_report_failures(): void
    {
        $product = $this->product();
        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => 'gallery/missing.jpg']);
        Http::fake([
            'https://autohimiki.kz/storage/catalog/main.jpg' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
            'https://autohimiki.kz/storage/gallery/missing.jpg' => Http::response('', 404),
        ]);
        $this->artisan('ozon:check-images --category=interior --sku=SKU-001')->expectsOutputToContain('Image checks failed: 1')->assertFailed();
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('HEAD', $request->method());
            $this->assertFalse($request->hasHeader('Api-Key'));
            $this->assertFalse($request->hasHeader('Client-Id'));
        }
    }

    public function test_stock_dry_run_does_not_send_or_change_links(): void
    {
        Http::fake();
        $link = $this->link($this->product(), ['status' => 'published', 'publication_confirmed_at' => now()]);
        $this->artisan('ozon:sync-stocks --category=interior --dry-run')->assertSuccessful();
        $this->assertNull($link->fresh()->last_stock_sync_at);
        Http::assertNothingSent();
    }

    public function test_export_dry_run_works_before_ozon_migration(): void
    {
        Http::fake();
        $this->product();
        (require database_path('migrations/2026_09_23_000001_create_ozon_export_tables.php'))->down();
        $this->artisan('ozon:export-category --category=interior --dry-run')->expectsOutputToContain('Ozon mapping: MISSING')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_pending_claim_blocks_duplicate_even_without_task_id(): void
    {
        Http::fake();
        $product = $this->product();
        $this->link($product, ['status' => 'pending', 'ozon_product_id' => null, 'create_attempted_at' => now()]);
        $this->assertSame('existing', app(OzonExporter::class)->export($product, $this->mapping()));
        Http::assertNothingSent();
    }

    public function test_missing_tax_data_is_not_invented(): void
    {
        Http::fake();
        $this->product();
        config(['ozon.vat' => null]);
        $this->artisan('ozon:export-category --category=interior')->expectsOutputToContain('ozon_vat_missing')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_relative_encoded_traversal_is_not_an_image_url(): void
    {
        $product = $this->product('TRAVERSAL', ['main_image' => '%2e%2e/private.jpg']);
        $this->assertSame([], app(OzonPayload::class)->images($product));
    }
}
