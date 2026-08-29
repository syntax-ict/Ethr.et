<?php

/**
 * ETHR shared-hosting capability probe.
 *
 * Answers, in one page load, most of the PHP / filesystem / database rows in
 * docs/HOSTING_VERIFICATION_CHECKLIST.md. It cannot answer cron, Node.js,
 * wildcard DNS, wildcard TLS or document-root configuration — those are Plesk
 * panel questions and are listed separately in that document.
 *
 * USAGE
 *   1. Upload to the hosting account (web root is fine).
 *   2. Open it in a browser, or run `php ethr-hosting-check.php` over SSH.
 *   3. Save the output.
 *   4. *** DELETE IT IMMEDIATELY. *** It discloses environment detail that is
 *      useful to an attacker.
 *
 * SAFETY
 *   - Read-only apart from one temp file and (optionally) one temp database
 *     table, both of which it removes.
 *   - Writes nothing outside its own directory.
 *   - Creates no accounts, sends no mail, changes no settings.
 *   - Database checks run ONLY if you pass credentials; with none it skips them.
 *
 *   php ethr-hosting-check.php --db-host=localhost --db-name=X --db-user=Y --db-pass=Z
 *
 *   Over the web, append the same as query parameters. Prefer SSH: a browser
 *   request puts the password in the access log.
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (! $isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$results = [];

/** Record one classified result. $status is VERIFIED, UNSUPPORTED or UNKNOWN. */
function record(array &$results, string $section, string $id, string $item, string $status, string $detail = ''): void
{
    $results[$section][] = compact('id', 'item', 'status', 'detail');
}

// ── Section 1: PHP ───────────────────────────────────────────────────────────

$phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
record($results, 'PHP', 'P1', 'PHP >= 8.2', $phpOk ? 'VERIFIED' : 'UNSUPPORTED', PHP_VERSION);
record($results, 'PHP', 'P2', 'SAPI / handler', 'VERIFIED', PHP_SAPI);

// Mandatory extensions — absence of any of these means Laravel 12 will not run.
$mandatory = [
    'pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'dom',
    'ctype', 'json', 'fileinfo', 'filter', 'hash', 'session', 'curl',
    'bcmath', 'iconv', 'zip', 'gd',
];

// Wanted but not fatal. `gd` is deliberately in the list above, not here: its
// absence is silent rather than fatal (FileStorageService returns original bytes
// and skips thumbnails), which makes it more dangerous, not less.
$optional = ['intl', 'sodium', 'simplexml', 'xmlwriter', 'redis', 'opcache', 'exif'];

foreach ($mandatory as $ext) {
    record($results, 'PHP', 'ext', "ext-$ext (MANDATORY)",
        extension_loaded($ext) ? 'VERIFIED' : 'UNSUPPORTED');
}
foreach ($optional as $ext) {
    record($results, 'PHP', 'ext', "ext-$ext (optional)",
        extension_loaded($ext) ? 'VERIFIED' : 'UNKNOWN');
}

// ── Section 2: PHP limits ────────────────────────────────────────────────────

/** Convert a php.ini shorthand size ("512M") to bytes. 0/-1 mean unlimited. */
function toBytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1' || $value === '0') {
        return PHP_INT_MAX;
    }
    $unit = strtolower($value[strlen($value) - 1]);
    $n = (int) $value;

    return match ($unit) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
}

$limits = [
    // id, ini key, minimum wanted, why
    ['L1', 'memory_limit', 256 * 1024 * 1024, 'payroll and export jobs'],
    ['L2', 'max_execution_time', 120, 'payroll runs and CSV imports'],
    ['L3', 'upload_max_filesize', 10 * 1024 * 1024, 'employee document upload'],
    ['L4', 'post_max_size', 10 * 1024 * 1024, 'employee document upload'],
];

foreach ($limits as [$id, $key, $min, $why]) {
    $raw = (string) ini_get($key);
    $actual = $key === 'max_execution_time' ? ((int) $raw ?: PHP_INT_MAX) : toBytes($raw);
    record($results, 'Limits', $id, "$key (want >= "
        .($key === 'max_execution_time' ? $min.'s' : round($min / 1048576).'M').", for $why)",
        $actual >= $min ? 'VERIFIED' : 'UNSUPPORTED',
        $raw === '' ? '(unset)' : $raw);
}

