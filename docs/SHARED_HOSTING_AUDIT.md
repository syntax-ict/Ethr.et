# ETHR — Shared-Hosting Migration Audit (Phase 0 + Phase 1)

**Date:** 2026-08-29
**Status:** Read-only discovery complete. No application code modified.
**Source of truth:** the code in `api/` and `src/`, not `docs/phases/PHASE_*.md`.

---

## A. Current architecture (as discovered in code)

Verified from `docker-compose.prod.yml`, `infrastructure/nginx.conf`,
`infrastructure/nginx-common.conf` and `.env.production.example`.

```
                         Browser
                            │  https://{ethr.et | admin.ethr.et | *.ethr.et}
                            ▼
                    ┌───────────────┐
                    │ nginx (443)   │  3 server blocks, one wildcard TLS cert
                    │ root = api/   │  Host header is PRESERVED — it is the
                    │        public │  tenant selector for BOTH tiers.
                    └───┬───┬───┬───┘
        /api, /sanctum  │   │   │  /app (WebSocket)
        *.php ──────────┘   │   └──────────────► reverb:8080  (Laravel Reverb)
                            │
                  everything else
                            ▼
                  frontend:3000  (Next.js 16, output:"standalone", Node runtime)
                            │
   FastCGI ▼                │  the browser then calls /api/v1/* SAME-ORIGIN
     php-fpm (api)  ◄───────┘  (src/api/client.ts baseURL = "/api/v1")
        │
        ├── mariadb (primary)  ──── mariadb-replica  (read/write split)
        ├── redis            (queue + broadcast)
        ├── redis-cache      (cache + session)
        └── minio:9000       (S3-compatible object storage, presigned URLs)

  worker-realtime        ┐
  worker-notifications   ├─ Horizon supervisors (6), consuming redis queues
  worker-heavy           ┘
  scheduler               ── `php artisan schedule:work` (14 scheduled entries)
```

**Service inventory (`docker-compose.prod.yml`):** `nginx`, `api`, `worker-realtime`,
`worker-notifications`, `worker-heavy`, `scheduler`, `reverb`, `frontend`, `mariadb`,
`mariadb-replica`, `redis`, `redis-cache`, `minio` — 13 services.

### Scale of the application

| Metric | Value |
| --- | --- |
| Laravel | v12.64.0 (PHP `^8.2`) |
| Next.js | ^16.2.9 / React 19 |
| Route registrations in `routes/api.php` | 353 |
| Eloquent models | 69 |
| Controllers | 102 |
| Migrations | 54 |
| Queued job classes | 15 |
| Scheduled entries (`routes/console.php`) | 11 |
| Backend tests | 139 files (Pest/PHPUnit) |
| Frontend unit tests | 70 Vitest files |
| E2E specs | 13 Playwright specs |
| Next.js page/layout files | 126, of which **107 are `"use client"`** |

### Tenancy model — VERIFIED

**Single database, shared schema, row-level isolation.**

- `app/Traits/BelongsToTenant.php` adds a global scope `where(tenant_id = current)`,
  and `whereRaw('0 = 1')` when no tenant is resolved.
- 37 migrations carry a `tenant_id` column.
- **No** `CREATE DATABASE`, no `setDatabaseName`, no connection purging anywhere in `app/`.

This is the single most important finding for shared hosting: ETHR needs **one MySQL
database**, not one per tenant. Even the cheapest Ethio Telecom plan (1 database) is
schema-sufficient.

`app/Http/Middleware/ResolveTenant.php` resolves the tenant, in order:

1. **Subdomain** — the only selector honoured in production.
2. `X-Tenant` header — refused in production; local/testing only.
3. Tenant slug in the request body — login/register/password endpoints only.

`admin.ethr.et` is a platform host and resolves **no** tenant by any selector.

---

## B. Dependency inventory

