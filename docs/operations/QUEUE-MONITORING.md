# Queue and scheduler monitoring

**Status 2026-11-13: built, running, and watched from off the host.** Everything in *Current
state* below describes what runs today. Everything under *How this was designed, and what it
replaced* is kept as the reasoning — it is the most useful part of this file and the part most
easily lost, but it was written before the decisions were taken and its future tense is no
longer true.

*(Restructured 2026-11-13. This file opened with "the alert transport still depends on Gate 0",
said the external caller was unchosen and still a question for the owner, printed a crontab line
for a host that has no cron, and stated that "the switch exists and nothing is wired to listen to
it". All four were overtaken by events: the caller was chosen on 2026-09-27, the second caller and
its uptime monitor on 2026-10-12 and 2026-10-16, and the operations centre on 2026-10-28. A
status banner on top of a design document is not a correction, which is why the body moved
instead.)*

---

## Current state

### How background work actually runs

There is no worker process, no cron entry and no shell. Three things drive the schedule and the
queue:

| Driver | What it hits | Cadence |
|---|---|---|
| **GitHub Actions** — `.github/workflows/cron.yml` | `POST /api/v1/cron/schedule`, `POST /api/v1/cron/queue` | Written `*/5`, **4 to 6 times a day in practice** (F23). Fires only from the default branch |
| **cron-job.org** — the second caller | The same two endpoints, plus `GET /api/v1/cron/backup` | Chosen 2026-10-12. Account and alert address in [`CUSTODY.md`](CUSTODY.md) |
| **Ordinary traffic** — `RunBackgroundWorkAfterResponse` | Drains the queue in `terminate()`, and catches the schedule up | Queue at most once a minute, schedule at most once every five ([`cron-caller.md`](../deployment/shared-hosting/cron-caller.md) §5a) |

**The schedule endpoint runs `ethr:schedule-run`, not `schedule:run`**
(`api/app/Services/Observability/CronRunner.php:32`), and so does the middleware. That command
replays every event due since the last run. *(Corrected 2026-11-13: this file said the endpoints
"run only `schedule:run` and `queue:work` — not arbitrary commands". The second half still holds
and is the point; the first names the wrong command, and the difference is the whole reason the
schedule tolerates a caller that arrives six times a day.)*

