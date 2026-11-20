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
 *   punch is handled; kept on a quarantined row.
 * - `failed_at` / `failure`: set when the engine refused the punch or the job
 *   threw, so the row is quarantined and never purged by retention.
 * - `dismissed_at`: a person looked at a quarantined row and let it go. The
 *   row stays (its `failed_at` too) for a week, then retention removes it.
 *
 * Index names are explicit: MariaDB refuses an identifier over 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_inbound_punches', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable()->after('id');
            $table->mediumText('raw')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure', 40)->nullable();
            $table->timestamp('dismissed_at')->nullable();
        });

        DB::table('device_inbound_punches')->orderBy('id')->select('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('device_inbound_punches')->where('id', $row->id)->update(['public_id' => (string) Str::ulid()]);
            }
        });

        Schema::table('device_inbound_punches', function (Blueprint $table) {
            $table->unique('public_id', 'dip_public_id_unique');
            $table->index(['tenant_id', 'failed_at'], 'dip_tenant_failed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('device_inbound_punches', function (Blueprint $table) {
            $table->dropUnique('dip_public_id_unique');
            $table->dropIndex('dip_tenant_failed_idx');
        });

        Schema::table('device_inbound_punches', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'raw', 'failed_at', 'failure', 'dismissed_at']);
        });
    }
};
