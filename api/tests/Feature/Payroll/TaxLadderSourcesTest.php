<?php

declare(strict_types=1);

use Database\Seeders\TaxBracketSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The Proclamation 1395/2025 ladder is written down twice: by migration
 * 2026_08_29_000001 (which may not be edited) and by TaxBracketSeeder (which
 * also carries the 979/2016 ladder no migration writes). This pins the two
 * copies to each other, so a correction made in one place and not the other
 * fails here instead of paying two organisations two different taxes.
 */
function platformLadder(string $from): array
{
    return DB::table('tax_brackets')
        ->whereNull('tenant_id')
        ->whereDate('effective_from', $from)
        ->orderBy('min_amount_cents')
        ->get(['min_amount_cents', 'max_amount_cents', 'rate', 'deduction_cents'])
        ->map(fn (object $row): array => [
            'min' => (int) $row->min_amount_cents,
            'max' => (int) $row->max_amount_cents === 0 ? null : (int) $row->max_amount_cents,
            'rate' => (int) $row->rate,
            'deduction' => (int) $row->deduction_cents,
        ])
        ->all();
}

it('writes the same 1395/2025 ladder from the migration as from the seeder', function () {
    expect(platformLadder('2025-07-07'))->toBe(TaxBracketSeeder::PROCLAMATION_1395_2025);
});

it('changes nothing when the seeder runs over the migrated ladder', function () {
    $before = platformLadder('2025-07-07');
    $count = DB::table('tax_brackets')->whereNull('tenant_id')->whereDate('effective_from', '2025-07-07')->count();

    $this->seed(TaxBracketSeeder::class);

    expect(platformLadder('2025-07-07'))->toBe($before)
        ->and(DB::table('tax_brackets')->whereNull('tenant_id')->whereDate('effective_from', '2025-07-07')->count())->toBe($count)
        ->and(platformLadder('2016-07-08'))->toBe(TaxBracketSeeder::PROCLAMATION_979_2016);
});
