<?php

declare(strict_types=1);

use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\UndoFixtures as Used;

/*
 * Archiving a selection, and bringing it back. The same safety rule as the undo
 * of an import list (UndoListTest): a person with any history is kept with the
 * reason, and only a record nobody has used is archived. Who is chosen differs;
 * the check, the audit and the closing of the login are the one EmployeeArchiver.
 */

const ARCHIVE_NO_SUCH_ID = '01JNOSUCHPUBLICIDXXXXXXXXX';

/** @return array{0: Tenant, 1: User} */
function archiveAdmin(): array
{
    $tenant = createTenant();

    return [$tenant, actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant)];
}

function archivePerson(Tenant $tenant, array $attributes = []): Employee
{
    return Employee::factory()->create(['tenant_id' => $tenant->id, ...$attributes]);
}

/** @param  list<Employee>  $people */
function archiveIds(array $people): array
{
    return array_map(fn (Employee $person): string => $person->public_id, $people);
}

/** The two checks of a stored audit payload, with keys in one order (MySQL 8 sorts JSON keys by length). */
function archivePayload(AuditLog $entry): array
{
    $payload = $entry->payload;
    ksort($payload);

    return $payload;
}

describe('who may archive and restore', function () {
    it('refuses a role without employee.delete on all three, and writes nothing', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $person = archivePerson($tenant);
        $gone = archivePerson($tenant);
        $gone->delete();

        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [$person->public_id], 'confirm' => true])->assertForbidden();
        $this->postJson('/api/v1/employees/bulk-restore', ['employee_ids' => [$gone->public_id]])->assertForbidden();
        $this->getJson('/api/v1/employees?filter[archived]=1')->assertForbidden();

        expect($person->fresh()->trashed())->toBeFalse()
            ->and(Employee::withTrashed()->find($gone->id)->trashed())->toBeTrue();
    });

    it('answers a 403 before it names an id, even one that does not exist', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [ARCHIVE_NO_SUCH_ID]])->assertForbidden();
        $this->postJson('/api/v1/employees/bulk-restore', ['employee_ids' => [ARCHIVE_NO_SUCH_ID]])->assertForbidden();
    });
});

describe('the ids it names', function () {
    it('gives another organisation\'s id the message a nonexistent id gets, on archive and on restore', function () {
        [$tenant] = archiveAdmin();
        $own = archivePerson($tenant);
        $foreign = Employee::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $foreignGone = Employee::factory()->create(['tenant_id' => $foreign->tenant_id]);
        $foreignGone->delete();

        foreach (['bulk-archive', 'bulk-restore'] as $route) {
            $missing = $this->postJson("/api/v1/employees/{$route}", ['employee_ids' => [$own->public_id, ARCHIVE_NO_SUCH_ID]])
                ->assertUnprocessable()->json('errors')['employee_ids.1'];

            foreach ([$foreign, $foreignGone] as $other) {
                $crossTenant = $this->postJson("/api/v1/employees/{$route}", ['employee_ids' => [$own->public_id, $other->public_id]])
                    ->assertUnprocessable()->json('errors')['employee_ids.1'];

                expect($crossTenant)->toBe($missing);
            }
        }

        expect(DB::table('employees')->whereIn('id', [$foreign->id, $own->id])->whereNotNull('deleted_at')->count())->toBe(0);
    });

    it('refuses the whole request when one id is outside the caller\'s org scope', function () {
        $tenant = createTenant();
        $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
        $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
        $head = archivePerson($tenant, ['department_id' => $mine->id]);
        $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Department manager', 'org_scope' => OrgScope::DEPARTMENT->value]);
        $role->permissions()->sync(Permission::whereIn('name', ['employee.delete'])->pluck('id'));
        actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id, 'employee_id' => $head->id], $tenant);

        $inside = archivePerson($tenant, ['department_id' => $mine->id]);
        $outside = archivePerson($tenant, ['department_id' => $theirs->id]);
        $outsideGone = archivePerson($tenant, ['department_id' => $theirs->id]);
        $outsideGone->delete();

        $missing = $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [$inside->public_id, ARCHIVE_NO_SUCH_ID]])
            ->assertUnprocessable()->json('errors')['employee_ids.1'];

        foreach (['bulk-archive' => ['confirm' => true], 'bulk-restore' => []] as $route => $extra) {
            foreach ([$outside, $outsideGone] as $other) {
                $refused = $this->postJson("/api/v1/employees/{$route}", ['employee_ids' => [$inside->public_id, $other->public_id], ...$extra])
                    ->assertUnprocessable()->json('errors')['employee_ids.1'];

                expect($refused)->toBe($missing);
            }
        }

        expect($inside->fresh()->trashed())->toBeFalse()
            ->and($outside->fresh()->trashed())->toBeFalse();

        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [$inside->public_id], 'confirm' => true])
            ->assertOk()->assertJsonPath('archive_count', 1);
    });

    it('takes between 1 and 200 distinct ids', function () {
        [$tenant] = archiveAdmin();
        $person = archivePerson($tenant);

        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['employee_ids']);
        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => array_fill(0, 201, $person->public_id)])->assertUnprocessable()->assertJsonValidationErrors(['employee_ids']);
        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [$person->public_id, $person->public_id]])->assertUnprocessable()->assertJsonValidationErrors(['employee_ids.1']);
        $this->postJson('/api/v1/employees/bulk-restore', ['employee_ids' => array_fill(0, 201, $person->public_id)])->assertUnprocessable()->assertJsonValidationErrors(['employee_ids']);

        $many = Employee::factory()->count(200)->create(['tenant_id' => $tenant->id]);
        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => $many->pluck('public_id')->all(), 'confirm' => true])
            ->assertOk()->assertJsonPath('archive_count', 200);
        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    });
});

