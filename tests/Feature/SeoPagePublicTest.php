<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Simulates the production scenario: seo_pages table WITHOUT the new columns
 * from migrations 000001-000003 (intro, canonical_url, hero_image, etc.),
 * but WITH linked products — the exact state of the production DB.
 */
class SeoPagePublicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'seo_page_category', 'seo_page_product', 'seo_pages',
            'product_images', 'products', 'brands', 'categories',
            'redirects', 'settings',
        ] as $t) {
            Schema::dropIfExists($t);
        }

        // HandleRedirects middleware queries this table on every request
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
            $table->decimal('old_price', 10, 2)->nullable();
            $table->boolean('in_stock')->default(true);
            $table->unsignedInteger('quantity')->default(0);
            $table->text('short_description')->nullable();
            $table->json('attributes')->nullable();
            $table->string('main_image')->nullable();
            $table->string('main_image_webp')->nullable();
            $table->string('main_image_alt')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('h1')->nullable();
            $table->text('seo_text')->nullable();
            $table->string('canonical_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_hit')->default(false);
            $table->boolean('is_popular')->default(false);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_images', function ($table) {
            $table->id();
            $table->foreignId('product_id')->nullable();
            $table->string('path');
            $table->string('path_webp')->nullable();
            $table->string('alt')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // MINIMAL seo_pages schema — matches what production has AFTER
        // migrations 000001-000003 (with the new columns added then)
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
            $table->boolean('is_active')->default(true);
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
    }

    protected function tearDown(): void
    {
        foreach ([
            'seo_page_category', 'seo_page_product', 'seo_pages',
            'product_images', 'products', 'brands', 'categories',
            'redirects', 'settings',
        ] as $t) {
            Schema::dropIfExists($t);
        }

        parent::tearDown();
    }

    public function test_seo_page_loads_with_old_schema_and_no_products(): void
    {
        DB::table('seo_pages')->insert([
            'title'     => 'Антигель для дизельного топлива',
            'slug'      => 'antigel-test',
            'h1'        => 'Как выбрать антигель',
            'seo_text'  => '<p>Подробная статья.</p>',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/antigel-test')->assertOk();
    }

    public function test_seo_page_loads_with_old_schema_and_linked_products(): void
    {
        $brandId = DB::table('brands')->insertGetId([
            'name' => 'PRO-TEC', 'slug' => 'pro-tec', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $catId = DB::table('categories')->insertGetId([
            'name' => 'Антигели', 'slug' => 'antigeli', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'name'        => 'PRO-TEC P2010 Антигель',
            'slug'        => 'pro-tec-p2010',
            'sku'         => 'P2010',
            'brand_id'    => $brandId,
            'category_id' => $catId,
            'price'       => 3500,
            'in_stock'    => true,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $pageId = DB::table('seo_pages')->insertGetId([
            'title'      => 'Антигель для дизельного топлива',
            'slug'       => 'antigel-test-products',
            'h1'         => 'Как выбрать антигель',
            'seo_text'   => '<h2 id="vidy">Виды антигелей</h2><p>Подробная статья.</p>',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('seo_page_product')->insert([
            'seo_page_id' => $pageId,
            'product_id'  => $productId,
        ]);

        $response = $this->get('/antigel-test-products');
        $response->assertOk();
        $response->assertSee('Как выбрать антигель');
        $response->assertSee('PRO-TEC P2010 Антигель');
    }

    public function test_seo_page_loads_with_old_schema_and_faq(): void
    {
        DB::table('seo_pages')->insert([
            'title'      => 'Антигель с FAQ',
            'slug'       => 'antigel-faq-test',
            'seo_text'   => '<p>Текст статьи.</p>',
            'faq'        => json_encode([
                ['question' => 'Что такое антигель?', 'answer' => 'Присадка для топлива.'],
                ['question' => 'Как дозировать?', 'answer' => '1 мл на 10 л.'],
            ]),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/antigel-faq-test');
        $response->assertOk();
        $response->assertSee('Что такое антигель?');
        $response->assertSee('Частые вопросы');
    }

    public function test_seo_page_handles_completely_empty_fields(): void
    {
        DB::table('seo_pages')->insert([
            'title'      => 'Минимальная страница',
            'slug'       => 'minimal-page',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/minimal-page')->assertOk();
    }

    public function test_seo_page_with_products_having_attributes(): void
    {
        $brandId = DB::table('brands')->insertGetId([
            'name' => 'LAVR', 'slug' => 'lavr', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'name'        => 'LAVR LN2107',
            'slug'        => 'lavr-ln2107',
            'sku'         => 'LN2107',
            'brand_id'    => $brandId,
            'price'       => 2800,
            'in_stock'    => true,
            'is_active'   => true,
            // Attributes stored as JSON object (old format from Kaspi)
            'attributes'  => json_encode(['Вязкость' => '5W-30', 'Объём' => '1 л']),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $pageId = DB::table('seo_pages')->insertGetId([
            'title'      => 'Страница с товаром с атрибутами',
            'slug'       => 'page-with-attrs',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('seo_page_product')->insert([
            'seo_page_id' => $pageId,
            'product_id'  => $productId,
        ]);

        $this->get('/page-with-attrs')->assertOk();
    }

    public function test_seo_text_with_html_entities_renders_heading_not_literal_tags(): void
    {
        // Production bug: seo_text stored with HTML entities (&lt;h2&gt;) instead of
        // actual tags. {!! !!} outputs &lt;h2&gt; which the browser shows as "<h2>".
        // Fix: html_entity_decode() in the blade @php block.
        DB::table('seo_pages')->insert([
            'title'     => 'Страница с сущностями',
            'slug'      => 'entities-test',
            'seo_text'  => '&lt;h2&gt;Заголовок статьи&lt;/h2&gt;&lt;p&gt;Текст параграфа.&lt;/p&gt;',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get('/entities-test')->assertOk()->getContent();

        // After decode: <h2> must be rendered as an actual tag, not entity text
        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('Заголовок статьи', $html);

        // Should NOT contain the raw entity string as visible text
        $this->assertStringNotContainsString('&lt;h2&gt;', $html);
    }

    public function test_toc_auto_generates_id_for_h2_without_id(): void
    {
        DB::table('seo_pages')->insert([
            'title'     => 'Страница с TOC',
            'slug'      => 'toc-test',
            'seo_text'  => '<h2>Первый раздел</h2><p>Контент.</p><h2>Второй раздел</h2><p>Ещё.</p>',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get('/toc-test')->assertOk()->getContent();

        // Auto-generated IDs section-1, section-2 should be injected into headings
        $this->assertStringContainsString('id="section-1"', $html);
        $this->assertStringContainsString('id="section-2"', $html);

        // TOC links should reference those IDs
        $this->assertStringContainsString('href="#section-1"', $html);
        $this->assertStringContainsString('Первый раздел', $html);
    }

    public function test_comparison_table_shown_when_show_comparison_and_two_products(): void
    {
        $p1 = DB::table('products')->insertGetId([
            'name' => 'Товар 1', 'slug' => 'tovar-1', 'sku' => 'T001',
            'price' => 1000, 'in_stock' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $p2 = DB::table('products')->insertGetId([
            'name' => 'Товар 2', 'slug' => 'tovar-2', 'sku' => 'T002',
            'price' => 2000, 'in_stock' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pageId = DB::table('seo_pages')->insertGetId([
            'title'           => 'Сравнение',
            'slug'            => 'compare-test',
            'show_comparison' => true,
            'is_active'       => true,
            'created_at'      => now(), 'updated_at' => now(),
        ]);
        DB::table('seo_page_product')->insert([
            ['seo_page_id' => $pageId, 'product_id' => $p1],
            ['seo_page_id' => $pageId, 'product_id' => $p2],
        ]);

        $html = $this->get('/compare-test')->assertOk()->getContent();

        $this->assertStringContainsString('ah-seoart-compare-tbl', $html);
        $this->assertStringContainsString('Товар 1', $html);
        $this->assertStringContainsString('Товар 2', $html);
        $this->assertStringContainsString('Сравнение товаров', $html);
    }
}