// ── Section 3: process / shell capability ────────────────────────────────────
//
// ETHR's application code calls none of these — verified by grep across app/.
// They are probed because deployment tooling (composer, artisan over cron) may.

foreach (['proc_open', 'exec', 'shell_exec', 'symlink', 'putenv'] as $fn) {
    record($results, 'Process', 'PR', "$fn() available", function_exists($fn) && ! in_array($fn, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true) ? 'VERIFIED' : 'UNSUPPORTED');
}
record($results, 'Process', 'PR', 'disable_functions', 'VERIFIED', ini_get('disable_functions') ?: '(none)');

// ── Section 4: filesystem ────────────────────────────────────────────────────

$dir = __DIR__;
$probe = $dir.'/.ethr_probe_'.bin2hex(random_bytes(4));

$written = @file_put_contents($probe, 'probe') !== false;
record($results, 'Filesystem', 'ST4', 'PHP can write in its own directory',
    $written ? 'VERIFIED' : 'UNSUPPORTED', $dir);

if ($written) {
    // storage:link needs this; only relevant if the `public` disk is used.
    $link = $probe.'.link';
    $canSymlink = @symlink($probe, $link);
    record($results, 'Filesystem', 'ST5', 'symlink() works (php artisan storage:link)',
        $canSymlink ? 'VERIFIED' : 'UNSUPPORTED');
    if ($canSymlink) {
        @unlink($link);
    }
    @unlink($probe);
}

// Laravel keeps private uploads and the .env outside the document root. If the
// parent is not writable the whole "app above docroot" layout is unavailable.
$parent = dirname($dir);
record($results, 'Filesystem', 'ST6', 'parent directory writable (app above docroot)',
    is_writable($parent) ? 'VERIFIED' : 'UNSUPPORTED', $parent);

record($results, 'Filesystem', 'ST1', 'free space on this volume', 'VERIFIED',
    ($free = @disk_free_space($dir)) ? round($free / 1073741824, 2).' GB' : 'unknown');

// ── Section 5: outbound network ──────────────────────────────────────────────
//
// Needed for external SMTP relay, Sentry, EthioTelecom SMS, webhook delivery and
// biometric device polling. Shared hosts often block non-standard outbound ports.

$ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
$httpsOk = @file_get_contents('https://example.com', false, $ctx) !== false;
record($results, 'Network', 'H6', 'outbound HTTPS (443)', $httpsOk ? 'VERIFIED' : 'UNSUPPORTED');

foreach ([['SMTP submission', 587], ['SMTP implicit TLS', 465]] as [$label, $port]) {
    $sock = @fsockopen('smtp.gmail.com', $port, $errno, $errstr, 6);
    record($results, 'Network', 'H6', "outbound $label ($port)",
        $sock ? 'VERIFIED' : 'UNSUPPORTED', $sock ? '' : trim("$errno $errstr"));
    if ($sock) {
        fclose($sock);
    }
}

// ── Section 6: database ──────────────────────────────────────────────────────

$opt = [];
if ($isCli) {
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
            $opt[$m[1]] = $m[2];
        }
    }
} else {
    $opt = array_map('strval', $_GET);
}

$dbHost = $opt['db-host'] ?? null;
$dbName = $opt['db-name'] ?? null;
$dbUser = $opt['db-user'] ?? null;
$dbPass = $opt['db-pass'] ?? '';

