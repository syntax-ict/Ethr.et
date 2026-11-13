# Artisan commands and the schedule

**Rebuilt 2026-11-13 from `php artisan list` and `php artisan schedule:list` run against
`api/`.** When this disagrees with the code, the code is right — those two commands print the
live answer, and this page is only ever a snapshot of them.

*(It had drifted far enough to mislead. The previous version was derived on 2026-09-30 and
described **14 of 24** commands and **18 of 27** scheduled entries. Two of its statements were
not merely incomplete but wrong, and both are corrected below: the cadence of leave
carry-forward, and the rule about which minutes a scheduled entry may use.)*

Nothing runs a daemon on the production host. Three things drive the schedule and the queue,
and the first two are off-host:

1. **GitHub Actions** (`.github/workflows/cron.yml`) POSTs `/api/v1/cron/schedule` and
   `/api/v1/cron/queue`. It is written as every five minutes and delivers **4 to 6 times a day
   in practice** (F23). It only fires from the repository's **default branch**.
2. **A second URL-fetch caller** (cron-job.org) hits the same endpoints, and separately polls
   `GET /api/v1/cron/backup`, which answers 503 while backups are failing. See
   [`CUSTODY.md`](CUSTODY.md) for who holds that account.
3. **Ordinary traffic.** `RunBackgroundWorkAfterResponse` drains the queue for a few seconds
   after a response has been sent, at most once a minute, and catches the schedule up at most
   once every five
   ([`cron-caller.md`](../deployment/shared-hosting/cron-caller.md) §5a).

Both cron endpoints sit behind `throttle:cron` then `VerifyCronToken` (`api/routes/api.php`),
in that order. A wrong or too-short token answers **404**, deliberately, so there is no 401 to
look for. A **409** from the queue endpoint is normal: the previous drain is still running.

## Commands

### Platform accounts and access

| Command | What it does | Notes |
|---|---|---|
| `ethr:create-admin` | Create, or with `--force` update, a platform super admin. `--email=`, `--password=` (min 12 chars, prompted securely if omitted) | **The production way to get a super admin.** It never reads `SUPER_ADMIN_PASSWORD`; that belongs to the dev seeder only |
| `ethr:reset-mfa` | `{email}`, `--tenant=<slug>` — turn two-factor off for someone who lost their authenticator | For an account nobody in the app can reset: a platform super admin, or an organisation's only admin. Leave `--tenant` out for a platform account. Needs a holder of the host |

### Backups and restore

