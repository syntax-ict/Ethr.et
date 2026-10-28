# Operations centre — specification and gap list

**Status:** written 2026-10-28. **Phase 1 is built** on branch `feat/operations-centre`; phases 2 and 3 are not. Owner decisions are marked **OWNER**.

**What phase 1 shipped, and where it differs from the text below:**

- `GET /api/v1/admin/operations` (`Services/Admin/OperationsStatusService`) and the page at
  `/admin/operations`, with a summary strip on `/admin` that links to each panel.
- **Five areas, not six tiles:** background work, email delivery, release, failed jobs, services.
  Scheduled tasks, backups and organisations need data phase 2 records (G3, G4, G8), and a tile
  that could only ever say `unknown` would teach people to skip it.
- The *System Health* card on `/admin` **stays** for now, beside the strip. Removing it touches
  the failed-jobs list it shares a card with; that belongs with G9.
- G2 records caller ticks in the cache: `CronRunController` on arrival (a 409 still proves the
  caller is alive), and `RunBackgroundWorkAfterResponse` when it runs. Ticks are kept for 30
  days, so a caller that has been dead for two days reads as stale rather than as never seen.
- G7 compares cache times with `release.json`'s `built_at`: a configuration or route cache older
  than the release is stale by definition, which is the 404-after-deploy trap.
- G13: health probes now return `error: "unavailable"` and log the driver's message.
- Tests: `tests/Feature/Admin/OperationsStatusTest.php` (17) and
  `src/src/test/admin-operations-page.test.tsx` (5).
- **Live 2026-10-28 as v2026.10.28.6.** The first look found that every health timestamp
  (heartbeat, caller ticks, last drain, mail) lived in the default cache store, which the
  release step `optimize:clear` empties, so the console read "never" right after each deploy.
  They now live in a `signals` file store under `storage/framework/signals`
  (`App\Support\HealthSignals`), which nothing clears. Until a deploy's cached configuration
  knows that store, they fall back to the default one rather than throw.

**One question the page answers:** *is ETHR doing its work right now, for every organisation?*

Not "is the site up" — an uptime monitor answers that, and the console cannot, because a dead
host cannot render the page that says it is dead. The operations centre answers the question
behind it: the site is up, but did payroll finish, did last night's backup run, are emails
leaving, is anything waiting that should not be?

---

## Why ETHR needs this more than most SaaS

Three facts about the production shape make "the site works" a poor proxy for "the product
works":

1. **There is no worker process.** Every job, scheduled task and backup arrives because
   something POSTs to `/api/v1/cron/{schedule,queue}` (GitHub Actions and cron-job.org), or
   because a signed-in person's request drains the queue for a few seconds after its response
   (`RunBackgroundWorkAfterResponse`). If both stop, every page still loads and nothing
   happens. See [QUEUE-MONITORING.md](QUEUE-MONITORING.md).
2. **The host has no shell and no Scheduled Tasks section.** The operator cannot `tail` a log,
   run `artisan`, or list cron entries. Whatever the console does not show, the operator
   cannot see.
3. **The business runs on scheduled work.** Monthly invoices, overdue-invoice handling, leave
   accrual, device sync, auto-checkout, photo retention, backups, cleanup — 24 `Schedule::`
   entries in `api/routes/console.php`. A missed one fails silently and is found by a
   customer.

---

## What exists today

| Signal | Recorded by | Shown in console? |
|---|---|---|
| Database, cache and storage round trips | `Services/Admin/SystemHealthService` | Yes, *System Health* card |
| Queue depth per queue | `SystemHealthService::queueStatus()` | Yes |
| Failed jobs: list, retry, retry all, dismiss | `AdminDashboardController` | Yes |
| Disk free, PHP memory | `SystemHealthService::resourceUsage()` | Yes |
| **Scheduler heartbeat**, last queue drain, **oldest waiting job per queue**, problems list | `Services/Observability/QueueHealth::snapshot()` | **No.** Only on public `/health` as `queue_detail`, and in `ethr:queue:check` |
| Mail last sent, last failed, TLS mode | `Support/Mail/MailHealth` | **No.** Only on `/health` |
| Webhook delivery status, attempts, response code | `webhook_deliveries` table | No (per organisation only) |
| Device last sync, sync interval, sync log | `devices.last_sync_at`, `device_sync_logs` | No |
| Live release tag | `api/public/release.json`, read by `scripts/release-status.sh` | No |
| SMS sends | `sms_sends` (slot, purpose, created_at — **no outcome**) | Cap usage only, at `/admin/sms` |
| Backups | `ethr:backup` daily 01:00 UTC; writes archives, **records nothing** | No |
| Per-scheduled-task last run | **Not recorded** | No |
| Which caller kept the scheduler alive | **Not recorded** | No |

