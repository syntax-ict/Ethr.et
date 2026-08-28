<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\Position;
use App\Models\Team;
use App\Models\Tenant;
use App\Services\CurrentTenant;

// ──────────────────────────── Branches ────────────────────────────

describe('branches CRUD', function () {
    it('lists branches for authenticated user', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        Branch::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson('/api/v1/organization/branches');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
    });

    it('creates a branch as hr_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/organization/branches', [
            'name' => 'Addis Ababa HQ',
            'name_am' => 'አዲስ አበባ ዋና መ/ቤት',
            'code' => 'AA-HQ',
            'city' => 'Addis Ababa',
            'address' => 'Bole Road',
            'phone' => '+251111234567',
            'latitude' => 9.0192,
            'longitude' => 38.7525,
            'geofence_radius_meters' => 200,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Addis Ababa HQ')
            ->assertJsonPath('code', 'AA-HQ')
            ->assertJsonPath('geofence_radius_meters', 200);

        expect($response->json())->not->toHaveKey('id');
        expect($response->json())->toHaveKey('public_id');

        $this->assertDatabaseHas('branches', ['name' => 'Addis Ababa HQ', 'tenant_id' => $tenant->id]);
    });

    it('shows a single branch', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson("/api/v1/organization/branches/{$branch->public_id}");

        $response->assertOk()
            ->assertJsonPath('public_id', $branch->public_id)
            ->assertJsonPath('name', $branch->name);
    });

    it('updates a branch as hr_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->putJson("/api/v1/organization/branches/{$branch->public_id}", [
            'name' => 'Updated Branch',
            'city' => 'Hawassa',
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'Updated Branch')
            ->assertJsonPath('city', 'Hawassa');
    });

    it('deletes a branch as tenant_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/organization/branches/{$branch->public_id}")
            ->assertNoContent();

        $this->assertSoftDeleted('branches', ['public_id' => $branch->public_id]);
    });

    it('denies create for employee role', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->postJson('/api/v1/organization/branches', [
            'name' => 'Blocked Branch',
        ])->assertForbidden();
    });

    it('denies delete for hr_admin role', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/organization/branches/{$branch->public_id}")
            ->assertForbidden();
    });

    it('searches branches', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Addis Branch', 'code' => 'AB-01']);
        Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Hawassa Branch', 'code' => 'HW-01']);

        $response = $this->getJson('/api/v1/organization/branches?search=Hawassa');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Hawassa Branch');
    });

    it('validates branch creation input', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/organization/branches', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('never exposes numeric id', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson("/api/v1/organization/branches/{$branch->public_id}");

        $response->assertOk()
            ->assertJsonMissingPath('id');
    });
});

// ──────────────────────────── Departments ────────────────────────────

