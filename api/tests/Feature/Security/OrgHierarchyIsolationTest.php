<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;

/**
 * The isolation a government organisation is promised: tenant A never reads
 * tenant B, department A never reads department B, team A never reads team B.
 * A department reaches down into the units under it and never across.
 *
 * The branch-wide dashboards are part of it: supervisors and department admins
 * held `dashboard.regional`, so a department head opened every other department
 * of their branch through the executive dashboard and its drill-down, staff
 * names and average salary included.
 */

/**
 * Ministry (one branch)
 *  ├─ Directorate A ── Desk A1
 *  └─ Directorate B ── Desk B1
 *
 * @return array<string, mixed>
 */
function governmentOrg(Tenant $tenant): array
{
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $deptA = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'parent_id' => null]);
    $deptA1 = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'parent_id' => $deptA->id]);
    $deptB = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'parent_id' => null]);
    $deptB1 = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'parent_id' => $deptB->id]);

    $person = fn (Department $d, array $extra = []) => Employee::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'department_id' => $d->id,
    ], $extra));

    return [
        'branch' => $branch,
        'deptA' => $deptA,
        'deptB' => $deptB,
        'headA' => $person($deptA),
        'inA' => $person($deptA),
        'inA1' => $person($deptA1),
        'inB' => $person($deptB),
        'inB1' => $person($deptB1),
    ];
}

/** @return list<int> */
function reachableIds(User $user): array
{
    $query = Employee::query();
    $user->scopeAccessibleEmployees($query);

    return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

describe('department', function () {
    it('reaches its own staff and the units under it, never a sibling department', function () {
        $tenant = createTenant();
        $org = governmentOrg($tenant);
        $user = createUser(['role' => UserRole::DEPT_ADMIN, 'employee_id' => $org['headA']->id], $tenant);

        $expected = [$org['headA']->id, $org['inA']->id, $org['inA1']->id];
        sort($expected);
        expect(reachableIds($user))->toBe($expected);

        foreach (['headA', 'inA', 'inA1'] as $key) {
            expect($user->canAccessEmployee($org[$key]))->toBeTrue();
        }
        foreach (['inB', 'inB1'] as $key) {
            expect($user->canAccessEmployee($org[$key]))->toBeFalse();
        }
    });

    it('lists and opens only its own staff over the API', function () {
        $tenant = createTenant();
        $org = governmentOrg($tenant);
        $this->actingAs(createUser(['role' => UserRole::DEPT_ADMIN, 'employee_id' => $org['headA']->id], $tenant));

        $listed = collect($this->getJson('/api/v1/employees?per_page=100')->assertOk()->json('data'))->pluck('public_id');

        expect($listed)->toContain($org['inA']->public_id, $org['inA1']->public_id)
            ->not->toContain($org['inB']->public_id)
            ->not->toContain($org['inB1']->public_id);

        $this->getJson('/api/v1/employees/'.$org['inA1']->public_id)->assertOk();
        expect($this->getJson('/api/v1/employees/'.$org['inB']->public_id)->status())->toBeIn([403, 404]);
    });

    it('stops walking a parent_id cycle instead of looping', function () {
        $tenant = createTenant();
        $x = Department::factory()->create(['tenant_id' => $tenant->id]);
        $y = Department::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => $x->id]);
        $x->update(['parent_id' => $y->id]);
        $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $x->id]);
        $inY = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $y->id]);

        $user = createUser(['role' => UserRole::DEPT_ADMIN, 'employee_id' => $head->id], $tenant);

        expect(reachableIds($user))->toBe([$head->id, $inY->id]);
    });
});

describe('team', function () {
    it('reaches its own team and never another team, even in the same department', function () {
        $tenant = createTenant();
        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
        $teamA = Team::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
        $teamB = Team::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
        $lead = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'team_id' => $teamA->id]);
        $mate = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'team_id' => $teamA->id]);
        $other = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'team_id' => $teamB->id]);

        $user = createScopedUser($tenant, $lead->id, 'team', ['employee.viewAny', 'employee.view']);

        expect(reachableIds($user))->toBe([$lead->id, $mate->id]);
        expect($user->canAccessEmployee($other))->toBeFalse();
    });
});

describe('tenant', function () {
    it('never reaches another tenant, whatever the scope', function () {
        $tenantA = createTenant();
        $tenantB = createTenant();
        $orgA = governmentOrg($tenantA);
        $orgB = governmentOrg($tenantB);

        $this->actingAs(createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $orgA['headA']->id], $tenantA));

        $listed = collect($this->getJson("http://{$tenantA->subdomain}.ethr.test/api/v1/employees?per_page=100")->assertOk()->json('data'))->pluck('public_id');

        expect($listed)->toContain($orgA['inB']->public_id)
            ->not->toContain($orgB['inA']->public_id)
            ->not->toContain($orgB['inB']->public_id);
        expect($this->getJson("http://{$tenantA->subdomain}.ethr.test/api/v1/employees/".$orgB['inA']->public_id)->status())->toBeIn([403, 404]);
    });
});

describe('branch-wide dashboards', function () {
    it('are closed to a department admin and a supervisor', function (UserRole $role) {
        $tenant = createTenant();
        $org = governmentOrg($tenant);
        $this->actingAs(createUser(['role' => $role, 'employee_id' => $org['headA']->id], $tenant));

        $this->getJson('/api/v1/dashboard/executive')->assertForbidden();
        $this->getJson('/api/v1/dashboard/executive/payroll')->assertForbidden();
        $this->getJson('/api/v1/analytics/departments/'.$org['deptB']->public_id)->assertForbidden();
        $this->getJson('/api/v1/dashboard/digests')->assertForbidden();
        $this->getJson('/api/v1/dashboard/alert-thresholds/triggered')->assertForbidden();
    })->with([UserRole::DEPT_ADMIN, UserRole::SUPERVISOR]);

    it('hold an executive whose role reaches one department to nothing', function () {
        $tenant = createTenant();
        $org = governmentOrg($tenant);
        $this->actingAs(createScopedUser($tenant, $org['headA']->id, 'department', ['dashboard.executive', 'dashboard.regional']));

        $this->getJson('/api/v1/dashboard/executive')->assertForbidden();
        $this->getJson('/api/v1/analytics/departments')->assertForbidden();
        $this->getJson('/api/v1/analytics/departments/'.$org['deptB']->public_id)->assertForbidden();
    });

    it('give a branch-scoped role its own branch, and an executive one the whole tenant', function () {
        $tenant = createTenant();
        $org = governmentOrg($tenant);

        $branchScoped = createScopedUser($tenant, $org['headA']->id, 'branch', ['dashboard.regional']);
        $this->actingAs($branchScoped);
        $this->getJson('/api/v1/dashboard/executive')->assertOk();
        $this->getJson('/api/v1/analytics/departments')->assertForbidden();

        // The same person with an executive role instead: one live login each.
        $branchScoped->delete();
        $this->app['auth']->forgetGuards();
        $this->actingAs(createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $org['headA']->id], $tenant));
        $this->getJson('/api/v1/dashboard/executive')->assertOk();
        $this->getJson('/api/v1/analytics/departments')->assertOk();
    });
});
