<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UserProvisioningService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One live login per employee, held by the database.
 *
 * A login is whose punches, payslips, salary and leave an account acts on, so
 * a second login on someone's record read theirs as its own. #670 refused it
 * in `StoreUserRequest` and `UserProvisioningService::provision()`; every
 * other path and any race could still write one. These tests are about the
 * index (`2026_11_09_000002`) and the data migration before it
 * (`2026_11_09_000001`), not the request validation #670 pinned.
 */
function loginFor(Employee $employee, array $attributes = []): User
{
    return User::factory()->create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        ...$attributes,
    ]);
}

function migrationFile(string $name): object
{
    return require database_path("migrations/{$name}.php");
}

// ── The index ──

test('the database refuses a second live login on the same employee', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    loginFor($employee);

    expect(fn () => loginFor($employee))->toThrow(QueryException::class);
    expect(User::where('employee_id', $employee->id)->count())->toBe(1);
});

test('the refusal holds on a path that skips the request check', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null]);

    // The in-person path has no duplicate check of its own: it trusts its
    // caller to have looked at employees.user_id. Two callers racing past that
    // check, or one with an employee row whose user_id was never set, wrote a
    // second login. Now the second insert fails.
    $provisioning = app(UserProvisioningService::class);
    $provisioning->provisionWithoutContact($employee);
    $employee->forceFill(['user_id' => null])->save();

    expect(fn () => $provisioning->provisionWithoutContact($employee->fresh()))
        ->toThrow(QueryException::class);
    expect(User::where('employee_id', $employee->id)->count())->toBe(1);
});

test('an account in another tenant cannot take the same employee', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    loginFor($employee);

    $other = Tenant::factory()->create();

    // Global rather than (tenant_id, employee_id): an employee id belongs to one
    // tenant, so a second tenant holding it is a defect, never a legitimate row.
    expect(fn () => User::factory()->create(['tenant_id' => $other->id, 'employee_id' => $employee->id]))
        ->toThrow(QueryException::class);
});

test('moving a login onto an employee who has one is refused too', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    loginFor($employee);

    $unlinked = User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => null]);

    expect(fn () => $unlinked->update(['employee_id' => $employee->id]))
        ->toThrow(QueryException::class);
});

test('any number of accounts may have no employee', function () {
    $tenant = createTenant();

    User::factory()->count(3)->create(['tenant_id' => $tenant->id, 'employee_id' => null]);

    expect(User::where('tenant_id', $tenant->id)->whereNull('employee_id')->count())->toBe(3);
});

test('a deleted login does not block the employee getting a new one', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $first = loginFor($employee);
    $first->delete();

    // The reason the index is on a generated column: UserController::destroy()
    // soft-deletes, and a plain unique index would count the deleted row.
    $second = loginFor($employee);

    expect($second->exists)->toBeTrue()
        ->and(User::withTrashed()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and($first->fresh()?->employee_id)->toBe($employee->id);
});

test('a deleted login cannot be restored onto an employee who has a new one', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $first = loginFor($employee);
    $first->delete();
    loginFor($employee);

    expect(fn () => $first->restore())->toThrow(QueryException::class);
});

test('the generated column is not part of a user as serialised', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    expect(loginFor($employee)->fresh()?->toArray())->not->toHaveKey('employee_id_active');
});

// ── The data migration, and the index migration after it ──
//
// A duplicate can only be written with the index gone, which is DDL. MariaDB
// commits DDL implicitly, which would end RefreshDatabase's transaction and
// leave the index dropped for every later test in the suite, so these run on
// SQLite only. Nothing in either migration is SQLite-specific: the rehearsal
// against MariaDB is recorded in BASELINE §25h.

