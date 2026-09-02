<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KaspiWidgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kaspi.merchant_id' => 'Avtoximiya', 'services.kaspi.city_id' => '750000000']);
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php',
            '2025_01_012_create_redirects_table.php', '2025_01_013_create_settings_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('categories')->insert(['id' => 1, 'name' => 'Category', 'slug' => 'category']);
        DB::table('products')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Manual product',
            'slug' => 'manual-product', 'sku' => 'РТ-00000808', 'price' => 5500, 'quantity' => 7,
            'in_stock' => true, 'is_active' => true, 'description' => 'Manual description',
            'attributes' => '{"Тип":"Ручное значение"}', 'meta_title' => 'Manual SEO',
            'main_image' => 'manual.jpg', 'main_image_webp' => 'manual.webp',
            'updated_at' => '2026-01-01 00:00:00']);
        DB::table('product_images')->insert(['product_id' => 1, 'path' => 'gallery.jpg']);
    }

    public function test_product_page_renders_widget_without_any_product_or_gallery_writes(): void
    {
        $before = DB::table('products')->get()->toJson();
        $images = DB::table('product_images')->get()->toJson();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(update|insert|delete)/i', $query->sql) && str_contains($query->sql, 'product')) {
                $writes[] = $query->sql;
            }
        });
        $response = $this->get('/product/manual-product')->assertOk();
        $response->assertSee('data-merchant-sku="РТ-00000808"', false)
            ->assertSee('data-merchant-code="Avtoximiya"', false)
            ->assertSee('data-city="750000000"', false)
            ->assertSee('data-template="button"', false)
            ->assertSee('data-style="desktop"', false)
            ->assertSee('ks-wi_ext.js', false);
        $this->assertSame(1, substr_count($response->getContent(), 'ks-wi_ext.js'));
        $this->assertSame([], $writes);
        $this->assertSame($before, DB::table('products')->get()->toJson());
        $this->assertSame($images, DB::table('product_images')->get()->toJson());
    }

    public function test_missing_merchant_keeps_product_page_working_without_widget_or_script(): void
    {
        config(['services.kaspi.merchant_id' => null]);
        $this->get('/product/manual-product')->assertOk()
            ->assertDontSee('class="ks-widget"', false)->assertDontSee('ks-wi_ext.js', false);
    }

    public function test_exact_sku_config_and_single_script_for_multiple_components(): void
    {
        $product = Product::findOrFail(1);
        $product->sku = '000РТ-Ab  00808';
        config(['services.kaspi.merchant_id' => 'AnotherMerchant', 'services.kaspi.city_id' => '710000000']);
        $html = Blade::render('<x-kaspi.credit-button :product="$product" /><x-kaspi.credit-button :product="$product" />@stack("scripts")', compact('product'));
        $this->assertSame(2, substr_count($html, 'data-merchant-sku="000РТ-Ab  00808"'));
        $this->assertStringContainsString('data-merchant-code="AnotherMerchant"', $html);
        $this->assertStringContainsString('data-city="710000000"', $html);
        $this->assertSame(1, substr_count($html, 'ks-wi_ext.js'));
        $this->assertStringNotContainsString('30366013', $html);
    }

    public function test_blank_sku_or_city_and_unpublished_product_do_not_render_widget(): void
    {
        $product = Product::findOrFail(1);
        $product->sku = '   ';
        $this->assertStringNotContainsString('ks-widget', Blade::render('<x-kaspi.credit-button :product="$product" />', compact('product')));
        $product->sku = 'РТ-00000808';
        config(['services.kaspi.city_id' => null]);
        $this->assertStringNotContainsString('ks-widget', Blade::render('<x-kaspi.credit-button :product="$product" />', compact('product')));
        DB::table('products')->where('id', 1)->update(['is_active' => false]);
        $this->get('/product/manual-product')->assertNotFound();
    }

    public function test_csp_allows_only_required_kaspi_script_and_frame_origins(): void
    {
        $response = $this->get('/product/manual-product')->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');
        $directives = [];
        foreach (explode('; ', $csp) as $directive) {
            $parts = explode(' ', $directive);
            $directives[array_shift($parts)] = $parts;
        }
        $this->assertSame(["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://www.googletagmanager.com',
            'https://www.google-analytics.com', 'https://connect.facebook.net', 'https://mc.yandex.ru', 'https://kaspi.kz'], $directives['script-src']);
        $this->assertSame(['https://kaspi.kz'], $directives['frame-src']);
        $this->assertSame(["'self'", 'https://www.google-analytics.com', 'https://mc.yandex.ru', 'https://api.whatsapp.com'], $directives['connect-src']);
        $this->assertSame(["'none'"], $directives['object-src']);
        $this->assertStringNotContainsString('*', $csp);
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