| Command | What it does | Notes |
|---|---|---|
| `ethr:backup` | Back up the database and employee documents. `--label=`, `--keep=`, `--off-host`, `--disk=` | `--keep` defaults to `config('backup.keep')` (`BACKUP_KEEP`); `--disk` names a `.env` disk, and without it the storage saved in the console (`/admin/backups`), then `BACKUP_OFF_HOST_DISK`. Copies an encrypted archive off the host whenever storage and a key are set; `--off-host` makes a missing copy a failure. **Also measures disk use** against `DISK_QUOTA_MB` and refuses to run above 95% of it. Scheduled daily 01:00 |
| `ethr:restore {name}` | Restore from a backup directory name or an absolute path. `--force` skips the prompt; a declined or non-interactive run without it restores nothing and **exits 1** (it exited 0 until 2026-10-02) | Destructive. Needs the `APP_KEY` the backup was taken under — `Employee.tin` and `national_id` are encrypted. [`BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md) |
| `ethr:backup:key` | Print a new `BACKUP_ENCRYPTION_KEY` for `.env` setups; the console generates its own. Writes nothing | Keep a copy off the host: without it no off-host archive opens |
| `ethr:backup:unpack {archive}` | Decrypt an off-host archive and unpack it into the backup folder for `ethr:restore`. `--from-disk`, `--disk=`, `--key=` | `--key` for a new host whose `.env` lacks the old key. Refuses a name already on the host |
| `ethr:backup:rehearse` | Back up, drop everything, restore, verify. `--force`, `--keep` | **Destroys the database.** Refuses unless the environment and database name read as disposable, unless `--force`. Never run it on the production host; the rehearsal there is still outstanding (M6) |

### Health and diagnostics

| Command | What it does | Notes |
|---|---|---|
| `health:check` | Are the database and cache reachable | Command-line twin of `GET /api/v1/health` |
| `ethr:queue:check` | Exit 1 if the scheduler has gone silent or a queue is starving. `--json`, `--scheduler-stale=900`, `--oldest-job=1800` | **Nothing in this repository runs it** — see *What actually watches this* below. [`QUEUE-MONITORING.md`](QUEUE-MONITORING.md) |
| `ethr:mail-probe` | Show the SMTP certificate chain and whether it verifies. `--timeout=15`. **Sends nothing** | Also reachable as the `mail-probe` maintenance task, which is how it is run on the host |
| `ethr:schedule-run` | `schedule:run` with catch-up: every event due since the last run | **This is what the cron endpoint calls**, not `schedule:run` (`api/app/Services/Observability/CronRunner.php:32`). It is why the schedule survives a caller that arrives a few times a day |

### Keys and generated artifacts

| Command | What it does | Notes |
|---|---|---|
| `ethr:webpush-keys` | Print a VAPID key pair for web push | Changes nothing. Run once; see [`PLESK-GO-LIVE.md`](../deployment/PLESK-GO-LIVE.md) |
| `ethr:api-reference` | Build the API reference an organisation's developers see. `{source=openapi.json}`, `--output=` | Reads what `scramble:export` wrote. Build-time, not operational |

### Attendance

| Command | What it does | Notes |
|---|---|---|
| `devices:sync` | Pull attendance events from auto-sync devices. `--tenant=<public_id>` | Every five minutes, `withoutOverlapping` |
| `attendance:auto-checkout` | Close check-ins left open past the end of the shift, where the organisation enables it | Every five minutes, `withoutOverlapping` |
| `attendance:pattern-scan` | File patterns no single punch shows (two people punching on one phone, arrivals always on the grace edge) in the integrity queue | Daily 16:00, `withoutOverlapping`, idempotent |
| `attendance:push-reminders` | Punch reminders as web push: before the shift, grace run out with no check-in, shift ended while checked in | Every five minutes, `withoutOverlapping`; each reminder at most once per person per day. Off without VAPID keys |
| `attendance:baseline` | Give every organisation a working attendance setup: one with no shift gets its industry's usual schedule, working week and holidays; one that lost its default gets it back | Hourly, `withoutOverlapping`, idempotent. Sign-up does the same for a new organisation (`ProvisionTenant`) |
| `attendance:photo-retention` | Delete attendance selfies whose 48-hour review ended, then announce those past the retention period to HR and department managers | Hourly, `withoutOverlapping`, idempotent. [`ATTENDANCE-PHOTO-RETENTION.md`](ATTENDANCE-PHOTO-RETENTION.md) |
| `attendance:purge-device-challenges` | Delete expired attendance passkey challenges | Daily 00:00, `withoutOverlapping`. Reads and returns nothing |

### People and organisations

| Command | What it does | Notes |
|---|---|---|
| `auth:purge-activation-codes` | Delete expired in-person activation codes | Daily 00:00, `withoutOverlapping`. Sweeps them a month after they expired |
| `invitations:send-reminders` | Queue reminder invitations for people who have not activated | Daily 06:00, `withoutOverlapping`. Governed by `invitations.reminder_after_days` (3), `reminder_window_days` (30) and `max_reminders` (2) |
| `ethr:refresh-tenant-journeys` | Recompute where each organisation is between sign-up and using ETHR. `--tenant=<public_id>`, `--all` | Hourly at :20, `withoutOverlapping`; only snapshots older than fifty minutes. [`TENANT-JOURNEY.md`](TENANT-JOURNEY.md) |
| `tenancy:recheck-custom-domains` | Re-check verified custom domains and report any whose DNS no longer checks out | Mondays 03:00. Writes `platform.tenant.domain_check_failed` to the platform audit log and logs a warning; **revokes nothing**. The symptom row in [`ON-CALL.md`](ON-CALL.md) says what to do |

## Scheduled entries (`api/routes/console.php`)

All times UTC; Ethiopia is UTC+3 year-round. "Per organisation" means the entry loops
`Tenant::operational()` and dispatches one job each. A queue of `—` means the entry runs in the
scheduler's own process rather than dispatching a job.

| When (UTC) | What | Queue |
|---|---|---|
| every minute | scheduler heartbeat (`QueueHealth::beat`) — deliberately **not** `withoutOverlapping` | — |
| every 5 min | `devices:sync` | — |
| every 5 min | `attendance:auto-checkout` | — |
| every 5 min | `attendance:push-reminders` | — |
| hourly :00 | `attendance:baseline` | — |
| hourly :00 | `attendance:photo-retention` | — |
| hourly :00 | `RunScheduledReportsJob` | exports |
| hourly :00 | `RunDashboardDigestsJob` | exports |
| hourly :05 | apply due personnel actions, per organisation | default |
| hourly :20 | `ethr:refresh-tenant-journeys` | — |
| daily 00:00 | `attendance:purge-device-challenges` | — |
| daily 00:00 | `auth:purge-activation-codes` | — |
| daily 00:30 | leave accrual, per organisation | default |
| daily 01:00 | `ethr:backup` — `--off-host` deliberately **not** set | — |
| daily 01:00 | leave carry-forward, per organisation (see below) | default |
| daily 02:00 | `CleanupExpiredDataJob` | default |
| daily 04:00 | `HandleOverdueInvoicesJob` | default |
| daily 05:00 | `NotifyExpiringTrialsJob` | notifications |
| daily 06:00 | `invitations:send-reminders` | — |
| daily 06:00 | approval reminders (requests waiting over 48 h), per organisation | notifications |
| daily 06:05 | expiry reminders (contracts and documents 30, 7 and 1 days before they end; probation 14 and 1 days before), per organisation | notifications |
| daily 15:30 | missing-punch scan, per organisation | attendance |
| daily 15:45 | attendance-anomaly scan, per organisation | attendance |
| daily 16:00 | `attendance:pattern-scan` | — |
| weekly, Mon 03:00 | `tenancy:recheck-custom-domains` | — |
| monthly, 1st 01:30 | roll next year's holidays forward, per organisation | default |
| monthly, 1st 03:00 | `GenerateMonthlyInvoicesJob` | default |

Three of those entries — `GenerateMonthlyInvoicesJob`, `HandleOverdueInvoicesJob` and
`CleanupExpiredDataJob` — carry neither a `->name()` nor `withoutOverlapping()`, unlike every
entry around them. Recorded here as observed, not defended; it is a code question.

### Leave carry-forward is daily, and it is not 1 January

**Corrected 2026-11-13.** This table used to read *"yearly, 1 Jan 01:00"*. The entry runs
**daily at 01:00** (`api/routes/console.php:95-105`), and each organisation decides for itself
whether today is in scope: the job is dispatched only while that organisation is within the
first seven days of **its own leave year**.

That year is a per-organisation setting of its own, `settings.leave_year`, Gregorian or
Ethiopian (`api/app/Services/Leave/LeaveYear.php:55-63`). It is **not** the fiscal-year setting,
so an organisation on the Ethiopian calendar carries forward around Meskerem and a Gregorian one
around 1 January. A daily entry with a seven-day window is also how a missed run catches up,
which a single yearly tick could not.

### The five-minute rule is gone, and catch-up is why

**Corrected 2026-11-13.** This page used to state that an entry scheduled on a minute which is
not a multiple of five *"would never run, because the caller arrives on the five-minute mark"*,
and gave that as the reason the backup sits at 01:00 rather than 01:03.

Both halves have moved:

- The caller does **not** arrive on the five-minute mark. It is written as `*/5` and delivers 4
  to 6 times a day in practice (F23), so a rule built on reliable five-minute ticks was already
  describing something that does not happen.
- The cron endpoint runs **`ethr:schedule-run`**, not `schedule:run`
  (`api/app/Services/Observability/CronRunner.php:32`), and so does the after-response
  middleware. That command replays **every event due since the last run**, so an entry fires
  because its due time has passed, not because a call lands in its minute.

This is what makes the entries at `:05`, `:20`, `:30` and `:45` safe, and they are now the
majority. What a long gap between calls costs is **lateness**, not a skipped run: an entry due
at 06:00 may not run until 09:00. Design against that, not against the minute.

## Draining the queue by hand

```bash
php artisan queue:work --queue=attendance,notifications,default,exports --stop-when-empty
```

A bare `queue:work` reads only `default` and starves the other three (`QueueHealth::QUEUES` is
the list, and the cron endpoint passes all four). Do not set `QUEUE_CONNECTION=sync` to get
around it — that hides every bug only a real worker exposes.

## What actually watches this

Worth stating plainly, because this page and
[`QUEUE-MONITORING.md`](QUEUE-MONITORING.md) both used to imply otherwise:

- **`ethr:queue:check` is scheduled nowhere.** It is absent from `api/routes/console.php` and
  from `.github/workflows/cron.yml`; the only mentions in the repository are comments and these
  documents, and `HealthController` says it "runs from cron". On this host there is no cron and
  no shell, so it is a command to run locally and nothing more.
- **What watches the service is off-host**: the uptime monitor polling `GET /api/v1/health` and
  reading `queue_detail`, and the same account polling `GET /api/v1/cron/backup`, which answers
  503 while `BackupHealth` is critical — a failing backup, or disk above 85%.
- **What an operator opens is `/admin/operations`**, which shows the heartbeat, the last tick of
  each caller, the last drain, queue depths, failed jobs, mail, release and backups.
  [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) reads it field by field.

## First checks when something looks wrong

| Symptom | Look at |
|---|---|
| Nothing asynchronous happens; emails and reports never arrive | `/admin/operations` → *Background work*. A stale heartbeat with a stale external tick means the **caller** stopped: is `cron.yml` on the default branch, and did its last Actions run succeed? |
| Health endpoint degraded | `GET /api/v1/health` reports `database`, `cache`, `queue` and `storage` independently; [`health-check.md`](../deployment/shared-hosting/health-check.md) |
| Backups missing | `ethr:backup` runs via the schedule, so a silent scheduler also means no backup. Then `/admin/operations` → *Backups*, then `storage/app/backups` (or `BACKUP_PATH`) |
| A release misbehaves | [`rollback.md`](../deployment/shared-hosting/rollback.md) |
| Anything else | [`ON-CALL.md`](ON-CALL.md) is the symptom-to-action table |
