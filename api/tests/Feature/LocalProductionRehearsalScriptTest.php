<?php

declare(strict_types=1);

/**
 * The local production rehearsal must not be able to eat the developer's own database.
 *
 * WHY. `scripts/local-production/up.sh` reproduces the Bronze deployment procedure
 * locally: it creates a database, ALTERs its user's password, migrates into it, and
 * assembles a document root. Its first version took `DB_NAME=ethr` / `DB_USER=ethr`
 * as defaults "because those are the documented defaults" — which is true, and is
 * precisely the problem: `api/.env.example` hands every developer those same two names,
 * so the rehearsal's default target was the one database guaranteed to already exist.
 *
 * On 2026-09-27 it did exactly that on this machine. Two silent mechanisms:
 *
 *   CREATE DATABASE IF NOT EXISTS ethr   -> did not create; ADOPTED a schema holding
 *                                           2 tenants and 151 employees, then ran 5
 *                                           pending migrations into it
 *   ALTER USER 'ethr'@... IDENTIFIED BY  -> CHANGED the password api/.env uses, which
 *                                           is empty
 *
 * Every subsequent local command then failed with
 * `ERROR 1045 (28000) Access denied ... (using password: NO)` — a message that reads
 * like a wrong password rather than like something a setup script did. Nothing was
 * lost, and that was luck: the script's own header claimed an isolation it did not have.
 *
 * WHAT IT ASSERTS, and why each one is here rather than being obvious:
 *
 *  1. The defaults differ from the names `api/.env.example` ships. Derived from that
 *     file rather than from the literal `ethr`, so renaming the dev database does not
 *     quietly re-open the hole.
 *  2. The collision guard exists on executable lines — comments explaining the incident
 *     must not be able to satisfy it. This repository has twice had a negative assertion
 *     pass against its own prose.
 *  3. Both of the guard's greps end in `|| true`. This is the one assertion that pins an
 *     invisible defect: under `set -euo pipefail` a grep matching nothing returns 1,
 *     pipefail carries it through `cut` and `tr`, and the assignment exits the script —
 *     so an `api/.env` with no `DB_DATABASE=` line killed up.sh immediately after the
 *     step-2 header with **no message at all**. Measured 2026-09-28. A missing line must
 *     mean "no collision possible", never "abort mysteriously".
 *  4. Neither script tells the reader to drop the dev database. `down.sh`'s header and
 *     `LOCAL-PRODUCTION-SETUP.md` §5 both carried `DROP DATABASE ethr` as cleanup advice.
 *
 * WHAT IT DOES NOT ASSERT. It does not execute `up.sh` — that needs XAMPP's Apache and a
 * MariaDB server, neither of which exists on a CI runner, and step 1 copies `api/`
 * including `vendor/`. Both directions of the guard were verified by running it
 * (refuses on `DB_NAME=ethr` and on `DB_USER=ethr`; proceeds otherwise), and this file
 * pins the shape so that result stays true. Running it is what found defect 3.
 *
 * No database, no network.
 */
function ethrRehearsalScript(string $name): string
{
    $path = dirname(base_path()).'/scripts/local-production/'.$name;

    expect(file_exists($path))->toBeTrue("scripts/local-production/{$name} is missing");

    return (string) file_get_contents($path);
}

/**
 * The lines of a shell script that can actually run, with comments removed.
 *
 * `up.sh` documents the incident above in ~25 lines of comment that name `ethr`,
 * `CREATE DATABASE IF NOT EXISTS ethr` and `ALTER USER 'ethr'@...` verbatim. A check
 * over raw text would find every string it is looking for inside that explanation and
 * pass while the guard itself was gone — the exact trap `CLAUDE.md` records for the
 * tenant-scope inventory, one scale down.
 */
function ethrRehearsalLiveLines(string $source): string
{
    $live = [];

    foreach (explode("\n", $source) as $line) {
        if (preg_match('/^\s*#/', $line) === 1) {
            continue;
        }

        $live[] = $line;
    }

    return implode("\n", $live);
}

/** The DB_* default in a `VAR="${VAR:-default}"` assignment, from executable lines only. */
function ethrRehearsalDefault(string $live, string $var): ?string
{
    $found = preg_match(
        '/^'.preg_quote($var, '/').'="\$\{'.preg_quote($var, '/').':-([^}]*)\}"/m',
        $live,
        $m
    );

    return $found === 1 ? $m[1] : null;
}

/** What `api/.env.example` hands every developer — the names most likely to already exist. */
function ethrCommittedDevDbNames(): array
{
    $example = (string) file_get_contents(base_path('.env.example'));

    $read = function (string $key) use ($example): ?string {
        return preg_match('/^'.$key.'=(.*)$/m', $example, $m) === 1 ? trim($m[1]) : null;
    };

    return [$read('DB_DATABASE'), $read('DB_USERNAME')];
}

