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
        // Ethiopian income tax brackets (Proclamation No. 979/2016)
        $brackets = [
            ['min' => 0,       'max' => 60000,     'rate' => 0,    'deduction' => 0],
            ['min' => 60001,   'max' => 150600,    'rate' => 10,   'deduction' => 6000],
            ['min' => 150601,  'max' => 266400,    'rate' => 15,   'deduction' => 13530],
            ['min' => 266401,  'max' => 393600,    'rate' => 20,   'deduction' => 26830],
            ['min' => 393601,  'max' => 550800,    'rate' => 25,   'deduction' => 46530],
            ['min' => 550801,  'max' => 999999999, 'rate' => 30,   'deduction' => 74080],
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
    }
}
