# The external cron caller — from "Q6 answered" to background work running

**Status: the endpoints exist and nothing has ever called them on the target account.**
This file is the step between choosing a caller and having a working background half. It
was missing: [`../SHARED_HOSTING_PLAN.md`](../SHARED_HOSTING_PLAN.md) §5.3a lays out the
*trade* between callers, [`cron.txt`](cron.txt) gives the *endpoint mechanics*, and §5.5
step 11 says *"point an external caller at both cron endpoints"* — but nothing joined them
into a procedure.

> # ✅ Q6 DECIDED, 2026-09-27 — **GitHub Actions scheduled workflows.**
>
> The caller is **[`.github/workflows/cron.yml`](../../../.github/workflows/cron.yml)**,
> committed in this repository. It is not a new cron architecture: it calls the two
> endpoints that already existed, with the token mechanism that already existed, and
> changes nothing in the application.
>
> Plesk Scheduled Tasks were **not** chosen because the subscription does not offer
> them — **G0-D is FAILED**, no Scheduled Tasks / Task Scheduler / Cron Jobs section
> exists (owner-read 2026-09-18), and Dev Tools has no Terminal.
>
> **The two values an operator must supply** — nothing else, and neither belongs in
> this repository:
>
> | GitHub | Holds |
> |---|---|
> | `secrets.ETHR_CRON_TOKEN` (environment `production`) | the same value as the host's `CRON_TOKEN` |
> | `secrets.ETHR_CRON_BASE_URL` (same environment) | `https://<APP_DOMAIN>` — no trailing slash, no path |
>
> ### Secret-name mapping, stated once
>
> | Layer | Name |
> |---|---|
> | GitHub secret | `ETHR_CRON_TOKEN` |
> | Host `.env` | **`CRON_TOKEN`** ← the application's established name, unchanged |
> | HTTP header | `X-Cron-Token` |
>
> All three hold one value. **`CRON_TOKEN` is not renamed to suit the caller.**
>
> **`SCHEDULER_HTTP_TOKEN` is a dead name and must not be reintroduced.** It was a
> placeholder in `ENVIRONMENT.md` and `DEPLOYMENT.md` §6 for an endpoint that had not
> been built; the endpoint shipped on 2026-09-22 reading `CRON_TOKEN`, and nothing in
> the application has ever read `SCHEDULER_HTTP_TOKEN`. Setting it does nothing.
> `CronCallerWorkflowTest` fails if it appears as a live variable in the workflow.
>
> ### What the workflow does, and the one line that matters most
>
> - calls **both** `/cron/schedule` and `/cron/queue`, in that order — the scheduler
>   enqueues, the worker drains, and a caller wired to only the first fills the `jobs`
>   table while reporting success
> - **treats HTTP 200 with `"status":"failed"` as a failure.** `runExclusively()`
>   returns 200 when artisan exits non-zero, so a status-code-only check would report
>   green through a scheduler that fails every five minutes. This is the single most
>   important behaviour in the file
> - treats **409 as benign** — that is the application's own `Cache::lock` refusing a
>   duplicate execution, which is what it is for
> - sends the token as a **header**, never in the URL, and never runs `set -x`
> - `concurrency: ethr-scheduler` with **`cancel-in-progress: false`** — the opposite of
>   `gates.yml`, because cancelling a live `queue:work` strands reserved jobs until
>   `retry_after` (1200s)
> - refuses to run if it is **partially** configured, or if the token is under 32
>   characters — rather than calling nothing and exiting 0. *(Corrected 2026-09-28: this
>   read "if either secret is unset". With **neither** set it now reports DORMANT and
>   exits 0 on a scheduled run — see the three-state table below for why that is not a
>   softened guard.)*
>
> ### ⚠ The `schedule:` trigger does not fire from a non-default branch
>
> **Measured 2026-09-27.** `cron.yml` currently exists only on `migration/bronze-plesk`.
> GitHub indexes scheduled workflows from the **default branch**, so:
>
> ```
> $ gh workflow list --all
> Quality gates · Security audit · Verify tests fail without fix · Dependabot Updates
> ```
>
> *ETHR scheduler* is **absent** — and `gh run list --workflow=cron.yml` returns
> `HTTP 404: workflow cron.yml not found on the default branch`.
>
> **What this means in practice, and it is a trap:**
>
> | | |
> |---|---|
> | `workflow_dispatch` (manual) | **Works now**, from this branch |
> | `schedule:` (every 5 min) | **Does not run at all** until the workflow is on `main` |
>
> So an operator can add the two secrets, dispatch the workflow by hand, see a green
> run and two `"status":"ok"` responses — and conclude the scheduler is live when
> nothing will fire on its own. **A green manual dispatch is evidence that the wiring
> works, not that the schedule runs.**
>
> This is recorded rather than worked around. Merging to `main` is gated by the cutover
> decision, so the honest position is that the scheduler is **manual-only** until then,
> and `QueueHealth::beat()` / `ethr:queue:check` are what would notice if anyone forgot.
>
> **And merging was not merely gated — until 2026-09-28 it would have been harmful.**
> The guard here exited 1 whenever a secret was missing, so landing on the default
> branch would have produced a failed run every 5 minutes — ~288 a day, each notifying
> the owner — before ETHR was deployed or the secrets existed. A permanently red signal
> stops being read, so the scheduler's real failures would have been invisible by the
> time they mattered.
>
> It now distinguishes three states, and the middle one is the reason the first cannot
> simply be a silent pass:
>
> | Secrets | On `schedule` | On a manual run |
> |---|---|---|
> | neither set | **DORMANT** — warns, writes a job summary, exits 0 | **fails**; you clicked Run |
> | exactly one | **fails** — the typo case, or a secret on the repo instead of the environment | fails |
> | both set | runs, with every check applying | runs |
>
> Verified 2026-09-28 by substituting `github.event_name` and stubbing `curl`: all five
> combinations behave as tabulated. `CronCallerWorkflowTest` pins them.
>
> ### Cadence, stated as the limitation it is
>
> **Every 5 minutes**, which is GitHub's floor for `schedule:` — finer intervals are
> silently rounded up. Two caveats that are properties of the platform and not of ETHR:
>
> - **Delivery is best-effort.** Scheduled runs can be delayed or dropped under load.
>   Laravel's scheduler is idempotent about *"was this due since last checked"*, so a
>   missed tick delays work rather than losing it — but anything needing minute
>   accuracy does not get it here.
> - **GitHub disables scheduled workflows after 60 days of repository inactivity.** On a
>   repository that goes quiet, the scheduler stops **silently**. `QueueHealth::beat()`
>   and `ethr:queue:check` are what notice; §5 below is how to watch them.
>
> ### How the decision table was verified
>
> The response handling was exercised by extracting the `run` block and stubbing `curl`
> — 10 cases, all passing: 200/ok, 200/failed, 409, 404, 429, curl-failure, an
> unexpected 5xx, an unrecognised 200 body, an empty token and a short token. Reproduce
> it by parsing `.github/workflows/cron.yml`, writing `.jobs.tick.steps[0].run` to a
> file, and running it with a shell function named `curl` that echoes a canned status
> code. **Nothing was called on the host.**
>
> `CronCallerWorkflowTest` pins the properties above and is mutation-checked against
> the two defects that matter: dropping the failed-status check, and calling only the
> scheduler.

