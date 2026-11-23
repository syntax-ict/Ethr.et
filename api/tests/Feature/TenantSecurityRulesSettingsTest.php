<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\AuditLog;
use App\Models\OnboardingProgress;
use App\Models\Tenant;
use App\Rules\PasswordPolicy;
use App\Services\Auth\ActivationCodeService;

/*
 * Settings > General > Security: the password policy, the lifetime of an
 * activation code, and the sign-in identifiers. The first two are written
 * through PUT /settings; the third keeps its own endpoint (PUT
 * /onboarding/access), which the Security tab reuses after onboarding.
 */

function secRulesAdmin(array $settings = []): Tenant
{
    $tenant = createTenant(['settings' => $settings]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

    return $tenant;
}

function secRulesUrl(Tenant $tenant, string $path = 'settings'): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function secRulesPolicy(array $over = []): array
{
    return array_merge([
        'min_length' => 12,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_number' => true,
        'require_symbol' => false,
    ], $over);
}

test('writableKeys names the two new keys and no sub-keys', function () {
    $keys = UpdateSettingsRequest::writableKeys();

    expect($keys)->toContain('password_policy')
        ->toContain('activation_code_hours')
        ->and(array_filter($keys, fn (string $k): bool => str_contains($k, 'min_length') || str_contains($k, 'expiry_days')))->toBe([]);
});

test('a password policy in range is saved and read back as enforced', function () {
    $tenant = secRulesAdmin();

    test()->putJson(secRulesUrl($tenant), ['settings' => ['password_policy' => secRulesPolicy()]])->assertOk();

    expect($tenant->fresh()->settings['password_policy'])->toEqual(secRulesPolicy());

    test()->getJson(secRulesUrl($tenant))
        ->assertOk()
        ->assertJsonPath('security.password_policy.min_length', 12)
        ->assertJsonPath('security.password_policy.require_symbol', false)
        ->assertJsonMissingPath('security.password_policy.expiry_days');
});

test('the limits of the password policy are accepted', function (array $over) {
    $tenant = secRulesAdmin();

    test()->putJson(secRulesUrl($tenant), ['settings' => ['password_policy' => secRulesPolicy($over)]])->assertOk();
})->with([
    'floor' => [['min_length' => 8]],
    'ceiling' => [['min_length' => 128]],
]);

test('a password policy out of range is refused and nothing is stored', function (array $over, string $field) {
    $tenant = secRulesAdmin();

    test()->putJson(secRulesUrl($tenant), ['settings' => ['password_policy' => secRulesPolicy($over)]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["settings.password_policy.{$field}"]);

    expect($tenant->fresh()->settings ?? [])->not->toHaveKey('password_policy');
})->with([
    'min length 7' => [['min_length' => 7], 'min_length'],
    'min length 129' => [['min_length' => 129], 'min_length'],
    'min length text' => [['min_length' => 'long'], 'min_length'],
    'flag not a boolean' => [['require_number' => 'maybe'], 'require_number'],
]);

test('an unknown password policy key is refused', function () {
    $tenant = secRulesAdmin();

    test()->putJson(secRulesUrl($tenant), ['settings' => ['password_policy' => secRulesPolicy(['lockout' => 3])]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.password_policy']);

    expect($tenant->fresh()->settings ?? [])->not->toHaveKey('password_policy');
});

test('expiry_days is not writable, and a save keeps one already stored', function () {
    $tenant = secRulesAdmin(['password_policy' => secRulesPolicy() + ['expiry_days' => 90]]);

    test()->putJson(secRulesUrl($tenant), ['settings' => ['password_policy' => secRulesPolicy(['expiry_days' => 30])]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.password_policy']);

    test()->putJson(secRulesUrl($tenant), ['settings' => ['password_policy' => secRulesPolicy(['min_length' => 14])]])->assertOk();

    expect($tenant->fresh()->settings['password_policy']['min_length'])->toBe(14)
        ->and($tenant->fresh()->settings['password_policy']['expiry_days'])->toBe(90);
});

test('a partial password policy is refused, so a save never resets the rules it left out', function () {
    $tenant = secRulesAdmin(['password_policy' => secRulesPolicy()]);

    test()->putJson(secRulesUrl($tenant), ['settings' => ['password_policy' => ['min_length' => 10]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.password_policy.require_uppercase']);

    expect($tenant->fresh()->settings['password_policy'])->toEqual(secRulesPolicy());
});

test('the stored policy is what the password rule enforces on the next password', function () {
    $tenant = secRulesAdmin();
    test()->putJson(secRulesUrl($tenant), ['settings' => ['password_policy' => secRulesPolicy()]])->assertOk();

    $rule = new PasswordPolicy($tenant->fresh());
    $messages = [];
    $fail = function (string $m) use (&$messages): void {
        $messages[] = $m;
    };

    $rule->validate('password', 'Short1', $fail);
    expect($messages)->not->toBe([]);

    $messages = [];
    $rule->validate('password', 'Longenough123', $fail);
    expect($messages)->toBe([]);
});

test('an activation code lifetime in range is saved and read back', function () {
    $tenant = secRulesAdmin();

    test()->putJson(secRulesUrl($tenant), ['settings' => ['activation_code_hours' => 48]])->assertOk();

    expect($tenant->fresh()->settings['activation_code_hours'])->toBe(48);
    test()->getJson(secRulesUrl($tenant))->assertOk()->assertJsonPath('security.activation_code_hours', 48);
});

test('an unset activation code lifetime reads as the default', function () {
    $tenant = secRulesAdmin();

    test()->getJson(secRulesUrl($tenant))
        ->assertOk()
        ->assertJsonPath('security.activation_code_hours', ActivationCodeService::DEFAULT_TTL_HOURS)
        ->assertJsonPath('security.password_policy.min_length', 8);
});

test('the limits of the activation code lifetime are accepted and what is saved is what is enforced', function (int $hours) {
    $tenant = secRulesAdmin();

    test()->putJson(secRulesUrl($tenant), ['settings' => ['activation_code_hours' => $hours]])->assertOk();

    expect(ActivationCodeService::ttlHoursFor($tenant->fresh()))->toBe($hours);
})->with([1, ActivationCodeService::MAX_TTL_HOURS]);

test('an Amharic refusal names the field once, without a doubled የ', function (array $settings, string $field, string $name) {
    // The templates read "የ:attribute መስክ", and every Amharic field name
    // already starts with የ, so the message said "የየ…" (native review,
    // 2026-11-23). The template now leaves the የ to the name.
    $tenant = secRulesAdmin();
    app()->setLocale('am');

    $message = test()->withHeader('Accept-Language', 'am')
        ->putJson(secRulesUrl($tenant), ['settings' => $settings])
        ->assertUnprocessable()
        ->json('errors')[$field][0];

    expect($message)->toStartWith("{$name} መስክ")
        ->and($message)->not->toContain('የየ');
})->with([
    'activation hours' => [['activation_code_hours' => 0], 'settings.activation_code_hours', 'የማግበሪያ ኮድ የአገልግሎት ጊዜ'],
    'minimum length' => [['password_policy' => ['min_length' => 7, 'require_uppercase' => true, 'require_lowercase' => true, 'require_number' => true, 'require_symbol' => true]], 'settings.password_policy.min_length', 'ዝቅተኛ ርዝመት'],
    'session timeout' => [['session_timeout_minutes' => 3], 'settings.session_timeout_minutes', 'የክፍለ ጊዜ ማብቂያ'],
]);

test('no Amharic validation template puts የ in front of the field name', function () {
    expect(file_get_contents(lang_path('am/validation.php')))->not->toContain('የ:attribute');
});

test('an activation code lifetime out of range is refused', function (mixed $hours) {
    $tenant = secRulesAdmin();

    test()->putJson(secRulesUrl($tenant), ['settings' => ['activation_code_hours' => $hours]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.activation_code_hours']);

    expect($tenant->fresh()->settings ?? [])->not->toHaveKey('activation_code_hours');
})->with([0, -5, ActivationCodeService::MAX_TTL_HOURS + 1, 168, 'a day']);

test('without settings.manage neither key can be written', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->putJson(secRulesUrl($tenant), ['settings' => ['activation_code_hours' => 48]])->assertForbidden();
    test()->putJson(secRulesUrl($tenant), ['settings' => ['password_policy' => secRulesPolicy()]])->assertForbidden();

    expect($tenant->fresh()->settings ?? [])->not->toHaveKey('activation_code_hours')
        ->not->toHaveKey('password_policy');
});

test('one organisation cannot change another organisation\'s rules', function () {
    $a = createTenant(['settings' => ['activation_code_hours' => 24]]);
    $b = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $b);

    test()->putJson(secRulesUrl($b), ['settings' => [
        'activation_code_hours' => 72,
        'password_policy' => secRulesPolicy(),
    ]])->assertOk();

    expect($b->fresh()->settings['activation_code_hours'])->toBe(72)
        ->and($a->fresh()->settings['activation_code_hours'])->toBe(24)
        ->and($a->fresh()->settings)->not->toHaveKey('password_policy');
});

test('a change to either rule writes one audit entry with before and after', function () {
    $tenant = secRulesAdmin();

    test()->putJson(secRulesUrl($tenant), ['settings' => [
        'password_policy' => secRulesPolicy(),
        'activation_code_hours' => 48,
    ]])->assertOk();

    $entry = AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'settings.sign_in_rules_updated')->get();

    expect($entry)->toHaveCount(1)
        ->and($entry[0]->payload['before']['password_policy']['min_length'])->toBe(8)
        ->and($entry[0]->payload['after']['password_policy']['min_length'])->toBe(12)
        ->and($entry[0]->payload['before']['activation_code_hours'])->toBe(24)
        ->and($entry[0]->payload['after']['activation_code_hours'])->toBe(48);
});

test('saving the same values again, or another setting, writes no audit entry for the rules', function () {
    $tenant = secRulesAdmin(['password_policy' => secRulesPolicy(), 'activation_code_hours' => 48]);

    test()->putJson(secRulesUrl($tenant), ['settings' => ['activation_code_hours' => 48, 'password_policy' => secRulesPolicy()]])->assertOk();
    test()->putJson(secRulesUrl($tenant), ['settings' => ['run_day' => 20]])->assertOk();

    expect(AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'settings.sign_in_rules_updated')->count())->toBe(0);
});

describe('sign-in identifiers after onboarding has finished', function () {
    test('they save, and the finished wizard record is left as it was', function () {
        $tenant = secRulesAdmin();
        $done = now()->subDay()->startOfSecond();
        OnboardingProgress::query()->create([
            'tenant_id' => $tenant->id,
            'current_step' => 7,
            'completed_steps' => [3, 4, 5, 7],
            'step_data' => ['step_5' => ['login_identifiers' => ['email']]],
            'completed_at' => $done,
        ]);

        test()->putJson(secRulesUrl($tenant, 'onboarding/access'), ['login_identifiers' => ['email', 'phone']])
            ->assertOk()
            ->assertJsonPath('login_identifiers', ['email', 'phone']);

        $progress = OnboardingProgress::query()->where('tenant_id', $tenant->id)->firstOrFail();
        expect($tenant->fresh()->settings['login_identifiers'])->toEqual(['email', 'phone'])
            ->and($progress->current_step)->toBe(7)
            ->and($progress->completed_steps)->toEqual([3, 4, 5, 7])
            ->and($progress->completed_at?->equalTo($done))->toBeTrue()
            ->and($progress->step_data['step_5']['login_identifiers'])->toEqual(['email']);
    });

    test('during onboarding the step is still marked done and the wizard moves on', function () {
        $tenant = secRulesAdmin();

        test()->putJson(secRulesUrl($tenant, 'onboarding/access'), ['login_identifiers' => ['email']])->assertOk();

        $progress = OnboardingProgress::query()->where('tenant_id', $tenant->id)->firstOrFail();
        expect($progress->completed_steps)->toContain(5)
            ->and($progress->current_step)->toBe(6)
            ->and($progress->completed_at)->toBeNull();
    });

    test('at least one identifier is required', function () {
        $tenant = secRulesAdmin();

        test()->putJson(secRulesUrl($tenant, 'onboarding/access'), ['login_identifiers' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login_identifiers']);
    });

    test('it needs settings.manage', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        test()->putJson(secRulesUrl($tenant, 'onboarding/access'), ['login_identifiers' => ['email', 'phone']])->assertForbidden();
        expect($tenant->fresh()->settings ?? [])->not->toHaveKey('login_identifiers');
    });

    test('another organisation\'s identifiers are untouched', function () {
        $a = createTenant(['settings' => ['login_identifiers' => ['email']]]);
        $b = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $b);

        test()->putJson(secRulesUrl($b, 'onboarding/access'), ['login_identifiers' => ['phone']])->assertOk();

        expect($a->fresh()->settings['login_identifiers'])->toEqual(['email'])
            ->and($b->fresh()->settings['login_identifiers'])->toEqual(['phone']);
    });
});