describe('departments CRUD', function () {
    it('lists departments', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        Department::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson('/api/v1/organization/departments');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
    });

    it('creates a department with parent', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $parent = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);

        $response = $this->postJson('/api/v1/organization/departments', [
            'name' => 'Backend Team',
            'code' => 'ENG-BE',
            'parent_public_id' => $parent->public_id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Backend Team')
            ->assertJsonPath('code', 'ENG-BE');

        $this->assertDatabaseHas('departments', [
            'name' => 'Backend Team',
            'parent_id' => $parent->id,
        ]);
    });

    it('creates a department assigned to a branch', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson('/api/v1/organization/departments', [
            'name' => 'Regional Finance',
            'branch_public_id' => $branch->public_id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('departments', [
            'name' => 'Regional Finance',
            'branch_id' => $branch->id,
        ]);
    });

    it('returns department tree', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $root = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Root']);
        $child = Department::factory()->create([
            'tenant_id' => $tenant->id,
            'parent_id' => $root->id,
            'name' => 'Child',
        ]);
        Department::factory()->create([
            'tenant_id' => $tenant->id,
            'parent_id' => $child->id,
            'name' => 'Grandchild',
        ]);

        $response = $this->getJson('/api/v1/organization/tree');

        $response->assertOk();

        $tree = $response->json();
        expect($tree)->toHaveCount(1);
        expect($tree[0]['name'])->toBe('Root');
        expect($tree[0]['children_recursive'])->toHaveCount(1);
        expect($tree[0]['children_recursive'][0]['name'])->toBe('Child');
        expect($tree[0]['children_recursive'][0]['children_recursive'])->toHaveCount(1);
    });

    it('includes employee counts at every level of the tree', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $root = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Root']);
        $child = Department::factory()->create([
            'tenant_id' => $tenant->id,
            'parent_id' => $root->id,
            'name' => 'Child',
        ]);

        Employee::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'department_id' => $root->id,
        ]);
        Employee::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'department_id' => $child->id,
        ]);

        $tree = $this->getJson('/api/v1/organization/tree')->assertOk()->json();

        expect($tree[0]['employees_count'])->toBe(2);
        expect($tree[0]['children_recursive'][0]['employees_count'])->toBe(3);
    });

    it('shows a department with children', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $parent = Department::factory()->create(['tenant_id' => $tenant->id]);
        Department::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'parent_id' => $parent->id,
        ]);

        $response = $this->getJson("/api/v1/organization/departments/{$parent->public_id}");

        $response->assertOk()
            ->assertJsonPath('public_id', $parent->public_id);

        expect($response->json('children'))->toHaveCount(2);
    });

    it('updates a department', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->putJson("/api/v1/organization/departments/{$dept->public_id}", [
            'name' => 'Renamed Department',
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'Renamed Department');
    });

    it('deletes a department as tenant_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/organization/departments/{$dept->public_id}")
            ->assertNoContent();

        $this->assertSoftDeleted('departments', ['public_id' => $dept->public_id]);
    });

    it('filters departments by branch', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);

        Department::factory()->count(2)->create(['tenant_id' => $tenant->id, 'branch_id' => $branchA->id]);
        Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchB->id]);

        $response = $this->getJson("/api/v1/organization/departments?filter[branch_public_id]={$branchA->public_id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
    });
});

// ──────────────────────────── Teams ────────────────────────────

describe('teams CRUD', function () {
    it('lists teams', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        Team::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson('/api/v1/organization/teams');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('creates a team under a department', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson('/api/v1/organization/teams', [
            'name' => 'Alpha Squad',
            'department_public_id' => $dept->public_id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Alpha Squad');

        $this->assertDatabaseHas('teams', [
            'name' => 'Alpha Squad',
            'department_id' => $dept->id,
        ]);
    });

    it('updates a team', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $team = Team::factory()->create(['tenant_id' => $tenant->id]);

        $this->putJson("/api/v1/organization/teams/{$team->public_id}", [
            'name' => 'Beta Squad',
        ])->assertOk()
            ->assertJsonPath('name', 'Beta Squad');
    });

    it('deletes a team as tenant_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $team = Team::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/organization/teams/{$team->public_id}")
            ->assertNoContent();
    });
});

// ──────────────────────────── Positions ────────────────────────────

describe('positions CRUD', function () {
    it('lists positions', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        Position::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson('/api/v1/organization/positions');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
    });

    it('creates a position', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/organization/positions', [
            'title' => 'Senior Developer',
            'title_am' => 'ዋና ገንቢ',
            'code' => 'POS-SD',
            'description' => 'Senior software development role',
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'Senior Developer')
            ->assertJsonPath('code', 'POS-SD');
    });

    it('updates a position', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $this->putJson("/api/v1/organization/positions/{$position->public_id}", [
            'title' => 'Lead Developer',
        ])->assertOk()
            ->assertJsonPath('title', 'Lead Developer');
    });

    it('searches positions', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Accountant']);
        Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Engineer']);

        $response = $this->getJson('/api/v1/organization/positions?search=Engineer');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
    });

    it('deletes a position as tenant_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/organization/positions/{$position->public_id}")
            ->assertNoContent();
    });
});

// ──────────────────────────── Grades ────────────────────────────

