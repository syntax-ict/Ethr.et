<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\BulkUpdateRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeBulkController extends Controller
{
    public function bulkUpdate(BulkUpdateRequest $request): JsonResponse
    {
        Gate::authorize('employee.update');

        $data = [];

        if ($request->has('department_id')) {
            $dept = Department::where('public_id', $request->validated('department_id'))->first();
            if ($dept) {
                $data['department_id'] = $dept->id;
            }
        }

        if ($request->has('branch_id')) {
            $branch = Branch::where('public_id', $request->validated('branch_id'))->first();
            if ($branch) {
                $data['branch_id'] = $branch->id;
            }
        }

        if ($request->has('status')) {
            $data['status'] = $request->validated('status');
        }

        if (empty($data)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/no-update-fields',
                'title' => 'No Update Fields',
                'status' => 422,
                'detail' => 'At least one field to update must be provided.',
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $publicIds = $request->validated('employee_ids');
        $updated = Employee::whereIn('public_id', $publicIds)->update($data);

        AuditLog::record('employee.bulk_updated', null, [
            'count' => $updated,
            'fields' => array_keys($data),
        ]);

        return response()->json(['updated' => $updated]);
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