describe('archiving', function () {
    it('previews who would be archived and who kept, and writes nothing', function () {
        [$tenant] = archiveAdmin();
        $unused = archivePerson($tenant, ['name' => 'Meron Alemu']);
        $worked = archivePerson($tenant, ['name' => 'Abebe Kebede']);
        Used::use($tenant, $worked, 'attendance');

        $answer = $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [$unused->public_id, $worked->public_id]])
            ->assertOk()
            ->assertJsonPath('confirmed', false)
            ->assertJsonPath('archive_count', 1)
            ->assertJsonPath('keep_count', 1)
            ->assertJsonPath('already_archived', 0)
            ->assertJsonPath('failed', 0)
            ->json();

        expect($answer['results'])->toBe([
            ['employee_id' => $unused->public_id, 'name' => 'Meron Alemu', 'outcome' => 'archive', 'reasons' => [], 'error' => null],
            ['employee_id' => $worked->public_id, 'name' => 'Abebe Kebede', 'outcome' => 'keep', 'reasons' => ['attendance'], 'error' => null],
        ]);

        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
            ->and(AuditLog::query()->whereIn('action', ['employee.deleted', 'employee.bulk_archived'])->count())->toBe(0);
    });

    it('archives the unused and keeps everyone with history, each with the right reason', function () {
        [$tenant] = archiveAdmin();
        $names = ['Abebe Kebede' => 'attendance', 'Dawit Haile' => 'payroll', 'Sara Tesfaye' => 'signed_in', 'Hana Girma' => 'leave', 'Meron Alemu' => null];
        $people = [];
        foreach ($names as $name => $reason) {
            $people[$name] = archivePerson($tenant, ['name' => $name]);
            if ($reason !== null) {
                Used::use($tenant, $people[$name], $reason);
            }
        }

        $answer = $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => archiveIds(array_values($people)), 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('archive_count', 1)
            ->assertJsonPath('keep_count', 4)
            ->json();

        $byName = collect($answer['results'])->keyBy('name');
        expect($byName['Meron Alemu']['outcome'])->toBe('archived')
            ->and($byName['Abebe Kebede'])->toMatchArray(['outcome' => 'kept', 'reasons' => ['attendance']])
            ->and($byName['Dawit Haile'])->toMatchArray(['outcome' => 'kept', 'reasons' => ['payroll']])
            ->and($byName['Sara Tesfaye'])->toMatchArray(['outcome' => 'kept', 'reasons' => ['signed_in']])
            ->and($byName['Hana Girma'])->toMatchArray(['outcome' => 'kept', 'reasons' => ['leave']]);

        expect(Employee::withTrashed()->where('tenant_id', $tenant->id)->onlyTrashed()->pluck('name')->all())->toBe(['Meron Alemu']);
    });

    it('closes the login nobody signed in to, and audits each person and the whole', function () {
        [$tenant, $admin] = archiveAdmin();
        $person = archivePerson($tenant, ['name' => 'Meron Alemu']);
        $other = archivePerson($tenant, ['name' => 'Tigist Wolde']);
        $account = User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $person->id, 'status' => 'invited', 'last_login_at' => null]);

        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [$person->public_id, $other->public_id], 'confirm' => true])
            ->assertOk()->assertJsonPath('archive_count', 2);

        expect($account->refresh()->status)->toBe('inactive')
            ->and($account->trashed())->toBeFalse();

        $deleted = AuditLog::query()->where('action', 'employee.deleted')->get();
        expect($deleted)->toHaveCount(2)
            ->and($deleted->every(fn (AuditLog $entry): bool => archivePayload($entry) === ['via' => 'bulk_archive'] && $entry->user_id === $admin->id))->toBeTrue();

        expect(archivePayload(AuditLog::query()->where('action', 'user.deactivated')->sole()))->toBe(['reason' => 'bulk_archive']);

        $summary = AuditLog::query()->where('action', 'employee.bulk_archived')->sole();
        expect($summary->tenant_id)->toBe($tenant->id)
            ->and(archivePayload($summary))->toBe(['already_archived' => 0, 'archived' => 2, 'failed' => 0, 'kept' => 0, 'requested' => 2]);
    });

    it('keeps the person whose own login it is, as self', function () {
        $tenant = createTenant();
        $mine = archivePerson($tenant, ['name' => 'Abebe Kebede']);
        $other = archivePerson($tenant, ['name' => 'Meron Alemu']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN, 'employee_id' => $mine->id], $tenant);

        $answer = $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [$mine->public_id, $other->public_id], 'confirm' => true])
            ->assertOk()->json();

        $self = collect($answer['results'])->firstWhere('employee_id', $mine->public_id);
        expect($self['outcome'])->toBe('kept')
            ->and($self['reasons'])->toContain('self')
            ->and($mine->fresh()->trashed())->toBeFalse()
            ->and($other->fresh()->trashed())->toBeTrue();
    });

    it('does nothing the second time and says so', function () {
        [$tenant] = archiveAdmin();
        $person = archivePerson($tenant);
        $body = ['employee_ids' => [$person->public_id], 'confirm' => true];

        $this->postJson('/api/v1/employees/bulk-archive', $body)->assertOk()->assertJsonPath('archive_count', 1);

        $this->postJson('/api/v1/employees/bulk-archive', $body)->assertOk()
            ->assertJsonPath('archive_count', 0)
            ->assertJsonPath('already_archived', 1)
            ->assertJsonPath('results.0.outcome', 'already_archived');

        expect(AuditLog::query()->where('action', 'employee.deleted')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'employee.bulk_archived')->count())->toBe(1);
    });

    it('asks again at the moment of archiving, so someone used since the preview is kept', function () {
        [$tenant] = archiveAdmin();
        $person = archivePerson($tenant);

        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [$person->public_id]])
            ->assertOk()->assertJsonPath('results.0.outcome', 'archive');

        Used::use($tenant, $person, 'attendance');

        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => [$person->public_id], 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('results.0.outcome', 'kept')
            ->assertJsonPath('results.0.reasons', ['attendance']);

        expect($person->fresh()->trashed())->toBeFalse()
            ->and(AuditLog::query()->where('action', 'employee.bulk_archived')->count())->toBe(0);
    });
});

