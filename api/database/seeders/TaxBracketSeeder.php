<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaxBracketSeeder extends Seeder
{
    public function run(): void
    {
        // Ethiopian monthly income tax brackets (Proclamation No. 979/2016),
        // in integer cents. Must stay identical to
        // App\Services\Payroll\TaxCalculator::$defaultBrackets — the seeded
        // rows are what payroll actually reads once the database is seeded.
        // max = 0 marks the final, open-ended bracket.
        $brackets = [
            ['min' => 0,       'max' => 60000,   'rate' => 0,  'deduction' => 0],
            ['min' => 60001,   'max' => 165000,  'rate' => 10, 'deduction' => 6000],
            ['min' => 165001,  'max' => 320000,  'rate' => 15, 'deduction' => 14250],
            ['min' => 320001,  'max' => 525000,  'rate' => 20, 'deduction' => 30250],
            ['min' => 525001,  'max' => 780000,  'rate' => 25, 'deduction' => 56500],
            ['min' => 780001,  'max' => 1090000, 'rate' => 30, 'deduction' => 95500],
            ['min' => 1090001, 'max' => 0,       'rate' => 35, 'deduction' => 150000],
        ];

        foreach ($brackets as $bracket) {
            DB::table('tax_brackets')->updateOrInsert(
                [
                    'tenant_id' => null,
                    'min_amount_cents' => $bracket['min'],
                ],
                [
                    'public_id' => (string) Str::ulid(),
                    'max_amount_cents' => $bracket['max'],
                    'rate' => $bracket['rate'],
                    'deduction_cents' => $bracket['deduction'],
                    'effective_from' => '2016-07-08',
                    'effective_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Drop platform brackets from a previous (superseded) ladder — leaving
        // them behind would overlap the canonical bands above.
        DB::table('tax_brackets')
            ->whereNull('tenant_id')
            ->whereNotIn('min_amount_cents', array_column($brackets, 'min'))
            ->delete();
    }
}
