# Queue Monitoring

**Status: built and tested. The alert transport still depends on Gate 0** (`../deployment/GATE-0-RESULT.md`, G0-D and G0-H).

```bash
php artisan ethr:queue:check                # exit 1 if the scheduler stopped or a queue is starving
php artisan ethr:queue:check --json         # for an uptime monitor
GET /api/v1/health                          # same picture, under `queue_detail`
```

A heartbeat runs every minute from `routes/console.php`; `QueueHealth::snapshot()` compares it against a staleness threshold and checks all four queues for starving jobs. Eight tests in `api/tests/Feature/QueueHealthTest.php` drive it through every state it exists to detect, including the CLI exit code — a monitor nobody has watched fail is an assumption with a dashboard.

What is **not** built is the part that needs the host: which transport carries the alert. That is G0-H (is outbound SMTP open?) and G0-D (does Plesk offer a real cron?). Until then the switch exists and nothing is wired to listen to it.

---

## The problem

On the VPS, Supervisor kept a queue worker alive and Horizon showed its state. Neither survives the move to shared hosting: there are no long-running processes, and Horizon is being removed because it hard-requires `ext-pcntl` and `ext-posix`, which shared PHP-FPM rarely has (`../audit/BASELINE.md` §3a).

The replacement is a cron entry running a bounded worker:

```
* * * * * cd ~/ethr/api && php artisan schedule:run
```

That works. What it does not do is notice its own absence.

If the Plesk cron entry is disabled, mis-saved, silently rate-limited, or quietly stops after an account change, **jobs stop and nothing says so.** There is no supervisor to restart, no dashboard to go red, no error anywhere — the application keeps serving pages normally. What stops is everything asynchronous:

| Job | What silence costs |
|---|---|
| `GenerateMonthlyInvoicesJob` | **Nobody is billed.** This is how the business takes money |
| `HandleOverdueInvoicesJob` | No dunning; overdue accounts stay active |
| `AccrueLeaveBalancesJob` | Leave entitlement never accrues on the 1st |
| `CarryForwardLeaveBalancesJob` | Year-end balances silently lost |
| `PullDeviceEventsJob` | Attendance stops arriving from biometric devices |
| `ScanMissingPunchesJob`, `ScanAttendanceAnomaliesJob` | Anomalies go unflagged |
| Notification and payslip jobs | Employees are not told anything |

The first three are the ones that hurt. An invoicing run that does not happen is indistinguishable, from the inside, from a month with no invoices due — and by the time someone notices, several billing cycles may have passed.

This is the highest-consequence operational failure available on shared hosting, and the existing migration plan has no detection for it. It was found in the Phase 0 baseline (§15, risk 9) and is recorded here rather than left as a line in a risk table.

## Precedent

This has already happened once on this project, with a supervisor present:

> `ScanAttendanceAnomaliesJob` failed on every scheduled run and was found only by opening the health endpoint for an unrelated reason.
> — `CLAUDE.md`, Queue Failure Recovery

The `Queue::failing` hook in `AppServiceProvider::boot()` exists because of it. That hook catches a job that **runs and fails**. It cannot catch a job that **never runs**, which is the failure mode cron introduces.

---

## Design

Three parts. The first two are buildable now; only the third waits on Gate 0.

### 1. Heartbeat

A scheduled task whose only job is to prove the scheduler ran.

- Writes a timestamp on every `schedule:run` — to the `cache` table (already database-backed, `CACHE_STORE=database`) or a single-row table.
- Cheap enough to run every minute without consideration.

The heartbeat's value is that it is independent of whether any *real* job was due. A quiet hour must look different from a dead scheduler.

### 2. Health endpoint

Extend the existing health surface (`HealthController`, and `SystemHealthService` for the admin page) to report:

| Signal | Meaning when bad |
|---|---|
| Heartbeat age | Scheduler is not running |
| Oldest unreserved `jobs.available_at` | Worker is not draining, or a queue name is unwatched |
| Depth per queue | Backlog building on a specific queue |
| `failed_jobs` count and newest entry | Jobs are running and failing — a different problem |
| Last successful run per scheduled task | One task is wedged while others are fine |

