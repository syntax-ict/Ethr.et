<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AccountActivationCode;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SmsGateway;
use App\Models\SmsSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActivationCodeService;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * An organisation that signs in by employee code, an HR admin, and an employee
 * with no email and no phone.
 *
 * @return array{0: Tenant, 1: User, 2: Employee}
 */
function inPersonFixture(array $settings = ['login_identifiers' => ['employee_code', 'email']]): array
{
    $tenant = createTenant(['subdomain' => 'acme', 'settings' => $settings]);
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => null,
        'phone' => null,
        'employee_code' => 'EMP-0042',
    ]);

    return [$tenant, $hr, $employee];
}

/** @return array<string, mixed> */
function issuePayload(array $overrides = []): array
{
    return [
        'attested' => true,
        'document_type' => 'kebele_id',
        'current_password' => 'password',
        ...$overrides,
    ];
}

function issueCode(Employee $employee, array $overrides = []): string
{
    $response = test()->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload($overrides));
    $response->assertCreated();

    return str_replace('-', '', (string) $response->json('code'));
}

function redeemCode(string $code, array $overrides = []): TestResponse
{
    // A real request starts with no tenant resolved; createTenant() leaves one set.
    app(CurrentTenant::class)->forget();
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/activation/redeem', [
        'tenant' => 'acme',
        'employee_code' => 'EMP-0042',
        'code' => $code,
        'password' => 'a-fresh-secret-9',
        'password_confirmation' => 'a-fresh-secret-9',
        ...$overrides,
    ]);
}

describe('issuing a code', function () {
    it('creates a no-contact account and returns the code once, never cached', function () {
        [$tenant, , $employee] = inPersonFixture();

        $response = $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload())
            ->assertCreated()
            ->assertJsonPath('purpose', 'activation')
            ->assertJsonPath('employee_code', 'EMP-0042');

        expect($response->headers->get('Cache-Control'))->toContain('no-store');

        $code = str_replace('-', '', (string) $response->json('code'));
        expect($code)->toHaveLength(12)->and(ActivationCodeService::normalise($code))->toBe($code);
        expect($response->json('activation_url'))->toContain('#c='.$code);

        $user = User::query()->where('employee_id', $employee->id)->first();
        expect($user)->not->toBeNull()
            ->and($user->email)->toBeNull()
            ->and($user->status)->toBe('invited');
        expect($employee->refresh()->user_id)->toBe($user->id);
    });

    it('stores a keyed hash and never the code, and keeps the code out of the audit log', function () {
        [, , $employee] = inPersonFixture();

        $code = issueCode($employee);

        $row = AccountActivationCode::query()->firstOrFail();
        expect($row->code_hash)->not->toBe($code)
            ->and($row->code_hash)->toBe(hash_hmac('sha256', $code, (string) config('app.key')));

        $logged = AuditLog::query()->where('action', 'like', 'user.activation_code%')->get()->toJson();
        expect($logged)->not->toContain($code)->and($logged)->toContain('kebele_id');
    });

    it('does not expose the code through the status endpoint', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);

        $body = $this->getJson("/api/v1/employees/{$employee->public_id}/activation-code")
            ->assertOk()
            ->assertJsonPath('state', 'live')
            ->getContent();

        expect($body)->not->toContain($code);
    });

    it('withdraws the earlier code when a new one is issued', function () {
        [, , $employee] = inPersonFixture();

        $first = issueCode($employee);
        $second = issueCode($employee);

        redeemCode($first)->assertStatus(422);
        redeemCode($second)->assertOk();
    });

    it('requires the attestation, a document and the HR password', function () {
        [, , $employee] = inPersonFixture();
        $url = "/api/v1/employees/{$employee->public_id}/activation-code";

        $this->postJson($url, issuePayload(['attested' => false]))->assertStatus(422)->assertJsonValidationErrors('attested');
        $this->postJson($url, issuePayload(['document_type' => 'library_card']))->assertStatus(422)->assertJsonValidationErrors('document_type');
        $this->postJson($url, issuePayload(['current_password' => 'wrong']))->assertStatus(422)->assertJsonValidationErrors('current_password');

        expect(AccountActivationCode::query()->count())->toBe(0);
    });

    it('asks for an authenticator code when the HR user has two-factor on', function () {
        [$tenant, $hr, $employee] = inPersonFixture();
        $hr->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('mfa_code');

        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload(['mfa_code' => '000000']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('mfa_code');
    });

    it('is forbidden without the permission', function () {
        [$tenant, , $employee] = inPersonFixture();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload())->assertForbidden();
    });

    it('refuses an employee who has an email', function () {
        [, , $employee] = inPersonFixture();
        $employee->update(['email' => 'reachable@acme.test']);

        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'contact');
    });

    // Until 2026-10-28 any phone refused, while SMS was not live: a person with
    // only a phone could get no link, no text and no code. A phone counts now
    // only once texts would reach it.
    it('issues for an employee with only a phone while text invitations are off', function () {
        [, , $employee] = inPersonFixture();
        $employee->update(['phone' => '+251911223344']);

        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload())
            ->assertCreated();
    });

    it('refuses an employee with only a phone once text invitations are on', function () {
        [, , $employee] = inPersonFixture();
        $employee->update(['phone' => '+251911223344']);

        $gateway = new SmsGateway([
            'slot' => SmsGateway::SLOT_PRIMARY,
            'endpoint' => 'https://sms.example.et/send',
            'method' => 'form',
            'auth_type' => 'none',
            'recipient_field' => 'to',
            'message_field' => 'message',
            'number_format' => 'international',
            'timeout_seconds' => 5,
        ]);
        $gateway->verified_at = now();
        $gateway->save();
        SmsSetting::current()->update(['enabled' => true, 'invitations_enabled' => true]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'contact');
    });

    it('refuses when the employee would have no way to sign in', function () {
        [, , $employee] = inPersonFixture(['login_identifiers' => ['email']]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'identifier');
    });

    it('refuses to issue for the HR user themselves', function () {
        [$tenant, $hr] = inPersonFixture();
        $own = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => null, 'user_id' => $hr->id]);

        $this->postJson("/api/v1/employees/{$own->public_id}/activation-code", issuePayload())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'self');
    });

    it('refuses an account at or above the issuer level', function () {
        [$tenant, , $employee] = inPersonFixture();
        $peer = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::HR_ADMIN, 'email' => null, 'employee_id' => $employee->id]);
        $employee->update(['user_id' => $peer->id]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'role');
    });

    it('does not reach an employee of another organisation', function () {
        inPersonFixture();
        $other = Tenant::factory()->create(['subdomain' => 'other']);
        $stranger = Employee::factory()->create(['tenant_id' => $other->id, 'email' => null, 'phone' => null]);

        $this->postJson("/api/v1/employees/{$stranger->public_id}/activation-code", issuePayload())->assertNotFound();
        expect(AccountActivationCode::withoutGlobalScopes()->count())->toBe(0);
    });

    it('lets HR withdraw a live code', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);

        $this->deleteJson("/api/v1/employees/{$employee->public_id}/activation-code")->assertOk()->assertJsonPath('revoked', true);

        redeemCode($code)->assertStatus(422);
        expect(AuditLog::query()->where('action', 'user.activation_code_revoked')->exists())->toBeTrue();
    });
});

