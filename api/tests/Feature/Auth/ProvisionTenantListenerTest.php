<?php

declare(strict_types=1);

use App\Events\TenantCreated;
use App\Listeners\ProvisionTenant;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * ProvisionTenant is the only listener on TenantCreated, and TenantCreated is
 * dispatched from exactly one place: AuthService::registerTenant(), inside the
 * sign-up transaction. It had no test, so nothing said what a new tenant is
 * supposed to come out of sign-up with.
 */

function provisionTenantRegistrationPayload(string $subdomain): array
{
    return [
        'organization_name' => 'Provision Test Ltd',
        'subdomain' => $subdomain,
        'admin_name' => 'Provision Admin',
        'admin_email' => "admin@{$subdomain}.test",
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
        'organization_type' => 'general',
    ];
}

it('gives a freshly registered tenant Ethiopian defaults', function () {
    $this->postJson('/api/v1/auth/register', provisionTenantRegistrationPayload('provisioned'))
        ->assertCreated();

    $tenant = Tenant::where('subdomain', 'provisioned')->firstOrFail();

    expect($tenant->settings)->toMatchArray([
        'timezone' => 'Africa/Addis_Ababa',
        'locale' => 'en',
        'calendar' => 'ethiopian',
    ]);
});

it('keeps settings the tenant already had, adding the defaults beside them', function () {
    $tenant = createTenant(['settings' => ['brand_colour' => '#0a7']]);
    $admin = createUser([], $tenant);

    (new ProvisionTenant)->handle(new TenantCreated($tenant, $admin));

    expect($tenant->fresh()->settings)->toMatchArray([
        'brand_colour' => '#0a7',
        'timezone' => 'Africa/Addis_Ababa',
        'calendar' => 'ethiopian',
    ]);
});

it('touches only the tenant it was given', function () {
    $other = createTenant(['settings' => ['calendar' => 'gregorian']]);
    $tenant = createTenant();
    $admin = createUser([], $tenant);

    (new ProvisionTenant)->handle(new TenantCreated($tenant, $admin));

    expect($other->fresh()->settings)->toBe(['calendar' => 'gregorian']);
});

it('is idempotent, so a second delivery changes nothing', function () {
    $tenant = createTenant();
    $admin = createUser([], $tenant);
    $event = new TenantCreated($tenant, $admin);

    (new ProvisionTenant)->handle($event);
    $first = $tenant->fresh()->settings;

    (new ProvisionTenant)->handle($event);

    expect($tenant->fresh()->settings)->toBe($first);
});

it('runs inside the sign-up transaction, so a failed sign-up provisions nothing', function () {
    // The listener is synchronous and registerTenant() dispatches inside
    // DB::transaction(). If provisioning throws, the tenant, its admin and the
    // registration audit row must all roll back together — a half-created
    // tenant with no admin is unrecoverable through the UI.
    Event::listen(TenantCreated::class, function () {
        throw new RuntimeException('provisioning failed');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/api/v1/auth/register', provisionTenantRegistrationPayload('halfmade')))
        ->toThrow(RuntimeException::class, 'provisioning failed');

    // Raw table reads: the question is what reached the database at all,
    // whichever tenant (if any) is resolved when the assertion runs.
    expect(DB::table('tenants')->where('subdomain', 'halfmade')->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', 'admin@halfmade.test')->exists())->toBeFalse()
        ->and(DB::table('audit_log')->where('action', 'tenant.registered')->exists())->toBeFalse();
});
