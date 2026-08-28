<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Shift;
use App\Models\User;

/**
 * Scores how ready a tenant is to go live, as a deterministic rubric over real
 * record counts — the auditable substitute for an "AI validation" (ONBOARDING_V2.md
 * decision D2). Every check is a concrete query; the same tenant state always
 * yields the same score, and each failing check carries a deep link to where it
 * gets fixed.
 */
final class ReadinessScorer
{
    private const LEVEL_READY = 90;

    private const LEVEL_ATTENTION = 60;

    public function score(int $tenantId): array
    {
        $categories = [
            'configuration' => $this->configurationChecks($tenantId),
            'data' => $this->dataChecks($tenantId),
            'security' => $this->securityChecks($tenantId),
        ];

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
    private function configurationChecks(int $tenantId): array
    {
        return [
            $this->check('branch_exists', 'At least one branch', 'configuration', '/organization',
                Branch::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists(), 2),
            $this->check('departments_exist', 'Departments defined', 'configuration', '/organization',
                Department::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists()),
            $this->check('positions_exist', 'Positions defined', 'configuration', '/organization',
                Position::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists()),
            $this->check('shifts_exist', 'Working hours / shifts defined', 'configuration', '/settings/shifts',
                Shift::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists(), 2),
            $this->check('leave_types_exist', 'Leave policies defined', 'configuration', '/settings/leave-types',
                LeaveType::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists()),
            $this->check('holidays_exist', 'Public holidays loaded', 'configuration', '/settings/holidays',
                Holiday::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function dataChecks(int $tenantId): array
    {
        $employees = Employee::withoutGlobalScope('tenant')->where('tenant_id', $tenantId);

        $hasEmployees = (clone $employees)->exists();
        $hasDepartmentAssigned = (clone $employees)->whereNotNull('department_id')->exists();
        $hasAttendanceSource = Device::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->exists();

        return [
            $this->check('has_employees', 'Employees added', 'data', '/employees', $hasEmployees, 3),
            $this->check('employees_have_department', 'Employees assigned to a department', 'data', '/employees',
                $hasDepartmentAssigned),
            $this->check('attendance_source_configured', 'An attendance source (device or method) is set', 'data', '/devices',
                $hasAttendanceSource),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function securityChecks(int $tenantId): array
    {
        $adminRoles = [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value];

        $hasAdmin = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', $adminRoles)
            ->exists();

        $activeAdmins = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', $adminRoles)
            ->where('status', 'active')
            ->exists();

        return [
            $this->check('admin_present', 'A tenant or HR administrator exists', 'security', '/settings/users', $hasAdmin, 3),
            $this->check('active_admin', 'At least one administrator is active', 'security', '/settings/users', $activeAdmins, 2),
        ];
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
