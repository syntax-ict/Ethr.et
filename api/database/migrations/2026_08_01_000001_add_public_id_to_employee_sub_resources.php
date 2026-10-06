<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Convention #4: never expose a numeric PK in an API response.
 *
 * These three tables were created without a `public_id`, so their resources returned the
 * auto-increment key and the routes bound on it — sequential, enumerable, and leaking
 * tenant-wide row counts. Backfills ULIDs for existing rows before adding the unique index.
 */
return new class extends Migration
{
    private const TABLES = [
        'employee_bank_details',
        'employee_education',
        'employee_emergency_contacts',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->char('public_id', 26)->nullable()->after('id');
            });

            DB::table($table)->orderBy('id')->select('id')->chunk(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['public_id' => (string) Str::ulid()]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->char('public_id', 26)->nullable(false)->unique()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique($table.'_public_id_unique');
                $blueprint->dropColumn('public_id');
            });
        }
    }
};