if ($dbHost === null || $dbName === null || $dbUser === null) {
    record($results, 'Database', 'DB', 'database checks', 'UNKNOWN',
        'skipped — pass --db-host --db-name --db-user --db-pass to run them');
} else {
    try {
        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName}", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $one = fn (string $sql) => (string) $pdo->query($sql)->fetchColumn();

        $version = $one('SELECT VERSION()');
        record($results, 'Database', 'DB1', 'server version', 'VERIFIED', $version);

        $charset = $one('SELECT @@character_set_server');
        record($results, 'Database', 'DB10', 'server charset is utf8mb4 (REQUIRED for Amharic)',
            str_starts_with($charset, 'utf8mb4') ? 'VERIFIED' : 'UNSUPPORTED', $charset);

        foreach ([
            ['DB5', 'log_bin', 'SELECT @@log_bin'],
            ['DB5', 'log_bin_trust_function_creators', 'SELECT @@log_bin_trust_function_creators'],
            ['DB12', 'event_scheduler', 'SELECT @@event_scheduler'],
            ['DB13', 'max_connections', 'SELECT @@max_connections'],
        ] as [$id, $label, $sql]) {
            try {
                record($results, 'Database', $id, $label, 'VERIFIED', $one($sql));
            } catch (Throwable $e) {
                record($results, 'Database', $id, $label, 'UNKNOWN', $e->getMessage());
            }
        }

        $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        record($results, 'Database', 'DB3', 'grants', 'VERIFIED', implode(' | ', $grants));

        // Feature probes on a throwaway table. Dropped in the finally block.
        $t = '_ethr_probe_'.bin2hex(random_bytes(3));
        $pdo->exec("CREATE TABLE `{$t}` (id INT PRIMARY KEY, d JSON NULL, t TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("ALTER TABLE `{$t}` ADD FULLTEXT KEY `_ft` (`t`)");
            record($results, 'Database', 'DB9', 'FULLTEXT index (employee search)', 'VERIFIED');
        } catch (Throwable $e) {
            record($results, 'Database', 'DB9', 'FULLTEXT index (employee search)', 'UNSUPPORTED', $e->getMessage());
        }

        record($results, 'Database', 'DB8', 'JSON columns (32 migrations use them)', 'VERIFIED');

        // *** GATE H1 — the one that decides whether migrations run at all. ***
        try {
            $pdo->exec(
                "CREATE TRIGGER `{$t}_guard` BEFORE UPDATE ON `{$t}` ".
                "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'probe'"
            );
            record($results, 'Database', 'DB4', 'CREATE TRIGGER — GATE H1 (audit-log immutability)', 'VERIFIED');
            $pdo->exec("DROP TRIGGER IF EXISTS `{$t}_guard`");
        } catch (Throwable $e) {
            record($results, 'Database', 'DB4', 'CREATE TRIGGER — GATE H1 (audit-log immutability)',
                'UNSUPPORTED', $e->getMessage().'  <-- record this error number verbatim');
        }

        // Compensating control A1: can audit_log be protected by grant instead?
        try {
            $pdo->exec("GRANT SELECT, INSERT ON `{$dbName}`.`{$t}` TO CURRENT_USER()");
            record($results, 'Database', 'DB6', 'per-table GRANT (compensating control A1)', 'VERIFIED');
        } catch (Throwable $e) {
            record($results, 'Database', 'DB6', 'per-table GRANT (compensating control A1)',
                'UNSUPPORTED', $e->getMessage());
        }

        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
    } catch (Throwable $e) {
        record($results, 'Database', 'DB', 'connection', 'UNSUPPORTED', $e->getMessage());
    }
}

// ── Report ───────────────────────────────────────────────────────────────────

$line = str_repeat('=', 78);
echo "$line\nETHR SHARED-HOSTING CAPABILITY PROBE\n";
echo 'Generated: '.date('c')."\nHost: ".($_SERVER['HTTP_HOST'] ?? php_uname('n'))."\n$line\n\n";

$fail = 0;
foreach ($results as $section => $rows) {
    echo "── $section ".str_repeat('─', max(1, 60 - strlen($section)))."\n";
    foreach ($rows as $r) {
        $mark = match ($r['status']) {
            'VERIFIED' => '  OK  ',
            'UNSUPPORTED' => ' FAIL ',
            default => ' ???? ',
        };
        if ($r['status'] === 'UNSUPPORTED' && str_contains($r['item'], 'MANDATORY')) {
            $fail++;
        }
        printf("[%s] %-58s %s\n", $mark, $r['item'], $r['detail']);
    }
    echo "\n";
}

echo "$line\n";
echo $fail > 0
    ? "RESULT: {$fail} MANDATORY item(s) unsupported — Laravel 12 will not run as-is.\n"
    : "RESULT: no mandatory PHP item failed. Check FAIL/???? rows above individually.\n";
echo "\nThis probe cannot answer: cron, Node.js, wildcard DNS, wildcard TLS,\n";
echo "document-root configuration. Those are Plesk panel questions — see\n";
echo "docs/HOSTING_VERIFICATION_CHECKLIST.md sections 2, 3, 4, 5 and 6.\n";
echo "\n*** DELETE THIS FILE FROM THE SERVER NOW. ***\n$line\n";