describe('the archived view', function () {
    it('lists only archived people, with the date, under the same search and org scope', function () {
        [$tenant] = archiveAdmin();
        $gone = archivePerson($tenant, ['name' => 'Meron Alemu']);
        $other = archivePerson($tenant, ['name' => 'Tigist Wolde']);
        archivePerson($tenant, ['name' => 'Abebe Kebede']);
        $foreign = Employee::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'name' => 'Meron Foreign']);
        $gone->delete();
        $other->delete();
        $foreign->delete();

        $this->getJson('/api/v1/employees?filter[archived]=1&sort=name')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Meron Alemu')
            ->assertJsonPath('data.1.name', 'Tigist Wolde')
            ->assertJsonPath('data.0.public_id', $gone->public_id)
            ->assertJsonPath('data.0.archived_at', fn ($at) => is_string($at) && $at !== '');

        $this->getJson('/api/v1/employees?filter[archived]=1&search=Meron')->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/employees')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.archived_at', null);
    });

    it('does not list an archived person outside the caller\'s org scope', function () {
        $tenant = createTenant();
        $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
        $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
        $head = archivePerson($tenant, ['department_id' => $mine->id]);
        $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Department manager', 'org_scope' => OrgScope::DEPARTMENT->value]);
        $role->permissions()->sync(Permission::whereIn('name', ['employee.delete', 'employee.viewAny'])->pluck('id'));
        actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id, 'employee_id' => $head->id], $tenant);

        archivePerson($tenant, ['department_id' => $mine->id, 'name' => 'Inside'])->delete();
        archivePerson($tenant, ['department_id' => $theirs->id, 'name' => 'Outside'])->delete();

        $this->getJson('/api/v1/employees?filter[archived]=1')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Inside');
    });
});

