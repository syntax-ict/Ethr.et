<?php

declare(strict_types=1);

/**
 * No script may be wired to a compose file that does not exist.
 *
 * WHY. `3db9904` deleted `docker-compose.{prod,lowmem,hostnames}.yml`.
 * `scripts/{backup,restore,seed}.sh` were kept, and all three still opened with
 *
 *     COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
 *
 * so every one of them failed at its first `docker compose` call. That is bad on
 * its own; what made it worse is that `docs/deployment/VPS-DECOMMISSION.md` was
 * simultaneously holding `backup.sh` and `restore.sh` on the grounds that
 * deleting them would "remove a working path in favour of an untested one". The
 * path was not working. The repository believed it had a backup fallback it did
 * not have, and nothing in the test suite or the docs gate could notice: the docs
 * gate checks relative markdown links, and a dangling `COMPOSE_FILE` default is
 * neither a link nor a syntax error.
 *
 * `./scripts/gates.sh docs` was green throughout. This is the check that would
 * not have been.
 *
 * WHAT IT ASSERTS. Since 2026-09-30, that no reachable line of any
 * `scripts/**.sh` runs `docker` at all. Until then it asserted the narrower
 * "every `docker-compose*.yml` a script names exists"; the Docker development
 * stack was removed that day — production is shared hosting and so is the one
 * local setup — so any executable docker call is now a reference to a removed
 * asset. Comments are exempt, since scripts deliberately cite deleted assets to
 * explain what replaced them. Annotated provenance is the habit this repository
 * wants; an executable reference to a missing tool is not.
 *
 * No database, no network.
 */
