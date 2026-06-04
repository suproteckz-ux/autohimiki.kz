<?php

namespace Database\Seeders;

use App\Models\ImportColumnTemplate;
use Illuminate\Database\Seeder;

/**
 * ImportTemplateSeeder
 *
 * Создаёт предустановленный шаблон маппинга колонок
 * для стандартного файла выгрузки из 1С.
 */
class ImportTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Шаблон для режима "Обновление из 1С" (prices_only)
        ImportColumnTemplate::updateOrCreate(
            ['name' => '1С — Обновление цен и остатков'],
            [
                'type'       => 'prices_only',
                'is_default' => true,
                'column_map' => [
                    'sku'      => 'Номенклатура.Код',
                    'price'    => 'Розничная цена',
                    'quantity' => 'Остаток на складе',
                ],
            ]
        );

        // Шаблон для режима "Полный импорт" (full)
        ImportColumnTemplate::updateOrCreate(
            ['name' => '1С — Полный импорт'],
            [
                'type'       => 'full',
                'is_default' => true,
                'column_map' => [
                    'sku'      => 'Номенклатура.Код',
                    'name'     => 'Номенклатура',
                    'price'    => 'Розничная цена',
                    'quantity' => 'Остаток на складе',
                    'unit'     => 'Ед. изм.',
                ],
            ]
        );

        $this->command->info('✅ Шаблоны маппинга 1С созданы');
    }
}
