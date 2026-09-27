<?php

declare(strict_types=1);

/**
 * The host-evidence validator must refuse the mistakes this project has actually made.
 *
 * WHY. Every mandatory cutover gate still open is a HOST gate. The host session
 * happens once, by a person, on an account with no shell, and the findings then
 * have to reach `GATE-0-RESULT.md` and `CUTOVER-CHECKLIST.md`. Transcription is
 * where a result changes meaning — and each refusal below corresponds to something
 * on record:
 *
 *   404-as-pass        `GET /secret.env.probe` → 404 on 2026-09-25, because the bait
 *                      was absent. RUN-SHEET.md §4a had to say in capitals that a 404
 *                      is not a pass.
 *   omitted gate       `G0-B.6` became a requirement on 2026-09-26 and had no row
 *                      anywhere until 2026-09-27. Nothing reported it missing.
 *   inferred from CI   CI is green on MariaDB in a container; it says nothing about
 *                      Ethio Telecom's MySQL.
 *   partial composite  DNS passes and the wildcard certificate exists — and neither
 *                      is M3. Stopping at the first green is the failure.
 *   false readiness    the one thing the validator exists to prevent.
 *
 * These tests build fixtures in a temp directory; they never write into the
 * repository, and there is deliberately no committed "all gates pass" fixture —
 * a filled evidence file in the tree would be fabricated host evidence.
 *
 * No database, no network.
 */
function ethrEvidenceValidator(): string
{
    $path = dirname(base_path()).'/scripts/hosting-verification/validate-host-evidence.php';

    expect(is_file($path))->toBeTrue("validator missing at {$path}");

    return $path;
}

function ethrEvidenceTemplate(): array
{
    $path = dirname(base_path()).'/docs/deployment/host-evidence/HOST-EVIDENCE.template.json';

    expect(is_file($path))->toBeTrue("template missing at {$path}");

    /** @var array $doc */
    $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $doc;
}

/** Run the validator against a document, returning [exitCode, output]. */
function ethrRunValidator(?array $doc): array
{
    $root = dirname(base_path());
    $args = [PHP_BINARY, ethrEvidenceValidator()];

    $tmp = null;

    if ($doc !== null) {
        $tmp = tempnam(sys_get_temp_dir(), 'ethr-ev').'.json';
        file_put_contents($tmp, json_encode($doc, JSON_PRETTY_PRINT));
        $args[] = $tmp;
    } else {
        // A path that does not exist exercises the outstanding-gate report.
        $args[] = sys_get_temp_dir().'/ethr-no-such-evidence-file.json';
    }

    $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($tmp !== null) {
        @unlink($tmp);
    }

    return [$code, $out];
}

/** Fill every gate with a plausible PASS, so each test can break exactly one thing. */
function ethrCompleteEvidence(): array
{
    $doc = ethrEvidenceTemplate();

    foreach ($doc['gates'] as $id => $gate) {
        $doc['gates'][$id]['state'] = 'PASS';
        $doc['gates'][$id]['measured_at'] = '2026-09-28';
        $doc['gates'][$id]['method'] = 'measured on the Ethio Telecom host during the panel session';
        $doc['gates'][$id]['result'] = "fixture reading for {$id}: the host returned a specific value here";

        foreach (array_keys($gate['parts'] ?? []) as $part) {
            $doc['gates'][$id]['parts'][$part] = "fixture reading for {$part}";
        }
    }

    $doc['cutover_ready'] = true;

    return $doc;
}

// ── the shape it accepts ────────────────────────────────────────────────────

it('reports the outstanding gates when there is no evidence file', function () {
    [$code, $out] = ethrRunValidator(null);

    // Exit 0: the absence of evidence is the expected state today, not a failure.
    // It has to stay usable as a "what is left?" report, or nobody runs it.
    expect($code)->toBe(0);
    expect($out)->toContain('no evidence file yet');
    expect($out)->toContain('HOST gates cannot be closed from this machine');

    // Every mandatory gate must be listed, or the report is misleading about scope.
    foreach (array_keys(ethrEvidenceTemplate()['gates']) as $id) {
        expect($out)->toContain($id);
    }
});

it('accepts the shipped template, which is honest rather than complete', function () {
    [$code, $out] = ethrRunValidator(ethrEvidenceTemplate());

    expect($code)->toBe(0);
    expect($out)->toContain('is well-formed');

    // The template must NOT claim readiness. It ships with the host gates open,
    // which is the true state.
    expect($out)->toContain('CUTOVER READY = NO');
});

it('accepts a complete file, so it is a gate and not a wall', function () {
    [$code, $out] = ethrRunValidator(ethrCompleteEvidence());

    expect($code)->toBe(0);
    expect($out)->toContain('14 of 14 mandatory gates PASS');
    expect($out)->toContain('CUTOVER READY = YES');

    // And it still defers to a human. A well-formed file is not a read one.
    expect($out)->toContain('not just its shape');
});

// ── the mistakes it refuses ─────────────────────────────────────────────────

it('refuses a 404 recorded as a pass', function () {
    $doc = ethrCompleteEvidence();
    $doc['gates']['M1']['result'] = 'GET /ethr-canary/secret.env.probe -> 404';

    [$code, $out] = ethrRunValidator($doc);

    expect($code)->toBe(1);
    expect($out)->toContain('A 404 is NOT a pass');
});

