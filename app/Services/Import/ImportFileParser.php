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
    public const ONEC_HEADERS = ['Ед. изм.', 'Номенклатура', 'Номенклатура.Код', 'Остаток на складе', 'Розничная цена'];

    /** Validate the WHOLE immutable workbook before exposing any commercial rows. */
    public function parseCommercial(string $fullPath): array
    {
        $this->validateWorkbook($fullPath);
        $options = new \OpenSpout\Reader\XLSX\Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new \OpenSpout\Reader\XLSX\Reader($options);
        $rows = [];
        $seen = [];
        $sheets = 0;
        $layout = null;
        $ended = false;
        $reader->open($fullPath);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (++$sheets !== 1) {
                    throw new \RuntimeException('1C workbook must contain exactly one sheet.');
                }
                foreach ($sheet->getRowIterator() as $number => $row) {
                    if ($number > config('onec.max_rows', 20000) + 4) {
                        throw new \RuntimeException('1C workbook row limit exceeded.');
                    }
                    foreach ($row->getCells() as $cell) {
                        if ($cell instanceof \OpenSpout\Common\Entity\Cell\FormulaCell) {
                            throw new \RuntimeException("Formula cell at row {$number}; export values only.");
                        }
                    }
                    $values = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
                    if (array_filter(array_slice($values, 5), fn ($v) => $v !== null && $v !== '') !== []) {
                        throw new \RuntimeException("Unexpected columns at row {$number}.");
                    }
                    $values = array_slice(array_pad($values, 5, null), 0, 5);
                    $texts = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $values);
                    if ($number === 1) {
                        if ($texts === self::ONEC_HEADERS) {
                            $layout = 'flat';
                        } elseif ($texts[0] === 'Склад' && blank($texts[1]) && blank($texts[2])
                            && array_slice($texts, 3) === array_slice(self::ONEC_HEADERS, 3)) {
                            $layout = 'warehouse';
                        } else {
                            throw new \RuntimeException('Unknown 1C header layout.');
                        }
                        continue;
                    }
                    if ($layout === 'warehouse' && $number === 2) {
                        if (array_slice($texts, 0, 3) !== array_slice(self::ONEC_HEADERS, 0, 3)
                            || ! blank($texts[3]) || ! blank($texts[4])) {
                            throw new \RuntimeException('Invalid second 1C header row.');
                        }
                        continue;
                    }
                    if ($layout === 'warehouse' && $number === 3) {
                        if (! is_string($texts[0]) || blank($texts[0]) || ! blank($texts[1]) || ! blank($texts[2])) {
                            throw new \RuntimeException('Invalid warehouse summary row.');
                        }
                        continue;
                    }
                    if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    if ($texts[0] === 'Итого' && blank($texts[1]) && blank($texts[2]) && ! $ended) {
                        $ended = true;
                        continue;
                    }
                    if ($ended || ! is_string($values[2]) || trim($values[2]) === ''
                        || mb_strlen(trim($values[2])) > 255 || preg_match('/[\x00-\x1F]/u', $values[2])) {
                        throw new \RuntimeException("Invalid string SKU/structure at row {$number}.");
                    }
                    $sku = trim($values[2]);
                    // Also reject case-only ambiguities before database collation can merge them.
                    $key = 'sku:'.mb_strtolower($sku);
                    if (isset($seen[$key])) {
                        throw new \RuntimeException("Duplicate SKU {$sku} at rows {$seen[$key]} and {$number}.");
                    }
                    $seen[$key] = $number;
                    $values[2] = $sku;
                    $rows[] = array_combine(self::ONEC_HEADERS, $values) + ['__row' => $number];
                }
            }
        } finally {
            $reader->close();
        }
        if ($rows === []) {
            throw new \RuntimeException('1C workbook has no product rows.');
        }
        return $rows;
    }

    private function validateWorkbook(string $path): void
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx' || ! is_readable($path)
            || filesize($path) > config('onec.max_bytes', 52428800)) {
            throw new \RuntimeException('Expected a readable XLSX within the size limit.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('Invalid or incomplete XLSX ZIP.');
        }
        try {
            foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $part) {
                if ($zip->locateName($part) === false) {
                    throw new \RuntimeException("Missing XLSX part: {$part}");
                }
            }
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $size += $stat['size'];
                if ($size > config('onec.max_uncompressed_bytes', 157286400) || $zip->numFiles > 2000) {
                    throw new \RuntimeException('XLSX expanded size/entry limit exceeded.');
                }
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/\.(xml|rels)$/i', $name)) {
                    $xml = $zip->getFromIndex($i);
                    $previous = libxml_use_internal_errors(true);
                    try {
                        if ($xml === false || stripos($xml, '<!DOCTYPE') !== false
                            || ! (new \DOMDocument())->loadXML($xml, LIBXML_NONET)) {
                            throw new \RuntimeException("Invalid XML part: {$name}");
                        }
                    } finally {
                        libxml_clear_errors();
                        libxml_use_internal_errors($previous);
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

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
