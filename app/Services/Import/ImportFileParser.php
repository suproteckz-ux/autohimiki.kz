<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;

/**
 * ImportFileParser
 *
 * Читает файл любого поддерживаемого формата (XLS/XLSX/CSV)
 * и возвращает массив строк с заголовками из первой строки.
 *
 * Результат: array of associative arrays
 * [
 *   ['Номенклатура.Код' => 'РТ-00001272', 'Розничная цена' => 7300, ...],
 *   ...
 * ]
 */
class ImportFileParser
{
    /**
     * Поддерживаемые расширения файлов.
     */
    public static array $allowedExtensions = ['xls', 'xlsx', 'csv'];

    /**
     * Парсит файл из storage и возвращает все строки.
     *
     * @param  string $storagePath  Путь в storage (imports/file.xlsx)
     * @return array[]
     */
    public function parse(string $storagePath): array
    {
        $fullPath  = Storage::disk('public')->path($storagePath);
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv'          => $this->parseCsv($fullPath),
            'xls', 'xlsx'  => $this->parseExcel($storagePath),
            default        => throw new \InvalidArgumentException(
                "Неподдерживаемый формат файла: {$extension}"
            ),
        };
    }

    /**
     * Возвращает только первые N строк для предпросмотра.
     */
    public function preview(string $storagePath, int $limit = 20): array
    {
        return array_slice($this->parse($storagePath), 0, $limit);
    }

    /**
     * Возвращает список колонок (заголовков) файла.
     */
    public function getColumns(string $storagePath): array
    {
        $rows = $this->parse($storagePath);
        return ! empty($rows) ? array_keys($rows[0]) : [];
    }

    /**
     * Читает XLSX/XLS через Maatwebsite Excel.
     */
    private function parseExcel(string $storagePath): array
    {
        // HeadingRowImport читает первую строку как заголовки
        $data = Excel::toArray(new HeadingRowImport(), Storage::disk('public')->path($storagePath));

        if (empty($data[0])) {
            return [];
        }

        // Первый лист
        $rows = $data[0];

        // Убираем пустые строки
        return array_filter($rows, fn ($row) =>
            ! empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))
        );
    }

    /**
     * Читает CSV с автоопределением разделителя и кодировки.
     */
    private function parseCsv(string $fullPath): array
    {
        // Определяем кодировку
        $content = file_get_contents($fullPath);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
            file_put_contents($fullPath, $content);
        }

        // Определяем разделитель (;  или  ,  или  \t)
        $firstLine = strtok($content, "\n");
        $delimiter = $this->detectDelimiter($firstLine);

        $handle = fopen($fullPath, 'r');
        if (! $handle) {
            throw new \RuntimeException("Не удалось открыть файл: {$fullPath}");
        }

        $headers = null;
        $rows    = [];

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($headers === null) {
                // Первая строка — заголовки
                $headers = array_map('trim', $line);
                continue;
            }

            // Пустые строки пропускаем
            if (empty(array_filter($line))) {
                continue;
            }

            // Строим ассоциативный массив
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = isset($line[$i]) ? trim($line[$i]) : null;
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Определяет разделитель CSV по первой строке.
     */
    private function detectDelimiter(string $firstLine): string
    {
        $delimiters = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];

        foreach (array_keys($delimiters) as $delimiter) {
            $delimiters[$delimiter] = substr_count($firstLine, $delimiter);
        }

        arsort($delimiters);
        return array_key_first($delimiters);
    }
}