describe('redeeming a code', function () {
    it('activates the account and lets the employee sign in by employee code', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);
        $this->app['auth']->forgetGuards();

        redeemCode($code)->assertOk();
        $user = User::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
        expect($user->status)->toBe('active')
            ->and($user->activated_at)->not->toBeNull()
            ->and(Hash::check('a-fresh-secret-9', $user->password))->toBeTrue();

        // The HR session from the fixture left the Sanctum guard as the default.
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'EMP-0042',
            'password' => 'a-fresh-secret-9',
            'tenant' => 'acme',
        ])->assertOk();
    });

    it('accepts the code in lower case, with spaces, and with the letters O and I for 0 and 1', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);
        $loose = strtolower(implode(' ', str_split(strtr($code, ['0' => 'O', '1' => 'I']), 4)));

        redeemCode($loose)->assertOk();
    });

    it('can be used once', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);

        redeemCode($code)->assertOk();
        redeemCode($code, ['password' => 'another-secret-7', 'password_confirmation' => 'another-secret-7'])->assertStatus(422);

        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();
        expect(Hash::check('a-fresh-secret-9', $user->password))->toBeTrue();
    });

    it('loses the race when another request already spent the code', function () {
        [, , $employee] = inPersonFixture();
        issueCode($employee);

        $row = AccountActivationCode::query()->firstOrFail();
        $claim = fn () => AccountActivationCode::query()
            ->where('id', $row->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now('UTC'))
            ->update(['consumed_at' => Carbon::now('UTC')]);

        expect($claim())->toBe(1)->and($claim())->toBe(0);
    });

    it('fails once the code has expired', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);
        AccountActivationCode::query()->update(['expires_at' => now()->subMinute()]);

        redeemCode($code)->assertStatus(422);
        expect(User::query()->where('employee_id', $employee->id)->value('status'))->toBe('invited');
    });

    it('honours the organisation lifetime and caps it at 72 hours', function () {
        [, , $employee] = inPersonFixture(['login_identifiers' => ['employee_code'], 'activation_code_hours' => 500]);
        issueCode($employee);

        $row = AccountActivationCode::query()->firstOrFail();
        expect((int) round(now()->diffInHours($row->expires_at, false)))->toBeLessThanOrEqual(72)
            ->and((int) round(now()->diffInHours($row->expires_at, false)))->toBeGreaterThanOrEqual(71);
    });

    it('gives every failure the same answer', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);
        $wrong = $code === str_repeat('A', 12) ? str_repeat('B', 12) : str_repeat('A', 12);

        $answers = [
            redeemCode($wrong),
            redeemCode($code, ['employee_code' => 'EMP-9999']),
            redeemCode('short'),
        ];

        foreach ($answers as $answer) {
            $answer->assertStatus(422);
        }

        expect(collect($answers)->map(fn ($r) => $r->getContent())->unique()->count())->toBe(1);
    });

    it('does not accept the code for another organisation', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);
        $this->app['auth']->forgetGuards();

        $other = Tenant::factory()->create(['subdomain' => 'other', 'settings' => ['login_identifiers' => ['employee_code']]]);
        Employee::factory()->create(['tenant_id' => $other->id, 'employee_code' => 'EMP-0042', 'email' => null, 'phone' => null]);

        redeemCode($code, ['tenant' => 'other'])->assertStatus(422);
        expect(User::withoutGlobalScopes()->where('employee_id', $employee->id)->value('status'))->toBe('invited');
    });

    it('locks the code after five wrong guesses, even for the right code afterwards', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);
        $wrong = $code === str_repeat('A', 12) ? str_repeat('B', 12) : str_repeat('A', 12);

        foreach (range(1, 5) as $_) {
            redeemCode($wrong)->assertStatus(422);
        }

        redeemCode($code)->assertStatus(422);

        $row = AccountActivationCode::query()->firstOrFail();
        expect($row->revoked_at)->not->toBeNull()->and($row->failed_attempts)->toBe(5);
        expect(app(ActivationCodeService::class)->stateOf($row))->toBe('locked');
        expect(AuditLog::query()->where('action', 'user.activation_code_locked')->exists())->toBeTrue();
    });

    it('rejects a weak password or one equal to the employee code', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);

        redeemCode($code, ['password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
        redeemCode($code, ['password' => 'emp-0042', 'password_confirmation' => 'emp-0042'])->assertStatus(422)->assertJsonValidationErrors('password');
        redeemCode($code, ['password_confirmation' => 'different-one-1'])->assertStatus(422)->assertJsonValidationErrors('password');

        redeemCode($code)->assertOk();
    });

    it('does not sign anyone in and revokes sessions that already existed', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);
        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();
        $user->createToken('stale');
        $this->app['auth']->forgetGuards();

        $response = redeemCode($code)->assertOk();

        expect($response->headers->getCookies())->toBeEmpty();
        expect($user->tokens()->count())->toBe(0);
    });

    it('recovers a lost password and leaves two-factor on', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);
        redeemCode($code)->assertOk();

        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();
        $user->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        actingAsUser(['role' => UserRole::HR_ADMIN], Tenant::query()->firstOrFail());
        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", issuePayload())
            ->assertCreated()
            ->assertJsonPath('purpose', 'recovery');

        expect($user->refresh()->mfa_enabled)->toBeTrue();
    });

    it('records the redemption in the audit log without the code', function () {
        [, , $employee] = inPersonFixture();
        $code = issueCode($employee);
        redeemCode($code)->assertOk();

        $actions = AuditLog::query()->pluck('action')->all();
        expect($actions)->toContain('user.activation_code_issued', 'user.activation_code_redeemed', 'user.activated');
        expect(AuditLog::query()->get()->toJson())->not->toContain($code);
    });
});

