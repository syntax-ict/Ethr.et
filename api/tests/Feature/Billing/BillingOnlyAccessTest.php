<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Tenant;

/*
 * A-Z review H-09 (U-H2). An organisation locked out for money, not for
 * cause (its trial has ended, or it was suspended with a bill unpaid), could
 * not sign in to see or pay that bill: every request answered 403. It now
 * reaches sign-in and billing, and nothing else.
 */

function lockedOut(array $attributes): array
{
    $tenant = Tenant::factory()->create($attributes);
    $admin = createUser(['role' => UserRole::TENANT_ADMIN, 'password' => 'correct-horse-battery'], $tenant);

    return [$tenant, $admin];
}

function billingUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

dataset('locked out for money', [
    'trial ended' => fn () => ['status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDay()],
    'suspended, bill unpaid' => fn () => ['status' => TenantStatus::SUSPENDED],
]);

function withUnpaidBill(Tenant $tenant): void
{
    if ($tenant->status === TenantStatus::SUSPENDED) {
        Invoice::factory()->create(['tenant_id' => $tenant->id, 'status' => 'overdue']);
    }
}

it('lets the organisation reach its sign-in page and say why it is limited', function (array $attributes) {
    [$tenant] = lockedOut($attributes);
    withUnpaidBill($tenant);

    test()->getJson(billingUrl($tenant, 'auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.billing_only', true);
})->with('locked out for money');

it('lets an administrator sign in and see and pay the bill', function (array $attributes) {
    [$tenant, $admin] = lockedOut($attributes);
    withUnpaidBill($tenant);

    test()->postJson(billingUrl($tenant, 'auth/login'), ['email' => $admin->email, 'password' => 'correct-horse-battery'])
        ->assertOk();

    test()->actingAs($admin)->getJson(billingUrl($tenant, 'auth/me'))
        ->assertOk()
        ->assertJsonPath('billing_only', true);

    test()->actingAs($admin)->getJson(billingUrl($tenant, 'billing/dashboard'))
        ->assertOk();

    test()->getJson(billingUrl($tenant, 'plans'))
        ->assertOk();
})->with('locked out for money');

it('keeps everything else closed', function (array $attributes) {
    [$tenant, $admin] = lockedOut($attributes);
    withUnpaidBill($tenant);

    test()->actingAs($admin)->getJson(billingUrl($tenant, 'employees'))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-inactive');

    test()->actingAs($admin)->postJson(billingUrl($tenant, 'auth/password/change'), [])
        ->assertForbidden();
})->with('locked out for money');

it('keeps billing to whoever manages it', function () {
    [$tenant] = lockedOut(['status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDay()]);
    $employee = createUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->actingAs($employee)->getJson(billingUrl($tenant, 'billing/dashboard'))
        ->assertForbidden();
});

it('stays closed to an organisation suspended with nothing owed, or cancelled', function (array $attributes, string $invoiceStatus) {
    [$tenant, $admin] = lockedOut($attributes);
    Invoice::factory()->create(['tenant_id' => $tenant->id, 'status' => $invoiceStatus]);

    test()->getJson(billingUrl($tenant, 'auth/tenant-context'))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-inactive');

    test()->actingAs($admin)->getJson(billingUrl($tenant, 'billing/dashboard'))
        ->assertForbidden();
})->with([
    'suspended, nothing owed' => [['status' => TenantStatus::SUSPENDED], 'paid'],
    'cancelled, bill unpaid' => [['status' => TenantStatus::CANCELLED], 'overdue'],
]);

it('says an organisation in good standing is not limited', function () {
    $tenant = createTenant();
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->actingAs($admin)->getJson(billingUrl($tenant, 'auth/me'))
        ->assertOk()
        ->assertJsonPath('billing_only', false);
});
