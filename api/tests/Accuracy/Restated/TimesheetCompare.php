<?php

declare(strict_types=1);

namespace Tests\Accuracy\Restated;

use App\Services\Attendance\TimesheetLedger;

/**
 * Holds one timesheet output (TimesheetOutputs) against the ledger, person by
 * person and in total. The expected side is the ledger's own totals and cells
 * put through the output's stated format (TimesheetFigures); nothing is
 * recomputed from attendance.
 *
 * Static only: Pest loads every test file into one process.
 */
final class TimesheetCompare
{
    /**
     * @param  array{days: list<string>, employees: array<int, array<string, mixed>>}  $ledger
     * @param  array{people: list<array<string, mixed>>, total: array<string, string>}  $output
     * @param  list<array{rate: float, label: string, types: list<string>}>  $rateColumns
     * @return array{compared: int, misses: list<string>}
     */
    public static function against(string $outputName, array $ledger, array $output, array $rateColumns, bool $hasFigures = true): array
    {
        $compared = 0;
        $misses = [];
        $rows = array_values($ledger['employees']);

        $same = static function (string $field, string $engine, string $shown) use (&$compared, &$misses, $outputName): void {
            $compared++;
            if ($engine !== $shown) {
                $misses[] = "{$outputName}, {$field}: the ledger gives {$engine} and the output shows {$shown}";
            }
        };

        if (count($output['people']) !== count($rows)) {
            return ['compared' => 0, 'misses' => ["{$outputName}: the ledger has ".count($rows).' people and the output lists '.count($output['people'])]];
        }

        foreach ($rows as $i => $row) {
            $who = $row['employee']->name;
            $shown = $output['people'][$i];

            if (($shown['name'] ?? '') !== '' && $shown['name'] !== $who) {
                $misses[] = "{$outputName}, row ".($i + 1).": the ledger's person is {$who} and the output lists {$shown['name']}";
            }

            // The day by day codes.
            $codes = array_values(array_map(static fn (array $cell): string => $cell['code']?->short() ?? '·', $row['cells']));
            foreach ($ledger['days'] as $d => $day) {
                $same("{$who} {$day} code", $codes[$d], (string) ($shown['codes'][$d] ?? '(missing)'));
            }
            $same("{$who} check mark", $row['check_ok'] ? '✓' : '!', (string) $shown['check']);

            // The per-day numbers, where the output carries them (the grid does).
            foreach ($shown['cells'] ?? [] as $day => $cell) {
                foreach (['worked_minutes', 'late_minutes', 'early_minutes', 'scheduled_minutes'] as $field) {
                    $same("{$who} {$day} {$field}", (string) $row['cells'][$day][$field], (string) $cell[$field]);
                }
                foreach (TimesheetLedger::OVERTIME_TYPES as $type) {
                    $same("{$who} {$day} overtime {$type}", (string) ($row['cells'][$day]['overtime'][$type] ?? 0), (string) ($cell['overtime'][$type] ?? 0));
                }
            }

            if ($hasFigures) {
                foreach (TimesheetFigures::expected([$row['totals']], $rateColumns) as $key => $engine) {
                    $same("{$who} ".$key, $engine, (string) ($shown['figures'][$key] ?? '(missing)'));
                }
            }
        }

        if ($hasFigures) {
            $expected = TimesheetFigures::expected(array_map(static fn (array $row): array => $row['totals'], $rows), $rateColumns);
            foreach ($expected as $key => $engine) {
                $same('grand total '.$key, $engine, (string) ($output['total'][$key] ?? '(missing)'));
            }
        }

        return ['compared' => $compared, 'misses' => $misses];
    }
}
