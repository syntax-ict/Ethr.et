<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Middleware\RequirePasswordChange;
use App\Jobs\CleanupExpiredDataJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Services\Auth\ImpersonationToken;
use App\Services\CurrentTenant;
use App\Support\PasswordTokens;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;

/**
 * One starting password for a batch of employees (owner decision 2026-11-10,
 * docs/decisions/OWNER-DECISION-MASS-ACTIVATION.md).
 *
 * HR gives people with no email one system-generated password; each signs in
 * with their employee number and is held at the change-password screen until
 * they choose their own. Unused after 72 hours, the account goes back to
 * `invited`.
 */
const SP_HOST = 'http://acme.ethr.test/api/v1';

/**
 * An organisation that signs in by employee number, an HR admin signed in, and
 * three employees with no email and no phone.
 *
 * @return array{0: Tenant, 1: User, 2: list<Employee>}
 */
function startingPasswordFixture(array $settings = ['login_identifiers' => ['employee_code', 'email']], int $people = 3): array
{
    $tenant = createTenant(['subdomain' => 'acme', 'settings' => $settings]);
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employees = [];
    for ($i = 1; $i <= $people; $i++) {
        $employees[] = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => null,
            'phone' => null,
            'employee_code' => sprintf('EMP-%04d', $i),
        ]);
    }

    return [$tenant, $hr, $employees];
}

/** @param  list<Employee>  $employees */
function issueStartingPassword(array $employees, array $overrides = []): TestResponse
{
    return test()->postJson(SP_HOST.'/employees/starting-password', [
        'employee_ids' => array_map(fn (Employee $e) => $e->public_id, $employees),
        'current_password' => 'password',
        ...$overrides,
    ]);
}

/** Leave the HR session behind: the next request is a visitor's. */
function spVisitor(): void
{
    app(CurrentTenant::class)->forget();
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
}

function spSignIn(string $employeeCode, string $password): TestResponse
{
    spVisitor();

    return test()->postJson(SP_HOST.'/auth/login', [
        'identifier' => $employeeCode,
        'password' => $password,
    ]);
}

function spUser(Employee $employee): User
{
    return User::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
}

