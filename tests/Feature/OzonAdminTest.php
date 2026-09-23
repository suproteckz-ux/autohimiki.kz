<?php

namespace Tests\Feature;

use App\Filament\Pages\OzonDashboard;
use App\Filament\Pages\OzonProducts;
use App\Models\OzonProductLink;
use App\Models\Product;
use App\Models\User;
use App\Services\Ozon\OzonAdmin;
use App\Services\Ozon\OzonAdminSettings;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OzonAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->rememberToken();
            $table->timestamps();
        });
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php',
            '2025_01_012_create_redirects_table.php', '2025_01_013_create_settings_table.php',
            '2026_09_23_000001_create_ozon_export_tables.php', '2026_09_23_000002_add_ozon_status_details.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        DB::table('categories')->insert([['id' => 1, 'name' => 'Interior', 'slug' => 'interior'], ['id' => 2, 'name' => 'Other', 'slug' => 'other']]);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => 'admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['app.url' => 'https://autohimiki.kz', 'ozon.enabled' => true, 'ozon.api_key' => 'secret-test-key',
            'ozon.client_id' => 'secret-client-id', 'ozon.vat' => '0', 'ozon.warehouse_id' => 1020005000312240, 'ozon.backoff_ms' => 0]);
        app(OzonAdminSettings::class)->saveCategory(123, 456);
        Http::preventStrayRequests();
    }

    private function product(string $sku = 'SKU-001', array $extra = []): Product
    {
        $id = DB::table('products')->insertGetId(array_merge(['category_id' => 1, 'sku' => $sku, 'slug' => $sku,
            'name' => 'Очиститель '.$sku, 'price' => 1500, 'quantity' => 7, 'is_active' => true,
            'description' => 'Описание', 'attributes' => json_encode(['Объём' => '500 мл']), 'main_image' => 'catalog/main.jpg'], $extra));

        return Product::findOrFail($id);
    }

    private function link(Product $product, array $extra = []): OzonProductLink
    {
        return OzonProductLink::create(array_merge(['local_product_id' => $product->id, 'offer_id' => $product->sku,
            'ozon_product_id' => 1001, 'import_task_id' => 987, 'status' => 'requires_manual_review'], $extra));
    }

    private function fakeImport(): void
    {
        Http::fake([
            '*/v3/product/list' => Http::response(['result' => ['items' => [], 'total' => 0]]),
            '*/v1/description-category/attribute' => Http::response(['result' => [['id' => 71001, 'name' => 'Аннотация', 'dictionary_id' => 0]]]),
            '*/v3/product/import' => Http::response(['result' => ['task_id' => 987]]),
        ]);
    }

    public function test_admin_pages_display_settings_and_all_local_categories_without_http_or_secrets(): void
    {
        Http::fake();
        $one = $this->product();
        $two = $this->product('OTHER', ['category_id' => 2]);
        $this->get('/admin/ozon-dashboard')->assertOk()->assertSee('NetBazar')->assertSee('Муратбаева 138')
            ->assertSee('1020005000312240')->assertSee('Очистители салона')->assertSee('Загрузить список из Ozon')
            ->assertSee('Выбрать из Ozon')->assertDontSee('secret-test-key')->assertDontSee('secret-client-id');
        Livewire::test(OzonProducts::class)->assertCanSeeTableRecords([$one, $two])->assertTableActionExists('check')
            ->assertTableBulkActionExists('selected_status')->assertTableBulkActionDoesNotExist('selected_send');
        Http::assertNothingSent();
    }

    public function test_manager_cannot_access_pages_or_admin_operations(): void
    {
        auth()->user()->update(['role' => 'manager']);
        $this->get('/admin/ozon-dashboard')->assertForbidden();
        $this->get('/admin/ozon-products')->assertForbidden();
        $this->expectException(HttpException::class);
        app(OzonAdmin::class)->check($this->product());
    }

    public function test_connection_reads_seller_when_disabled_without_credentials_in_output_or_storage(): void
    {
        config(['ozon.enabled' => false]);
        Http::fake(['*/v1/seller/info' => Http::response(['company' => ['name' => 'NetBazar secret-test-key secret-client-id']])]);
        Log::spy();
        Livewire::test(OzonDashboard::class)->call('checkConnection')->assertSee('Подключено')->assertSee('NetBazar')
            ->assertDontSee('secret-test-key')->assertDontSee('secret-client-id');
        $this->assertStringNotContainsString('secret-test-key', DB::table('settings')->value('value'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->body() === '{}' && str_ends_with($r->url(), '/v1/seller/info'));
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
    }

    public function test_warehouse_check_reads_only_one_page_and_does_not_change_configuration(): void
    {
        Http::fake(['*/v2/warehouse/list' => Http::response(['warehouses' => [['warehouse_id' => 1020005000312240, 'name' => 'Муратбаева 138', 'status' => 'ACTIVE']]])]);
        Livewire::test(OzonDashboard::class)->call('checkWarehouses')->assertSee('Муратбаева 138');
        Http::assertSentCount(1);
        $this->assertSame(1020005000312240, config('ozon.warehouse_id'));
    }

    public function test_fixed_category_is_saved_once_without_local_mapping(): void
    {
        Livewire::test(OzonDashboard::class)
            ->set('categoryFormState.taxonomyMode', 'manual')
            ->set('categoryFormState.categoryId', '999')
            ->set('categoryFormState.typeId', '777')
            ->call('saveCategory')
            ->assertHasNoErrors();
        $this->assertSame(999, app(OzonAdminSettings::class)->mapping()->ozon_description_category_id);
        $this->assertSame(0, DB::table('ozon_category_mappings')->count());
        Livewire::test(OzonDashboard::class)
            ->set('categoryFormState.taxonomyMode', 'manual')
            ->set('categoryFormState.categoryId', '0')
            ->set('categoryFormState.typeId', '1')
            ->call('saveCategory')
            ->assertHasErrors('categoryFormState.categoryId');
    }

    public function test_dry_run_modal_uses_local_payload_data_and_sends_nothing(): void
    {
        Http::fake();
        $product = $this->product();
        Livewire::test(OzonProducts::class)->mountTableAction('check', $product)->assertSee('Описание')->assertSee('500 мл')->assertSee('123')->assertSee('456');
        $this->assertTrue(app(OzonAdmin::class)->canSend($product));
        Http::assertNothingSent();
        $this->assertSame(0, OzonProductLink::count());
    }

    public function test_send_without_dry_run_is_rejected(): void
    {
        Http::fake();
        try {
            app(OzonAdmin::class)->send($this->product());
            $this->fail('Expected check gate');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ozon_check_required', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_changed_product_or_category_invalidates_dry_run(): void
    {
        Http::fake();
        $product = $this->product();
        app(OzonAdmin::class)->check($product);
        DB::table('products')->where('id', $product->id)->update(['price' => 2000]);
        try {
            app(OzonAdmin::class)->send($product);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ozon_check_required', $e->getMessage());
        }
        app(OzonAdmin::class)->check($product);
        app(OzonAdminSettings::class)->saveCategory(555, 666);
        try {
            app(OzonAdmin::class)->send($product);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ozon_check_required', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_missing_global_category_or_photos_blocks_send(): void
    {
        DB::table('settings')->delete();
        $product = $this->product('NO-PHOTO', ['main_image' => null]);
        $report = app(OzonAdmin::class)->check($product);
        $this->assertCount(2, $report['Ошибки']);
        $this->assertFalse(app(OzonAdmin::class)->canSend($product));
    }

    public function test_single_confirmed_action_uses_global_pair_and_saves_task_without_publishing(): void
    {
        $this->fakeImport();
        $product = $this->product('OTHER', ['category_id' => 2]);
        $page = Livewire::test(OzonProducts::class)->mountTableAction('check', $product)->unmountTableAction();
        $page->mountTableAction('send', $product);
        Http::assertNothingSent();
        $page->callMountedTableAction()->assertHasNoErrors();
        $link = $product->ozonLink()->firstOrFail();
        $this->assertSame(987, (int) $link->import_task_id);
        $this->assertSame('exported', $link->status);
        $this->assertNull($link->publication_confirmed_at);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/product/import') && count($r['items']) === 1 && $r['items'][0]['description_category_id'] === 123 && $r['items'][0]['type_id'] === 456);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/product/list') && $r['filter']['offer_id'] === ['OTHER']);
        Http::assertSentCount(3);
    }

    public function test_existing_remote_product_does_not_receive_price_or_import(): void
    {
        Http::fake(['*/v3/product/list' => Http::response(['result' => ['items' => [['offer_id' => 'SKU-001', 'product_id' => 999]], 'total' => 1]])]);
        $product = $this->product();
        app(OzonAdmin::class)->check($product);
        app(OzonAdmin::class)->send($product);
        Http::assertSentCount(1);
        $this->assertSame(999, (int) $product->ozonLink()->first()->ozon_product_id);
        $report = app(OzonAdmin::class)->check($product);
        $this->assertNotEmpty($report['Ошибки']);
        $this->assertFalse(app(OzonAdmin::class)->canSend($product->fresh()));
    }

    public function test_disabled_integration_cannot_write_after_successful_check(): void
    {
        Http::fake();
        $product = $this->product();
        app(OzonAdmin::class)->check($product);
        config(['ozon.enabled' => false]);
        $this->assertFalse(app(OzonAdmin::class)->canSend($product));
        try {
            app(OzonAdmin::class)->send($product);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ozon_disabled', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_status_modal_saves_product_id_and_remote_status_but_not_publication(): void
    {
        $product = $this->product();
        $link = $this->link($product, ['ozon_product_id' => null, 'status' => 'exported']);
        Http::fake(['*/v1/product/import/info' => Http::response(['result' => ['items' => [['offer_id' => $product->sku, 'product_id' => 777, 'status' => 'imported', 'errors' => []]]]])]);
        Livewire::test(OzonProducts::class)->mountTableAction('status', $product)->assertSee('777')->assertSee('imported');
        $link->refresh();
        $this->assertSame(777, (int) $link->ozon_product_id);
        $this->assertSame('requires_manual_review', $link->status);
        $this->assertNotNull($link->last_status_check_at);
        $this->assertNull($link->publication_confirmed_at);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['task_id'] === 987);
    }

    public function test_remote_errors_are_sanitized_displayed_and_not_logged(): void
    {
        $product = $this->product();
        $link = $this->link($product);
        Log::spy();
        Http::fake(['*/v1/product/import/info' => Http::response(['result' => ['items' => [['offer_id' => $product->sku, 'status' => 'failed',
            'errors' => [['code' => 'INVALID_ATTRIBUTE', 'message' => 'Missing required attribute secret-test-key secret-client-id', 'request_headers' => ['Api-Key' => 'secret-test-key'], 'cookie' => 'hidden-cookie']]]]]])]);
        Livewire::test(OzonProducts::class)->mountTableAction('status', $product)->assertSee('Missing required attribute')
            ->assertDontSee('secret-test-key')->assertDontSee('secret-client-id')->assertDontSee('hidden-cookie')->assertDontSee('request_headers');
        $link->refresh();
        $this->assertSame('error', $link->status);
        $this->assertStringNotContainsString('secret-test-key', json_encode($link->getAttributes()));
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
    }

    public function test_processing_is_not_a_created_or_published_card(): void
    {
        $product = $this->product();
        $link = $this->link($product, ['ozon_product_id' => null]);
        Http::fake(['*/v1/product/import/info' => Http::response(['result' => ['items' => [['offer_id' => $product->sku, 'status' => 'processing']]]])]);
        app(OzonAdmin::class)->status($product, true);
        $this->assertSame('processing', $link->fresh()->status);
        $this->assertNull($link->fresh()->ozon_product_id);
    }

    public function test_confirm_requires_explicit_ui_confirmation_and_enables_stock(): void
    {
        Http::fake();
        $product = $this->product();
        $link = $this->link($product);
        $page = Livewire::test(OzonProducts::class)->mountTableAction('confirm', $product);
        $this->assertNull($link->fresh()->publication_confirmed_at);
        $page->callMountedTableAction();
        $this->assertSame('published', $link->fresh()->status);
        $this->assertNotNull($link->fresh()->publication_confirmed_at);
        Http::assertNothingSent();
    }

    public function test_stock_uses_quantity_clamped_at_zero_without_price(): void
    {
        Http::fake(['*/v2/products/stocks' => fn ($request) => Http::response(['result' => [['offer_id' => $request['stocks'][0]['offer_id'], 'updated' => true, 'errors' => []]]])]);
        foreach ([7, 0, -2] as $quantity) {
            $product = $this->product('Q'.$quantity, ['quantity' => $quantity]);
            $link = $this->link($product, ['status' => 'published', 'publication_confirmed_at' => now()]);
            app(OzonAdmin::class)->stock($product);
            Http::assertSent(fn ($r) => $r['stocks'][0]['offer_id'] === $product->sku && $r['stocks'][0]['stock'] === max(0, $quantity)
                && $r['stocks'][0]['warehouse_id'] === 1020005000312240 && ! array_key_exists('price', $r['stocks'][0]));
            $this->assertNotNull($link->fresh()->last_stock_sync_at);
        }
    }

    public function test_unconfirmed_or_error_products_never_send_stock(): void
    {
        Http::fake();
        foreach (['unlinked', 'exported', 'requires_manual_review', 'error', 'published'] as $status) {
            $product = $this->product($status);
            if ($status !== 'unlinked') {
                $this->link($product, ['status' => $status, 'publication_confirmed_at' => null]);
            }
            $reports = app(OzonAdmin::class)->selected([$product->id], 'stock');
            $this->assertArrayHasKey('Ошибка', $reports[0]);
        }
        Http::assertNothingSent();
    }

    public function test_bulk_status_targets_selected_task_only_without_catalog_or_tree(): void
    {
        $one = $this->product('ONE');
        $two = $this->product('TWO');
        $this->link($one, ['import_task_id' => 101]);
        $other = $this->link($two, ['import_task_id' => 202]);
        Http::fake(['*/v1/product/import/info' => Http::response(['result' => ['items' => [['offer_id' => 'ONE', 'status' => 'imported', 'product_id' => 500]]]])]);
        Livewire::test(OzonProducts::class)->callTableBulkAction('selected_status', [$one])->assertHasNoErrors();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['task_id'] === 101);
        $this->assertNull($other->fresh()->last_status_check_at);
    }

    public function test_bulk_stock_targets_selected_published_only(): void
    {
        $one = $this->product('ONE');
        $two = $this->product('TWO');
        $three = $this->product('THREE');
        $this->link($one, ['status' => 'published', 'publication_confirmed_at' => now()]);
        $this->link($two);
        $this->link($three, ['status' => 'published', 'publication_confirmed_at' => now()]);
        Http::fake(['*/v2/products/stocks' => Http::response(['result' => [['offer_id' => 'ONE', 'updated' => true]]])]);
        app(OzonAdmin::class)->selected([$one->id, $two->id], 'stock');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['stocks'][0]['offer_id'] === 'ONE');
    }

    public function test_bulk_limit_is_checked_before_any_request(): void
    {
        Http::fake();
        try {
            app(OzonAdmin::class)->selected(range(1, 11), 'status');
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_images_use_only_local_head_without_seller_credentials(): void
    {
        $product = $this->product();
        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => 'https://kaspi.kz/foreign.jpg']);
        Http::fake(['https://autohimiki.kz/storage/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg'])]);
        $report = app(OzonAdmin::class)->images($product);
        $this->assertStringContainsString('✓', implode('', $report));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'HEAD' && ! $r->hasHeader('Api-Key') && ! $r->hasHeader('Client-Id'));
    }

    public function test_search_and_filters_include_unlinked_and_stock_states(): void
    {
        $one = $this->product('ONE');
        $two = $this->product('TWO', ['quantity' => 0, 'main_image' => null, 'description' => null]);
        $this->link($one);
        Livewire::test(OzonProducts::class)->filterTable('ozon_status', 'unlinked')->assertCanSeeTableRecords([$two])->assertCanNotSeeTableRecords([$one]);
        Livewire::test(OzonProducts::class)->filterTable('photo', 'no')->filterTable('description', 'no')->filterTable('stock', 'no')->assertCanSeeTableRecords([$two])->assertCanNotSeeTableRecords([$one]);
        Livewire::test(OzonProducts::class)->searchTable('ONE')->assertCanSeeTableRecords([$one])->assertCanNotSeeTableRecords([$two]);
    }
}
