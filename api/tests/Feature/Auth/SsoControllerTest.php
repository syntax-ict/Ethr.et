<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\SsoSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sso\SsoProviderInterface;
use App\Services\Sso\SsoUser;
use Illuminate\Support\Facades\DB;

/*
 * SsoController ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â what happens AFTER an assertion has been verified: which
 * account it signs in, whether it may create one, and what it refuses.
 *
 * The SAML cryptography is pinned by SsoSamlSignatureTest and is not repeated
 * here. These tests bind a provider double that returns a chosen identity, so
 * each one is about the controller's decisions alone.
 */

/** Bind an SSO provider that is configured and yields $identity (or throws). */
function ssoControllerProvider(?SsoUser $identity, bool $configured = true): void
{
    app()->instance(SsoProviderInterface::class, new class($identity, $configured) implements SsoProviderInterface
    {
        public function __construct(private ?SsoUser $identity, private bool $configured) {}

        public function getLoginUrl(Tenant $tenant, string $relayState = ''): string
        {
            return 'https://idp.example.test/sso?RelayState='.urlencode($relayState);
        }

        public function handleCallback(Tenant $tenant, array $requestData): SsoUser
        {
            return $this->identity ?? throw new RuntimeException('SAML assertion is not covered by a valid signature');
        }

        public function getMetadataXml(Tenant $tenant): string
        {
            return '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"/>';
        }

        public function isConfigured(Tenant $tenant): bool
        {
            return $this->configured;
        }
    });
}

function ssoControllerTenant(array $settings = [], array $tenantAttributes = []): Tenant
{
    $tenant = createTenant($tenantAttributes);

    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.example.test',
        'idp_sso_url' => 'https://idp.example.test/sso',
        'idp_certificate' => 'unused-by-the-double',
        ...$settings,
    ]);

    return $tenant;
}

function ssoControllerIdentity(string $email = 'jane@acme.test'): SsoUser
{
    return new SsoUser(nameId: $email, email: $email, firstName: 'Jane', lastName: 'Doe');
}

function ssoControllerAcs(Tenant $tenant): string
{
    return "/api/v1/sso/saml/{$tenant->subdomain}/acs";
}

function ssoControllerTokenCount(User $user): int
{
    return DB::table('personal_access_tokens')
        ->where('tokenable_type', User::class)
        ->where('tokenable_id', $user->id)
        ->count();
}

// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Signing in ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

it('signs in the matching user of this tenant and records it', function () {
    $tenant = ssoControllerTenant();
    $user = createUser(['email' => 'jane@acme.test', 'status' => 'active'], $tenant);
    ssoControllerProvider(ssoControllerIdentity());

    $response = $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x', 'RelayState' => '/leave'])
        ->assertOk();

    expect($response->json('access_token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('mfa_required'))->toBeFalse()
        ->and($response->json('relay_state'))->toBe('/leave')
        ->and(ssoControllerTokenCount($user))->toBe(1);

    $this->assertDatabaseHas('audit_log', ['action' => 'sso.login', 'tenant_id' => $tenant->id]);
});

it('signs in this tenant\'s account when the same email exists in another tenant too', function () {
    $other = createTenant();
    $theirs = createUser(['email' => 'jane@acme.test', 'status' => 'active'], $other);

    $tenant = ssoControllerTenant();
    $mine = createUser(['email' => 'jane@acme.test', 'status' => 'active'], $tenant);
    ssoControllerProvider(ssoControllerIdentity());

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])->assertOk();

    expect(ssoControllerTokenCount($mine))->toBe(1)
        ->and(ssoControllerTokenCount($theirs))->toBe(0);
});

it('never signs in another tenant\'s user, and says only that there is no account', function () {
    $other = createTenant();
    $theirs = createUser(['email' => 'jane@acme.test', 'status' => 'active'], $other);

    $tenant = ssoControllerTenant(['auto_provision' => false]);
    ssoControllerProvider(ssoControllerIdentity());

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/sso-no-account');

    expect(ssoControllerTokenCount($theirs))->toBe(0);
});

it('still demands the second factor from a user who enabled MFA', function () {
    $tenant = ssoControllerTenant();
    createUser(['email' => 'jane@acme.test', 'status' => 'active', 'mfa_enabled' => true], $tenant);
    ssoControllerProvider(ssoControllerIdentity());

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])
        ->assertOk()
        ->assertJsonPath('mfa_required', true);
});

it('refuses an inactive user and records the attempt', function () {
    $tenant = ssoControllerTenant();
    $user = createUser(['email' => 'jane@acme.test', 'status' => 'inactive'], $tenant);
    ssoControllerProvider(ssoControllerIdentity());

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/account-inactive');

    expect(ssoControllerTokenCount($user))->toBe(0);
    $this->assertDatabaseHas('audit_log', ['action' => 'sso.login_blocked']);
});

it('answers 401 and records why when the provider rejects the assertion', function () {
    $tenant = ssoControllerTenant(['auto_provision' => true]);
    ssoControllerProvider(null);

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'forged'])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://ethr.et/errors/sso-failed');

    $this->assertDatabaseHas('audit_log', ['action' => 'sso.callback_failed']);
    expect(DB::table('users')->where('tenant_id', $tenant->id)->count())->toBe(0);
});

// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Auto-provisioning ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