describe('restoring', function () {
    it('restores archived people through the same code as the single restore, and audits each', function () {
        [$tenant, $admin] = archiveAdmin();
        $a = archivePerson($tenant);
        $b = archivePerson($tenant);
        $live = archivePerson($tenant);
        $account = User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $a->id, 'status' => 'inactive']);
        $a->delete();
        $b->delete();

        $answer = $this->postJson('/api/v1/employees/bulk-restore', ['employee_ids' => [$a->public_id, $b->public_id, $live->public_id]])
            ->assertOk()
            ->assertJsonPath('restored', 2)
            ->assertJsonPath('failed', 0)
            ->json();

        expect(array_column($answer['results'], 'outcome'))->toBe(['restored', 'restored', 'unchanged'])
            ->and(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(3)
            ->and(AuditLog::query()->where('action', 'employee.restored')->where('user_id', $admin->id)->count())->toBe(2)
            ->and($account->refresh()->status)->toBe('inactive');
    });

    it('reports a person the plan has no seat for, and restores the others it can', function () {
        [$tenant] = archiveAdmin();
        Subscription::factory()->for($tenant)->create(['plan_id' => Plan::factory()->create(['max_employees' => 2])->id]);
        archivePerson($tenant);
        $a = archivePerson($tenant);
        $b = archivePerson($tenant);
        $a->delete();
        $b->delete();

        $answer = $this->postJson('/api/v1/employees/bulk-restore', ['employee_ids' => [$a->public_id, $b->public_id]])
            ->assertOk()
            ->assertJsonPath('restored', 1)
            ->assertJsonPath('failed', 1)
            ->json();

        expect($answer['results'][0])->toMatchArray(['outcome' => 'restored', 'error' => null])
            ->and($answer['results'][1])->toMatchArray(['outcome' => 'failed', 'error' => __('billing.employee_limit_reached')])
            ->and(Employee::withTrashed()->find($b->id)->trashed())->toBeTrue();
    });

    it('brings back what a bulk archive archived', function () {
        [$tenant] = archiveAdmin();
        $people = Employee::factory()->count(3)->create(['tenant_id' => $tenant->id]);
        $ids = $people->pluck('public_id')->all();

        $this->postJson('/api/v1/employees/bulk-archive', ['employee_ids' => $ids, 'confirm' => true])->assertOk()->assertJsonPath('archive_count', 3);
        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(0);

        $this->postJson('/api/v1/employees/bulk-restore', ['employee_ids' => $ids])->assertOk()->assertJsonPath('restored', 3);
        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(3);
    });
});
