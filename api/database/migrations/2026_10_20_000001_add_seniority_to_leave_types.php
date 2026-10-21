<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Annual leave that grows with service.
 *
 * Labour Proclamation No. 1156/2019, Art. 77(1): sixteen working days for the
 * first year of service, plus one working day for every additional two years.
 * A leave type stored only `default_days`, so every employee got sixteen
 * however long they had served (blueprint assessment 2026-10-20, LV2).
 *
 * The backfill corrects only what the catalogue created and nobody changed:
 * an `annual` type still at 16 days with monthly accrual, in an organisation
 * not on the public-service profile, whose rules are not the proclamation's.
 * Anything an organisation edited is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('seniority_every_years')->nullable()->after('max_carry_days');
            $table->decimal('seniority_extra_days', 4, 1)->nullable()->after('seniority_every_years');
        });

        $publicTenants = DB::table('tenants')
            ->select(['id', 'settings'])
            ->get()
            ->filter(function (object $tenant): bool {
                $settings = is_string($tenant->settings) ? json_decode($tenant->settings, true) : null;

                return is_array($settings)
                    && (($settings['configuration_profile']['policy_profile'] ?? null) === 'ethiopia_public');
            })
            ->pluck('id')
            ->all();

        DB::table('leave_types')
            ->where('code', 'annual')
            ->where('default_days', 16)
            ->where('accrual_type', 'monthly')
            ->whereNull('seniority_every_years')
            ->when($publicTenants !== [], fn ($q) => $q->whereNotIn('tenant_id', $publicTenants))
            ->update(['seniority_every_years' => 2, 'seniority_extra_days' => 1]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['seniority_every_years', 'seniority_extra_days']);
        });
    }
};
