<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;
use RuntimeException;

/**
 * The password broker, narrowed to the implementation that can mint tokens.
 *
 * `Password::broker()` is typed as `Illuminate\Contracts\Auth\PasswordBroker`,
 * and that contract declares exactly two methods:
 *
 *     sendResetLink(array $credentials, ?Closure $callback = null)
 *     reset(array $credentials, Closure $callback)
 *
 * `createToken()`, `tokenExists()` and `deleteToken()` are **not** on it. They
 * exist only on the concrete `Illuminate\Auth\Passwords\PasswordBroker`. ETHR
 * calls all three, because it issues reset and activation links through its own
 * notifications rather than Laravel's built-in mail, which means minting and
 * retiring tokens by hand.
 *
 * Those calls therefore worked only because the default binding happens to be
 * the concrete class. Bind a custom broker that satisfies the contract — which
 * the contract exists to permit — and password reset and account activation
 * both fail with "Call to undefined method", at the moment a user is locked out
 * and least able to tolerate it.
 *
 * This is an `instanceof` check rather than a cast or an annotation, so the
 * guarantee is real at runtime and not merely asserted to the analyser: a
 * replaced broker fails here, immediately and with an explanation, instead of
 * failing three frames deeper on a missing method.
 *
 * Found by PHPStan — in CI, on 2026-09-16, the first run in which it had ever
 * executed. A local run of the same version against the same lockfile reported
 * nothing, which looked like a platform divergence and was not one: Larastan's
 * ContractsMethodsExtension resolves every Illuminate\Contracts interface
 * through the live container and grants it the bound concrete class's methods,
 * so the contract is checked against the default binding rather than against
 * itself. CI's backend job had no `.env` at the time, that resolution threw on a
 * null `app.key`, and the widening did not happen. It has a `.env` now, so **CI
 * no longer reports this and would not catch a reintroduction** — see
 * docs/audit/BASELINE.md §12c. The check below, not the analyser, is what makes
 * the guarantee hold; PasswordBrokerNarrowingTest pins it.
 */
final class PasswordTokens
{
    public static function broker(): PasswordBroker
    {
        $broker = Password::broker();

        if (! $broker instanceof PasswordBroker) {
            throw new RuntimeException(sprintf(
                'The configured password broker is %s, which cannot mint reset tokens. '
                .'ETHR issues its own reset and activation links and needs '
                .'Illuminate\Auth\Passwords\PasswordBroker (createToken/tokenExists/deleteToken).',
                get_debug_type($broker),
            ));
        }

        return $broker;
    }
}
