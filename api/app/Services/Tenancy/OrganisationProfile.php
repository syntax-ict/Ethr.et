<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\AuditLog;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\TenantProfileAssignment;
use App\Services\Payroll\StatutoryRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of an organisation's profile: its type and sector from a
 * date on (tenant_profile_assignments), and `tenants.organization_type`,
 * which it keeps equal to the assignment in force today.
 *
 * Assigning on a date that already has an assignment replaces that one; any
 * other date adds a row, so the history an earlier period resolves against
 * is never rewritten.
 */
final class OrganisationProfile
{
    public function __construct(
        private readonly ProfileContext $context,
        private readonly StatutoryRules $rules,
    ) {}

    /**
     * Sets the organisation's type, and its sector when one is given (else
     * keeps the one it has), from the settings page or onboarding. Before any payroll is approved this is a correction: the type is
     * what the organisation always was, so its history is replaced and every
     * period resolves to it. Once one is approved, it applies from today and
     * earlier periods keep the type they were paid under.
     */
    public function set(Tenant $tenant, string $organizationType, ?int $by = null, ?string $sector = null): TenantProfileAssignment
    {
        $sector ??= $this->context->for($tenant->id, Carbon::today())['sector'];

        $approved = PayrollRun::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['approved', 'paid'])
            ->exists();

        if ($approved) {
            return $this->assign($tenant, $organizationType, $sector, Carbon::today(), $by);
        }

        $first = TenantProfileAssignment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->min('effective_from');
        $from = Carbon::parse($first ?? $tenant->created_at ?? Carbon::today());

        TenantProfileAssignment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereDate('effective_from', '>', $from->format('Y-m-d'))
            ->delete();

        return $this->assign($tenant, $organizationType, $sector, $from, $by);
    }

    public function assign(Tenant $tenant, string $organizationType, ?string $sector, CarbonInterface $from, ?int $by = null): TenantProfileAssignment
    {
        $on = $from->format('Y-m-d');

        $assignment = DB::transaction(function () use ($tenant, $organizationType, $sector, $on, $by): TenantProfileAssignment {
            $row = TenantProfileAssignment::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereDate('effective_from', $on)
                ->first() ?? new TenantProfileAssignment(['tenant_id' => $tenant->id, 'effective_from' => $on]);

            $row->fill([
                'organization_type' => $organizationType,
                'sector' => $sector !== null ? substr($sector, 0, 60) : null,
                'created_by' => $by,
            ]);
            $row->tenant_id = $tenant->id;
            $row->save();

            return $row;
        });

        $this->context->forget();
        $this->rules->forget();

        $today = $this->context->for($tenant->id, Carbon::today());
        if ($today['organization_type'] !== null && $tenant->organization_type !== $today['organization_type']) {
            $tenant->forceFill(['organization_type' => $today['organization_type']])->save();
        }

        AuditLog::record('organization_profile.assigned', $assignment, [
            'organization_type' => $organizationType,
            'sector' => $assignment->sector,
            'effective_from' => $on,
        ]);

        return $assignment;
    }
}
