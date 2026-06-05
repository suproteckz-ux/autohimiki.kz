<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Составные индексы для ускорения типичных запросов каталога.
 * FULLTEXT индекс обёрнут в try/catch — на некоторых shared-хостингах
 * пользователь БД не имеет прав на FULLTEXT ALTER TABLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── products ──────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $table->index(
                ['category_id', 'is_active', 'sort_order'],
                'idx_products_category_active_sort'
            );
            $table->index(
                ['category_id', 'brand_id', 'is_active'],
                'idx_products_category_brand_active'
            );
            $table->index(
                ['category_id', 'is_active', 'price'],
                'idx_products_category_active_price'
            );
            $table->index(
                ['is_hit', 'is_active', 'sort_order'],
                'idx_products_hit_active_sort'
            );
            $table->index(
                ['is_new', 'is_active', 'created_at'],
                'idx_products_new_active_created'
            );
            $table->index(
                ['in_stock', 'is_active'],
                'idx_products_stock_active'
            );
        });

        // FULLTEXT — в try/catch, т.к. на shared hosting могут отсутствовать права
        try {
            DB::statement(
                'ALTER TABLE products ADD FULLTEXT idx_products_fulltext (name, sku, short_description)'
            );
        } catch (\Throwable $e) {
            // FULLTEXT недоступен — поиск будет работать через LIKE (fallback в SearchController)
        }

        // ── categories ────────────────────────────────────────────
        Schema::table('categories', function (Blueprint $table) {
            $table->index(
                ['parent_id', 'is_active', 'sort_order'],
                'idx_categories_parent_active_sort'
            );
        });

        // ── blog_posts ────────────────────────────────────────────
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->index(
                ['is_active', 'published_at'],
                'idx_blog_active_published'
            );
        });

        // ── seo_filters ───────────────────────────────────────────
        Schema::table('seo_filters', function (Blueprint $table) {
            $table->index(
                ['is_active', 'is_indexed', 'category_id', 'brand_id'],
                'idx_seo_filters_active_indexed_cat_brand'
            );
        });
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE products DROP INDEX idx_products_fulltext');
        } catch (\Throwable $e) {
            //
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_category_active_sort');
            $table->dropIndex('idx_products_category_brand_active');
            $table->dropIndex('idx_products_category_active_price');
            $table->dropIndex('idx_products_hit_active_sort');
            $table->dropIndex('idx_products_new_active_created');
            $table->dropIndex('idx_products_stock_active');
        });

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
