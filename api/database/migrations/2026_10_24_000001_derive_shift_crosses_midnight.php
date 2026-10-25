<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `shifts.crosses_midnight` is derived from the times on save from 2026-10-24
 * (Shift::booted). Rows saved before then carry whatever was typed, so a
 * 22:00-06:00 shift saved without the flag was read as ending the same
 * morning. This brings every stored row in line with the rule.
 *
 * Every tenant's rows, on purpose: the flag is a fact about the times, not a
 * tenant's choice. `time` columns compare correctly as strings on both
 * MariaDB and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shifts')->whereColumn('end_time', '<=', 'start_time')->update(['crosses_midnight' => true]);
        DB::table('shifts')->whereColumn('end_time', '>', 'start_time')->update(['crosses_midnight' => false]);
    }

    public function down(): void
    {
        // The derived value is the correct one; there is nothing to restore.
    }
};
