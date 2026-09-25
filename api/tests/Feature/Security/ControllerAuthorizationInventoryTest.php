<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Convention #7 — no unprotected endpoints.
 *
 * The convention's wording names `Policy` or `Gate`. The codebase authorizes
 * four ways: an inline `Gate::authorize` / `$this->authorize`; a same-class
 * private helper that throws (`ExecutiveDashboardController`,
 * `AlertThresholdController`, `DashboardDigestController`); a
 * `FormRequest::authorize()` that is not `return true`; and — for a large and
 * legitimate set — `auth:sanctum` plus a query scoped to `$request->user()`.
 * A sweep written from the literal wording flags all four, reports mostly false
 * positives, and gets ignored. This one classifies, then pins the residue.
 *
 * The route surface comes from the live router rather than from parsing
 * `routes/api.php`, and guard detection is tokenised. Nothing here matches
 * words in raw file text — that is the mistake Convention #1 records, where
 * `preg_match_all` counted five docblocks as tenant-scope bypasses for four
 * days.
 *
 * What this buys: adding an endpoint with no authorization construct becomes a
 * deliberate act. What it does not buy: anything about the entries already
 * pinned. `routes-without-authorization.php` carries a reason per entry and
 * states which half of it was read individually.
 *
 * Scope: actions under `App\Http\Controllers\Api\V1`. A route served by a
 * vendor controller (Scramble's `api/docs`) or by a closure is not covered,
 * and neither is anything the router does not know about at test time.
 */

/** @return array<int, string> */
function authzGuards(): array
{
    return [
        // Throw on failure.
        'authorize', 'authorizeForUser', 'abort_if', 'abort_unless',
        // Return bool; every current caller throws on false.
        'allows', 'denies', 'hasPermission', 'hasAnyPermission', 'isSuperAdmin',
    ];
}

/** @return array<int, array{0: int, 1: string, 2: int}> */
function authzMethodTokens(ReflectionMethod $method): array
{
    static $cache = [];

    $file = $method->getFileName();

    if ($file === false) {
        return [];
    }

    $cache[$file] ??= token_get_all((string) file_get_contents($file));

    $start = $method->getStartLine();
    $end = $method->getEndLine();

    // Single-character tokens carry no line number and nothing below needs
    // them: guard detection reads T_STRING, and the one place that needs whole
    // statements slices the source by line instead.
    return array_values(array_filter(
        $cache[$file],
        fn ($token) => is_array($token) && $token[2] >= $start && $token[2] <= $end,
    ));
}

/**
 * The method's source with comments removed and whitespace collapsed.
 *
 * Comments are dropped through the lexer rather than by pattern, and that is
 * the whole reason this function exists: seventeen of the codebase's
 * `authorize()` methods read `return true; // Gate::authorize(...) runs in the
 * controller.` A triviality test that matched raw text would call every one of
 * them an authorization check and quietly excuse seventeen endpoints.
 */
function authzMethodSource(ReflectionMethod $method): string
{
    $file = $method->getFileName();

    if ($file === false) {
        return '';
    }

    $lines = (array) file($file);
    $slice = array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

    $clean = '';
    foreach (token_get_all('<?php '.implode('', $slice)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
            continue;
        }

        $clean .= is_array($token) ? $token[1] : $token;
    }

    return trim((string) preg_replace('/\s+/', ' ', $clean));
}

function authzGuardIn(ReflectionMethod $method): ?string
{
    $guards = authzGuards();

    foreach (authzMethodTokens($method) as $token) {
        if ($token[0] === T_STRING && in_array($token[1], $guards, true)) {
            return $token[1];
        }
    }

    return null;
}

/**
 * Names reached as `$this->name`. A property read of the same name matches too;
 * harmless, because the caller keeps only names that are declared methods *and*
 * contain a guard.
 *
 * @return array<int, string>
 */
function authzSelfCalls(ReflectionMethod $method): array
{
    $tokens = authzMethodTokens($method);
    $names = [];

    foreach ($tokens as $i => $token) {
        if ($token[0] !== T_VARIABLE || $token[1] !== '$this') {
            continue;
        }

        $arrow = $tokens[$i + 1] ?? null;
        $name = $tokens[$i + 2] ?? null;

        if ($arrow !== null && $arrow[0] === T_OBJECT_OPERATOR && $name !== null && $name[0] === T_STRING) {
            $names[] = $name[1];
        }
    }

    return array_values(array_unique($names));
}

function authzHelperGuard(ReflectionClass $class, ReflectionMethod $method, int $depth = 2): ?string
{
    if ($depth === 0) {
        return null;
    }

    foreach (authzSelfCalls($method) as $name) {
        if (! $class->hasMethod($name) || $name === $method->getName()) {
            continue;
        }

        $helper = $class->getMethod($name);

        if ($guard = authzGuardIn($helper)) {
            return "{$name}() → {$guard}";
        }

        if ($nested = authzHelperGuard($class, $helper, $depth - 1)) {
            return "{$name}() → {$nested}";
        }
    }

    return null;
}

/** A FormRequest parameter whose authorize() is more than `return true`. */
function authzFormRequestGuard(ReflectionMethod $method): ?string
{
    foreach ($method->getParameters() as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        $name = $type->getName();

        if (! str_starts_with($name, 'App\\Http\\Requests\\') || ! method_exists($name, 'authorize')) {
            continue;
        }

        if (! str_ends_with(authzMethodSource(new ReflectionMethod($name, 'authorize')), '{ return true; }')) {
            return class_basename($name).'::authorize()';
        }
    }

    return null;
}

/** @return array<int, RoutingRoute> */
function authzApiRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        function (RoutingRoute $route): bool {
            $action = $route->getActionName();

            return str_starts_with($action, 'App\\Http\\Controllers\\Api\\V1\\') && str_contains($action, '@');
        },
    ));
}

