<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\BulkUpdateRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
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

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->has('filter.department_id')) {
            $dept = Department::where('public_id', $request->input('filter.department_id'))->first();
            if ($dept) {
                $query->where('department_id', $dept->id);
            }
        }

        $employees = $query->get();

        $headers = ['name', 'email', 'phone', 'employee_code', 'gender', 'status', 'hire_date', 'department', 'branch', 'position'];
        $rows = [];

        foreach ($employees as $emp) {
            $rows[] = [
                $emp->name,
                $emp->email,
                $emp->phone,
                $emp->employee_code,
                $emp->gender,
                $emp->status?->value,
                $emp->hire_date?->format('Y-m-d'),
                $emp->department?->name,
                $emp->branch?->name,
                $emp->position?->title ?? $emp->position?->name ?? null,
            ];
        }

        $csv = implode(',', $headers)."\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) ($v ?? '')).'"', $row))."\n";
        }

        return response()->json([
            'csv' => $csv,
            'count' => count($rows),
        ]);
    }
}
