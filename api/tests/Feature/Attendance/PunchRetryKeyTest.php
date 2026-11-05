<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;

// A-Z review D-09 (2026-11-04). A retried punch is answered with the record
// its key already made. The lookup matched the key alone, so a punch from one
// person carrying a key another person's punch had used was answered with
// that other person's record. A key now answers only for its own person.

function retryKeyPerson(Tenant $tenant): array
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    return [$employee, createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant)];
}

function retryKeyCheckIn(Tenant $tenant, User $user, string $key)
{
    test()->actingAs($user);

    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => $key,
        'latitude' => 9.0192,
        'longitude' => 38.7525,
    ]);
}

it('answers a key only for the person whose punch used it', function () {
    $tenant = createTenant();
    [$first, $firstUser] = retryKeyPerson($tenant);
    [$second, $secondUser] = retryKeyPerson($tenant);
    $key = (string) Str::uuid();

    $record = retryKeyCheckIn($tenant, $firstUser, $key)->assertCreated()->json('public_id');

    $response = retryKeyCheckIn($tenant, $secondUser, $key)->assertStatus(422);

    expect($response->getContent())->not->toContain($record)
        ->not->toContain($first->name)
        ->and(AttendanceRecord::where('employee_id', $second->id)->exists())->toBeFalse();
});

it('still answers a person\'s own retry with the record it made', function () {
    $tenant = createTenant();
    [, $user] = retryKeyPerson($tenant);
    $key = (string) Str::uuid();

    $record = retryKeyCheckIn($tenant, $user, $key)->assertCreated()->json('public_id');

    retryKeyCheckIn($tenant, $user, $key)->assertOk()->assertJsonPath('public_id', $record);
});
