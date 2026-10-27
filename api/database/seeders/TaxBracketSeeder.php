<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaxBracketSeeder extends Seeder
{
    /**
     * Employment income tax, Proclamation No. 1395/2025, in force since
     * 7 July 2025. Monthly figures in integer cents; `deduction` is the
     * constant of the quick form `gross × rate − deduction`, derived from
     * the statutory cumulative form (band 3: ETB 300 + 20% over 4,000 is
     * 0.20g − 500, hence 50000 cents). null marks the open-ended band.
     *
     * Here, and in the migrations, is the only place a ladder is written
     * down: since 2026-10-27 the engine has none in code and reads only the
     * rows (TaxCalculator).
     *
     * @var list<array{min: int, max: int|null, rate: int, deduction: int}>
     */
    public const PROCLAMATION_1395_2025 = [
        ['min' => 0, 'max' => 200000, 'rate' => 0, 'deduction' => 0],
        ['min' => 200001, 'max' => 400000, 'rate' => 15, 'deduction' => 30000],
        ['min' => 400001, 'max' => 700000, 'rate' => 20, 'deduction' => 50000],
        ['min' => 700001, 'max' => 1000000, 'rate' => 25, 'deduction' => 85000],
        ['min' => 1000001, 'max' => 1400000, 'rate' => 30, 'deduction' => 135000],
        ['min' => 1400001, 'max' => null, 'rate' => 35, 'deduction' => 205000],
    ];

    /**
     * Proclamation No. 979/2016, in force 8 July 2016 to 6 July 2025. Kept
     * because payroll is re-runnable: reprocessing a period from before the
     * amendment must reproduce the tax withheld at the time.
     *
     * @var list<array{min: int, max: int|null, rate: int, deduction: int}>
     */
    public const PROCLAMATION_979_2016 = [
        ['min' => 0, 'max' => 60000, 'rate' => 0, 'deduction' => 0],
        ['min' => 60001, 'max' => 165000, 'rate' => 10, 'deduction' => 6000],
        ['min' => 165001, 'max' => 320000, 'rate' => 15, 'deduction' => 14250],
        ['min' => 320001, 'max' => 525000, 'rate' => 20, 'deduction' => 30250],
        ['min' => 525001, 'max' => 780000, 'rate' => 25, 'deduction' => 56500],
        ['min' => 780001, 'max' => 1090000, 'rate' => 30, 'deduction' => 95500],
        ['min' => 1090001, 'max' => null, 'rate' => 35, 'deduction' => 150000],
    ];

    public function run(): void
    {
        // Two ladders, both platform-wide, separated by effective date.
        // max = 0 marks the open-ended band in the table, whose column is
        // NOT NULL.
        //
        // Both are seeded, not just the current one, because payroll is
        // re-runnable: `PayrollEngine::void()` reprocesses a past period, and a
        // period before 7 July 2025 must reproduce the tax actually withheld at
        // the time rather than today's.
        $ladders = [
            // Proclamation No. 979/2016 — superseded 6 July 2025.
            [
                'from' => '2016-07-08',
                'to' => '2025-07-06',
                'reference' => 'Income Tax Proclamation No. 979/2016',
                'brackets' => self::PROCLAMATION_979_2016,
            ],
            // Proclamation No. 1395/2025 — in force from 7 July 2025.
            [
                'from' => '2025-07-07',
                'to' => null,
                'reference' => 'Income Tax (Amendment) Proclamation No. 1395/2025',
                'brackets' => self::PROCLAMATION_1395_2025,
            ],
        ];

        $seededKeys = [];

        foreach ($ladders as $ladder) {
            foreach ($ladder['brackets'] as $bracket) {
                // Keyed on effective_from as well as min_amount_cents: the two
                // ladders share band floors (0, for one), so keying on the floor
                // alone would make the second ladder overwrite the first.
                DB::table('tax_brackets')->updateOrInsert(
                    [
                        'tenant_id' => null,
                        'min_amount_cents' => $bracket['min'],
                        'effective_from' => $ladder['from'],
                    ],
                    [
                        'public_id' => (string) Str::ulid(),
                        'max_amount_cents' => $bracket['max'] ?: 0,
                        'rate' => $bracket['rate'],
                        'deduction_cents' => $bracket['deduction'],
                        'effective_to' => $ladder['to'],
                        'legal_reference' => $ladder['reference'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $seededKeys[] = $ladder['from'].':'.$bracket['min'];
            }
        }

        // Drop platform bands belonging to neither ladder — a leftover from an
        // earlier schedule would overlap the canonical bands above.
        DB::table('tax_brackets')
            ->whereNull('tenant_id')
            ->get(['id', 'effective_from', 'min_amount_cents'])
            ->reject(fn ($row) => in_array(
                Carbon::parse($row->effective_from)->toDateString().':'.$row->min_amount_cents,
                $seededKeys,
                true,
            ))
            ->each(fn ($row) => DB::table('tax_brackets')->where('id', $row->id)->delete());
    }
}
