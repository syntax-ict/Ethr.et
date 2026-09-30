# Artisan commands and the schedule

**Derived from `api/app/Console/Commands/*` and `api/routes/console.php` on 2026-09-30.** When this
disagrees with the code, the code is right — `php artisan list ethr` and `php artisan schedule:list`
print the live answer.

Nothing runs a daemon on the production host. Every scheduled entry below fires only when the
external caller (`.github/workflows/cron.yml`, every five minutes) hits `POST /api/v1/cron/schedule`,
and every queued job runs only when it hits `POST /api/v1/cron/queue`. Both sit behind
`throttle:cron` and `VerifyCronToken` (`api/routes/api.php`). Setup and the caveat that the workflow
must be on the repository's **default branch** to fire: [`../deployment/shared-hosting/cron-caller.md`](../deployment/shared-hosting/cron-caller.md).

## Commands

| Command | What it does | Notes |
|---|---|---|
| `ethr:create-admin` | Create, or with `--force` update, a platform super admin. `--email=`, `--password=` (min 12 chars, prompted if omitted) | **The production way to get a super admin.** It never reads `SUPER_ADMIN_PASSWORD`; that belongs to the dev seeder only |
| `ethr:backup` | Back up the database and employee documents. `--label=`, `--keep=`, `--off-host`, `--disk=` | `--keep` defaults to `config('backup.keep')` (`BACKUP_KEEP`); `--disk` to `config('backup.off_host_disk')` (`BACKUP_OFF_HOST_DISK`). Scheduled daily 01:00 |
| `ethr:restore {name}` | Restore database and documents from a backup directory name or absolute path. `--force` skips the prompt | Destructive. Needs the `APP_KEY` the backup was taken under — `Employee.tin`/`national_id` are encrypted. [`../deployment/BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md) |
| `ethr:backup:rehearse` | Back up, drop everything, restore, verify. `--force`, `--keep` | **Destroys the database.** Refuses unless the environment and database name read as disposable, unless `--force` |
| `ethr:queue:check` | Exit 1 if the scheduler has gone silent or a queue is starving. `--json`, `--scheduler-stale=900`, `--oldest-job=1800` | The only signal that the off-host caller has stopped. [`QUEUE-MONITORING.md`](QUEUE-MONITORING.md) |
| `health:check` | Are the database and cache reachable | Command-line twin of `GET /api/v1/health` |
| `devices:sync` | Pull attendance events from auto-sync devices. `--tenant=<public_id>` | Scheduled every five minutes, `withoutOverlapping` |

## Scheduled entries (`routes/console.php`)

| When (UTC) | What | Queue |
|---|---|---|
| every invocation | scheduler heartbeat (`QueueHealth::beat`) — deliberately **not** `withoutOverlapping` | — |
| every 5 min | `devices:sync` | — |
| daily 01:00 | `ethr:backup` | — |
| daily 02:00 | `CleanupExpiredDataJob` | default |
| daily 04:00 | `HandleOverdueInvoicesJob` | default |
| daily 05:00 | `NotifyExpiringTrialsJob` | notifications |
| daily 06:00 | `SendApprovalRemindersJob` (requests waiting over 48 h), per active tenant | notifications |
| daily 15:30 | missing-punch scan, per active tenant | attendance |
| daily 15:45 | attendance-anomaly scan, per active tenant | attendance |
| monthly, 1st 00:30 | leave accrual, per active tenant | default |
| monthly, 1st 03:00 | `GenerateMonthlyInvoicesJob` | default |
| yearly, 1 Jan 01:00 | leave carry-forward, per active tenant | default |
| hourly | `RunScheduledReportsJob`, `RunDashboardDigestsJob` — both `withoutOverlapping` | exports |

**An entry scheduled at a minute that is not a multiple of five would never run**, because the caller
arrives on the five-minute mark. That is why the backup is at 01:00 and not 01:03.

## Draining the queue by hand

```bash
php artisan queue:work --queue=attendance,notifications,default,exports --stop-when-empty
```

A bare `queue:work` reads only `default` and starves the other three (`QueueHealth::QUEUES` is the
list). Do not set `QUEUE_CONNECTION=sync` to get around it — that hides every bug that only a real
worker exposes.

## First checks when something looks wrong

| Symptom | Look at |
|---|---|
| Nothing asynchronous happens; emails and reports never arrive | `php artisan ethr:queue:check`. A stale scheduler means the **caller** stopped: is `cron.yml` on the default branch, and does its last Actions run show success? |
| Health endpoint degraded | `GET /api/v1/health` reports `database`, `cache`, `queue`, `storage` independently; [`../deployment/shared-hosting/health-check.md`](../deployment/shared-hosting/health-check.md) |
| Backups missing | `ethr-backup` runs via the scheduler, so a silent scheduler also means no backup. Then `storage/app/backups` (or `BACKUP_PATH`) |
| A release misbehaves | [`../deployment/shared-hosting/rollback.md`](../deployment/shared-hosting/rollback.md) |
