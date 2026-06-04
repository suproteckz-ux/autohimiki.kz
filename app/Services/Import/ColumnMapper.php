<?php

namespace App\Services\Import;

/**
 * ColumnMapper
 *
 * Преобразует строки из файла (с колонками файла)
 * в строки с полями системы (sku, price, quantity, name, ...).
 *
 * Пример маппинга для 1С:
 * [
 *   'sku'      => 'Номенклатура.Код',
 *   'price'    => 'Розничная цена',
 *   'quantity' => 'Остаток на складе',
 *   'name'     => 'Номенклатура',
 * ]
 *
 * Пример строки после маппинга:
 * ['sku' => 'РТ-00001272', 'price' => 7300.00, 'quantity' => 17, 'name' => 'BOMBA FOAM ...']
 */
class ColumnMapper
{
    /**
     * Поля системы для режима prices_only.
     * Поле => [возможные названия в файле 1С]
     */
    public const PRICES_ONLY_FIELDS = [
        'sku'      => ['Номенклатура.Код', 'Код', 'SKU', 'Артикул', 'код'],
        'price'    => ['Розничная цена', 'Цена', 'Цена розничная', 'цена'],
        'quantity' => ['Остаток на складе', 'Остаток', 'Кол-во', 'количество'],
    ];

    /**
     * Поля системы для полного импорта.
     */
    public const FULL_FIELDS = [
        'sku'              => ['Номенклатура.Код', 'Код', 'SKU', 'Артикул'],
        'name'             => ['Номенклатура', 'Наименование', 'Название', 'Товар'],
        'price'            => ['Розничная цена', 'Цена', 'Цена розничная'],
        'old_price'        => ['Старая цена', 'Цена до скидки', 'Цена опт'],
        'quantity'         => ['Остаток на складе', 'Остаток', 'Кол-во'],
        'category'         => ['Категория', 'Группа', 'Раздел', 'Вид'],
        'brand'            => ['Бренд', 'Производитель', 'Торговая марка'],
        'unit'             => ['Ед. изм.', 'Единица', 'Ед.изм'],
        'short_description'=> ['Краткое описание', 'Описание кратко'],
        'description'      => ['Описание', 'Полное описание', 'Характеристика'],
        'image_url'        => ['Изображение', 'Фото', 'URL фото', 'Картинка'],
        'meta_title'       => ['Meta Title', 'СЕО заголовок', 'SEO Title'],
        'meta_description' => ['Meta Description', 'СЕО описание'],
    ];

    /**
     * Применяет маппинг к массиву строк файла.
     *
     * @param  array[] $rows      Строки из файла (associative)
     * @param  array   $columnMap Маппинг {поле_системы => колонка_файла}
     * @return array[]            Строки с полями системы
     */
    public function map(array $rows, array $columnMap): array
    {
        return array_map(
            fn ($row) => $this->mapRow($row, $columnMap),
            $rows
        );
    }

    /**
     * Применяет маппинг к одной строке.
     */
    public function mapRow(array $row, array $columnMap): array
    {
        $mapped = [];

        foreach ($columnMap as $systemField => $fileColumn) {
            if (empty($fileColumn)) {
                continue;
            }

            $value = $row[$fileColumn] ?? null;
            $mapped[$systemField] = $this->castValue($systemField, $value);
        }

        return $mapped;
    }

    /**
     * Автоматически определяет маппинг по заголовкам файла.
     * Возвращает наилучшее совпадение для каждого поля системы.
     */
    public function autoDetect(array $fileColumns, string $mode = 'prices_only'): array
    {
        $fields     = $mode === 'full' ? self::FULL_FIELDS : self::PRICES_ONLY_FIELDS;
        $columnMap  = [];

        foreach ($fields as $systemField => $variants) {
            foreach ($variants as $variant) {
                if (in_array($variant, $fileColumns, true)) {
                    $columnMap[$systemField] = $variant;
                    break;
                }
            }
        }

        return $columnMap;
    }

    /**
     * Приводит значение к нужному типу по имени поля.
     */
    private function castValue(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'price', 'old_price' => $this->parsePrice($value),
            'quantity'           => (int) $value,
            default              => trim((string) $value),
        };
    }

    /**
     * Парсит цену — убирает пробелы, запятые, валютные символы.
     * '7 300,00 ₸' → 7300.00
     */
    private function parsePrice(mixed $value): float
    {
        // Убираем пробелы, знаки валют, буквы
        $clean = preg_replace('/[^\d.,]/', '', (string) $value);

        // Если запятая — десятичный разделитель
        if (str_contains($clean, ',') && ! str_contains($clean, '.')) {
            $clean = str_replace(',', '.', $clean);
        } else {
            // Убираем запятые как разделители тысяч
            $clean = str_replace(',', '', $clean);
        }

        return round((float) $clean, 2);
    }
}
