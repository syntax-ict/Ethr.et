# On call — what to do when

**For the Ethio Telecom Plesk deployment.** Written 2026-10-01 from the repository's own records;
corrected 2026-10-14 (how artisan runs, background work, mail, custody of credentials).
Several of the host facts it relies on are **not yet measured on the account** (see
[`../deployment/CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md)); where an
instruction depends on one, it says so.

## Three constraints that shape every answer

1. **There is no shell, and the deployment action has no PHP.** SSH is forbidden, and Plesk
   Git's deployment action runs in a chrooted shell without PHP, so artisan cannot run there.
   *(Corrected 2026-10-14: this said to put commands in "additional deployment actions"; the
   first host deploy showed they cannot run.)* The fixed release tasks — `migrate`, `seed`,
   `optimize`, `clear`, the read-only `mail-probe`, and `backups`, `backup` and `restore` —
   run through the token-guarded
   **maintenance endpoint**: set `MAINTENANCE_TOKEN` in the host `.env`, call
   `POST /api/v1/maintenance/{task}`, then empty the token
   ([`PLESK-GO-LIVE.md`](../deployment/PLESK-GO-LIVE.md),
   [decision](../decisions/OWNER-DECISION-MAINTENANCE-ENDPOINT.md)). Anything outside those
   tasks has no route on the host.
2. **Background work runs when something reaches Laravel.** There is no worker process and no
   cron entry on the host. Three things drive the scheduler and the queue: GitHub Actions
   (`.github/workflows/cron.yml`, every five minutes on paper, 4–6 times a day in practice —
   F23; it only fires from the default branch), a second URL-fetch caller (see
   [`CUSTODY.md`](CUSTODY.md)), and — since 2026-10-14 — ordinary traffic: after a response is
   sent, at most once a minute, a request drains the queue for a few seconds and catches the
   scheduler up ([`cron-caller.md`](../deployment/shared-hosting/cron-caller.md) §5a).
3. **Everything readable from outside is in one place:** `GET https://<domain>/api/v1/health`.
   It reports `database`, `cache`, `queue`, `storage` and `database_read` independently, plus
   `queue_detail` (scheduler heartbeat, `last_drain_at`, queue depths, failed jobs) and
   `mail_detail` (last sent, last failed, `verified` or `pinned` TLS). A 503 means a core
   service is broken; a stale scheduler alone does **not** 503, on purpose.

## First five minutes

| Look at | What it tells you |
|---|---|
| `GET /api/v1/health` | Which service is broken (`unhealthy` or `unavailable`), and whether the scheduler is alive (`queue_detail`) |
| GitHub → Actions → *ETHR scheduler* | Whether the caller is running, and the status of its last runs |
| Plesk → File Manager → `~/ethr/api/storage/logs/laravel-YYYY-MM-DD.log` | The actual exception behind a 500. One file per day (the `daily` channel), kept 14 days |
| Super-admin dashboard → failed jobs | Jobs that ran and failed, with retry and dismiss (`/api/v1/admin/failed-jobs`) |

## Symptom → cause → action

| Symptom | Likely cause | Action |
|---|---|---|
| **Site returns 500 everywhere** | A boot failure: wrong `.env` path, missing `APP_KEY`, an unreachable database | Read today's `laravel-YYYY-MM-DD.log`. `DEPLOYMENT.md` lists the three path mistakes that cause most 500s ([`shared-hosting/DEPLOYMENT.md`](../deployment/shared-hosting/DEPLOYMENT.md)) |
| **`/health` says `database: unhealthy`** | Database server unreachable or credentials changed | Plesk → Databases. The server is a separate host (**`<DB_HOST>:3306`**, from the panel; the connection itself is not yet measured — M2). Check `DB_*` in `~/ethr/api/.env` |
| **No emails, reports or attendance scans; nothing asynchronous happens** | The scheduler stopped and no traffic is draining the queue. `queue_detail` shows a stale heartbeat (threshold 900 s) and an old `last_drain_at` | Open the *ETHR scheduler* workflow. **DORMANT** means neither secret is set. A failure on one secret means `ETHR_CRON_TOKEN` or `ETHR_CRON_BASE_URL` is missing from the `production` environment. A **404** from the cron endpoints means the GitHub secret and the host's `CRON_TOKEN` disagree: the endpoint deliberately answers a wrong token as if it did not exist, so do not look for a 401. Use *Run workflow* to dispatch it by hand |
| **Jobs pile up but the scheduler is alive** | The queue call is failing or timing out | The queue drain runs under the **web** request limits (`max_execution_time`, `memory_limit`), which are unmeasured on this account (G0-J). Check the workflow's queue step and the day's log |
| **`failed_jobs` grows** | Jobs run and throw | Super-admin dashboard → failed jobs. Read the exception before *retry all*: a payroll job is deliberately single-attempt, and retrying a half-written run can duplicate entries |
| **Disk quota warnings** | The plan is **5 GB** for the database, documents and backups together | Backups are uncompressed; `BACKUP_KEEP` (default 2) caps how many stay. Logs rotate daily. Plesk shows usage. A full quota fails **writes** while reads keep working, so the site looks fine until someone saves |
| **No recent backup** | The nightly `ethr:backup` (01:00 UTC) runs through the scheduler, so a stalled scheduler also means no backup | Fix the scheduler first. Backups land in `storage/app/backups` unless `BACKUP_PATH` says otherwise ([`BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md)) |
| **A release misbehaves** | Code, not data | [`rollback.md`](../deployment/shared-hosting/rollback.md): redeploy the last good commit. Do not roll migrations back |
| **Data is wrong or lost** | Bad import, bad edit, bad job | The maintenance endpoint: `backups` to list them, then `restore` with `{"name": …, "confirm": …}`, the same name twice. It takes a `pre-restore` backup first, so a wrong choice can be undone, and checks that `audit_log` is still append-only afterwards ([`BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md), *On the Ethio Telecom target: the maintenance endpoint*). **Do not import a dump through phpMyAdmin or Plesk's backup tool**: under a different database user the `audit_log` triggers' definer goes missing and every save in the product then returns 500 (*The DEFINER problem*). If the backup you need is not on the host, escalate to whoever holds the off-host copy (see [`CUSTODY.md`](CUSTODY.md)). **Needs the `APP_KEY` the backup was taken under** — `Employee.tin` and `national_id` are encrypted and unreadable without it. **Never rehearsed on this host (M6)**; treat it as untested and rehearse it before you need it |
| **Sign-in emails do not arrive** | The SMTP server refused the connection or the certificate | `/health` → `mail_detail`. A failed reset, activation or MFA-reset email is retried from the queue for about two hours (`RetryCriticalMail`). Run the `mail-probe` maintenance task to see the certificate. When the provider renews its expired certificate, mail switches to `verified` by itself and the log says the pin can be removed |
| **`platform.tenant.domain_check_failed` in the platform audit log** (Mondays, from `tenancy:recheck-custom-domains`) | An organisation's verified custom domain lost its TXT token or no longer CNAMEs to ETHR. The payload names which check failed | Nothing was revoked; links still point at the domain. Run the command again by hand to rule out a DNS hiccup. If it still fails, ask the organisation to restore the records. If they have moved away, clear the domain in the console, which sends links back to `ethr.et/{slug}` ([decision](../decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md)) |
| **Realtime notifications do not appear** | Expected: there is no WebSocket server. `BROADCAST_CONNECTION=null` and the client polls | Nothing to do |

## Secrets

Who holds which credential is in [`CUSTODY.md`](CUSTODY.md) — names and places, never values.

- **`CRON_TOKEN`** (host `.env`), **`ETHR_CRON_TOKEN`** (GitHub, `production` environment) and
  the second caller's copy hold one value. Rotate all three together, or scheduler calls get a
  404.
- **Never rotate `APP_KEY`** on a deployment with data. The encrypted employee columns become
  unreadable, and so does every backup taken under the old key.

## Where the deeper answers are

| Question | Document |
|---|---|
| Personal data may have been exposed | [`DATA-BREACH-RESPONSE.md`](DATA-BREACH-RESPONSE.md): the first hour, the 72-hour clock, who notifies whom |
| Every command and the full schedule | [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| Queue and scheduler monitoring in depth | [`QUEUE-MONITORING.md`](QUEUE-MONITORING.md) |
| The health endpoint, field by field | [`health-check.md`](../deployment/shared-hosting/health-check.md) |
| Environment variables on this target | [`ENVIRONMENT.md`](../deployment/shared-hosting/ENVIRONMENT.md) |
| What is and is not verified on the host | [`CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md) |
| Escalating to the host | [`ETHIO-TELECOM-SUPPORT-REQUEST.md`](../deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md) — drafted, not sent |
