<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeReportingNodeResource;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\Position;
use App\Models\Team;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\PlanLimitService;
use App\Services\UserProvisioningService;
use App\Support\EthiopianPhone;
use App\Traits\DispatchesWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    use DispatchesWebhooks;

    /**
     * The org reporting hierarchy: every employee with no active supervisor,
     * each nesting its full chain of direct reports. Tenant isolation is
     * automatic via the model's global scope; reports of a soft-deleted
     * supervisor surface as their own roots rather than vanishing.
     */
    public function reportingTree(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $roots = Employee::query()
            ->where(function ($query) {
                // A root is anyone with no *active* supervisor: either no
                // supervisor set, or one that has been soft-deleted (the
                // belongsTo respects the global scope, so `doesntHave` is true).
                // Without the second clause, terminating a manager would drop
                // their whole team off the chart until each report was reassigned.
                $query->whereNull('supervisor_id')
                    ->orWhereDoesntHave('supervisor');
            })
            ->with(['position', 'directReportsRecursive'])
            ->orderBy('name')
            ->get();

        return EmployeeReportingNodeResource::collection($roots);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $query = Employee::query()
            ->with(['department', 'branch', 'position']);

        $request->user()->scopeAccessibleEmployees($query);

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

    public function store(StoreEmployeeRequest $request, UserProvisioningService $provisioning, PlanLimitService $planLimits): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $planLimits->assertCanAdd(app(CurrentTenant::class)->get(), 'employees');

        $data = $request->validated();

        // Login-provisioning inputs are not Employee attributes — pull them out
        // before mass-assigning the model.
        $createLogin = (bool) ($data['create_login'] ?? false);
        $requestedRole = $data['user_role'] ?? null;
        unset($data['create_login'], $data['user_role']);

        $data = $this->resolveRelationIds($data);
        $data['status'] ??= EmployeeStatus::HIRED->value;

        // Store in one canonical shape (+251…) regardless of whether the form
        // submitted the local 09… or E.164 form — this is the primary,
        // highest-traffic path for adding an employee's phone number, so
        // leaving it uncanonicalized here undermined the same fix already
        // applied to import/migration/SCIM/SSO/profile-self-service.
        if (isset($data['phone'])) {
            $data['phone'] = EthiopianPhone::canonicalOrRaw($data['phone']);
        }

        $employee = Employee::create($data);

        $loginInvited = false;
        if ($createLogin && ! empty($employee->email)) {
            $loginInvited = $provisioning->provision(
                email: $employee->email,
                role: $this->resolveAssignableRole($request->user(), $requestedRole),
                employee: $employee,
                invitedBy: $request->user()->id,
            ) !== null;
        }

        $employee->load(['department', 'branch', 'position', 'grade', 'team', 'costCenter']);

        AuditLog::record('employee.created', $employee, ['login_invited' => $loginInvited]);
        $this->webhook($employee->tenant_id, 'employee.created', ['public_id' => $employee->public_id, 'name' => $employee->name]);

        // Resources render flat (withoutWrapping). Merge the provisioning result
        // as a top-level `meta` key without re-introducing a `data` wrapper.
        $response = (new EmployeeResource($employee))->response()->setStatusCode(201);
        $payload = $response->getData(true);
        $payload['meta'] = ['login_invited' => $loginInvited];
        $response->setData($payload);

        return $response;
    }

    /**
     * Resolve the requested login role, clamped so a creator can never grant a
     * role above their own level. Defaults to the base employee role.
     */
    private function resolveAssignableRole(User $actor, ?string $requested): UserRole
    {
        $role = $requested !== null ? UserRole::tryFrom($requested) : null;
        $role ??= UserRole::EMPLOYEE;

        if ($role === UserRole::SUPER_ADMIN) {
            return UserRole::EMPLOYEE;
        }

        if (! $actor->isSuperAdmin() && $role->level() > $actor->role->level()) {
            return UserRole::EMPLOYEE;
        }

        return $role;
    }

    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        $employee->load([
            'department', 'branch', 'position', 'grade',
            'team', 'costCenter', 'supervisor',
            'emergencyContacts', 'bankDetails', 'education', 'transitions',
        ]);

        return new EmployeeResource($employee);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);

        $data = $request->validated();
        $data = $this->resolveRelationIds($data);

        if (isset($data['phone'])) {
            $data['phone'] = EthiopianPhone::canonicalOrRaw($data['phone']);
        }

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
        $this->authorize('delete', $employee);

        $employee->delete();

        AuditLog::record('employee.deleted', $employee);

        return response()->json(null, 204);
    }

    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $baseQuery = Employee::query();
        $request->user()->scopeAccessibleEmployees($baseQuery);

        $byStatus = (clone $baseQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byDepartment = (clone $baseQuery)
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw('departments.name as department, count(*) as count')
            ->groupBy('departments.name')
            ->pluck('count', 'department');

        $byBranch = (clone $baseQuery)
            ->join('branches', 'employees.branch_id', '=', 'branches.id')
            ->selectRaw('branches.name as branch, count(*) as count')
            ->groupBy('branches.name')
            ->pluck('count', 'branch');

        $total = (clone $baseQuery)->count();

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