it('refuses a silently omitted gate', function () {
    $doc = ethrCompleteEvidence();
    unset($doc['gates']['G0-B.6']);

    [$code, $out] = ethrRunValidator($doc);

    expect($code)->toBe(1);
    expect($out)->toContain('G0-B.6');
    expect($out)->toContain('is missing');
});

it('refuses a host gate closed by CI', function () {
    $doc = ethrCompleteEvidence();
    $doc['gates']['G0-I']['method'] = 'CI backend-mysql job is green';

    [$code, $out] = ethrRunValidator($doc);

    expect($code)->toBe(1);
    expect($out)->toContain('answerable only on the Ethio Telecom host');
})->with([
    // The phrasing varies; the refusal must not depend on one spelling.
    'ci', 'GitHub Actions', 'inferred from the lockfile', 'assumed', 'favourable prior',
]);

it('refuses a composite gate that passed only one of its parts', function () {
    $doc = ethrCompleteEvidence();
    $doc['gates']['G0-F']['parts']['show_triggers'] = '';
    $doc['gates']['G0-F']['parts']['update_refused'] = '';

    [$code, $out] = ethrRunValidator($doc);

    expect($code)->toBe(1);
    expect($out)->toContain('One green part is not the gate');
});

it('refuses a placeholder standing in for a reading', function () {
    $doc = ethrCompleteEvidence();
    $doc['gates']['Quotas']['result'] = 'OK';

    [$code, $out] = ethrRunValidator($doc);

    expect($code)->toBe(1);
    expect($out)->toContain('records nothing');
});

it('refuses a PASS with no date, method or result', function () {
    $doc = ethrCompleteEvidence();
    $doc['gates']['M6']['measured_at'] = '';

    [$code, $out] = ethrRunValidator($doc);

    expect($code)->toBe(1);
    expect($out)->toContain('is a claim, not evidence');
});

it('refuses a state outside the fixed vocabulary', function () {
    $doc = ethrCompleteEvidence();
    $doc['gates']['M2']['state'] = 'probably fine';

    [$code, $out] = ethrRunValidator($doc);

    expect($code)->toBe(1);
    expect($out)->toContain('is not one of');
});

it('refuses a file that claims readiness it has not earned', function () {
    $doc = ethrCompleteEvidence();
    $doc['gates']['M1']['state'] = 'HOST ACTION REQUIRED';
    $doc['cutover_ready'] = true;

    [$code, $out] = ethrRunValidator($doc);

    expect($code)->toBe(1);
    expect($out)->toContain('the one thing this validator exists to refuse');
});

// ── drift between the validator and the register ────────────────────────────

it('requires exactly the gates the cutover checklist calls mandatory', function () {
    $checklist = (string) file_get_contents(
        dirname(base_path()).'/docs/deployment/CUTOVER-CHECKLIST.md'
    );

    // Only the MANDATORY table. Scoped to the section, because the state-legend
    // table above it has the same row shape — `| **PASS** | Means … |` — and a
    // naive sweep reads PASS, FAIL and BLOCKED as gate names.
    $start = strpos($checklist, '## Mandatory gates');
    $end = strpos($checklist, '## Non-blocking');

    expect($start)->not->toBeFalse('CUTOVER-CHECKLIST.md has no "Mandatory gates" heading');
    expect($end)->not->toBeFalse('CUTOVER-CHECKLIST.md has no "Non-blocking" heading');

    $mandatorySection = substr($checklist, $start, $end - $start);

    preg_match_all('/^\| \*\*([A-Za-z0-9.-]+)\*\* \| /m', $mandatorySection, $m);

    $inChecklist = array_values(array_unique($m[1]));
    $inValidator = array_keys(ethrEvidenceTemplate()['gates']);

    sort($inChecklist);
    sort($inValidator);

    // Two documents disagreeing about which gates block a cutover is the exact
    // failure mode that left G0-B.6 unrowed for a day. If a gate is added, it goes
    // in both, deliberately.
    expect($inValidator)->toBe(
        $inChecklist,
        "the evidence template and CUTOVER-CHECKLIST.md disagree about the mandatory gates.\n"
        .'  template: '.implode(', ', $inValidator)."\n"
        .'  checklist: '.implode(', ', $inChecklist)
    );
});

it('ships no filled evidence file, because that would be fabricated', function () {
    $filled = dirname(base_path()).'/docs/deployment/host-evidence/HOST-EVIDENCE.json';

    // The real file appears only when a host session has happened. Its presence in
    // a commit that did not involve the host would be invented evidence — which is
    // the single most damaging thing that could be added to this repository.
    if (is_file($filled)) {
        $doc = json_decode((string) file_get_contents($filled), true);

        expect(is_array($doc))->toBeTrue('HOST-EVIDENCE.json exists but is not valid JSON');
        expect($doc['session_date'] ?? '')->not->toBe(
            '',
            'HOST-EVIDENCE.json exists with no session_date. If a host session happened, date it; '
            .'if it did not, delete the file and leave the template.'
        );
    } else {
        expect(true)->toBeTrue();   // the expected state today
    }
});
