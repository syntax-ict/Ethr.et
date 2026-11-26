<?php

declare(strict_types=1);

use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\PositionAccess;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Bulk position, supervisor, team and cost centre. Each person's change goes
 * through EmployeeUpdater, the class the edit form's PUT runs, so what the form
 * refuses or does, the bulk change refuses or does for that person alone.
 */

const JOB_NO_SUCH_ID = '01JNOSUCHPUBLICIDXXXXXXXXX';

dataset('job fields', [
    'position' => ['position_id', Position::class, 'position_id'],
    'team' => ['team_id', Team::class, 'team_id'],
    'cost centre' => ['cost_center_id', CostCenter::class, 'cost_center_id'],
    'supervisor' => ['supervisor_id', Employee::class, 'supervisor_id'],
]);

/**
 * @param  list<string>  $abilities
 * @return array{0: Department, 1: Department}
 */
function jobScopedManager(Tenant $tenant, array $abilities): array
{
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);

    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Department manager', 'org_scope' => OrgScope::DEPARTMENT->value]);
    $role->permissions()->sync(Permission::whereIn('name', $abilities)->pluck('id'));

    actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id, 'employee_id' => $head->id], $tenant);

    return [$mine, $theirs];
}

function jobTitle(Tenant $tenant, ?string $access = null): Position
{
    return Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'T'.uniqid(), 'access_role' => $access]);
}

function jobHolder(Tenant $tenant, ?Position $position = null): User
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'position_id' => $position?->id]);

    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'role' => UserRole::EMPLOYEE,
        'role_follows_position' => true,
    ]);
}

describe('each job field', function () {
    it('is set for everyone selected, through the same audit trail as an edit', function (string $field, string $model, string $column) {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $people = Employee::factory()->count(2)->create(['tenant_id' => $tenant->id]);
        $target = $model::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson('/api/v1/employees/bulk-update', [
            'employee_ids' => $people->pluck('public_id')->all(),
            $field => $target->public_id,
        ])->assertOk()->assertJsonPath('updated', 2)->assertJsonPath('failed', 0)
            ->assertJsonPath('results.0.outcome', 'updated')->assertJsonPath('results.0.access_held', false);

        foreach ($people as $person) {
            expect($person->fresh()->{$column})->toBe($target->id);
            $edit = AuditLog::query()->where('action', 'employee.updated')->where('auditable_id', $person->id)->latest('id')->first();
            $bulk = AuditLog::query()->where('action', 'employee.bulk_updated')->where('auditable_id', $person->id)->latest('id')->first();
            expect($edit)->not->toBeNull()
                ->and($bulk->payload['after'][$field])->toBe($target->public_id)
                ->and($bulk->payload['before'])->toHaveKey($field);
        }
    })->with('job fields');

    it('reports a person who already has it as unchanged and writes nothing', function (string $field, string $model, string $column) {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $target = $model::factory()->create(['tenant_id' => $tenant->id]);
        $person = Employee::factory()->create(['tenant_id' => $tenant->id, $column => $target->id]);

        $this->postJson('/api/v1/employees/bulk-update', [
            'employee_ids' => [$person->public_id],
            $field => $target->public_id,
        ])->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('results.0.outcome', 'unchanged');

        expect(AuditLog::query()->where('action', 'employee.bulk_updated')->count())->toBe(0);
    })->with('job fields');

    it('refuses another organisation\'s record with the message a missing id gets', function (string $field, string $model) {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $person = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $foreign = $model::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $missing = $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$person->public_id], $field => JOB_NO_SUCH_ID])
            ->assertUnprocessable()->json("errors.{$field}");
        $crossTenant = $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$person->public_id], $field => $foreign->public_id])
            ->assertUnprocessable()->json("errors.{$field}");

        expect($crossTenant)->toBe($missing);
    })->with('job fields');

    it('refuses someone outside the requester\'s reach, and changes no one', function (string $field, string $model, string $column) {
        $tenant = createTenant();
        [$mine, $theirs] = jobScopedManager($tenant, ['employee.update', 'employee.viewAny']);
        $inReach = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);
        $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id]);
        $target = $model::factory()->create(['tenant_id' => $tenant->id]);

        $missing = $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$inReach->public_id, JOB_NO_SUCH_ID], $field => $target->public_id])
            ->assertUnprocessable()->json('errors')['employee_ids.1'];
        $refused = $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$inReach->public_id, $outside->public_id], $field => $target->public_id])
            ->assertUnprocessable()->json('errors')['employee_ids.1'];

        expect($refused)->toBe($missing)
            ->and($inReach->fresh()->{$column})->toBeNull()
            ->and($outside->fresh()->{$column})->toBeNull();
    })->with('job fields');

    it('is refused to someone who may not edit employees', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
        $person = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$person->public_id], 'team_id' => Team::factory()->create(['tenant_id' => $tenant->id])->public_id])
            ->assertForbidden();
    });

    it('refuses an archived job title or supervisor like one that does not exist', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $person = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $title = jobTitle($tenant);
        $title->delete();
        $boss = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $boss->delete();

        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$person->public_id], 'position_id' => $title->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors(['position_id']);
        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$person->public_id], 'supervisor_id' => $boss->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors(['supervisor_id']);
    });

    it('leaves everything else on the record as it was', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $person = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'address_region_text' => 'Oromia',
            'address_kebele' => '07',
            'outside_employment_framework' => true,
            'employment_framework_note' => 'Seconded by the ministry',
            'salary_cents' => 1250000,
        ]);
        $team = Team::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$person->public_id], 'team_id' => $team->public_id])
            ->assertOk()->assertJsonPath('updated', 1);

        $after = $person->fresh();
        expect($after->team_id)->toBe($team->id)
            ->and($after->address_region_text)->toBe('Oromia')
            ->and($after->address_kebele)->toBe('07')
            ->and($after->outside_employment_framework)->toBeTrue()
            ->and($after->employment_framework_note)->toBe('Seconded by the ministry')
            ->and($after->salary_cents)->toBe(1250000);
    });

    it('can go with a status change in one request', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $person = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => 'hired']);
        $title = jobTitle($tenant);

        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$person->public_id], 'position_id' => $title->public_id, 'status' => 'probation'])
            ->assertOk()->assertJsonPath('updated', 1);

        expect($person->fresh()->position_id)->toBe($title->id)->and($person->fresh()->status->value)->toBe('probation');
    });
});

