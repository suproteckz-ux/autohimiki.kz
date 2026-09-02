<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiProductionBridgeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class KaspiMassCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kaspi.internal_api_token' => 'never-log-this-secret', 'services.kaspi.production_base_url' => 'https://autohimiki.kz']);
        Http::preventStrayRequests();
        $guard = Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed');
        $this->app->instance(KaspiLocalBrowserGuard::class, $guard);
    }

    private function row(string $sku): array
    {
        return ['sku' => $sku, 'name' => 'Product', 'storefront_url' => 'https://autohimiki.kz/product/'.$sku];
    }

    private function bridge(array &$order, array $failures = []): void
    {
        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldReceive('prepareCandidate')->andReturnUsing(function ($row, $debug, $resolved) use (&$order, $failures) {
            $sku = $row['sku'];
            $order[] = 'prepare:'.$sku;
            if (isset($failures[$sku]) && str_starts_with($failures[$sku], 'resolver_')) {
                throw new \RuntimeException($failures[$sku]);
            }
            $resolved();
            if (isset($failures[$sku]) && ! str_starts_with($failures[$sku], 'post_')) {
                throw new \RuntimeException($failures[$sku]);
            }

            return ['preview' => ['sku' => $sku], 'payload' => ['sku' => $sku]];
        });
        $bridge->shouldReceive('send')->andReturnUsing(function ($payload) use (&$order, $failures) {
            $sku = $payload['sku'];
            $order[] = 'send:'.$sku;
            if (isset($failures[$sku])) {
                throw new \RuntimeException($failures[$sku]);
            }

            return ['sku' => $sku, 'status' => 'imported', 'description' => 'updated', 'gallery_added' => 2, 'attributes_added' => 7];
        });
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);
    }

    private function runBatch(array $options, int $exit = 0): array
    {
        $this->assertSame($exit, Artisan::call('kaspi:push-production', $options));
        $output = Artisan::output();
        $this->assertStringNotContainsString('never-log-this-secret', $output);
        $lines = array_values(array_filter(explode("\n", trim($output))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_all_traverses_pages_sequentially_and_duplicates_are_processed_once(): void
    {
        $order = [];
        $this->bridge($order);
        Http::fake(['*' => Http::sequence()
            ->push(['data' => [$this->row('a'), $this->row('b')], 'next_cursor' => 20])
            ->push(['data' => [$this->row('a'), $this->row('c')], 'next_cursor' => 40])
            ->push(['data' => [$this->row('d')], 'next_cursor' => null])]);
        $result = $this->runBatch(['--all' => true]);
        $this->assertSame(['prepare:a', 'send:a', 'prepare:b', 'send:b', 'prepare:c', 'send:c', 'prepare:d', 'send:d'], $order);
        foreach (['received' => 5, 'unique_candidates' => 4, 'processed' => 4, 'resolved' => 4, 'imported' => 4,
            'failed' => 0, 'duplicates_skipped' => 1, 'images_added' => 8, 'attributes_added' => 28, 'descriptions_added' => 4] as $key => $value) {
            $this->assertSame($value, $result['summary'][$key], $key);
        }
        $this->assertNull($result['pagination_error']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r['cursor'] === 40);
    }

    public function test_each_failure_continues_to_later_success_and_summary_counts_failures(): void
    {
        $failures = ['a' => 'resolver_not_verified_widget_not_found', 'b' => 'resolver_not_verified_timeout',
            'c' => 'resolver_not_verified_captcha_detected', 'd' => 'resolver_not_verified_kaspi_url_not_opened',
            'e' => 'collector_failed', 'f' => 'parser_images_missing', 'g' => 'invalid_payload', 'h' => 'post_import_http_422'];
        $order = [];
        $this->bridge($order, $failures);
        Http::fake(['*' => Http::response(['data' => array_map(fn ($s) => $this->row($s), [...array_keys($failures), 'last']), 'next_cursor' => null])]);
        $result = $this->runBatch(['--all' => true], 1);
        $this->assertSame('send:last', end($order));
        foreach (['processed' => 9, 'resolved' => 5, 'imported' => 1, 'failed' => 8, 'captcha' => 1, 'no_widget' => 1,
            'no_kaspi_url' => 1, 'collector_failed' => 1, 'parser_failed' => 1, 'validation_failed' => 1, 'import_failed' => 1] as $key => $value) {
            $this->assertSame($value, $result['summary'][$key], $key);
        }
        $this->assertCount(8, $result['failed_skus']);
    }

    public function test_limit_ten_stops_after_ten_without_fetching_next_page(): void
    {
        $order = [];
        $this->bridge($order);
        Http::fake(function ($request) {
            $this->assertSame(10, $request['limit']);

            return Http::response(['data' => array_map(fn ($n) => $this->row('sku'.$n), range(1, 10)), 'next_cursor' => 10]);
        });
        $result = $this->runBatch(['--limit' => '10']);
        $this->assertSame(10, $result['summary']['processed']);
        Http::assertSentCount(1);
    }

    public function test_repeated_cursor_aborts_pagination_with_diagnostic(): void
    {
        $order = [];
        $this->bridge($order);
        Http::fake(['*' => Http::sequence()->push(['data' => [$this->row('a')], 'next_cursor' => 5])
            ->push(['data' => [$this->row('b')], 'next_cursor' => 5])]);
        $result = $this->runBatch(['--all' => true], 1);
        $this->assertSame('candidate_invalid_cursor', $result['pagination_error']);
        $this->assertSame(['prepare:a', 'send:a'], $order);
        Http::assertSentCount(2);
    }

    public function test_no_mode_conflicting_modes_and_invalid_limits_refuse_before_network(): void
    {
        Http::fake();
        foreach ([[], ['--all' => true, '--sku' => 'a'], ['--all' => true, '--limit' => 1], ['--limit' => 0], ['--limit' => '-1'], ['--limit' => 'abc']] as $options) {
            $this->assertSame(1, Artisan::call('kaspi:push-production', $options));
        }
        Http::assertNothingSent();
    }

    public function test_arbitrary_exact_sku_and_batch_dry_run_preserved(): void
    {
        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldReceive('prepare')->once()->with('РТ-00000074', false)->andReturn(['preview' => [], 'payload' => ['sku' => 'РТ-00000074']]);
        $bridge->shouldReceive('send')->once()->with(['sku' => 'РТ-00000074'])->andReturn(['status' => 'imported']);
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);
        $this->assertSame(0, Artisan::call('kaspi:push-production', ['--sku' => 'РТ-00000074']));
        $order = [];
        $this->bridge($order);
        Http::fake(['*' => Http::response(['data' => [$this->row('a')], 'next_cursor' => null])]);
        $result = $this->runBatch(['--all' => true, '--dry-run' => true]);
        $this->assertSame(['prepare:a'], $order);
        $this->assertSame(0, $result['summary']['imported']);
    }

    public function test_preserved_results_and_untrusted_failure_text_are_counted_without_secret_output(): void
    {
        Http::fake(['*' => Http::response(['data' => [$this->row('bad'), $this->row('kept')], 'next_cursor' => null])]);
        $bridge = Mockery::mock(KaspiProductionBridgeService::class);
        $bridge->shouldReceive('prepareCandidate')->andReturnUsing(function ($row, $debug, $resolved) {
            $resolved();

            return ['preview' => [], 'payload' => $row];
        });
        $bridge->shouldReceive('send')->andReturnUsing(function ($row) {
            if ($row['sku'] === 'bad') {
                throw new \RuntimeException('never-log-this-secret');
            }

            return ['sku' => 'kept', 'status' => 'unchanged', 'description' => 'preserved', 'gallery_added' => 0, 'attributes_added' => 0];
        });
        $this->app->instance(KaspiProductionBridgeService::class, $bridge);
        $result = $this->runBatch(['--all' => true], 1);
        $this->assertSame(1, $result['summary']['preserved']);
        $this->assertSame(1, $result['summary']['import_failed']);
        $this->assertSame(0, $result['summary']['images_added']);
        $this->assertSame(0, $result['summary']['descriptions_added']);
        $this->assertSame(0, $result['summary']['attributes_added']);
        $this->assertSame('kaspi_enrichment_failed', $result['failed_skus'][0]['reason']);
    }
}