describe('employee create', function () {
    it('makes a no-contact account and says an activation code is pending', function () {
        $tenant = createTenant(['subdomain' => 'acme', 'settings' => ['login_identifiers' => ['employee_code']]]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'No Contact Worker',
            'hire_date' => '2026-01-01',
            'create_login' => true,
        ])->assertCreated()
            ->assertJsonPath('meta.login_invited', false)
            ->assertJsonPath('meta.activation_code_pending', true);

        expect(User::query()->whereNull('email')->where('status', 'invited')->count())->toBe(1);
        expect(AccountActivationCode::query()->count())->toBe(0);
    });
});

describe('a user without an email', function () {
    it('has a name, resolves a display fallback and never matches an empty email lookup', function () {
        [, , $employee] = inPersonFixture();
        issueCode($employee);

        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();
        expect($user->name)->toBe($employee->name);

        $this->postJson('/api/v1/auth/login', ['identifier' => '', 'password' => 'x', 'tenant' => 'acme'])->assertStatus(422);
    });

    it('allows several in one organisation', function () {
        [$tenant, , $employee] = inPersonFixture();
        $second = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => null]);

        issueCode($employee);
        issueCode($second);

        expect(User::query()->whereNull('email')->count())->toBe(2);
    });
});

describe('housekeeping', function () {
    it('purges codes a month after they expired and keeps the rest', function () {
        [, , $employee] = inPersonFixture();
        issueCode($employee);
        issueCode(Employee::factory()->create(['tenant_id' => $employee->tenant_id, 'email' => null, 'phone' => null]));

        AccountActivationCode::query()->oldest('id')->limit(1)->update(['expires_at' => now()->subDays(31)]);

        expect(app(ActivationCodeService::class)->purge())->toBe(1);
        expect(AccountActivationCode::query()->count())->toBe(1);
    });
});
