<?php

declare(strict_types=1);

/**
 * The external cron caller must keep the properties that make it honest.
 *
 * WHY THIS IS A TEST. `G0-D` is FAILED — the Bronze subscription has no Scheduled
 * Tasks section — so the entire asynchronous half of ETHR depends on something
 * outside the host calling two URLs. Q6 chose GitHub Actions
 * (`.github/workflows/cron.yml`, owner decision 2026-09-27). That workflow is now
 * load-bearing production infrastructure, and it has three failure modes that are
 * **silent**:
 *
 *   1. Calling only `/cron/schedule`. Eleven of the fourteen `Schedule::` entries
 *      do nothing but insert rows into `jobs`, and all 16 `app/Jobs` classes are
 *      ShouldQueue. A caller wired to the scheduler alone fills the table and
 *      drains nothing — while every tick reports success.
 *   2. Checking only the HTTP status. `CronRunController::runExclusively()`
 *      returns **HTTP 200 with `status: "failed"`** when the artisan exit code is
 *      non-zero. A workflow that trusts the status code reports green while
 *      `schedule:run` fails every five minutes.
 *   3. Leaking the token. `VerifyCronToken` accepts `?token=` because Plesk's
 *      fetch-a-URL task cannot set headers; a query string reaches access logs
 *      and Referer headers. This caller can set a header, so it must.
 *
 * None of the three produces a red build on its own. This test is what does.
 *
 * It asserts the workflow's TEXT, not its behaviour — it does not run Actions and
 * does not call the host. The decision table (which HTTP code means what) was
 * verified separately by stubbing `curl`; `docs/deployment/shared-hosting/cron-caller.md`
 * carries the reproduction command.
 *
 * No database, no network.
 */
function ethrCronWorkflow(): string
{
    $path = dirname(base_path()).'/.github/workflows/cron.yml';

    expect(is_file($path))->toBeTrue(
        "The external cron caller is missing: {$path}\n"
        .'G0-D is FAILED, so without it nothing drives schedule:run or queue:work and the '
        .'asynchronous half of the product is inert — silently. If the caller moved somewhere '
        .'else, update this test to point at it; do not delete the test.'
    );

    return (string) file_get_contents($path);
}

/**
 * The workflow with every comment line removed — what Actions and the shell act on.
 *
 * Every "must NOT appear" assertion below runs against this rather than the raw
 * file, and the reason is immediate: the workflow *explains* why it does not put
 * the token in a query string, so the raw text necessarily contains `?token=`.
 * Asserting over prose condemns a file for documenting itself, which is the same
 * trap `SharedHostingHtaccessTest` records for `.htaccess` at a different scale.
 */
function ethrCronWorkflowLive(): string
{
    return (string) preg_replace('/^\s*#.*$/m', '', ethrCronWorkflow());
}

it('calls both cron endpoints, not just the scheduler', function () {
    $workflow = ethrCronWorkflow();

    // Both paths must appear as things the workflow actually calls.
    expect($workflow)->toContain('/api/v1/cron/');
    expect($workflow)->toContain('call schedule');
    expect($workflow)->toContain('call queue');

    // And the queue must not be reachable only through a non-default branch: the
    // unattended path (the schedule trigger) has to drive both.
    expect($workflow)->toMatch(
        '/both\)\s*call schedule \|\| rc=1; call queue \|\| rc=1/',
        'the `both` branch must call schedule AND queue. Eleven of fourteen scheduled '
        .'entries only enqueue, so a scheduler-only caller looks healthy and drains nothing.'
    );
});

it('treats HTTP 200 with a failed status as a failure', function () {
    $live = ethrCronWorkflowLive();

    // THE ASSERTION THIS FILE EXISTS FOR. runExclusively() returns 200 with
    // status "failed" on a non-zero artisan exit code.
    //
    // str_contains rather than ->toContain($needle, $message): Pest's string
    // toContain takes VARARGS of needles, so a second argument intended as a
    // failure message silently becomes a second thing being asserted.
    expect(str_contains($live, '"status":"failed"'))->toBeTrue(
        'the workflow does not inspect the response body for a failed status. HTTP 200 is '
        .'not success here: CronRunController returns 200 with status=failed when artisan '
        .'exits non-zero, so a status-code-only check reports green through a broken scheduler.'
    );

    // And it must not accept an unrecognised 200 body as success either.
    expect(str_contains($live, '"status":"ok"'))->toBeTrue(
        'the workflow does not confirm a positive status, so an unrecognised 200 body would pass.'
    );
});

