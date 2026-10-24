<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductSortingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '2025_01_001_create_categories_table.php',
            '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php',
            '2025_01_004_create_product_images_table.php',
            '2025_01_008_create_seo_filters_table.php',
            '2025_01_012_create_redirects_table.php',
            '2025_01_013_create_settings_table.php',
        ] as $file) {
            (require database_path('migrations/' . $file))->up();
        }

        DB::table('categories')->insert(['id' => 1, 'name' => 'Тест', 'slug' => 'test-cat', 'is_active' => true]);
        DB::table('settings')->insert(['key' => 'phone', 'value' => '']);

        CacheService::forgetHomepage();
    }

    public function test_homepage_hits_exclude_zero_quantity_products(): void
    {
        DB::table('products')->insert([
            [
                'category_id' => 1, 'sku' => 'HIT-IN', 'name' => 'Хит в наличии',
                'slug' => 'hit-in', 'price' => 1000, 'quantity' => 5,
                'is_active' => true, 'is_hit' => true,
            ],
            [
                'category_id' => 1, 'sku' => 'HIT-OUT', 'name' => 'Хит без наличия',
                'slug' => 'hit-out', 'price' => 1000, 'quantity' => 0,
                'is_active' => true, 'is_hit' => true,
            ],
        ]);

        $hits = CacheService::homepageHits();

        $this->assertCount(1, $hits);
        $this->assertSame('Хит в наличии', $hits->first()->name);
    }

    public function test_storefront_priority_groups_in_stock_with_photo_first(): void
    {
        // All rows must share the same column set for SQLite batch insert
        DB::table('products')->insert([
            ['category_id' => 1, 'sku' => 'G4', 'name' => 'Нет наличия нет фото',  'slug' => 'g4', 'price' => 1000, 'in_stock' => false, 'main_image' => null,       'is_active' => true],
            ['category_id' => 1, 'sku' => 'G1', 'name' => 'Наличие и фото',        'slug' => 'g1', 'price' => 1000, 'in_stock' => true,  'main_image' => 'photo.jpg', 'is_active' => true],
            ['category_id' => 1, 'sku' => 'G3', 'name' => 'Нет наличия есть фото', 'slug' => 'g3', 'price' => 1000, 'in_stock' => false, 'main_image' => 'photo.jpg', 'is_active' => true],
            ['category_id' => 1, 'sku' => 'G2', 'name' => 'Наличие нет фото',      'slug' => 'g2', 'price' => 1000, 'in_stock' => true,  'main_image' => null,        'is_active' => true],
        ]);

        $names = Product::active()
            ->orderByStorefrontPriority()
            ->pluck('name')
            ->values()
            ->all();

        $this->assertSame('Наличие и фото',       $names[0], 'Group 1 (in_stock + photo) must be first');
        $this->assertSame('Наличие нет фото',      $names[1], 'Group 2 (in_stock, no photo) must be second');
        $this->assertSame('Нет наличия есть фото', $names[2], 'Group 3 (no_stock + photo) must be third');
        $this->assertSame('Нет наличия нет фото',  $names[3], 'Group 4 (no_stock, no photo) must be last');
    }

    public function test_storefront_priority_promotes_product_with_only_gallery_image(): void
    {
        $noMainId = DB::table('products')->insertGetId([
            'category_id' => 1, 'sku' => 'GALL', 'name' => 'Галерея без главной',
            'slug' => 'gallery-only', 'price' => 1000, 'in_stock' => true, 'is_active' => true,
        ]);
        // `path` is the column name in product_images
        DB::table('product_images')->insert(['product_id' => $noMainId, 'path' => 'gallery.jpg']);

        DB::table('products')->insert([
            'category_id' => 1, 'sku' => 'BARE', 'name' => 'Совсем без фото',
            'slug' => 'bare', 'price' => 1000, 'in_stock' => true, 'is_active' => true,
        ]);

        $names = Product::active()
            ->orderByStorefrontPriority()
            ->pluck('name')
            ->values()
            ->all();

        $this->assertSame('Галерея без главной', $names[0]);
        $this->assertSame('Совсем без фото',     $names[1]);
    }

    public function test_storefront_priority_scope_uses_single_query_no_n_plus_1(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            DB::table('products')->insert([
                'category_id' => 1, 'sku' => "NQ-{$i}", 'name' => "Product {$i}",
                'slug' => "p-{$i}", 'price' => 1000, 'is_active' => true,
            ]);
        }

        DB::connection()->enableQueryLog();

        Product::active()->orderByStorefrontPriority()->get(['id', 'name', 'in_stock', 'main_image']);

        $log = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $this->assertCount(1, $log, 'Scope must resolve in exactly one SQL query (no N+1)');
        $this->assertStringContainsStringIgnoringCase('EXISTS', $log[0]['query'], 'Query must use EXISTS subquery for image check');
    }

    public function test_explicit_price_asc_sort_overrides_storefront_priority(): void
    {
        DB::table('products')->insert([
            ['category_id' => 1, 'sku' => 'CHEAP',  'name' => 'Дешевле', 'slug' => 'cheap',  'price' => 500,  'in_stock' => false, 'is_active' => true],
            ['category_id' => 1, 'sku' => 'PRICEY', 'name' => 'Дороже',  'slug' => 'pricey', 'price' => 2000, 'in_stock' => true,  'is_active' => true],
        ]);

        $response = $this->get('/catalog/test-cat?sort=price_asc')->assertOk();
        $content  = $response->getContent();

        $this->assertLessThan(
            strpos($content, 'Дороже'),
            strpos($content, 'Дешевле'),
            'price_asc: cheaper product must appear before pricier one regardless of stock status'
        );
    }

    public function test_brand_filter_is_preserved_with_default_sort(): void
    {
        DB::table('brands')->insert(['id' => 1, 'name' => 'TestBrand', 'slug' => 'testbrand', 'is_active' => true]);
        DB::table('products')->insert([
            ['category_id' => 1, 'brand_id' => 1, 'sku' => 'BRANDED', 'name' => 'Брендовый товар', 'slug' => 'branded', 'price' => 1000, 'is_active' => true],
            ['category_id' => 1, 'brand_id' => null, 'sku' => 'PLAIN',   'name' => 'Без бренда',      'slug' => 'plain',   'price' => 1000, 'is_active' => true],
        ]);

        $response = $this->get('/catalog/test-cat?brand=1')->assertOk();

        $response->assertSee('Брендовый товар');
        $response->assertDontSee('Без бренда');
    }

    public function test_pagination_works_with_storefront_priority_sort(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            DB::table('products')->insert([
                'category_id' => 1, 'sku' => "PAG-{$i}", 'name' => "Товар {$i}",
                'slug' => "pag-{$i}", 'price' => 1000, 'is_active' => true,
            ]);
        }

        $this->get('/catalog/test-cat')->assertOk()->assertSee('Товар 1');
        $this->get('/catalog/test-cat?page=2')->assertOk()->assertSee('Товар');
    }
}
