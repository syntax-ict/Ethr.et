<?php

declare(strict_types=1);

/**
 * Validate a host-evidence file, and refuse the specific mistakes on record.
 *
 *   php scripts/hosting-verification/validate-host-evidence.php
 *   php scripts/hosting-verification/validate-host-evidence.php path/to/evidence.json
 *
 * WHY THIS EXISTS. Every mandatory cutover gate that is still open is a HOST
 * gate, and the host session happens once, by a person, on an account with no
 * shell. The findings then have to reach `docs/deployment/GATE-0-RESULT.md` and
 * `docs/deployment/CUTOVER-CHECKLIST.md`. Retyping is where a result changes
 * meaning, and this repository has already recorded each of these happening:
 *
 *   - **A 404 read as a pass.** `GET /secret.env.probe` returned 404 on
 *     2026-09-25 because the bait was absent. `RUN-SHEET.md` §4a had to say, in
 *     capitals, *"A 404 is NOT a pass."*
 *   - **A gate silently omitted.** `G0-B.6` (`AllowOverride Options`) became a
 *     requirement on 2026-09-26 and had no row anywhere until 2026-09-27.
 *   - **A capability inferred from CI.** CI is green on MariaDB in a container
 *     and says nothing about Ethio Telecom's MySQL.
 *   - **A register drifting from its readings.** Five G0-B rows read
 *     `NOT VERIFIED` for two days after being measured VERIFIED.
 *
 * So the evidence is a FILE with a shape, and this refuses a file that records
 * any of those. It is not a substitute for the measurement; it is what stops a
 * measurement being recorded as something it was not.
 *
 * WITH NO FILE YET it is still useful: it prints exactly which gates are
 * outstanding and what closes each. That is its normal mode today.
 *
 * Exit codes: 0 valid (or nothing to validate) · 1 the file is invalid · 2 usage.
 */

/** The mandatory gates. Canonical here; a Pest test asserts the checklist agrees. */
const MANDATORY = [
    'C-5' => 'Production frontend mode',
    'Q6' => 'External cron caller chosen',
    'Q6-x' => 'Cron caller actually running',
    'M1' => '.htaccess [F,L] deny + CSP survival',
    'M2' => 'Hosting probe run on the account',
    'G0-F' => 'CREATE TRIGGER',
    'G0-B.6' => 'AllowOverride Options',
    'M6' => 'Restore rehearsal on the host',
    'M3' => 'Wildcard vhost + certificate + routing',
    'G0-H' => 'SMTP 587/465 + mailbox send cap',
    'G0-I' => 'MySQL version, DB_CONNECTION, charset',
    'G0-J' => 'CPU and database performance',
    'Quotas' => '5 GB disk / 50 GB bandwidth',
    'Secrets' => 'Secret / artifact audit',
];

/** The only permitted states. No synonyms, no "probably", no blanks. */
const STATES = ['PASS', 'FAIL', 'HOST ACTION REQUIRED', 'OWNER DECISION', 'BLOCKED'];

/**
 * Gates whose PASS needs more than one reading, and which readings.
 *
 * Each exists because one of the parts can pass while the gate as a whole does
 * not — the trap in every case is stopping at the first green.
 */
const COMPOSITE = [
    // A grant is the input; a refused UPDATE is the outcome. G0-F-CREATE-TRIGGER.md §5.
    'G0-F' => ['db4', 'show_triggers', 'update_refused'],
    // DNS passing and a certificate existing are both already true and neither
    // is the gate: what is absent is the vhost binding.
    'M3' => ['dns', 'vhost', 'served_certificate', 'tenant_routing'],
    // Two separate baits, added specifically to close a known false pass.
    'M1' => ['fl_deny', 'csp_survival'],
];

/** Gates that cannot be satisfied by a repository or CI measurement. */
const HOST_ONLY = ['Q6-x', 'M1', 'M2', 'G0-F', 'G0-B.6', 'M6', 'M3', 'G0-H', 'G0-I', 'G0-J', 'Quotas'];

const DEFAULT_PATH = 'docs/deployment/host-evidence/HOST-EVIDENCE.json';

function root(): string
{
    return dirname(__DIR__, 2);
}

function out(string $s): void
{
    fwrite(STDOUT, $s."\n");
}

function err(string $s): void
{
    fwrite(STDERR, $s."\n");
}

// ── outstanding-gate report, which is the mode in use today ──────────────────

function reportOutstanding(): void
{
    out('validate-host-evidence: no evidence file yet.');
    out('');
    out('  Expected at: '.DEFAULT_PATH);
    out('  Template:    docs/deployment/host-evidence/HOST-EVIDENCE.template.json');
    out('  Procedure:   docs/deployment/host-evidence/README.md');
    out('');
    out('Mandatory gates and what closes each:');
    out('');

    foreach (MANDATORY as $id => $what) {
        $host = in_array($id, HOST_ONLY, true) ? 'HOST' : 'repo';
        $composite = isset(COMPOSITE[$id])
            ? '  (needs all of: '.implode(', ', COMPOSITE[$id]).')'
            : '';
        out(sprintf('  [%-4s] %-8s %s%s', $host, $id, $what, $composite));
    }

    out('');
    out('  HOST gates cannot be closed from this machine, and must not be closed from CI.');
}

// ── validation ──────────────────────────────────────────────────────────────

