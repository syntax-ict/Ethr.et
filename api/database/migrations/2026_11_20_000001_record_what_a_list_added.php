<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a committed staged list did, so that it can be undone (a list's
 * additions, 2026-11-20).
 *
 * `migration_staging_rows.created_employee` says what a row did to the
 * person it points at. A row chosen as "create" either made the person or,
 * because they appeared between the review and the commit, only linked to
 * someone already here; both wrote the same two columns and nothing said which
 * happened. True: this row made them. False: the row linked to someone already
 * here. Null: not a processed create row, or processed before this column
 * existed. A list committed earlier is worked out from when each person was
 * made (App\Services\Migration\ListAdditions); nothing is backfilled here.
 *
 * `migration_batches.undone_at` is when an undo last finished, and
 * `undo_totals` is `{"archived": all runs, "pending": archived since the last
 * audit entry}`. An undo is done a slice at a time, like a commit, and writes
 * one audit entry when it ends, so what the slices have archived so far is
 * kept on the list.
 *
 * Nullable columns only, no data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_staging_rows', function (Blueprint $table) {
            $table->boolean('created_employee')->nullable();
        });

        Schema::table('migration_batches', function (Blueprint $table) {
            $table->timestamp('undone_at')->nullable();
            $table->json('undo_totals')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('migration_staging_rows', function (Blueprint $table) {
            $table->dropColumn('created_employee');
        });

        Schema::table('migration_batches', function (Blueprint $table) {
            $table->dropColumn(['undone_at', 'undo_totals']);
        });
    }
};