describe('grades CRUD', function () {
    it('lists grades ordered by sort_order', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        Grade::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Grade C', 'sort_order' => 3]);
        Grade::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Grade A', 'sort_order' => 1]);
        Grade::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Grade B', 'sort_order' => 2]);

        $response = $this->getJson('/api/v1/organization/grades');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
        expect($response->json('data.0.name'))->toBe('Grade A');
        expect($response->json('data.1.name'))->toBe('Grade B');
        expect($response->json('data.2.name'))->toBe('Grade C');
    });

    it('creates a grade with salary range', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/organization/grades', [
            'name' => 'Grade I',
            'min_salary_cents' => 500000,
            'max_salary_cents' => 1500000,
            'sort_order' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Grade I')
            ->assertJsonPath('min_salary_cents', 500000)
            ->assertJsonPath('max_salary_cents', 1500000);
    });

    it('validates max >= min salary', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/organization/grades', [
            'name' => 'Invalid Grade',
            'min_salary_cents' => 2000000,
            'max_salary_cents' => 500000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['max_salary_cents']);
    });

    it('updates a grade', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $grade = Grade::factory()->create(['tenant_id' => $tenant->id]);

        $this->putJson("/api/v1/organization/grades/{$grade->public_id}", [
            'name' => 'Updated Grade',
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Grade');
    });

    it('deletes a grade as tenant_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $grade = Grade::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/organization/grades/{$grade->public_id}")
            ->assertNoContent();
    });
});

// ──────────────────────────── Cost Centers ────────────────────────────

describe('cost centers CRUD', function () {
    it('lists cost centers', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        CostCenter::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson('/api/v1/organization/cost-centers');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('creates a cost center', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/organization/cost-centers', [
            'name' => 'Research & Development',
            'code' => 'CC-RND',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Research & Development')
            ->assertJsonPath('code', 'CC-RND');
    });

    it('updates a cost center', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $cc = CostCenter::factory()->create(['tenant_id' => $tenant->id]);

        $this->putJson("/api/v1/organization/cost-centers/{$cc->public_id}", [
            'name' => 'Updated CC',
        ])->assertOk()
            ->assertJsonPath('name', 'Updated CC');
    });

    it('deletes a cost center as tenant_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $cc = CostCenter::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/organization/cost-centers/{$cc->public_id}")
            ->assertNoContent();
    });

    it('searches cost centers', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        CostCenter::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Marketing']);
        CostCenter::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Production']);

        $response = $this->getJson('/api/v1/organization/cost-centers?search=Prod');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
    });
});

// ──────────────────────────── Tenant Isolation ────────────────────────────

describe('organization tenant isolation', function () {
    it('only returns branches for the current tenant', function () {
        $tenantA = createTenant();
        Branch::factory()->count(2)->create(['tenant_id' => $tenantA->id]);

        $tenantB = Tenant::factory()->create();
        Branch::factory()->count(3)->create(['tenant_id' => $tenantB->id]);

        actingAsUser(['role' => UserRole::EMPLOYEE], $tenantA);

        $response = $this->getJson('/api/v1/organization/branches');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('returns 404 for a branch from another tenant', function () {
        $tenantA = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenantA);

        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB);
        $branch = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        app(CurrentTenant::class)->set($tenantA);

        $this->getJson("/api/v1/organization/branches/{$branch->public_id}")
            ->assertNotFound();
    });

    it('only returns departments for the current tenant', function () {
        $tenantA = createTenant();
        Department::factory()->count(2)->create(['tenant_id' => $tenantA->id]);

        $tenantB = Tenant::factory()->create();
        Department::factory()->count(5)->create(['tenant_id' => $tenantB->id]);

        actingAsUser(['role' => UserRole::EMPLOYEE], $tenantA);

        $response = $this->getJson('/api/v1/organization/departments');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
    });
});

// ──────────────────────────── Authorization ────────────────────────────

