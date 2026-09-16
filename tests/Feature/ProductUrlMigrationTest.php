<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Product;
use App\Services\Import\FullProductImporter;
use App\Services\Import\PriceStockUpdater;
use App\Services\Kaspi\KaspiProductionCandidateService;
use App\Services\Kaspi\KaspiProductionImportService;
use App\Services\ProductUrls\ProductSlugAllocator;
use App\Services\ProductUrls\ProductUrlMigration;
use App\Services\ProductUrls\ProductUrlRollback;
use App\Services\ProductUrls\ProductUrlVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductUrlMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Never connect these write tests to a configured local or production DB.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['app.url' => 'https://autohimiki.kz', 'services.kaspi.production_base_url' => 'https://autohimiki.kz',
            'services.kaspi.merchant_id' => 'Avtoximiya', 'services.kaspi.city_id' => '750000000']);
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php',
            '2025_01_008_create_seo_filters_table.php', '2025_01_012_create_redirects_table.php',
            '2025_01_013_create_settings_table.php', '2025_01_014_create_import_batches_table.php',
            '2025_01_015_create_import_errors_table.php', '2025_01_016_update_import_batches.php',
            '2026_09_02_000001_add_commercial_import_ledger.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        DB::table('categories')->insert(['id' => 1, 'name' => 'Category', 'slug' => 'category']);
    }

    private function product(array $fields = []): object
    {
        $n = DB::table('products')->count() + 1;
        $id = DB::table('products')->insertGetId(array_replace([
            'category_id' => 1, 'name' => 'Foam Cleaner Mitsuji универсальный пенный очиститель',
            'slug' => 'onec-00000000-0000-4000-8000-'.str_pad((string) $n, 12, '0', STR_PAD_LEFT),
            'sku' => 'РТ-'.str_pad((string) $n, 8, '0', STR_PAD_LEFT), 'price' => 1250.50,
            'quantity' => 7, 'in_stock' => true, 'is_active' => true, 'is_hit' => true,
            'description' => 'Preserved description', 'attributes' => '{"manual":"value"}',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $fields));

        return DB::table('products')->find($id);
    }

    private function redirect(string $from, string $to, bool $active = true): int
    {
        return DB::table('redirects')->insertGetId(['from_url' => $from, 'to_url' => $to, 'is_active' => $active]);
    }

    private function migrate(): array
    {
        $service = app(ProductUrlMigration::class);

        return $service->execute($service->plan()['approval']);
    }

    public function test_migration_is_one_hop_with_new_canonical_sitemap_and_unchanged_business_data(): void
    {
        $p = $this->product();
        $categories = DB::table('categories')->get()->toJson();
        // Warm all caches whose product URLs must be invalidated.
        $this->get('/')->assertOk()->assertSee('/product/'.$p->slug);
        $this->get('/sitemap-products.xml')->assertOk()->assertSee('/product/'.$p->slug);
        $result = $this->migrate();
        $new = $result['rows'][0]['new_slug'];
        $this->assertSame('foam-cleaner-mitsuji-universalnyi-pennyi-ocistitel', $new);
        $this->get('/product/'.$p->slug)->assertStatus(301)->assertRedirect('/product/'.$new);
        $response = $this->get('/product/'.$new)->assertOk();
        $response->assertSee('<link rel="canonical" href="https://autohimiki.kz/product/'.$new.'">', false);
        $this->assertNull($response->headers->get('Location'));
        $this->get('/sitemap-products.xml')->assertOk()->assertSee('/product/'.$new)->assertDontSee('/product/'.$p->slug);
        $this->get('/')->assertOk()->assertSee('/product/'.$new)->assertDontSee('/product/'.$p->slug);
        $this->get('/catalog/category')->assertOk()->assertSee('/product/'.$new);
        $after = (array) DB::table('products')->find($p->id);
        $before = (array) $p;
        unset($before['slug'], $before['canonical_url'], $after['slug'], $after['canonical_url']);
        $this->assertSame($before, $after);
        $this->assertSame($categories, DB::table('categories')->get()->toJson());
        $this->assertStringEndsWith('/product/'.$new, Product::find($p->id)->url);
    }

    public function test_dry_run_performs_no_database_or_cache_writes_and_lists_all_candidates(): void
    {
        $this->product();
        $this->product(['name' => 'Another']);
        Cache::put('active_redirects', ['sentinel' => 'preserved']);
        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (! preg_match('/^\s*select\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $this->artisan('products:migrate-onec-slugs', ['--dry-run' => true, '--expect' => '2', '--json' => true])->assertSuccessful();
        $this->assertSame([], $writes);
        $this->assertSame(['sentinel' => 'preserved'], Cache::get('active_redirects'));
    }

    public function test_readable_and_inactive_products_are_not_migrated(): void
    {
        $this->product();
        $readable = $this->product(['slug' => 'existing-readable']);
        $draft = $this->product(['is_active' => false]);
        $this->assertSame(1, $this->migrate()['count']);
        $this->assertEquals($readable, DB::table('products')->find($readable->id));
        $this->assertEquals($draft, DB::table('products')->find($draft->id));
    }

    public function test_duplicate_names_and_draft_slugs_get_deterministic_suffixes(): void
    {
        $this->product(['name' => 'Cleaner', 'sku' => 'B']);
        $this->product(['name' => 'Cleaner', 'sku' => 'A']);
        $this->product(['slug' => 'cleaner', 'is_active' => false]);
        $rows = $this->migrate()['rows'];
        $this->assertSame(['A', 'B'], array_column($rows, 'sku'));
        $this->assertSame(['cleaner-1', 'cleaner-2'], array_column($rows, 'new_slug'));
    }

    public function test_both_ends_of_active_inactive_and_absolute_history_are_reserved(): void
    {
        $this->product(['name' => 'Cleaner']);
        $this->redirect('/product/cleaner', '/unrelated', false);
        $this->redirect('/other', 'https://autohimiki.kz/product/cleaner-1');
        $this->redirect('https://autohimiki.kz/product/cleaner-2', '/another');
        $before = DB::table('redirects')->get()->toArray();
        $this->assertSame('cleaner-3', $this->migrate()['rows'][0]['new_slug']);
        foreach ($before as $r) {
            $this->assertEquals($r, DB::table('redirects')->find($r->id));
        }
    }

    public function test_same_product_history_is_flattened_without_touching_unrelated_redirects(): void
    {
        $p = $this->product(['name' => 'Cleaner']);
        $this->redirect('/product/a', '/product/b');
        $this->redirect('/product/b', '/product/'.$p->slug);
        $unrelated = $this->redirect('/elsewhere', '/destination');
        $before = DB::table('redirects')->find($unrelated);
        $this->migrate();
        foreach (['a', 'b', $p->slug] as $old) {
            $this->get('/product/'.$old)->assertStatus(301)->assertRedirect('/product/cleaner');
        }
        $this->get('/product/cleaner')->assertOk();
        $this->assertEquals($before, DB::table('redirects')->find($unrelated));
    }

    public function test_conflicting_outgoing_redirect_blocks_entire_plan_without_overwrite(): void
    {
        $p = $this->product();
        $this->product();
        $this->redirect('/product/'.$p->slug, '/some-other-product');
        $before = app(ProductUrlMigration::class)->snapshot();
        $this->assertSame(1, app(ProductUrlMigration::class)->plan()['blocked']);
        try {
            $this->migrate();
            $this->fail('Conflict was ignored');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('plan_blocked', $e->getMessage());
        }
        $this->assertEquals($before, app(ProductUrlMigration::class)->snapshot());
    }

    public function test_history_pointing_from_another_current_product_is_not_claimed(): void
    {
        $p = $this->product();
        $other = $this->product(['slug' => 'other']);
        $this->redirect('/product/'.$other->slug, '/product/'.$p->slug);
        $plan = app(ProductUrlMigration::class)->plan();
        $this->assertSame(1, $plan['blocked']);
        $this->assertStringContainsString('history_shadows', $plan['rows'][0]['error']);
    }

    public function test_equivalent_absolute_and_query_history_targets_are_flattened(): void
    {
        $p = $this->product(['name' => 'Cleaner']);
        $this->redirect('/product/alias', 'https://AUTOHIMIKI.kz:443/product/'.$p->slug.'/?ref=old#details');
        $this->redirect('/product/older', 'http://autohimiki.kz/product/alias?source=archive');
        $this->migrate();
        $this->get('/product/alias')->assertStatus(301)->assertRedirect('/product/cleaner');
        $this->get('/product/older')->assertStatus(301)->assertRedirect('/product/cleaner');
        $this->get('/product/cleaner')->assertOk();
    }

    public function test_null_empty_and_old_self_canonicals_are_preserved_or_updated(): void
    {
        $a = $this->product(['name' => 'A', 'canonical_url' => null]);
        $b = $this->product(['name' => 'B', 'canonical_url' => '']);
        $c = $this->product(['name' => 'C']);
        DB::table('products')->where('id', $c->id)->update(['canonical_url' => 'https://autohimiki.kz/product/'.$c->slug]);
        $this->migrate();
        $this->assertNull(DB::table('products')->find($a->id)->canonical_url);
        $this->assertSame('', DB::table('products')->find($b->id)->canonical_url);
        $this->assertSame('https://autohimiki.kz/product/c', DB::table('products')->find($c->id)->canonical_url);
    }

    public function test_custom_canonical_is_reported_and_never_overwritten(): void
    {
        $p = $this->product(['canonical_url' => 'https://example.org/deliberate']);
        $plan = app(ProductUrlMigration::class)->plan();
        $this->assertSame('custom_canonical_requires_review', $plan['rows'][0]['error']);
        $this->artisan('products:migrate-onec-slugs', ['--dry-run' => true])->assertFailed();
        $this->assertEquals($p, DB::table('products')->find($p->id));
    }

    public function test_idempotence_leaves_zero_candidates_and_no_new_redirects(): void
    {
        $this->product();
        $this->migrate();
        $before = app(ProductUrlMigration::class)->snapshot();
        $this->assertSame(0, $this->migrate()['count']);
        $this->assertEquals($before, app(ProductUrlMigration::class)->snapshot());
    }

    public function test_execution_requires_fresh_approval_and_expected_count(): void
    {
        $p = $this->product();
        $plan = app(ProductUrlMigration::class)->plan();
        $this->artisan('products:migrate-onec-slugs')->assertFailed();
        $this->artisan('products:migrate-onec-slugs', ['--approve' => $plan['approval'], '--expect' => '2'])->assertFailed();
        DB::table('products')->where('id', $p->id)->update(['name' => 'Changed after review']);
        $this->artisan('products:migrate-onec-slugs', ['--approve' => $plan['approval']])->assertFailed();
        $this->assertSame($p->slug, DB::table('products')->find($p->id)->slug);
        $this->assertSame(0, DB::table('redirects')->count());
    }

    public function test_database_failure_rolls_back_products_and_redirects_for_whole_batch(): void
    {
        $this->product(['name' => 'First']);
        $this->product(['name' => 'Second']);
        DB::unprepared("CREATE TRIGGER reject_second BEFORE INSERT ON redirects WHEN NEW.to_url = '/product/second' BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        $before = app(ProductUrlMigration::class)->snapshot();
        try {
            $this->migrate();
            $this->fail('Injected DB failure did not fire');
        } catch (QueryException $e) {
            $this->assertStringContainsString('injected failure', $e->getMessage());
        }
        $this->assertEquals($before, app(ProductUrlMigration::class)->snapshot());
    }

    public function test_empty_name_slug_fails_safely_and_long_names_are_bounded(): void
    {
        $this->product(['name' => '!!!']);
        $this->assertSame(1, app(ProductUrlMigration::class)->plan()['blocked']);
        $reserved = [];
        $allocator = new ProductSlugAllocator;
        foreach ([str_repeat('Long product ', 50), '  Очиститель / СТЕКЛА  ', '12345'] as $name) {
            $slug = $allocator->generate($name, $reserved)['slug'];
            $this->assertTrue(ProductSlugAllocator::valid($slug));
            $this->assertLessThanOrEqual(200, strlen($slug));
        }
        foreach (['', '-test', 'test-', 'test/test', 'UPPER', 'тест', 'two--hyphens'] as $slug) {
            $this->assertFalse(ProductSlugAllocator::valid($slug));
        }
    }

    public function test_invalid_onec_uuid_is_reported_instead_of_omitted(): void
    {
        $this->product(['slug' => 'onec-invalid']);
        $plan = app(ProductUrlMigration::class)->plan();
        $this->assertSame(1, $plan['count']);
        $this->assertSame('invalid_onec_uuid', $plan['rows'][0]['error']);
    }

    public function test_future_draft_publication_gets_readable_url_and_later_name_is_stable(): void
    {
        $draft = $this->product(['name' => 'Future cleaner', 'is_active' => false]);
        $p = Product::find($draft->id);
        $p->update(['is_active' => true]);
        $this->assertSame('future-cleaner', $p->fresh()->slug);
        $this->get('/product/'.$draft->slug)->assertStatus(301)->assertRedirect('/product/future-cleaner');
        $p->update(['name' => 'Renamed in admin']);
        $this->assertSame('future-cleaner', $p->fresh()->slug);
        $p->update(['is_active' => false]);
        $p->update(['is_active' => true]);
        $this->assertSame('future-cleaner', $p->fresh()->slug);
    }

    public function test_invalid_name_cannot_be_published_and_has_no_partial_history(): void
    {
        $draft = $this->product(['name' => '!!!', 'is_active' => false]);
        try {
            Product::find($draft->id)->update(['is_active' => true]);
            $this->fail('Invalid draft published');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }
        $this->assertEquals($draft, DB::table('products')->find($draft->id));
        $this->assertSame(0, DB::table('redirects')->count());
    }

    public function test_return_to_historical_slug_cannot_create_a_loop_or_self_redirect(): void
    {
        $p = Product::find($this->product(['slug' => 'a'])->id);
        $p->update(['slug' => 'b']);
        try {
            $p->update(['slug' => 'a']);
            $this->fail('Historical slug reused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }
        $this->get('/product/a')->assertStatus(301)->assertRedirect('/product/b');
        $this->get('/product/b')->assertOk();
        $this->assertSame(0, DB::table('redirects')->whereColumn('from_url', 'to_url')->count());
    }

    public function test_failed_model_update_does_not_leave_observer_redirect(): void
    {
        $first = $this->product(['slug' => 'first']);
        $second = $this->product(['slug' => 'second']);
        try {
            Product::find($first->id)->update(['slug' => 'changed', 'sku' => $second->sku]);
            $this->fail('Duplicate SKU accepted');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('redirects')->count());
            $this->assertEquals($first, DB::table('products')->find($first->id));
        }
    }

    public function test_onec_still_matches_exact_sku_after_migration(): void
    {
        $p = $this->product();
        $this->migrate();
        $updater = new PriceStockUpdater(new ImportBatch);
        $plan = $updater->planRow(['sku' => $p->sku, 'name' => 'Renamed in 1C', 'price' => '2000', 'quantity' => '8']);
        $this->assertSame($p->id, $plan['product_id']);
        $this->assertSame('updated', $plan['status']);
        $this->assertSame(['price', 'quantity', 'in_stock'], array_keys($plan['after']));
        $this->assertSame($p->name, DB::table('products')->find($p->id)->name);
    }

    public function test_kaspi_candidates_widget_and_current_url_import_remain_valid(): void
    {
        $p = $this->product();
        $new = $this->migrate()['rows'][0]['new_slug'];
        $candidate = app(KaspiProductionCandidateService::class)->list(['sku' => $p->sku])['data'][0];
        $this->assertSame('https://autohimiki.kz/product/'.$new, $candidate['storefront_url']);
        $this->get('/product/'.$new)->assertOk()->assertSee('data-merchant-sku="'.$p->sku.'"', false)
            ->assertSee('data-merchant-code="Avtoximiya"', false)->assertSee('data-city="750000000"', false);
        $payload = ['sku' => $p->sku, 'storefront_url' => $candidate['storefront_url'],
            'content' => ['images' => [], 'attributes' => [], 'description' => ''], 'kaspi_url' => 'https://kaspi.kz/shop/p/test-123/'];
        $result = app(KaspiProductionImportService::class)->import($payload);
        $this->assertContains($result['status'], ['unchanged', 'imported']);
        $payload['storefront_url'] = 'https://autohimiki.kz/product/'.$p->slug;
        try {
            app(KaspiProductionImportService::class)->import($payload);
            $this->fail('Stale payload accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('storefront_mismatch', $e->getMessage());
        }
    }

    public function test_actual_commercial_import_creates_draft_then_publishes_readable_and_updates_by_sku(): void
    {
        $run = function (string $hash, array $row): void {
            $file = DB::table('onec_files')->insertGetId(['sha256' => str_repeat($hash, 64), 'filename' => 'test.xlsx', 'source_mtime' => 1, 'total_rows' => 1]);
            $batch = ImportBatch::create(['filename' => 'test.xlsx', 'filepath' => 'test.xlsx', 'type' => 'prices_only', 'onec_file_id' => $file]);
            DB::transaction(fn () => (new PriceStockUpdater($batch))->processChunk([$row]));
        };
        $run('a', ['sku' => '000001', 'name' => 'Imported cleaner', 'price' => '1250', 'quantity' => '5']);
        $p = Product::where('sku', '000001')->firstOrFail();
        $id = $p->id;
        $this->assertFalse($p->is_active);
        $this->assertTrue(ProductSlugAllocator::technical($p->slug));
        $p->update(['is_active' => true]);
        $this->assertSame('imported-cleaner', $p->fresh()->slug);
        $run('b', ['sku' => '000001', 'name' => 'Renamed in 1C', 'price' => '1500', 'quantity' => '6']);
        $p->refresh();
        $this->assertSame($id, $p->id);
        $this->assertSame('000001', $p->sku);
        $this->assertSame('imported-cleaner', $p->slug);
        $this->assertSame('Imported cleaner', $p->name);
        $this->assertSame('1500.00', $p->price);
        $this->assertSame(6, $p->quantity);
        $this->assertSame(1, DB::table('products')->count());
    }

    public function test_new_active_product_with_technical_slug_and_default_active_flag_is_readable(): void
    {
        $p = Product::create(['category_id' => 1, 'sku' => 'new', 'name' => 'New product',
            'slug' => 'onec-00000000-0000-4000-8000-000000000001']);
        $this->assertTrue($p->fresh()->is_active);
        $this->assertSame('new-product', $p->fresh()->slug);
        $this->assertSame(0, DB::table('redirects')->count());
    }

    public function test_new_product_cannot_steal_a_historical_address(): void
    {
        $this->redirect('/product/reserved', '/product/current');
        $this->expectException(ValidationException::class);
        Product::create(['category_id' => 1, 'sku' => 'new', 'name' => 'New', 'slug' => 'reserved']);
    }

    public function test_new_active_technical_product_reconciles_its_explicit_old_canonical(): void
    {
        $technical = 'onec-00000000-0000-4000-8000-000000000001';
        $p = Product::create(['category_id' => 1, 'sku' => 'new', 'name' => 'New product', 'slug' => $technical,
            'canonical_url' => 'https://autohimiki.kz/product/'.$technical]);
        $this->assertSame('https://autohimiki.kz/product/new-product', $p->fresh()->canonical_url);
    }

    public function test_full_import_uses_reserved_history_and_preserves_slug_on_later_name_changes(): void
    {
        $this->redirect('/product/cleaner', '/product/some-existing-product');
        $batch = ImportBatch::create(['type' => 'full', 'filename' => 'test.xlsx', 'filepath' => 'test.xlsx']);
        $importer = new FullProductImporter($batch);
        $created = $importer->processChunk([['sku' => 'full-new', 'name' => 'Cleaner']]);
        $this->assertSame(1, $created['created']);
        $product = Product::where('sku', 'full-new')->firstOrFail();
        $this->assertSame('cleaner-1', $product->slug);
        $importer->processChunk([['sku' => 'full-new', 'name' => 'Renamed cleaner']]);
        $this->assertSame('cleaner-1', $product->fresh()->slug);
        $this->assertSame('Renamed cleaner', $product->fresh()->name);
        $this->assertSame(1, DB::table('redirects')->count());
    }

    public function test_all_140_public_proposals_migrate_in_isolated_database_with_108_readable_unchanged(): void
    {
        $manifest = json_decode(file_get_contents(base_path('docs/URL02_PUBLIC_MANIFEST.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(140, $manifest['rows']);
        $this->assertCount(108, $manifest['preserved_readable_slugs']);
        foreach ($manifest['preserved_readable_slugs'] as $i => $slug) {
            $this->product(['slug' => $slug, 'sku' => 'fixture-readable-'.$i]);
        }
        $readableBefore = DB::table('products')->get()->toJson();
        foreach ($manifest['rows'] as $row) {
            $this->product(['slug' => $row['old_slug'], 'sku' => $row['sku'], 'name' => $row['name']]);
        }
        $plan = app(ProductUrlMigration::class)->plan();
        $this->assertSame(140, $plan['count']);
        $this->assertSame(0, $plan['blocked']);
        $this->assertSame(array_column($manifest['rows'], 'new_slug'), array_column($plan['rows'], 'new_slug'));
        $this->migrate();
        foreach ($plan['rows'] as $row) {
            $this->get('/product/'.$row['old_slug'])->assertStatus(301)->assertRedirect('/product/'.$row['new_slug']);
            $this->get('/product/'.$row['new_slug'])->assertOk()
                ->assertSee('<link rel="canonical" href="'.$row['new_url'].'">', false);
        }
        $this->assertSame($readableBefore, DB::table('products')->where('id', '<=', 108)->get()->toJson());
        $this->assertSame(248, DB::table('products')->count());
        $this->assertSame(0, DB::table('products')->where('slug', 'like', 'onec-%')->count());
        $this->get('/sitemap-products.xml')->assertOk()->assertDontSee('/product/onec-');
    }

    public function test_operator_verifier_checks_receipt_without_following_redirects_or_writing(): void
    {
        $this->product(['name' => 'Cleaner']);
        $receipt = $this->migrate();
        $row = $receipt['rows'][0];
        Http::preventStrayRequests();
        Http::fake([
            'https://autohimiki.kz/sitemap-products.xml' => Http::response('<urlset><url><loc>'.$row['new_url'].'</loc></url></urlset>'),
            $row['old_url'] => Http::response('', 301, ['Location' => $row['new_url']]),
            $row['new_url'] => Http::response('<html><head><link rel="canonical" href="'.$row['new_url'].'"></head></html>'),
        ]);
        $before = app(ProductUrlMigration::class)->snapshot();
        $result = app(ProductUrlVerifier::class)->verify($receipt);
        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['failed']);
        $this->assertEquals($before, app(ProductUrlMigration::class)->snapshot());
        Http::assertSentCount(3);
    }

    public function test_operator_verifier_reports_chain_canonical_sitemap_and_data_regressions(): void
    {
        $this->product(['name' => 'Cleaner']);
        $receipt = $this->migrate();
        $row = $receipt['rows'][0];
        DB::table('products')->where('id', $row['id'])->update(['price' => 999]);
        Http::preventStrayRequests();
        Http::fake([
            'https://autohimiki.kz/sitemap-products.xml' => Http::response('<urlset><url><loc>'.$row['old_url'].'</loc></url></urlset>'),
            $row['old_url'] => Http::response('', 301, ['Location' => '/intermediate']),
            $row['new_url'] => Http::response('<html></html>', 301, ['Location' => '/another']),
        ]);
        $result = app(ProductUrlVerifier::class)->verify($receipt);
        $this->assertSame(1, $result['failed']);
        $this->assertEqualsCanonicalizing(['old_must_301_directly_to_new', 'new_must_200_without_redirect', 'canonical_mismatch',
            'sitemap_mismatch', 'product_identity_or_protected_data_changed'], $result['rows'][0]['failures']);
    }

    public function test_receipt_rollback_preserves_both_addresses_and_current_commercial_data(): void
    {
        $p = $this->product(['name' => 'Cleaner']);
        $this->redirect('/product/ancient', '/product/'.$p->slug);
        $receipt = $this->migrate();
        DB::table('products')->where('id', $p->id)->update(['price' => 999, 'quantity' => 2]);
        $rollback = app(ProductUrlRollback::class);
        $beforePlan = app(ProductUrlMigration::class)->snapshot();
        $plan = $rollback->plan($receipt);
        $this->assertEquals($beforePlan, app(ProductUrlMigration::class)->snapshot());
        $this->assertSame('rollback_committed', $rollback->execute($receipt, $plan['approval'])['status']);
        $this->get('/product/'.$p->slug)->assertOk()
            ->assertSee('<link rel="canonical" href="https://autohimiki.kz/product/'.$p->slug.'">', false);
        $this->get('/product/cleaner')->assertStatus(301)->assertRedirect('/product/'.$p->slug);
        $this->get('/product/ancient')->assertStatus(301)->assertRedirect('/product/'.$p->slug);
        $this->assertEquals(999, DB::table('products')->find($p->id)->price);
        $this->assertSame(2, DB::table('products')->find($p->id)->quantity);
        $this->assertSame(0, DB::table('redirects')->whereColumn('from_url', 'to_url')->count());
    }

    public function test_rollback_refuses_changed_ur_l_history_without_overwriting_it(): void
    {
        $this->product(['name' => 'Cleaner']);
        $receipt = $this->migrate();
        $this->redirect('/new-unrelated-history', '/target');
        $before = app(ProductUrlMigration::class)->snapshot();
        try {
            app(ProductUrlRollback::class)->execute($receipt, 'not-approved');
            $this->fail('Changed history ignored');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('URL_state_changed', $e->getMessage());
        }
        $this->assertEquals($before, app(ProductUrlMigration::class)->snapshot());
    }

    public function test_rollback_database_failure_is_atomic(): void
    {
        $this->product(['name' => 'Cleaner']);
        $receipt = $this->migrate();
        DB::unprepared("CREATE TRIGGER reject_reverse BEFORE INSERT ON redirects WHEN NEW.from_url = '/product/cleaner' BEGIN SELECT RAISE(ABORT, 'rollback injected failure'); END");
        $rollback = app(ProductUrlRollback::class);
        $plan = $rollback->plan($receipt);
        $before = app(ProductUrlMigration::class)->snapshot();
        try {
            $rollback->execute($receipt, $plan['approval']);
            $this->fail('Injected rollback failure ignored');
        } catch (QueryException) {
            $this->assertEquals($before, app(ProductUrlMigration::class)->snapshot());
        }
    }
}
