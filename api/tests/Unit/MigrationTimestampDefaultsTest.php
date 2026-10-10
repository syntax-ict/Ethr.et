<?php

declare(strict_types=1);

/**
 * A NOT NULL `timestamp` column must state its default.
 *
 * The production database (MySQL 8, Ethio Telecom shared hosting) runs with
 * `explicit_defaults_for_timestamp` OFF, and the `mysql` connection is strict.
 * Under that pair, a `timestamp` column with neither NULL nor a default:
 *
 *   - is the table's FIRST such column: it silently becomes
 *     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, so every later
 *     UPDATE of the row overwrites it (a sync log's `started_at`, an OTP's
 *     `expires_at`);
 *   - is any LATER one: it defaults to '0000-00-00 00:00:00', which strict
 *     mode rejects — "1067 Invalid default value".
 *
 * Measured 2026-10-10: the first `migrate` on the host failed with 1067 on
 * `trusted_devices.expires_at`, inside the very first migration, after its
 * earlier tables had already been created. Local runs and CI never saw it:
 * they use the `mariadb` connection, which is not strict.
 *
 * `->nullable()`, `->useCurrent()` or `->default(...)` each state a default,
 * and a stated default also stops MySQL adding the automatic ON UPDATE.
 */
it('gives every NOT NULL timestamp column an explicit default', function () {
    $files = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];

    // A broken glob would report the rule as held while checking nothing.
    expect(count($files))->toBeGreaterThan(50);

    $offenders = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        // Each column definition runs from `->timestamp(` to the end of its statement.
        preg_match_all('/->timestamp(?:Tz)?\(\s*[\'"]([a-z0-9_]+)[\'"][^;]*;/i', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $statement = $match[0][0];

            if (preg_match('/->(nullable|useCurrent|default)\(/', $statement) === 1) {
                continue;
            }

            $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
            $offenders[] = basename($file).':'.$line.' '.$match[1][0];
        }
    }

    expect($offenders)->toBe([], 'These NOT NULL timestamp columns state no default. On the production MySQL '
        .'(explicit_defaults_for_timestamp OFF, strict mode) the first in a table silently gains '
        .'ON UPDATE CURRENT_TIMESTAMP and any later one fails the migration with 1067. Add '
        .'->useCurrent(), ->nullable() or ->default(...):'."\n  ".implode("\n  ", $offenders));
});