| Component | Required by ETHR? | Current implementation | Shared-hosting support | Migration strategy |
| --- | --- | --- | --- | --- |
| **PHP 8.2+** | Yes, hard | php-fpm 8.2 (`docker/php`) | ~~UNKNOWN~~ **VERIFIED 8.3.33** — Plesk *PHP Settings*, 2026-09-17 | ~~Blocking gate.~~ **Cleared.** Satisfies `^8.2`. Panel reading, not probe output, so G0-E's *extension* and *limit* rows are still open — only the version row is answered. |
| **Laravel 12** | Yes | `api/` | Runs on any PHP 8.2 host with a writable `storage/` | Deploy `api/public` as document root. No code change. |
| **MariaDB/MySQL** | Yes | `mariadb` container, `DB_CONNECTION=mariadb` | Offered (Linux + MySQL plans) | Point `DB_*` at the Plesk database. Confirm server flavour matches `DB_CONNECTION`. |
| **DB read replica** | No — optimisation | `mariadb-replica`, `DB_READ_HOST` | Not offered | Drop. Unset `DB_READ_HOST`; `config/database.php` collapses to one connection. Zero code change. |
| **Redis (queue)** | **No — driver-level only** | `QUEUE_CONNECTION=redis` | **UNKNOWN**, assume absent | `QUEUE_CONNECTION=database`. Laravel's own default; the `jobs` / `job_batches` / `failed_jobs` tables already exist. **Zero code change** — there is no `Redis::` call anywhere in `app/`. |
| **Redis (cache)** | No — driver-level only | `CACHE_STORE=redis` | UNKNOWN, assume absent | `CACHE_STORE=database`. ~~Caveat: two files hardcode `cache()->store('redis')`~~ — **fixed in Phase A (`80cac67`); re-verified by grep 2026-09-18, no `store('redis')` survives in `api/app/`.** See §D. |
| **Redis (session)** | No — driver-level only | `SESSION_DRIVER=redis` | UNKNOWN | `SESSION_DRIVER=database`. Sessions table exists. |
| **Redis (rate limiting)** | No | `RateLimiter` over the cache store | — | Follows `CACHE_STORE`. 10 named limiters in `AppServiceProvider`, all driver-agnostic. |
| **Laravel Horizon** | **No — it is a Redis-queue runner** | 6 supervisors, `config/horizon.php` | Needs Redis + long-running processes | Replace with cron-driven `queue:work --stop-when-empty`. The *dashboard* is lost; the *jobs* are not. |
| **Queue workers** | **Yes — 15 job classes** | 3 worker containers | Long-running daemons **UNKNOWN** | Cron every minute: `queue:work --stop-when-empty --max-time=55`. |
| **Scheduler** | **Yes — 14 entries** | `schedule:work` container | Needs one cron entry | Standard `* * * * * php artisan schedule:run`. **UNKNOWN** whether the plan offers cron. |
| **Reverb / WebSockets** | **No — enhancement only** | `reverb` container, `/app` upgrade | Shared hosting cannot hold a WS listener | `BROADCAST_CONNECTION=log`. Already guarded in application code — see §C. |
| **MinIO / S3** | Files yes; the *implementation* is swappable | `minio` disk, presigned URLs | Object storage not offered; local disk is | Repoint the `minio` disk env at any S3-compatible endpoint, **or** switch to the `local` disk (Laravel 11+ `serve => true` gives signed local `temporaryUrl`). One line either way — see §D. |
| **Nginx** | No | 3 server blocks | Plesk fronts with nginx + Apache; `.htaccess` honoured | Translate the rules to `.htaccess` plus Plesk vhost settings. |
| **Node.js runtime (Next SSR)** | **Yes, as currently built** | `output: "standalone"` | **UNKNOWN** — Plesk has a Node.js extension; availability unconfirmed | Second blocking gate. See §E. |
| **Docker** | No | dev/prod convenience | Not offered | Drop entirely. |
| **SMTP** | Yes | `MAIL_MAILER=smtp`, external host | Plans include email accounts | Use plan SMTP or an external relay. Already env-driven. |
| **Sentry** | No — optional | `SENTRY_LARAVEL_DSN` | Outbound HTTPS **UNKNOWN** | Leave unset if outbound is blocked. Already a no-op when empty. |
| **EthioTelecom SMS** | Optional, opt-in | `SMS_DRIVER=log` default | Outbound HTTPS **UNKNOWN** | Unchanged. Never verified live in any environment (`docs/DEPLOYMENT.md`). |

