<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use App\Services\Auth\StartingPasswordSprayLimit;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * Everyone in a batch shares one starting password, so a guess that is wrong
 * for one account is wrong for all of them, and one try per account never
 * reaches the per-identifier lockout (10 tries on one identifier). The limit
 * here counts failed sign-ins against accounts that still have a starting
 * password, per organisation, whatever the identifier or address.
 */
function sprayFixture(string $subdomain = 'acme', int $people = 30): array
{
    $tenant = createTenant(['subdomain' => $subdomain, 'settings' => ['login_identifiers' => ['employee_code', 'email']]]);
    $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'email' => "admin@{$subdomain}.test"], $tenant);

    $employees = [];
    for ($i = 1; $i <= $people; $i++) {
        $employees[] = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => null,
            'phone' => null,
            'employee_code' => sprintf('EMP-%04d', $i),
        ]);
    }

    $password = (string) test()->postJson("http://{$subdomain}.ethr.test/api/v1/employees/starting-password", [
        'employee_ids' => array_map(fn (Employee $e) => $e->public_id, $employees),
        'current_password' => 'password',
    ])->assertCreated()->json('password');

    return [$tenant, $admin, $password];
}

function spraySignIn(string $identifier, string $password, string $subdomain = 'acme', string $ip = '10.0.0.9'): TestResponse
{
    app(CurrentTenant::class)->forget();
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    return test()->postJson("http://{$subdomain}.ethr.test/api/v1/auth/login", [
        'identifier' => $identifier,
        'password' => $password,
    ], ['REMOTE_ADDR' => $ip]);
}

/** One wrong try against each of the first $count accounts, each from its own address. */
function sprayWrongTries(int $count, string $subdomain = 'acme'): void
{
    for ($i = 1; $i <= $count; $i++) {
        spraySignIn(sprintf('EMP-%04d', $i), 'a-wrong-guess-'.$i, $subdomain, '10.0.1.'.$i)->assertStatus(422);
    }
}

beforeEach(function () {
    Notification::fake();
});

it('stops wrong tries that are spread one per account', function () {
    [, , $password] = sprayFixture();

    sprayWrongTries(StartingPasswordSprayLimit::MAX_FAILURES);

    // The right password, on an account nobody has tried, from a new address.
    spraySignIn('EMP-0025', $password, ip: '10.0.2.1')->assertStatus(422);
});

it('answers during the cooling period exactly as it answers a wrong password', function () {
    [, , $password] = sprayFixture();

    $wrong = spraySignIn('EMP-0030', 'a-wrong-guess', ip: '10.0.3.1')->assertStatus(422)->getContent();
    sprayWrongTries(StartingPasswordSprayLimit::MAX_FAILURES - 1);

    $held = spraySignIn('EMP-0025', $password, ip: '10.0.2.1')->assertStatus(422)->getContent();

    expect($held)->toBe($wrong);
});

it('tells the organisation\'s administrators once, and records it', function () {
    [, $admin] = sprayFixture();

    sprayWrongTries(StartingPasswordSprayLimit::MAX_FAILURES + 5);

    Notification::assertSentToTimes($admin, SystemAlertNotification::class, 1);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'user.starting_password_cooldown')->count())->toBe(1);
});

it('lets people in again once the cooling period is over', function () {
    [, , $password] = sprayFixture();

    sprayWrongTries(StartingPasswordSprayLimit::MAX_FAILURES);
    spraySignIn('EMP-0025', $password, ip: '10.0.2.1')->assertStatus(422);

    test()->travel(StartingPasswordSprayLimit::COOLDOWN_SECONDS + 1)->seconds();

    spraySignIn('EMP-0025', $password, ip: '10.0.2.1')->assertOk()->assertJsonPath('password_change_required', true);
});

it('does not trip below the limit', function () {
    [, , $password] = sprayFixture();

    sprayWrongTries(StartingPasswordSprayLimit::MAX_FAILURES - 1);

    spraySignIn('EMP-0025', $password, ip: '10.0.2.1')->assertOk();
    Notification::assertNothingSent();
});

it('does not count failures against accounts with a password of their own', function () {
    [$tenant, , $password] = sprayFixture();
    for ($i = 1; $i <= StartingPasswordSprayLimit::MAX_FAILURES + 2; $i++) {
        User::factory()->create(['tenant_id' => $tenant->id, 'email' => "own{$i}@acme.test", 'status' => 'active']);
        spraySignIn("own{$i}@acme.test", 'a-wrong-guess', ip: '10.0.4.'.$i)->assertStatus(422);
    }

    spraySignIn('EMP-0025', $password, ip: '10.0.2.1')->assertOk();
});

it('does not hold back an account with a password of its own during the cooling period', function () {
    [$tenant] = sprayFixture();
    User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'own@acme.test', 'status' => 'active']);

    sprayWrongTries(StartingPasswordSprayLimit::MAX_FAILURES);

    spraySignIn('own@acme.test', 'password', ip: '10.0.2.2')->assertOk();
});

it('counts per organisation', function () {
    [, , $password] = sprayFixture();
    [, , $otherPassword] = sprayFixture('other', 3);

    sprayWrongTries(StartingPasswordSprayLimit::MAX_FAILURES);

    spraySignIn('EMP-0025', $password, ip: '10.0.2.1')->assertStatus(422);
    spraySignIn('EMP-0002', $otherPassword, 'other', '10.0.2.1')->assertOk();
});

it('holds back someone who signed in once and has not changed the password yet', function () {
    [, , $password] = sprayFixture();
    spraySignIn('EMP-0025', $password, ip: '10.0.2.1')->assertOk();

    sprayWrongTries(StartingPasswordSprayLimit::MAX_FAILURES);

    spraySignIn('EMP-0025', $password, ip: '10.0.2.1')->assertStatus(422);
});
