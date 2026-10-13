<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\CurrentTenant;

/*
 * Production QA 2026-10-11, P14: two `exists:{table},public_id` rules were
 * unscoped, so another tenant's public_id passed validation. The leave request
 * then failed later with a different answer (404) and the user invitation
 * silently linked nobody — each a reply that differed from a nonexistent id's.
 * Both now answer exactly as a nonexistent id does, so a foreign id is neither
 * accepted nor confirmed.
 */

function unscopedOtherTenant(): Tenant
{
    $other = Tenant::factory()->create();

    return $other;
}

test('a leave request naming another tenant\'s leave type is refused like a missing one', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    $other = unscopedOtherTenant();
    $foreign = LeaveType::withoutGlobalScopes()->create(LeaveType::factory()->raw([
        'tenant_id' => $other->id,
        'code' => 'foreign_type',
        'is_active' => true,
    ]));
    app(CurrentTenant::class)->set($tenant);

    $payload = fn (string $id) => [
        'leave_type_public_id' => $id,
        'start_date' => now()->addDays(3)->toDateString(),
        'end_date' => now()->addDays(4)->toDateString(),
    ];
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/leave/request";

    $foreignReply = test()->postJson($url, $payload($foreign->public_id))
        ->assertUnprocessable()
        ->json('errors.leave_type_public_id');

    $missingReply = test()->postJson($url, $payload('01ZZZZZZZZZZZZZZZZZZZZZZZZ'))
        ->assertUnprocessable()
        ->json('errors.leave_type_public_id');

    expect($foreignReply)->toBe($missingReply);
});

test('inviting a user linked to another tenant\'s employee is refused like a missing one', function () {
    $tenant = createTenant();
    test()->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));

    $other = unscopedOtherTenant();
    $foreign = Employee::withoutGlobalScopes()->create(Employee::factory()->raw(['tenant_id' => $other->id]));
    app(CurrentTenant::class)->set($tenant);

    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/users";
    $payload = fn (string $email, string $id) => [
        'email' => $email,
        'role' => UserRole::EMPLOYEE->value,
        'employee_id' => $id,
        'send_activation' => false,
    ];

    $foreignReply = test()->postJson($url, $payload('one@example.test', $foreign->public_id))
        ->assertUnprocessable()
        ->json('errors.employee_id');

    $missingReply = test()->postJson($url, $payload('two@example.test', '01ZZZZZZZZZZZZZZZZZZZZZZZZ'))
        ->assertUnprocessable()
        ->json('errors.employee_id');

    expect($foreignReply)->toBe($missingReply);
});

test('inviting a user linked to an own employee still works', function () {
    $tenant = createTenant();
    test()->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));
    $own = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/users", [
        'email' => 'own@example.test',
        'role' => UserRole::EMPLOYEE->value,
        'employee_id' => $own->public_id,
        'send_activation' => false,
    ])->assertCreated();

    expect($own->refresh()->user_id)->not->toBeNull();
});