So **a long gap between calls costs lateness, not a skipped run.** An entry due at 06:00 may not
run until 09:00. That is the failure mode to design against — see
[`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md), *The five-minute rule is gone*.

### What watches it, and what does not

| | Does it watch? |
|---|---|
| `GET /api/v1/health` → `queue_detail` | **Yes, and it is the mechanism.** Unauthenticated behind `throttle:health`, it publishes the heartbeat age, `last_drain_at`, per-queue depth and oldest waiting job, and the failed-job count. The uptime monitor polls it |
| `GET /api/v1/cron/backup` | **Yes.** Answers **503** while `BackupHealth` is critical — a failing or stale backup, or disk above 85%. A GET so a monitor can watch it. The second caller emails after one failure |
| `/admin/operations` | **Yes, for a human.** The page an operator opens: heartbeat, each caller's last tick, last drain, queue depths, failed jobs, mail, release, services, backups. [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) |
| `ethr:queue:check` | **No, and it must not.** It is absent from `api/routes/console.php` and from `cron.yml`. Scheduling it would be circular — a watchdog this scheduler starts cannot report that this scheduler stopped — so the one state it exists to detect is the one it could never reach. It works and it is tested; treat it as a command for a terminal, or for a deployment that has a runner. *(Three docblocks said it "runs from cron" until 2026-11-13, which made adding the entry look like the fix; all three now carry the reasoning, and `OperationsCommandsTest` pins that no entry mentions `queue:check`.)* |

That last row is the correction this file most needed. It described itself as the dead-man's
switch and named the CLI as its arm. The arm is off-host: a monitor reading two HTTP endpoints.

### Reading the signals

`QueueHealth::snapshot()` is the single source. Its thresholds are **900 s** for scheduler
staleness and **1800 s** for the oldest waiting job (`QueueHealth.php`). Reviewed 2026-11-13
against the caller that was actually chosen and **left as they are**, deliberately:

- Against a caller arriving 4 to 6 times a day, 900 s is crossed routinely. That is correct for
  this host. `queue_detail` going amber means "nothing has run for fifteen minutes", which is
  *information*, not an alarm, and it never 503s (see below).
- Tightening them would make the field permanently amber and teach everyone to ignore it.
  Widening them to fit a six-hour gap would hide a caller that has genuinely died.
- What distinguishes a quiet gap from a dead caller is **not** the heartbeat age but
  `last_external_tick_at` against `last_opportunistic_tick_at`, which the operations centre
  shows separately. A live site with no external tick for two days is the real signal, and it is
  the scenario the tick recording exists for.

Three failure modes this host has that a cron entry did not:

1. **A wrong or too-short token returns 404, by design.** `VerifyCronToken` 404s on an unset
   token, a token under 32 characters, and a mismatch, so an unconfigured deployment does not
   advertise that these routes exist. The cost is that **a misconfigured caller is
   indistinguishable from a missing route**. If background work has stopped and the caller
   reports 404, check `CRON_TOKEN` before concluding the deploy is broken. The short-token case
   logs `CRON_TOKEN is shorter than the configured minimum`; that line is the only outward
   difference.
2. **409 is normal, not an error.** The previous drain is still going. A caller that treats
   non-200 as failure will alarm under ordinary load.
3. **The callers are off-host, so their silence leaves no trace here.** A cron entry that stops
   at least leaves a configuration to look at. A workflow that was disabled, a free-tier account
   that was suspended, a token rotated on one side only — none of that is visible on this
   account. The recorded ticks are the only signal.

**Scheduler fresh does not mean the queue is draining.** The two endpoints are independent calls.
A caller configured for `/cron/schedule` but not `/cron/queue` keeps the heartbeat perfectly
fresh while nothing drains: jobs pile up and the scheduler looks healthy. The per-queue
oldest-job check is what catches it. Configure and verify both, not just the one that moves the
heartbeat.

### Why scheduler staleness does not make `/health` return 503

Worth recording, because the first implementation got it wrong and the full suite caught it —
twenty failures, all health-endpoint tests.

`QueueHealth::snapshot()` was wired straight into `services.queue`, and that array decides the
endpoint's 200-vs-503. Since a fresh deployment has never run the scheduler, the heartbeat was
absent, the snapshot said `unhealthy`, and **`/health` returned 503 on every new install** —
before anything was actually wrong.

That is the exact failure this file and `HealthController` both warn about: a probe that cries
wolf gets muted, and a muted probe is worth nothing on the day the site really is down. It had
already happened twice in this codebase — a hardcoded `redis` cache check and a hardcoded `minio`
disk check, each reporting a permanent fault on deployments that used neither.

The separation that works:

| | Question | Audience | Signal |
|---|---|---|---|
| `services.queue` in `/health` | Can I reach the `jobs` table? | uptime monitor | 503 = the site is down, page someone |
| `queue_detail` in `/health` | Is anything actually running? | dashboards, humans | informational |
| `/admin/operations` | Is anything actually running, and since when? | the operator | amber and red, never a page |
| `GET /cron/backup` | Did last night's backup land, and is there disk? | uptime monitor | 503 = act today |

A scheduler that stopped an hour ago is a real problem and **not** "the site is down". Requests
are still served. Conflating the two means either paging someone at 3am for a cron job, or — far
more likely — learning to ignore the page.

### First checks

| Symptom | Look at |
|---|---|
| Nothing asynchronous happens | `/admin/operations` → *Background work*. Stale heartbeat **and** stale external tick means a caller stopped. Fresh opportunistic tick with a stale external one means both callers are down and only traffic is keeping things moving |
| Jobs pile up, heartbeat fresh | Only `/cron/schedule` is wired, or the drain is timing out under the web request's `max_execution_time` |
| Background work stopped, caller reports 404 | `CRON_TOKEN` on the host against the GitHub secret and the second caller. All three hold one value |
| `failed_jobs` grows | A different problem: jobs ran and threw. `/admin/operations` → *Failed jobs*. Read the exception before *retry all* — payroll is deliberately single-attempt |

---

## How this was designed, and what it replaced

**Everything below is history.** It is kept because the reasoning is still correct and still worth
reading, and because three of its principles are load-bearing in code. It was written in
September 2026, before the callers were chosen; its future tense refers to work that is now done.

### The problem it was written for

On the VPS, Supervisor kept a queue worker alive and Horizon showed its state. Neither survives
the move to shared hosting: there are no long-running processes, and Horizon hard-requires
`ext-pcntl` and `ext-posix`, which shared PHP-FPM rarely has
([`BASELINE.md`](../audit/BASELINE.md) §3a).

The replacement considered at the time was a cron entry running a bounded worker. *(That is the
one passage of this file that was not merely dated but actively misleading, and it is removed:
it printed a crontab line, and **the target account has no Scheduled Tasks section at all** —
G0-D is FAIL, owner-read 2026-09-18. There was never a cron entry to write.)*

Whatever drives it, the thing a scheduler does not do is **notice its own absence.** If the
driver is disabled, mis-saved, silently rate-limited, or quietly stops after an account change,
**jobs stop and nothing says so.** There is no supervisor to restart, no dashboard to go red, no
error anywhere — the application keeps serving pages normally. What stops is everything
asynchronous:

| Job | What silence costs |
|---|---|
| `GenerateMonthlyInvoicesJob` | **Nobody is billed.** This is how the business takes money |
| `HandleOverdueInvoicesJob` | No dunning; overdue accounts stay active |
| `AccrueLeaveBalancesJob` | Leave entitlement never accrues |
| `CarryForwardLeaveBalancesJob` | Year-end balances silently lost |
| `PullDeviceEventsJob` | Attendance stops arriving from biometric devices |
| `ScanMissingPunchesJob`, `ScanAttendanceAnomaliesJob` | Anomalies go unflagged |
| Notification and payslip jobs | Employees are not told anything |

The first three are the ones that hurt. An invoicing run that does not happen is
indistinguishable, from the inside, from a month with no invoices due — and by the time someone
notices, several billing cycles may have passed. It remains the highest-consequence operational
failure available on this host.

### Precedent

This had already happened once on this project, with a supervisor present:

> `ScanAttendanceAnomaliesJob` failed on every scheduled run and was found only by opening the
> health endpoint for an unrelated reason.
> — `docs/CLAUDE.md`, Queue Failure Recovery

The `Queue::failing` hook in `AppServiceProvider::boot()` exists because of it. That hook catches
a job that **runs and fails**. It cannot catch a job that **never runs**, which is the failure
mode an external driver introduces.

### The three parts, and where each one landed

| Design | Built as |
|---|---|
| **1. Heartbeat** — a scheduled task whose only job is to prove the scheduler ran, written every minute to a store that is already database-backed. Its value is being independent of whether any *real* job was due: a quiet hour must look different from a dead scheduler | `QueueHealth::beat()`, `everyMinute()` in `api/routes/console.php`. Since 2026-10-28 the timestamps live in a `signals` file store under `storage/framework/signals` (`App\Support\HealthSignals`), because the default cache store is emptied by the release's `optimize:clear` and the console read "never" after every deploy |
| **2. Health endpoint** — heartbeat age, oldest unreserved job, depth per queue, `failed_jobs` count, last successful run per task. **Per-queue depth matters more than it looks**: jobs dispatch onto four queues and a bare `queue:work` reads only `default`, so a misconfigured drain starves three while total depth looks healthy | `queue_detail` on `/api/v1/health`, and the whole of `/admin/operations`. Per-task last-run is the one item still unbuilt (G3) |
| **3. Dead-man's switch** — alert when the heartbeat is stale or the oldest job crosses a threshold. Transport depends on whether outbound SMTP works (G0-H); **an external uptime service polling the health endpoint is the better option regardless, because it also catches the case where the whole site is down, which an alert sent *from* the dead host cannot** | Exactly that, since 2026-10-16: cron-job.org polls `/api/v1/health` and `/api/v1/cron/backup` and emails after one failure. The reasoning in the original was right, and it is why no in-app alerting was built |

The most valuable sentence in the original design turned out to be the aside in part 3, and it is
the one that decided the architecture.

### Never cry wolf

The principle the three parts were built under, and the first of the operations centre's own
([`OPERATIONS-CENTRE-SPEC.md`](OPERATIONS-CENTRE-SPEC.md)): every check needs an explicit
`unknown` / `not configured` state that is **not** red. Thresholds should start generous and
tighten with evidence. A false alarm at 3am teaches people to ignore the alarm.

Two concrete instances, both resolved:

- **The "never run" message named something that does not exist here.** `snapshot()` distinguishes
  never-beaten from stale, and the never-beaten message read *"scheduler has never run — is the
  Plesk Scheduled Task created?"*. There is no Scheduled Task to create on this account, so an
  operator following that message went looking for a panel section that is not there, on the one
  code path where nothing else was telling them anything. Fixed 2026-09-28 to name the external
  caller and its token. The same stale claim was in five other places — `QueueHealth`'s docblock,
  both scheduler comments in `routes/console.php`, `BackupCommand`, `QueueHealthTest`, and a
  `BACKUP-RESTORE.md` passage — all reconciled in the same pass.

  **The lesson is the gap this paragraph sat in.** It identified the defect, gave the correct
  reading, and left the string in place for three days. Documenting a defect is not fixing it,
  and a note telling operators to mentally translate a runtime message is a workaround with no
  enforcement — the next operator reads the message, not this file.
- **`SystemHealthService` lied in two ways**, and the admin health page is the first thing an
  operator opens after an alert. It hardcoded `Storage::disk('minio')` inside a `try/catch`, so
  on any non-MinIO deployment storage showed permanently red; and `queueStatus()` polled queues
  named `default`, `high` and `low`, which this application does not use — reporting on queues
  that do not exist while ignoring the four that do. **Both resolved 2026-10-01** (audit D15): it
  probes `Storage::disk(config('filesystems.default'))` and reads its queue list from
  `QueueHealth::QUEUES`. Kept here as the record of what a monitoring page that is wrong in a
  familiar way costs.

### Verification

**Stop a caller — disable the workflow, or rotate `CRON_TOKEN` on one side only — and confirm an
alert fires.** That is the test; everything else is a component check. Rotating the token on one
side is the closer analogue of the failure that actually worries us, because it is silent on the
host and returns 404 to the caller.

A monitor that has never been observed failing is not a monitor — it is an assumption with a
dashboard. The same reasoning applies as to the backup rehearsal
([`GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md)): a backup that has never been restored is
not a backup. **This has not been rehearsed on the production host.**

