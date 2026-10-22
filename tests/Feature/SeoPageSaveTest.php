<?php

namespace Tests\Feature;

use App\Models\SeoPage;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeoPageSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('seo_page_product');
        Schema::dropIfExists('seo_page_category');
        Schema::dropIfExists('seo_pages');

        Schema::create('seo_pages', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('h1')->nullable();
            $table->text('intro')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->longText('seo_text')->nullable();
            $table->json('faq')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('canonical_url')->nullable();
            $table->string('hero_image')->nullable();
            $table->string('hero_image_alt', 255)->nullable();
            $table->string('hero_label', 120)->nullable();
            $table->string('cta_heading', 255)->nullable();
            $table->string('cta_description', 500)->nullable();
            $table->string('cta_link', 500)->nullable();
            $table->string('cta_button_text', 100)->nullable();
            $table->boolean('show_comparison')->default(false);
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
        Schema::dropIfExists('seo_page_product');
        Schema::dropIfExists('seo_page_category');
        Schema::dropIfExists('seo_pages');

        parent::tearDown();
    }

    public function test_seo_page_saves_canonical_url(): void
    {
        $page = SeoPage::create([
            'title'         => 'Антигель для дизельного топлива',
            'slug'          => 'antigel-dlya-dizelnogo-topliva',
            'canonical_url' => 'https://autohimiki.kz/antigel-dlya-dizelnogo-topliva',
        ]);

        $this->assertDatabaseHas('seo_pages', [
            'slug'          => 'antigel-dlya-dizelnogo-topliva',
            'canonical_url' => 'https://autohimiki.kz/antigel-dlya-dizelnogo-topliva',
        ]);

        $this->assertSame(
            'https://autohimiki.kz/antigel-dlya-dizelnogo-topliva',
            $page->fresh()->canonical_url
        );
    }

    public function test_seo_title_falls_back_to_title(): void
    {
        $page = SeoPage::create([
            'title' => 'Антигель для дизельного топлива',
            'slug'  => 'test-antigel',
        ]);

        // defaultSeoTitle() appends site name when meta_title is empty
        $this->assertSame('Антигель для дизельного топлива | Autohimiki.kz', $page->seoTitle());
    }

    public function test_seo_title_uses_meta_title_when_set(): void
    {
        $page = SeoPage::create([
            'title'      => 'Антигель для дизельного топлива',
            'slug'       => 'test-antigel-meta',
            'meta_title' => 'Купить антигель в Алматы',
        ]);

        $this->assertSame('Купить антигель в Алматы', $page->seoTitle());
    }

    public function test_seo_h1_falls_back_to_title(): void
    {
        $page = SeoPage::create([
            'title' => 'Антигель для дизельного топлива',
            'slug'  => 'test-h1-fallback',
        ]);

        $this->assertSame('Антигель для дизельного топлива', $page->seoH1());
    }

    public function test_seo_h1_uses_h1_when_set(): void
    {
        $page = SeoPage::create([
            'title' => 'Антигель',
            'slug'  => 'test-h1-set',
            'h1'    => 'Антигель для дизельного топлива — купить в Алматы',
        ]);

        $this->assertSame('Антигель для дизельного топлива — купить в Алматы', $page->seoH1());
    }

    public function test_hero_and_cta_fields_save_and_retrieve_correctly(): void
    {
        $page = SeoPage::create([
            'title'           => 'Тест',
            'slug'            => 'test-hero-cta',
            'hero_image'      => 'seo/hero/test.jpg',
            'hero_image_alt'  => 'Антигель фото',
            'hero_label'      => 'Советы по автохимии',
            'cta_heading'     => 'Готовы к зиме?',
            'cta_description' => 'Выбор антигелей для дизеля.',
            'cta_link'        => 'https://autohimiki.kz/catalog',
            'cta_button_text' => 'Смотреть каталог',
            'show_comparison' => true,
        ]);

        $fresh = $page->fresh();

        $this->assertSame('seo/hero/test.jpg', $fresh->hero_image);
        $this->assertSame('Советы по автохимии', $fresh->hero_label);
        $this->assertSame('Готовы к зиме?', $fresh->cta_heading);
        $this->assertSame('Смотреть каталог', $fresh->cta_button_text);
        $this->assertTrue($fresh->show_comparison);
    }
}
