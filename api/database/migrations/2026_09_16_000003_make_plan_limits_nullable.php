<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a plan express "no ceiling" as null, which the code already assumed it could.
 *
 * `PlanLimitService::remaining()` returns null for an uncapped resource and
 * `canAdd()` reads `$remaining === null` as "always allowed" — so the service
 * was written against nullable limits. The generated API contract agrees:
 * `Plan.max_employees` is typed `number | null`. Only the schema disagreed,
 * having carried `unsignedInteger()->default(...)` and NOT NULL since the first
 * migration.
 *
 * `PlanSeeder` bridged the gap with 999999/999/999 — a number big enough to
 * look like infinity. Harmless while the figure was internal. It stopped being
 * harmless when the public pricing page began rendering these columns and
 * advertised "Up to 999,999 employees".
 *
 * The defaults are restated deliberately: Laravel 11+ native column changes
 * drop any attribute not re-specified, so omitting them here would silently
 * remove the defaults along with the NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_employees')->nullable()->default(50)->change();
            $table->unsignedInteger('max_branches')->nullable()->default(5)->change();
            $table->unsignedInteger('max_devices')->nullable()->default(10)->change();
        });
    }

    public function down(): void
    {
        // Anything uncapped has to become a number again before NOT NULL can be
        // restored, or the ALTER fails on exactly the rows this migration
        // existed to allow. The sentinel is the value that was there before.
        DB::table('plans')->whereNull('max_employees')->update(['max_employees' => 999999]);
        DB::table('plans')->whereNull('max_branches')->update(['max_branches' => 999]);
        DB::table('plans')->whereNull('max_devices')->update(['max_devices' => 999]);

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_employees')->default(50)->change();
            $table->unsignedInteger('max_branches')->default(5)->change();
            $table->unsignedInteger('max_devices')->default(10)->change();
        });
    }
};
