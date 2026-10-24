<?php

namespace Tests\Feature;

use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomepageCollectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '2025_01_001_create_categories_table.php',
            '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php',
            '2025_01_004_create_product_images_table.php',
            '2025_01_012_create_redirects_table.php',
            '2025_01_013_create_settings_table.php',
        ] as $file) {
            (require database_path('migrations/' . $file))->up();
        }

        DB::table('categories')->insert(['id' => 1, 'name' => 'Тест', 'slug' => 'test', 'is_active' => true]);
        DB::table('settings')->insert(['key' => 'phone', 'value' => '']);

        CacheService::forgetProductContent();
    }

    // ──────────────────────────────────────────────────────────────
    // Хиты продаж
    // ──────────────────────────────────────────────────────────────

    public function test_hits_exclude_zero_quantity(): void
    {
        DB::table('products')->insert([
            ['category_id' => 1, 'sku' => 'H1', 'name' => 'Хит в наличии',  'slug' => 'h1',
             'price' => 1000, 'quantity' => 3, 'main_image' => 'a.jpg', 'is_active' => true, 'is_hit' => true],
            ['category_id' => 1, 'sku' => 'H2', 'name' => 'Хит без наличия', 'slug' => 'h2',
             'price' => 1000, 'quantity' => 0, 'main_image' => 'b.jpg', 'is_active' => true, 'is_hit' => true],
        ]);

        $hits = CacheService::homepageHits();

        $this->assertCount(1, $hits);
        $this->assertSame('Хит в наличии', $hits->first()->name);
    }

    public function test_hits_include_positive_quantity(): void
    {
        DB::table('products')->insert([
            ['category_id' => 1, 'sku' => 'H3', 'name' => 'Хит qty=1', 'slug' => 'h3',
             'price' => 1000, 'quantity' => 1, 'is_active' => true, 'is_hit' => true],
            ['category_id' => 1, 'sku' => 'H4', 'name' => 'Хит qty=99', 'slug' => 'h4',
             'price' => 1000, 'quantity' => 99, 'is_active' => true, 'is_hit' => true],
        ]);

        $hits = CacheService::homepageHits();

        $this->assertCount(2, $hits);
    }

    public function test_hits_quantity_field_is_present_in_result(): void
    {
        DB::table('products')->insert([
            'category_id' => 1, 'sku' => 'HQ', 'name' => 'Хит с кол-вом', 'slug' => 'hq',
            'price' => 1000, 'quantity' => 7, 'is_active' => true, 'is_hit' => true,
        ]);

        $hit = CacheService::homepageHits()->first();

        // quantity must be in SELECT so card.blade.php can display correct stock status
        $this->assertNotNull($hit->quantity, 'quantity must be selected — card uses it for in_stock display');
        $this->assertSame(7, (int) $hit->quantity);
    }

    public function test_hits_limited_to_8(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            DB::table('products')->insert([
                'category_id' => 1, 'sku' => "H{$i}", 'name' => "Хит {$i}", 'slug' => "hit-{$i}",
                'price' => 1000, 'quantity' => 1, 'is_active' => true, 'is_hit' => true,
            ]);
        }

        $this->assertCount(8, CacheService::homepageHits());
    }

    public function test_empty_hits_does_not_crash(): void
    {
        // No products with is_hit=true — section should be empty, not an error
        $hits = CacheService::homepageHits();

        $this->assertCount(0, $hits);
        $this->get('/')->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    // Новинки
    // ──────────────────────────────────────────────────────────────

    public function test_new_products_exclude_zero_quantity(): void
    {
        DB::table('products')->insert([
            ['category_id' => 1, 'sku' => 'N1', 'name' => 'Новинка в наличии',  'slug' => 'n1',
             'price' => 1000, 'quantity' => 5, 'main_image' => 'n1.jpg', 'is_active' => true,
             'created_at' => now()],
            ['category_id' => 1, 'sku' => 'N2', 'name' => 'Новинка без наличия', 'slug' => 'n2',
             'price' => 1000, 'quantity' => 0, 'main_image' => 'n2.jpg', 'is_active' => true,
             'created_at' => now()],
        ]);

        $new = CacheService::homepageNewProducts();

        $this->assertCount(1, $new);
        $this->assertSame('Новинка в наличии', $new->first()->name);
    }

    public function test_new_products_exclude_no_image(): void
    {
        DB::table('products')->insert([
            ['category_id' => 1, 'sku' => 'NI1', 'name' => 'С фото',    'slug' => 'ni1',
             'price' => 1000, 'quantity' => 2, 'main_image' => 'img.jpg', 'is_active' => true,
             'created_at' => now()],
            ['category_id' => 1, 'sku' => 'NI2', 'name' => 'Без фото',  'slug' => 'ni2',
             'price' => 1000, 'quantity' => 2, 'main_image' => null,      'is_active' => true,
             'created_at' => now()],
        ]);

        $new = CacheService::homepageNewProducts();

        $this->assertCount(1, $new);
        $this->assertSame('С фото', $new->first()->name);
    }

    public function test_new_products_include_gallery_only_image(): void
    {
        $noMainId = DB::table('products')->insertGetId([
            'category_id' => 1, 'sku' => 'NG1', 'name' => 'Галерея без main', 'slug' => 'ng1',
            'price' => 1000, 'quantity' => 1, 'main_image' => null, 'is_active' => true,
            'created_at' => now(),
        ]);
        DB::table('product_images')->insert(['product_id' => $noMainId, 'path' => 'gallery.jpg']);

        $new = CacheService::homepageNewProducts();

        $this->assertCount(1, $new);
        $this->assertSame('Галерея без main', $new->first()->name);
    }

    public function test_new_products_sorted_by_created_at_desc_then_id_desc(): void
    {
        $yesterday = now()->subDay();
        $today     = now();

        DB::table('products')->insert([
            ['category_id' => 1, 'sku' => 'NS1', 'name' => 'Старый',    'slug' => 'ns1',
             'price' => 1000, 'quantity' => 1, 'main_image' => 'a.jpg', 'is_active' => true,
             'created_at' => $yesterday, 'updated_at' => $yesterday],
            ['category_id' => 1, 'sku' => 'NS2', 'name' => 'Новый',     'slug' => 'ns2',
             'price' => 1000, 'quantity' => 1, 'main_image' => 'b.jpg', 'is_active' => true,
             'created_at' => $today, 'updated_at' => $today],
            ['category_id' => 1, 'sku' => 'NS3', 'name' => 'Новый же',  'slug' => 'ns3',
             'price' => 1000, 'quantity' => 1, 'main_image' => 'c.jpg', 'is_active' => true,
             'created_at' => $today, 'updated_at' => $today],
        ]);

        $names = CacheService::homepageNewProducts()->pluck('name')->values()->all();

        // Most recent created_at first; among equal timestamps, higher id first
        $this->assertSame('Старый', end($names), 'Oldest product must come last');
        $this->assertSame('Новый же', $names[0], 'Higher id of same timestamp must be first');
    }

    public function test_new_products_not_limited_to_is_new_flag(): void
    {
        // Products without is_new=true but with quantity and image must appear
        for ($i = 1; $i <= 3; $i++) {
            DB::table('products')->insert([
                'category_id' => 1, 'sku' => "NF{$i}", 'name' => "Обычный {$i}", 'slug' => "nf-{$i}",
                'price' => 1000, 'quantity' => 1, 'main_image' => "img{$i}.jpg",
                'is_active' => true, 'is_new' => false,
                'created_at' => now(),
            ]);
        }

        $new = CacheService::homepageNewProducts();

        $this->assertCount(3, $new, 'Products without is_new flag must be included if they meet other criteria');
    }

    public function test_new_products_limited_to_8(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            DB::table('products')->insert([
                'category_id' => 1, 'sku' => "NL{$i}", 'name' => "Новинка {$i}", 'slug' => "nl-{$i}",
                'price' => 1000, 'quantity' => 1, 'main_image' => "img{$i}.jpg",
                'is_active' => true, 'created_at' => now(),
            ]);
        }

        $this->assertCount(8, CacheService::homepageNewProducts());
    }

    public function test_empty_new_products_does_not_crash(): void
    {
        // No active products with quantity>0 and image
        $new = CacheService::homepageNewProducts();

        $this->assertCount(0, $new);
        $this->get('/')->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    // Кеш
    // ──────────────────────────────────────────────────────────────

    public function test_forget_product_content_clears_hits_and_new_cache(): void
    {
        DB::table('products')->insert([
            'category_id' => 1, 'sku' => 'C1', 'name' => 'Товар 1', 'slug' => 'c1',
            'price' => 1000, 'quantity' => 1, 'main_image' => 'c1.jpg',
            'is_active' => true, 'is_hit' => true, 'created_at' => now(),
        ]);

        // Prime both caches
        $this->assertCount(1, CacheService::homepageHits());
        $this->assertCount(1, CacheService::homepageNewProducts());

        // Add a second product directly to DB (bypasses Observer)
        DB::table('products')->insert([
            'category_id' => 1, 'sku' => 'C2', 'name' => 'Товар 2', 'slug' => 'c2',
            'price' => 1000, 'quantity' => 1, 'main_image' => 'c2.jpg',
            'is_active' => true, 'is_hit' => true, 'created_at' => now(),
        ]);

        // Cache still returns stale 1-item result
        $this->assertCount(1, CacheService::homepageHits(), 'Cache must serve stale data before invalidation');

        // Invalidate
        CacheService::forgetProductContent();

        // Now returns fresh 2-item result
        $this->assertCount(2, CacheService::homepageHits(), 'Cache must return fresh data after forgetProductContent()');
        $this->assertCount(2, CacheService::homepageNewProducts(), 'New products cache must also be refreshed');
    }

    public function test_new_products_quantity_field_is_present_in_result(): void
    {
        DB::table('products')->insert([
            'category_id' => 1, 'sku' => 'NQ1', 'name' => 'Новинка', 'slug' => 'nq1',
            'price' => 1000, 'quantity' => 4, 'main_image' => 'img.jpg',
            'is_active' => true, 'created_at' => now(),
        ]);

        $product = CacheService::homepageNewProducts()->first();

        $this->assertNotNull($product->quantity, 'quantity must be in SELECT so card shows correct stock status');
        $this->assertSame(4, (int) $product->quantity);
    }
}
