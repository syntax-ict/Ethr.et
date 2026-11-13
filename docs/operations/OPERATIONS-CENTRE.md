# Reading the operations centre

**`/admin/operations` in the platform console, live since 2026-10-28.** Written 2026-11-13 from
`api/app/Services/Admin/OperationsStatusService.php`, `api/app/Support/BackupHealth.php` and
`api/app/Support/DiskUsage.php`. A state or reason on the page that disagrees with this document
is a defect in one of the two.

This is the **runbook**: what each area shows, every state and reason it can report, and what to
do. What the page was specified to be, the reasoning behind each design choice, and the eight
panels it was planned to have are in
[`OPERATIONS-CENTRE-SPEC.md`](OPERATIONS-CENTRE-SPEC.md). A design document and a runbook answer
different questions, which is why they are separate files.

**The one question the page answers:** *is ETHR doing its work right now, for every
organisation?* Not *is the site up* — it cannot answer that, because a dead host cannot render
the page that says it is dead. For that, see *What actually reaches a human* at the foot of this
page.

## Who opens it

Every console route sits behind `EnsurePlatformContext`, `RequirePlatformMfa` and
`throttle:platform-admin` (`api/routes/api.php`), so: a platform super admin holding
`admin.manage` who signed in with a second factor, on the platform host. The page is **read-only**
apart from the failed-job actions, and the endpoint behind it is
`GET /api/v1/admin/operations`.

## The four states

| State | Means | Does it alarm? |
|---|---|---|
| `ok` | Last evidence is recent and good | No |
| `unknown` | Nobody has told us yet, or this host cannot read it. A fresh install with no heartbeat, a mailer that sends nothing, an unreadable migration repository | **No, deliberately.** It ranks *below* `warning` |
| `warning` | Something is late or degraded and a person should look today | No page, no 503 |
| `critical` | Something is broken now | For backups and disk, this is what makes `/cron/backup` answer 503 |

**`unknown` is never red, and that is the single most important thing about this page.** Two
panels in this codebase were permanently red for reasons that were not faults — a hardcoded
`minio` disk and a `redis` cache row on a host with neither — and both taught operators to skip
the whole card. The rollup at the top takes the worst state of the six areas, with `unknown`
ranked below `warning` so an absence never raises an alarm.

Every area carries **the time of its last evidence**. "Healthy" with no "as of when" is not an
answer.

---

## Area 1 — Background work

Whether anything is running at all: the scheduler heartbeat, the two off-host callers, the
opportunistic drain, and how long the oldest job on each of the four queues has waited.

| Reason | State | Holds when | What to do |
|---|---|---|---|
| `scheduler_never_run` | unknown | No heartbeat has ever been written | A fresh deployment, or `storage/framework/signals` is not writable. Not a fault on day one |
| `scheduler_stale` | warning at 600 s, critical at 900 s | The heartbeat is older than that | On its own this is **routine on this host** — see below. Read it next to the caller ticks, never alone |
| `external_caller_never_seen` | unknown | No off-host caller has ever reached `/cron/*` | Neither `CRON_TOKEN` nor the callers are configured. [`cron-caller.md`](../deployment/shared-hosting/cron-caller.md) |
| `external_caller_stale` | warning at 1800 s, critical at **7200 s** | No off-host caller for two hours | **This is the real signal.** Open GitHub → Actions → *ETHR scheduler* and the cron-job.org account. A **404** from the endpoints means the host's `CRON_TOKEN` and the caller's copy disagree — the endpoint answers a wrong token as if the route did not exist, so there is no 401 to find |
| `queue_waiting` | warning at 900 s, critical at 1800 s | A queue's oldest unreserved job has waited that long | The drain is failing or timing out under the web request's limits, or only `/cron/schedule` is wired and not `/cron/queue`. The two are independent calls |

### Read the heartbeat and the external tick together

This is the distinction the area exists for, and it is easy to misread.

Ordinary traffic runs the scheduler too (`RunBackgroundWorkAfterResponse`). So with people
signed in, **the heartbeat can stay perfectly fresh while the off-host timer is dead** — and
nothing but that timer runs the night's backup at 01:00, when nobody is signed in.

| Heartbeat | External tick | Reading |
|---|---|---|
| fresh | fresh | Healthy |
| stale | fresh | A gap between calls. Expected: the caller delivers 4 to 6 times a day in practice, so 900 s is crossed routinely. Work is **late, not lost** — `ethr:schedule-run` catches up everything due |
| fresh | **stale** | **Both callers are down and only page traffic is keeping things moving.** The quiet-hours work — backup, invoices, accrual — will not run. Act today |
| stale | stale | Nothing is running. Start with the callers, then `CRON_TOKEN` |

