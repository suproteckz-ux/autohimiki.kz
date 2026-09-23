<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_category_id')->unique()->constrained('categories')->restrictOnDelete();
            $table->unsignedBigInteger('ozon_description_category_id');
            $table->unsignedBigInteger('ozon_type_id');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('ozon_product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_product_id')->unique()->constrained('products')->restrictOnDelete();
            $table->string('offer_id')->unique();
            $table->unsignedBigInteger('ozon_product_id')->nullable();
            $table->unsignedBigInteger('import_task_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('desired_quantity')->default(0);
            $table->timestamp('create_attempted_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('last_content_sync_at')->nullable();
            $table->timestamp('last_stock_sync_at')->nullable();
            $table->timestamp('publication_confirmed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_product_links');
        Schema::dropIfExists('ozon_category_mappings');
    }
};
