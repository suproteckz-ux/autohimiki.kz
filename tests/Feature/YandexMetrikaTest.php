<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Models\Setting;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class YandexMetrikaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2025_01_013_create_settings_table.php'))->up();
        config(['services.yandex_metrika.counter_id' => null]);
        CacheService::forgetSettings();
    }

    private function analytics(): string
    {
        return view('components.ui.analytics')->render();
    }

    public function test_missing_setting_and_config_render_no_counter(): void
    {
        $this->assertSame('', trim($this->analytics()));
    }

    public function test_site_setting_renders_counter_and_all_supplied_options(): void
    {
        Setting::create(['key' => 'yandex_metrika_id', 'value' => '112644243']);
        $html = $this->analytics();

        foreach ([
            'https://mc.yandex.ru/metrika/tag.js?id=112644243',
            "ym(112644243, 'init'", 'https://mc.yandex.ru/watch/112644243',
            'ssr: true', 'webvisor: true', 'clickmap: true', "ecommerce: 'dataLayer'",
            'accurateTrackBounce: true', 'trackLinks: true',
            'referrer: document.referrer', 'url: location.href',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertSame(1, substr_count($html, 'ym(112644243'));
    }

    public function test_config_is_used_when_setting_is_missing(): void
    {
        config(['services.yandex_metrika.counter_id' => '123456']);
        $this->assertStringContainsString("ym(123456, 'init'", $this->analytics());
    }

    public function test_site_setting_takes_priority_over_config(): void
    {
        config(['services.yandex_metrika.counter_id' => '123456']);
        Setting::create(['key' => 'yandex_metrika_id', 'value' => '112644243']);
        $html = $this->analytics();
        $this->assertStringContainsString("ym(112644243, 'init'", $html);
        $this->assertStringNotContainsString('123456', $html);
    }

    public function test_invalid_setting_cannot_inject_javascript_or_enable_fallback(): void
    {
        config(['services.yandex_metrika.counter_id' => '123456']);
        foreach (['', 'abc', '0', '-1', '1.5', '1e8', '00123', "112644243\n", '9999999999999999', '1);alert(1);//', '</script><script>alert(1)</script>'] as $value) {
            Setting::updateOrCreate(['key' => 'yandex_metrika_id'], ['value' => $value]);
            $this->assertSame('', trim($this->analytics()), 'Unsafe value: '.$value);
        }
    }

    public function test_invalid_config_cannot_inject_javascript(): void
    {
        config(['services.yandex_metrika.counter_id' => '1);alert(1);//']);
        $this->assertSame('', trim($this->analytics()));
    }

    public function test_create_update_and_delete_refresh_warmed_settings_cache(): void
    {
        $this->assertSame('', trim($this->analytics()));
        $setting = Setting::create(['key' => 'yandex_metrika_id', 'value' => '112644243']);
        $this->assertStringContainsString("ym(112644243, 'init'", $this->analytics());
        $setting->update(['value' => '123456']);
        $html = $this->analytics();
        $this->assertStringContainsString("ym(123456, 'init'", $html);
        $this->assertStringNotContainsString('112644243', $html);
        $setting->delete();
        $this->assertSame('', trim($this->analytics()));
    }

    public function test_public_csp_allows_metrika_without_removing_existing_providers(): void
    {
        $response = (new SecurityHeadersMiddleware)->handle(Request::create('/'), fn () => new Response('OK'));
        $directives = [];
        foreach (explode('; ', $response->headers->get('Content-Security-Policy')) as $directive) {
            [$name, $value] = explode(' ', $directive, 2);
            $directives[$name] = explode(' ', $value);
        }
        foreach (['script-src', 'connect-src', 'frame-src', 'child-src'] as $name) {
            foreach (['https://mc.yandex.ru', 'https://mc.yandex.kz', 'https://mc.webvisor.com', 'https://mc.webvisor.org'] as $origin) {
                $this->assertContains($origin, $directives[$name]);
            }
            $this->assertNotContains('*', $directives[$name]);
            $this->assertNotContains('https:', $directives[$name]);
        }
        $this->assertContains('https://yastatic.net', $directives['script-src']);
        $this->assertContains('wss://mc.yandex.ru', $directives['connect-src']);
        $this->assertContains('blob:', $directives['frame-src']);
        $this->assertContains('blob:', $directives['child-src']);
        $this->assertContains('https://www.googletagmanager.com', $directives['script-src']);
        $this->assertContains('https://www.google-analytics.com', $directives['connect-src']);
        $this->assertContains('https://connect.facebook.net', $directives['script-src']);
        $this->assertContains('https://kaspi.kz', $directives['frame-src']);
    }
}
