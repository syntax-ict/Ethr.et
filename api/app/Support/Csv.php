<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every CSV the API writes goes through here.
 *
 * Three exports built their own, and between them: the accounting journal
 * wrote amounts with number_format()'s default comma grouping into unquoted
 * cells, so "12,345.67" became two columns; and none neutralised a
 * spreadsheet formula, so a value such as =HYPERLINK(...) in an employee name
 * executed when HR opened the file (OWASP "CSV injection").
 */
final class Csv
{
    private const FORMULA_START = '/^[=+\-@\t\r]/';

    private const PLAIN_NUMBER = '/^[-+]?\d+(\.\d+)?$/';

    /**
     * One cell, formula-safe. A value a spreadsheet would evaluate gets a
     * leading apostrophe so it stays text; a plain number such as -250.00 is
     * left alone. Quoted only when it holds a delimiter, a quote or a line
     * break, so an ordinary row is byte-identical to what banks' import
     * tools already accept.
     */
    public static function cell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        if (preg_match(self::FORMULA_START, $text) === 1 && preg_match(self::PLAIN_NUMBER, $text) !== 1) {
            $text = "'".$text;
        }

        return preg_match('/[",\r\n]/', $text) === 1
            ? '"'.str_replace('"', '""', $text).'"'
            : $text;
    }

    /**
     * @param  array<int|string, mixed>  $cells
     */
    public static function row(array $cells): string
    {
        return implode(',', array_map(self::cell(...), $cells));
    }

    /**
     * Money for a CSV cell: two decimals, no thousands separator — a grouped
     * "12,345.67" is a second delimiter to a CSV reader.
     */
    public static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
