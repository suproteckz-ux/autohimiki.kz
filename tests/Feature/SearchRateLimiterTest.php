<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SearchRateLimiterTest extends TestCase
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

        Schema::dropIfExists('settings');
        Schema::create('settings', function ($table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function test_search_named_rate_limiter_is_registered(): void
    {
        $this->assertNotNull(app(RateLimiter::class)->limiter('search'));
    }

    public function test_search_route_does_not_throw_missing_rate_limiter(): void
    {
        $this->get('/search')
            ->assertOk();
    }

    public function test_search_route_still_rate_limits_excessive_requests(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get('/search')->assertOk();
        }

        $this->get('/search')->assertTooManyRequests();
    }
}