~~**Which caller is not decided here.** That is question **Q6**, and it is the owner's: §5.3a
sets out four candidates with their trade-offs, and its recommendation is explicitly *"not
the obvious one"*.~~ **Decided — see the banner above.** §5.3a's comparison is kept as the
reasoning that produced the choice. Everything below is caller-independent except §3.

---

## Why this exists at all

**G0-D is FAIL.** There is no *Scheduled Tasks* section on the subscription dashboard
(owner-read 2026-09-18, [`../GATE-0-RESULT.md`](../GATE-0-RESULT.md)), and SSH is
**Forbidden**. So nothing on the host starts `schedule:run` or `queue:work`. Two endpoints
exist to be driven from outside instead:

```
POST https://ethr.et/api/v1/cron/schedule
POST https://ethr.et/api/v1/cron/queue
```

**Both, not one.** Eleven of the fourteen entries in `routes/console.php` do nothing but
insert rows into the `jobs` table, and all 16 classes in `app/Jobs` implement `ShouldQueue`.
A caller wired only to `/cron/schedule` leaves every queued job unrun while looking
perfectly healthy — see §5 and `cron.txt`, which makes the same point about the two cron
lines it would have needed.

---

## 1 · Generate the token

`CRON_TOKEN` must be **at least 32 characters** or the routes disable themselves —
`VerifyCronToken` logs `CRON_TOKEN is shorter than the configured minimum` and 404s
(**MEASURED**, `config/cron.php:31`, `VerifyCronToken:49`). A short token is worse than
none, because it looks configured.

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

**Run that somewhere else.** `cron.txt` gives the same line, and on this account there is
no command line to run it on — that is the whole reason this file exists. Any machine will
do; the value is what matters, not where it was generated.

## 2 · Put it on the host

Plesk environment variable, `CRON_TOKEN`. Not a file in the document root, and not in the
repository — `.env` files and `APP_KEY` literals are refused by the pre-push hook, and the
same judgement applies here.

**Until it is set, the routes 404 and the caller cannot tell that from a broken deploy.**
See §4.

## 3 · Configure the caller — **Q6 answered: GitHub Actions**

