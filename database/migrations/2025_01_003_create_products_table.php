<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')
                  ->constrained('categories')
                  ->restrictOnDelete();
            $table->foreignId('brand_id')
                  ->nullable()
                  ->constrained('brands')
                  ->nullOnDelete();
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

            $table->index('category_id');
            $table->index('brand_id');
            $table->index('is_active');
            $table->index('in_stock');
            $table->index('is_new');
            $table->index('is_hit');
            $table->index('is_popular');
            $table->index('price');
            $table->index('sort_order');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
