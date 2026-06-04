<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Составные индексы для ускорения типичных запросов каталога.
 *
 * Анализ запросов:
 *
 * 1. Каталог категории: WHERE category_id=? AND is_active=1 ORDER BY sort_order
 *    → составной индекс (category_id, is_active, sort_order)
 *
 * 2. Фильтр бренда в категории: WHERE category_id=? AND brand_id=? AND is_active=1
 *    → составной индекс (category_id, brand_id, is_active)
 *
 * 3. Фильтр по цене: WHERE category_id=? AND is_active=1 AND price BETWEEN ? AND ?
 *    → составной индекс (category_id, is_active, price)
 *
 * 4. Поиск по SKU: WHERE sku LIKE '%РТ-00001272%'
 *    → FULLTEXT индекс (name, sku, short_description)
 *
 * 5. Хиты/новинки на главной: WHERE is_hit=1 AND is_active=1 ORDER BY sort_order
 *    → составной индекс (is_hit, is_active, sort_order)
 *
 * 6. Блог: WHERE is_active=1 AND published_at<=NOW() ORDER BY published_at DESC
 *    → составной индекс (is_active, published_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Products ──────────────────────────────────────────────

        Schema::table('products', function (Blueprint $table) {
            // Каталог категории с сортировкой
            $table->index(
                ['category_id', 'is_active', 'sort_order'],
                'idx_products_category_active_sort'
            );

            // Фильтр по бренду в категории
            $table->index(
                ['category_id', 'brand_id', 'is_active'],
                'idx_products_category_brand_active'
            );

            // Фильтр по цене в категории
            $table->index(
                ['category_id', 'is_active', 'price'],
                'idx_products_category_active_price'
            );

            // Хиты продаж на главной
            $table->index(
                ['is_hit', 'is_active', 'sort_order'],
                'idx_products_hit_active_sort'
            );

            // Новинки на главной
            $table->index(
                ['is_new', 'is_active', 'created_at'],
                'idx_products_new_active_created'
            );

            // Популярные
            $table->index(
                ['is_popular', 'is_active', 'views'],
                'idx_products_popular_active_views'
            );

            // Фильтр наличия
            $table->index(
                ['in_stock', 'is_active'],
                'idx_products_stock_active'
            );

            // FULLTEXT для поиска по названию и SKU
            // Примечание: добавляем через raw SQL (Blueprint не поддерживает FULLTEXT напрямую в старых версиях)
        });

        // FULLTEXT индекс для поиска
        \DB::statement(
            'ALTER TABLE products ADD FULLTEXT idx_products_fulltext (name, sku, short_description)'
        );

        // ── Categories ────────────────────────────────────────────

        Schema::table('categories', function (Blueprint $table) {
            // Корневые активные категории
            $table->index(
                ['parent_id', 'is_active', 'sort_order'],
                'idx_categories_parent_active_sort'
            );
        });

        // ── Blog Posts ────────────────────────────────────────────

        Schema::table('blog_posts', function (Blueprint $table) {
            // Список активных статей по дате
            $table->index(
                ['is_active', 'published_at'],
                'idx_blog_active_published'
            );
        });

        // ── SEO Filters ───────────────────────────────────────────

        Schema::table('seo_filters', function (Blueprint $table) {
            // Поиск SEO-фильтра по категории + бренду
            $table->index(
                ['is_active', 'is_indexed', 'category_id', 'brand_id'],
                'idx_seo_filters_active_indexed_cat_brand'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_category_active_sort');
            $table->dropIndex('idx_products_category_brand_active');
            $table->dropIndex('idx_products_category_active_price');
            $table->dropIndex('idx_products_hit_active_sort');
            $table->dropIndex('idx_products_new_active_created');
            $table->dropIndex('idx_products_popular_active_views');
            $table->dropIndex('idx_products_stock_active');
        });

        \DB::statement('ALTER TABLE products DROP INDEX idx_products_fulltext');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('idx_categories_parent_active_sort');
        });

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropIndex('idx_blog_active_published');
        });

        Schema::table('seo_filters', function (Blueprint $table) {
            $table->dropIndex('idx_seo_filters_active_indexed_cat_brand');
        });
    }
};
