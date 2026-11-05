<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An organisation that moved from its trial to a paid plan before 2026-11-03
 * kept `tenants.status = trial`: only its subscription became active. From its
 * old trial end date it would have been refused as an expired trial. This
 * makes every such organisation active, which is what the plan change now
 * does itself (BillingService::changePlan).
 *
 * Only an organisation still on `trial` with a live `active` subscription
 * moves. A suspended or cancelled organisation, and one still trialling, is
 * left as it is. Nothing to undo: the old state was the defect.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->where('status', 'trial')
            ->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('subscriptions')
                ->whereColumn('subscriptions.tenant_id', 'tenants.id')
                ->where('subscriptions.status', 'active'))
            ->update(['status' => 'active', 'updated_at' => now()]);
    }

    public function down(): void {}
};
