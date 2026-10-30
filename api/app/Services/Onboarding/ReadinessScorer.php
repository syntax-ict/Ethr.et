<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\EmployeeStatus;
use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Features\FeatureGate;
use App\Services\Payroll\StatutorySchedules;
use App\Support\EmployeeGaps;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scores how ready a tenant is to go live, as a deterministic rubric over real
 * record counts — the auditable substitute for an "AI validation" (ONBOARDING_V2.md
 * decision D2). Every check is a concrete query; the same tenant state always
 * yields the same score, and each failing check carries a deep link to where it
 * gets fixed.
 *
 * Sign-up creates shifts, leave types and an administrator by itself, so a
 * check that only asks whether one such record exists passes before anyone
 * has set anything up. The checks about people therefore ask about all of
 * them (every current employee has a department, a salary, a TIN), the
 * organisation's own choices are asked about directly (the setup applied, an
 * employer TIN), and payroll is scored whenever the plan includes it, since
 * it is the costliest thing to go live without (onboarding review 2026-10-30).
 */
final class ReadinessScorer
{
    private const LEVEL_READY = 90;

    private const LEVEL_ATTENTION = 60;

    public function __construct(private readonly FeatureGate $features) {}

    public function score(int $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);

        $categories = [
            'configuration' => $this->configurationChecks($tenantId, $tenant),
            'data' => $this->dataChecks($tenantId),
        ];

        if ($tenant instanceof Tenant && $this->features->allows($tenant, Feature::Payroll)) {
            $categories['payroll'] = $this->payrollChecks($tenantId, $tenant);
        }

        $categories['security'] = $this->securityChecks($tenantId);

        $allChecks = array_merge(...array_values($categories));

        $categoryScores = [];
        foreach ($categories as $name => $checks) {
            $categoryScores[$name] = [
                'score' => $this->percentage($checks),
                'checks' => array_map($this->presentCheck(...), $checks),
            ];
        }

        $overall = $this->percentage($allChecks);

        $gaps = array_values(array_map($this->presentCheck(...), array_filter(
            $allChecks,
            static fn (array $c): bool => ! $c['passed'],
        )));

