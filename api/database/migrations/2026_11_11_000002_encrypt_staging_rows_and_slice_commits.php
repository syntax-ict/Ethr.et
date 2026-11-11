<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging for the reviewed update of a matched employee (One employee
 * identity, PR 5).
 *
 * `migration_staging_rows.raw` becomes LONGTEXT so it can hold ciphertext. It
 * is a person's details as a file or a device gave them, and it sat in a JSON
 * column in clear text for as long as the list was kept. MariaDB's JSON is
 * LONGTEXT with a JSON_VALID check, which refuses anything encrypted; SQLite
 * does not care. Rows already there are left as they are and still read
 * (`App\Casts\EncryptedJson` takes both), so there is nothing to backfill.
 *
 * `changes` is what committing the row would write to the employee it
 * matched: per field, a blank to fill or a difference for a person to decide.
 * Encrypted for the same reason.
 *
 * `migration_batches.commit_cursor` is the last row a commit has dealt with.
 * A list is committed a slice at a time, because one request has thirty
 * seconds on the host and a thousand people do not fit in it.
 *
 * Nullable columns only, no data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_staging_rows', function (Blueprint $table) {
            $table->longText('raw')->change();
            $table->longText('changes')->nullable();
        });

        Schema::table('migration_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('commit_cursor')->nullable();
        });
    }

    /**
     * `raw` stays LONGTEXT. Rows written since `up()` hold ciphertext, and
     * turning the column back into JSON would fail its validity check on the
     * first of them. A LONGTEXT column reads the older rows exactly as the
     * JSON one did.
     */
    public function down(): void
    {
        Schema::table('migration_staging_rows', function (Blueprint $table) {
            $table->dropColumn('changes');
        });

        Schema::table('migration_batches', function (Blueprint $table) {
            $table->dropColumn('commit_cursor');
        });
    }
};
