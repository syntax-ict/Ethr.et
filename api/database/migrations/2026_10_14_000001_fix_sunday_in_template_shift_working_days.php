<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The hospital and hotel templates wrote Sunday as `0` in `working_days`.
 * Shifts use ISO days (1 = Monday … 7 = Sunday) and `Shift::isWorkingDay`
 * compares against `dayOfWeekIso`, which is never 0 — so every shift those
 * templates created treated Sunday as a rest day, for payroll's rest-day
 * overtime and the punch assistant alike. The API has always refused 0, so a
 * 0 can only have come from a template; rewriting it to 7 is what the
 * template meant.
 *
 * Data only, no schema change. `down()` is a no-op: after the fix there is no
 * telling a corrected row from one that always said 7, and restoring the bug
 * would serve no one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shifts')
            ->where(function ($query): void {
                $query->where('working_days', '0')
                    ->orWhere('working_days', 'like', '0,%')
                    ->orWhere('working_days', 'like', '%,0')
                    ->orWhere('working_days', 'like', '%,0,%');
            })
            ->orderBy('id')
            ->select(['id', 'working_days'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $days = [];
                    foreach (explode(',', (string) $row->working_days) as $part) {
                        $day = (int) trim($part);
                        $day = $day === 0 ? 7 : $day;
                        if ($day >= 1 && $day <= 7) {
                            $days[$day] = $day;
                        }
                    }
                    ksort($days);

                    DB::table('shifts')->where('id', $row->id)->update(['working_days' => implode(',', $days)]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally empty — see the class docblock.
    }
};
