<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The inbox keeps what a terminal sent until it is handled, and keeps what
 * could not be handled until a person has seen it.
 *
 * - `public_id`: the id the API shows (never the numeric id).
 * - `raw`: the event as received (capped at 16 KB in code). Cleared once the
 *   punch is recorded; kept on a quarantined row.
 * - `failed_at` / `failure`: set when the engine refused the punch or the job
 *   threw, so the row is quarantined and never purged by retention.
 * - `dismissed_at`: a person looked at a quarantined row and let it go. The
 *   row stays (its `failed_at` too) for a week, then retention removes it.
 *
 * The release steps run this through `POST /maintenance/migrate`, which is held
 * to 30 seconds, so it is written to be run again: every step checks whether it
 * is already done, the backfill works in transactions of 500 rows and touches
 * only rows still without a `public_id`, and the unique index is added last.
 *
 * Rows already in the table are carried over. Before this change the job wrote
 * `error` only for a punch it could not record (a refusal, an exception, a
 * row that failed five times), and nothing else, so a processed row with an
 * `error` is a failure that was about to be purged: it becomes `failure =
 * error` and is kept, replayable from its badge, time and type.
 *
 * Index names are explicit: MariaDB refuses an identifier over 64 characters.
 */
return new class extends Migration
{
    private const TABLE = 'device_inbound_punches';

    public function up(): void
    {
        $columns = [
            'public_id' => fn (Blueprint $t) => $t->char('public_id', 26)->nullable()->after('id'),
            'raw' => fn (Blueprint $t) => $t->mediumText('raw')->nullable(),
            'failed_at' => fn (Blueprint $t) => $t->timestamp('failed_at')->nullable(),
            'failure' => fn (Blueprint $t) => $t->string('failure', 40)->nullable(),
            'dismissed_at' => fn (Blueprint $t) => $t->timestamp('dismissed_at')->nullable(),
        ];

        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, $add);
            }
        }

        while (($ids = DB::table(self::TABLE)->whereNull('public_id')->orderBy('id')->limit(500)->pluck('id'))->isNotEmpty()) {
            DB::transaction(function () use ($ids): void {
                foreach ($ids as $id) {
                    DB::table(self::TABLE)->where('id', $id)->update(['public_id' => (string) Str::ulid()]);
                }
            });
        }

        DB::table(self::TABLE)
            ->whereNotNull('processed_at')
            ->whereNotNull('error')
            ->whereNull('failed_at')
            ->update(['failed_at' => DB::raw('processed_at'), 'failure' => 'error']);

        if (! Schema::hasIndex(self::TABLE, 'dip_public_id_unique')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->unique('public_id', 'dip_public_id_unique'));
        }

        if (! Schema::hasIndex(self::TABLE, 'dip_tenant_failed_idx')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->index(['tenant_id', 'failed_at'], 'dip_tenant_failed_idx'));
        }
    }

    public function down(): void
    {
        // The composite index became the one the `tenant_id` foreign key leans
        // on, so MySQL and MariaDB refuse to drop it (error 1553) until the
        // key has an index of its own again.
        if (! Schema::hasIndex(self::TABLE, 'dip_tenant_idx')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->index('tenant_id', 'dip_tenant_idx'));
        }

        if (Schema::hasIndex(self::TABLE, 'dip_tenant_failed_idx')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex('dip_tenant_failed_idx'));
        }

        if (Schema::hasIndex(self::TABLE, 'dip_public_id_unique')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropUnique('dip_public_id_unique'));
        }

        $present = array_values(array_filter(
            ['public_id', 'raw', 'failed_at', 'failure', 'dismissed_at'],
            fn (string $column) => Schema::hasColumn(self::TABLE, $column),
        ));

        if ($present !== []) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn($present));
        }
    }
};
