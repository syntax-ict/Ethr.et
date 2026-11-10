<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Identity\EmployeeIntake;
use App\Services\Sso\SsoUser;
use Illuminate\Testing\TestResponse;
use Tests\Support\IntakeFixtures;

/*
 * A directory (SCIM, SSO) sending someone the organisation already has.
 *
 * Both called `Employee::create()` after looking only at `users.email`. HR
 * adds people first and the directory is connected later, so the usual case
 * was a person with an employee record and no login, and each of them got a
 * second employee record on the day the directory was switched on.
 *
 * The login now goes on the record that is there, when there is exactly one
 * and it is safe to; anything less certain is refused, because nobody is there
 * to ask (EmployeeIntake::admitUnattended).
 */

function directoryPush(Tenant $tenant, string $email, array $body = []): TestResponse
{
    return test()->postJson('/api/v1/scim/v2/Users', [
        'userName' => $email,
        'name' => ['givenName' => 'Abebe', 'familyName' => 'Kebede'],
        'emails' => [['value' => $email, 'type' => 'work', 'primary' => true]],
        'active' => true,
        ...$body,
    ], ['Authorization' => 'Bearer '.IntakeFixtures::scimToken($tenant)]);
}

function directoryEmployee(Tenant $tenant, array $attributes = []): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Abebe Kebede',
        'email' => 'abebe@example.et',
        'phone' => '+251911223344',
        ...$attributes,
    ]);
}

function directoryRecords(Tenant $tenant): int
{
    return Employee::withTrashed()->where('tenant_id', $tenant->id)->count();
}

describe('SCIM', function () {
    it('puts the login on the employee HR already added', function () {
        $tenant = createTenant();
        $abebe = directoryEmployee($tenant);

        directoryPush($tenant, 'Abebe@Example.et')->assertCreated();

        $user = User::where('tenant_id', $tenant->id)->where('email', 'Abebe@Example.et')->firstOrFail();

        expect(directoryRecords($tenant))->toBe(1)
            ->and($user->employee_id)->toBe($abebe->id)
            // The record is HR's: its status and where it came from are untouched.
            ->and($abebe->fresh()->status)->toBe(EmployeeStatus::CONFIRMED)
            ->and($abebe->fresh()->created_via)->toBeNull();

        $audit = AuditLog::where('action', 'scim.user_created')->where('auditable_id', $user->id)->firstOrFail();
        expect($audit->payload['employee'])->toBe('existing');
    });

    it('uses no seat when it attaches, and one when it creates', function () {
        $tenant = createTenant();
        directoryEmployee($tenant);
        Subscription::factory()->for($tenant)->create(['plan_id' => Plan::factory()->create(['max_employees' => 1])->id]);

        // The plan is full, and this person is already counted.
        directoryPush($tenant, 'abebe@example.et')->assertCreated();

        // Someone new is one more than the plan allows.
        directoryPush($tenant, 'sara@example.et', ['name' => ['givenName' => 'Sara', 'familyName' => 'Tesfaye']])
            ->assertForbidden();

        expect(directoryRecords($tenant))->toBe(1);
    });

    it('creates someone nobody knows, and records that SCIM made the record', function () {
        $tenant = createTenant();
        directoryEmployee($tenant);

        directoryPush($tenant, 'sara@example.et', ['name' => ['givenName' => 'Sara', 'familyName' => 'Tesfaye']])
            ->assertCreated();

        expect(directoryRecords($tenant))->toBe(2)
            ->and(Employee::where('tenant_id', $tenant->id)->where('email', 'sara@example.et')->value('created_via'))
            ->toBe(EmployeeIntake::VIA_SCIM);
    });

    it('puts the login on an employee with no e-mail when the phone and name are theirs', function () {
        $tenant = createTenant();
        $abebe = directoryEmployee($tenant, ['email' => null]);

        directoryPush($tenant, 'abebe@example.et', ['phoneNumbers' => [['value' => '0911223344', 'type' => 'work']]])
            ->assertCreated();

        expect(directoryRecords($tenant))->toBe(1)
            ->and(User::where('tenant_id', $tenant->id)->where('email', 'abebe@example.et')->value('employee_id'))->toBe($abebe->id);
    });

    it('refuses when it cannot be sure, and makes nothing', function (Closure $arrange) {
        $tenant = createTenant();
        $arrange($tenant);
        $before = directoryRecords($tenant);
        $logins = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();

        $response = directoryPush($tenant, 'abebe@example.et', ['phoneNumbers' => [['value' => '0911223344', 'type' => 'work']]]);

        $response->assertStatus(409);
        expect($response->json('scimType'))->toBe('uniqueness')
            ->and($response->json('schemas.0'))->toBe('urn:ietf:params:scim:api:messages:2.0:Error')
            ->and(directoryRecords($tenant))->toBe($before)
            // The SCIM token's own admin is the one login the push adds to.
            ->and(User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe($logins + 1)
            ->and(User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('email', 'abebe@example.et')->exists())->toBeFalse()
            ->and(AuditLog::where('action', 'scim.user_refused')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
    })->with([
        'two employees share the address' => [function (Tenant $tenant): void {
            directoryEmployee($tenant, ['phone' => '+251922000001']);
            directoryEmployee($tenant, ['name' => 'Abebe Kebede Alemu', 'phone' => '+251922000002']);
        }],
        'the employee has left' => [function (Tenant $tenant): void {
            directoryEmployee($tenant, ['status' => EmployeeStatus::TERMINATED]);
        }],
        'the employee already signs in under another address' => [function (Tenant $tenant): void {
            $abebe = directoryEmployee($tenant);
            User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $abebe->id, 'email' => 'a.kebede@example.et', 'role' => UserRole::EMPLOYEE]);
        }],
        'the phone and name are theirs but the record holds another address' => [function (Tenant $tenant): void {
            directoryEmployee($tenant, ['email' => 'personal@example.et']);
        }],
        'only the phone is shared' => [function (Tenant $tenant): void {
            directoryEmployee($tenant, ['name' => 'Sara Tesfaye', 'email' => null]);
        }],
    ]);

    it('does not let a deleted record block the directory', function () {
        $tenant = createTenant();
        directoryEmployee($tenant)->delete();

        directoryPush($tenant, 'abebe@example.et')->assertCreated();

        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(directoryRecords($tenant))->toBe(2);
    });
});

