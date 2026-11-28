<?php

declare(strict_types=1);

namespace Tests\Accuracy\Restated;

use RuntimeException;

/**
 * Reads the words a PDF prints, the way tests/Feature/Documents/DocumentPdfsTest.php
 * reads one (`inflatedStreams()`): every Flate stream is inflated with gzuncompress.
 * It goes one step further than that test, because a figure needs its place on the
 * page: each `BT x y Td ... [(text)] TJ ET` line DomPDF writes becomes a run with
 * its position. DomPDF writes the text as UTF-16BE, one NUL byte before each ASCII
 * letter, so that is undone here. No package and no external program is used.
 *
 * Static only: Pest loads every test file into one process.
 */
final class PdfText
{
    /**
     * Every run of text in document order. A page's runs keep the order they were drawn in.
     *
     * @return list<array{x: float, y: float, text: string, page: int}>
     */
    public static function runs(string $pdf): array
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('not a PDF: it starts '.json_encode(substr($pdf, 0, 8)));
        }

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        $runs = [];
        $page = 0;
        foreach ($streams[1] as $raw) {
            $text = @gzuncompress($raw);
            if ($text === false || ! str_contains($text, ' Td ')) {
                continue;
            }
            $page++;
            preg_match_all('/BT ([\-\d.]+) ([\-\d.]+) Td (?:[^\[]*?)\[\((.*?)\)\] TJ/s', $text, $found, PREG_SET_ORDER);
            foreach ($found as $m) {
                $runs[] = [
                    'x' => (float) $m[1],
                    'y' => (float) $m[2],
                    'text' => self::decode($m[3]),
                    'page' => $page,
                ];
            }
        }

        return $runs;
    }

    /** A literal string of the content stream as plain UTF-8 text. */
    private static function decode(string $literal): string
    {
        $bytes = '';
        $slash = chr(92);
        for ($i = 0, $n = strlen($literal); $i < $n; $i++) {
            if ($literal[$i] === $slash && $i + 1 < $n) {
                $i++;
                $bytes .= match ($literal[$i]) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", default => $literal[$i],
                };

                continue;
            }
            $bytes .= $literal[$i];
        }

        return trim((string) mb_convert_encoding((string) $bytes, 'UTF-8', 'UTF-16BE'));
    }

    /**
     * Runs grouped into lines: consecutive runs on the same page whose baselines
     * are within `$tolerance` points. Each line holds its runs from left to right.
     *
     * @param  list<array{x: float, y: float, text: string, page: int}>  $runs
     * @return list<list<array{x: float, y: float, text: string, page: int}>>
     */
    public static function lines(array $runs, float $tolerance = 1.0): array
    {
        $lines = [];
        $current = [];
        foreach ($runs as $run) {
            $last = $current === [] ? null : $current[array_key_last($current)];
            if ($last !== null && ($last['page'] !== $run['page'] || abs($last['y'] - $run['y']) > $tolerance)) {
                $lines[] = $current;
                $current = [];
            }
            $current[] = $run;
        }
        if ($current !== []) {
            $lines[] = $current;
        }

        return array_map(static function (array $line): array {
            usort($line, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);

            return $line;
        }, $lines);
    }

    /** "8,800.00 ETB" as 880000: money read as text, never as a float. */
    public static function cents(string $printed, string $currency): ?int
    {
        if (preg_match('/^(-?)([\d,]+)\.(\d{2}) '.preg_quote($currency, '/').'$/u', $printed, $m) !== 1) {
            return null;
        }
        $digits = (int) (str_replace(',', '', $m[2]).$m[3]);

        return $m[1] === '-' ? -$digits : $digits;
    }
}