**Per-queue depth matters more than it looks.** Jobs dispatch onto `attendance`, `notifications`, `exports` and `default`, and a bare `queue:work` reads only `default` (`DB_QUEUE=default`). A misconfigured cron drains one queue and silently starves three — total depth would look healthy while attendance quietly stopped.

### 3. Dead-man's-switch

Alert when the heartbeat is stale or the oldest pending job exceeds a threshold.

Transport depends on **G0-H** (outbound SMTP 587/465, and the plan mailbox's send cap):

- **SMTP available** → mail the tenant admin and the operator.
- **SMTP blocked or capped** → an external uptime service polling the health endpoint. This is the better option regardless: it also catches the case where the whole site is down, which an alert sent *from* the dead host cannot.

Thresholds should start generous and tighten with evidence. A false alarm at 3am teaches people to ignore the alarm.

---

## Why scheduler staleness does not make `/health` return 503

Worth recording, because the first implementation got it wrong and the full test
suite caught it — twenty failures, all of them health-endpoint tests.

`QueueHealth::snapshot()` was wired straight into `services.queue`, and that
array decides the endpoint's 200-vs-503. Since a fresh deployment has never run
`schedule:run`, the heartbeat was absent, the snapshot said `unhealthy`, and
**`/health` returned 503 on every new install** — before anything was actually
wrong.

That is the exact failure this file and `HealthController` both already warn
about: a probe that cries wolf gets muted, and a muted probe is worth nothing on
the day the site really is down. It had already happened twice in this codebase
— a hardcoded `redis` cache check and a hardcoded `minio` disk check, each
reporting a permanent fault on deployments that used neither.

The separation that works:

| | Question | Audience | Signal |
|---|---|---|---|
| `services.queue` in `/health` | Can I reach the `jobs` table? | uptime monitor | 503 = the site is down, page someone |
| `queue_detail` in `/health` | Is anything actually running? | dashboards, humans | informational |
| `ethr:queue:check` | Is anything actually running? | cron, uptime check | exit 1 = look at cron in the morning |

A scheduler that stopped an hour ago is a real problem and **not** "the site is
down". Requests are still served. Conflating the two means either paging someone
at 3am for a cron job, or — far more likely — learning to ignore the page.

## Fix `SystemHealthService` alongside this

The admin health page is the first thing an operator opens after an alert, and it currently lies in two ways (`../audit/BASELINE.md` §9, §11):

- `SystemHealthService.php:66` hardcodes `Storage::disk('minio')`. Commit `80cac67` fixed four such sites and missed this one. It is inside `try/catch`, so on any non-MinIO deployment the page shows **storage permanently red** — which trains operators to ignore a red panel.
- `queueStatus()` polls queues named `default`, `high` and `low`. Those are not the queue names this application uses. The panel reports on queues that do not exist and ignores the four that do.

A monitoring page that is wrong in a familiar way is worse than no page. Both are small fixes and belong in the same slice as the heartbeat.

---

## Verification

**Disable the Plesk cron entry and confirm an alert fires within the threshold.**

That is the test. Everything else is a component check.

A monitor that has never been observed failing is not a monitor — it is an assumption with a dashboard. The same reasoning applies here as to the backup rehearsal in `../deployment/GATE-0-RESULT.md`: a backup that has never been restored is not a backup.

Secondary checks worth running once:

- Dispatch to `attendance` only, run a bare `queue:work`, confirm the health endpoint reports a starving queue rather than looking healthy.
- Stop the worker with jobs pending; confirm oldest-job age rises and crosses the threshold.
- Let a job fail; confirm `failed_jobs` surfaces separately from "not running", because the two need different responses.

---

## Related

| | |
|---|---|
| `../audit/BASELINE.md` §7, §15 | Queue architecture, and this risk in context |
| `../audit/BASELINE.md` §7a | `DB_QUEUE_RETRY_AFTER` at 90s against job timeouts up to 900s — five jobs execute twice under the database driver. **Unfixed.** Monitoring will not catch it; it looks like success |
| `../deployment/GATE-0-RESULT.md` | G0-D (cron type) and G0-H (SMTP) gate this design |
| `CLAUDE.md` Queue Failure Recovery | Per-queue failure policy, and the `Queue::failing` hook |