describe('SSO auto-provisioning', function () {
    it('puts the first sign-in on the employee HR already added', function () {
        $tenant = createTenant();
        $abebe = directoryEmployee($tenant);
        $acs = IntakeFixtures::ssoSignsIn($tenant, new SsoUser(nameId: 'abebe@example.et', email: 'abebe@example.et', firstName: 'Abebe', lastName: 'Kebede'));

        // The callback arrives from the identity provider with nobody signed
        // in and no organisation resolved; the controller resolves it.
        app(CurrentTenant::class)->forget();
        $response = $this->post($acs, ['SAMLResponse' => 'x'])->assertStatus(303);
        expect((string) $response->headers->get('Location'))->not->toContain('error=');

        $user = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('email', 'abebe@example.et')->firstOrFail();

        expect($user->employee_id)->toBe($abebe->id)
            ->and(directoryRecords($tenant))->toBe(1);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'sso.user_provisioned')->where('auditable_id', $user->id)->firstOrFail();
        expect($audit->payload['employee'])->toBe('existing');
    });

    it('creates someone nobody knows, and records that SSO made the record', function () {
        $tenant = createTenant();
        directoryEmployee($tenant);
        $acs = IntakeFixtures::ssoSignsIn($tenant, new SsoUser(nameId: 'sara@example.et', email: 'sara@example.et', firstName: 'Sara', lastName: 'Tesfaye'));

        app(CurrentTenant::class)->forget();
        $this->post($acs, ['SAMLResponse' => 'x'])->assertStatus(303);

        expect(directoryRecords($tenant))->toBe(2)
            ->and(Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('email', 'sara@example.et')->value('created_via'))
            ->toBe(EmployeeIntake::VIA_SSO);
    });

    it('signs nobody in, and makes nothing, when the person may be someone who left', function () {
        $tenant = createTenant();
        directoryEmployee($tenant, ['status' => EmployeeStatus::RESIGNED]);
        $acs = IntakeFixtures::ssoSignsIn($tenant, new SsoUser(nameId: 'abebe@example.et', email: 'abebe@example.et', firstName: 'Abebe', lastName: 'Kebede'));

        app(CurrentTenant::class)->forget();
        $response = $this->post($acs, ['SAMLResponse' => 'x'])->assertStatus(303);

        expect((string) $response->headers->get('Location'))->toContain('error=no_account')
            ->and(directoryRecords($tenant))->toBe(1)
            ->and(User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0)
            ->and(AuditLog::withoutGlobalScopes()->where('action', 'sso.provision_refused')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
    });
});
