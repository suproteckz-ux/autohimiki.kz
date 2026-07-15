<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SeoFilter;
use App\Models\SeoPage;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicPagesSmokeTest extends TestCase
{
    private Category $category;

    private Category $subcategory;

    private Brand $brand;

    private Product $product;

    private BlogPost $post;

    private SeoPage $seoPage;

    private SeoFilter $seoFilter;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'blog_post_product',
            'seo_page_category',
            'seo_page_product',
            'product_images',
            'products',
            'seo_filters',
            'seo_pages',
            'blog_posts',
            'brands',
            'categories',
            'redirects',
            'settings',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role')->default('manager');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('settings', function ($table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('redirects', function ($table) {
            $table->id();
            $table->string('from_url')->unique();
            $table->string('to_url');
            $table->boolean('is_active')->default(true);
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

        Schema::create('seo_pages', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('h1')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->longText('seo_text')->nullable();
            $table->json('faq')->nullable();
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

        Schema::create('seo_filters', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('category_id')->nullable();
            $table->foreignId('brand_id')->nullable();
            $table->string('h1')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->text('seo_text')->nullable();
            $table->string('canonical_url')->nullable();
            $table->json('faq')->nullable();
            $table->boolean('is_indexed')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->seedPublicContent();
    }

    public function test_homepage_loads(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_catalog_loads(): void
    {
        $this->get('/catalog')->assertOk();
    }

    public function test_search_endpoint_loads(): void
    {
        $this->get('/search')->assertOk();
    }

    public function test_admin_login_page_loads(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_catalog_category_and_subcategory_pages_load(): void
    {
        $this->get("/catalog/{$this->category->slug}")->assertOk();
        $this->get("/catalog/{$this->category->slug}/{$this->subcategory->slug}")->assertOk();
    }

    public function test_product_page_loads(): void
    {
        $this->get("/product/{$this->product->slug}")->assertOk();
    }

    public function test_brand_index_and_detail_pages_load(): void
    {
        $this->get('/brand')->assertOk();
        $this->get("/brand/{$this->brand->slug}")->assertOk();
    }

    public function test_blog_index_and_article_pages_load(): void
    {
        $this->get('/blog')->assertOk();
        $this->get("/blog/{$this->post->slug}")->assertOk();
    }

    public function test_seo_page_and_filter_pages_load(): void
    {
        $this->get("/{$this->seoPage->slug}")->assertOk();
        $this->get("/{$this->category->slug}/{$this->brand->slug}")->assertOk();
    }

    public function test_sitemaps_and_robots_load(): void
    {
        foreach ([
            '/robots.txt',
            '/sitemap.xml',
            '/sitemap-products.xml',
            '/sitemap-categories.xml',
            '/sitemap-brands.xml',
            '/sitemap-blog.xml',
            '/sitemap-seo-pages.xml',
            '/sitemap-seo-filters.xml',
        ] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_404_page_renders_without_500(): void
    {
        $this->get('/missing-page-for-recovery-audit')->assertNotFound();
    }

    public function test_authenticated_filament_pages_load(): void
    {
        $this->actingAs($this->admin);

        foreach ([
            '/admin',
            '/admin/import-wizard-page',
            '/admin/export-page',
        ] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    private function seedPublicContent(): void
    {
        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);

        $this->category = Category::create([
            'name' => 'Care',
            'slug' => 'care',
            'is_active' => true,
        ]);

        $this->subcategory = Category::create([
            'parent_id' => $this->category->id,
            'name' => 'Shampoo',
            'slug' => 'shampoo',
            'is_active' => true,
        ]);

        $this->brand = Brand::create([
            'name' => 'Koch',
            'slug' => 'koch',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'category_id' => $this->subcategory->id,
            'brand_id' => $this->brand->id,
            'name' => 'Test Shampoo',
            'slug' => 'test-shampoo',
            'sku' => 'SKU-1',
            'price' => 1000,
            'quantity' => 5,
            'in_stock' => true,
            'is_active' => true,
            'is_new' => true,
            'is_hit' => true,
        ]);

        $this->post = BlogPost::create([
            'title' => 'Washing guide',
            'slug' => 'washing-guide',
            'content' => '<p>Use safely.</p>',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $this->post->products()->attach($this->product->id);

        $this->seoPage = SeoPage::create([
            'title' => 'Detailing',
            'slug' => 'detailing',
            'seo_text' => '<p>Detailing products.</p>',
            'is_active' => true,
        ]);
        $this->seoPage->products()->attach($this->product->id);
        $this->seoPage->categories()->attach($this->category->id);

        $this->seoFilter = SeoFilter::create([
            'name' => 'Care Koch',
            'slug' => 'care-koch',
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'seo_text' => '<p>Care by Koch.</p>',
            'is_indexed' => true,
            'is_active' => true,
        ]);
    }
}
