<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\Position;
use App\Models\Team;
use App\Traits\DispatchesWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    use DispatchesWebhooks;

    public function index(Request $request): AnonymousResourceCollection
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

        $sortField = $request->input('sort', 'name');
        $sortDir = str_starts_with($sortField, '-') ? 'desc' : 'asc';
        $sortField = ltrim($sortField, '-');
        $allowed = ['name', 'employee_code', 'hire_date', 'status', 'created_at'];
        if (in_array($sortField, $allowed, true)) {
            $query->orderBy($sortField, $sortDir);
        }

        return EmployeeResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        Gate::authorize('employee.create');

        $data = $request->validated();
        $data = $this->resolveRelationIds($data);
        $data['status'] ??= EmployeeStatus::HIRED->value;

        $employee = Employee::create($data);
        $employee->load(['department', 'branch', 'position', 'grade', 'team', 'costCenter']);

        AuditLog::record('employee.created', $employee);
        $this->webhook($employee->tenant_id, 'employee.created', ['public_id' => $employee->public_id, 'name' => $employee->name]);

        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('employee.view');

        $employee->load([
            'department', 'branch', 'position', 'grade',
            'team', 'costCenter', 'supervisor',
            'emergencyContacts', 'bankDetails', 'education', 'transitions',
        ]);

        return new EmployeeResource($employee);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        Gate::authorize('employee.update');

        $data = $request->validated();
        $data = $this->resolveRelationIds($data);

        $before = $employee->only(array_keys($data));
        $employee->update($data);

        AuditLog::record('employee.updated', $employee, [
            'before' => $before,
            'after' => $employee->only(array_keys($data)),
        ]);
        $this->webhook($employee->tenant_id, 'employee.updated', ['public_id' => $employee->public_id, 'name' => $employee->name]);

        $employee->load(['department', 'branch', 'position', 'grade', 'team', 'costCenter']);

        return new EmployeeResource($employee);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        Gate::authorize('employee.delete');

        $employee->delete();

        AuditLog::record('employee.deleted', $employee);

        return response()->json(null, 204);
    }

    public function stats(): JsonResponse
    {
        Gate::authorize('employee.viewAny');

        $byStatus = Employee::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byDepartment = Employee::query()
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw('departments.name as department, count(*) as count')
            ->groupBy('departments.name')
            ->pluck('count', 'department');

        $byBranch = Employee::query()
            ->join('branches', 'employees.branch_id', '=', 'branches.id')
            ->selectRaw('branches.name as branch, count(*) as count')
            ->groupBy('branches.name')
            ->pluck('count', 'branch');

        $total = Employee::count();

        return response()->json([
            'total' => $total,
            'by_status' => $byStatus,
            'by_department' => $byDepartment,
            'by_branch' => $byBranch,
        ]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveRelationIds(array $data): array
    {
        $map = [
            'department_id' => Department::class,
            'branch_id' => Branch::class,
            'position_id' => Position::class,
            'grade_id' => Grade::class,
            'team_id' => Team::class,
            'cost_center_id' => CostCenter::class,
            'supervisor_id' => Employee::class,
        ];

        foreach ($map as $field => $modelClass) {
            if (isset($data[$field])) {
                $model = $modelClass::where('public_id', $data[$field])->first();
                $data[$field] = $model?->id;
            }
        }

        return $data;
    }
}
