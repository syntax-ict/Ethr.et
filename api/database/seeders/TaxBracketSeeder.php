<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Payroll\TaxCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaxBracketSeeder extends Seeder
{
    public function run(): void
    {
        // Two ladders, both platform-wide, separated by effective date. The
        // seeded rows are what payroll actually reads once the database is
        // seeded; they must stay identical to the constants in
        // App\Services\Payroll\TaxCalculator. max = 0 marks the open-ended band.
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
                'brackets' => TaxCalculator::SUPERSEDED_BRACKETS,
            ],
            // Proclamation No. 1395/2025 — in force from 7 July 2025.
            [
                'from' => TaxCalculator::AMENDMENT_1395_EFFECTIVE_FROM,
                'to' => null,
                'brackets' => TaxCalculator::CURRENT_BRACKETS,
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
