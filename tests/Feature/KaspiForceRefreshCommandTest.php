<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiLocalPageCollector;
use App\Services\Kaspi\KaspiLocalUrlResolver;
use App\Services\Kaspi\KaspiRefreshManifest;
use App\Services\Kaspi\KaspiRefreshPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Tests\TestCase;

class KaspiForceRefreshCommandTest extends TestCase
{
    private array $rows = [];

    private array $parsed = [];

    private array $resolveErrors = [];

    private array $parseErrors = [];

    private array $postErrors = [];

    private array $unsafe = [];

    private array $events = [];

    private string $lastOutput = '';

    private bool $exactOnly = false;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['services.kaspi.production_base_url' => 'https://autohimiki.kz', 'services.kaspi.internal_api_token' => 'never-print-secret',
            'services.kaspi.merchant_id' => 'merchant', 'services.kaspi.city_id' => 'city']);
        $guard = Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed');
        $this->app->instance(KaspiLocalBrowserGuard::class, $guard);
        $resolver = Mockery::mock(KaspiLocalUrlResolver::class);
        $resolver->shouldReceive('resolve')->andReturnUsing(function ($row) {
            $sku = $row['sku'];
            $this->events[] = 'resolve:'.$sku;

            return ['sku' => $sku, 'storefront_url' => $row['storefront_url'], 'status' => $this->resolveErrors[$sku] ?? 'resolved',
                'kaspi_url' => 'https://kaspi.kz/shop/p/cleaner-'.$row['product_id'].'/'];
        });
        $this->app->instance(KaspiLocalUrlResolver::class, $resolver);
        $collector = Mockery::mock(KaspiLocalPageCollector::class);
        $collector->shouldReceive('collectRefreshUrl')->andReturnUsing(function ($url) {
            preg_match('/-(\d+)\/$/', $url, $match);
            $sku = 'sku-'.$match[1];
            $this->events[] = 'parse:'.$sku;
            if (isset($this->parseErrors[$sku])) {
                throw new \RuntimeException($this->parseErrors[$sku]);
            }

            return ['url' => $url] + $this->parsed[$sku];
        });
        $this->app->instance(KaspiLocalPageCollector::class, $collector);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer never-print-secret'));
            if (str_contains($request->url(), '/candidates')) {
                if ($this->exactOnly) {
                    $this->assertNotEmpty($request['sku'], 'SKU-file must never request a full candidate scan');
                }
                $this->assertSame('true', $request['force_content_refresh']);
                $query = $request->data();
                $rows = array_values(array_filter($this->rows, fn ($row) => (! isset($query['sku']) || $query['sku'] === $row['sku']) && $row['product_id'] > (int) $request['cursor']));
                $page = array_slice($rows, 0, (int) $request['limit']);

                return Http::response(['data' => $page, 'next_cursor' => count($rows) > count($page) ? end($page)['product_id'] : null]);
            }
            $sku = $request['sku'];
            if ($request->method() === 'GET') {
                return Http::response($this->rows[$sku] + ['attributes_safe' => ! isset($this->unsafe[$sku]), 'reason' => null]);
            }
            $this->events[] = 'post:'.$sku;
            $this->assertTrue($request['force_content_refresh']);
            if (isset($this->postErrors[$sku])) {
                return Http::response(['error' => $this->postErrors[$sku], 'trace' => 'never-print-secret'], 409);
            }

            return Http::response(['sku' => $sku, 'product_id' => $this->rows[$sku]['product_id'], 'status' => 'imported', 'cleanup_warnings' => []]);
        });
        $this->add(1);
    }

    private function add(int $id): void
    {
        $sku = 'sku-'.$id;
        $this->rows[$sku] = ['product_id' => $id, 'sku' => $sku, 'name' => 'Manual '.$id, 'storefront_url' => 'https://autohimiki.kz/product/manual-'.$id,
            'state_fingerprint' => hash('sha256', $sku), 'current_photo_count' => 2, 'current_description_present' => true, 'current_attribute_count' => 2];
        $this->parsed[$sku] = ['title' => 'Kaspi '.$id, 'description' => '<p>Fresh</p>', 'images' => ['https://resources.cdn-kaspi.kz/img/m/p/'.$id.'.png'],
            'attributes' => [['name' => 'Цвет', 'value' => 'New']]];
    }

    private function runForce(array $options = [], int $exit = 0): array
    {
        $actual = Artisan::call('kaspi:push-production', $options + ['--all' => true, '--force-content-refresh' => true]);
        $output = $this->lastOutput = Artisan::output();
        $this->assertSame($exit, $actual, $output);
        $this->assertStringNotContainsString('never-print-secret', $output);
        $lines = array_values(array_filter(explode("\n", trim($output))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    private function runFile(string $contents, array $options = [], int $exit = 0): array
    {
        $file = tmpfile();
        fwrite($file, $contents);
        $path = stream_get_meta_data($file)['uri'];
        $this->exactOnly = true;
        try {
            $actual = Artisan::call('kaspi:push-production', $options + ['--sku-file' => $path, '--force-content-refresh' => true]);
            $this->lastOutput = Artisan::output();
            $this->assertSame($exit, $actual, $this->lastOutput);

            return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                explode("\n", trim($this->lastOutput)));
        } finally {
            fclose($file);
        }
    }

    public function test_file_scope_reads_bom_blank_lines_deduplicates_and_never_scans_other_products(): void
    {
        $this->add(2);
        $this->add(3);
        $rows = $this->runFile("\xEF\xBB\xBFsku-2\r\n\nsku-1\nsku-2\nmissing\n", ['--execute' => true]);
        $this->assertSame(['sku_file_count' => 3, 'found' => 2, 'missing' => 1], $rows[0]);
        $this->assertSame(['sku' => 'missing', 'status' => 'skipped', 'reason' => 'product_not_found'], $rows[3]);
        $this->assertSame(['total_requested' => 3, 'resolved' => 2, 'ready' => 2, 'skipped' => 1,
            'processed' => 2, 'updated' => 2, 'failed' => 0, 'empty_description' => 0], end($rows)['summary']);
        $this->assertSame(['resolve:sku-2', 'parse:sku-2', 'post:sku-2', 'resolve:sku-1', 'parse:sku-1', 'post:sku-1'], $this->events);
        Http::assertNotSent(fn ($r) => ! in_array($r['sku'], ['sku-1', 'sku-2', 'missing'], true));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['force_content_refresh'] === true
            && isset($r['product_id'], $r['state_fingerprint']));
    }

    public function test_file_scope_imports_empty_description_skips_unverified_widget_then_continues(): void
    {
        $this->add(2);
        $this->add(3);
        $this->parsed['sku-1']['description'] = '<script>empty()</script>';
        $this->resolveErrors['sku-2'] = 'widget_not_found';
        $rows = $this->runFile("sku-1\nsku-2\nsku-3", ['--execute' => true]);
        $this->assertSame('imported', $rows[1]['status']);
        $this->assertSame('resolver_not_verified_widget_not_found', $rows[2]['reason']);
        $this->assertSame(['total_requested' => 3, 'resolved' => 2, 'ready' => 2, 'skipped' => 1,
            'processed' => 2, 'updated' => 2, 'failed' => 0, 'empty_description' => 1], end($rows)['summary']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && $r['sku'] === 'sku-2');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['sku'] === 'sku-1'
            && $r['allow_empty_description'] === true && $r['content']['description'] === '');
    }

    public function test_file_force_dry_run_still_skips_empty_description(): void
    {
        $this->parsed['sku-1']['description'] = '';
        $rows = $this->runFile('sku-1', ['--dry-run' => true]);
        $this->assertSame('empty_description', $rows[1]['reason']);
        $this->assertSame(0, end($rows)['summary']['ready']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_file_scope_without_execute_has_no_network_or_import_and_dry_run_has_no_posts(): void
    {
        $this->assertSame(1, Artisan::call('kaspi:push-production', ['--sku-file' => 'unused', '--force-content-refresh' => true]));
        Http::assertNothingSent();
        $rows = $this->runFile('sku-1', ['--dry-run' => true]);
        $this->assertSame(1, end($rows)['summary']['ready']);
        $this->assertSame(0, end($rows)['summary']['processed']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_file_scope_invalid_or_conflicting_options_fail_before_network(): void
    {
        foreach ([['--all' => true], ['--sku' => 'sku-1'], ['--limit' => 1], ['--approve' => str_repeat('a', 64)], ['--dry-run' => true]] as $conflict) {
            $this->assertSame(1, Artisan::call('kaspi:push-production', $conflict + ['--sku-file' => 'unused', '--execute' => true]));
        }
        $this->assertSame(1, Artisan::call('kaspi:push-production', ['--sku' => 'sku-1', '--execute' => true]));
        $rows = $this->runFile("\n\r\n", ['--execute' => true], 1);
        $this->assertSame('sku_file_empty', end($rows)['batch_error']);
        $rows = $this->runFile("sku-1\ninvalid\tsku\n", ['--execute' => true], 1);
        $this->assertSame('invalid_exact_sku', end($rows)['batch_error']);
        Http::assertNothingSent();
    }

    public function test_file_scope_preserves_numeric_sku_leading_zeroes_and_rejects_invalid_utf8(): void
    {
        $rows = $this->runFile("sku-1\n\xFF\n", ['--execute' => true], 1);
        $this->assertSame('sku_file_invalid_utf8', end($rows)['batch_error']);
        Http::assertNothingSent();
        $rows = $this->runFile("00000000680\nРТ-00001286", ['--execute' => true]);
        $this->assertSame(['sku_file_count' => 2, 'found' => 0, 'missing' => 2], $rows[0]);
        $this->assertSame('00000000680', $rows[1]['sku']);
        $this->assertSame('РТ-00001286', $rows[2]['sku']);
        $this->assertSame([], $this->events);
        Http::assertSent(fn ($r) => $r['sku'] === '00000000680');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_file_scope_import_failure_is_counted_and_later_sku_still_runs(): void
    {
        $this->add(2);
        $this->postErrors['sku-1'] = 'state_changed';
        $rows = $this->runFile("sku-1\nsku-2", ['--execute' => true], 1);
        $this->assertSame('failed', $rows[1]['status']);
        $this->assertSame(2, end($rows)['summary']['processed']);
        $this->assertSame(1, end($rows)['summary']['updated']);
        $this->assertSame(1, end($rows)['summary']['failed']);
    }

    public function test_file_scope_paces_preflight_for_133_exact_lookups(): void
    {
        $skus = array_map(fn ($id) => 'missing-'.$id, range(1, 133));
        $rows = $this->runFile(implode("\n", $skus), ['--execute' => true]);
        $this->assertSame(['sku_file_count' => 133, 'found' => 0, 'missing' => 133], $rows[0]);
        $this->assertSame(133, end($rows)['summary']['skipped']);
        Sleep::assertSleptTimes(132);
        Http::assertSentCount(133);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_dry_run_exact_fields_counters_no_posts_and_deterministic_approval(): void
    {
        $first = $this->runForce(['--dry-run' => true]);
        $output = $this->lastOutput;
        $row = json_decode(explode("\n", trim($output))[0], true);
        foreach (['id', 'sku', 'name', 'current_photo_count', 'kaspi_photo_count', 'current_description_present', 'kaspi_description_present',
            'current_attribute_count', 'kaspi_attribute_count', 'photo_action', 'description_action', 'attributes_action', 'status', 'reason', 'state_fingerprint'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame('https://autohimiki.kz/product/manual-1', $row['storefront_url']);
        $this->assertSame('https://kaspi.kz/shop/p/cleaner-1/', $row['kaspi_url']);
        foreach (['total_candidates', 'with_kaspi_source', 'resolved', 'parsed', 'ready', 'planned'] as $key) {
            $this->assertSame(1, $first['summary'][$key], $key);
        }
        foreach (['processed', 'updated', 'skipped', 'failed', 'cleanup_warnings', 'resolve_failed', 'parse_failed', 'empty_description', 'empty_attributes'] as $key) {
            $this->assertSame(0, $first['summary'][$key], $key);
        }
        $this->assertSame($first['approval_hash'], $this->runForce(['--dry-run' => true])['approval_hash']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_finalization_and_diagnostics_use_stderr_and_manifest_is_cleaned(): void
    {
        $manifest = new KaspiRefreshManifest;
        $stream = (new \ReflectionProperty($manifest, 'stream'))->getValue($manifest);
        $path = stream_get_meta_data($stream)['uri'];
        $this->app->instance(KaspiRefreshManifest::class, $manifest);
        $stdout = new BufferedOutput;
        $stderr = new BufferedOutput;
        $output = new class($stdout) extends ConsoleOutput
        {
            public function __construct(private BufferedOutput $buffer)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                $this->buffer->write($message, $newline);
            }
        };
        $output->setErrorOutput($stderr);
        $this->assertSame(0, Artisan::call('kaspi:push-production', [
            '--limit' => 1, '--force-content-refresh' => true, '--dry-run' => true, '--diagnostics' => true,
        ], $output));
        $lines = explode("\n", trim($stdout->fetch()));
        foreach ($lines as $line) {
            $this->assertIsArray(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        }
        $summary = json_decode(end($lines), true);
        $this->assertSame(1, $summary['summary']['ready']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['approval_hash']);
        $progress = $stderr->fetch();
        $this->assertStringContainsString('[finalizing] ready=1', $progress);
        $this->assertStringContainsString('[finalizing] canonical manifest complete', $progress);
        $this->assertStringContainsString('[finalizing] approval hash calculated', $progress);
        $this->assertMatchesRegularExpression('/memory_bytes=\d+ peak_bytes=\d+ ready=1 manifest_bytes=\d+/', $progress);
        $this->assertStringNotContainsString('never-print-secret', $progress);
        $this->assertStringNotContainsString('Fresh', $progress);
        $this->assertFileDoesNotExist($path);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public static function manifestFailures(): array
    {
        return [['write'], ['read'], ['mismatch']];
    }

    #[DataProvider('manifestFailures')]
    public function test_spool_failure_or_mismatch_prints_summary_cleans_temp_and_blocks_posts(string $failure): void
    {
        $manifest = new class($failure) extends KaspiRefreshManifest
        {
            public function __construct(private string $failure)
            {
                parent::__construct();
            }

            public function append(array $payload): void
            {
                parent::append($payload);
                if ($this->failure === 'write') {
                    throw new \RuntimeException('manifest_write_failed');
                }
            }

            public function approval(): string
            {
                if ($this->failure === 'read') {
                    throw new \RuntimeException('manifest_read_failed');
                }

                return parent::approval();
            }
        };
        $stream = (new \ReflectionProperty(KaspiRefreshManifest::class, 'stream'))->getValue($manifest);
        $path = stream_get_meta_data($stream)['uri'];
        $this->app->instance(KaspiRefreshManifest::class, $manifest);
        $result = $this->runForce(['--approve' => str_repeat('0', 64)], 1);
        $expected = $failure === 'mismatch' ? 'approval_mismatch' : 'manifest_'.$failure.'_failed';
        $this->assertSame($expected, $result['batch_error']);
        $this->assertSame(0, $result['summary']['processed']);
        $this->assertFileDoesNotExist($path);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_all_plans_verified_before_posts_and_one_failure_does_not_stop_next_product(): void
    {
        $this->add(2);
        $hash = $this->runForce(['--dry-run' => true])['approval_hash'];
        $this->postErrors['sku-1'] = 'state_changed';
        $this->events = [];
        $result = $this->runForce(['--approve' => $hash], 1);
        $this->assertSame(['resolve:sku-1', 'parse:sku-1', 'resolve:sku-2', 'parse:sku-2', 'post:sku-1', 'post:sku-2'], $this->events);
        $this->assertSame(2, $result['summary']['processed']);
        $this->assertSame(1, $result['summary']['updated']);
        $this->assertSame(1, $result['summary']['failed']);
        $this->assertSame(['sku' => 'sku-1', 'product_id' => 1, 'status' => 'failed', 'reason' => 'state_changed'], $result['failures'][0]);
    }

    public function test_paginated_ready_set_finishes_with_summary_and_no_posts(): void
    {
        foreach (range(2, 205) as $id) {
            $this->add($id);
        }
        $result = $this->runForce(['--dry-run' => true]);
        $this->assertSame(205, $result['summary']['total_candidates']);
        $this->assertSame(205, $result['summary']['ready']);
        $this->assertSame(205, $result['summary']['planned']);
        $this->assertNull($result['batch_error']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['approval_hash']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_unordered_candidate_page_aborts_without_approval_or_posts(): void
    {
        $this->add(2);
        $this->rows = array_reverse($this->rows, true);
        $result = $this->runForce(['--dry-run' => true], 1);
        $this->assertSame('candidate_invalid_row', $result['batch_error']);
        $this->assertNull($result['approval_hash']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public static function drift(): array
    {
        return [['state'], ['description'], ['images'], ['attributes'], ['set'], ['resolver']];
    }

    #[DataProvider('drift')]
    public function test_any_approval_drift_blocks_entire_set_before_first_post(string $kind): void
    {
        $hash = $this->runForce(['--dry-run' => true])['approval_hash'];
        switch ($kind) {
            case 'state': $this->rows['sku-1']['state_fingerprint'] = str_repeat('c', 64);
                break;
            case 'description': $this->parsed['sku-1']['description'] = 'Changed';
                break;
            case 'images': $this->parsed['sku-1']['images'][] = 'https://resources.cdn-kaspi.kz/img/m/p/other.png';
                break;
            case 'attributes': $this->parsed['sku-1']['attributes'][0]['value'] = 'Changed';
                break;
            case 'set': $this->add(2);
                break;
            case 'resolver': $this->resolveErrors['sku-1'] = 'widget_not_found';
                break;
        }
        $result = $this->runForce(['--approve' => $hash], 1);
        $this->assertSame('approval_mismatch', $result['batch_error']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public static function resolutionFailures(): array
    {
        return [['widget_not_found'], ['widget_mismatch'], ['timeout'], ['invalid_kaspi_url'], ['ambiguous_urls']];
    }

    #[DataProvider('resolutionFailures')]
    public function test_real_widget_required_and_resolution_failure_skips_without_parsing(string $reason): void
    {
        $this->resolveErrors['sku-1'] = $reason;
        $result = $this->runForce(['--dry-run' => true]);
        $this->assertSame(1, $result['summary']['skipped']);
        $this->assertSame(1, $result['summary']['resolve_failed']);
        $this->assertSame(0, $result['summary']['ready']);
        $this->assertSame(['resolve:sku-1'], $this->events);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_parser_failures_and_empty_content_counts_continue_to_later_candidates(): void
    {
        foreach (range(2, 5) as $id) {
            $this->add($id);
        }
        $this->parseErrors['sku-1'] = 'parser_images_missing';
        $this->parsed['sku-2']['description'] = '<script>bad()</script>';
        $this->parsed['sku-3']['attributes'] = [];
        $this->unsafe['sku-4'] = true;
        $result = $this->runForce(['--dry-run' => true]);
        foreach (['total_candidates' => 5, 'with_kaspi_source' => 5, 'resolved' => 5, 'parsed' => 4, 'parse_failed' => 1, 'ready' => 1,
            'no_images' => 1, 'empty_description' => 1, 'empty_attributes' => 1, 'attributes_ambiguous' => 0, 'skipped' => 4] as $key => $value) {
            $this->assertSame($value, $result['summary'][$key], $key);
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_scope_and_approval_requirements_fail_before_network(): void
    {
        foreach ([[], ['--force-content-refresh' => true], ['--all' => true, '--sku' => 'x'], ['--all' => true, '--force-content-refresh' => true],
            ['--all' => true, '--force-content-refresh' => true, '--approve' => 'bad'], ['--all' => true, '--approve' => str_repeat('a', 64)],
            ['--all' => true, '--force-content-refresh' => true, '--dry-run' => true, '--approve' => str_repeat('a', 64)]] as $options) {
            $this->assertSame(1, Artisan::call('kaspi:push-production', $options));
        }
        Http::assertNothingSent();
    }

    public function test_exact_sku_and_limit_scopes(): void
    {
        $this->add(2);
        foreach ([['--sku' => 'sku-2'], ['--limit' => '1']] as $scope) {
            $this->assertSame(0, Artisan::call('kaspi:push-production', $scope + ['--force-content-refresh' => true, '--dry-run' => true]));
            $lines = explode("\n", trim(Artisan::output()));
            $result = json_decode(end($lines), true);
            $this->assertSame(1, $result['summary']['ready']);
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_unknown_server_error_is_not_exposed(): void
    {
        $hash = $this->runForce(['--dry-run' => true])['approval_hash'];
        $this->postErrors['sku-1'] = 'never-print-secret';
        $result = $this->runForce(['--approve' => $hash], 1);
        $this->assertSame('post_import_http_409', $result['failures'][0]['reason']);
    }

    public function test_approval_binds_normalized_payload_and_is_independent_of_object_key_order(): void
    {
        $a = ['product_id' => 1, 'sku' => 'exact', 'content' => ['description' => 'x', 'images' => ['a', 'b']]];
        $b = ['content' => ['images' => ['a', 'b'], 'description' => 'x'], 'sku' => 'exact', 'product_id' => 1];
        $this->assertSame(KaspiRefreshPolicy::approval([$a]), KaspiRefreshPolicy::approval([$b]));
        $b['content']['images'] = ['b', 'a'];
        $this->assertNotSame(KaspiRefreshPolicy::approval([$a]), KaspiRefreshPolicy::approval([$b]));
    }
}