### PHP extensions actually required

Traced through `composer.lock` `require` blocks and `app/` source.

**Mandatory (18):** `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `dom`,
`ctype`, `json`, `fileinfo`, `filter`, `hash`, `session`, `curl`, `iconv`, `simplexml`,
`libxml`, **`gd`** (EXIF stripping in `FileStorageService`, plus thumbnails).

> **Two corrections, measured 2026-09-18.** This list said it was *"traced through
> `composer.lock` `require` blocks and `app/` source"* — and that trace is exactly what
> disproves two of its entries.
>
> **`bcmath` removed.** It appears in `composer.lock` four times, every one under
> `suggest`, never `require`. The application calls no `bc*` function: money is stored in
> integer minor units (`salary_cents`, `price_cents`), which is why it never needed
> arbitrary precision.
>
> **`zip (dompdf)` removed — the attribution was wrong twice over.** `dompdf/dompdf`
> requires `ext-dom` and `ext-mbstring` only, and **no production package requires
> `ext-zip` at all.** Its one use is `BackupService`, which tries `PharData` first and
> falls back to `ZipArchive`, throwing a clear `RuntimeException` if neither exists — so
> the requirement is **`phar` OR `zip`**, and neither alone is mandatory.

**Conditional:** `intl` (locale formatting), `redis`/`phpredis` (**only** if Redis is
kept), `sodium` (Laravel encryption paths).

**Not needed:** `pcntl`, `posix` (used by Horizon and `queue:work --daemon` signal
handling — not required for `--stop-when-empty`), `imagick`, `memcached`, `mongodb`,
`amqp`. Verified: **no `exec()`, `proc_open()`, `shell_exec()`, `pcntl_*`,
`set_time_limit()` or `ini_set()` calls anywhere in `app/`.**

`gd` is the one extension whose absence causes silent behavioural change rather than a
crash: `FileStorageService::compressImage()` returns the original bytes and
`storeThumbnails()` returns `[]` when GD is missing. Uploads keep working; the size
budget and the thumbnails quietly stop being enforced.

### Database features requiring elevated privileges — RISK

| Feature | Migration | Privilege needed | Shared-hosting risk |
| --- | --- | --- | --- |
| **`CREATE TRIGGER` × 2** (append-only `audit_log`) | `2026_07_22_000001_restrict_audit_log_to_insert_only.php` | `TRIGGER`, plus `SUPER` **or** `log_bin_trust_function_creators=1` when binary logging is on | **HIGH.** Managed MySQL with binlog on and no `SUPER` fails with error 1419 and **aborts the entire migration run**. |
| **`ALTER TABLE … ADD FULLTEXT`** | `2026_07_16_000003_add_employee_fulltext_index.php` | `ALTER` (normally granted) | LOW. InnoDB FULLTEXT is standard on MySQL 5.6+ / MariaDB 10.0+. |
| Foreign keys (32 migrations) | various | `REFERENCES` (normally granted) | LOW. |
| `json()` columns (32) | various | MySQL 5.7+ / MariaDB 10.2+ | LOW, but the server version must be confirmed. |
| `utf8mb4` / `utf8mb4_unicode_ci` | `config/database.php` | — | LOW. Required for Amharic. |

The trigger migration is the single most likely cause of a failed first deployment.

---

## C. Feature dependency matrix

Classified from the code. Where a classification depends on an unverified hosting
capability, the dependency is named.

| Feature | Class | Evidence / reasoning |
| --- | --- | --- |
| Authentication (login / logout / refresh) | 🟢 GREEN | Sanctum token in an httpOnly cookie (`AuthenticateFromCookie`). Pure DB + PHP. |
| Session / CSRF (`statefulApi()`) | 🟢 GREEN | `SESSION_DRIVER=database` behaves identically. |
| MFA (TOTP) | 🟢 GREEN | `pragmarx/google2fa`, pure PHP. |
| OTP / trusted devices | 🟢 GREEN | DB-backed. |
| Password reset | 🟡 YELLOW | Works; **depends on SMTP** being reachable. |
| Multi-tenancy (row-level) | 🟢 GREEN | Single DB, global scope. No infra dependency. |
| **Tenant subdomains** | 🔴 **AT RISK** | Needs **wildcard DNS + wildcard TLS + a catch-all vhost**. "Unlimited subdomains" on a Plesk plan means *manually created* subdomains, which is not the same thing. Open question #1. |
| Tenant isolation | 🟢 GREEN | Enforced in PHP, not in infrastructure. |
| Employee management (69 models) | 🟢 GREEN | CRUD over MySQL. |
| Attendance (manual / QR / kiosk / mobile) | 🟢 GREEN | HTTP + DB. Camera is client-side. |
| Biometric device integration | 🟡 YELLOW | `SyncDevicesCommand` + `PullDeviceEventsJob` poll every 5 min. **Requires outbound network access from the host to the devices** — unverified, and devices behind customer NAT were already unreachable from the VPS. |
| Leave (accrual, carry-forward) | 🟡 YELLOW | Logic is GREEN; delivery depends on **scheduler + queue**. |
| Payroll | 🟡 YELLOW | Pure PHP calculation. Risk is **`max_execution_time`** — the current nginx config allows 120 s for `/api/v1/payroll` and Horizon allows 1800 s for the `payroll` queue. Shared hosts commonly cap at 30–120 s. |
| Billing / invoices | 🟡 YELLOW | Depends on `GenerateMonthlyInvoicesJob` + `HandleOverdueInvoicesJob` (scheduler). |
| Notifications (in-app) | 🟢 GREEN | `database` channel, 18 notifications. Frontend polls every 30 s (`features/notifications/api.ts:30`). |
| Email notifications | 🟡 YELLOW | 13 notifications use `mail`. Needs SMTP plus a queue worker. |
| SMS notifications | 🟡 YELLOW | Off by default (`SMS_DRIVER=log`). Needs outbound HTTPS when enabled. |
| **Realtime (Reverb)** | 🟡 YELLOW — **safely degradable** | 12 notifications wrap the broadcast leg in `if (config('broadcasting.default') === 'reverb')`. The only frontend consumer is `ReverbProvider`, subscribing to `user.{id}` for toast pop-ups, and it **already catches failure and falls back to the 30 s poll**. `BROADCAST_CONNECTION=log` loses live toasts and live device-status pushes; it loses **no data and no notification** — the `database` channel still fires. |
| File upload | 🟢 GREEN | Bounded by `upload_max_filesize` / `post_max_size`. nginx currently allows 50 MB. |
| File download / signed URLs | 🟡 YELLOW | `temporaryUrl()` needs S3 **or** the local disk with `serve => true` (already set in `config/filesystems.php`). One hardcoded disk name to change — §D. |
| Image thumbnails / EXIF strip | 🟡 YELLOW | Needs `ext-gd`. Degrades silently without it. |
| PDF generation (payslips, invoices, reports) | 🟢 GREEN | `barryvdh/laravel-dompdf` — pure PHP. **Needs `ext-dom` + `ext-mbstring`**, which is what `dompdf/dompdf` actually declares; `ext-gd` matters for images inside a PDF. *Corrected 2026-09-18: this said `ext-zip`, and no production package requires `ext-zip` at all.* |
| CSV import (employees, attendance) | 🟡 YELLOW | Staged in DB then queued. Execution-time sensitive. |
| Reports / scheduled reports | 🟡 YELLOW | `RunScheduledReportsJob` runs hourly — scheduler-dependent. |
| Dashboard digests | 🟡 YELLOW | `RunDashboardDigestsJob`, hourly — scheduler-dependent. |
| **Background jobs (15 classes)** | 🟡 YELLOW | Fully preserved on `QUEUE_CONNECTION=database` **provided cron exists**. Zero code change. |
| **Scheduled jobs (14 entries)** | 🟡 YELLOW | Needs exactly one cron entry. **RED if cron is unavailable** — leave accrual, invoicing, anomaly scans and cleanup all stop. |
| Object storage (MinIO) | 🟡 YELLOW | Replaceable by external S3 or the local disk. |
| Search | 🟢 GREEN | MySQL FULLTEXT. No Elasticsearch, no Scout. |
| Ethiopian calendar | 🟢 GREEN | Pure computation — `lib/calendar/ethiopian.ts` + `CalendarService`. |
| Amharic localisation | 🟢 GREEN | `api/lang/` + client i18n. Needs `utf8mb4`. |
| PWA / offline queue | 🟢 GREEN | `public/sw.js`, `lib/offline-queue.ts`. Static assets. |
| Audit-log immutability | 🔴 **AT RISK** | DB triggers — see the privilege table above. |
| API docs (Scramble) | 🟢 GREEN | Should be disabled in production regardless. |
| Horizon dashboard | 🔴 RED | Requires Redis. **Lost** — but it is an ops UI, not a product feature. |

**Summary:** no product feature is impossible. Every genuinely blocking risk is an
*infrastructure capability* question, not an application-architecture question:
wildcard subdomains, cron, Node.js runtime, PHP version, DB trigger privilege.

---

## D. Exact hardcoded infrastructure references — **ALL FIXED, and there were five**

> **Corrected 2026-09-18. This section is called "the headline result of the audit", and
> it reads as outstanding work in the present tense. It is not — every site below was
> fixed in Phase A (`80cac67`, merged `12f53de`), and the count was wrong.**
>
> - **The count is five, not four.** `MIGRATION_STATE.md` **D5** already records this:
>   *"Was reported as 4; `checkReadReplica()`'s hardcoded `mariadb` found during
>   implementation."* The table below never gained the fifth row.
> - **All five are fixed.** Re-verified today by grep: no `= 'minio'`, no
>   `cache()->store('redis')`, no `Storage::disk('minio')` survives in those three files,
>   and `HealthController.php:103` now carries a comment reading *"The connection in use,
>   not `mariadb` by name. Same defect class as the …"*.
>
> The table is kept as the original finding rather than deleted — it was accurate when
> written, and a defect that was real and then fixed should read as history. The
> **Impact** column describes what *would* happen, not what does.

The application is almost entirely env-driven. Grepping `app/` for hardcoded
infrastructure yielded **four sites in three files** *(five — see the correction above)*:

| File | Line | Code | Impact |
| --- | --- | --- | --- |
| `api/app/Services/FileStorageService.php` | 44 | `$this->disk = 'minio';` | Ignores `FILESYSTEM_DISK`. **Every** upload / download / thumbnail path goes through this. |
| `api/app/Http/Controllers/Api/V1/HealthController.php` | 29–30 | `cache()->store('redis')` | Health endpoint reports unhealthy without Redis. |
| `api/app/Http/Controllers/Api/V1/HealthController.php` | 43 | `Storage::disk('minio')` | Same. |
| `api/app/Console/Commands/HealthCheckCommand.php` | 20 | `cache()->store('redis')` | `health:check` fails without Redis. |

Verified absent: no `Redis::` facade calls, no `Queue::connection()`, no
`DB::connection('literal')`, no `Horizon::` calls in application code.

**This is the headline result of the audit.** Swapping queue, cache, session and
broadcast drivers is a `.env` change. Swapping storage is a one-line change — or zero,
if the `minio` disk's env vars are simply repointed at another S3-compatible endpoint.

---

## E. Next.js route classification (Phase 6 input)

`output: "standalone"` today. A static export (`output: "export"`) would have to remove
or replace these three things:

| Server-side dependency | Location | Can it be removed? |
| --- | --- | --- |
| **`middleware.ts`** — host-based routing: refuses `/admin` off `admin.ethr.et`, redirects tenant routes on the platform host | `src/src/middleware.ts` | Yes, functionally. Its own comment states **nginx is the authoritative control** and the middleware exists so the same rule holds in development. The equivalent must then be reimplemented in `.htaccess`. |
| **`headers()` in the auth layout** — reads `Host` so the login page knows which tenant it is for during SSR | `src/src/app/(auth)/layout.tsx` | Yes, at a cost. It exists specifically to fix React hydration error #418. Under static export the host is read client-side, reintroducing a first-paint flash on the tenant login page — not an error, since the server no longer renders a competing tree. |
| **`rewrites()`** — proxies `/api/*` and `/sanctum/*` to Laravel | `src/next.config.ts` | Yes. The proxy exists only for local dev on a split origin. In production nginx already routes `/api` to PHP before the SPA sees it, and `src/api/client.ts` uses the relative `baseURL: "/api/v1"`. `.htaccess` must reproduce this. |

**Not present, and therefore not a blocker:** zero `route.ts` API routes, zero
`"use server"` server actions, zero `generateStaticParams`, zero
`export const dynamic` / `revalidate` / `runtime`. **107 of 126** page and layout files
are `"use client"`.

The four dynamic routes (`employees/[id]`, `payroll/[id]`, `devices/[id]`,
`admin/tenants/[id]`) are client components that fetch by id. Under static export they
need `generateStaticParams` returning `[]` plus a client-side param read, or a rewrite
to a shell page.

> **Do not act on the sentence above without reading *MEASURED 2026-09-18* at the end of
> this section.** The `generateStaticParams` half is **not implementable on these files** —
> they are `"use client"`, and Next rejects the combination. The "rewrite to a shell page"
> alternative is the one that works.

**Conclusion:** this application is a client-rendered SPA that happens to be built with
Next.js. A static export is *technically viable* — a genuinely fortunate finding — but
it is not free, and must not be done blind. It is Option B2 in the migration plan.

### Re-verified 2026-09-17, because this conclusion became load-bearing

`deployment/GATE-0-RESULT.md`'s amended G0-A consequence now rests on this section: if the
account has no custom-directive field, static export is what keeps the frontend
same-origin. That made it worth re-checking against a `src/` that had moved 41 commits
since — PR #16 added locale-routed marketing pages, which is exactly the kind of change
that could have introduced a server-side dependency.

**It did not. The conclusion holds, and the counts above are stale in a benign direction.**

| §E claim | Then | Now | Effect |
| --- | --- | --- | --- |
| zero `route.ts` | 0 | **1** — `app/og.png/route.tsx` | **None.** It carries `export const dynamic = "force-static"` and a comment saying it "keeps working under `output: export`, where a dynamic route handler would not." Built export-compatible on purpose |
| zero `generateStaticParams` | 0 | **1** — `(marketing)/[locale]/layout.tsx:21` | **None** — this is what static export needs, not an obstacle |
| dynamic segments | 4 | **5** — `[locale]` is new | **None.** It ships `generateStaticParams` *and* `dynamicParams = false`, whose own comment cites `output: "export"` |
| zero `"use server"` | 0 | 0 | unchanged |

**All three named blockers still stand** and still need the work described above:
`middleware.ts` exists, `(auth)/layout.tsx` reads `headers()` (twice), `rewrites()` is
still in `next.config.ts`, and the four `[id]` dashboard routes still need
`generateStaticParams` returning `[]`.

> **A warning for whoever re-runs this check.** Grepping for
> `export const (dynamic|revalidate|runtime)` matches **`dynamicParams`**, which is a
> different directive and is *favourable* to static export. That false positive was hit on
> this very re-verification and briefly looked like the conclusion had broken. Match on
> word boundaries.

### MEASURED 2026-09-18 (commit `7aed9d2`) — the export was attempted, and it does not build

Everything above was read from the source. It was never *run*. Running it changes two of
its conclusions and adds a blocker it did not list.

**Baseline first, so the comparison is fair.** `npm run build` with the shipped
`output: "standalone"` **passes** — exit 0, 90 routes prerendered, 11 dynamic
(`routes-manifest.json`), one server-rendered (`/register`). The repository is not broken;
it is simply built for a Node server.

Switching to `output: "export"` produced three build-stopping blockers, in this order:

| # | Blocker | §E's position |
|---|---|---|
| 1 | `/manifest.webmanifest` requires `export const dynamic = "force-static"` | **Not listed.** `app/manifest.ts` is a metadata route and was missed by the "not present, therefore not a blocker" sweep, which checked `route.ts` but not `manifest.ts` |
| 2 | The four `[id]` routes lack `generateStaticParams()` | Listed, correctly |
| 3 | **All four are `"use client"`, and Next rejects `generateStaticParams` on a client component** | **Not listed, and it invalidates the prescription** |

Blocker 3, verbatim, four times:

```
Error: Next.js can't recognize the exported `generateStaticParams` field in route.
App pages cannot use both "use client" and export function "generateStaticParams()".
```

**§E prescribes *"`generateStaticParams` returning `[]` plus a client-side param read"*.
The first half of that cannot be done to these files.** §E notes on its own page that
**107 of 126** page and layout files are `"use client"` — it simply never connected that
count to this prescription. The four `[id]` pages are among them, so the compiler rejects
the addition outright. The remedy is a **server-component wrapper per route** with the
existing client component moved into a child: four file splits with prop-threading, not
four one-line additions.

#### The distinction that matters more than the blocker count

These are two different questions and the audit conflates them:

| | |
|---|---|
| **Static-export feasibility** | Can `next build` emit files? **Currently no**, and fixable — a metadata directive, four server/client splits, `generateStaticParams` returning `[]` |
| **Application runtime feasibility** | Will the exported app *work*? **No, and the fixes above do not change that.** `[]` pre-renders nothing, so every real `/employees/123` returns 404 |

The IDs are **tenant data**. Nothing can enumerate every employee, device, payroll run and
tenant at build time. Making those routes work under export requires client-side routing
that reads the id from the URL at runtime — §E's *"or a rewrite to a shell page"*, which is
the option that actually works and the one it costed least explicitly.

**So a passing export build would not mean a working application.** That is the same shape
as the green-test-run trap in the root `CLAUDE.md`: the signal that looks like success is
available before the thing itself works.

#### Status

`output: "standalone"` is unchanged and the experiment was reverted in full
(`git checkout -- src/`, working tree clean). **No production implementation was made.**

**Branch B is NOT APPROVED for implementation** until a deployment architecture is
selected. It is gated on G0-A and G0-G, both `NOT VERIFIED`, and the pre-registered
decision rule in `SHARED_HOSTING_MIGRATION_PLAN.md` §4 has returned No-Go on B3.

**Stop describing this as four small route changes.** On the measured evidence it is an
**architectural frontend deployment change**: a rendering-strategy switch, four component
splits, and a routing rearchitecture for entity pages.

---

## F. What is lost in every shared-hosting scenario

Unavoidable, before any option is chosen:

1. **Horizon dashboard** — queue observability. Jobs still run; the UI goes.
2. **Read replica** — a performance optimisation, currently configured but not required.
3. **Sub-minute queue latency** — cron granularity is 1 minute (5 on some plans).
   Affects how quickly a notification email leaves the building, nothing else.
4. **Live WebSocket push** — degrades to the 30 s poll that already exists.

Nothing in that list is a product feature. That is the argument for a shared-hosting
option being viable at all.

---

## G. Open questions that block a Go decision

Not answerable from the codebase. Listed with a verification procedure in
`docs/ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`.

1. Does the plan support **wildcard subdomains** (`*.ethr.et`) with a **wildcard TLS
   certificate**? Without this, SaaS tenant hosts do not work.
2. Is there **cron**? Without it, 14 scheduled entries and all 16 queued jobs stop.
3. Is there a **Node.js runtime** (Plesk Node.js extension)? Determines Option B1 vs B2.
4. What **PHP version and extension set**? `>= 8.2` with `gd` is mandatory.
5. Can the DB user **`CREATE TRIGGER`**? Determines whether migrations run at all.
6. What are **`max_execution_time`**, **`memory_limit`**, **`upload_max_filesize`**?
7. ~~Is **SSH** available?~~ — **ANSWERED 2026-09-17: no, `Forbidden`.** The framing was
   also slightly off: Composer *can* run on the server, via the Plesk Composer extension.
   What SSH actually determines is whether anything can run **`php artisan`** — blockers
   **B-4** and **B-5** in `MIGRATION_STATE.md`. That now rests on Plesk *Scheduled Tasks*.
