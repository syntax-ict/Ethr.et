<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\AuditLog;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\TenantProfileAssignment;
use App\Services\Calendar\OrganisationMonth;
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
     * keeps the one it has), from the settings page or onboarding. Before any
     * payroll is approved this is a correction: the type is what the
     * organisation always was, so its history is replaced and every period
     * resolves to it. Once one is approved, it applies from `$from`, a month
     * start after the last approved period that UpdateOrganizationRequest
     * checked, and earlier periods keep the type they were paid under.
     */
    public function set(Tenant $tenant, string $organizationType, ?int $by = null, ?string $sector = null, ?CarbonInterface $from = null): TenantProfileAssignment
    {
        $today = $this->context->for($tenant->id, Carbon::today());
        $sector ??= $today['sector'];

        if ($this->lastApprovedEnd($tenant) !== null) {
            // Never retroactive: from the future date UpdateOrganizationRequest
            // checked, else the earliest one allowed. A change scheduled
            // earlier and not yet in force is replaced by this one.
            $keepThrough = Carbon::today();
            $from ??= $organizationType === $today['organization_type']
                ? Carbon::today()
                : $this->earliestChange($tenant) ?? Carbon::today();
        } else {
            $first = TenantProfileAssignment::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->min('effective_from');
            $from = Carbon::parse($first ?? $tenant->created_at ?? Carbon::today());
            $keepThrough = $from;
        }

        TenantProfileAssignment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereDate('effective_from', '>', $keepThrough->format('Y-m-d'))
            ->delete();
        $this->context->forget();

        // A change that starts later needs the type in force until then to
        // be on record, or the later row would be read back over today.
        if ($from->format('Y-m-d') > Carbon::today()->format('Y-m-d') && ! $this->context->assignedBy($tenant->id, Carbon::today()) && $today['organization_type'] !== null) {
            $this->assign($tenant, $today['organization_type'], $today['sector'], Carbon::parse($tenant->created_at ?? Carbon::today()), $by);
        }

        return $this->assign($tenant, $organizationType, $sector, $from, $by);
    }

    /**
     * The first day a change of type may take effect: null while no payroll
     * is approved (a change is then a correction of history), else the first
     * organisation-month start after the last approved period and not before
     * today. Pagume is never a start: it is paid with a neighbouring month.
     */
    public function earliestChange(Tenant $tenant): ?CarbonInterface
    {
        $last = $this->lastApprovedEnd($tenant);
        if ($last === null) {
            return null;
        }

        $floor = Carbon::parse($last)->addDay()->max(Carbon::today())->startOfDay();
        $month = OrganisationMonth::forTenant($tenant);
        $of = $month->monthOf($floor);
        $start = $month->start($of['year'], $of['month']);

        while ($start->lt($floor) || ! $this->isChangeDate($tenant, $start)) {
            $of = $month->shift($of['year'], $of['month'], 1);
            $start = $month->start($of['year'], $of['month']);
        }

        return $start;
    }

    /** Whether a change of type may take effect on this day: the start of an organisation month other than Pagume. */
    public function isChangeDate(Tenant $tenant, CarbonInterface $date): bool
    {
        $month = OrganisationMonth::forTenant($tenant);
        $of = $month->monthOf($date);

        return $month->start($of['year'], $of['month'])->isSameDay($date)
            && ! ($month->isEthiopian() && $of['month'] === 13);
    }

    /** The last day of the latest approved or paid payroll period, or null. */
    private function lastApprovedEnd(Tenant $tenant): ?string
    {
        $end = PayrollRun::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['approved', 'paid'])
            ->max('period_end');

        return $end === null ? null : (string) $end;
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
