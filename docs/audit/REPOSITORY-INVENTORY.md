# ETHR — Repository Inventory

**Phase 0 forensic audit, read-only. Measured 2026-09-25 against `6d9fb23` on `migration/bronze-plesk`.**

Every row below was read from source, a manifest, a lockfile or a config file in this
working tree. Where a figure disagrees with an existing document, the disagreement is
recorded in [`CODE-VS-DOCUMENTATION.md`](CODE-VS-DOCUMENTATION.md) rather than silently
resolved here.

This document does **not** restate [`BASELINE.md`](BASELINE.md), which already carries
§1–§18 of measured state and is the deeper reference. It exists to answer one question
BASELINE does not answer in one place: *what is in this repository, and which part of it
is production runtime versus build tooling versus optional infrastructure.*

---

## 1. Actual architecture

**A two-application monorepo with inverted directory names.** There is no shared build,
no workspace file, no package manager linking the two halves.

| Path | What it actually is |
|---|---|
| `api/` | The **entire Laravel 12 backend**. Not a thin API layer — `app/`, `config/`, `database/`, `routes/`, `tests/`, its own `composer.json` and its own `package.json` |
| `src/` | The **Next.js project root** (`package.json`, `next.config.ts`, `tsconfig.json`, `playwright.config.ts`, `vitest.config.ts`, `e2e/`) |
| `src/src/` | The **actual frontend source** — `app/`, `components/`, `features/`, `lib/`, `api/`, `middleware.ts`, `test/` |

The two communicate over HTTP only. Coupling is one generated file —
`src/src/api/generated.ts`, produced from the backend's OpenAPI document by
`src/scripts/generate-api.mjs`.

**Deployment shape:** the backend is a PHP application served by a web server; the
frontend is a Node process (`output: "standalone"` then `node server.js`). They are
**same-origin by construction at the browser**: `src/src/api/client.ts:14` sets
`baseURL: "/api/v1"` — a relative path — and `src/next.config.ts:88`–`100` rewrites
`/api/:path*` and `/sanctum/:path*` to `NEXT_PUBLIC_API_URL`. The Node process is
therefore the reverse proxy in the current design.

---

## 2. Actual technology stack

Read from `api/composer.json`, `api/composer.lock`, `src/package.json`, `.nvmrc` and
`api/config/*.php`.

### Backend

| Item | Actual value | Evidence |
|---|---|---|
| PHP requirement | **`^8.2`** | `composer.lock` `platform` block; `composer.json` `require.php` |
| Mandatory extensions declared | **`ext-gd`, `ext-pdo`, `ext-pdo_mysql`** | `composer.lock` `platform`. **`ext-redis` is not declared anywhere** |
| Framework | **Laravel `v12.64.0`** | `composer.lock` |
| Package count | **114 production + 49 dev = 163** | `composer.lock`, counted programmatically |
| Auth | **Laravel Sanctum `v4.3.2`**, cookie-borne opaque token | `composer.lock`; `bootstrap/app.php` `AuthenticateFromCookie` plus `statefulApi()` |
| MFA | **`pragmarx/google2fa v9.0.0`** | `composer.lock`; `RequirePlatformMfa`, `RejectUnverifiedMfaToken` |
| PDF | **`barryvdh/laravel-dompdf v3.1.2`** — pure PHP, no binary | `composer.lock`; `PayslipPdfService`, `ReportController`, `BillingController` |
| API docs | **`dedoc/scramble v0.13.36`** — generates the OpenAPI the frontend client is built from | `composer.lock` |
| Object storage client | **`league/flysystem-aws-s3-v3 3.35.2`** | `composer.lock` |
| Realtime server | **`laravel/reverb v1.10.2`** plus `pusher/pusher-php-server 7.2.8` and 7 `react/*` packages | `composer.lock` |
| Error tracking | **`sentry/sentry-laravel 4.27.0`** | `composer.lock` |
| **Horizon** | **ABSENT.** Not in `composer.json`, not in `composer.lock`, no `api/config/horizon.php` | removed in `cdf85d1`; `infrastructure/supervisor.conf` header records it |
| **Redis client** | **ABSENT from PHP dependencies.** No `predis/predis` in the lockfile; no `ext-redis` platform requirement | `composer.lock` |
| Tests | **180 `*Test.php` classes** (Pest plus PHPUnit 11.5) | `find api/tests -name '*Test.php'` |

