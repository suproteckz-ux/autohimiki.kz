<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            // Тип импорта: обновление из 1С или полный
            $table->enum('type', ['prices_only', 'full'])
                  ->default('prices_only')
                  ->after('id');

            // Сохранённый маппинг колонок (JSON)
            $table->json('column_map')
                  ->nullable()
                  ->after('filepath');

            // Лог изменённых цен: [['sku'=>..., 'old'=>..., 'new'=>...]]
            $table->json('price_changes')
                  ->nullable()
                  ->after('column_map');

            // Лог изменённых остатков: [['sku'=>..., 'old'=>..., 'new'=>...]]
            $table->json('stock_changes')
                  ->nullable()
                  ->after('price_changes');

            // Сколько SKU не найдено (только для режима prices_only)
            $table->unsignedInteger('not_found_count')
                  ->default(0)
                  ->after('error_count');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'type', 'column_map', 'price_changes',
                'stock_changes', 'not_found_count',
            ]);
        });
    }
};