test('the data migration keeps the login the employee record names and unlinks the rest', function () {
    $tenant = createTenant();
    $named = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $unnamed = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $single = Employee::factory()->create(['tenant_id' => $tenant->id]);

    migrationFile('2026_11_09_000002_make_users_employee_id_unique')->down();

    $oldest = loginFor($named);
    $recordsOwn = loginFor($named);
    $third = loginFor($named);
    $deleted = loginFor($named);
    $deleted->delete();
    $named->forceFill(['user_id' => $recordsOwn->id])->save();

    // No user_id on the employee: the oldest keeps the link.
    $unnamedFirst = loginFor($unnamed);
    $unnamedSecond = loginFor($unnamed);
    $unnamed->forceFill(['user_id' => null])->save();

    $alone = loginFor($single);

    migrationFile('2026_11_09_000001_unlink_second_logins_from_employees')->up();

    expect($recordsOwn->fresh()->employee_id)->toBe($named->id)
        ->and($oldest->fresh()->employee_id)->toBeNull()
        ->and($third->fresh()->employee_id)->toBeNull()
        ->and(User::withTrashed()->find($deleted->id)->employee_id)->toBe($named->id)
        ->and($unnamedFirst->fresh()->employee_id)->toBe($unnamed->id)
        ->and($unnamedSecond->fresh()->employee_id)->toBeNull()
        ->and($alone->fresh()->employee_id)->toBe($single->id);

    // An unlinked account keeps its sign-in; only the link goes.
    expect($oldest->fresh()->status)->toBe($oldest->status)
        ->and($oldest->fresh()->role)->toBe($oldest->role);

    $audit = DB::table('audit_log')->where('action', 'user.employee_unlinked')->orderBy('id')->get();
    expect($audit)->toHaveCount(3)
        ->and($audit->pluck('auditable_id')->all())->toBe([$oldest->id, $third->id, $unnamedSecond->id])
        ->and($audit->pluck('tenant_id')->unique()->all())->toBe([$tenant->id]);

    $payload = json_decode((string) $audit->first()->payload, true);
    expect($payload)->toMatchArray([
        'employee_id' => $named->public_id,
        'kept_user_id' => $recordsOwn->public_id,
        'reason' => 'second_login',
    ]);

    // With the data fixed, the index migration runs, and holds.
    migrationFile('2026_11_09_000002_make_users_employee_id_unique')->up();
    expect(fn () => loginFor($single))->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'DDL inside the test transaction: SQLite only');

test('the data migration changes nothing when there are no duplicates', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $login = loginFor($employee);
    $before = $login->fresh()->updated_at;

    migrationFile('2026_11_09_000001_unlink_second_logins_from_employees')->up();

    expect($login->fresh()->employee_id)->toBe($employee->id)
        ->and($login->fresh()->updated_at?->equalTo($before))->toBeTrue()
        ->and(DB::table('audit_log')->where('action', 'user.employee_unlinked')->count())->toBe(0);
});

test('the index migration refuses, naming the accounts, if a duplicate is still there', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    migrationFile('2026_11_09_000002_make_users_employee_id_unique')->down();

    $first = loginFor($employee);
    $second = loginFor($employee);

    expect(fn () => migrationFile('2026_11_09_000002_make_users_employee_id_unique')->up())
        ->toThrow(RuntimeException::class, $second->public_id);

    expect(Schema::hasColumn('users', 'employee_id_active'))->toBeFalse()
        ->and($first->fresh()->employee_id)->toBe($employee->id);
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'DDL inside the test transaction: SQLite only');

test('the index migration rolls back and forward cleanly', function () {
    $index = migrationFile('2026_11_09_000002_make_users_employee_id_unique');

    $index->down();
    expect(Schema::hasColumn('users', 'employee_id_active'))->toBeFalse();

    $index->up();
    expect(Schema::hasColumn('users', 'employee_id_active'))->toBeTrue()
        ->and(collect(Schema::getIndexes('users'))->pluck('name'))->toContain('users_live_employee_unique');
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'DDL inside the test transaction: SQLite only');