it('treats 409 as benign rather than an error', function () {
    $live = ethrCronWorkflowLive();

    // The application holds a Cache::lock and returns 409 when a previous run is
    // still draining. That is the overlap guard working, not a fault — and a
    // caller that errors on it produces a failed run every tick during any slow
    // drain, which trains people to ignore the notifications.
    //
    // `.*?` with the /s modifier, NOT `(.|\n)*?`: the alternation form backtracks
    // catastrophically across a file this size and preg_match returns FALSE on
    // hitting pcre.backtrack_limit — which reads as "assertion failed" on a file
    // that is completely correct. Measured here 2026-09-27.
    expect($live)->toMatch(
        '/^\s*409\)\s*$.*?return 0/ms',
        'the workflow must exit 0 on 409. The application prevents the duplicate execution '
        .'itself; flagging that as an error makes every slow drain look like an outage.'
    );
});

it('sends the token as a header and never in the URL', function () {
    $live = ethrCronWorkflowLive();

    expect(str_contains($live, 'X-Cron-Token: ${ETHR_CRON_TOKEN}'))->toBeTrue(
        'the workflow does not send the token as an X-Cron-Token header.'
    );

    // `?token=` is supported by the application for Plesk's headerless fetch-a-URL
    // task. This caller can set headers, so using the query string would put the
    // secret into access logs for no benefit.
    //
    // Checked against the comment-stripped text: the workflow explains this very
    // decision, so the raw file necessarily contains the string it forbids.
    expect($live)->not->toMatch(
        '/\?token=/',
        'the token must not travel in the query string from this caller — it reaches access '
        .'logs and Referer headers. VerifyCronToken accepts it only for headerless callers.'
    );

    // `set -x` would echo the curl invocation, token included.
    expect($live)->not->toMatch(
        '/^\s*set -x\b/m',
        'set -x in this job would print the curl command line, and with it the token.'
    );

    // No literal secret anywhere, comments included — a hex blob in a comment is
    // just as leaked. A 32-byte token is 64 hex characters.
    expect(ethrCronWorkflow())->not->toMatch(
        '/\b[0-9a-f]{40,}\b/',
        'a long hex literal appears in the workflow. The token belongs in a GitHub secret.'
    );
});

it('refuses to run silently when it is not configured', function () {
    $workflow = ethrCronWorkflow();

    // An unset secret must fail loudly. The alternative — calling nothing and
    // exiting 0 — is exactly the silent-inertness this whole design exists to
    // prevent, and it would be indistinguishable from a working scheduler.
    expect($workflow)->toContain('ETHR_CRON_TOKEN');
    expect($workflow)->toContain('ETHR_CRON_BASE_URL');
    expect($workflow)->toMatch('/if \[ -z "\$\{ETHR_CRON_TOKEN:-\}" \]/');

    // And the 32-character floor, checked locally so the error names the cause
    // rather than surfacing as an indistinguishable 404.
    expect($workflow)->toContain('-lt 32');
});

it('serialises its runs without cancelling one mid-drain', function () {
    $workflow = ethrCronWorkflow();

    expect($workflow)->toContain('group: ethr-scheduler');

    // cancel-in-progress MUST be false here, unlike gates.yml. Cancelling a
    // running queue:work severs the HTTP request mid-drain; jobs already reserved
    // then wait for retry_after (1200s) instead of being released.
    expect($workflow)->toMatch(
        '/group: ethr-scheduler\s*\n\s*cancel-in-progress: false/',
        'cancel-in-progress must be false for the scheduler. Cancelling a live queue:work '
        .'leaves reserved jobs stranded until retry_after.'
    );
});

it('agrees with the application about the token variable name', function () {
    $workflow = ethrCronWorkflow();

    // The mapping is GitHub secret ETHR_CRON_TOKEN -> host env CRON_TOKEN. The
    // application's name is CRON_TOKEN (config/cron.php) and is not renamed to
    // suit the caller; the workflow documents the mapping.
    expect(config('cron.min_token_length'))->toBe(32);
    expect($workflow)->toContain('CRON_TOKEN');

    // SCHEDULER_HTTP_TOKEN is a dead placeholder for an endpoint that shipped
    // reading CRON_TOKEN. It must not be reintroduced as a live variable — only
    // named in the comment that explains why it is dead.
    $live = preg_replace('/^\s*#.*$/m', '', $workflow) ?? '';

    expect($live)->not->toContain(
        'SCHEDULER_HTTP_TOKEN',
        'SCHEDULER_HTTP_TOKEN is not read by anything in the application. Use CRON_TOKEN.'
    );
});
