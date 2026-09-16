<?php

declare(strict_types=1);

use App\Support\PasswordTokens;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Illuminate\Support\Facades\Password;

/**
 * `Password::broker()` is typed as the contract, and the contract has two
 * methods — `sendResetLink()` and `reset()`. ETHR calls `createToken()`,
 * `tokenExists()` and `deleteToken()`, which exist only on the concrete
 * implementation, because it sends its own reset and activation notifications
 * instead of Laravel's built-in mail.
 *
 * So those calls depended on the default binding being the concrete class.
 * `PasswordTokens::broker()` makes that dependency explicit and checked.
 *
 * These tests exist because the alternative fix — an annotation or a baseline
 * entry — would satisfy the analyser while leaving the runtime exactly as
 * fragile. The point is the `instanceof`, so the `instanceof` is what is tested.
 */
it('returns the concrete broker, which is the one that can mint tokens', function () {
    $broker = PasswordTokens::broker();

    expect($broker)->toBeInstanceOf(PasswordBroker::class);

    // The three methods the application actually needs, none of which the
    // contract declares. If Laravel ever moves them, this fails here rather
    // than in a password-reset request.
    foreach (['createToken', 'tokenExists', 'deleteToken'] as $method) {
        expect(method_exists($broker, $method))->toBeTrue(
            "Illuminate\\Auth\\Passwords\\PasswordBroker::{$method}() has gone."
        );
    }
});

it('refuses a broker that satisfies the contract but cannot mint tokens', function () {
    // Exactly what the contract permits and what the old code assumed away.
    $substitute = new class implements PasswordBrokerContract
    {
        public function sendResetLink(array $credentials, ?Closure $callback = null)
        {
            return PasswordBrokerContract::RESET_LINK_SENT;
        }

        public function reset(array $credentials, Closure $callback)
        {
            return PasswordBrokerContract::PASSWORD_RESET;
        }
    };

    Password::shouldReceive('broker')->andReturn($substitute);

    // Loudly, and naming the cause — not "Call to undefined method" three
    // frames further in, while somebody is locked out of their account.
    expect(fn () => PasswordTokens::broker())
        ->toThrow(RuntimeException::class, 'cannot mint reset tokens');
});