it('does not default to the database api/.env.example hands every developer', function () {
    [$devDb, $devUser] = ethrCommittedDevDbNames();

    expect($devDb)->not->toBeNull('api/.env.example declares no DB_DATABASE');
    expect($devUser)->not->toBeNull('api/.env.example declares no DB_USERNAME');

    $live = ethrRehearsalLiveLines(ethrRehearsalScript('up.sh'));

    $dbName = ethrRehearsalDefault($live, 'DB_NAME');
    $dbUser = ethrRehearsalDefault($live, 'DB_USER');

    expect($dbName)->not->toBeNull('up.sh sets no DB_NAME default on an executable line');
    expect($dbUser)->not->toBeNull('up.sh sets no DB_USER default on an executable line');

    expect($dbName !== $devDb)->toBeTrue(
        "up.sh defaults DB_NAME to '{$dbName}', which is what api/.env.example uses. ".
        'The rehearsal migrates into that schema and resets its user password.'
    );

    expect($dbUser !== $devUser)->toBeTrue(
        "up.sh defaults DB_USER to '{$dbUser}', which is what api/.env.example uses. ".
        'ALTER USER on it changes the password the dev environment authenticates with.'
    );
});

it('refuses, on an executable line, when the target is what api/.env already uses', function () {
    $live = ethrRehearsalLiveLines(ethrRehearsalScript('up.sh'));

    // It must read the developer's ACTUAL .env, not compare against a hardcoded name:
    // a developer who renamed their dev database is still protected.
    foreach (['DB_DATABASE', 'DB_USERNAME'] as $key) {
        expect(str_contains($live, "'^{$key}='"))->toBeTrue(
            "up.sh does not read {$key} out of api/.env, so it cannot detect a collision ".
            'with a dev database that was renamed.'
        );
    }

    foreach (['DB_NAME', 'DB_USER'] as $var) {
        $refuses = preg_match(
            '/\[\s*"\$'.$var.'"\s*=\s*"\$dev_(db|user)"\s*\]\s*&&\s*die/',
            $live
        ) === 1;

        expect($refuses)->toBeTrue(
            "up.sh has no executable guard comparing {$var} against api/.env and calling die. ".
            'Without it, "create if not exists" silently means "adopt".'
        );
    }
});

it('does not exit silently when api/.env carries no DB_DATABASE line', function () {
    $live = ethrRehearsalLiveLines(ethrRehearsalScript('up.sh'));

    // set -euo pipefail + a grep that matches nothing = the script dies with no message.
    // Reproduced 2026-09-28; `|| true` is the whole fix and is invisible on inspection.
    expect(str_contains($live, 'set -euo pipefail'))->toBeTrue(
        'up.sh no longer sets -euo pipefail; this test pins a consequence of it.'
    );

    foreach (['dev_db', 'dev_user'] as $var) {
        $guarded = preg_match(
            '/'.$var.'="\$\(grep -m1 [^\n]*\|\|\s*true\)"/',
            $live
        ) === 1;

        expect($guarded)->toBeTrue(
            "up.sh's {$var} assignment does not end in `|| true`. Under set -e with ".
            'pipefail, an api/.env lacking that key makes the grep return 1 and exits '.
            'the script with no message, immediately after the step-2 header.'
        );
    }
});

it('never tells anyone to drop the database api/.env uses', function () {
    [$devDb] = ethrCommittedDevDbNames();

    $targets = [
        'scripts/local-production/down.sh' => ethrRehearsalScript('down.sh'),
        'scripts/local-production/up.sh' => ethrRehearsalScript('up.sh'),
        'docs/deployment/LOCAL-PRODUCTION-SETUP.md' => (string) file_get_contents(
            dirname(base_path()).'/docs/deployment/LOCAL-PRODUCTION-SETUP.md'
        ),
    ];

    foreach ($targets as $label => $source) {
        // Comments are NOT stripped here on purpose: cleanup advice in a header comment is
        // advice a developer follows. `down.sh` carried `DROP DATABASE ethr` in exactly
        // that position, and the doc carried it in a copy-pasteable fenced block.
        $matched = preg_match_all('/DROP\s+DATABASE\s+`?([A-Za-z0-9_]+)`?/i', $source, $m);

        if ($matched === 0 || $matched === false) {
            continue;
        }

        foreach ($m[1] as $named) {
            expect($named !== $devDb)->toBeTrue(
                "{$label} tells the reader to DROP DATABASE {$named}, which is the ".
                'database api/.env.example configures. That destroys the dev environment.'
            );
        }
    }
});

it('documents the dedicated database it actually uses', function () {
    $live = ethrRehearsalLiveLines(ethrRehearsalScript('up.sh'));
    $doc = (string) file_get_contents(
        dirname(base_path()).'/docs/deployment/LOCAL-PRODUCTION-SETUP.md'
    );

    // The doc's local-vs-production table and its knobs table both named the old
    // defaults for a day after the script stopped using them, which is how a reader
    // ends up setting DB_NAME=ethr by hand and hitting the very defect the guard exists
    // to prevent.
    foreach (['DB_NAME', 'DB_USER'] as $var) {
        $default = ethrRehearsalDefault($live, $var);

        expect($default)->not->toBeNull("up.sh sets no {$var} default");
        expect(str_contains($doc, (string) $default))->toBeTrue(
            "LOCAL-PRODUCTION-SETUP.md never mentions '{$default}', the {$var} default ".
            'up.sh actually uses. The documented default and the real one have drifted.'
        );
    }
});
