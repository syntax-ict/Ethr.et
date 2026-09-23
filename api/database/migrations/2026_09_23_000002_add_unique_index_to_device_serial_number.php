<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make a live device's `(serial_number, adapter_type)` unique.
     *
     * `DeviceController::resolveWebhookDevice()` selects a device by serial and
     * adapter type with the tenant scope dropped, then `->first()`. Nothing
     * stopped two tenants holding the same serial, so which row came back was
     * undefined — `BASELINE.md` §11d site 1. The 2026-08-23 migration's own
     * comment calls serials *"printed on the hardware, enumerable, and not
     * unique in the schema"*. This is the half of that sentence that was still
     * true.
     *
     * **Why a generated column rather than a plain unique index.** `Device`
     * soft-deletes, `DeviceController::destroy()` soft-deletes, and there is no
     * restore route. A plain unique index counts soft-deleted rows, so deleting
     * a device would burn its serial permanently with no way back through the
     * API — a worse regression than the ambiguity it fixes. MariaDB has no
     * partial indexes, so the standard workaround is a generated column that
     * goes NULL once the row is deleted: NULLs repeat freely in a unique index
     * on both MariaDB and SQLite, so any number of deleted rows may share a
     * serial while live ones may not.
     *
     * The column is VIRTUAL, not STORED, deliberately: SQLite permits adding a
     * virtual generated column with `ALTER TABLE` and refuses a stored one, and
     * the test suite runs on SQLite while production runs on MariaDB.
     *
     * Deliberately global rather than `(tenant_id, …)`. A tenant-scoped index
     * would leave two *different* tenants free to hold the same serial, which
     * is exactly the case the bypassed lookup cannot distinguish — it does not
     * state `tenant_id`. Only global uniqueness makes that `->first()` sound.
     * The trade is stated in §11h: registering a serial another tenant already
     * holds now fails, which tells the caller that serial exists somewhere.
     */
    public function up(): void
    {
        $this->guardAgainstExistingDuplicates();

        Schema::table('devices', function (Blueprint $table): void {
            $table->string('serial_number_active')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN serial_number ELSE NULL END')
                ->after('serial_number');
        });

        // Separate statement: SQLite cannot add a column and index it in one
        // ALTER, and Laravel emits one ALTER per Schema::table closure.
        Schema::table('devices', function (Blueprint $table): void {
            $table->unique(['serial_number_active', 'adapter_type'], 'devices_live_serial_unique');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique('devices_live_serial_unique');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('serial_number_active');
        });
    }

    /**
     * Refuse to run against data the index cannot represent.
     *
     * Unlike the `webhook_token` index in §11e, duplicates here are *plausible*
     * — nothing has ever prevented them and the API never validated the field —
     * so this is a real possibility rather than a formality. It reports the
     * device `public_id`s so an operator can find and resolve them, and the
     * owning `tenant_id`s so cross-tenant collisions are distinguishable from
     * within-tenant ones. Serial values are not secrets (they are printed on
     * the hardware) but are left out anyway: the public_id is the handle you
     * act on.
     */
    private function guardAgainstExistingDuplicates(): void
    {
        $duplicateGroups = DB::table('devices')
            ->select('serial_number', 'adapter_type')
            ->whereNull('deleted_at')
            ->whereNotNull('serial_number')
            ->groupBy('serial_number', 'adapter_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateGroups->isEmpty()) {
            return;
        }

        $offenders = DB::table('devices')
            ->whereNull('deleted_at')
            ->whereNotNull('serial_number')
            ->whereIn('serial_number', $duplicateGroups->pluck('serial_number')->all())
            ->orderBy('id')
            ->get(['public_id', 'tenant_id', 'adapter_type']);

        $lines = $offenders->map(
            fn ($row) => sprintf('  %s (tenant %d, %s)', $row->public_id, $row->tenant_id, $row->adapter_type)
        )->implode("\n");

        throw new RuntimeException(sprintf(
            "Cannot make devices.(serial_number, adapter_type) unique: %d serial/adapter pair(s) are shared by "
            ."more than one live device.\n\nAffected devices:\n%s\n\n"
            .'Resolve by deleting or re-serialising the duplicates, then migrate again. '
            .'Serial values are omitted here; the public_id is the handle.',
            $duplicateGroups->count(),
            $lines,
        ));
    }
};
