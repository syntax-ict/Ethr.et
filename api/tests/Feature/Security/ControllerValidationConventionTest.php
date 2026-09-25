<?php

declare(strict_types=1);

/**
 * Convention #6: "All validation in dedicated `FormRequest` classes. No inline
 * `$request->validate()`."
 *
 * Documented since v1.0 and enforced by nothing. When this test was written
 * there was exactly **one** violation —
 * `ShiftRotationController::preview()` — out of 305 validation sites, and that
 * is precisely why it survived: a lone exception in an otherwise uniform
 * codebase is invisible to code review, which sees one file at a time, and to
 * a reader who has internalised the convention and stops looking.
 *
 * `SoftDeletePolicyTest` records the same lesson about the soft-delete table:
 * **a documented control nobody runs is worse than an admitted gap, because it
 * stops people looking.** This file is the control.
 *
 * ## What it buys, stated exactly
 *
 * It asserts that no controller calls `validate()` on a request. It does NOT
 * assert that every endpoint's FormRequest validates the right things, or that
 * one exists at all — a controller that validates nothing passes this cleanly.
 * Convention #6's other half ("every endpoint has a FormRequest") needs the
 * route surface, which is a different test and is not written.
 *
 * ## Why the check is tokenised
 *
 * Root `CLAUDE.md` records what text-matching cost the tenant-scope inventory:
 * `preg_match_all` over raw source counted five docblock mentions of
 * `withoutGlobalScopes()` as real call sites, inflating a security figure by a
 * constant +5 for four days. A convention gate that a comment can trip is a
 * gate people learn to route around. `token_get_all()` cannot see into a
 * comment or a string.
 */
function ethrControllerFiles(): array
{
    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(base_path()).'/api/app/Http/Controllers')
    );

    $files = [];

    foreach ($dir as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Every `->validate(` call whose RECEIVER IS A VARIABLE, by token.
 *
 * That qualifier is the whole precision of this check, and it was learned the
 * hard way: the first draft matched any `->validate(` and immediately flagged
 * `QrAttendanceController:84`, which is `$this->qrService->validate($token)` —
 * a domain service that happens to have a method of that name, in a controller
 * already using a FormRequest correctly. A gate whose first finding is wrong is
 * a gate people learn to route around, and this file's own docblock warns
 * against widening it on a guess.
 *
 * The token shapes separate cleanly:
 *
 *   $request->validate(          tokens[i-2] is T_VARIABLE ($request)   FLAG
 *   $this->validate(             tokens[i-2] is T_VARIABLE ($this)      FLAG
 *   $this->qrService->validate(  tokens[i-2] is T_STRING  (qrService)   SKIP
 *
 * So it catches `$request`, `$this`, and a request under any other variable
 * name — which plain `grep '$request->validate('` does not — while a service
 * reached through a property is left alone. `Validator::make(...)` is out of
 * scope deliberately: a different construct with legitimate uses.
 *
 * @return array<int, int> line numbers
 */
function ethrInlineValidateLines(string $path): array
{
    $tokens = token_get_all((string) file_get_contents($path));
    $lines = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'validate') {
            continue;
        }

        $arrow = $tokens[$i - 1] ?? null;

        if (! is_array($arrow) || $arrow[0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        $receiver = $tokens[$i - 2] ?? null;

        if (! is_array($receiver) || $receiver[0] !== T_VARIABLE) {
            continue;
        }

        $lines[] = $token[2];
    }

    return $lines;
}

test('no controller validates inline — convention #6', function () {
    $offenders = [];

    foreach (ethrControllerFiles() as $path) {
        $lines = ethrInlineValidateLines($path);

        if ($lines !== []) {
            $relative = substr($path, strpos($path, 'app/Http/Controllers') ?: 0);
            $offenders[] = $relative.':'.implode(',', $lines);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These controllers validate inline, which convention #6 forbids:',
        '  '.implode("\n  ", $offenders),
        '',
        'Move the rules into a FormRequest under app/Http/Requests/ and type-hint it',
        'on the action. Two things to get right while you do:',
        '',
        '  1. NO explanatory comments above a key in rules(). Scramble generates',
        '     src/src/api/generated.ts from that array and publishes them as OpenAPI',
        '     descriptions, verbatim — see generated.ts:5270 for a live one.',
        '  2. Moving validation changes what Scramble sees, so the API contract gate',
        '     may need a regenerated generated.ts in the same change.',
    ]));
});

test('the sweep actually reads controllers, so an empty pass means something', function () {
    // Guard the guard. If the directory walk broke — a moved path, a changed
    // extension filter — the test above would pass with zero files scanned and
    // report the convention as held while checking nothing. That failure mode
    // is the one this repository keeps finding: `vendor/bin/pest` collecting 21
    // of 132 classes and exiting 0 green.
    $files = ethrControllerFiles();

    expect(count($files))->toBeGreaterThan(50);
    expect($files)->toContain(dirname(base_path()).'/api/app/Http/Controllers/Api/V1/Shift/ShiftRotationController.php');
});