function ethrShellScripts(): array
{
    $root = dirname(base_path());
    $found = [];

    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/scripts', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($dir as $file) {
        if ($file->isFile() && $file->getExtension() === 'sh') {
            // Normalise separators: getPathname() returns the OS separator for
            // every component below the directory it was handed, so on Windows
            // this comes back half forward-slashed. `ControllerValidationConventionTest`
            // records the same trap costing a guard test its entire purpose.
            $found[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($found);

    return $found;
}

/**
 * The lines of a shell script that can actually execute a command.
 *
 * Three things are excluded, and each was found the hard way writing this test:
 *
 *   comments            a `#` line may name a deleted asset on purpose; that
 *                       annotation is how a reader learns where the capability
 *                       went, and this repository treats it as the good habit.
 *   heredoc bodies      `cat >&2 <<'RETIRED' … RETIRED` is data. Both retired
 *                       scripts print usage text naming `docker compose exec`, and
 *                       reading that as a command makes a correct file look like
 *                       one that runs docker before refusing to.
 *   after a top-level   an unconditional `exit N` at column 0 ends the reachable
 *   unconditional exit  part of the file. Everything below is a record — which is
 *                       how backup.sh keeps the VPS implementation readable after
 *                       retiring it.
 *
 * @return array<int, string> line number (1-based) => line
 */
function ethrReachableShellLines(string $path): array
{
    $out = [];
    $heredocTerminator = null;

    foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
        if ($heredocTerminator !== null) {
            if (trim($line) === $heredocTerminator) {
                $heredocTerminator = null;
            }

            continue;
        }

        // <<EOF, <<'EOF', <<"EOF", <<-EOF
        if (preg_match('/<<-?\s*[\'"]?([A-Za-z_][A-Za-z0-9_]*)[\'"]?/', $line, $m) === 1) {
            $heredocTerminator = $m[1];
            // The line opening the heredoc IS executable (it is the `cat` call),
            // so it still counts — only the body that follows does not.
            $out[$index + 1] = $line;

            continue;
        }

        if (preg_match('/^exit\s+\d+\s*$/', $line) === 1) {
            break;
        }

        if (preg_match('/^\s*#/', $line) === 1 || trim($line) === '') {
            continue;
        }

        $out[$index + 1] = $line;
    }

    return $out;
}

it('finds the shell scripts it is supposed to be checking', function () {
    $scripts = ethrShellScripts();

    // Guard the guard. A broken directory walk would report the convention as
    // held while scanning nothing — the failure mode that let `vendor/bin/pest`
    // collect 21 of 132 classes and exit 0 green.
    expect(count($scripts))->toBeGreaterThan(5);

    $names = array_map(fn (string $p): string => basename($p), $scripts);

    expect($names)->toContain('gates.sh');
    expect($names)->toContain('deploy.sh');   // scripts/shared-hosting/deploy.sh
});

it('never runs docker, which this repository no longer has', function () {
    $root = dirname(base_path());
    $offences = [];

    foreach (ethrShellScripts() as $script) {
        foreach (ethrReachableShellLines($script) as $number => $line) {
            if (preg_match('/\bdocker\b|docker-compose[A-Za-z0-9._-]*\.yml/', $line) !== 1) {
                continue;
            }

            $offences[] = sprintf(
                '%s:%d runs docker: %s',
                str_replace(str_replace('\\', '/', $root).'/', '', $script),
                $number,
                trim($line)
            );
        }
    }

    expect(is_file($root.'/docker-compose.yml'))->toBeFalse(
        'docker-compose.yml is back. The Docker development stack was removed on 2026-09-30; '
        .'if it is being reinstated deliberately, rewrite this test rather than delete it.'
    );

    expect($offences)->toBe(
        [],
        "a script still runs docker, which was removed on 2026-09-30:\n  ".implode("\n  ", $offences)
        ."\n\nPoint the script at native PHP / XAMPP (scripts/local-production/) or retire it "
        .'explicitly. Do not leave an executable reference to a removed tool — that is how '
        .'VPS-DECOMMISSION.md came to describe backup.sh as "a working path" for a day while '
        .'it could not run at all.'
    );
});

it('keeps the retired VPS scripts refusing rather than half-working', function () {
    $root = dirname(base_path());

    // These two are HELD, not deleted: VPS-DECOMMISSION.md commits them to go out
    // after the Stage 6 host restore rehearsal, which has not happened. While they
    // are here they must not look usable — a script that runs three commands and
    // then dies has already stopped services.
    foreach (['backup.sh', 'restore.sh'] as $retired) {
        $path = $root.'/scripts/'.$retired;

        expect(is_file($path))->toBeTrue("scripts/{$retired} is missing. Deleting it needs the Stage 6 rehearsal first.");

        $body = (string) file_get_contents($path);

        // str_contains rather than ->toContain($needle, $message): Pest's string
        // toContain takes VARARGS of needles, so a second argument meant as a
        // failure message silently becomes a second thing being asserted.
        expect(str_contains($body, 'RETIRED'))->toBeTrue(
            "scripts/{$retired} no longer announces that it is retired."
        );

        // The refusal has to come before any docker call, or it is not a refusal:
        // a script that stops three services and then dies has already done harm.
        expect($body)->toMatch(
            '/^exit\s+64\s*$/m',
            "scripts/{$retired} does not unconditionally exit 64"
        );

        // ethrReachableShellLines() stops at that exit and skips comments and
        // heredoc bodies, so what is left is only what the shell would run.
        // Both halves of that matter here: the refusal message names
        // `docker compose exec api php artisan ethr:backup`, and reading either
        // the comment or the heredoc as code condemns a correct file.
        foreach (ethrReachableShellLines($path) as $number => $line) {
            expect($line)->not->toMatch(
                '/\bdocker\b/',
                sprintf(
                    'scripts/%s:%d runs docker before it refuses: %s',
                    $retired,
                    $number,
                    trim($line)
                )
            );
        }

        // And it must name the replacement, so the refusal is useful rather than
        // merely correct.
        expect(str_contains($body, 'ethr:backup') || str_contains($body, 'ethr:restore'))
            ->toBeTrue("scripts/{$retired} does not name what replaced it.");
    }
});