/** @return array{authenticated: array<int, string>, public: array<int, string>} */
function authzUnguardedActions(): array
{
    $unguarded = ['authenticated' => [], 'public' => []];

    foreach (authzApiRoutes() as $route) {
        $action = $route->getActionName();
        [$class, $methodName] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $methodName)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        $method = $reflection->getMethod($methodName);

        $guarded = authzGuardIn($method)
            ?? authzHelperGuard($reflection, $method)
            ?? authzFormRequestGuard($method);

        if ($guarded !== null) {
            continue;
        }

        $bucket = in_array('auth:sanctum', $route->gatherMiddleware(), true) ? 'authenticated' : 'public';
        $unguarded[$bucket][] = $action;
    }

    foreach (['authenticated', 'public'] as $bucket) {
        $unguarded[$bucket] = array_values(array_unique($unguarded[$bucket]));
        sort($unguarded[$bucket]);
    }

    return $unguarded;
}

test('no API route reaches an unauthorized action that is not pinned', function (string $bucket) {
    /** @var array<string, array<string, string>> $pinned */
    $pinned = require __DIR__.'/routes-without-authorization.php';

    $expected = array_keys($pinned[$bucket]);
    sort($expected);

    $message = implode("\n", [
        "The set of {$bucket} routes with no authorization construct has moved.",
        '',
        'If an action LEFT the set it gained a guard — delete its line from',
        'tests/Feature/Security/routes-without-authorization.php.',
        '',
        'If an action ENTERED it, answer the question that file exists to ask:',
        'does this action narrow to the caller\'s own rows, is it tenant-wide on',
        'purpose, or can an authenticated user now reach data they should not?',
        'Add a line saying which. Do not add one that only says it is fine.',
    ]);

    test()->assertSame($expected, authzUnguardedActions()[$bucket], $message);
})->with(['authenticated', 'public']);

test('the inventory reflects the real route surface', function () {
    // Guard the guard. Every assertion above passes trivially against an empty
    // router — a changed namespace, a routes file that stopped loading, a
    // gatherMiddleware() that no longer reports the alias. This is the floor
    // those failures fall through.
    $routes = authzApiRoutes();

    expect(count($routes))->toBeGreaterThan(250);

    $authenticated = array_filter(
        $routes,
        fn (RoutingRoute $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true),
    );

    expect(count($authenticated))->toBeGreaterThan(200);

    // And the classifier has to be doing work. If everything came back
    // unguarded, or nothing did, the pin above would still hold across a
    // rewrite that broke the token walk.
    $unguarded = authzUnguardedActions();

    expect(count($unguarded['authenticated']) + count($unguarded['public']))
        ->toBeLessThan(intdiv(count($routes), 2));
});
