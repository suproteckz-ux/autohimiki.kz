<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleRedirects;
use App\Models\Redirect;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RedirectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('redirects');
        Schema::create('redirects', function ($table) {
            $table->id();
            $table->string('from_url')->unique();
            $table->string('to_url');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Cache::flush();
    }

    public function test_active_scope_uses_is_active_column(): void
    {
        Redirect::create([
            'from_url' => '/old-active',
            'to_url' => '/new-active',
            'is_active' => true,
        ]);

        Redirect::create([
            'from_url' => '/old-inactive',
            'to_url' => '/new-inactive',
            'is_active' => false,
        ]);

        $this->assertSame(
            ['/old-active' => '/new-active'],
            CacheService::redirects()
        );
    }

    public function test_handle_redirects_uses_active_redirects_without_throwing(): void
    {
        Cache::forget(CacheService::KEY_REDIRECTS);

        Redirect::create([
            'from_url' => '/old-page',
            'to_url' => '/new-page',
            'is_active' => true,
        ]);

        $request = Request::create('/old-page', 'GET');
        $response = app(HandleRedirects::class)->handle(
            $request,
            fn () => response('next')
        );

        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringEndsWith('/new-page', $response->headers->get('Location'));
    }
}