The most valuable fact here is that the hard part is mostly done. `QueueHealth` already
answers "is anything actually running?" with sensible thresholds and careful wording, but the
one page an operator opens after an alert asks a different, weaker question: "can I reach the
`jobs` table?"

---

## Principles (each one learned the hard way in this codebase)

1. **Never cry wolf.** A permanently red panel gets ignored. Storage was red for weeks over a
   hard-coded `minio` disk; the cache row said "Redis" on a host with no Redis. Every check
   needs an explicit `unknown` / `not configured` state that is **not** red.
2. **"Down" and "not running" are different alerts.** `/health` returns 503 only for "the site
   is down". Scheduler staleness stays out of it (recorded in QUEUE-MONITORING.md). The
   operations centre is where "not running" lives.
3. **A failure and an absence are different.** A failed job ran and broke. A job that never ran
   leaves no row. Show both, never add them together.
4. **Show age, not just state.** "Healthy" is meaningless without "as of when". Every row
   carries the time of its last evidence.
5. **Read-only by default.** Every action is audited, behind `admin.manage` and platform MFA,
   and allow-listed.
6. **Cheap enough for shared hosting.** One page load must not scan `jobs`, `webhook_deliveries`
   or `audit_logs` without an index. Aggregates are cached for 60 seconds.
7. **Cross-tenant by design, stated per query.** Aggregates across organisations need
   `withoutGlobalScope`, which moves the inventory in
   `tests/Feature/Security/tenant-scope-bypasses.php`. Each new site says why in that file.
   Prefer `DB::table()` aggregates that return counts and organisation ids, never row content.

---

## The page

One route, `/admin/operations`, replacing the *System Health* card on `/admin` with a summary
strip that links to it.

### Top strip: the rollup

Six tiles, each **green / amber / red / unknown**, each with the age of its evidence:

`Background work` · `Scheduled tasks` · `Delivery` · `Backups` · `Organisations` · `Release`

The worst tile sets the page title, for example "2 need attention". Clicking a tile scrolls to
its panel.

### Panel 1: Background work

| Row | Source | Amber | Red |
|---|---|---|---|
| Scheduler last ran | `QueueHealth::lastBeat()` | > 10 min | > 15 min, or never |
| **Last external caller tick** | *new*, see G2 | > 30 min | > 2 h |
| Last page-traffic drain | *new*, see G2 | informational | — |
| Last queue drain | `QueueHealth::lastDrain()` | > 15 min with jobs waiting | > 30 min with jobs waiting |
| Per queue: depth, oldest waiting | `QueueHealth::snapshot()` | oldest > 15 min | oldest > 30 min |

**Why the external caller row matters:** `RunBackgroundWorkAfterResponse` beats the scheduler
whenever someone is using ETHR. During working hours that masks a dead external caller
completely. At night, with no traffic, the external caller is the only thing that runs the
01:00 UTC backup and the 02:00 cleanup. Today the heartbeat cannot tell the two apart, so a
broken caller is found the morning a backup is missing.

### Panel 2: Scheduled tasks

One row per `Schedule::` entry: name, cadence, last started, last finished, last result, next
due, last duration. A task is **red** when it is overdue by more than one cadence (daily → late
by more than 26 h; monthly → the 1st passed without a run). Lead with the business-critical
tasks:

- `GenerateMonthlyInvoicesJob` (1st, 03:00) — this is how the business bills
- `HandleOverdueInvoicesJob` (daily 04:00)
- `ethr:backup` (daily 01:00)
- `CleanupExpiredDataJob` (daily 02:00)
- `devices:sync`, `attendance:auto-checkout`, `attendance:push-reminders` (every 5 min)
- `attendance:photo-retention` (hourly): a consent promise to employees, so a missed run is a
  compliance fact, not only an ops one

### Panel 3: Failed jobs (exists, extend)

Keep list, retry, retry all and dismiss. Add:

- **Group by job class** with count, first seen and last seen. Fifty identical failures are
  one problem.
- **Organisation** column, decoded from the payload's `tenantId`. The three queued jobs carry
  it as a required argument since §11j.
- **Retry-safety badge.** Mark `ProcessPayrollJob` "safe: idempotent, skips a run that is not
  `processing`". Mark jobs that send mail or SMS "may send twice". *Retry all* asks for
  confirmation when any job in the set is not safe.

### Panel 4: Delivery

| Channel | Shows | Source |
|---|---|---|
| Email | last sent, last failed, TLS mode (pinned / verified / none) | `MailHealth::snapshot()` |
| SMS | sends today vs cap, active slot, **success rate (24 h)** | `sms_sends` + *new* outcome column, G5 |
| Webhooks | deliveries (24 h), success rate, exhausted (gave up), worst 5 organisations | `webhook_deliveries` |
| Web push | subscriptions, failures (24 h), expired endpoints pruned | `push_subscriptions` |

The email row has a single red condition: last failed is later than last sent. That is the
signature of the pinned Ethio Telecom certificate expiring or being replaced.

### Panel 5: Backups

Last successful backup (time, size, duration), archives kept vs `BACKUP_KEEP`, **off-host copy:
not configured** (amber, permanently, until it is), last restore rehearsal
(`ethr:backup-rehearsal`). Red when there is no successful backup in 26 h.

The off-host row stays amber on purpose. A backup that exists only on the host it protects is
the most important known gap on this page, and the panel should keep saying so until it is
closed.

### Panel 6: Organisations: work stuck for a customer

What a customer would phone about, listed by organisation and linked to the organisation page:

- Payroll runs at `processing` for more than 15 minutes
- Data exports pending for more than 30 minutes (`tenant_exports`)
- Devices overdue for sync, from `Device::isDueForSync()` (`auto_sync` on, last sync older than
  its interval)
- Unmatched device punches waiting (`unmatched_device_punches`)
- Custom domains failing `tenancy:recheck-custom-domains`
- Webhooks paused or exhausted

### Panel 7: Release and configuration

- **Live release:** tag, commit and build time from `release.json`, the same file
  `release-status.sh` reads, plus how long ago it went live
- **Pending migrations:** count. Red when not zero, because the last several releases needed
  `migrate` as a release step.
- **Config and route cache present.** A stale route cache meant 404s after a deploy (recorded
  2026-10-10).
- `APP_DEBUG` off, `QUEUE_CONNECTION` not `sync`, `BROADCAST_CONNECTION` as expected
- **`MAINTENANCE_TOKEN` empty.** Red when set. Every release-step procedure ends with emptying
  it, and this makes the step visible.
- `CRON_TOKEN` set (yes/no only)
- PHP limits as served: `upload_max_filesize`, `post_max_size`, `memory_limit`,
  `max_execution_time`. The live values are provider-locked at 2M/8M/128M/30s, and uploads are
  designed around that.

Booleans and limits only. Never a secret's value or length.

### Panel 8: Capacity

Disk free (exists), database size and its five largest tables, private storage per
organisation (top 10), row counts of `jobs`, `failed_jobs`, `cache` and `webhook_deliveries`.
On a 5 GB plan, growth shows up here first.

---

## Actions

Every action: `admin.manage`, `EnsurePlatformContext`, `RequirePlatformMfa`, rate limited,
written to the audit log with the operator and a reason.

| Action | Notes |
|---|---|
| Retry / dismiss failed job | exists |
| **Drain the queue now** | `CronRunner::queue()`, same lock as `/cron/queue`, bounded seconds |
| **Run scheduler catch-up now** | `CronRunner::schedule()` |
| **Run one task now** | allow-list only: `ethr:backup`, `tenancy:recheck-custom-domains`, `devices:sync`. Never invoicing or cleanup from a button. |
| **Send test email / SMS** | SMS test exists at `/admin/sms`. Email goes to the operator's own address only. |

**OWNER:** whether `migrate` / `optimize` belong in the console. Today they run through the
token-guarded maintenance endpoint, and the token is emptied afterwards. A console button would
remove the token dance, but it would make every compromised platform session able to run
migrations. Recommendation: **not** in this module.

---

## Alerting

The console is where you look, not what wakes you. Two channels, both outside the host:

1. **External uptime monitor** polls `/health` (exists) for "site down".
2. **Dead-man's switch:** cron-job.org (already a caller) or an uptime service polls a new
   token-guarded `GET /api/v1/ops/status`. It returns 200 when all tiles are green or amber and
   503 when any is red, with the tile names only in the body. This puts `ethr:queue:check`'s
   logic on a URL, because there is no shell to run the command from.

Email on **state transitions only** (green→red, red→green), never on every poll. Mail leaves
from the same host it would be reporting on, so it is a convenience; the external poller is the
real alarm.

---

## Gap list

Ordered by value per effort. S ≈ under a day, M ≈ 1–3 days, L ≈ a week.

| # | Gap | Size | Schema? |
|---|---|---|---|
| G1 | Admin health ignores `QueueHealth::snapshot()`: heartbeat, oldest waiting job and problems never reach the console | S | no |
| G2 | Scheduler beats do not record their source, so an external caller that has stopped is invisible while people use the app. Record `last_external_tick_at` in `CronRunController`, and `last_opportunistic_tick_at` in the middleware (cache keys, same pattern as `QueueHealth`). | S | no |
| G3 | No per-task run ledger. Use Laravel's `ScheduledTaskStarting/Finished/Failed` events to write `scheduled_task_runs` (task, started, finished, outcome, ms), pruned to 30 days. | M | yes |
| G4 | `ethr:backup` records nothing. Write the outcome (name, bytes, ms, off-host yes/no) to a ledger or cache key; read the rehearsal outcome the same way. | S | optional |
| G5 | `sms_sends` has no outcome, so an SMS success rate cannot be computed. Add `status` + `error_code` (no message text). | S | yes |
| G6 | `MailHealth` is shown only on public `/health` | S | no |
| G7 | Live release, pending migrations, cache state and `MAINTENANCE_TOKEN` are not visible anywhere on the host side | S | no |
| G8 | Stuck-work queries (payroll, exports, devices, domains), cross-tenant, with inventory entries | M | no |
| G9 | Failed jobs not grouped, no organisation column, no retry-safety badge | M | no |
| G10 | `GET /ops/status` dead-man's endpoint + cron-job.org monitor | S | no |
| G11 | Console actions: drain now, catch-up now, allow-listed run-now | M | no |
| G12 | Capacity panel: table sizes, storage per organisation | M | no |
| G13 | `SystemHealthService` returns raw exception messages (`$e->getMessage()`) for database, cache and storage. Admin-only, but a connection error can name the host and database. Return a fixed message and log the detail. | S | no |
| G14 | Off-host backup copy is not configured (`--off-host` deliberately absent) | — | **OWNER** (needs a destination and credentials) |

## Phasing

- **Phase 1: show what is already recorded.** G1, G2, G6, G7, G13, plus the new page and its
  rollup strip. No migrations, one PR, and it closes the largest blind spot (a dead external
  caller during working hours).
- **Phase 2: record what is not.** G3, G4, G5, G8, G9. Two small migrations. Run them on
  scratch MariaDB before pushing: index names over 64 characters fail there and not on SQLite.
- **Phase 3: act and alert.** G10, G11, G12, and G14 once the owner chooses an off-host
  destination.

## Acceptance

- Each tile has a test for green, amber, red **and** unknown. A fresh install with no
  heartbeat shows `unknown — never run`, not red, and says what to configure.
- Stopping the external caller while page traffic continues turns *Last external caller tick*
  amber within 30 minutes, while *Scheduler last ran* stays green. This is the scenario G2
  exists for, and it needs its own test.
- No payload contains a secret, an email address, a phone number or an SMTP error message:
  asserted on the JSON, the same as `/health`.
- `TenantScopeBypassInventoryTest` moves only by the sites listed in the PR, each one recorded
  with its reason.
- The page loads in under 1 s on the seeded demo database, with the `scripts/gates.sh
  performance` budget extended to cover it.
- UI copy follows the house style: no em dashes, emoji or exclamation marks, en + am keys
  present (`copy-style.test.ts`).

## Open questions for the owner

1. Off-host backup destination (G14): which provider, and who holds the credentials?
2. Who receives transition emails: the platform owner only, or a list?
3. Should organisation admins see a reduced version, such as "your payroll is processing,
   last background run 2 min ago"? It would cut support calls, but it is a separate decision.
