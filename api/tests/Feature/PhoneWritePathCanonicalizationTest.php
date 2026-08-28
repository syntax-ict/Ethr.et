<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Employee;
use App\Models\User;
use App\Services\Migration\WorkforceMigrationService;

// Registration already canonicalizes the admin phone; these are the bulk/
// automated write paths that used to persist whatever raw shape their source
// carried. Each now stores the one canonical +251 form. A user who typed the
// local 09… shape must land identically to one imported as +251… — otherwise a
// later phone lookup (OTP sign-in, identity matching) sees two different people.

test('CSV migration stores a locally-typed phone in canonical form', function () {
    $tenant = createTenant();

    $batch = app(WorkforceMigrationService::class)->stageFromRows(
        [['name' => 'Migrated Person', 'phone' => '0911223344', 'employee_code' => 'MIG-1']],
        $tenant->id,
    );

    // A brand-new person resolves to NEW → suggested action CREATE.
    app(WorkforceMigrationService::class)->commit($batch);

    $employee = Employee::withoutGlobalScopes()->where('employee_code', 'MIG-1')->firstOrFail();
    expect($employee->phone)->toBe('+251911223344');
});

test('SCIM provisioning stores a locally-typed phone in canonical form', function () {
    $tenant = createTenant();
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $token = 'scim-canon-token-'.uniqid();
    ApiKey::create([
        'tenant_id' => $tenant->id,
        'name' => 'SCIM Canon',
        'key_hash' => hash('sha256', $token),
        'key_prefix' => substr($token, 0, 8),
        'abilities' => ['scim'],
        'created_by' => $admin->id,
        'expires_at' => now()->addYear(),
    ]);

    $response = $this->postJson('/api/v1/scim/v2/Users', [
        'userName' => 'canon.user@example.com',
        'name' => ['givenName' => 'Local', 'familyName' => 'Number'],
        'phoneNumbers' => [['value' => '0911223344', 'type' => 'work']],
        'active' => true,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertCreated();

    $employee = Employee::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('email', 'canon.user@example.com')
        ->firstOrFail();
    expect($employee->phone)->toBe('+251911223344');
});

test('a foreign number on a write path is stored verbatim, not discarded', function () {
    $tenant = createTenant();

    $batch = app(WorkforceMigrationService::class)->stageFromRows(
        [['name' => 'Foreign Consultant', 'phone' => '+1 202 555 0100', 'employee_code' => 'MIG-2']],
        $tenant->id,
    );
    app(WorkforceMigrationService::class)->commit($batch);

    $employee = Employee::withoutGlobalScopes()->where('employee_code', 'MIG-2')->firstOrFail();
    expect($employee->phone)->toBe('+1 202 555 0100');
});
