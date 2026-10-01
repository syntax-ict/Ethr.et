# ETHR — VPS vs Shared-Hosting Component Matrix

**Phase 0 forensic audit, read-only. Measured 2026-09-25 against `6d9fb23`.**

> **2026-10-01:** dated, and left as measured. Every Docker, nginx and VPS path it cites has
> since been removed — the production set in `3db9904` (2026-09-26/27), the development set
> (`docker-compose*.yml`, `docker/`, `pest-isolated.sh`, `phpstan-isolated.sh`) on 2026-09-30.
> See [`../deployment/VPS-DECOMMISSION.md`](../deployment/VPS-DECOMMISSION.md).

Classification rule applied throughout, and the reason this document exists: **a filename
containing "docker", "redis" or "nginx" is not evidence of a production dependency.** Each
row below was classified by asking *what stops working if this is absent at runtime*, then
reading the code that would break.

Four classes are used:

- **A — production runtime dependency:** the deployed application does not function without it.
- **B — local development / build tooling:** needed before or beside deployment, never during.
- **C — optional infrastructure:** selectable, with a working alternative already shipped.
- **D — documentation / example only:** referenced in prose or an example file; no executable path.

---

## The matrix

| Component | Actual Usage | VPS Only | Shared Hosting | Both | Unknown | Evidence |
|---|---|---:|---:|---:|---:|---|
| **PHP 8.2+ handler** | **A** — serves all 361 `Route::` registrations | | | ✅ | | `composer.lock` `platform: php ^8.2`; host panel-read **8.3.33** (2026-09-17) |
| **`ext-gd`** | **A** — image compression, EXIF strip, thumbnails | | | ✅ | | `composer.lock` platform; `FileStorageService::compressImage`, `stripExif` |
| **`ext-pdo_mysql`** | **A** — the only DB driver declared mandatory | | | ✅ | | `composer.lock` platform |
| **MySQL / MariaDB** | **A** | | | ✅ | | `api/.env.example:30` `DB_CONNECTION=mariadb`; `phpunit.mysql.xml`; `gates.sh mysql` runs in CI on every push |
| **`CREATE TRIGGER` privilege** | **A** — `migrate` **aborts** without it | | | ✅ | ⚠️ | `2026_07_22_000001_restrict_audit_log_to_insert_only.php` rethrows on MySQL/MariaDB. Privilege **NOT VERIFIED** on the account — `GATE-0-RESULT.md` G0-F, probe `DB4` |
| **Queue runner** (`queue:work`) | **A** — all 16 `app/Jobs` classes are `ShouldQueue` | | | ✅ | | `infrastructure/supervisor.conf` `[program:ethr-queue]` on VPS; `CronRunController::queue` on shared hosting |
| **Scheduler tick** (`schedule:run`) | **A** — 14 `Schedule::` entries | | | ✅ | | `routes/console.php`; `supervisor.conf` `[program:ethr-scheduler]` on VPS; `CronRunController::schedule` on shared hosting |
| **Supervisor** | **C** — supervises `queue:work`, `schedule:run`, `reverb:start`. Each has a non-Supervisor equivalent | ✅ | | | | `infrastructure/supervisor.conf`; the three programs it defines all exist as artisan commands invocable another way |
| **`cron` / Plesk Scheduled Task** | **A** on shared hosting — nothing else starts `schedule:run` | | ✅ | | ⚠️ | `docs/deployment/shared-hosting/cron.txt`, `cron-caller.md`. **G0-D = FAIL** (strong evidence): no Scheduled Tasks section exists on the subscription, owner-read 2026-09-18 |
| **HTTP cron endpoints** | **A** on shared hosting, given G0-D | | ✅ | | | `CronRunController`; `VerifyCronToken`; `config/cron.php`; `routes/api.php:130`–`146` |
| **Node.js — build** | **B** | | | ✅ | | `.nvmrc` = **24**; `src/package.json` `build: next build`; `.github/workflows` read `node-version-file` |
| **Node.js — runtime** | **A as currently built** | | | ✅ | ⚠️ | `src/next.config.ts:24` `output: "standalone"`; `docker/frontend/Dockerfile` `CMD ["node","server.js"]`. Panel offers a startable Node app at **22.23.2** (G0-G, 2026-09-22) — below the pinned 24 |
| **Next.js middleware** | **A as currently built** — hostname routing, locale negotiation | | | ✅ | | `src/src/middleware.ts`. Middleware **cannot run under a static export** |
| **Next.js rewrites (`/api`, `/sanctum`)** | **A as currently built** — the browser's `baseURL` is relative | | | ✅ | | `src/next.config.ts:88`–`100`; `src/src/api/client.ts:14` `baseURL: "/api/v1"` |
| **Nginx** | **C** on VPS; the shared-hosting equivalent already exists as Apache rules | ✅ | | | | `infrastructure/nginx.conf`, `docker/nginx/default.conf` for VPS; `docs/deployment/shared-hosting/.htaccess` and `nginx-directives.conf` for Plesk |
| **Apache `.htaccess` (mod_rewrite, mod_headers)** | **A** on shared hosting | | ✅ | | ⚠️ | `docs/deployment/shared-hosting/.htaccess`; `api/public/.htaccess`. **G0-B.1/B.2/B.3/B.4 all NOT VERIFIED** |
| **Reverse proxy for `/api/`** | **A** for the same-origin design | | | ✅ | ⚠️ | **G0-A: neither directive textarea present on the settings page (2026-09-17) — strong evidence of FAIL, unconfirmed.** Next.js rewrites are the in-repo substitute |
| **Docker** | **B** for local development; **C** for VPS production | ✅ | | | | 6 `docker-compose*.yml`; `docker/`; `api/Dockerfile.prod`. **No `app/` code references Docker.** `pest-isolated.sh` / `phpstan-isolated.sh` are the only gate paths that need it, and both are now native-first |
| **Redis** | **C** for cache/queue/session; **D** at application level | ✅ | | | | **No `Redis::` or Redis facade call anywhere in `app/`, `routes/`, `bootstrap/`.** No `predis/predis` and no `ext-redis` in `composer.lock`. Selected only by `docker-compose.yml:35`–`37` and provided only by `pecl install redis` in `docker/php/Dockerfile:25`. Application code comments already state it was **removed for the shared-hosting target** — `PlatformSetting.php:66`, `DashboardCacheVersion.php:15`, `SiteContentController.php:32` |
| **Horizon** | **D — removed from the project** | | | | | Absent from `composer.json` and `composer.lock`; **no `api/config/horizon.php`**. Removed in `cdf85d1`; `infrastructure/supervisor.conf`, `docker-compose.yml:75`, `docker-compose.prod.yml:2` and `docker-compose.lowmem.yml:2` all carry headers recording that they invoked a command the application does not define |
| **Reverb / WebSockets** | **C** — off by default | ✅ | | | | `laravel/reverb v1.10.2` installed; `config/broadcasting.php:40` defaults to **`null`**; `api/.env.shared-hosting.example` sets `BROADCAST_CONNECTION=null`. Requires a long-running `reverb:start` daemon, so no shared-hosting path exists. **3** `ShouldBroadcast` events degrade to silence — *corrected 2026-09-25 from 5; `grep -rl` matched comment text, including `PayrollRunFailed`'s note that "broadcasting is deployed on neither target"* — and **none of the three has a frontend subscriber** |
| **MinIO** | **C** | ✅ | | | | `config/filesystems.php` `minio` disk; `FileStorageService.php:64` resolves `config('filesystems.default')`, so the backend is a pure config switch. `local` is the shipped default |
| **S3 / off-host object storage** | **C** — wired, not configured | | | ✅ | | `league/flysystem-aws-s3-v3` installed; `config/backup.php:48` `BACKUP_OFF_HOST_DISK`. The scheduled backup **does not pass `--off-host`** |
| **MariaDB read replica** | **C** — inert unless `DB_READ_HOST` is set | ✅ | | | | `config/database.php:70`–`79`; `docker-compose.prod.yml` `mariadb-replica`; `scripts/setup-replication.sh`. `api/.env.shared-hosting.example` leaves `DB_READ_USERNAME` empty by instruction |
| **`certbot` / ACME webroot** | **C** on VPS; Plesk issues its own certificates | ✅ | | | | `infrastructure/certbot-webroot/README.md`; `.htaccess` carries an `acme-challenge` passthrough rule, so the shared-hosting path is already accommodated |
| **`systemd` / `systemctl` / `sudo` / root** | **D** | | | | | No unit files, no `systemctl` invocation, and no `exec`/`shell_exec`/`proc_open` in `app/`. Referenced only in VPS deployment prose |
| **`docker compose exec` backup scripts** | **B/VPS only** | ✅ | | | | `scripts/backup.sh:53`, `:63`; `restore.sh`, `rollback.sh`, `deploy.sh`, `prod-build-test.sh` |
| **`ethr:backup` / `ethr:restore`** | **A** — the host-neutral backup path | | | ✅ | | Artisan commands; scheduled at 01:00 in `routes/console.php`; rehearsed on MariaDB in CI since 2026-09-23 |
| **SMTP** | **A** — password reset is dead without it | | | ✅ | ⚠️ | `api/.env.shared-hosting.example` marks `MAIL_HOST`/`USERNAME`/`PASSWORD` **REQUIRED**. **G0-H: outbound 587/465 NOT VERIFIED**; mailbox send cap unknown. Bronze provides 5 mailboxes |
| **Sentry** | **C** | | | ✅ | | `sentry/sentry-laravel`; `@sentry/nextjs`. No-ops without a DSN — `bootstrap/app.php` comment states this |
| **Wildcard subdomain vhost** | **A** — the tenant selector | | | ✅ | ⚠️ | `ResolveTenant::resolveFromSubdomain()` is *"the only selector honoured in production"*. **G0-C PARTIAL**: wildcard DNS PASS; wildcard TLS blocked, needs DNS-01. Bronze publishes **5 subdomains**, which is not a wildcard |
| ~~**`symlink()` for `public/storage`**~~ | **D — not used** | | | ✅ | | **Corrected 2026-09-27.** This read *"**A** where `FILESYSTEM_DISK=local` … `storage:link` must run on the host"*. It does not: ETHR serves every file through a signed `temporaryUrl()` against a disk with `'serve' => true` (`FileStorageService`), so nothing is ever read from `public/storage` and the symlink would be broken by construction. `shared-hosting/DEPLOYMENT.md:308` states *"No `storage:link` step"*. Probe `ST5` is informational, not a dependency |
| **`RUN_ALL.ps1` and siblings** | **D — stale dev launchers** | | | | | Predate Docker; `RUN_ALL.ps1` prints "SQLite" against a MariaDB stack and starts neither worker nor Reverb |