### Frontend

| Item | Actual value | Evidence |
|---|---|---|
| Framework | **Next.js `^16.3.5`**, App Router | `src/package.json` |
| UI runtime | **React `^19.2.7`** | `src/package.json` |
| Build output mode | **`output: "standalone"`** — a Node server, not a static export | `src/next.config.ts:24` |
| Node version pinned for build/CI | **24** | `.nvmrc` |
| Node version the container runs | **22** (`node:22-alpine`) | `docker/frontend/Dockerfile:1` |
| Styling | **Tailwind CSS 4** via `@tailwindcss/postcss` | `src/package.json`, `src/postcss.config.mjs` |
| Data layer | **TanStack Query 5**, `axios`, `zod` 4, `react-hook-form` | `src/package.json` |
| Realtime client | **`laravel-echo` plus `pusher-js`** | `src/package.json` |
| Offline-first | **service worker** intercepting `/api/`; `fake-indexeddb` in tests | `src/public/sw.js:59`; `src/package.json` devDeps |
| Middleware | **`src/src/middleware.ts`** — hostname decides which application a request may reach | file header |
| Unit tests | **85 `*.test.ts(x)` files** (Vitest 2) | `find src/src -name '*.test.ts*'` |
| E2E | **14 Playwright specs** including accessibility, offline, Amharic layout | `src/e2e/` |

### Data and infrastructure defaults (as the code ships)

Every default below is **database-backed or inert** — read from `api/config/`:

| Concern | `config/` default | File |
|---|---|---|
| Database | `sqlite` | `database.php:20` |
| Queue | **`database`** | `queue.php:16` |
| Cache | **`database`** | `cache.php:18` |
| Session | **`database`** | `session.php:21` |
| Broadcasting | **`null`** | `broadcasting.php:40` |
| Filesystem | **`local`** | `filesystems.php:16` |

Connections *defined* (not necessarily used): `sqlite`, `mysql`, `mariadb`, `pgsql`,
`sqlsrv`. Queue connections defined: `sync`, `database`, `beanstalkd`, `sqs`, `redis`,
`deferred`, `background`, `failover`. Cache stores defined: `array`, `database`, `file`,
`memcached`, `redis`, `dynamodb`, `octane`, `failover`. Filesystem disks defined:
`local`, `public`, `s3`, `minio`.

**The `redis` blocks in `queue.php` and `cache.php` are Laravel skeleton defaults that
cannot be selected by this lockfile** — no `predis/predis`, no `ext-redis`. Selecting
them requires either the extension (which `docker/php/Dockerfile:25` and
`api/Dockerfile.prod:25` install with `pecl install redis`) or adding a package.

---

## 3. Runtime components — what must be executing in production

Classified by what breaks if the component is absent.

| Component | Required at production runtime? | What depends on it |
|---|---|---|
| **PHP handler serving `api/public/index.php`** | **Yes — hard** | Every one of the 361 `Route::` registrations in `routes/api.php` |
| **A MySQL-family database** | **Yes — hard** | 80 `Schema::create` calls across 67 migrations; plus queue, cache, session and lock tables under the shipped defaults |
| **A queue runner** (`queue:work`) | **Yes — functionally hard** | **All 16 classes in `api/app/Jobs` implement `ShouldQueue`.** Without a runner: payroll never completes (`ProcessPayrollJob`), webhooks never deliver, backups never run, leave never accrues |
| **A scheduler tick** (`schedule:run`) | **Yes — functionally hard** | **14 `Schedule::` entries** in `routes/console.php`, of which 12 exist only to enqueue. Includes the minute heartbeat that makes the other 13 observable |
| **A Node process for the frontend** | **Yes, as currently built** | `output: "standalone"`; `middleware.ts`; the `/api` and `/sanctum` rewrites in `next.config.ts` that make the browser's relative `baseURL` resolve |
| **Reverb (WebSocket daemon)** | **No — optional, and off by default** | **3** `ShouldBroadcast` events *(corrected 2026-09-25 — this read 5; `grep -rl ShouldBroadcast` matched the string inside comments)*, 3 authorised channels, and **one** frontend subscriber. `BROADCAST_CONNECTION` defaults to `null` (`broadcasting.php:40`), so absence is inert rather than fatal |
| **Redis** | **No** | Nothing in `app/`, `routes/` or `bootstrap/` references `Redis::` or the Redis facade. Only `docker-compose*.yml` selects it, and only the Docker images provide the extension |
| **MinIO / S3** | **No** | `FileStorageService.php:64` resolves `config('filesystems.default')`; `local` is the shipped default |
| **Docker** | **No** | Nothing in `app/` shells out. `exec`/`shell_exec`/`proc_open` appear nowhere in application code |