Why the thresholds are not tightened to match reality: a field that is amber every day is a
field nobody reads. 900 s agrees with `QueueHealth::snapshot()` and with
`ethr:queue:check`'s default, so the three cannot drift apart; the two-hour external threshold is
the one tuned to this host.

---

## Area 2 — Failed jobs

Jobs that **ran and threw**, which is a different event from a job that never ran. The page
shows both and never adds them together.

| Reason | State | Holds when | What to do |
|---|---|---|---|
| `failed_jobs_present` | warning | `failed_jobs` has at least one row | Read the exception **before** *retry all* |
| `failed_jobs_unreadable` | unknown | The table could not be read | A database problem; *Services* will usually be red too |

**Read before retrying.** `ProcessPayrollJob` is deliberately `$tries = 1`: a failed run must be
inspected and re-submitted by a person, because retrying a half-written run can append a second
set of entries to a run that already has them. The same caution applies to anything that writes
money.

Retry, retry-all and dismiss are the only actions on this page.

---

## Area 3 — Delivery

Email: whether the last attempt succeeded, the TLS state of the connection, and the day's volume
against the mailbox's send cap.

| Reason | State | Holds when | What to do |
|---|---|---|---|
| `mail_failing` | **critical** | The last failure is newer than the last success | `ethr:mail-probe` (as the `mail-probe` maintenance task) shows the certificate chain. A failed reset, activation or MFA-reset email is retried from the queue for about two hours (`RetryCriticalMail`), so there is a window to fix it |
| `mail_no_record` | unknown | Nothing has been sent or failed yet | Normal on a fresh deployment |
| `mail_not_sent_by_this_mailer` | unknown | `MAIL_MAILER` is `log` or `array` | Correct in development. **On production this means no mail is leaving at all** — check which environment you are reading |
| `mail_cap_near` | warning | The day's sends reached **80%** of `mail.daily_cap` | Essential mail still goes. Expect bulk mail to be held shortly |
| `mail_held` | warning | Bulk mail was held today (`MailBudget`) | **Amber on purpose:** the in-app notification went, only the email did not, and essential mail — resets, activation, MFA — is never held. If this is daily, the cap is too low for the organisation count |

The TLS field reads `verified` or `pinned`. Pinned means the provider's certificate is expired
and `MAIL_PEER_FINGERPRINT` is carrying delivery; when the provider renews, it returns to
`verified` by itself and the log says the pin can be removed.

---

## Area 4 — Release and configuration

Whether the deployed release finished its release steps. Every check here is a step that has
been missed on this host before. Booleans and limits only — never a secret's value or length.

| Reason | State | Holds when | What to do |
|---|---|---|---|
| `pending_migrations` | **critical** | Migrations are waiting | **Run `migrate` now.** Code that reads a column its migration has not created returns 500. Deploy and migrate are one step |
| `maintenance_routes_open` | **critical** | `MAINTENANCE_TOKEN` is still set | Empty it. It is set for a release and emptied after, every time |
| `config_cache_stale` | **critical** | The config cache is older than the release's `built_at` | Run `optimize`. A stale config cache serves the previous release's settings |
| `route_cache_stale` | **critical** | The route cache is older than `built_at` | Run `optimize`. **This is the 404-after-deploy trap**: new routes exist in code and not in the cache |
| `debug_on` | **critical** | `APP_DEBUG` is true in production | Turn it off. It puts stack traces and configuration in front of users |
| `queue_sync` | **critical** | `QUEUE_CONNECTION=sync` in production | Change it. Every queued job would run inside the web request that dispatched it |
| `config_not_cached`, `routes_not_cached` | warning | Production with no cache at all | Run `optimize`. Slower, not broken |
| `cron_token_missing` | warning | `CRON_TOKEN` is unset in production | No off-host caller can work; the endpoints 404 |
| `no_release_stamp`, `migrations_unreadable` | unknown | `release.json` is absent, or the migration repository could not be read | A build that did not stamp itself, or a database problem |

The release steps themselves are in
[`PLESK-GO-LIVE.md`](../deployment/PLESK-GO-LIVE.md). All of them run through the token-guarded
maintenance endpoint, which is registered in `api/bootstrap/app.php` rather than
`api/routes/api.php` — deliberately outside the api group, because the throttle's cache table
does not exist until `migrate` has run.

---

## Area 5 — Services

Database, cache and storage round trips, plus the read replica.

