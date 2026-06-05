<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_page_product', function (Blueprint $table) {
            $table->foreignId('seo_page_id')
                  ->constrained('seo_pages')
                  ->cascadeOnDelete();
            $table->foreignId('product_id')
                  ->constrained('products')
                  ->cascadeOnDelete();
            $table->primary(['seo_page_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_page_product');
    }
};
