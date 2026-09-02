<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiLocalNodeProcessRunner;
use App\Services\Kaspi\KaspiLocalUrlResolver;
use App\Services\Kaspi\KaspiProductionCandidateClient;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class KaspiLocalResolverTest extends TestCase
{
    private array $candidate = ['sku' => 'РТ-00001158', 'storefront_url' => 'https://autohimiki.kz/product/test'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kaspi.production_base_url' => 'https://autohimiki.kz',
            'services.kaspi.merchant_id' => 'Avtoximiya', 'services.kaspi.city_id' => '750000000']);
        Http::preventStrayRequests();
    }

    private function resolver(array $process): KaspiLocalUrlResolver
    {
        $guard = Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed')->once();
        $runner = Mockery::mock(KaspiLocalNodeProcessRunner::class);
        $runner->shouldReceive('run')->once()->with(Mockery::on(fn ($args) => $args === [
            'url' => $this->candidate['storefront_url'], 'sku' => 'РТ-00001158', 'merchant' => 'Avtoximiya',
            'city' => '750000000', 'headless' => 'false']))->andReturn($process);

        return new KaspiLocalUrlResolver($runner, $guard);
    }

    public function test_resolved_uses_exact_sku_and_visible_browser_arguments(): void
    {
        $result = $this->resolver(['exit_code' => 0, 'stdout' => json_encode([
            'status' => 'resolved', 'captcha' => false, 'url' => 'https://kaspi.kz/shop/p/test-123/?c=750000000']),
            'stderr' => 'Bearer secret-should-not-appear'])->resolve($this->candidate, true);
        $this->assertSame('resolved', $result['status']);
        $this->assertSame('https://kaspi.kz/shop/p/test-123/', $result['kaspi_url']);
        $this->assertSame('РТ-00001158', $result['sku']);
        $this->assertStringNotContainsString('secret-should-not-appear', json_encode($result));
    }

    public function test_diagnostic_failures_never_return_a_success_url(): void
    {
        foreach ([
            [['status' => 'widget_not_found', 'captcha' => false], 'widget_not_found'],
            [['status' => 'resolved', 'captcha' => true, 'url' => 'https://kaspi.kz/shop/p/test-123/'], 'captcha_detected'],
            [['status' => 'resolved', 'captcha' => false, 'url' => 'https://kaspi.kz.evil.test/shop/p/test-123/'], 'invalid_kaspi_url'],
            [['status' => 'resolved', 'captcha' => false, 'url' => 'https://kaspi.kz/login'], 'invalid_kaspi_url'],
            [['status' => 'ambiguous_urls', 'captcha' => false], 'ambiguous_urls'],
            [['status' => 'iframe_not_loaded', 'captcha' => false], 'iframe_not_loaded'],
        ] as [$payload, $expected]) {
            $result = $this->resolver(['exit_code' => 0, 'stdout' => json_encode($payload)])
                ->resolve($this->candidate);
            $this->assertSame($expected, $result['status']);
            $this->assertNull($result['kaspi_url']);
        }
        foreach ([['stdout' => '{bad', 'exit_code' => 0], ['stdout' => '', 'timeout' => true]] as $process) {
            $result = $this->resolver($process)->resolve($this->candidate);
            $this->assertSame(isset($process['timeout']) ? 'timeout' : 'malformed_node_output', $result['status']);
            $this->assertNull($result['kaspi_url']);
        }
    }

    public function test_guard_requires_local_windows_cli_and_opt_in(): void
    {
        $windows = new class extends KaspiLocalBrowserGuard
        {
            protected function platform(): string
            {
                return 'Windows';
            }
        };
        foreach ([['production', true], ['testing', true], ['local', false]] as [$env, $enabled]) {
            $this->app->instance('env', $env);
            config(['services.kaspi.local_browser_enabled' => $enabled]);
            try {
                $windows->assertAllowed();
                $this->fail('Expected guard rejection');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('local_browser_disabled', $e->getMessage());
            }
        }
        $this->app->instance('env', 'local');
        config(['services.kaspi.local_browser_enabled' => true]);
        $windows->assertAllowed();
        $linux = new class extends KaspiLocalBrowserGuard
        {
            protected function platform(): string
            {
                return 'Linux';
            }
        };
        $this->expectExceptionMessage('local_browser_disabled');
        $linux->assertAllowed();
    }

    public function test_child_environment_excludes_api_token_and_other_secrets(): void
    {
        $_ENV['KASPI_INTERNAL_API_TOKEN'] = 'secret';
        $_ENV['DB_PASSWORD'] = 'database-secret';
        try {
            $runner = new class(new KaspiLocalBrowserGuard) extends KaspiLocalNodeProcessRunner
            {
                public function environment(): array
                {
                    return $this->browserEnvironment();
                }
            };
            $environment = $runner->environment();
            $this->assertFalse($environment['KASPI_INTERNAL_API_TOKEN']);
            $this->assertFalse($environment['DB_PASSWORD']);
            $this->assertSame('1', $environment['NO_COLOR']);
        } finally {
            unset($_ENV['KASPI_INTERNAL_API_TOKEN'], $_ENV['DB_PASSWORD']);
        }
    }

    public function test_command_guard_prevents_any_network_and_browser_run(): void
    {
        $client = Mockery::mock(KaspiProductionCandidateClient::class);
        $client->shouldNotReceive('fetch');
        $this->app->instance(KaspiProductionCandidateClient::class, $client);
        config(['services.kaspi.local_browser_enabled' => false]);
        $this->artisan('kaspi:resolve-production', ['--sku' => 'РТ-00001158'])->assertFailed();
    }

    public function test_command_is_read_only_and_stops_batch_on_captcha(): void
    {
        $guard = Mockery::mock(KaspiLocalBrowserGuard::class);
        $guard->shouldReceive('assertAllowed')->once();
        $client = Mockery::mock(KaspiProductionCandidateClient::class);
        $client->shouldReceive('fetch')->once()->andReturn([$this->candidate, $this->candidate]);
        $resolver = Mockery::mock(KaspiLocalUrlResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn($this->candidate + [
            'status' => 'captcha_detected', 'kaspi_url' => null, 'diagnostics' => ['reason' => 'captcha_detected']]);
        $this->app->instance(KaspiLocalBrowserGuard::class, $guard);
        $this->app->instance(KaspiProductionCandidateClient::class, $client);
        $this->app->instance(KaspiLocalUrlResolver::class, $resolver);
        $this->artisan('kaspi:resolve-production', ['--sku' => 'РТ-00001158', '--limit' => '2', '--dry-run' => true])->assertFailed();
    }
}
