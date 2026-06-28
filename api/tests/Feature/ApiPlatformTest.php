<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhook\WebhookDispatcher;

// ── API Key Management ──

test('tenant admin can create api key', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/api-keys", [
        'name' => 'Integration Key',
        'abilities' => ['read', 'employees'],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['public_id', 'name', 'key', 'abilities'])
        ->assertJsonPath('name', 'Integration Key')
        ->assertJsonMissingPath('id');

    expect($response->json('key'))->toStartWith('ethr_');
});

test('tenant admin can list api keys', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    ApiKey::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/api-keys");

    $response->assertOk();
    expect($response->json('keys'))->toHaveCount(2);
});

test('tenant admin can revoke api key', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $key = ApiKey::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/api-keys/{$key->public_id}")
        ->assertNoContent();

    $key->refresh();
    expect($key->revoked_at)->not->toBeNull();
    expect($key->isActive())->toBeFalse();
});

test('revoked keys excluded from listing', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    ApiKey::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    ApiKey::factory()->revoked()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/api-keys");

    expect($response->json('keys'))->toHaveCount(1);
});

test('employee cannot manage api keys', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/api-keys", [
        'name' => 'Test',
        'abilities' => ['read'],
    ])->assertForbidden();
});

test('api key creation is audit logged', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/api-keys", [
        'name' => 'Audit Test',
        'abilities' => ['read'],
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', ['action' => 'apikey.created']);
});

// ── Webhooks ──

test('tenant admin can create webhook', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks", [
        'url' => 'https://example.com/webhook',
        'events' => ['employee.created', 'attendance.recorded'],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['public_id', 'url', 'secret', 'events'])
        ->assertJsonPath('url', 'https://example.com/webhook')
        ->assertJsonMissingPath('id');

    expect(strlen($response->json('secret')))->toBe(32);
});

test('tenant admin can list webhooks', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Webhook::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks");

    $response->assertOk();
    expect($response->json('webhooks'))->toHaveCount(3);
});

test('tenant admin can update webhook', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $webhook = Webhook::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks/{$webhook->public_id}", [
        'url' => 'https://updated.com/hook',
        'is_active' => false,
    ]);

    $response->assertOk()
        ->assertJsonPath('url', 'https://updated.com/hook')
        ->assertJsonPath('is_active', false);
});

test('tenant admin can delete webhook', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $webhook = Webhook::factory()->create(['tenant_id' => $tenant->id]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks/{$webhook->public_id}")
        ->assertNoContent();

    expect(Webhook::find($webhook->id))->toBeNull();
});

test('tenant admin can send test webhook', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $webhook = Webhook::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks/{$webhook->public_id}/test");

    $response->assertOk()
        ->assertJsonPath('event', 'test');
});

test('tenant admin can view webhook deliveries', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $webhook = Webhook::factory()->create(['tenant_id' => $tenant->id]);
    WebhookDelivery::create([
        'webhook_id' => $webhook->id,
        'event' => 'employee.created',
        'payload' => ['test' => true],
        'response_status' => 200,
        'attempt' => 1,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks/{$webhook->public_id}/deliveries");

    $response->assertOk();
    expect($response->json('deliveries'))->toHaveCount(1);
});

// ── Webhook Signing ──

test('webhook generates hmac signature', function () {
    $webhook = Webhook::factory()->make(['secret' => 'test-secret']);

    $payload = '{"event":"test"}';
    $signature = $webhook->sign($payload);

    expect($signature)->toBe(hash_hmac('sha256', $payload, 'test-secret'));
});

// ── Webhook Dispatcher ──

test('dispatcher creates delivery for matching events', function () {
    $tenant = createTenant();

    $webhook = Webhook::factory()->create([
        'tenant_id' => $tenant->id,
        'events' => ['employee.created'],
    ]);

    $dispatcher = new WebhookDispatcher();
    $dispatcher->dispatch($tenant->id, 'employee.created', ['name' => 'Abebe']);

    expect(WebhookDelivery::where('webhook_id', $webhook->id)->count())->toBe(1);
});

test('dispatcher skips non-matching events', function () {
    $tenant = createTenant();

    Webhook::factory()->create([
        'tenant_id' => $tenant->id,
        'events' => ['employee.created'],
    ]);

    $dispatcher = new WebhookDispatcher();
    $dispatcher->dispatch($tenant->id, 'leave.approved', ['id' => '123']);

    expect(WebhookDelivery::count())->toBe(0);
});

// ── Accounting Integration ──

test('finance admin can view payroll journal entries', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'gross_cents' => 1000000,
        'income_tax_cents' => 150000,
        'employee_pension_cents' => 70000,
        'employer_pension_cents' => 110000,
        'net_cents' => 780000,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/accounting/journal/{$run->public_id}");

    $response->assertOk()
        ->assertJsonStructure([
            'period',
            'reference',
            'entries',
            'total_debits_cents',
            'total_credits_cents',
            'is_balanced',
        ]);

    expect($response->json('is_balanced'))->toBeTrue();
});

test('journal entries balance debits and credits', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'gross_cents' => 500000,
        'income_tax_cents' => 49750,
        'employee_pension_cents' => 35000,
        'employer_pension_cents' => 55000,
        'other_deductions_cents' => 20000,
        'net_cents' => 395250,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/accounting/journal/{$run->public_id}");

    $response->assertOk();
    $totalDebits = $response->json('total_debits_cents');
    $totalCredits = $response->json('total_credits_cents');

    expect($totalDebits)->toBe($totalCredits);
});

// ── Auth ──

test('api keys require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/api-keys')
        ->assertUnauthorized();
});

test('webhooks require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/webhooks')
        ->assertUnauthorized();
});

test('accounting requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/accounting/journal/some-id')
        ->assertUnauthorized();
});