/** @return list<string> problems */
function validate(array $doc): array
{
    $problems = [];

    if (! isset($doc['gates']) || ! is_array($doc['gates'])) {
        return ['the file has no `gates` object'];
    }

    $gates = $doc['gates'];

    // 1. Nothing may be silently absent. This is G0-B.6's failure mode: a real
    //    requirement with no row anywhere, so nothing reported it missing.
    foreach (MANDATORY as $id => $what) {
        if (! array_key_exists($id, $gates)) {
            $problems[] = "gate {$id} ({$what}) is missing. Every mandatory gate needs an entry, "
                .'including the ones that are not done — record them as HOST ACTION REQUIRED.';
        }
    }

    foreach ($gates as $id => $g) {
        if (! isset(MANDATORY[$id])) {
            $problems[] = "gate {$id} is not a mandatory gate. Remove it, or add it to MANDATORY "
                .'in this script and to CUTOVER-CHECKLIST.md deliberately.';

            continue;
        }

        if (! is_array($g)) {
            $problems[] = "gate {$id} is not an object";

            continue;
        }

        $state = $g['state'] ?? null;

        // 2. One vocabulary. "Mostly", "probably fine", "should work" are how a
        //    non-answer becomes a pass in someone's summary.
        if (! is_string($state) || ! in_array($state, STATES, true)) {
            $problems[] = "gate {$id}: state ".json_encode($state).' is not one of '
                .implode(' / ', STATES);

            continue;
        }

        if ($state !== 'PASS') {
            continue;   // only a PASS has to prove itself
        }

        // 3. A PASS needs a date, a method and a verbatim result.
        foreach (['measured_at', 'method', 'result'] as $field) {
            if (! isset($g[$field]) || trim((string) $g[$field]) === '') {
                $problems[] = "gate {$id} is PASS but `{$field}` is empty. A PASS without "
                    .'a date, a method and the verbatim result is a claim, not evidence.';
            }
        }

        $method = strtolower((string) ($g['method'] ?? ''));
        $result = (string) ($g['result'] ?? '');

        // 4. Do not infer host capability from CI.
        if (in_array($id, HOST_ONLY, true)) {
            foreach (['ci', 'github actions', 'pipeline', 'inferred', 'assumed', 'prior', 'documentation'] as $bad) {
                if (str_contains($method, $bad)) {
                    $problems[] = "gate {$id} is PASS with method \"{$g['method']}\". This gate is "
                        .'answerable only on the Ethio Telecom host. CI runs MariaDB in a container '
                        .'and says nothing about it.';
                    break;
                }
            }
        }

        // 5. A 404 is not a pass. The exact recorded mistake.
        if (preg_match('/\b404\b/', $result) === 1) {
            $problems[] = "gate {$id} is PASS but its result mentions 404: \"{$result}\". "
                .'A 404 means the probe or bait was absent — RUN-SHEET.md §4a: "A 404 is NOT a '
                .'pass." Record it as HOST ACTION REQUIRED.';
        }

        // 6. Nor is a placeholder.
        if (preg_match('/^(tbd|todo|n\/?a|ok|yes|done|pass|fine|\?+|-+)$/i', trim($result)) === 1) {
            $problems[] = "gate {$id} is PASS with result \"{$result}\", which records nothing. "
                .'Paste what the host actually returned.';
        }

        // 7. Composite gates need every part, and each part needs its own result.
        foreach (COMPOSITE[$id] ?? [] as $part) {
            $parts = $g['parts'] ?? [];

            if (! isset($parts[$part]) || trim((string) $parts[$part]) === '') {
                $problems[] = "gate {$id} is PASS but part `{$part}` is missing. "
                    .'All of: '.implode(', ', COMPOSITE[$id]).'. One green part is not the gate.';
            }
        }
    }

    // 8. The file may not claim readiness it has not earned.
    $allPass = true;

    foreach (MANDATORY as $id => $_) {
        if (($gates[$id]['state'] ?? null) !== 'PASS') {
            $allPass = false;
            break;
        }
    }

    if (array_key_exists('cutover_ready', $doc)) {
        $claimed = (bool) $doc['cutover_ready'];

        if ($claimed && ! $allPass) {
            $problems[] = 'the file sets cutover_ready = true while at least one mandatory gate '
                .'is not PASS. That is the one thing this validator exists to refuse.';
        }
    }

    return $problems;
}

// ── main ────────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    err('validate-host-evidence: CLI only');
    exit(2);
}

$path = $argv[1] ?? (root().'/'.DEFAULT_PATH);

if (! is_file($path)) {
    reportOutstanding();
    exit(0);
}

$raw = (string) file_get_contents($path);
$doc = json_decode($raw, true);

if (! is_array($doc)) {
    err('validate-host-evidence: '.$path.' is not valid JSON — '.json_last_error_msg());
    exit(1);
}

$problems = validate($doc);

if ($problems !== []) {
    err('validate-host-evidence: REFUSED — '.count($problems).' problem(s) in '.$path);
    err('');

    foreach ($problems as $p) {
        err('  - '.$p);
    }

    err('');
    err('  Nothing here is a judgement about the host. It is a judgement about how the');
    err('  result was recorded. Fix the record, or record the honest state.');
    exit(1);
}

$passed = 0;

foreach (MANDATORY as $id => $_) {
    if (($doc['gates'][$id]['state'] ?? null) === 'PASS') {
        $passed++;
    }
}

$total = count(MANDATORY);
out("validate-host-evidence: {$path} is well-formed.");
out("validate-host-evidence: {$passed} of {$total} mandatory gates PASS.");

if ($passed < $total) {
    out('');
    out('Not yet ready. Outstanding:');

    foreach (MANDATORY as $id => $what) {
        $state = $doc['gates'][$id]['state'] ?? 'MISSING';

        if ($state !== 'PASS') {
            out(sprintf('  %-8s %-22s %s', $id, $state, $what));
        }
    }

    out('');
    out('CUTOVER READY = NO');
    exit(0);
}

out('');
out('Every mandatory gate records PASS with evidence.');
out('CUTOVER READY = YES — subject to a human reading the evidence, not just its shape.');
exit(0);
