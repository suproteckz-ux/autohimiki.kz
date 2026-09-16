<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiLocalPageCollector;
use App\Services\Kaspi\KaspiLocalUrlResolver;
use App\Services\Kaspi\KaspiRefreshPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
