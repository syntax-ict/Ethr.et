<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\EmployeeStatus;
use App\Exceptions\InvalidEmployeeTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\BulkUpdateRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\EmployeeTransitionService;
use App\Support\Csv;
use App\Support\EmployeeGaps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class EmployeeBulkController extends Controller
{
    public function __construct(private readonly EmployeeTransitionService $transitions) {}

    /**
     * Move employees to a department or branch, or change their status.
     *
     * The selection is all or nothing: an id outside the requester's org scope
     * refuses the whole request with 422 before anything is written, with the
     * message an id that exists nowhere gets. The changes are per employee: a
     * status change goes through the same transition rules as
     * `POST /employees/{employee}/transition`, and `results` reports each id as
     * `updated`, `unchanged` (already in that status) or `failed`, with the
     * reason. One refused transition does not stop the others.
     */
    public function bulkUpdate(BulkUpdateRequest $request): JsonResponse
    {
        Gate::authorize('employee.update');

        $toStatus = $request->filled('status')
            ? EmployeeStatus::from($request->validated('status'))
            : null;

        // A status change is a transition, and the single transition endpoint
        // asks for employee.transition; holding employee.update is not enough.
        if ($toStatus !== null) {
            Gate::authorize('employee.transition');
        }

        $moves = [];
        $department = null;
        $branch = null;

        if ($request->has('department_id')) {
            $department = Department::where('public_id', $request->validated('department_id'))->first();
            if ($department) {
                $moves['department_id'] = $department->id;
            }
        }

        if ($request->has('branch_id')) {
            $branch = Branch::where('public_id', $request->validated('branch_id'))->first();
            if ($branch) {
                $moves['branch_id'] = $branch->id;
            }
        }

        if ($moves === [] && $toStatus === null) {
            return response()->json([
                'type' => 'https://ethr.et/errors/no-update-fields',
                'title' => 'No Update Fields',
                'status' => 422,
                'detail' => 'At least one field to update must be provided.',
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        /** @var list<string> $publicIds */
        $publicIds = array_values(array_unique($request->validated('employee_ids')));

        // The same org scope as the employee list and the export. Without it a
        // manager scoped to one department, holding employee.update, changed
        // anyone in the tenant.
        $query = Employee::query()->with(['department', 'branch'])->whereIn('public_id', $publicIds);
        $request->user()->scopeAccessibleEmployees($query);
        $employees = $query->get()->keyBy('public_id');

        $request->refuseEmployeesOutOfReach($employees->keys()->all());

        $effectiveDate = $request->validated('effective_date') ?? EmployeeTransitionService::today();
        $reason = $request->validated('reason');
        $results = [];

        foreach ($publicIds as $publicId) {
            $results[] = $this->updateOne($employees->get($publicId), $moves, $department, $branch, $toStatus, $effectiveDate, $reason);
        }

        $updated = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'updated'));
        $failed = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'failed'));

        return response()->json([
            'updated' => $updated,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * One employee's share of a bulk update, in its own transaction, so a
     * refused transition rolls back that employee's move as well and leaves
     * the others alone.
     *
     * @param  array<string, int>  $moves
     * @return array{employee_id: string, outcome: 'updated'|'unchanged'|'failed', error: string|null}
     */
    private function updateOne(
        Employee $employee,
        array $moves,
        ?Department $department,
        ?Branch $branch,
        ?EmployeeStatus $toStatus,
        string $effectiveDate,
        ?string $reason,
    ): array {
        $changesStatus = $toStatus !== null && $employee->status !== $toStatus;

        if ($moves === [] && ! $changesStatus) {
            return $this->result($employee, 'unchanged');
        }

        $before = $this->auditedFields($employee, $moves, $toStatus);

        try {
            DB::transaction(function () use ($employee, $moves, $department, $branch, $toStatus, $changesStatus, $effectiveDate, $reason, $before): void {
                if ($moves !== []) {
                    $employee->fill($moves)->save();

                    if ($department !== null) {
                        $employee->setRelation('department', $department);
                    }

                    if ($branch !== null) {
                        $employee->setRelation('branch', $branch);
                    }
                }

                if ($changesStatus) {
                    $this->transitions->transition($employee, $toStatus, $effectiveDate, $reason, auth()->id());
                }

                AuditLog::record('employee.bulk_updated', $employee, [
                    'before' => $before,
                    'after' => $this->auditedFields($employee, $moves, $toStatus),
                ]);
            });
        } catch (InvalidEmployeeTransition $e) {
            return $this->result($employee, 'failed', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->result($employee, 'failed', (string) __('employee.bulk_update_failed'));
        }

        return $this->result($employee, 'updated');
    }

    /**
     * One entry of `results`. Built in one place so the published contract
     * reads `error` as a string, not as the English text of one message.
     *
     * @param  'updated'|'unchanged'|'failed'  $outcome
     * @return array{employee_id: string, outcome: 'updated'|'unchanged'|'failed', error: string|null}
     */
    private function result(Employee $employee, string $outcome, ?string $error = null): array
    {
        return ['employee_id' => $employee->public_id, 'outcome' => $outcome, 'error' => $error];
    }

    /**
     * The fields a bulk update touches, by public id and enum value, so the
     * audit entry reads as what changed without joining back to internal keys.
     *
     * @param  array<string, int>  $moves
     * @return array<string, string|null>
     */
    private function auditedFields(Employee $employee, array $moves, ?EmployeeStatus $toStatus): array
    {
        $fields = [];

        if (array_key_exists('department_id', $moves)) {
            $fields['department_id'] = $employee->department?->public_id;
        }

        if (array_key_exists('branch_id', $moves)) {
            $fields['branch_id'] = $employee->branch?->public_id;
        }

        if ($toStatus !== null) {
            $fields['status'] = $employee->status->value;
        }

        return $fields;
    }

    public function export(Request $request): JsonResponse
    {
        Gate::authorize('employee.viewAny');

        $query = Employee::query()
            ->with(['department', 'branch', 'position']);

        // The same org scope as the employee list. Without it a supervisor —
        // who holds employee.viewAny to see their team — downloaded every
        // employee's name, e-mail and phone in the tenant.
        $request->user()->scopeAccessibleEmployees($query);

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        // The list's own filters and sort (EmployeeController::index), so the
        // file holds what the screen showed: the export read only search,
        // status and department, and the page sent only search.
        if ($request->boolean('filter.nationality_needs_review')) {
            $query->where('nationality_needs_review', true);
        }

        EmployeeGaps::restrict($query, $request);

        if ($request->has('filter.department_id')) {
            $dept = Department::where('public_id', $request->input('filter.department_id'))->first();
            if ($dept) {
                $query->where('department_id', $dept->id);
            }
        }

        if ($request->has('filter.branch_id')) {
            $branch = Branch::where('public_id', $request->input('filter.branch_id'))->first();
            if ($branch) {
                $query->where('branch_id', $branch->id);
            }
        }

        if ($request->has('filter.position_id')) {
            $position = Position::where('public_id', $request->input('filter.position_id'))->first();
            if ($position) {
                $query->where('position_id', $position->id);
            }
        }

        $sortField = (string) $request->input('sort', 'name');
        $sortDir = str_starts_with($sortField, '-') ? 'desc' : 'asc';
        $sortField = ltrim($sortField, '-');
        if (in_array($sortField, ['name', 'employee_code', 'hire_date', 'status', 'created_at'], true)) {
            $query->orderBy($sortField, $sortDir);
        }

        $employees = $query->get();

        // The import's own column names, so an exported file imports as it
        // stands; department, branch and position by name, which the import
        // reads as readily as a code. Salary, national ID and TIN stay out:
        // this is a viewAny export, and a supervisor can run it.
        $headers = [
            'name', 'name_am', 'employee_code', 'email', 'phone', 'gender', 'date_of_birth',
            'hire_date', 'department', 'branch', 'position', 'badge_number',
            'nationality', 'nationality_status', 'marital_status', 'status',
        ];
        $rows = [];

        foreach ($employees as $emp) {
            $rows[] = [
                $emp->name,
                $emp->name_am,
                $emp->employee_code,
                $emp->email,
                $emp->phone,
                $emp->gender,
                $emp->date_of_birth?->format('Y-m-d'),
                $emp->hire_date?->format('Y-m-d'),
                $emp->department?->name,
                $emp->branch?->name,
                $emp->position?->title,
                $emp->badge_number,
                $emp->nationality,
                $emp->nationality_status?->value,
                $emp->marital_status,
                $emp->status?->value,
            ];
        }

        // Through Csv::row, like every other export: a name such as
        // =HYPERLINK(...) is neutralised instead of executing when HR opens it.
        $csv = Csv::row($headers)."\n";
        foreach ($rows as $row) {
            $csv .= Csv::row($row)."\n";
        }

        return response()->json([
            'csv' => $csv,
            'count' => count($rows),
        ]);
    }
}
