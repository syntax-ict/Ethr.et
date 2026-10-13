<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization\Concerns;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

/**
 * Departments, branches and positions soft-delete, and an employee row keeps
 * pointing at a deleted one — so deleting a unit people still work in left
 * them with no department, branch or position on every screen, while the
 * record said otherwise. Refused while anyone current (EmployeeStatus::current)
 * is assigned; people who have left do not block, because their records keep
 * the reference and the soft-deleted unit still explains it.
 */
trait RefusesDeletingStaffedUnit
{
    /**
     * @param  'department_id'|'branch_id'|'position_id'  $column
     */
    protected function refuseIfStaffed(string $column, int $unitId, string $unit): ?JsonResponse
    {
        $people = Employee::query()
            ->where($column, $unitId)
            ->whereIn('status', array_map(static fn (EmployeeStatus $status): string => $status->value, EmployeeStatus::current()))
            ->count();

        if ($people === 0) {
            return null;
        }

        $who = $people === 1 ? '1 current employee is' : "{$people} current employees are";

        return response()->json([
            'type' => 'https://ethr.et/errors/org-unit-in-use',
            'title' => 'In Use',
            'status' => 409,
            'detail' => "This {$unit} cannot be deleted: {$who} assigned to it. Move them first, or mark it inactive.",
        ], 409)->header('Content-Type', 'application/problem+json');
    }
}