**Decided 2026-09-27.** The workflow is committed; the operator's whole job is to create
the GitHub `production` environment and add the two secrets named in the banner above.
Then run the workflow once by hand — *Actions → ETHR scheduler → Run workflow* — rather
than waiting for a tick, and check §4.

The comparison below is kept as the reasoning, with the chosen row marked. **Do not
configure two callers**: both would be refused by the application's lock, so the second
produces nothing but 409s and noise.

| Caller | Where the token goes | What to set |
|---|---|---|
| ✅ **GitHub Actions — CHOSEN** | environment **secret** (`production`) | Already written: `.github/workflows/cron.yml`. **5-minute floor**, best-effort delivery, and it is **disabled after 60 days of repository inactivity** — the platform limitations are in the banner above, and they are properties of GitHub rather than of ETHR |
| **The VPS already in hand** | a file readable only by the cron user | two `crontab` lines. **Choosing this re-opens Option A** — §5.3a's recommendation explains why that is a strategic decision, not an operational one |
| **A third-party cron service** | that service's dashboard | two jobs. A stranger then holds a bearer credential — §5.3a states the blast radius |
| **A laptop** | locally | not a production answer; named in `config/cron.php` for completeness |

Present the token as the **`X-Cron-Token` header** wherever the caller can set headers.
Where it cannot, `?token=…` on the query string is accepted — and puts the token in access
logs and `Referer` headers, so treat a query-form token as rotatable from day one.

**POST, not GET.** A GET would be followed by a crawler or a link prefetcher.

## 4 · Verify the first tick — do not assume it worked

Three checks, in order. The first two need nothing but the caller's own log.

1. **`POST /api/v1/cron/schedule` returns 200**, with `status: "ok"` and `exit_code: 0`.
   The body also carries `duration_ms` and up to 2000 characters of Artisan output
   (**MEASURED**, `CronRunController::tail()`).
2. **`POST /api/v1/cron/queue` returns 200** the same way. It runs
   `queue:work --stop-when-empty --max-time=50` over `QueueHealth::QUEUES`.
3. **`GET /api/v1/health` shows the heartbeat moving.** Unauthenticated, behind
   `throttle:health`. Read `queue_detail.scheduler.last_run_at` — it should be within a tick
   of when the caller fired, and `queue_detail.status` should read `healthy`.

**Check 3 is the one that proves the loop closed**, because 1 and 2 only prove the request
was accepted. `ethr:queue:check` would be the natural tool and **there is no CLI on this
account** — the endpoint is the instrument. See
[`../../operations/QUEUE-MONITORING.md`](../../operations/QUEUE-MONITORING.md).

### What each response code means

| | |
|---|---|
| **200** | Ran. Read `status` and `exit_code` — a 200 with `status: "failed"` means the command ran and returned non-zero |
| **409** | The previous run of *that task* is still going, and the lock (`CRON_LOCK_SECONDS`, default 110 s) refused a second. **Normal under load, not a fault** — a caller that treats non-200 as failure will alarm for no reason |
| **404** | `CRON_TOKEN` is unset, shorter than 32 characters, or did not match. **Deliberately indistinguishable from "no such route"**, so that an unconfigured deployment does not advertise these endpoints. So: **a 404 here means check the token first**, not that the deploy is broken |
| **429** | More than 10 requests a minute from one IP (`Limit::perMinute(10)->by(ip)`) |

## 5 · What to watch afterwards

Covered in full by [`../../operations/QUEUE-MONITORING.md`](../../operations/QUEUE-MONITORING.md)
and [`health-check.md`](health-check.md). The three that are specific to having an external
caller:

- **A fresh heartbeat does not mean the queue is draining.** The two endpoints are
  independent calls. Verify both, every time.
- **The caller's silence leaves no trace on this host.** A disabled workflow, an expired
  billing card, a suspended account — none of it shows up here except as the heartbeat going
  stale.
- **The thresholds were set for a one-minute cron** (900 s scheduler staleness, 1800 s
  oldest job — `QueueHealth::snapshot()`). **They are not changed anywhere in this
  repository**, because the right value depends on which caller is chosen. Re-check both
  once Q6 is answered, and expect to widen rather than narrow.

## 6 · Rotation

**Manual on both sides**, and there is a window in between where background work is dead and
nothing says so. Change `CRON_TOKEN` in the Plesk environment and the caller's secret as
close together as you can, then re-run §4's three checks. A query-form token should be
rotated on a schedule rather than on suspicion, since it has been in access logs all along.

---

## What this file does not claim

**None of it has ever been executed on this account.** The endpoints are **MEASURED** in the
code; the procedure above is derived from that code and from the panel facts recorded in
`../GATE-0-RESULT.md`. No request has been made, no token has been set, and no caller has
been configured. It is a procedure, not a record.