        return [
            'overall_score' => $overall,
            'level' => $this->level($overall),
            'categories' => $categoryScores,
            'gaps' => $gaps,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function configurationChecks(int $tenantId, ?Tenant $tenant): array
    {
        // Read as TenantSecurityPolicy reads it: the cast makes it an array,
        // which the model's schema-derived PHPDoc does not know.
        $settings = $tenant?->getAttribute('settings');
        $applied = is_array($settings) && ! empty($settings['configuration_applied_at']);

        return [
            $this->check('configuration_applied', 'Organisation setup reviewed and applied', 'configuration', '/setup/guided',
                $applied, 2),
            $this->check('branch_exists', 'At least one branch', 'configuration', '/organization',
                Branch::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists(), 2),
            $this->check('departments_exist', 'Departments defined', 'configuration', '/organization',
                Department::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists()),
            $this->check('positions_exist', 'Positions defined', 'configuration', '/organization',
                Position::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists()),
            $this->check('shifts_exist', 'Working hours / shifts defined', 'configuration', '/settings/shifts',
                Shift::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists()),
            $this->check('leave_types_exist', 'Leave policies defined', 'configuration', '/settings/leave-types',
                LeaveType::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists()),
            $this->check('holidays_exist', 'Public holidays loaded', 'configuration', '/settings/holidays',
                Holiday::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function dataChecks(int $tenantId): array
    {
        $hasEmployees = $this->currentEmployees($tenantId)->exists();
        // A device, or any way to punch without one. Until 2026-10-24 only a
        // device counted, so an organisation punching by phone, QR or kiosk
        // could never pass a check whose label says "device or method".
        $settings = AttendanceSetting::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->first();
        $methods = $settings instanceof AttendanceSetting
            ? (array) $settings->enabled_methods
            : AttendanceSetting::defaults()['enabled_methods'];
        $selfService = array_intersect(['mobile', 'web', 'qr', 'kiosk'], $methods);
        $hasAttendanceSource = $selfService !== []
            || Device::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists();

        return [
            $this->check('has_employees', 'Employees added', 'data', '/employees', $hasEmployees, 3),
            $this->check('employees_have_department', 'Every employee is in a department', 'data', '/employees',
                $this->everyone($tenantId, fn (Builder $missing) => $missing->whereNull('department_id'))),
            $this->check('attendance_source_configured', 'An attendance source (device or method) is set', 'data', '/devices',
                $hasAttendanceSource),
        ];
    }

    /**
     * What a first payroll run needs: the employer's TIN on the statutory
     * returns, and a salary, a TIN and a bank account for every current
     * employee. A salary is the employee's own or one a contract states, as
     * PayrollEngine pays it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function payrollChecks(int $tenantId, Tenant $tenant): array
    {
        return [
            $this->check('employer_tin_set', 'Employer TIN recorded', 'payroll', '/settings/payroll',
                StatutorySchedules::setting($tenant, 'employer_tin') !== null, 2),
            $this->check('employees_have_salary', 'Every employee has a salary', 'payroll', '/employees',
                $this->everyone($tenantId, fn (Builder $missing) => $missing
                    ->where(fn (Builder $own) => $own->whereNull('salary_cents')->orWhere('salary_cents', '<=', 0))
                    ->whereDoesntHave('contracts', fn (Builder $contract) => $contract
                        ->withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenantId)
                        ->where('salary_cents', '>', 0))), 2),
            $this->check('employees_have_tin', 'Every employee has a TIN', 'payroll', '/employees?missing='.EmployeeGaps::TIN,
                $this->everyone($tenantId, fn (Builder $missing) => EmployeeGaps::apply($missing, EmployeeGaps::TIN))),
            $this->check('employees_have_bank', 'Every employee has a bank account', 'payroll', '/employees?missing='.EmployeeGaps::BANK,
                $this->everyone($tenantId, fn (Builder $missing) => EmployeeGaps::apply($missing, EmployeeGaps::BANK))),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function securityChecks(int $tenantId): array
    {
        $adminRoles = [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value];

        $activeAdmins = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', $adminRoles)
            ->where('status', 'active');

        $hasActiveAdmin = (clone $activeAdmins)->exists();

        return [
            $this->check('active_admin', 'At least one administrator is active', 'security', '/settings/users', $hasActiveAdmin),
            $this->check('admins_use_mfa', 'Every administrator uses two-step sign-in', 'security', '/settings/users',
                $hasActiveAdmin && ! (clone $activeAdmins)->where('mfa_enabled', false)->exists(), 2),
        ];
    }

    /**
     * Current staff (hired, probation, confirmed), the people the setup is for.
     *
     * @return Builder<Employee>
     */
    private function currentEmployees(int $tenantId): Builder
    {
        return Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', EmployeeStatus::current());
    }

    /**
     * Whether there are current employees and none of them is missing what
     * `$missing` narrows to. With nobody yet, nobody can be said to have it.
     *
     * @param  callable(Builder<Employee>): mixed  $missing
     */
    private function everyone(int $tenantId, callable $missing): bool
    {
        if (! $this->currentEmployees($tenantId)->exists()) {
            return false;
        }

        $query = $this->currentEmployees($tenantId);
        $missing($query);

        return ! $query->exists();
    }

    /**
     * @return array{id: string, label: string, category: string, remediation: string, passed: bool, weight: int}
     */
    private function check(string $id, string $label, string $category, string $remediation, bool $passed, int $weight = 1): array
    {
        return compact('id', 'label', 'category', 'remediation', 'passed', 'weight');
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function presentCheck(array $check): array
    {
        return [
            'id' => $check['id'],
            'label' => $check['label'],
            'category' => $check['category'],
            'passed' => $check['passed'],
            'remediation' => $check['remediation'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function percentage(array $checks): int
    {
        $total = array_sum(array_column($checks, 'weight'));

        if ($total === 0) {
            return 100;
        }

        $earned = array_sum(array_map(
            static fn (array $c): int => $c['passed'] ? (int) $c['weight'] : 0,
            $checks,
        ));

        return (int) round($earned / $total * 100);
    }

    private function level(int $score): string
    {
        return match (true) {
            $score >= self::LEVEL_READY => 'ready',
            $score >= self::LEVEL_ATTENTION => 'needs_attention',
            default => 'not_ready',
        };
    }
}
