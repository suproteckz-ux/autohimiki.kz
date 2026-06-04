<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_column_templates', function (Blueprint $table) {
            $table->id();

            // Название шаблона, например "1С стандарт"
            $table->string('name');

            // Тип импорта к которому относится шаблон
            $table->enum('type', ['prices_only', 'full'])->default('prices_only');

            // JSON маппинга: {"sku": "Номенклатура.Код", "price": "Розничная цена", ...}
            $table->json('column_map');

            // Использовать этот шаблон по умолчанию для данного типа
            $table->boolean('is_default')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_column_templates');
    }
};
