<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Every Excel file the API writes goes through here, as every CSV goes
 * through `Csv`.
 *
 * A streaming writer: each row is written to a temporary file as it is
 * handed over, so a thousand rows cost the rows being read, not a tree of
 * cell objects, inside the host's memory limit. A cell is a number only when
 * the caller passes a number, so an employee code such as "00123" stays text,
 * and a date only when the caller passes an `XlsxDate`, so a string that
 * merely looks like one stays text too. A date in the Ethiopian calendar is
 * text, as Excel has no such calendar. A text cell is never a formula,
 * whatever it starts with: the writer's own shortcut reads "=..." as one, so
 * cells are built by type here, as `Csv::cell()` guards a CSV.
 *
 * A sheet is an array: `name`, `header` (bold, and frozen with everything
 * above it), `rows` (any iterable of lists), optional `lead` (lines above the
 * header, the first bold), optional `foot` (lines after a blank row, the note
 * a CSV carries at its end), optional `freeze_columns` and `bold_last` (the
 * last data row is a total).
 *
 * @phpstan-type SheetSpec array{name: string, header: list<string>, rows: iterable<list<mixed>>, lead?: list<string>, foot?: list<string>, freeze_columns?: int, bold_last?: bool}
 */
final class Xlsx
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** A workbook never has more characters in a sheet's name than this, and none of the characters below. */
    private const MAX_NAME = 31;

    /** Widths in characters for date cells: Excel shows ##### in a cell narrower than its text. */
    private const DATE_WIDTH = 12.0;

    private const DATE_TIME_WIDTH = 18.0;

    /**
     * @param  list<SheetSpec>  $sheets
     */
    public static function render(array $sheets): string
    {
        if ($sheets === []) {
            throw new RuntimeException('A workbook needs at least one sheet.');
        }

        // Under storage, not the system temp directory: the host's open_basedir
        // can refuse the latter, and a refused tempnam() is an ErrorException.
        $folder = storage_path('framework/xlsx');
        File::ensureDirectoryExists($folder);
        $path = $folder.DIRECTORY_SEPARATOR.'ethr-xlsx-'.Str::random(24).'.xlsx';

        try {
            $writer = new Writer(new Options(tempFolder: $folder));
            $writer->openToFile($path);

            $bold = (new Style)->withFontBold(true);
            $names = [];

            foreach ($sheets as $index => $spec) {
                $sheet = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName(self::name($spec['name'], $names));

                $lead = $spec['lead'] ?? [];
                $headerRow = count($lead) + 1;
                $columns = max(0, (int) ($spec['freeze_columns'] ?? 0));
                $sheet->setSheetView(new SheetView(
                    freezeRow: $headerRow + 1,
                    freezeColumn: self::columnLetter($columns + 1),
                ));

                foreach ($lead as $line => $text) {
                    $writer->addRow(self::row([$text], $line === 0 ? $bold : null));
                }
                if ($spec['header'] !== []) {
                    $writer->addRow(self::row(array_map(strval(...), $spec['header']), $bold));
                }

                $wide = [];
                $previous = null;
                foreach ($spec['rows'] as $row) {
                    if ($previous !== null) {
                        $writer->addRow(self::row(self::values($previous)));
                    }
                    self::noteDates($row, $wide);
                    $previous = $row;
                }
                if ($previous !== null) {
                    $writer->addRow(self::row(self::values($previous), ($spec['bold_last'] ?? false) ? $bold : null));
                }

                // The writer puts widths ahead of the rows only when it closes,
                // so they can be set after a streamed sheet has been read.
                foreach ($wide as $column => $width) {
                    $sheet->setColumnWidth(max($width, mb_strlen($spec['header'][$column - 1] ?? '') + 2.0), $column);
                }

                $foot = $spec['foot'] ?? [];
                if ($foot !== []) {
                    $writer->addRow(self::row([]));
                    foreach ($foot as $text) {
                        $writer->addRow(self::row([$text]));
                    }
                }
            }

            $writer->close();
            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new RuntimeException('The workbook could not be read back.');
            }

            return $bytes;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * One row. Every cell is built by its type, never by `Cell::fromValue()`,
     * which turns any string that starts with "=" into a formula: a name such
     * as =HYPERLINK(...) must arrive as a name.
     *
     * @param  list<int|float|string|XlsxDate|null>  $values
     */
    private static function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(static fn (int|float|string|XlsxDate|null $value): Cell => match (true) {
            $value === null || $value === '' => new EmptyCell(null, $style),
            is_string($value) => new StringCell($value, $style),
            $value instanceof XlsxDate => new DateTimeCell($value->value, ($style ?? new Style)->withFormat(
                $value->withTime ? XlsxDate::DATE_TIME_FORMAT : XlsxDate::DATE_FORMAT,
            )),
            default => new NumericCell($value, $style),
        }, $values));
    }

    /**
     * The columns, by 1-based number, that hold a date or a date and time in
     * this row, with the width each needs: the wider kind wins.
     *
     * @param  list<mixed>  $row
     * @param  array<int, float>  $wide
     */
    private static function noteDates(array $row, array &$wide): void
    {
        foreach ($row as $index => $value) {
            if ($value instanceof XlsxDate) {
                $width = $value->withTime ? self::DATE_TIME_WIDTH : self::DATE_WIDTH;
                $wide[$index + 1] = max($wide[$index + 1] ?? 0.0, $width);
            }
        }
    }

    /**
     * Rows keyed by column heading, as `ReportEngine::presentable()` returns
     * them: one sheet whose header is the first row's keys and whose foot is
     * the cut note, the same shape `ReportEngine::toCsv()` writes.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return SheetSpec
     */
    public static function table(string $name, array $rows, ?string $note = null): array
    {
        return [
            'name' => $name,
            'header' => $rows === [] ? [] : array_map(strval(...), array_keys($rows[0])),
            'rows' => (function () use ($rows): iterable {
                foreach ($rows as $row) {
                    yield array_values($row);
                }
            })(),
            'foot' => $note === null || $note === '' ? [] : [$note],
        ];
    }

    /**
     * A cell the way a person reads it: a number stays a number, a date the
     * caller marked stays a date, nothing becomes the empty cell, anything
     * else is text.
     *
     * @param  list<mixed>  $row
     * @return list<int|float|string|XlsxDate|null>
     */
    private static function values(array $row): array
    {
        return array_map(static fn (mixed $value): int|float|string|XlsxDate|null => match (true) {
            $value === null => null,
            $value instanceof XlsxDate => $value,
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value) => $value,
            default => (string) $value,
        }, $row);
    }

    /**
     * A sheet name Excel accepts: at most 31 characters, none of []:*?/\, not
     * empty, and not the same as another sheet's in the workbook.
     *
     * @param  list<string>  $taken
     */
    private static function name(string $wanted, array &$taken): string
    {
        $clean = trim((string) preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $wanted));
        $clean = $clean === '' ? 'Sheet' : mb_substr($clean, 0, self::MAX_NAME);

        $name = $clean;
        for ($n = 2; in_array(mb_strtolower($name), $taken, true); $n++) {
            $name = mb_substr($clean, 0, self::MAX_NAME - strlen((string) $n) - 1).' '.$n;
        }
        $taken[] = mb_strtolower($name);

        return $name;
    }

    /** 1 is A, 27 is AA. */
    private static function columnLetter(int $number): string
    {
        $letters = '';
        for ($n = max(1, $number); $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }
}