---

## What the matrix settles

**VPS-only, with a shipped shared-hosting substitute:** Supervisor, Nginx, Docker,
certbot, the Docker backup scripts. Every one of these supervises or wraps something that
also exists as a plain artisan command or an Apache rule already in the repository.

**VPS-only, with no substitute and no application dependency:** Redis, MinIO, the MariaDB
read replica. These are selectable infrastructure. Removing them from the *deployment* is
a configuration change, not a code change — `FileStorageService.php:64` and the
`database`-backed cache/queue/session defaults were built for exactly this.

**VPS-only, with no substitute and a real feature behind it:** Reverb. There is no
shared-hosting way to run a WebSocket daemon. See
[`BRONZE-COMPATIBILITY-MATRIX.md`](BRONZE-COMPATIBILITY-MATRIX.md) for the five events and
what silence costs.

**Removed already, and only documentation still claims otherwise:** Horizon. See
[`CODE-VS-DOCUMENTATION.md`](CODE-VS-DOCUMENTATION.md).

**The genuinely unknown rows are host capabilities, not code questions**, and every one of
them is already tracked in `docs/deployment/GATE-0-RESULT.md`. This audit adds no new
unknowns of its own.

---

## Related

- [`REPOSITORY-INVENTORY.md`](REPOSITORY-INVENTORY.md)
- [`BRONZE-COMPATIBILITY-MATRIX.md`](BRONZE-COMPATIBILITY-MATRIX.md)
- [`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md) — the authoritative host-capability register
- [`../deployment/SHARED-HOSTING-CONTRACT.md`](../deployment/SHARED-HOSTING-CONTRACT.md) — what ETHR may depend on, set by the owner
