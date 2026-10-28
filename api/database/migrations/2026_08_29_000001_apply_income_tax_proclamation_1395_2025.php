<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies Ethiopian Income Tax (Amendment) Proclamation No. 1395/2025 to the
 * platform-wide tax ladder.
 *
 * The amendment took effect **7 July 2025** and replaced Proclamation No.
 * 979/2016, which this deployment still carried: seven bands starting at a
 * 600 ETB tax-free threshold, rather than six starting at 2,000 ETB. Every
 * payslip produced since the amendment therefore over-deducted employment
 * income tax, and proportionally worst at the bottom — an employee on 2,000 ETB
 * was taxed 157.50 ETB a month on income that is now entirely exempt, roughly
 * 8% of gross.
 *
 * This does NOT delete the old bands. It closes them with `effective_to`, so a
 * period before the amendment still resolves the ladder that was in force at
 * the time — `PayrollEngine::void()` reprocesses past periods, and reproducing
 * a historical payslip must reproduce the tax actually withheld on it.
 *
 * Tenants that define their own ladder are untouched: `TaxCalculator` prefers a
 * tenant's own bands and only falls through to the platform ones. Those tenants
 * must be updated separately — see the operator note at the end of up().
 */
return new class extends Migration
{
    private const SUPERSEDED_ON = '2025-07-06';

    /**
     * The day Proclamation 1395/2025 took effect, and its ladder.
     *
     * This migration read both from constants on TaxCalculator until
     * 2026-10-27, when the engine stopped carrying a ladder in code. They are
     * written out here so the migration keeps doing exactly what it did.
     */
    private const EFFECTIVE_FROM = '2025-07-07';

    /** @var list<array{min: int, max: int|null, rate: int, deduction: int}> */
    private const BRACKETS = [
        ['min' => 0, 'max' => 200000, 'rate' => 0, 'deduction' => 0],
        ['min' => 200001, 'max' => 400000, 'rate' => 15, 'deduction' => 30000],
        ['min' => 400001, 'max' => 700000, 'rate' => 20, 'deduction' => 50000],
        ['min' => 700001, 'max' => 1000000, 'rate' => 25, 'deduction' => 85000],
        ['min' => 1000001, 'max' => 1400000, 'rate' => 30, 'deduction' => 135000],
        ['min' => 1400001, 'max' => null, 'rate' => 35, 'deduction' => 205000],
    ];

    public function up(): void
    {
        $from = self::EFFECTIVE_FROM;

        // Close every open platform band that predates the amendment. Scoped to
        // `effective_to IS NULL` so re-running this is inert, and to bands that
        // actually start earlier — a deployment seeded after this migration
        // already has the new ladder and must not have it closed.
        DB::table('tax_brackets')
            ->whereNull('tenant_id')
            ->whereNull('effective_to')
            ->whereDate('effective_from', '<', $from)
            ->update([
                'effective_to' => self::SUPERSEDED_ON,
                'updated_at' => now(),
            ]);

        foreach (self::BRACKETS as $bracket) {
            // updateOrInsert keyed on the band floor AND effective_from: the two
            // ladders share a floor at 0, so keying on the floor alone would
            // overwrite the historical row this migration just preserved.
            DB::table('tax_brackets')->updateOrInsert(
                [
                    'tenant_id' => null,
                    'min_amount_cents' => $bracket['min'],
                    'effective_from' => $from,
                ],
                [
                    'public_id' => (string) Str::ulid(),
                    // The column is NOT NULL; 0 is its open-ended sentinel.
                    'max_amount_cents' => $bracket['max'] ?: 0,
                    'rate' => $bracket['rate'],
                    'deduction_cents' => $bracket['deduction'],
                    'effective_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $overridingTenants = DB::table('tax_brackets')
            ->whereNotNull('tenant_id')
            ->whereNull('effective_to')
            ->distinct()
            ->count('tenant_id');

        if ($overridingTenants > 0 && PHP_SAPI === 'cli') {
            fwrite(STDERR, sprintf(
                "\n  NOTE: %d tenant(s) define their own tax ladder and were NOT changed by\n".
                "  this migration. Their brackets still follow Proclamation 979/2016 unless\n".
                "  updated via PUT /api/v1/payroll/tax-brackets.\n\n",
                $overridingTenants,
            ));
        }
    }

    public function down(): void
    {
        $from = self::EFFECTIVE_FROM;

        DB::table('tax_brackets')
            ->whereNull('tenant_id')
            ->whereDate('effective_from', $from)
            ->delete();

        DB::table('tax_brackets')
            ->whereNull('tenant_id')
            ->whereDate('effective_to', self::SUPERSEDED_ON)
            ->update([
                'effective_to' => null,
                'updated_at' => now(),
            ]);
    }
};