### The HTTP-driven runner already in the codebase

`api/app/Http/Controllers/Api/V1/Cron/CronRunController.php` drives `schedule:run` and
`queue:work --stop-when-empty --max-time=50` over **`POST /api/v1/cron/schedule`** and
**`POST /api/v1/cron/queue`**, behind `throttle:cron` then `VerifyCronToken` (order is
load-bearing — the limiter must see rejected tokens), with a `Cache::lock` preventing
overlap and a 409 when a run is already in flight. `config/cron.php` carries
`CRON_TOKEN`, `CRON_QUEUE_MAX_SECONDS` (50) and `CRON_LOCK_SECONDS` (110). The routes
**404 when `CRON_TOKEN` is unset**, and are excluded from the published OpenAPI document
with `#[ExcludeRouteFromDocs]`.

This is a **shared-hosting component that already exists**. It is not something the
migration has to build.

---

## 4. Build components — needed before deployment, not during

| Component | Role |
|---|---|
| `composer install` (163 packages) | Produces `api/vendor/` — **not present in this working tree** |
| `npm ci` plus `next build` | Produces `.next/standalone` and `.next/static` — **`src/node_modules` not present in this working tree** |
| `src/src/api/generated.ts` generation | `src/scripts/generate-api.mjs` reads the backend OpenAPI document; `openapi-typescript` emits types |
| Vite (`api/package.json`) | Builds the Laravel-side Blade assets only. **Not the SPA build** — that is Next.js |
| `php artisan key:generate` | Mandatory: `Employee.tin` and `national_id` use the `encrypted` cast, and the encrypter refuses to boot without `APP_KEY` |
| `php artisan storage:link` | `api/public/storage` is **not tracked in git**, so it must be created on the host |

**Ordering trap, already recorded in the root `CLAUDE.md`:** `.env` must exist *before*
`composer install`, because `package:discover` runs as a post-autoload-dump script during
the install.

---

## 5. Deployment components present in the repository

| Path | Target | Class |
|---|---|---|
| `docker-compose.yml` | Local development — 9 services: nginx, api, worker, scheduler, frontend, reverb, mariadb, redis, minio | VPS/Docker |
| `docker-compose.prod.yml` | VPS production — adds `worker-realtime`, `worker-exports`, `mariadb-replica`, `redis-cache` | VPS/Docker |
| `docker-compose.lowmem.yml`, `.test.yml`, `.hostnames.yml` | Variants | VPS/Docker |
| `docker/`, `api/Dockerfile.prod` | Image build inputs (`php:8.2-fpm-alpine`, `node:22-alpine`, MariaDB cnf, nginx conf, php.ini) | VPS/Docker |
| ~~`infrastructure/nginx.conf`, `nginx-common.conf`~~ | VPS web server; also the authoritative `/admin` host guard. **`infrastructure/` no longer exists — removed 2026-09-26/27**; on the shared-hosting target the `/admin` control is `.htaccess` group 0 | VPS |
| `infrastructure/supervisor.conf` | VPS process supervision — `ethr-queue`, `ethr-scheduler`, `ethr-reverb` | VPS |
| `infrastructure/certbot-webroot/` | ACME | VPS |
| `scripts/gates.sh` plus 9 sibling scripts | Quality gates; **CI calls `gates.sh` rather than restating it** | Both |
| `scripts/backup.sh`, `restore.sh`, `rollback.sh`, `deploy.sh`, `prod-build-test.sh` | **Docker-only** — `docker compose exec -T mariadb mysqldump` | VPS/Docker |
| `ethr:backup` / `ethr:restore` artisan commands | **Host-neutral backup path**, rehearsed in CI on MariaDB | Both |
| `scripts/shared-hosting/deploy.sh`, `smoke-check.sh` | Shared hosting | Bronze |
| `scripts/hosting-verification/ethr-hosting-check.php` (29.6 KB) | Capability probe; has a web-execution route | Bronze |
| `docs/deployment/shared-hosting/` | `.htaccess`, `nginx-directives.conf`, `cron.txt`, `DEPLOYMENT.md` (39.9 KB), `ENVIRONMENT.md`, `cron-caller.md`, `health-check.md`, `deploy-checklist.md`, `rollback.md` | Bronze |
| `api/.env.shared-hosting.example` (13.8 KB) | Fully annotated Bronze environment; every Docker service name already replaced | Bronze |
| `.github/workflows/` | `gates.yml`, `security.yml`, `verify-without-fix.yml` | CI |
| `RUN_ALL.ps1`, `START_BACKEND.ps1`, `START_FRONTEND.ps1` | Windows dev launchers predating Docker; start neither worker nor Reverb | Legacy |

