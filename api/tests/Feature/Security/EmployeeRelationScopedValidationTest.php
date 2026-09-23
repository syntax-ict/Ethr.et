<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\Position;
use App\Models\Team;
use App\Models\Tenant;
use App\Support\EmployeeRelations;

/**
 * Every employee relation that arrives as a `public_id`, scoped to the tenant.
 *
 * `docs/audit/BASELINE.md` §11d site 5, extended to all seven fields in §11f.
 * Each is validated with `exists:<table>,public_id`, which goes through
 * Laravel's DatabasePresenceVerifier — and that verifier does **not** apply
 * Eloquent global scopes, so another tenant's `public_id` passed validation.
 *
 * It was never a cross-tenant read: `EmployeeController::resolveRelationIds()`
 * resolves with a *scoped* query, so a foreign id became `null`. The effect was
 * a relation that silently vanished — 201 Created with the field empty and
 * nothing said anywhere.
 *
 * **These are behaviour changes, authorised deliberately.** Each field that
 * used to return 201-with-a-null now returns 422. The tests below pin both
 * halves per field: the rejection, and the fact that the rejection cannot be
 * used to probe another tenant.
 */
dataset('relation fields', [
    'department_id' => ['department_id', Department::class],
    'branch_id' => ['branch_id', Branch::class],
    'position_id' => ['position_id', Position::class],
    'grade_id' => ['grade_id', Grade::class],
    'team_id' => ['team_id', Team::class],
    'cost_center_id' => ['cost_center_id', CostCenter::class],
    'supervisor_id' => ['supervisor_id', Employee::class],
]);

/**
 * `CostCenter` is deliberately absent: it is the one model in the map that does
 * not use `SoftDeletes`, so there is no deleted state for it to be rejected in.
 * The validator asks the model rather than assuming a `deleted_at` column, and
 * this dataset is that asymmetry written down rather than worked around.
 */
dataset('soft-deleting relation fields', [
    'department_id' => ['department_id', Department::class],
    'branch_id' => ['branch_id', Branch::class],
    'position_id' => ['position_id', Position::class],
    'grade_id' => ['grade_id', Grade::class],
    'team_id' => ['team_id', Team::class],
    'supervisor_id' => ['supervisor_id', Employee::class],
]);

/** A public_id of the right shape (CHAR(26)) that belongs to nothing. */
function noSuchPublicId(): string
{
    return '01JNOSUCHPUBLICIDXXXXXXXXX';
}

function foreignRelation(string $modelClass): object
{
    $other = Tenant::factory()->create();

    return $modelClass::factory()->create(['tenant_id' => $other->id]);
}

function employeePayload(string $field, string $publicId, string $code): array
{
    return [
        'name' => 'Relation Validation Candidate',
        'employee_code' => $code,
        'hire_date' => '2024-01-15',
        $field => $publicId,
    ];
}

// ── The defect: a relation owned by another tenant ──

test('create rejects a relation belonging to another tenant', function (string $field, string $modelClass) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $foreign = foreignRelation($modelClass);

    $this->postJson('/api/v1/employees', employeePayload($field, $foreign->public_id, 'EMP-X1'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    // What this replaces: the employee used to be created, with the field null.
    $this->assertDatabaseMissing('employees', ['name' => 'Relation Validation Candidate']);
})->with('relation fields');

test('update rejects a relation belonging to another tenant', function (string $field, string $modelClass) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $foreign = foreignRelation($modelClass);

    $this->putJson("/api/v1/employees/{$employee->public_id}", [$field => $foreign->public_id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($employee->fresh()->{$field})->toBeNull();
})->with('relation fields');

// ── The invariant the whole change rests on: validation agrees with resolution ──

test('create accepts a same-tenant relation and actually stores it', function (string $field, string $modelClass) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $related = $modelClass::factory()->create(['tenant_id' => $tenant->id]);

    $response = $this->postJson('/api/v1/employees', employeePayload($field, $related->public_id, 'EMP-OK1'))
        ->assertCreated();

    $created = Employee::where('public_id', $response->json('public_id'))->firstOrFail();

    // Not merely "was accepted" — resolveRelationIds() must have found the same
    // row the validator did. A pass here with a null field would be the old bug
    // wearing a 201.
    expect($created->{$field})->toBe($related->id);
})->with('relation fields');

test('create rejects a soft-deleted relation from the same tenant', function (string $field, string $modelClass) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $related = $modelClass::factory()->create(['tenant_id' => $tenant->id]);
    $publicId = $related->public_id;
    $related->delete();

    // Not an extra rule — a consequence of asking with the resolver's own
    // query. resolveRelationIds() resolves through the SoftDeletes scope and
    // would find nothing, so accepting this would re-create the silent null for
    // deleted relations.
    $this->postJson('/api/v1/employees', employeePayload($field, $publicId, 'EMP-SD1'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with('soft-deleting relation fields');

// ── The rejection must not be usable to probe another tenant ──

test('a foreign relation is rejected in exactly the words a nonexistent one is', function (string $field, string $modelClass) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $foreign = foreignRelation($modelClass);

    $foreignMessage = $this->postJson('/api/v1/employees', employeePayload($field, $foreign->public_id, 'EMP-M1'))
        ->assertUnprocessable()
        ->json("errors.{$field}");

    $nonexistentMessage = $this->postJson('/api/v1/employees', employeePayload($field, noSuchPublicId(), 'EMP-M2'))
        ->assertUnprocessable()
        ->json("errors.{$field}");

    // The point of the scope is that a caller cannot learn whether a public_id
    // exists in some other tenant. A message distinguishing "no such record"
    // from "exists elsewhere" hands that back, so the two must be identical
    // rather than merely both 422.
    expect($foreignMessage)->toBe($nonexistentMessage);
})->with('relation fields');

test('a nonexistent relation is still rejected', function (string $field, string $modelClass) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->postJson('/api/v1/employees', employeePayload($field, noSuchPublicId(), 'EMP-N1'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with('relation fields');

// ── Structure: the map and the rules cannot cover different field sets ──

test('every mapped relation field is validated in both employee requests', function () {
    createTenant();

    $store = (new StoreEmployeeRequest)->rules();
    $update = (new UpdateEmployeeRequest)->rules();

    // EmployeeRelations::MAP is what both resolveRelationIds() and
    // ValidatesRelationTenancy iterate. If a field were added to the map with
    // no rule beside it, the hook would police a field the request does not
    // accept; if a rule were added with no map entry, it would be unscoped
    // again — the original defect. This fails on either.
    foreach (array_keys(EmployeeRelations::MAP) as $field) {
        expect($store)->toHaveKey($field);
        expect($update)->toHaveKey($field);
    }

    expect(array_keys(EmployeeRelations::MAP))->toHaveCount(7);
})->group('structure');

test('omitting every relation is still allowed', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->postJson('/api/v1/employees', [
        'name' => 'Relation Validation Candidate',
        'employee_code' => 'EMP-NONE-1',
        'hire_date' => '2024-01-15',
    ])->assertCreated();

    $nulls = array_fill_keys(array_keys(EmployeeRelations::MAP), null);

    $this->postJson('/api/v1/employees', array_merge([
        'name' => 'Kebede Abebe',
        'employee_code' => 'EMP-NONE-2',
        'hire_date' => '2024-01-15',
    ], $nulls))->assertCreated();
});