Secondary checks worth running once:

- Dispatch to `attendance` only, run a bare `queue:work`, confirm the health endpoint reports a
  starving queue rather than looking healthy.
- Stop the drain with jobs pending; confirm oldest-job age rises and crosses the threshold.
- Let a job fail; confirm `failed_jobs` surfaces separately from "not running", because the two
  need different responses.
- Keep traffic flowing while both callers are stopped; confirm the external tick goes stale while
  the heartbeat stays fresh. This is the scenario tick recording exists for.

---

## Related

| | |
|---|---|
| Every command and the full schedule | [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| Reading `/admin/operations` field by field | [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) |
| What the page was specified to be, and its remaining gaps | [`OPERATIONS-CENTRE-SPEC.md`](OPERATIONS-CENTRE-SPEC.md) |
| The health endpoint, field by field | [`health-check.md`](../deployment/shared-hosting/health-check.md) |
| Setting up and troubleshooting the callers | [`cron-caller.md`](../deployment/shared-hosting/cron-caller.md) |
| Symptom to action | [`ON-CALL.md`](ON-CALL.md) |
| `BASELINE.md` §7, §15 | Queue architecture, and this risk in context |
| `BASELINE.md` §7a | `DB_QUEUE_RETRY_AFTER` against job timeouts. **Fixed:** `api/config/queue.php` now defaults it to **1200**, above the longest job timeout of 900 s (`BackupTenantJob`, `ExportTenantDataJob`), so a job no longer becomes visible to a second worker while the first still holds it. *(This row read "at 90s against job timeouts up to 900s — five jobs execute twice under the database driver. **Unfixed.** Monitoring will not catch it; it looks like success." The diagnosis was right and the remedy has shipped; corrected 2026-11-13.)* |
| `docs/CLAUDE.md` Queue Failure Recovery | Per-queue failure policy, and the `Queue::failing` hook |
