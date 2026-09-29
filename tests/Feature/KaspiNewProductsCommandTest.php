<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiProductionCandidateClient;
use App\Services\Kaspi\KaspiProductionCandidateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

class KaspiNewProductsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['services.kaspi.production_base_url' => 'https://autohimiki.kz',
            'services.kaspi.internal_api_token' => 'test-secret']);
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Без категории', 'slug' => 'bez-kategorii', 'is_active' => false],
            ['id' => 2, 'name' => 'Автохимия', 'slug' => 'avtohimiya', 'is_active' => true],
        ]);
        $guard = Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed');
        $this->app->instance(KaspiLocalBrowserGuard::class, $guard);
    }

    private function product(string $sku, array $overrides = []): int
    {
        return DB::table('products')->insertGetId(array_replace([
            'category_id' => 1, 'sku' => $sku, 'name' => 'Product '.$sku, 'slug' => 'product-'.bin2hex(random_bytes(4)),
            'is_active' => true, 'price' => 1234, 'quantity' => 9, 'attributes' => '{}', 'meta_title' => 'Manual SEO',
        ], $overrides));
    }

    private function candidate(int $id, string $sku): array
    {
        return ['product_id' => $id, 'sku' => $sku, 'name' => 'Product '.$sku,
            'storefront_url' => 'https://autohimiki.kz/product/product-'.$id, 'state_fingerprint' => hash('sha256', $sku),
            'current_photo_count' => 0, 'current_description_present' => false, 'current_attribute_count' => 0];
    }

    private function outputRows(): array
    {
        return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim(Artisan::output())))));
    }

    public function test_scope_is_active_and_uses_the_real_uncategorized_relation(): void
    {
        $included = $this->product('active-new');
        $this->product('categorized', ['category_id' => 2]);
        $this->product('inactive-new', ['is_active' => false]);
        $this->product(' invalid ');
        $this->product('invalid-slug', ['slug' => '']);

        $service = app(KaspiProductionCandidateService::class);
        $before = DB::table('products')->orderBy('id')->get()->toJson();
        $rows = $service->list(['scope' => 'new_products', 'force_content_refresh' => true, 'limit' => 100])['data'];
        $this->assertSame(['active-new'], array_column($rows, 'sku'));
        $this->assertSame($included, $rows[0]['product_id']);
        $this->assertSame($before, DB::table('products')->orderBy('id')->get()->toJson());
        $this->withToken('test-secret')->getJson('/api/internal/kaspi-content/candidates?scope=new_products&force_content_refresh=true')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'active-new')
            ->assertJsonPath('scope', 'new_products');
        $this->withToken('test-secret')->getJson('/api/internal/kaspi-content/candidates?scope=all')
            ->assertUnprocessable();

        DB::table('products')->where('id', $included)->update(['category_id' => 2]);
        $this->assertSame([], $service->list(['scope' => 'new_products', 'force_content_refresh' => true])['data']);
    }

    public function test_candidate_client_forwards_only_the_explicit_new_products_scope(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => [], 'next_cursor' => null, 'scope' => 'new_products'])]);
        app(KaspiProductionCandidateClient::class)->page(['scope' => 'new_products', 'force_content_refresh' => true]);
        Http::assertSent(fn ($request) => $request['scope'] === 'new_products' && $request['force_content_refresh'] === 'true');
    }

    public function test_legacy_unscoped_response_fails_before_resolver_or_chromium(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => [$this->candidate(50, 'old-product')], 'next_cursor' => null])]);
        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldNotReceive('prepareRefreshCandidate');
        $bridge->shouldNotReceive('send');
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);

        $this->assertSame(1, Artisan::call('kaspi:push-new-products', ['--dry-run' => true]));
        $rows = $this->outputRows();
        $this->assertSame(0, $rows[0]['summary']['total_candidates']);
        $this->assertSame('candidate_scope_not_confirmed', $rows[0]['batch_error']);
        Http::assertSent(fn ($request) => $request['scope'] === 'new_products');
    }

    public function test_empty_confirmed_scope_reports_zero_without_starting_pipeline(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => [], 'next_cursor' => null, 'scope' => 'new_products'])]);
        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldNotReceive('prepareRefreshCandidate');
        $bridge->shouldNotReceive('send');
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);

        $this->assertSame(0, Artisan::call('kaspi:push-new-products', ['--dry-run' => true]));
        $rows = $this->outputRows();
        $this->assertSame(0, $rows[0]['summary']['total_candidates']);
        $this->assertNull($rows[0]['batch_error']);
    }

    public function test_dry_run_uses_existing_force_pipeline_without_posts_or_product_writes(): void
    {
        $id = $this->product('dry-run');
        $candidate = $this->candidate($id, 'dry-run');
        $client = Mockery::mock(KaspiProductionCandidateClient::class);
        $client->shouldReceive('page')->once()->with(Mockery::on(fn ($o) => $o['scope'] === 'new_products'
            && $o['force_content_refresh'] === true))->andReturn(['data' => [$candidate], 'next_cursor' => null]);
        $client->shouldReceive('fetch')->once()->with(Mockery::on(fn ($o) => $o['scope'] === 'new_products'
            && $o['sku'] === 'dry-run'))->andReturn([$candidate]);
        $this->app->instance(KaspiProductionCandidateClient::class, $client);

        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldReceive('prepareRefreshCandidate')->once()->andReturnUsing(function ($row, $debug, $progress, $allowEmpty) {
            $this->assertFalse($allowEmpty);
            $progress('resolved', ['url' => 'https://kaspi.kz/shop/p/item-1/']);

            return ['payload' => ['sku' => $row['sku'], 'content' => ['description' => 'Text']],
                'preview' => ['kaspi_images_parsed' => 7, 'images_to_send' => 7]];
        });
        $bridge->shouldNotReceive('send');
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);
        $before = (array) DB::table('products')->find($id);

        $this->assertSame(0, Artisan::call('kaspi:push-new-products', ['--dry-run' => true]));
        $rows = $this->outputRows();
        $this->assertSame(['sku' => 'dry-run', 'product_id' => $id, 'name' => 'Product dry-run',
            'storefront_url' => 'https://autohimiki.kz/product/product-'.$id, 'status' => 'ready', 'reason' => null,
            'kaspi_images_parsed' => 7, 'images_to_send' => 7], $rows[0]);
        $this->assertSame(1, $rows[1]['summary']['total_candidates']);
        $this->assertSame(0, $rows[1]['summary']['processed']);
        $this->assertSame($before, (array) DB::table('products')->find($id));
    }

    public function test_execute_continues_after_skip_and_reports_image_mismatch(): void
    {
        $first = $this->candidate(1, 'first');
        $second = $this->candidate(2, 'second');
        $client = Mockery::mock(KaspiProductionCandidateClient::class);
        $client->shouldReceive('page')->once()->andReturn(['data' => [$first, $second], 'next_cursor' => null]);
        $client->shouldReceive('fetch')->twice()->andReturnUsing(fn ($options) => [$options['sku'] === 'first' ? $first : $second]);
        $this->app->instance(KaspiProductionCandidateClient::class, $client);

        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldReceive('prepareRefreshCandidate')->twice()->andReturnUsing(function ($row, $debug, $progress, $allowEmpty) {
            $this->assertTrue($allowEmpty);
            if ($row['sku'] === 'first') {
                throw new \RuntimeException('resolver_not_verified_widget_not_found');
            }
            $progress('resolved', []);

            return ['payload' => ['sku' => 'second', 'content' => ['description' => '', 'images' => range(1, 5)]],
                'preview' => ['kaspi_images_parsed' => 5, 'images_to_send' => 5]];
        });
        $bridge->shouldReceive('send')->once()->andReturn(['sku' => 'second', 'product_id' => 2, 'status' => 'imported',
            'reason' => null, 'images_sent' => 5, 'images_stored' => 4, 'cleanup_warnings' => []]);
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);

        $this->assertSame(0, Artisan::call('kaspi:push-new-products', ['--execute' => true, '--diagnostics' => true]));
        $rows = $this->outputRows();
        $this->assertSame('resolver_not_verified_widget_not_found', $rows[0]['reason']);
        $this->assertTrue($rows[1]['image_count_mismatch']);
        $this->assertSame(['total_candidates' => 2, 'resolved' => 1, 'ready' => 1, 'processed' => 1,
            'imported' => 1, 'unchanged' => 0, 'updated' => 1, 'skipped' => 1, 'failed' => 0,
            'empty_description' => 1, 'images_sent' => 5, 'images_stored' => 4, 'image_mismatches' => 1], $rows[2]['summary']);
    }

    public function test_invalid_mode_and_category_assignment_race_fail_closed(): void
    {
        foreach ([[], ['--dry-run' => true, '--execute' => true]] as $options) {
            $this->assertSame(1, Artisan::call('kaspi:push-new-products', $options));
        }
        $candidate = $this->candidate(1, 'moved');
        $client = Mockery::mock(KaspiProductionCandidateClient::class);
        $client->shouldReceive('page')->once()->andReturn(['data' => [$candidate], 'next_cursor' => null]);
        $client->shouldReceive('fetch')->once()->andReturn([]);
        $this->app->instance(KaspiProductionCandidateClient::class, $client);
        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldNotReceive('prepareRefreshCandidate');
        $bridge->shouldNotReceive('send');
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);

        $this->assertSame(0, Artisan::call('kaspi:push-new-products', ['--execute' => true]));
        $rows = $this->outputRows();
        $this->assertSame('product_left_new_products_scope', $rows[0]['reason']);
        $this->assertSame(1, $rows[1]['summary']['skipped']);
    }
}
