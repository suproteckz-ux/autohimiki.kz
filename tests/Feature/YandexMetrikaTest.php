<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class YandexMetrikaTest extends TestCase
{
    public function test_counter_does_not_depend_on_config_or_settings_cache(): void
    {
        foreach ([null, '', '99999', '</script><script>alert(1)</script>'] as $value) {
            config(['services.yandex_metrika.counter_id' => $value]);
            Cache::put('site_settings', ['yandex_metrika_id' => $value]);
            $html = view('components.ui.analytics')->render();
            $this->assertStringContainsString("ym(112644243, 'init'", $html);
            $this->assertStringNotContainsString('alert(1)', $html);
            $this->assertStringNotContainsString('99999', $html);
        }
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
