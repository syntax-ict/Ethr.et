<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;

// The owner's request (2026-11-04): ETHR's super admin should have the phone
// number to call an organisation for support. Sign-up left it optional, an
// administrator with no employee record had nowhere to add it, and the
// console did not show it. The phone is required at sign-up and stored as
// unverified until delivery by SMS is proven and a code confirms it.

function ownerPhoneRegistration(array $overrides = []): array
{
    return [
        'organization_name' => 'Abay Trading',
        'subdomain' => 'abay-trading',
        'admin_name' => 'Selam Bekele',
        'admin_email' => 'selam@abay.example',
        'admin_phone' => '0912345678',
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
        'organization_type' => 'general',
        ...$overrides,
    ];
}

function ownerPhoneUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

it('requires the phone at sign-up, and stores it in one form, unverified', function () {
    $this->postJson('/api/v1/auth/register', ownerPhoneRegistration(['admin_phone' => '']))
        ->assertUnprocessable()->assertJsonValidationErrors('admin_phone');

    $this->postJson('/api/v1/auth/register', ownerPhoneRegistration())->assertCreated();

    $owner = User::withoutGlobalScopes()->where('email', 'selam@abay.example')->sole();
    expect($owner->phone)->toBe('+251912345678')
        ->and($owner->phone_verified_at)->toBeNull();
});

it('lets a signed-in person set their own phone, unverified', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'phone' => null], $tenant);

    $this->putJson(ownerPhoneUrl($tenant, '/auth/me/phone'), ['phone' => '0911 223 344'])
        ->assertOk()
        ->assertJsonPath('phone', '+251911223344')
        ->assertJsonPath('phone_verified', false);
    $this->putJson(ownerPhoneUrl($tenant, '/auth/me/phone'), ['phone' => '12345'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone' => __('auth.phone_invalid')]);

    expect($user->fresh()->phone)->toBe('+251911223344');
});

it('asks the owner, and only the owner, for a phone until there is one', function () {
    $tenant = createTenant();
    $owner = createUser(['role' => UserRole::TENANT_ADMIN, 'phone' => null], $tenant);
    $second = createUser(['role' => UserRole::TENANT_ADMIN, 'phone' => null], $tenant);
    $steps = fn (User $user) => collect(test()->actingAs($user)->getJson(ownerPhoneUrl($tenant, '/next-steps'))->assertOk()->json('steps'))->keyBy('id');

    expect($steps($owner)->get('owner_phone'))->not->toBeNull()
        ->and($steps($owner)->get('owner_phone')['severity'] ?? null)->toBe('blocker')
        ->and($steps($second)->has('owner_phone'))->toBeFalse();

    $owner->update(['phone' => '+251912345678']);
    expect($steps($owner)->has('owner_phone'))->toBeFalse();
});

it('shows the console the owner to call, labelled unverified, or missing', function () {
    $home = createTenant();
    test()->actingAs(createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home));
    $target = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $target->id, 'role' => UserRole::TENANT_ADMIN, 'status' => 'active',
        'display_name' => 'Selam Bekele', 'phone' => '+251912345678']);

    test()->getJson(ownerPhoneUrl($home, "/admin/tenants/{$target->public_id}"))
        ->assertOk()
        ->assertJsonPath('owner.name', 'Selam Bekele')
        ->assertJsonPath('owner.phone', '+251912345678')
        ->assertJsonPath('owner.phone_verified', false);

    $quiet = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $quiet->id, 'role' => UserRole::TENANT_ADMIN, 'status' => 'active', 'phone' => null]);

    test()->getJson(ownerPhoneUrl($home, "/admin/tenants/{$quiet->public_id}"))
        ->assertOk()
        ->assertJsonPath('owner.phone', null);
});
