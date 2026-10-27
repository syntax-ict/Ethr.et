<?php

declare(strict_types=1);

namespace App\Services\NextSteps\Providers;

use App\Enums\ContractStatus;
use App\Enums\EmployeeStatus;
use App\Enums\NextStepId;
use App\Enums\PlanFeature;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NextSteps\NextStep;
use App\Services\NextSteps\StepProvider;
use App\Services\PlanFeatureService;
use App\Support\EmployeeGaps;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Employee records that are incomplete, for the people who keep them.
 *
 * Every count is over current staff (hired, probation, confirmed) inside the
 * caller's org scope, so a department administrator sees their department's
 * gaps and nobody else's. A gap is offered only to someone who could fill it:
 * pay data needs the permission that edits pay data, and only an organisation
 * that runs payroll is asked for a TIN or a bank account. The employees list
 * opens the same set through `EmployeeGaps`, so the number and the list agree.
 */
final class RecordsProvider implements StepProvider
{
    private const GAP_STEPS = [
        EmployeeGaps::TIN => NextStepId::RecordsMissingTin,
        EmployeeGaps::BANK => NextStepId::RecordsMissingBank,
        EmployeeGaps::EMERGENCY_CONTACT => NextStepId::RecordsMissingEmergencyContact,
        EmployeeGaps::PHONE => NextStepId::RecordsMissingPhone,
    ];

    public function __construct(private readonly PlanFeatureService $plans) {}

    public function steps(User $user, Tenant $tenant): array
    {
        if (! $user->hasPermission('employee.viewAny')) {
            return [];
        }

        $steps = [];
        $payroll = $this->plans->allows($tenant, PlanFeature::Payroll);

        foreach (self::GAP_STEPS as $gap => $id) {
            if (! $this->mayFill($user, $gap, $payroll)) {
                continue;
            }

            $count = EmployeeGaps::apply($this->people($user), $gap)->count();

            if ($count > 0) {
                $steps[] = new NextStep($id, '/employees?missing='.$gap, (string) $count, $count);
            }
        }

        if ($user->hasPermission('employee.update')) {
            $review = $this->people($user)->where('nationality_needs_review', true)->count();

            if ($review > 0) {
                $steps[] = new NextStep(NextStepId::NationalityReview, '/employees?review=1', (string) $review, $review);
            }
        }

        if ($user->hasPermission('employee.create')) {
            $ending = $this->contractsEnding($user, $tenant);

            if ($ending > 0) {
                $steps[] = new NextStep(NextStepId::ContractsEnding, '/employees/contracts', (string) $ending, $ending);
            }
        }

        return $steps;
    }

    /** @return Builder<Employee> */
    private function people(User $user): Builder
    {
        return $user->scopeAccessibleEmployees(
            Employee::query()->whereIn('status', EmployeeStatus::current())
        );
    }

    private function mayFill(User $user, string $gap, bool $payroll): bool
    {
        if (in_array($gap, EmployeeGaps::financial(), true)) {
            return $payroll && $user->hasPermission('employee.updateFinancial');
        }

        return $user->hasPermission('employee.update');
    }

    /**
     * Active contracts ending within 30 days, and those already past their end
     * date that nobody renewed or ended: the watchlist page's own query.
     */
    private function contractsEnding(User $user, Tenant $tenant): int
    {
        return EmployeeContract::query()
            ->where('status', ContractStatus::ACTIVE)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', TenantTime::today($tenant)->addDays(30))
            ->whereHas('employee', fn ($q) => $user->scopeAccessibleEmployees($q))
            ->count();
    }
}
