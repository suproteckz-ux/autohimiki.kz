<?php

namespace Tests\Feature;

use App\Jobs\Import\FinalizeImportJob;
use App\Jobs\Import\ImportChunkJob;
use App\Jobs\Import\ProcessImportJob;
use App\Models\ImportBatch;
use App\Services\CacheService;
use App\Services\Import\ColumnMapper;
use App\Services\Import\CommercialImportRunner;
use App\Services\Import\CommercialValues;
use App\Services\Import\FullProductImporter;
use App\Services\Import\ImportFileParser;
use App\Services\Import\OnecFileIntake;
use App\Services\Import\PriceStockUpdater;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class OnecCommercialSyncTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = storage_path('framework/testing/onec-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->work, 0700, true);
        config(['onec.directory' => $this->work, 'onec.staging' => $this->work.'/private',
            'onec.stable_seconds' => 1, 'onec.order_source' => 'ftp_mtime', 'onec.full_snapshot' => false]);
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php',
            '2025_01_014_create_import_batches_table.php', '2025_01_015_create_import_errors_table.php',
            '2025_01_016_update_import_batches.php', '2026_09_02_000001_add_commercial_import_ledger.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('categories')->insert(['id' => 1, 'name' => 'Manual category', 'slug' => 'manual']);
        DB::table('brands')->insert(['id' => 1, 'name' => 'Manual brand', 'slug' => 'brand']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        parent::tearDown();
    }

    private function product(string $sku = 'РТ-00001343'): int
    {
        return DB::table('products')->insertGetId([
            'category_id' => 1, 'brand_id' => 1, 'sku' => $sku, 'name' => 'Manual product',
            'slug' => 'manual-'.bin2hex(random_bytes(4)), 'price' => 99.50, 'quantity' => 9, 'in_stock' => false,
            'old_price' => 100, 'description' => 'Manual description', 'short_description' => 'Manual short',
            'usage_instructions' => 'Manual instructions', 'attributes' => '{"manual":"yes"}', 'faq' => '[]',
            'main_image' => 'catalog/satu/00000000614.jpg', 'main_image_webp' => 'catalog/manual.webp',
            'main_image_alt' => 'Manual alt', 'meta_title' => 'Manual SEO', 'meta_description' => 'SEO description',
            'h1' => 'Manual heading', 'seo_text' => 'SEO text', 'canonical_url' => 'https://autohimiki.kz/manual',
            'is_active' => false, 'is_new' => true, 'is_hit' => true, 'is_popular' => true, 'views' => 8,
            'sort_order' => 5, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function fixture(string $name = 'flat'): string
    {
        $path = $this->work.'/'.$name.'.xlsx';
        copy(base_path('tests/Fixtures/onec/'.$name.'.xlsx'), $path);
        touch($path, time() - 120);

        return $path;
    }

    private function workbook(array $rows, ?array $headers = null): string
    {
        $path = $this->work.'/input.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers ?? ImportFileParser::ONEC_HEADERS));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        touch($path, time() - 120);

        return $path;
    }

    private function runFile(string $path, array $options = []): array
    {
        return app(CommercialImportRunner::class)->run(app(OnecFileIntake::class)->stage($path, $options['dry_run'] ?? false), $options);
    }

    public function test_both_real_workbooks_have_263_identical_products_and_text_skus(): void
    {
        $parser = app(ImportFileParser::class);
        $flat = $parser->parseCommercial($this->fixture());
        $warehouse = $parser->parseCommercial($this->fixture('warehouse'));
        $this->assertCount(263, $flat);
        $this->assertCount(263, $warehouse);
        $this->assertSame('РТ-00001343', $flat[0]['Номенклатура.Код']);
        $this->assertSame(2, $flat[0]['__row']);
        $this->assertSame(4, $warehouse[0]['__row']);
        $strip = fn ($rows) => array_map(function ($row) {
            unset($row['__row']);

            return $row;
        }, $rows);
        $this->assertSame($strip($flat), $strip($warehouse));
        $this->assertCount(10, array_filter($flat, fn ($row) => $row['Розничная цена'] === null || $row['Розничная цена'] === ''));
    }

    public function test_real_sku_dry_run_and_apply_preserve_every_protected_field_and_gallery(): void
    {
        $id = $this->product();
        DB::table('product_images')->insert(['product_id' => $id, 'path' => 'catalog/legacy.jpg']);
        $before = (array) DB::table('products')->find($id);
        $images = DB::table('product_images')->get()->toJson();
        $path = $this->fixture();
        $plan = $this->runFile($path, ['sku' => 'РТ-00001343', 'dry_run' => true]);
        $this->assertSame(263, $plan['total_rows']);
        $this->assertSame(['price' => '2700.00', 'quantity' => 2, 'in_stock' => true], $plan['plans'][0]['after']);
        $this->assertSame($before, (array) DB::table('products')->find($id));
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('onec_files', 0);
        Cache::put(CacheService::KEY_HOMEPAGE_HITS, 'old');
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'update "products"')) {
                $this->assertSame('old', Cache::get(CacheService::KEY_HOMEPAGE_HITS));
            }
        });
        $result = $this->runFile($path, ['sku' => 'РТ-00001343']);
        $this->assertSame('done', $result['status']);
        $this->assertSame(1, $result['updated']);
        $after = (array) DB::table('products')->find($id);
        foreach (array_diff(array_keys($before), ['price', 'quantity', 'in_stock']) as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $this->assertSame($images, DB::table('product_images')->get()->toJson());
        $this->assertNull(Cache::get(CacheService::KEY_HOMEPAGE_HITS));
        $receipt = DB::table('import_commercial_rows')->first();
        $this->assertSame('99.50', json_decode($receipt->before_values, true)['price']);
        $this->assertSame(2, json_decode($receipt->after_values, true)['quantity']);
        $this->assertDatabaseHas('import_batches', ['id' => $result['batch_id'], 'total_chunks' => 1, 'processed_chunks' => 1, 'status' => 'done']);
    }

    public function test_invalid_prices_preserve_price_but_disable_stock(): void
    {
        foreach ([null, '', 'garbage', '-100', '1,000.50', 'NaN', '10foo', '12.345'] as $price) {
            $sku = '000'.bin2hex(random_bytes(4));
            $this->product($sku);
            $plan = (new PriceStockUpdater(new ImportBatch))
                ->planRow(['sku' => $sku, 'price' => $price, 'quantity' => 2]);
            $this->assertSame('99.50', $plan['after']['price']);
            $this->assertSame(0, $plan['after']['quantity']);
            $this->assertNotEmpty($plan['diagnostics']);
        }
        $this->assertSame('7300.25', CommercialValues::price("7\u{00A0}300,25"));
        $this->assertSame('0.00', CommercialValues::price(0));
        $this->assertSame(0, CommercialValues::quantity(-3));
        $this->assertSame(0, CommercialValues::quantity(0));
    }

    public function test_zero_negative_and_malformed_quantities_apply_safely(): void
    {
        foreach (['zero', 'negative', 'invalid', 'fractional', 'blank'] as $sku) {
            $this->product($sku);
        }
        $this->runFile($this->workbook([
            ['шт', 'Name', 'zero', 0, 100], ['шт', 'Name', 'negative', -5, 100],
            ['шт', 'Name', 'invalid', 'abc', 100], ['шт', 'Name', 'fractional', 1.5, 100],
            ['шт', 'Name', 'blank', null, 100],
        ]));
        foreach (['zero', 'negative'] as $sku) {
            $this->assertDatabaseHas('products', ['sku' => $sku, 'quantity' => 0, 'in_stock' => false, 'price' => 100]);
        }
        foreach (['invalid', 'fractional', 'blank'] as $sku) {
            $this->assertDatabaseHas('products', ['sku' => $sku, 'quantity' => 0, 'in_stock' => false, 'price' => 100]);
        }
    }

    public function test_string_leading_zeros_cyrillic_unknown_sku_and_blank_price(): void
    {
        $this->product('000123');
        $this->product('РТ-00000001');
        $this->runFile($this->workbook([
            ['шт', 'Same name', '000123', 4, null], ['шт', 'Same name', 'РТ-00000001', 2, 'bad'],
            ['шт', 'Same name', 'unknown', 3, 123],
        ]));
        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseHas('products', ['sku' => '000123', 'price' => 99.5, 'quantity' => 0]);
        $this->assertDatabaseHas('products', ['sku' => 'РТ-00000001', 'price' => 99.5, 'quantity' => 0]);
        $this->assertDatabaseHas('import_commercial_rows', ['sku' => 'unknown', 'status' => 'created']);
    }

    public function test_duplicate_hash_renamed_file_and_partial_then_full_are_idempotent(): void
    {
        $this->product();
        $path = $this->fixture();
        $first = $this->runFile($path, ['sku' => 'РТ-00001343']);
        $renamed = $this->work.'/renamed.xlsx';
        rename($path, $renamed);
        $again = $this->runFile($renamed, ['sku' => 'РТ-00001343']);
        $this->assertSame('duplicate', $again['status']);
        $this->assertDatabaseCount('import_commercial_rows', 1);
        $full = $this->runFile($renamed);
        $this->assertSame(0, $full['updated']);
        $this->assertDatabaseCount('import_commercial_rows', 263);
        $this->assertDatabaseCount('onec_files', 1);
        $this->assertNotNull(DB::table('onec_files')->value('completed_at'));
        $this->assertSame('duplicate', $this->runFile($renamed)['status']);
        $this->assertDatabaseCount('import_commercial_rows', 263);
    }

    public function test_structural_failure_and_duplicate_sku_prevent_all_writes(): void
    {
        $id = $this->product('001');
        $before = (array) DB::table('products')->find($id);
        foreach ([
            [['шт', 'Name', '001', 2, 300], ['шт', 'Name', '001', 3, 400]],
            [['шт', 'Name', '001', 2, 300], ['шт', 'Name', 123, 3, 400]],
            [['шт', 'Name', '001', 2, 300], ['Итого', null, null, 2, 300], ['шт', 'Name', '002', 1, 5]],
        ] as $rows) {
            try {
                $this->runFile($this->workbook($rows));
                $this->fail('Expected structural failure');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            $this->assertSame($before, (array) DB::table('products')->find($id));
            $this->assertDatabaseCount('onec_files', 0);
        }
    }

    public function test_invalid_zip_unknown_headers_and_formula_are_rejected(): void
    {
        $path = $this->work.'/broken.xlsx';
        file_put_contents($path, 'not zip');
        foreach ([fn () => $path, fn () => $this->workbook([['шт', 'Name', '001', 1, 2]], ['wrong']),
            fn () => $this->workbook([['шт', 'Name', '001', 1, '=1+1']])] as $make) {
            $file = $make();
            try {
                app(ImportFileParser::class)->parseCommercial($file);
                $this->fail('Expected invalid workbook');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_interruption_rolls_back_everything_and_retry_succeeds(): void
    {
        $this->product('one');
        $this->product('two');
        $path = $this->workbook([['шт', 'Name', 'one', 1, 2], ['шт', 'Name', 'two', 2, 3]]);
        $throw = true;
        $writes = 0;
        DB::listen(function ($query) use (&$throw, &$writes) {
            if ($throw && str_starts_with($query->sql, 'update "products"') && ++$writes === 2) {
                $throw = false;
                throw new \RuntimeException('Simulated worker interruption');
            }
        });
        try {
            $this->runFile($path);
            $this->fail('Expected interruption');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated worker interruption', $e->getMessage());
        }
        $this->assertDatabaseCount('onec_files', 0);
        $this->assertDatabaseCount('import_chunks', 0);
        $this->assertDatabaseCount('import_commercial_rows', 0);
        $this->assertDatabaseHas('products', ['sku' => 'one', 'price' => 99.5]);
        $this->assertSame(2, $this->runFile($path)['updated']);
        $this->assertDatabaseCount('import_commercial_rows', 2);
    }

    public function test_old_snapshot_cannot_follow_a_newer_apply(): void
    {
        $this->product('one');
        $old = $this->workbook([['шт', 'Name', 'one', 1, 2]]);
        $oldStage = app(OnecFileIntake::class)->stage($old);
        touch($old, time() - 60);
        $new = $this->workbook([['шт', 'Name', 'one', 5, 6]]);
        touch($new, time() - 30);
        $this->runFile($new);
        $this->expectExceptionMessage('Older or chronologically ambiguous');
        app(CommercialImportRunner::class)->run($oldStage);
    }

    public function test_intake_ignores_partial_files_and_rejects_unstable_or_ambiguous_inputs(): void
    {
        file_put_contents($this->work.'/upload.partial.xlsx', 'partial');
        $this->assertSame('no_file', app(OnecFileIntake::class)->stage()['status']);
        $path = $this->fixture();
        touch($path, time());
        try {
            app(OnecFileIntake::class)->stage();
            $this->fail('Expected stability rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stable', $e->getMessage());
        }
        touch($path, time() - 120);
        $other = $this->fixture('warehouse');
        touch($other, filemtime($path));
        $this->expectExceptionMessage('equal timestamps');
        app(OnecFileIntake::class)->stage();
    }

    public function test_retried_chunk_does_not_increment_counters_or_write_again(): void
    {
        $this->product('one');
        $run = $this->runFile($this->workbook([['шт', 'Name', 'one', 1, 2]]));
        DB::transaction(function () use ($run) {
            app(CommercialImportRunner::class)->lock();
            DB::table('import_batches')->where('id', $run['batch_id'])->update(['status' => 'processing']);
            (new ImportChunkJob($run['batch_id'], [['sku' => 'one', 'price' => 999, 'quantity' => 999]], 0, 1))->handle();
            (new FinalizeImportJob($run['batch_id']))->handle();
        });
        $this->assertDatabaseHas('import_batches', ['id' => $run['batch_id'], 'processed_chunks' => 1, 'updated_count' => 1]);
        $this->assertDatabaseHas('products', ['sku' => 'one', 'price' => 2, 'quantity' => 1]);
    }

    public function test_failed_and_incomplete_batches_cannot_be_finalized(): void
    {
        $batch = ImportBatch::create(['type' => 'prices_only', 'filename' => 'x', 'filepath' => 'x', 'status' => 'failed']);
        (new FinalizeImportJob($batch->id))->handle();
        $this->assertSame('failed', $batch->fresh()->status);
        $batch->update(['status' => 'processing', 'total_chunks' => 1]);
        $this->expectExceptionMessage('incomplete');
        (new FinalizeImportJob($batch->id))->handle();
    }

    public function test_manual_prices_only_uses_same_ftp_hash_ledger_and_contract(): void
    {
        $this->product();
        $path = $this->fixture();
        Storage::fake('public');
        Storage::disk('public')->put('imports/current.xlsx', file_get_contents($path));
        $batch = ImportBatch::create(['type' => 'prices_only', 'filename' => 'current.xlsx', 'filepath' => 'imports/current.xlsx',
            'column_map' => ['price' => 'Номенклатура'], 'status' => 'pending']);
        $job = new ProcessImportJob($batch->id);
        $job->handle(app(ImportFileParser::class), app(ColumnMapper::class));
        $job->handle(app(ImportFileParser::class), app(ColumnMapper::class));
        $this->assertDatabaseHas('products', ['sku' => 'РТ-00001343', 'price' => 2700, 'quantity' => 2]);
        $this->assertDatabaseCount('import_commercial_rows', 263);
        $this->assertSame(3, (int) $batch->fresh()->processed_chunks);
    }

    public function test_full_import_cannot_overwrite_commercial_fields(): void
    {
        $this->product('one');
        $batch = ImportBatch::create(['type' => 'full', 'filename' => 'x', 'filepath' => 'x', 'status' => 'processing']);
        (new FullProductImporter($batch))->processChunk([['sku' => 'one', 'price' => 1234, 'quantity' => 100]]);
        $this->assertDatabaseHas('products', ['sku' => 'one', 'price' => 99.5, 'quantity' => 9, 'in_stock' => false]);
    }

    public function test_console_dry_run_one_real_sku_has_no_database_writes(): void
    {
        $this->product();
        $path = $this->fixture();
        $this->artisan('onec:sync', ['--dry-run' => true, '--sku' => 'РТ-00001343', '--file' => $path, '--debug' => true])->assertSuccessful();
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseHas('products', ['sku' => 'РТ-00001343', 'price' => 99.5]);
        $this->assertCount(0, glob($this->work.'/private/*.xlsx'));
    }

    public function test_ambiguous_database_sku_stops_instead_of_selecting_first(): void
    {
        $id = $this->product('one');
        Schema::table('products', fn ($table) => $table->dropUnique('products_sku_unique'));
        $copy = (array) DB::table('products')->find($id);
        unset($copy['id']);
        $copy['slug'] = 'duplicate-sku';
        DB::table('products')->insert($copy);
        try {
            $this->runFile($this->workbook([['шт', 'Name', 'one', 1, 2]]));
            $this->fail('Expected conflict');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Conflicting target SKU', $e->getMessage());
        }
        $this->assertDatabaseCount('import_commercial_rows', 0);
        $this->assertDatabaseCount('onec_files', 0);
    }

    public function test_unconfirmed_chronology_allows_dry_run_but_blocks_apply(): void
    {
        config(['onec.order_source' => null]);
        $this->product();
        $path = $this->fixture();
        $this->assertSame('dry_run', $this->runFile($path, ['dry_run' => true, 'sku' => 'РТ-00001343'])['status']);
        $this->expectExceptionMessage('chronology is unconfirmed');
        $this->runFile($path);
    }

    public function test_manual_upload_cannot_apply_an_old_different_file(): void
    {
        $this->product();
        $this->fixture();
        Storage::fake('public');
        Storage::disk('public')->put('imports/old.xlsx', 'wrong content');
        $batch = ImportBatch::create(['type' => 'prices_only', 'filename' => 'old.xlsx', 'filepath' => 'imports/old.xlsx']);
        try {
            (new ProcessImportJob($batch->id))->handle(app(ImportFileParser::class), app(ColumnMapper::class));
            $this->fail('Expected mismatch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('differs from current FTP', $e->getMessage());
        }
        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertDatabaseCount('onec_files', 0);
    }

    public function test_changed_staged_bytes_are_rejected(): void
    {
        $file = app(OnecFileIntake::class)->stage($this->fixture());
        file_put_contents($file['path'], 'tampered');
        $this->expectExceptionMessage('Staged file hash changed');
        app(CommercialImportRunner::class)->run($file);
    }

    public function test_new_products_dry_run_creation_receipts_and_repeat(): void
    {
        $path = $this->workbook([
            ['шт', 'Leading zero', ' 00000000680 ', 7, 5500],
            ['шт', 'Кириллица', 'БК000000011', 4, 120],
            ['шт', 'Blank price', 'РТ-00001284', 7, null],
        ]);
        $plan = $this->runFile($path, ['dry_run' => true]);
        $this->assertSame(3, $plan['created_planned']);
        $this->assertSame(1, $plan['invalid_price']);
        $this->assertSame(0, $plan['matched']);
        $this->assertSame('created_planned', $plan['plans'][0]['status']);
        $this->assertSame('Leading zero', $plan['plans'][0]['name']);
        $this->assertSame('5500.00', $plan['plans'][0]['price']);
        $this->assertSame(7, $plan['plans'][0]['quantity']);
        $this->assertTrue($plan['plans'][0]['in_stock']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('onec_files', 0);
        $this->assertDatabaseCount('import_commercial_rows', 0);
        $result = $this->runFile($path);
        $this->assertSame(3, $result['created']);
        $this->assertDatabaseHas('products', ['sku' => '00000000680', 'name' => 'Leading zero',
            'price' => 5500, 'quantity' => 7, 'in_stock' => true, 'is_active' => false, 'description' => null, 'meta_title' => null, 'main_image' => null]);
        $this->assertDatabaseHas('products', ['sku' => 'БК000000011', 'quantity' => 4, 'in_stock' => true]);
        $this->assertDatabaseHas('products', ['sku' => 'РТ-00001284', 'price' => 0, 'quantity' => 0, 'in_stock' => false]);
        $receipt = DB::table('import_commercial_rows')->where('sku', 'РТ-00001284')->first();
        $this->assertSame('created', $receipt->status);
        $this->assertNull($receipt->before_values);
        $this->assertSame(['price' => '0.00', 'quantity' => 0, 'in_stock' => false], json_decode($receipt->after_values, true));
        $this->assertNotEmpty(json_decode($receipt->diagnostics, true));
        $this->assertSame((int) DB::table('products')->where('sku', 'РТ-00001284')->value('id'), (int) $receipt->product_id);
        $this->assertSame('duplicate', $this->runFile($path)['status']);
        $this->assertSame(0, $this->runFile($path, ['dry_run' => true])['created_planned']);
        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseCount('import_commercial_rows', 3);
    }

    public function test_missing_snapshot_guard_and_filtered_runs(): void
    {
        $id = $this->product('absent');
        DB::table('products')->where('id', $id)->update(['in_stock' => true]);
        $before = (array) DB::table('products')->find($id);
        $path = $this->workbook([['шт', 'New', 'present', 2, 100]]);
        $this->assertSame(0, $this->runFile($path, ['dry_run' => true])['missing_from_snapshot_planned']);
        config(['onec.full_snapshot' => true]);
        foreach ([['sku' => 'present'], ['limit' => 100]] as $filter) {
            $this->assertSame(0, $this->runFile($path, $filter + ['dry_run' => true])['missing_from_snapshot_planned']);
        }
        $this->assertSame(1, $this->runFile($path, ['dry_run' => true])['missing_from_snapshot_planned']);
        $this->assertSame($before, (array) DB::table('products')->find($id));
        $this->assertSame(1, $this->runFile($path)['missing_from_snapshot_zeroed']);
        $after = (array) DB::table('products')->find($id);
        $this->assertSame(0, $after['quantity']);
        foreach (array_diff(array_keys($before), ['quantity', 'in_stock']) as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $this->assertDatabaseHas('import_commercial_rows', ['sku' => 'absent', 'status' => 'missing_from_snapshot', 'row_number' => 0]);
        $this->assertNotNull(DB::table('onec_files')->value('completed_at'));
        DB::table('products')->where('id', $id)->update(['quantity' => 8]);
        $this->assertSame('duplicate', $this->runFile($path)['status']);
        $this->assertSame(0, $this->runFile($path, ['dry_run' => true])['missing_from_snapshot_planned']);
        $this->assertDatabaseHas('products', ['id' => $id, 'quantity' => 8]);
    }

    public function test_disabled_or_filtered_apply_does_not_zero_missing_and_config_does_not_replay_hash(): void
    {
        foreach ([false, true] as $enabled) {
            config(['onec.full_snapshot' => $enabled]);
            $sku = $enabled ? 'second' : 'first';
            $id = $this->product($sku);
            $path = $this->workbook([['шт', 'New', 'present-'.$sku, 2, 100]]);
            touch($path, time() - ($enabled ? 30 : 120));
            $this->runFile($path, $enabled ? ['limit' => 1] : []);
            $this->assertDatabaseHas('products', ['id' => $id, 'quantity' => 9, 'price' => 99.5]);
            config(['onec.full_snapshot' => true]);
            $this->assertSame('duplicate', $this->runFile($path)['status']);
            $this->assertDatabaseHas('products', ['id' => $id, 'quantity' => 9]);
        }
    }

    public function test_failure_after_creation_and_missing_zeroing_rolls_back_everything(): void
    {
        config(['onec.full_snapshot' => true]);
        $id = $this->product('absent');
        $present = $this->product('existing');
        $path = $this->workbook([['шт', 'New', 'new', 2, 100], ['шт', 'Existing', 'existing', 3, 200]]);
        $throw = true;
        DB::listen(function ($query) use (&$throw) {
            if ($throw && str_starts_with($query->sql, 'update "onec_files"')) {
                $throw = false;
                throw new \RuntimeException('Failure after product creation and zeroing');
            }
        });
        try {
            $this->runFile($path);
            $this->fail('Expected interruption');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failure after product creation and zeroing', $e->getMessage());
        }
        $this->assertDatabaseMissing('products', ['sku' => 'new']);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseHas('products', ['id' => $id, 'quantity' => 9]);
        $this->assertDatabaseHas('products', ['id' => $present, 'quantity' => 9, 'price' => 99.5]);
        foreach (['onec_files', 'import_commercial_rows', 'import_chunks'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertNull(DB::table('import_run_locks')->value('file_hash'));
        $result = $this->runFile($path);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['missing_from_snapshot_zeroed']);
    }

    public function test_existing_invalid_prices_apply_and_new_product_manual_content_survives_next_export(): void
    {
        $this->product('blank');
        $this->product('bad');
        $first = $this->workbook([['шт', 'Blank', 'blank', 7, null], ['шт', 'Bad', 'bad', 7, 'malformed'], ['шт', 'Initial', 'new', 1, 100]]);
        $this->runFile($first);
        foreach (['blank', 'bad'] as $sku) {
            $this->assertDatabaseHas('products', ['sku' => $sku, 'price' => 99.5, 'quantity' => 0, 'in_stock' => false]);
        }
        $id = DB::table('products')->where('sku', 'new')->value('id');
        DB::table('products')->where('id', $id)->update(['name' => 'Manual', 'category_id' => 1, 'description' => 'Edited', 'is_active' => true]);
        $before = (array) DB::table('products')->find($id);
        $next = $this->workbook([['шт', 'Do not use', 'new', 5, 200]]);
        touch($next, time() - 30);
        $this->runFile($next);
        $after = (array) DB::table('products')->find($id);
        foreach (array_diff(array_keys($before), ['price', 'quantity', 'in_stock']) as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $this->assertSame(5, $after['quantity']);
    }

    public function test_dry_run_reports_conflict_without_writes(): void
    {
        $path = $this->workbook([['шт', '', 'new', 3, 100]]);
        $plan = $this->runFile($path, ['dry_run' => true]);
        $this->assertSame(1, $plan['conflicts']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('onec_files', 0);
        $this->expectExceptionMessage('Missing or oversized name');
        $this->runFile($path);
    }
}