describe('organization authorization', function () {
    it('denies employee from creating a department', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->postJson('/api/v1/organization/departments', [
            'name' => 'Blocked Dept',
        ])->assertForbidden();
    });

    it('denies supervisor from updating a position', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $this->putJson("/api/v1/organization/positions/{$position->public_id}", [
            'title' => 'Changed',
        ])->assertForbidden();
    });

    it('denies hr_admin from deleting a department', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/organization/departments/{$dept->public_id}")
            ->assertForbidden();
    });

    it('allows tenant_admin to perform all operations', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson('/api/v1/organization/branches')->assertOk();
        $this->getJson("/api/v1/organization/branches/{$branch->public_id}")->assertOk();
        $this->postJson('/api/v1/organization/branches', ['name' => 'New Branch'])->assertCreated();
        $this->putJson("/api/v1/organization/branches/{$branch->public_id}", ['name' => 'Updated'])->assertOk();
        $this->deleteJson("/api/v1/organization/branches/{$branch->public_id}")->assertNoContent();
    });

    it('requires authentication for all org endpoints', function () {
        $this->getJson('/api/v1/organization/branches')->assertUnauthorized();
        $this->postJson('/api/v1/organization/branches', ['name' => 'Test'])->assertUnauthorized();
        $this->getJson('/api/v1/organization/departments')->assertUnauthorized();
        $this->getJson('/api/v1/organization/teams')->assertUnauthorized();
        $this->getJson('/api/v1/organization/positions')->assertUnauthorized();
        $this->getJson('/api/v1/organization/grades')->assertUnauthorized();
        $this->getJson('/api/v1/organization/cost-centers')->assertUnauthorized();
        $this->getJson('/api/v1/organization/tree')->assertUnauthorized();
    });
});

// ──────────────────────────── Audit Logging ────────────────────────────

describe('organization audit logging', function () {
    it('logs branch creation', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/organization/branches', ['name' => 'Audit Test Branch']);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'branch.created',
            'tenant_id' => $tenant->id,
        ]);
    });

    it('logs department update', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);

        $this->putJson("/api/v1/organization/departments/{$dept->public_id}", [
            'name' => 'Audit Update',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'department.updated',
            'tenant_id' => $tenant->id,
        ]);
    });

    it('logs grade deletion', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $grade = Grade::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/organization/grades/{$grade->public_id}");

        $this->assertDatabaseHas('audit_log', [
            'action' => 'grade.deleted',
            'tenant_id' => $tenant->id,
        ]);
    });
});

describe('reporting hierarchy', function () {
    it('returns the manager to direct-reports hierarchy', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $ceo = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Chief']);
        $mgr = Employee::factory()->create([
            'tenant_id' => $tenant->id, 'name' => 'Manager', 'supervisor_id' => $ceo->id,
        ]);
        Employee::factory()->create([
            'tenant_id' => $tenant->id, 'name' => 'Contributor', 'supervisor_id' => $mgr->id,
        ]);

        $tree = $this->getJson('/api/v1/organization/reporting-tree')->assertOk()->json();

        // Assert by name rather than root count: an acting user may itself be an
        // unmanaged employee and appear as an extra root.
        $chief = collect($tree)->firstWhere('name', 'Chief');
        expect($chief)->not->toBeNull();
        expect($chief['direct_reports'])->toHaveCount(1);
        expect($chief['direct_reports'][0]['name'])->toBe('Manager');
        expect($chief['direct_reports'][0]['direct_reports'][0]['name'])->toBe('Contributor');
    });

    it('never leaks a numeric id', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson('/api/v1/organization/reporting-tree')
            ->assertOk()
            ->assertJsonMissingPath('0.id');
    });

    it('surfaces reports of a soft-deleted supervisor as roots', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $mgr = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Departing Manager']);
        Employee::factory()->create([
            'tenant_id' => $tenant->id, 'name' => 'Orphaned Report', 'supervisor_id' => $mgr->id,
        ]);

        $mgr->delete(); // soft delete

        $tree = $this->getJson('/api/v1/organization/reporting-tree')->assertOk()->json();
        $names = collect($tree)->pluck('name');

        // The report must not vanish just because its manager was terminated.
        expect($names)->toContain('Orphaned Report');
        expect($names)->not->toContain('Departing Manager');
    });

    it('requires authentication', function () {
        $this->getJson('/api/v1/organization/reporting-tree')->assertUnauthorized();
    });
});