describe('issuing a starting password', function () {
    it('gives each person an active login with one shared password, returned once and never cached', function () {
        [, , $employees] = startingPasswordFixture();

        $response = issueStartingPassword($employees)
            ->assertCreated()
            ->assertJsonPath('issued', 3)
            ->assertJsonCount(3, 'people')
            ->assertJsonPath('people.0.employee_code', 'EMP-0001')
            ->assertJsonPath('people.0.name', $employees[0]->name)
            ->assertJsonPath('skipped.active', 0);

        expect($response->headers->get('Cache-Control'))->toContain('no-store');

        $password = (string) $response->json('password');
        expect(mb_strlen($password))->toBeGreaterThanOrEqual(12);

        $users = collect($employees)->map(fn (Employee $e) => spUser($e));
        foreach ($users as $user) {
            expect($user->status)->toBe('active')
                ->and($user->role)->toBe(UserRole::EMPLOYEE)
                ->and($user->email)->toBeNull()
                ->and($user->password_change_required_at)->not->toBeNull()
                ->and(Hash::check($password, $user->password))->toBeTrue();

            $hours = now()->diffInHours($user->starting_password_expires_at, false);
            expect($hours)->toBeGreaterThan(71.9)->toBeLessThanOrEqual(72.0);
        }

        // Hashed once for the batch: 200 bcrypts at cost 12 do not fit in the
        // host's 30 seconds.
        expect($users->map(fn (User $u) => $u->getRawOriginal('password'))->unique())->toHaveCount(1);
        expect($employees[0]->refresh()->user_id)->toBe($users[0]->id);
    });

    it('generates a password that satisfies a strict organisation policy', function () {
        [, , $employees] = startingPasswordFixture([
            'login_identifiers' => ['employee_code'],
            'password_policy' => [
                'min_length' => 16,
                'require_uppercase' => true,
                'require_lowercase' => true,
                'require_number' => true,
                'require_symbol' => true,
            ],
        ]);

        $password = (string) issueStartingPassword($employees)->assertCreated()->json('password');

        expect(mb_strlen($password))->toBeGreaterThanOrEqual(16);
        expect(Validator::make(['password' => $password], ['password' => [new PasswordPolicy]])->passes())->toBeTrue();
    });

    it('records one audit entry for the batch, with a count and never the password', function () {
        [, , $employees] = startingPasswordFixture();

        $password = (string) issueStartingPassword($employees)->assertCreated()->json('password');

        $entries = AuditLog::query()->where('action', 'user.starting_password_issued')->get();
        expect($entries)->toHaveCount(1)
            ->and($entries->first()->payload['issued'] ?? null)->toBe(3);

        expect(AuditLog::query()->get()->toJson())->not->toContain($password);
    });

    it('cannot be read back after the first response', function () {
        [, , $employees] = startingPasswordFixture();

        $password = (string) issueStartingPassword($employees)->assertCreated()->json('password');

        $pages = [
            test()->getJson(SP_HOST.'/employees/'.$employees[0]->public_id)->assertOk()->getContent(),
            test()->getJson(SP_HOST.'/users')->assertOk()->getContent(),
            test()->getJson(SP_HOST.'/employees/'.$employees[0]->public_id.'/activation-code')->assertOk()->getContent(),
        ];

        foreach ($pages as $page) {
            expect($page)->not->toContain($password);
        }

        expect(spUser($employees[0])->getRawOriginal('password'))->not->toBe($password);
    });

    it('activates an invited login that was never used', function () {
        [$tenant, , $employees] = startingPasswordFixture();
        $invited = createUser(['employee_id' => $employees[0]->id, 'email' => null, 'status' => 'invited', 'role' => UserRole::EMPLOYEE], $tenant);
        $employees[0]->update(['user_id' => $invited->id]);

        $password = (string) issueStartingPassword([$employees[0]])->assertCreated()->assertJsonPath('issued', 1)->json('password');

        $invited->refresh();
        expect($invited->status)->toBe('active')
            ->and(Hash::check($password, $invited->password))->toBeTrue()
            ->and(User::query()->where('employee_id', $employees[0]->id)->count())->toBe(1);
    });

    it('never touches an account that is already active', function () {
        [$tenant, , $employees] = startingPasswordFixture();
        $active = createUser(['employee_id' => $employees[0]->id, 'email' => null, 'status' => 'active', 'role' => UserRole::EMPLOYEE], $tenant);
        $employees[0]->update(['user_id' => $active->id]);
        $before = $active->getRawOriginal('password');

        issueStartingPassword($employees)
            ->assertCreated()
            ->assertJsonPath('issued', 2)
            ->assertJsonPath('skipped.active', 1);

        $active->refresh();
        expect($active->getRawOriginal('password'))->toBe($before)
            ->and($active->password_change_required_at)->toBeNull()
            ->and($active->starting_password_expires_at)->toBeNull();
    });

    it('never touches a login above the employee role', function () {
        [$tenant, , $employees] = startingPasswordFixture();
        $supervisor = createUser(['employee_id' => $employees[0]->id, 'email' => null, 'status' => 'invited', 'role' => UserRole::SUPERVISOR], $tenant);
        $employees[0]->update(['user_id' => $supervisor->id]);

        issueStartingPassword($employees)
            ->assertCreated()
            ->assertJsonPath('issued', 2)
            ->assertJsonPath('skipped.role', 1);

        expect($supervisor->refresh()->status)->toBe('invited')
            ->and($supervisor->password_change_required_at)->toBeNull();
    });

    it('leaves out someone an invitation can reach', function () {
        [, , $employees] = startingPasswordFixture();
        $employees[0]->update(['email' => 'reachable@acme.test']);

        issueStartingPassword($employees)
            ->assertCreated()
            ->assertJsonPath('issued', 2)
            ->assertJsonPath('skipped.reachable', 1);

        expect(User::query()->where('employee_id', $employees[0]->id)->exists())->toBeFalse();
    });

    it('is refused, with no password made, when nobody in the batch can be given one', function () {
        [$tenant, , $employees] = startingPasswordFixture(people: 1);
        $active = createUser(['employee_id' => $employees[0]->id, 'email' => null, 'status' => 'active', 'role' => UserRole::EMPLOYEE], $tenant);
        $employees[0]->update(['user_id' => $active->id]);

        $response = issueStartingPassword($employees)
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://ethr.et/errors/starting-password-refused')
            ->assertJsonPath('reason', 'nobody');

        expect($response->json('password'))->toBeNull();
        expect(AuditLog::query()->where('action', 'user.starting_password_issued')->exists())->toBeFalse();
    });

    it('is refused when the organisation does not sign in by employee number', function () {
        [, , $employees] = startingPasswordFixture(['login_identifiers' => ['email']]);

        issueStartingPassword($employees)
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://ethr.et/errors/starting-password-refused')
            ->assertJsonPath('reason', 'identifier');

        expect(User::query()->where('role', UserRole::EMPLOYEE)->count())->toBe(0);
    });

    it('requires the HR password, and the authenticator code when HR has two-factor on', function () {
        [, $hr, $employees] = startingPasswordFixture();

        issueStartingPassword($employees, ['current_password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        test()->postJson(SP_HOST.'/employees/starting-password', ['employee_ids' => [$employees[0]->public_id]])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $hr->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        issueStartingPassword($employees)->assertStatus(422)->assertJsonValidationErrors('mfa_code');
        issueStartingPassword($employees, ['mfa_code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('mfa_code');

        expect(User::query()->where('role', UserRole::EMPLOYEE)->count())->toBe(0);
    });

    it('is forbidden without the permission', function () {
        [$tenant, , $employees] = startingPasswordFixture();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        issueStartingPassword($employees)->assertForbidden();
    });

    it('answers another organisation\'s employee exactly as an id that matches nobody', function () {
        [, , $employees] = startingPasswordFixture();
        $other = Tenant::factory()->create(['subdomain' => 'other', 'settings' => ['login_identifiers' => ['employee_code']]]);
        $theirs = Employee::withoutEvents(fn () => Employee::factory()->create(['tenant_id' => $other->id, 'email' => null, 'phone' => null]));

        $foreign = test()->postJson(SP_HOST.'/employees/starting-password', [
            'employee_ids' => [$employees[0]->public_id, $theirs->public_id],
            'current_password' => 'password',
        ])->assertStatus(422)->json('errors.employee_ids');

        $missing = test()->postJson(SP_HOST.'/employees/starting-password', [
            'employee_ids' => [$employees[0]->public_id, str_repeat('Z', 26)],
            'current_password' => 'password',
        ])->assertStatus(422)->json('errors.employee_ids');

        expect($foreign)->toBe($missing)
            ->and(User::withoutGlobalScopes()->where('employee_id', $theirs->id)->exists())->toBeFalse()
            ->and(User::query()->where('employee_id', $employees[0]->id)->exists())->toBeFalse();
    });

    it('takes at most 200 people in one batch', function () {
        startingPasswordFixture(people: 0);

        test()->postJson(SP_HOST.'/employees/starting-password', [
            'employee_ids' => array_map(fn (int $i) => str_pad((string) $i, 26, 'A', STR_PAD_LEFT), range(1, 201)),
            'current_password' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('employee_ids');
    });
});

describe('the first sign-in', function () {
    it('signs in by employee number and is held until the password is changed', function () {
        [, , $employees] = startingPasswordFixture();
        $password = (string) issueStartingPassword($employees)->json('password');

        $token = (string) spSignIn('EMP-0002', $password)
            ->assertOk()
            ->assertJsonPath('password_change_required', true)
            ->json('access_token');

        foreach (['auth/me', 'employees', 'dashboard/department', 'auth/sessions', 'profile'] as $path) {
            test()->withToken($token)->getJson(SP_HOST.'/'.$path)
                ->assertForbidden()
                ->assertJsonPath('type', RequirePasswordChange::PROBLEM_TYPE);
        }

        test()->withToken($token)->putJson(SP_HOST.'/auth/me/phone', ['phone' => '+251911000000'])
            ->assertForbidden()
            ->assertJsonPath('type', RequirePasswordChange::PROBLEM_TYPE);
    });

    it('lets the person change the password, which lifts the hold', function () {
        [, , $employees] = startingPasswordFixture();
        $password = (string) issueStartingPassword($employees)->json('password');
        $token = (string) spSignIn('EMP-0002', $password)->json('access_token');

        test()->withToken($token)->postJson(SP_HOST.'/auth/password/change', [
            'current_password' => $password,
            'password' => 'my-own-secret-42',
            'password_confirmation' => 'my-own-secret-42',
        ])->assertOk();

        $user = spUser($employees[1]);
        expect($user->password_change_required_at)->toBeNull()
            ->and($user->starting_password_expires_at)->toBeNull()
            ->and($user->activated_at)->not->toBeNull()
            ->and($user->status)->toBe('active');

        test()->withToken($token)->getJson(SP_HOST.'/auth/me')->assertOk();

        spSignIn('EMP-0002', $password)->assertStatus(422);
        spSignIn('EMP-0002', 'my-own-secret-42')->assertOk()->assertJsonPath('password_change_required', false);

        // The colleagues in the batch are still held.
        expect(spUser($employees[0])->password_change_required_at)->not->toBeNull();
    });

    it('does not accept the starting password as the new one', function () {
        [, , $employees] = startingPasswordFixture();
        $password = (string) issueStartingPassword($employees)->json('password');
        $token = (string) spSignIn('EMP-0001', $password)->json('access_token');

        test()->withToken($token)->postJson(SP_HOST.'/auth/password/change', [
            'current_password' => $password,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        expect(spUser($employees[0])->password_change_required_at)->not->toBeNull();
    });

    it('lets the person sign out and keep the session alive while held', function () {
        [, , $employees] = startingPasswordFixture();
        $password = (string) issueStartingPassword($employees)->json('password');
        $token = (string) spSignIn('EMP-0001', $password)->json('access_token');

        $fresh = (string) test()->withToken($token)->postJson(SP_HOST.'/auth/refresh')->assertOk()->json('access_token');

        app('auth')->forgetGuards();
        test()->withToken($fresh)->postJson(SP_HOST.'/auth/logout')->assertSuccessful();
    });

    it('does not confine an impersonation session', function () {
        [, , $employees] = startingPasswordFixture();
        issueStartingPassword($employees)->assertCreated();
        $user = spUser($employees[0]);
        spVisitor();

        $token = $user->createToken(ImpersonationToken::nameFor(999), ['*', ImpersonationToken::ABILITY], now()->addMinutes(30))->plainTextToken;

        test()->withToken($token)->getJson(SP_HOST.'/auth/me')->assertOk();
    });

    it('says nothing is owed for an ordinary account', function () {
        $tenant = createTenant(['subdomain' => 'acme']);
        $user = createUser(['email' => 'plain@acme.test', 'status' => 'active'], $tenant);
        spVisitor();

        $token = (string) test()->postJson(SP_HOST.'/auth/login', ['email' => 'plain@acme.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('password_change_required', false)
            ->json('access_token');

        test()->withToken($token)->getJson(SP_HOST.'/auth/me')->assertOk();
        expect($user->refresh()->password_change_required_at)->toBeNull();
    });
});

describe('the other ways a password is set', function () {
    it('clears the hold when an in-person code is redeemed', function () {
        [, , $employees] = startingPasswordFixture();
        issueStartingPassword($employees)->assertCreated();

        $code = str_replace('-', '', (string) test()->postJson(SP_HOST.'/employees/'.$employees[0]->public_id.'/activation-code', [
            'attested' => true,
            'document_type' => 'kebele_id',
            'current_password' => 'password',
        ])->assertCreated()->json('code'));

        spVisitor();
        test()->postJson(SP_HOST.'/auth/activation/redeem', [
            'employee_code' => 'EMP-0001',
            'code' => $code,
            'password' => 'a-fresh-secret-9',
            'password_confirmation' => 'a-fresh-secret-9',
        ])->assertOk();

        $user = spUser($employees[0]);
        expect($user->password_change_required_at)->toBeNull()
            ->and($user->starting_password_expires_at)->toBeNull()
            ->and($user->status)->toBe('active');
    });

    it('clears the hold when a reset link is used', function () {
        [, , $employees] = startingPasswordFixture();
        issueStartingPassword($employees)->assertCreated();

        $user = spUser($employees[0]);
        $user->forceFill(['email' => 'later@acme.test'])->save();
        $token = PasswordTokens::broker()->createToken($user);

        spVisitor();
        test()->postJson(SP_HOST.'/auth/password/reset', [
            'token' => $token,
            'email' => 'later@acme.test',
            'password' => 'a-fresh-secret-9',
            'password_confirmation' => 'a-fresh-secret-9',
        ])->assertOk();

        $user->refresh();
        expect($user->password_change_required_at)->toBeNull()
            ->and($user->starting_password_expires_at)->toBeNull();
    });
});

describe('expiry', function () {
    it('refuses a sign-in after 72 hours and puts the account back to invited', function () {
        [, , $employees] = startingPasswordFixture();
        $password = (string) issueStartingPassword($employees)->json('password');

        test()->travel(72)->hours();
        test()->travel(1)->minutes();

        spSignIn('EMP-0001', $password)
            ->assertForbidden()
            ->assertJsonPath('type', 'https://ethr.et/errors/starting-password-expired');

        $user = spUser($employees[0]);
        expect($user->status)->toBe('invited')
            ->and($user->password_change_required_at)->toBeNull()
            ->and($user->starting_password_expires_at)->toBeNull()
            ->and(Hash::check($password, $user->password))->toBeFalse();

        // Nothing left to try the password against.
        spSignIn('EMP-0001', $password)->assertStatus(422);

        expect(AuditLog::withoutGlobalScopes()->where('action', 'user.starting_password_expired')->exists())->toBeTrue();
    });

    it('still signs in one minute before the 72 hours are up', function () {
        [, , $employees] = startingPasswordFixture();
        $password = (string) issueStartingPassword($employees)->json('password');

        test()->travel(71)->hours();
        test()->travel(59)->minutes();

        spSignIn('EMP-0001', $password)->assertOk();
    });

    it('lets HR issue an in-person code once the account is back to invited', function () {
        [$tenant, $hr, $employees] = startingPasswordFixture();
        $password = (string) issueStartingPassword($employees)->json('password');

        test()->travel(73)->hours();
        spSignIn('EMP-0001', $password)->assertForbidden();

        app(CurrentTenant::class)->set($tenant);
        test()->actingAs($hr);
        test()->postJson(SP_HOST.'/employees/'.$employees[0]->public_id.'/activation-code', [
            'attested' => true,
            'document_type' => 'kebele_id',
            'current_password' => 'password',
        ])->assertCreated()->assertJsonPath('purpose', 'activation');
    });

    it('ends a session that was opened before the password expired', function () {
        [, , $employees] = startingPasswordFixture();
        $password = (string) issueStartingPassword($employees)->json('password');
        $token = (string) spSignIn('EMP-0001', $password)->json('access_token');

        test()->travel(73)->hours();
        // A token outlives 15 minutes only by being refreshed; this one stands
        // in for a session kept alive across the expiry.
        $user = spUser($employees[0]);
        $user->tokens()->update(['expires_at' => now()->addHour()]);

        test()->withToken($token)->postJson(SP_HOST.'/auth/password/change', [
            'current_password' => $password,
            'password' => 'my-own-secret-42',
            'password_confirmation' => 'my-own-secret-42',
        ])->assertForbidden()->assertJsonPath('type', 'https://ethr.et/errors/starting-password-expired');

        $user->refresh();
        expect($user->status)->toBe('invited')
            ->and(Hash::check('my-own-secret-42', $user->password))->toBeFalse()
            ->and($user->tokens()->count())->toBe(0);
    });

    it('is swept by the daily cleanup, which leaves live and changed accounts alone', function () {
        [, , $employees] = startingPasswordFixture();
        $password = (string) issueStartingPassword($employees)->json('password');

        // EMP-0002 chose their own password; EMP-0003 was issued later.
        $token = (string) spSignIn('EMP-0002', $password)->json('access_token');
        test()->withToken($token)->postJson(SP_HOST.'/auth/password/change', [
            'current_password' => $password,
            'password' => 'my-own-secret-42',
            'password_confirmation' => 'my-own-secret-42',
        ])->assertOk();
        spUser($employees[2])->forceFill(['starting_password_expires_at' => now()->addHours(100)])->save();
        spUser($employees[0])->createToken('stale');

        test()->travel(73)->hours();
        (new CleanupExpiredDataJob)->handle();

        $first = spUser($employees[0]);
        expect($first->status)->toBe('invited')
            ->and($first->password_change_required_at)->toBeNull()
            ->and($first->starting_password_expires_at)->toBeNull()
            ->and(Hash::check($password, $first->password))->toBeFalse()
            ->and($first->tokens()->count())->toBe(0);

        expect(spUser($employees[1])->status)->toBe('active')
            ->and(Hash::check('my-own-secret-42', spUser($employees[1])->password))->toBeTrue();

        $third = spUser($employees[2]);
        expect($third->status)->toBe('active')
            ->and($third->password_change_required_at)->not->toBeNull()
            ->and(Hash::check($password, $third->password))->toBeTrue();
    });
});
