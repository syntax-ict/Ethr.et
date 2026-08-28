<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;

/**
 * CLAUDE.md convention #10 documents an `Idempotency-Key` header on every write.
 * The FormRequests only ever validated a body field, and nothing read the header,
 * so an integrator following the published contract got a 422 on every attendance
 * capture method. The header now satisfies the rule without disturbing the body
 * field the frontend already sends.
 */
beforeEach(function () {
    $this->tenant = createTenant();
    $this->user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);
    // User::employee() is a BelongsTo, so the link lives on users.employee_id.
    $this->employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->user->forceFill(['employee_id' => $this->employee->id])->save();
});

it('accepts the documented Idempotency-Key header in place of the body field', function () {
    $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/attendance/check-in', [])
        ->assertSuccessful();
});

it('still accepts the body field, so existing clients keep working', function () {
    $this->postJson('/api/v1/attendance/check-in', [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSuccessful();
});

it('prefers the body field when a caller sends both', function () {
    $bodyKey = (string) Str::uuid();

    $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/attendance/check-in', ['idempotency_key' => $bodyKey])
        ->assertSuccessful();

    // Replaying the *body* key is what must be recognised as the duplicate.
    $replay = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/attendance/check-in', ['idempotency_key' => $bodyKey]);

    expect($replay->json('was_duplicate'))->toBeTrue();
});

it('treats a replayed header key as a duplicate rather than a second record', function () {
    $key = (string) Str::uuid();

    $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/attendance/check-in', [])
        ->assertSuccessful();

    $replay = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/attendance/check-in', []);

    $replay->assertSuccessful();
    expect($replay->json('was_duplicate'))->toBeTrue();
});

it('still rejects a write that supplies no idempotency key at all', function () {
    $this->postJson('/api/v1/attendance/check-in', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.idempotency_key.0', fn ($m) => is_string($m));
});

it('leaves GET requests untouched', function () {
    $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->getJson('/api/v1/attendance/my')
        ->assertOk();
});
