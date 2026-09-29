<?php

namespace Tests\Feature;

use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeSeoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php',
            '2025_01_012_create_redirects_table.php', '2025_01_013_create_settings_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Автошампуни', 'slug' => 'actual-wash-slug', 'is_active' => true],
            ['id' => 2, 'name' => 'Полироли', 'slug' => 'hidden-polishes', 'is_active' => false],
        ]);
        DB::table('products')->insert(['category_id' => 1, 'sku' => 'HOME-TEST', 'name' => 'Товар главной',
            'slug' => 'home-test', 'price' => 1500, 'quantity' => 5, 'is_active' => true, 'is_hit' => true]);
        DB::table('settings')->insert([
            ['key' => 'phone', 'value' => '+7 700 111 22 33'],
            ['key' => 'address', 'value' => 'Алматы, тестовый адрес 10'],
        ]);
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    public function test_home_has_exact_meta_one_h1_and_replaced_copy(): void
    {
        $response = $this->get('/')->assertOk()->assertSee('Товар главной')->assertSee('Работаем с юридическими лицами')
            ->assertSee('Работаем с НДС. Цены на сайте указаны с учетом НДС.')
            ->assertDontSee('Работаем 7 дней')->assertDontSee('Ежедневно')->assertDontSee('официальных поставщиков');
        $xpath = $this->dom($response->getContent());
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame('Автохимия и автокосметика в Алматы', $xpath->evaluate('normalize-space(//h1)'));
        $this->assertSame('Автохимия в Алматы — купить автокосметику | Autohimiki.kz', $xpath->evaluate('string(//title)'));
        $this->assertSame('Автохимия и автокосметика в Алматы в интернет-магазине Autohimiki.kz. Средства для ухода за кузовом, салоном и автомобилем. Самовывоз, доставка по Алматы и Казахстану.', $xpath->evaluate('string(//meta[@name="description"]/@content)'));
        $this->assertSame(1, $xpath->query('//section[@id="home-seo"]')->length);
        $this->assertSame(1, $xpath->query('//section[@id="home-seo"]/following-sibling::section[contains(@class,"ah-home-business")]')->length);
        $this->assertSame(1, $xpath->query('//a[@href="'.route('catalog').'" and contains(@class,"ah-button-orange")]')->length);
        $response->assertSee('Пн–Пт: 09:00–18:00')->assertSee('Сб: 11:00–16:00')->assertSee('Вс: выходной');
    }

    public function test_links_use_real_active_category_slugs_and_missing_categories_stay_plain_text(): void
    {
        $xpath = $this->dom($this->get('/')->assertOk()->getContent());
        $this->assertSame(route('catalog.category', ['category' => 'actual-wash-slug']), $xpath->evaluate('string(//section[@id="home-seo"]//p/a[normalize-space()="автошампуни"]/@href)'));
        $this->assertSame(0, $xpath->query('//section[@id="home-seo"]//p/a[normalize-space()="полироли" or normalize-space()="присадки"]')->length);
        $this->get('/catalog/actual-wash-slug')->assertOk();
        DB::table('categories')->where('id', 1)->update(['is_active' => false]);
        CacheService::forgetHomepage();
        $xpath = $this->dom($this->get('/')->assertOk()->getContent());
        $this->assertSame(0, $xpath->query('//section[@id="home-seo"]//p/a')->length);
    }

    public function test_store_schema_is_single_valid_json_with_settings_and_current_hours(): void
    {
        $xpath = $this->dom($this->get('/')->assertOk()->getContent());
        $nodes = $xpath->query('//script[@type="application/ld+json"]');
        $this->assertSame(1, $nodes->length);
        $schema = json_decode($nodes->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Store', $schema['@type']);
        $this->assertSame('Autohimiki.kz', $schema['name']);
        $this->assertSame(url('/'), $schema['url']);
        $this->assertSame('+7 700 111 22 33', $schema['telephone']);
        $this->assertSame('Алматы, тестовый адрес 10', $schema['address']['streetAddress']);
        $this->assertSame('Алматы', $schema['address']['addressLocality']);
        $this->assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $schema['openingHoursSpecification'][0]['dayOfWeek']);
        $this->assertSame(['09:00', '11:00', '00:00'], array_column($schema['openingHoursSpecification'], 'opens'));
        $this->assertSame(['18:00', '16:00', '00:00'], array_column($schema['openingHoursSpecification'], 'closes'));
    }
}
