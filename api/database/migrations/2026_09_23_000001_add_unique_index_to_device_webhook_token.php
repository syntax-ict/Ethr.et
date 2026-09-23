<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make `devices.webhook_token` unique at the storage layer.
     *
     * `DeviceController::resolveWebhookDevice()` looks a device up by token
     * alone, with the tenant scope removed — the token both selects and
     * authenticates. That is safe only because the token is
     * `bin2hex(random_bytes(32))` (see `Device::generateWebhookToken()`), so two
     * devices cannot collide. Nothing enforced that: the column was added as a
     * plain nullable string, and a future change to a weaker generator — a short
     * `Str::random()`, a derived value, an operator-supplied token — would turn
     * that lookup into a cross-tenant read without any test objecting.
     *
     * A unique index moves the invariant from "the generator happens to be
     * strong" to "the database refuses the collision". A weakened generator then
     * fails at the insert, loudly, instead of silently resolving to another
     * tenant's device.
     *
     * NULL is deliberately still allowed, and a unique index permits any number
     * of NULLs on both MariaDB and SQLite. Token-less devices are a supported
     * mode: they authenticate by `webhook_ip_allowlist` instead (fail-closed —
     * see `Device::webhookIpAllowed()`), and `DemoTenantSeeder` creates devices
     * without a token. This migration does not make the column NOT NULL.
     */
    public function up(): void
    {
        $this->guardAgainstExistingDuplicates();

        Schema::table('devices', function (Blueprint $table): void {
            $table->unique('webhook_token');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique(['webhook_token']);
        });
    }

    /**
     * Refuse to run against data the index would silently misrepresent.
     *
     * By construction there should be none: tokens are generated per row on
     * create, on rotate, and by the 2026_08_23 backfill. If there are, that is
     * itself the defect this index exists to catch, and the migration says so
     * rather than failing with an opaque driver error.
     *
     * The duplicated values are credentials, so the message reports how many
     * rows are involved and nothing else — never a token.
     */
    private function guardAgainstExistingDuplicates(): void
    {
        // Counted through a subquery: calling count() directly on a grouped
        // query returns the size of the first group, not the number of groups.
        $duplicateGroups = DB::query()->fromSub(
            DB::table('devices')
                ->select('webhook_token')
                ->whereNotNull('webhook_token')
                ->groupBy('webhook_token')
                ->havingRaw('COUNT(*) > 1'),
            'duplicates',
        )->count();

        if ($duplicateGroups === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot add a unique index to devices.webhook_token: %d token value(s) are shared by more than one device. '
            .'Rotate the affected devices (Device::generateWebhookToken()) before migrating. '
            .'Token values are omitted here deliberately — they are credentials.',
            $duplicateGroups,
        ));
    }
};