it('creates an employee and a login, in this tenant, when auto-provisioning is on', function () {
    // SsoController.php:152 writes Employee.status = 'active'. EmployeeStatus has
    // no such case (hired, probation, confirmed, ...), so the enum cast throws a
    // ValueError and the first SSO sign-in of every new user returns 500.
    // Auto-provisioning has never worked. ScimUserController uses HIRED.
    $tenant = ssoControllerTenant(['auto_provision' => true, 'default_role' => 'supervisor']);
    ssoControllerProvider(ssoControllerIdentity('new.hire@acme.test'));

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])->assertOk();

    $user = User::where('email', 'new.hire@acme.test')->firstOrFail();

    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->role)->toBe(UserRole::SUPERVISOR)
        ->and($user->status)->toBe('active')
        ->and($user->employee)->not->toBeNull()
        ->and($user->employee->tenant_id)->toBe($tenant->id)
        ->and($user->employee->name)->toBe('Jane Doe')
        ->and(ssoControllerTokenCount($user))->toBe(1);

    $this->assertDatabaseHas('audit_log', ['action' => 'sso.user_provisioned', 'tenant_id' => $tenant->id]);
})->todo(note: 'DEFECT: SsoController.php:152 creates the Employee with status \'active\', not an EmployeeStatus case; every auto-provisioned first sign-in is a 500 (ValueError)');

it('falls back to the employee role when the stored default role is not a role', function () {
    $tenant = ssoControllerTenant(['auto_provision' => true, 'default_role' => 'overlord']);
    ssoControllerProvider(ssoControllerIdentity('new.hire@acme.test'));

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])->assertOk();

    expect(User::where('email', 'new.hire@acme.test')->firstOrFail()->role)->toBe(UserRole::EMPLOYEE);
})->todo(note: 'DEFECT: same root cause as above - SsoController.php:152 status \'active\' is not an EmployeeStatus, so auto-provisioning 500s before the role is ever applied');

it('creates nothing when auto-provisioning is off', function () {
    $tenant = ssoControllerTenant(['auto_provision' => false]);
    ssoControllerProvider(ssoControllerIdentity('stranger@acme.test'));

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])->assertForbidden();

    expect(DB::table('users')->where('email', 'stranger@acme.test')->exists())->toBeFalse()
        ->and(DB::table('employees')->where('email', 'stranger@acme.test')->exists())->toBeFalse();
});

it('does not auto-provision past the tenant\'s plan employee limit', function () {
    // EmployeeController::store and the import commit call
    // PlanLimitService::assertCanAdd('employees'); SsoController::
    // findOrProvisionUser() (lines 143-174) creates an Employee without it,
    // so any IdP user who signs in once becomes a billable seat beyond the
    // plan. ScimUserController::store has the same gap ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â see ScimSecurityTest.
    $tenant = ssoControllerTenant(['auto_provision' => true]);
    $plan = Plan::factory()->create(['max_employees' => 1]);
    Subscription::factory()->for($tenant)->create(['plan_id' => $plan->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id]);
    ssoControllerProvider(ssoControllerIdentity('one.too.many@acme.test'));

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])->assertForbidden();

    expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(1);
})->todo(note: 'DEFECT: SsoController::findOrProvisionUser (lines 143-174) creates employees without PlanLimitService::assertCanAdd, bypassing the plan seat cap');

// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Tenant state and configuration ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

it('refuses to sign anyone in to a suspended tenant, and provisions nothing there', function () {
    // The ACS URL is on the API host (SamlProvider::getAcsUrl uses APP_URL),
    // not the tenant subdomain, so ResolveTenant's isActive() refusal never
    // runs for it. ScimAuth had the same shape and now checks the tenant
    // itself (ScimAuth.php:35-42); SsoController::callback does not.
    $tenant = ssoControllerTenant(['auto_provision' => true], ['status' => TenantStatus::SUSPENDED]);
    $existing = createUser(['email' => 'jane@acme.test', 'status' => 'active'], $tenant);
    ssoControllerProvider(ssoControllerIdentity());

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])->assertForbidden();
    expect(ssoControllerTokenCount($existing))->toBe(0);

    ssoControllerProvider(ssoControllerIdentity('new.hire@acme.test'));
    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])->assertForbidden();
    expect(DB::table('users')->where('email', 'new.hire@acme.test')->exists())->toBeFalse();
})->todo(note: 'DEFECT: SsoController::callback (line 52) never checks Tenant::isActive(); a suspended tenant\'s users get sessions and auto-provisioning still writes into it');

it('answers 422 at both ends when SSO is not configured', function () {
    $tenant = ssoControllerTenant();
    ssoControllerProvider(ssoControllerIdentity(), configured: false);

    $this->getJson("/api/v1/sso/saml/{$tenant->subdomain}/initiate")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/sso-not-configured');

    $this->postJson(ssoControllerAcs($tenant), ['SAMLResponse' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/sso-not-configured');
});

it('answers 404 for a subdomain that is not a tenant, on every SSO route', function () {
    ssoControllerProvider(ssoControllerIdentity());

    $this->getJson('/api/v1/sso/saml/no-such-tenant/initiate')->assertNotFound();
    $this->postJson('/api/v1/sso/saml/no-such-tenant/acs', ['SAMLResponse' => 'x'])->assertNotFound();
    $this->get('/api/v1/sso/saml/no-such-tenant/metadata')->assertNotFound();
});

it('passes the relay state through to the IdP, defaulting to the dashboard', function () {
    $tenant = ssoControllerTenant();
    ssoControllerProvider(ssoControllerIdentity());

    expect($this->getJson("/api/v1/sso/saml/{$tenant->subdomain}/initiate")->json('redirect_url'))
        ->toEndWith('RelayState='.urlencode('/dashboard'))
        ->and($this->getJson("/api/v1/sso/saml/{$tenant->subdomain}/initiate?relay_state=/payslips")->json('redirect_url'))
        ->toEndWith('RelayState='.urlencode('/payslips'));
});
