<?php

declare(strict_types=1);

namespace Tests\Accuracy\Restated;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the cells of a workbook, the way tests/Feature/Reports/ReportExcelTest.php
 * does (`xlRead()`): ZipArchive and SimpleXML over xl/workbook.xml and each
 * worksheet, shared strings resolved, a number kept as the text the file holds.
 *
 * Static only: Pest loads every test file into one process.
 */
final class XlsxRead
{
    /**
     * @return array<string, array<int, array<string, array{0: string, 1: string}>>> sheet name => row number => column letter => [type s|n, value]
     */
    public static function sheets(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'restated-xl-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('the workbook is not a zip file');
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach ((new SimpleXMLElement($xml))->si as $si) {
                $shared[] = (string) $si->t.implode('', array_map('strval', iterator_to_array($si->r ?? [], false)));
            }
        }

        $names = [];
        foreach ((new SimpleXMLElement((string) $zip->getFromName('xl/workbook.xml')))->sheets->sheet as $sheet) {
            $names[] = (string) $sheet['name'];
        }

        $sheets = [];
        foreach ($names as $i => $name) {
            $xml = (string) $zip->getFromName('xl/worksheets/sheet'.($i + 1).'.xml');
            foreach ((new SimpleXMLElement($xml))->sheetData->row ?? [] as $row) {
                foreach ($row->c as $cell) {
                    preg_match('/^([A-Z]+)(\d+)$/', (string) $cell['r'], $m);
                    $type = (string) $cell['t'];
                    $value = match ($type) {
                        's' => $shared[(int) $cell->v] ?? '',
                        'inlineStr' => (string) $cell->is->t,
                        default => (string) $cell->v,
                    };
                    $sheets[$name][(int) $m[2]][$m[1]] = [in_array($type, ['s', 'inlineStr', 'str'], true) ? 's' : 'n', $value];
                }
            }
        }

        $zip->close();
        unlink($path);

        return $sheets;
    }
}
