<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Storage;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * ImportFileParser
 *
 * Читает файлы XLS/XLSX/CSV и возвращает массив строк.
 *
 * ИЗМЕНЕНИЕ: заменён maatwebsite/excel на rap2hpoutre/fast-excel.
 * Причина: maatwebsite/excel требует ext-gd через phpspreadsheet,
 * которого нет на shared-хостинге hoster.kz.
 * fast-excel использует openspout — не требует ext-gd.
 *
 * Результат: array of associative arrays
 * [['Номенклатура.Код' => 'РТ-00001272', 'Розничная цена' => 7300, ...], ...]
 */
class ImportFileParser
{
    public static array $allowedExtensions = ['xls', 'xlsx', 'csv'];

    /**
     * Парсит файл из storage и возвращает все строки.
     */
    public function parse(string $storagePath): array
    {
        $fullPath  = Storage::disk('public')->path($storagePath);
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        if (! file_exists($fullPath)) {
            throw new \RuntimeException("Файл не найден: {$fullPath}");
        }

        if (! in_array($extension, self::$allowedExtensions, true)) {
            throw new \InvalidArgumentException(
                "Неподдерживаемый формат: {$extension}. Допустимы: " .
                implode(', ', self::$allowedExtensions)
            );
        }

        return match ($extension) {
            'csv'          => $this->parseCsv($fullPath),
            'xls', 'xlsx'  => $this->parseXlsx($fullPath),
        };
    }

    /**
     * Первые N строк для предпросмотра.
     */
    public function preview(string $storagePath, int $limit = 20): array
    {
        return array_slice($this->parse($storagePath), 0, $limit);
    }

    /**
     * Список колонок (заголовков) файла.
     */
    public function getColumns(string $storagePath): array
    {
        $rows = $this->preview($storagePath, 1);
        return ! empty($rows) ? array_keys($rows[0]) : [];
    }

    /**
     * Читает XLSX/XLS через FastExcel (openspout — без ext-gd).
     * FastExcel автоматически использует первую строку как заголовки.
     */
    private function parseXlsx(string $fullPath): array
    {
        $rows = [];

        (new FastExcel())->import($fullPath, function (array $line) use (&$rows) {
            // Пропускаем полностью пустые строки
            if (empty(array_filter($line, fn($v) => $v !== null && $v !== ''))) {
                return null;
            }
            $rows[] = $line;
        });

        return $rows;
    }

    /**
     * Читает CSV через FastExcel.
     */
    private function parseCsv(string $fullPath): array
    {
        // Определяем и нормализуем кодировку
        $content = file_get_contents($fullPath);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
            file_put_contents($fullPath, $content);
        }

        $rows      = [];
        $delimiter = $this->detectDelimiter($fullPath);

        (new FastExcel())->configureCsv($delimiter)->import($fullPath, function (array $line) use (&$rows) {
            if (empty(array_filter($line, fn($v) => $v !== null && $v !== ''))) {
                return null;
            }
            $rows[] = $line;
        });

        return $rows;
    }

    /**
     * Определяет разделитель CSV по первой строке.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first  = fgets($handle);
        fclose($handle);

        $counts = [];
        foreach ([';', ',', "\t", '|'] as $d) {
            $counts[$d] = substr_count($first, $d);
        }

        arsort($counts);
        return array_key_first($counts);
    }
}