| Reason | State | What to do |
|---|---|---|
| `service_unhealthy` | **critical** | Which service is named in the area's own rows. Then `GET /api/v1/health`, which reports each independently, and the day's `laravel-YYYY-MM-DD.log` through Plesk File Manager |

The reason is a **fixed code and the driver's message is only logged**, never returned: a
connection error can name the host and the database, and this page is admin-only rather than
secret. `SystemHealthService` used to return `$e->getMessage()` straight out.

---

## Area 6 — Backups and disk

Added 2026-11-01. Read from the backup manifests and the health signals rather than a ledger, so
it records failures and recency but not size or duration.

| Reason | State | Holds when | What to do |
|---|---|---|---|
| `backup_never_run` | critical in production, else unknown | No backup has ever been recorded | The nightly run is scheduled at 01:00 and needs a caller. Fix *Background work* first |
| `backup_stale` | **critical** | The last backup is older than **26 hours** | Same: a silent scheduler means no backup. 26 hours rather than 24 so a late caller is not a false alarm |
| `backup_failing` | **critical** | The last recorded outcome for the backup stage is a failure | Read the day's log. A disk above 95% of quota refuses the backup outright, so check the disk rows below first |
| `off_host_not_configured`, `off_host_disk_missing`, `off_host_switched_off`, `off_host_key_missing` | warning in production, else unknown | No off-host destination, or it is switched off, or there is no archive key | **A warning on purpose, not an alarm**: the backups themselves are fine, and choosing a destination is an owner decision rather than a fault. This is the state today. Set it in the console at `/admin/backups` |
| `off_host_copy_missing` | warning | Configured, and the next nightly run has not copied yet | Wait one night, then look again |
| `off_host_stale` | **critical** | The last off-host copy is older than 26 hours | The destination is configured and the copy has stopped. Check credentials and the bucket |
| `off_host_failing` | **critical** | The last off-host attempt failed | As above |
| `disk_high` | warning | Measured use is at **70%** of `DISK_QUOTA_MB` | Look at the breakdown. Backups are usually most of it; `BACKUP_KEEP` caps how many stay |
| `disk_critical` | **critical** | **85%** | Act today. **Above 95% the nightly backup refuses to run**, so a full disk costs the backup as well. A full quota fails **writes** while reads keep working, so the site looks fine until someone saves |

Disk is measured by the nightly backup, not continuously, so its figure is as old as the last
run. `DISK_QUOTA_MB` **defaults to 5120** and production sets **51200** (Linux Gold, 50 GB); a
deployment that has not set it turns amber at 3.5 GB of a 50 GB plan.

---

## What actually reaches a human

The page is where an operator looks. It is not what tells them to look. Almost nothing here
notifies anybody, and assuming otherwise is the way an incident goes unnoticed.

**Pushed to the platform owner or the super admins:**

- invoicing problems — `GenerateMonthlyInvoicesJob` and `HandleOverdueInvoicesJob` send
  `SystemAlertNotification`;
- a support request an organisation passed to ETHR (`SupportDesk`);
- contact-form leads, to `mail.contact_inbox`;
- a tenant backup that an operator requested, to that operator.

**Shown here and sent to nobody:** the disk-use watch, the backup failure reasons, the mail
budget, and the Monday custom-domain recheck, which writes
`platform.tenant.domain_check_failed` to the platform audit log and a log line and tells no one.

**The only outward alarm is off-host.** `GET /api/v1/cron/backup` answers **503** while this
area is critical — a failing or stale backup, or disk above 85% — and the cron-job.org account
polls it and emails after one failure. The same account polls `GET /api/v1/health`. Those two
endpoints are the whole notification system, and they are the only part of it that still works
when the host is down.

**Do not read a tenant alert as an operator alert.** Device offline and sync failures, payroll
run failures, scheduled-report and digest failures, sign-in lockouts and photo-deletion notices
all go to the organisation's own admins. They never reach ETHR.

---

## Related

| | |
|---|---|
| Where to start for any situation | [`README.md`](README.md) |
| Symptom to action | [`ON-CALL.md`](ON-CALL.md) |
| The page's design, principles and remaining gaps | [`OPERATIONS-CENTRE-SPEC.md`](OPERATIONS-CENTRE-SPEC.md) |
| Why staleness does not 503, and how the callers work | [`QUEUE-MONITORING.md`](QUEUE-MONITORING.md) |
| Every command and the full schedule | [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| The other console pages | [`PLATFORM-CONSOLE.md`](PLATFORM-CONSOLE.md) |
| Backups, off-host copies and restore | [`BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md) |
| Per-organisation health, as opposed to platform health | [`TENANT-JOURNEY.md`](TENANT-JOURNEY.md) |
