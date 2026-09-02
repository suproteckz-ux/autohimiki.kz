<?php

namespace App\Services\Import;

use InvalidArgumentException;

/** Strict 1C values: no inferred SKU padding, rounding or lossy text stripping. */
class CommercialValues
{
    public static function price(mixed $value): ?string
    {
        $number = self::number($value);
        if ($number === null) {
            return null;
        }
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $number) || (float) $number > 99999999.99) {
            throw new InvalidArgumentException('Invalid price; existing price preserved.');
        }

        return number_format((float) $number, 2, '.', '');
    }

    public static function quantity(mixed $value): ?int
    {
        $number = self::number($value);
        if ($number === null) {
            return null;
        }
        if (! preg_match('/^-?\d+(?:\.0+)?$/D', $number) || abs((float) $number) > 4294967295) {
            throw new InvalidArgumentException('Invalid or fractional quantity; existing stock preserved.');
        }

        return max(0, (int) $number);
    }

    private static function number(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Invalid numeric cell type.');
        }
        $text = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) $value));
        if (! preg_match('/^-?(?:\d+|\d{1,3}(?: \d{3})+)(?:[.,]\d+)?$/D', $text)) {
            throw new InvalidArgumentException('Malformed numeric value; field preserved.');
        }

        return str_replace([' ', ','], ['', '.'], $text);
    }
}
