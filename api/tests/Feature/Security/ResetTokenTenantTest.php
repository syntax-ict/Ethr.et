<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PasswordTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Reset and invitation tokens were stored under the email address alone, and
 * an address is unique within an organisation, not across them. So a link
 * minted for one organisation's account was accepted by another's with the
 * same address, and asking for a link in one replaced the other's.
 *
 * And on an organisation's sign-in page the platform admin's address was
 * hashed twice where any other unknown address was hashed once.
 */

/** @return array{0: Tenant, 1: User, 2: Tenant, 3: User} two organisations, one address */
function tokenTwins(): array
{
    $acme = createTenant(['subdomain' => 'acme']);
    $acmeUser = createUser(['email' => 'same@example.et', 'status' => 'active'], $acme);
    $beta = createTenant(['subdomain' => 'beta']);
    $betaUser = createUser(['email' => 'same@example.et', 'status' => 'active'], $beta);

    return [$acme, $acmeUser, $beta, $betaUser];
}

function tokenReset(string $tenant, string $token)
{
    return test()->postJson('/api/v1/auth/password/reset', [
        'tenant' => $tenant,
        'token' => $token,
        'email' => 'same@example.et',
        'password' => 'new-secret-123',
        'password_confirmation' => 'new-secret-123',
    ]);
}

it('accepts a reset link only in the organisation it was made for', function () {
    [, $acmeUser, , $betaUser] = tokenTwins();
    $token = PasswordTokens::broker()->createToken($acmeUser);
    $betaHash = $betaUser->password;

    tokenReset('beta', $token)->assertUnprocessable()->assertJsonPath('type', 'https://ethr.et/errors/invalid-token');
    expect($betaUser->fresh()->password)->toBe($betaHash);

    tokenReset('acme', $token)->assertOk();
    expect(Hash::check('new-secret-123', $acmeUser->fresh()->password))->toBeTrue();
});

it('accepts an invitation only in the organisation it was made for', function () {
    [, $acmeUser] = tokenTwins();
    $token = PasswordTokens::activationBroker()->createToken($acmeUser);

    tokenReset('beta', $token)->assertUnprocessable();
    tokenReset('acme', $token)->assertOk();
});

it('keeps one organisation\'s pending link when the other asks for one', function () {
    [, $acmeUser, , $betaUser] = tokenTwins();
    $acmeToken = PasswordTokens::broker()->createToken($acmeUser);

    PasswordTokens::broker()->createToken($betaUser);

    tokenReset('acme', $acmeToken)->assertOk();
});

it('moves a token stored under the bare address to its account', function () {
    [, $acmeUser] = tokenTwins();
    $only = createUser(['email' => 'only@example.et'], Tenant::where('subdomain', 'acme')->first());
    DB::table('account_activation_tokens')->insert([
        ['email' => 'only@example.et', 'token' => Hash::make('t1'), 'created_at' => now()],
        ['email' => 'same@example.et', 'token' => Hash::make('t2'), 'created_at' => now()],
    ]);

    (require database_path('migrations/2026_10_31_000001_key_password_tokens_by_tenant.php'))->up();

    expect(PasswordTokens::activationBroker()->tokenExists($only, 't1'))->toBeTrue()
        ->and(DB::table('account_activation_tokens')->where('email', 'same@example.et')->exists())->toBeFalse()
        ->and(PasswordTokens::activationBroker()->tokenExists($acmeUser, 't2'))->toBeFalse();
});

it('hashes the platform admin\'s address on an organisation\'s sign-in page no more than any other', function (bool $platformAddress) {
    // Before the organisation, which would otherwise become its tenant.
    $operator = User::factory()->create([
        'tenant_id' => null,
        'email' => 'operator@ethr.et',
        'password' => Hash::make('platform-secret'),
        'role' => UserRole::SUPER_ADMIN,
    ]);
    createTenant(['subdomain' => 'demo']);
    expect($operator->fresh()->tenant_id)->toBeNull();
    $calls = [];
    $mock = Hash::partialMock();
    $mock->shouldReceive('make')->andReturnUsing(function () use (&$calls) {
        $calls[] = 'make';

        return 'dummy';
    });
    $mock->shouldReceive('check')->andReturnUsing(function () use (&$calls) {
        $calls[] = 'check';

        return false;
    });

    test()->postJson('/api/v1/auth/login', [
        'email' => $platformAddress ? 'operator@ethr.et' : 'nobody@ethr.et',
        'password' => 'wrong',
        'tenant' => 'demo',
    ])->assertUnprocessable();

    expect($calls)->toHaveCount(1);
})->with(['the platform admin' => [true], 'an unknown address' => [false]]);
