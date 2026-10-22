<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies all application/ld+json blocks contain valid JSON.
 *
 * Production bug: breadcrumbs.blade.php used Illuminate\Support\Js::from()
 * which wraps objects in JSON.parse('...') — valid JavaScript but NOT valid JSON.
 * Google Search Console reports "structured data that cannot be parsed".
 *
 * Secondary: product.blade.php lacked JSON_HEX_TAG, so </script> in descriptions
 * would prematurely close the script block and produce truncated/invalid JSON.
 */
class JsonLdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['product_images', 'products', 'brands', 'categories', 'redirects', 'settings'] as $t) {
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
            $table->string('image')->nullable();
            $table->string('image_webp')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('h1')->nullable();
            $table->text('seo_text_top')->nullable();
            $table->text('seo_text_bottom')->nullable();
            $table->string('canonical_url')->nullable();
            $table->json('faq')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('brands', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->string('logo_webp')->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('h1')->nullable();
            $table->string('canonical_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
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
            $table->longText('description')->nullable();
            $table->text('usage_instructions')->nullable();
            $table->json('attributes')->nullable();
            $table->json('faq')->nullable();
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
    }

    protected function tearDown(): void
    {
        foreach (['product_images', 'products', 'brands', 'categories', 'redirects', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractJsonLdBlocks(string $html): array
    {
        preg_match_all(
            '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
            $html,
            $matches
        );

        return array_map('trim', $matches[1] ?? []);
    }

    private function assertAllBlocksAreValidJson(array $blocks, string $context = ''): void
    {
        $this->assertNotEmpty($blocks, "No application/ld+json blocks found{$context}");

        foreach ($blocks as $i => $block) {
            try {
                $decoded = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
                $this->assertIsArray($decoded, "Block #{$i} must decode to array{$context}");
            } catch (\JsonException $e) {
                $this->fail(
                    "Block #{$i} is not valid JSON{$context}: {$e->getMessage()}\n"
                    . "Content: " . substr($block, 0, 300)
                );
            }
        }
    }

    // ── Category page ─────────────────────────────────────────────────────────

    public function test_category_page_json_ld_is_valid_json(): void
    {
        DB::table('categories')->insert([
            'name' => 'Антигели', 'slug' => 'antigeli', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get('/catalog/antigeli')->assertOk()->getContent();
        $blocks = $this->extractJsonLdBlocks($html);

        $this->assertAllBlocksAreValidJson($blocks, ' on /catalog/antigeli');
    }

    public function test_breadcrumb_list_schema_structure(): void
    {
        DB::table('categories')->insert([
            'name' => 'Шампуни', 'slug' => 'shampuni', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get('/catalog/shampuni')->assertOk()->getContent();
        $blocks = $this->extractJsonLdBlocks($html);

        $breadcrumb = null;
        foreach ($blocks as $block) {
            $decoded = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
            if (($decoded['@type'] ?? '') === 'BreadcrumbList') {
                $breadcrumb = $decoded;
                break;
            }
        }

        $this->assertNotNull($breadcrumb, 'BreadcrumbList block must exist on category page');
        $this->assertSame('https://schema.org', $breadcrumb['@context']);

        $items = $breadcrumb['itemListElement'];
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        $this->assertSame('ListItem', $items[0]['@type']);
        $this->assertSame(1, $items[0]['position']);
        $this->assertSame('Главная', $items[0]['name']);

        $last = end($items);
        $this->assertSame('Шампуни', $last['name']);
    }

    public function test_category_json_ld_with_special_chars_in_name(): void
    {
        // Ampersands and quotes must not corrupt JSON string values
        DB::table('categories')->insert([
            'name' => 'Масла & смазки "Авто"',
            'slug' => 'masla-smazki',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get('/catalog/masla-smazki')->assertOk()->getContent();
        $blocks = $this->extractJsonLdBlocks($html);

        $this->assertAllBlocksAreValidJson($blocks, ' when category name has & and quotes');
    }

    // ── Product page ──────────────────────────────────────────────────────────

    public function test_product_page_json_ld_is_valid_json(): void
    {
        DB::table('products')->insert([
            'name' => 'Моторное масло 5W-30', 'slug' => 'motornoe-maslo', 'sku' => 'OIL-001',
            'price' => 5000, 'in_stock' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get('/product/motornoe-maslo')->assertOk()->getContent();
        $blocks = $this->extractJsonLdBlocks($html);

        $this->assertAllBlocksAreValidJson($blocks, ' on product page');
    }

    public function test_product_schema_type_and_offer_structure(): void
    {
        DB::table('products')->insert([
            'name' => 'Очиститель двигателя', 'slug' => 'ochistitel', 'sku' => 'ENG-001',
            'price' => 3500, 'in_stock' => true, 'quantity' => 10, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get('/product/ochistitel')->assertOk()->getContent();
        $blocks = $this->extractJsonLdBlocks($html);

        $product = null;
        foreach ($blocks as $block) {
            $decoded = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
            if (($decoded['@type'] ?? '') === 'Product') {
                $product = $decoded;
                break;
            }
        }

        $this->assertNotNull($product, 'Product schema block must exist');
        $this->assertSame('https://schema.org', $product['@context']);
        $this->assertSame('Очиститель двигателя', $product['name']);
        $this->assertArrayHasKey('offers', $product);
        $this->assertSame('Offer', $product['offers']['@type']);
        $this->assertSame('KZT', $product['offers']['priceCurrency']);
        $this->assertStringContainsString('InStock', $product['offers']['availability']);
    }

    public function test_product_json_ld_with_script_tag_in_description(): void
    {
        // Without JSON_HEX_TAG, </script> inside a JSON string would terminate
        // the <script type="application/ld+json"> block prematurely, producing
        // truncated (invalid) JSON that Google cannot parse.
        DB::table('products')->insert([
            'name'             => 'Масло "Зима" & <Лето>',
            'slug'             => 'maslo-spetssimvoly',
            'sku'              => 'SPL-001',
            'meta_description' => 'Описание содержит </script> и & спецсимволы',
            'price' => 4000, 'in_stock' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get('/product/maslo-spetssimvoly')->assertOk()->getContent();
        $blocks = $this->extractJsonLdBlocks($html);

        $this->assertAllBlocksAreValidJson(
            $blocks,
            ' when product description contains </script>'
        );
    }
}
