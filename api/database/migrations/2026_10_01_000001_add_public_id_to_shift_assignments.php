<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Convention #4: an API row is addressed by a `public_id`, never a numeric key.
 *
 * `shift_assignments` was created without one, so ShiftAssignmentResource had
 * nothing to identify a row by and no endpoint could end or remove a single
 * assignment. Same shape as `2026_08_01_000001_add_public_id_to_employee_sub_resources`:
 * add nullable, backfill a ULID per existing row, then make it NOT NULL and
 * unique. Additive only; no existing column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable()->after('id');
        });

        DB::table('shift_assignments')->orderBy('id')->select('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('shift_assignments')
                    ->where('id', $row->id)
                    ->update(['public_id' => (string) Str::ulid()]);
            }
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropUnique('shift_assignments_public_id_unique');
            $table->dropColumn('public_id');
        });
    }
};