---

## 6. Database schema shape

| Measurement | Value |
|---|---|
| Migration files | **67** |
| `Schema::create` calls | **80** |
| Engine-specific constructs | audit-log **triggers**, one **generated column**, a dropped **fulltext** index, an optional **read/write split** |

Three constructs decide Bronze compatibility and are detailed in
[`BRONZE-COMPATIBILITY-MATRIX.md`](BRONZE-COMPATIBILITY-MATRIX.md):

1. **`2026_07_22_000001_restrict_audit_log_to_insert_only.php`** creates
   `audit_log_no_update` and `audit_log_no_delete` `BEFORE UPDATE`/`BEFORE DELETE`
   triggers that `SIGNAL SQLSTATE '45000'`. On MySQL/MariaDB a failure is **fatal by
   design** — it rethrows, aborting `migrate`. The file's own docblock explains why:
   `AuditLog`'s `update()`/`delete()` overrides are instance methods, so every mass path
   bypasses them and the trigger is the only real control.
2. **`2026_09_23_000002_add_unique_index_to_device_serial_number.php`** adds
   `serial_number_active`, a **VIRTUAL generated column** that goes NULL once
   `deleted_at` is set, carrying a **global** (not tenant-scoped) unique index on
   `(serial_number, adapter_type)`. VIRTUAL because SQLite can `ALTER TABLE` a virtual
   generated column and refuses a stored one.
3. **`2026_09_16_000001_drop_employee_fulltext_index.php`** removes the fulltext index
   added by `2026_07_16_000003`. Fulltext is therefore **not** a Bronze requirement.

---

## 7. What is NOT in this repository

Recorded because their absence changes migration scope:

- **No `api/vendor/`, no `src/node_modules/`** in this working tree.
- **No Horizon** — package, config and provider entry all gone.
- **No Redis PHP client** — the extension arrives only via the Docker images.
- **No Octane, no Swoole, no PM2, no systemd unit files.**
- **No non-Docker `backup.sh`** — but `ethr:backup`/`ethr:restore` artisan commands do
  exist and are the host-neutral path.
- **No `config/horizon.php`**, which `docs/ARCHITECTURE.md:1254` still cites.

---

## Related

- [`BASELINE.md`](BASELINE.md) — the measured-state reference this document defers to
- [`VPS-SHARED-HOSTING-MATRIX.md`](VPS-SHARED-HOSTING-MATRIX.md)
- [`BRONZE-COMPATIBILITY-MATRIX.md`](BRONZE-COMPATIBILITY-MATRIX.md)
- [`CODE-VS-DOCUMENTATION.md`](CODE-VS-DOCUMENTATION.md)
- [`TENANT-ISOLATION-AUDIT.md`](TENANT-ISOLATION-AUDIT.md)
- [`MIGRATION-DOCUMENT-INVENTORY.md`](MIGRATION-DOCUMENT-INVENTORY.md)
- [`../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md)