describe('the supervisor', function () {
    it('is never the person themselves, and the others are still done', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $boss = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $other = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson('/api/v1/employees/bulk-update', [
            'employee_ids' => [$boss->public_id, $other->public_id],
            'supervisor_id' => $boss->public_id,
        ])->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('failed', 1)
            ->assertJsonPath('results.0.employee_id', $boss->public_id)
            ->assertJsonPath('results.0.outcome', 'failed')
            ->assertJsonPath('results.0.error', __('employee.supervisor_self'))
            ->assertJsonPath('results.1.outcome', 'updated');

        expect($boss->fresh()->supervisor_id)->toBeNull()->and($other->fresh()->supervisor_id)->toBe($boss->id);
    });

    it('never makes a loop, directly or further up, and says what the edit form says', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $top = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $middle = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $top->id]);
        $bottom = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $middle->id]);

        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$top->public_id], 'supervisor_id' => $bottom->public_id])
            ->assertOk()->assertJsonPath('failed', 1)->assertJsonPath('results.0.error', __('employee.supervisor_loop'));
        expect($top->fresh()->supervisor_id)->toBeNull();

        $this->putJson('/api/v1/employees/'.$top->public_id, ['supervisor_id' => $bottom->public_id])
            ->assertUnprocessable()->assertJsonPath('errors.supervisor_id.0', __('employee.supervisor_loop'));
    });
});

