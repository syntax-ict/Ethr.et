<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// The owner's direction (2026-11-04): an organisation has an owner, the
// person ETHR's support calls and who alone may hand ownership on, with the
// handovers on record. There was no such field: "the first active
// administrator" stood in for it.

function ownershipUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

/** @return array{0: Tenant, 1: User, 2: User} */
function ownedOrganisation(): array
{
    $tenant = createTenant();
    $owner = createUser(['role' => UserRole::TENANT_ADMIN, 'password' => Hash::make('Owner-Pass-1'), 'display_name' => 'Selam Bekele'], $tenant);
    $next = createUser(['role' => UserRole::TENANT_ADMIN, 'display_name' => 'Dawit Alemu'], $tenant);
    $tenant->update(['owner_user_id' => $owner->id]);

    return [$tenant->fresh(), $owner, $next];
}

it('makes the person who registers the owner', function () {
    $this->postJson('/api/v1/auth/register', [
        'organization_name' => 'Abay Trading', 'subdomain' => 'abay-owner', 'admin_name' => 'Selam Bekele',
        'admin_email' => 'selam@abay-owner.example', 'admin_phone' => '0912345678',
        'password' => 'SecurePass123!', 'password_confirmation' => 'SecurePass123!', 'organization_type' => 'general',
    ])->assertCreated();

    $tenant = Tenant::query()->where('subdomain', 'abay-owner')->sole();
    expect($tenant->owner_user_id)->toBe(User::withoutGlobalScopes()->where('email', 'selam@abay-owner.example')->value('id'));
});

it('lets the owner hand ownership to another active administrator, with their password, on record', function () {
    [$tenant, $owner, $next] = ownedOrganisation();
    test()->actingAs($owner);

    $this->postJson(ownershipUrl($tenant, '/settings/ownership/transfer'), ['user_public_id' => $next->public_id, 'password' => 'wrong'])
        ->assertStatus(422);
    expect($tenant->fresh()->owner_user_id)->toBe($owner->id);

    $this->postJson(ownershipUrl($tenant, '/settings/ownership/transfer'), ['user_public_id' => $next->public_id, 'password' => 'Owner-Pass-1'])
        ->assertOk()->assertJsonPath('owner_public_id', $next->public_id);

    expect($tenant->fresh()->owner_user_id)->toBe($next->id);
    $entry = AuditLog::withoutGlobalScopes()->where('action', 'tenant.ownership_transferred')->sole();
    expect($entry->payload['from_name'])->toBe('Selam Bekele')->and($entry->payload['to_name'])->toBe('Dawit Alemu');
});

it('lets only the owner hand ownership on, and only to an active administrator', function () {
    [$tenant, $owner, $next] = ownedOrganisation();
    $employee = createUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->actingAs($next);
    $this->postJson(ownershipUrl($tenant, '/settings/ownership/transfer'), ['user_public_id' => $next->public_id, 'password' => 'x'])
        ->assertForbidden();

    test()->actingAs($owner);
    $this->postJson(ownershipUrl($tenant, '/settings/ownership/transfer'), ['user_public_id' => $employee->public_id, 'password' => 'Owner-Pass-1'])
        ->assertStatus(422);
    expect($tenant->fresh()->owner_user_id)->toBe($owner->id);
});

it('keeps the owner an active administrator until ownership moves', function () {
    [$tenant, $owner, $next] = ownedOrganisation();
    test()->actingAs($next);

    $this->patchJson(ownershipUrl($tenant, "/users/{$owner->public_id}"), ['status' => 'inactive'])
        ->assertUnprocessable()->assertJsonValidationErrors(['status' => __('user.errors.owner_transfer_first')]);
    $this->patchJson(ownershipUrl($tenant, "/users/{$owner->public_id}"), ['role' => 'hr_admin'])
        ->assertUnprocessable()->assertJsonValidationErrors(['role' => __('user.errors.owner_transfer_first')]);
    $this->deleteJson(ownershipUrl($tenant, "/users/{$owner->public_id}"))->assertStatus(422);

    expect($owner->fresh()->status)->toBe('active');
});

it('lets ETHR hand ownership on with a reason, and shows the owner, the admins and the history', function () {
    [$tenant, $owner, $next] = ownedOrganisation();
    $home = createTenant();
    test()->actingAs(createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home));

    $this->postJson(ownershipUrl($home, "/admin/tenants/{$tenant->public_id}/ownership"), [
        'user_public_id' => $next->public_id, 'reason' => 'Owner left the company; confirmed by phone.',
    ])->assertOk();

    expect($tenant->fresh()->owner_user_id)->toBe($next->id);

    $detail = $this->getJson(ownershipUrl($home, "/admin/tenants/{$tenant->public_id}"))->assertOk();
    $detail->assertJsonPath('owner.public_id', $next->public_id)
        ->assertJsonPath('ownership_history.0.to', 'Dawit Alemu')
        ->assertJsonPath('ownership_history.0.by_platform', true)
        ->assertJsonCount(2, 'admins');
});
