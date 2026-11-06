<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * One platform row per organisation type for the month length payroll and
     * Art. 85 sick leave divide by, so each type can be approved or changed on
     * its own with a new dated row, never a code change. The accountant may
     * well say 22 for a ministry and 26 for a factory.
     *
     * No figure is chosen here: each row copies what the all-types row holds
     * today, so no organisation's payroll moves. All stay `confirmed = false`
     * until the sign-off, and an organisation's own value still wins.
     */
    public function up(): void
    {
        $base = DB::table('statutory_parameters')
            ->whereNull('tenant_id')
            ->whereNull('organization_type')
            ->where('code', 'payroll.working_days_per_month')
            ->orderByDesc('effective_from')
            ->first();

        if ($base === null) {
            return;
        }

        $now = now();

        foreach (DB::table('organization_types')->pluck('code') as $type) {
            $exists = DB::table('statutory_parameters')
                ->whereNull('tenant_id')
                ->where('organization_type', $type)
                ->where('code', 'payroll.working_days_per_month')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('statutory_parameters')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => null,
                'organization_type' => $type,
                'code' => 'payroll.working_days_per_month',
                'value' => $base->value,
                'tenant_overridable' => true,
                'legal_reference' => 'ETHR convention, set per organisation type (ACCOUNTANT-SIGN-OFF.md)',
                'confirmed' => false,
                'note' => 'Awaiting accountant approval for this organisation type. Change it with a new dated row.',
                'effective_from' => $base->effective_from,
                'effective_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('statutory_parameters')
            ->whereNull('tenant_id')
            ->whereNotNull('organization_type')
            ->where('code', 'payroll.working_days_per_month')
            ->where('note', 'like', 'Awaiting accountant approval for this organisation type.%')
            ->delete();
    }
};