describe('a job title and what it gives the login', function () {
    it('moves the login when the requester could have by hand', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $holder = jobHolder($tenant);
        $title = jobTitle($tenant, 'dept_admin');

        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$holder->employee->public_id], 'position_id' => $title->public_id])
            ->assertOk()->assertJsonPath('results.0.outcome', 'updated')->assertJsonPath('results.0.access_held', false);

        expect($holder->fresh()->role)->toBe(UserRole::DEPT_ADMIN);
    });

    it('leaves a role the requester could not give by hand, and says it is waiting', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $bulk = jobHolder($tenant);
        $single = jobHolder($tenant);
        $title = jobTitle($tenant, 'finance_admin');

        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$bulk->employee->public_id], 'position_id' => $title->public_id])
            ->assertOk()->assertJsonPath('results.0.outcome', 'updated')->assertJsonPath('results.0.access_held', true);
        $this->patchJson('/api/v1/employees/'.$single->employee->public_id, ['position_id' => $title->public_id])->assertOk();

        $held = app(PositionAccess::class)->heldQuery()->pluck('users.id')->sort()->values()->all();

        expect($bulk->fresh()->role)->toBe(UserRole::EMPLOYEE)
            ->and($single->fresh()->role)->toBe($bulk->fresh()->role)
            ->and($held)->toBe(collect([$bulk->id, $single->id])->sort()->values()->all());
    });

    it('gives nothing to a requester who lacks users.update, as the single edit does not', function () {
        $tenant = createTenant();
        $editor = createUser(['role' => UserRole::DEPT_ADMIN], $tenant);
        $custom = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'People editor', 'org_scope' => 'all']);
        $custom->permissions()->sync(Permission::whereIn('name', ['employee.update', 'employee.view', 'employee.viewAny'])->pluck('id'));
        $editor->update(['custom_role_id' => $custom->id]);
        $this->actingAs($editor);
        $holder = jobHolder($tenant);
        $title = jobTitle($tenant, 'supervisor');

        expect($editor->fresh()->hasPermission('users.update'))->toBeFalse();
        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$holder->employee->public_id], 'position_id' => $title->public_id])
            ->assertOk()->assertJsonPath('results.0.access_held', true);

        expect($holder->fresh()->role)->toBe(UserRole::EMPLOYEE);
    });

    it('never moves a requester\'s own access through their own record', function () {
        $tenant = createTenant();
        $me = jobHolder($tenant, jobTitle($tenant));
        $me->update(['role' => UserRole::HR_ADMIN]);
        $this->actingAs($me);
        $title = jobTitle($tenant, 'dept_admin');

        $this->postJson('/api/v1/employees/bulk-update', ['employee_ids' => [$me->employee->public_id], 'position_id' => $title->public_id])
            ->assertOk()->assertJsonPath('results.0.access_held', true);

        expect($me->fresh()->role)->toBe(UserRole::HR_ADMIN);
    });
});

describe('GET /employees/ids', function () {
    it('names the people the list matches, by the list\'s own filters', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $in = Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'branch_id' => $branch->id, 'status' => 'probation']);
        Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'status' => 'confirmed']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'probation']);
        $named = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Zerihun Ketema', 'department_id' => $dept->id, 'status' => 'probation']);

        $ids = $this->getJson('/api/v1/employees/ids?filter[department_id]='.$dept->public_id.'&filter[status]=probation')
            ->assertOk()->assertJsonPath('total', 4)->assertJsonPath('capped', false)->assertJsonPath('cap', 2000)->json('ids');
        expect($ids)->toEqualCanonicalizing([...$in->pluck('public_id')->all(), $named->public_id]);

        $this->getJson('/api/v1/employees/ids?search=Zerihun')->assertOk()->assertJsonPath('ids', [$named->public_id]);

        $this->getJson('/api/v1/employees?filter[department_id]='.$dept->public_id.'&filter[status]=probation&per_page=100')
            ->assertOk()->assertJsonPath('meta.total', 4);
    });

    it('stops at 2,000 and says so', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $seed = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $row = (array) DB::table('employees')->where('id', $seed->id)->first();
        unset($row['id']);
        // The generated columns are computed by the database, not inserted.
        $row = array_filter($row, fn (string $column): bool => ! str_ends_with($column, '_normalized'), ARRAY_FILTER_USE_KEY);
        $rows = [];
        for ($i = 0; $i < 2000; $i++) {
            $rows[] = [...$row, 'public_id' => (string) Str::ulid(), 'employee_code' => 'CAP-'.$i, 'email' => null, 'badge_number' => null, 'national_id_hash' => null, 'user_id' => null];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('employees')->insert($chunk);
        }

        $response = $this->getJson('/api/v1/employees/ids')->assertOk()
            ->assertJsonPath('total', 2001)->assertJsonPath('capped', true)->assertJsonPath('cap', 2000);
        expect($response->json('ids'))->toHaveCount(2000);
    });

    it('holds to the requester\'s reach', function () {
        $tenant = createTenant();
        [$mine, $theirs] = jobScopedManager($tenant, ['employee.viewAny']);
        $inReach = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);
        $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id]);

        $ids = $this->getJson('/api/v1/employees/ids')->assertOk()->json('ids');

        expect($ids)->toContain($inReach->public_id)->and($ids)->not->toContain($outside->public_id);
    });

    it('never names another organisation\'s people', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $mine = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $foreign = Employee::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        app(CurrentTenant::class)->set($tenant);

        $ids = $this->getJson('/api/v1/employees/ids')->assertOk()->json('ids');

        expect($ids)->toContain($mine->public_id)->and($ids)->not->toContain($foreign->public_id);
    });

    it('is closed to someone who may not list employees', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->getJson('/api/v1/employees/ids')->assertForbidden();
    });
});
