# On call — what to do when

**For the Ethio Telecom Plesk deployment.** Written 2026-10-01 from the repository's own records.
Several of the host facts it relies on are **not yet measured on the account** (see
[`../deployment/CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md)); where an
instruction depends on one, it says so.

## Three constraints that shape every answer

1. **There is no shell.** SSH is forbidden on the account. You cannot type `php artisan …` on
   the live host. A one-off Artisan command runs through **Plesk → Git → additional deployment
   actions**, which execute as the subscription user on the next deploy
   ([`GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md), blocker B-4). Enter the command,
   deploy, then **remove it from the field** — a destructive command left there runs again on
   every deploy.
2. **Nothing runs unless something calls it.** There is no worker process and no cron entry on
   the host. The scheduler and the queue run only when GitHub Actions
   (`.github/workflows/cron.yml`) calls `POST /api/v1/cron/schedule` and
   `POST /api/v1/cron/queue`, every five minutes. **The workflow only fires from the
   repository's default branch.** ([`cron-caller.md`](../deployment/shared-hosting/cron-caller.md))
3. **Everything readable from outside is in one place:** `GET https://<domain>/api/v1/health`.
   It reports `database`, `cache`, `queue`, `storage` and `database_read` independently, plus
   `queue_detail` (scheduler heartbeat, queue depths, failed jobs). A 503 means a core service
   is broken; a stale scheduler alone does **not** 503, on purpose.

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
| **`/health` says `database: unhealthy`** | Database server unreachable or credentials changed | Plesk → Databases. The server is a separate host (**`10.180.50.142:3306`**, from the panel; the connection itself is not yet measured — M2). Check `DB_*` in `~/ethr/api/.env` |
| **No emails, reports or attendance scans; nothing asynchronous happens** | The scheduler stopped. `queue_detail` shows a stale heartbeat (threshold 900 s) | Open the *ETHR scheduler* workflow. **DORMANT** means neither secret is set. A failure on one secret means `ETHR_CRON_TOKEN` or `ETHR_CRON_BASE_URL` is missing from the `production` environment. A **404** from the cron endpoints means the GitHub secret and the host's `CRON_TOKEN` disagree: the endpoint deliberately answers a wrong token as if it did not exist, so do not look for a 401. Use *Run workflow* to dispatch it by hand |
| **Jobs pile up but the scheduler is alive** | The queue call is failing or timing out | The queue drain runs under the **web** request limits (`max_execution_time`, `memory_limit`), which are unmeasured on this account (G0-J). Check the workflow's queue step and the day's log |
| **`failed_jobs` grows** | Jobs run and throw | Super-admin dashboard → failed jobs. Read the exception before *retry all*: a payroll job is deliberately single-attempt, and retrying a half-written run can duplicate entries |
| **Disk quota warnings** | The plan is **5 GB** for the database, documents and backups together | Backups are uncompressed; `BACKUP_KEEP` (default 2) caps how many stay. Logs rotate daily. Plesk shows usage. A full quota fails **writes** while reads keep working, so the site looks fine until someone saves |
| **No recent backup** | The nightly `ethr:backup` (01:00 UTC) runs through the scheduler, so a stalled scheduler also means no backup | Fix the scheduler first. Backups land in `storage/app/backups` unless `BACKUP_PATH` says otherwise ([`BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md)) |
| **A release misbehaves** | Code, not data | [`rollback.md`](../deployment/shared-hosting/rollback.md): redeploy the last good commit. Do not roll migrations back |
| **Data is wrong or lost** | Bad import, bad edit, bad job | `ethr:restore <name> --force` through a deployment action. **Needs the `APP_KEY` the backup was taken under** — `Employee.tin` and `national_id` are encrypted and unreadable without it. **The restore has never been rehearsed on this host (M6)**; treat it as untested |
| **`platform.tenant.domain_check_failed` in the platform audit log** (Mondays, from `tenancy:recheck-custom-domains`) | An organisation's verified custom domain lost its TXT token or no longer CNAMEs to ETHR. The payload names which check failed | Nothing was revoked; links still point at the domain. Run the command again by hand to rule out a DNS hiccup. If it still fails, ask the organisation to restore the records. If they have moved away, clear the domain in the console, which sends links back to `ethr.et/{slug}` ([decision](../decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md)) |
| **Realtime notifications do not appear** | Expected: there is no WebSocket server. `BROADCAST_CONNECTION=null` and the client polls | Nothing to do |

## Secrets

- **`CRON_TOKEN`** (host `.env`) and **`ETHR_CRON_TOKEN`** (GitHub, `production` environment)
  hold one value. Rotate both together, or every scheduler call gets a 404.
- **Never rotate `APP_KEY`** on a deployment with data. The encrypted employee columns become
  unreadable, and so does every backup taken under the old key.

## Where the deeper answers are

| Question | Document |
|---|---|
| Every command and the full schedule | [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| Queue and scheduler monitoring in depth | [`QUEUE-MONITORING.md`](QUEUE-MONITORING.md) |
| The health endpoint, field by field | [`health-check.md`](../deployment/shared-hosting/health-check.md) |
| Environment variables on this target | [`ENVIRONMENT.md`](../deployment/shared-hosting/ENVIRONMENT.md) |
| What is and is not verified on the host | [`CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md) |
| Escalating to the host | [`ETHIO-TELECOM-SUPPORT-REQUEST.md`](../deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md) — drafted, not sent |
