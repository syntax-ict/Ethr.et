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

/**
 * PHPStan found the original defect, and PHPStan can no longer find it again.
 *
 * Larastan's ContractsMethodsExtension resolves every `Illuminate\Contracts`
 * interface through the live container and grants it the bound concrete class's
 * methods — so a receiver typed as the contract is checked against the *default
 * binding*, not against the contract. That widening is exactly the assumption
 * this class exists to stop relying on.
 *
 * It reported the four errors in CI run #56 only because the backend job had no
 * `.env`, which made `config('app.key')` null, which made resolving the broker
 * throw, which made the widening not happen. That job has had a `.env` since
 * `b33adde`, so the analyser is permissive again and a reintroduction would pass
 * it silently. See docs/audit/BASELINE.md §12c for the full trace.
 *
 * Hence a source-level pin, in the same spirit as TenantScopeBypassInventoryTest:
 * the guarantee is that `Password::broker()` is reached through one audited
 * place, and a grep is the only check that still holds when the analyser does not.
 */
it('routes every broker call through PasswordTokens, because PHPStan no longer can', function () {
    $appPath = dirname(__DIR__, 3).'/app';

    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // The one place allowed to call it — it is the narrowing itself.
        if ($file->getRealPath() === realpath($appPath.'/Support/PasswordTokens.php')) {
            continue;
        }

        if (preg_match('/Password::broker\(/', (string) file_get_contents((string) $file->getRealPath())) === 1) {
            $offenders[] = substr((string) $file->getRealPath(), strlen($appPath) + 1);
        }
    }

    expect($offenders)->toBe([], sprintf(
        'These files call Password::broker() directly: %s. Use PasswordTokens::broker(), '
        .'which checks the binding really is Illuminate\Auth\Passwords\PasswordBroker. '
        .'The contract declares only sendResetLink() and reset(); createToken(), '
        .'tokenExists() and deleteToken() are not on it, and PHPStan will not tell you.',
        implode(', ', $offenders),
    ));
});
