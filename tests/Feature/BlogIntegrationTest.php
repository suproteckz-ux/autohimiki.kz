<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for the unified blog listing that merges BlogPost records
 * with SeoPage records that have show_in_blog = true.
 */
class BlogIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'blog_post_product', 'blog_posts',
            'seo_page_category', 'seo_page_product', 'seo_pages',
            'product_images', 'products', 'brands', 'categories',
            'redirects', 'settings',
        ] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('redirects', function ($table) {
            $table->id();
            $table->string('from_url')->unique();
            $table->string('to_url');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('settings', function ($table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function ($table) {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brands', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function ($table) {
            $table->id();
            $table->foreignId('category_id')->nullable();
            $table->foreignId('brand_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->unique();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('in_stock')->default(true);
            $table->json('attributes')->nullable();
            $table->string('main_image')->nullable();
            $table->string('main_image_webp')->nullable();
            $table->string('main_image_alt')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_hit')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_images', function ($table) {
            $table->id();
            $table->foreignId('product_id')->nullable();
            $table->string('path');
            $table->timestamps();
        });

        Schema::create('seo_pages', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('h1')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->longText('seo_text')->nullable();
            $table->json('faq')->nullable();
            $table->boolean('show_comparison')->default(false);
            $table->boolean('show_in_blog')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('intro', 500)->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('hero_image')->nullable();
            $table->string('hero_label', 120)->nullable();
            $table->string('cta_heading', 255)->nullable();
            $table->string('cta_description', 500)->nullable();
            $table->string('cta_link', 500)->nullable();
            $table->string('cta_button_text', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('seo_page_product', function ($table) {
            $table->id();
            $table->foreignId('seo_page_id');
            $table->foreignId('product_id');
        });

        Schema::create('seo_page_category', function ($table) {
            $table->id();
            $table->foreignId('seo_page_id');
            $table->foreignId('category_id');
        });

        Schema::create('blog_posts', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('h1')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('cover_image')->nullable();
            $table->string('cover_image_webp')->nullable();
            $table->string('cover_image_alt')->nullable();
            $table->longText('content')->nullable();
            $table->json('faq')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('blog_post_product', function ($table) {
            $table->id();
            $table->foreignId('blog_post_id');
            $table->foreignId('product_id');
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'blog_post_product', 'blog_posts',
            'seo_page_category', 'seo_page_product', 'seo_pages',
            'product_images', 'products', 'brands', 'categories',
            'redirects', 'settings',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────

    public function test_blog_index_returns_200(): void
    {
        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('Блог об автохимии', $html);
    }

    public function test_seo_page_with_show_in_blog_appears_in_blog_listing(): void
    {
        DB::table('seo_pages')->insert([
            'title'       => 'Как выбрать антигель',
            'slug'        => 'blog-seo-page-visible',
            'is_active'   => true,
            'show_in_blog'=> true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('Как выбрать антигель', $html);
        $this->assertStringContainsString('/blog-seo-page-visible', $html);
    }

    public function test_seo_page_without_show_in_blog_does_not_appear_in_blog(): void
    {
        DB::table('seo_pages')->insert([
            'title'       => 'Скрытая SEO-страница',
            'slug'        => 'blog-seo-page-hidden',
            'is_active'   => true,
            'show_in_blog'=> false,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringNotContainsString('Скрытая SEO-страница', $html);
    }

    public function test_inactive_seo_page_with_show_in_blog_does_not_appear_in_blog(): void
    {
        DB::table('seo_pages')->insert([
            'title'       => 'Неактивная страница',
            'slug'        => 'blog-seo-page-inactive',
            'is_active'   => false,
            'show_in_blog'=> true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringNotContainsString('Неактивная страница', $html);
    }

    public function test_blog_posts_appear_in_blog_listing(): void
    {
        DB::table('blog_posts')->insert([
            'title'        => 'Обзор Koch Chemie Nano Magic Shampoo',
            'slug'         => 'blog-post-test',
            'is_active'    => true,
            'published_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('Обзор Koch Chemie Nano Magic Shampoo', $html);
    }

    public function test_inactive_blog_post_does_not_appear_in_blog(): void
    {
        DB::table('blog_posts')->insert([
            'title'        => 'Черновик статьи',
            'slug'         => 'blog-post-inactive',
            'is_active'    => false,
            'published_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringNotContainsString('Черновик статьи', $html);
    }

    public function test_blog_and_seo_pages_appear_together(): void
    {
        DB::table('blog_posts')->insert([
            'title'        => 'Статья блога',
            'slug'         => 'blog-together-post',
            'is_active'    => true,
            'published_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('seo_pages')->insert([
            'title'        => 'SEO-статья в блоге',
            'slug'         => 'blog-together-seo',
            'is_active'    => true,
            'show_in_blog' => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('Статья блога', $html);
        $this->assertStringContainsString('SEO-статья в блоге', $html);
    }

    public function test_seo_page_card_links_to_original_url_not_blog_url(): void
    {
        DB::table('seo_pages')->insert([
            'title'        => 'Оригинальный URL тест',
            'slug'         => 'original-url-test',
            'is_active'    => true,
            'show_in_blog' => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $html = $this->get('/blog')->assertOk()->getContent();
        // Card links to the original SEO page URL, not /blog/original-url-test
        $this->assertStringContainsString('/original-url-test', $html);
        $this->assertStringNotContainsString('/blog/original-url-test', $html);
    }
}
