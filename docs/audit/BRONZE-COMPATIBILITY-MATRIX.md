# ETHR — Bronze Compatibility Matrix

**Phase 0 forensic audit, read-only. Measured 2026-09-25 against `6d9fb23`.**

Target: **Ethio Telecom Linux Bronze shared hosting under Plesk** — 5 GB storage,
50 GB bandwidth, **1 MySQL database**, 5 email accounts, **5 subdomains**, 1 website, SSL,
no root/sudo/systemd/Supervisor/Docker.

Read this alongside [`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md),
which is the authoritative register of what has actually been *measured* on the account.
This document does not re-score those gates; it maps each published Bronze constraint onto
the implementation this repository actually contains.

**Compatibility values used:** `COMPATIBLE` · `COMPATIBLE WITH CHANGE` ·
`BLOCKED` · `UNVERIFIED` (a host capability nobody has measured — not a soft yes).

> **Updated 2026-09-25 by Phase 1 host verification.** Rows marked **[P1]** carry live
> measurements taken against `www.ethr.et` (pinned to `213.55.96.154`) in the 15:01–15:04 UTC
> window, using the existing `htaccess-canary` procedure. The evidence, the exact tests and
> eight discrepancies against existing documentation are in
> [`BRONZE-BLOCKER-RESOLUTION.md`](BRONZE-BLOCKER-RESOLUTION.md). Rows without **[P1]** are
> unchanged from the Phase 0 reading and remain as measured then.

---

## 1. Platform and runtime

| Requirement | Current ETHR Implementation | Bronze Compatibility | Blocker | Evidence | Migration Needed |
|---|---|---|---|---|---|
| PHP version **[P1]** | `^8.2` required | **COMPATIBLE — VERIFIED** | — | `X-Powered-By: PHP/8.3.33` on a live response, 2026-09-25. **Upgrades the 2026-09-17 panel reading to a measurement** | No |
| Web stack **[P1]** | Laravel served by a PHP handler | **COMPATIBLE — VERIFIED** | — | `Server: nginx` + `X-Powered-By: PleskLin` at the edge; canary reports `server software: Apache`, `php sapi: fpm-fcgi` → **nginx → Apache → PHP-FPM**, with per-directory `.htaccess` honoured | No |
| PHP extensions | `ext-gd`, `ext-pdo`, `ext-pdo_mysql` declared mandatory; 18 counted mandatory in G0-E | **UNVERIFIED** | Possible | `composer.lock`; `GATE-0-RESULT.md` G0-E — extension list NOT VERIFIED | Probe only |
| `memory_limit` ≥ 256M, `max_execution_time` ≥ 120s | `.htaccess` sets `php_value max_execution_time 120` under `mod_php` | **UNVERIFIED** | Possible | `docs/deployment/shared-hosting/.htaccess`; G0-E rows blank. Note the directive only applies under `mod_php`, not FastCGI | Probe only |
| No root / sudo / systemd | No `exec`, `shell_exec`, `proc_open`, `putenv` or `symlink` anywhere in `api/app` | **COMPATIBLE** | — | grep of `api/app`; `BASELINE.md` §9 | No |
| 1 website | Backend (`api/public`) plus a Next.js Node app must share one subscription | **COMPATIBLE WITH CHANGE** | — | `docs/deployment/shared-hosting/DEPLOYMENT.md` §4a *"Assemble the document root"* already solves this: three files placed in `httpdocs`, `index.php` repointed on three lines | Already designed |
| SSL | Per-hostname certificates proven | **COMPATIBLE** | — | `GATE-0-RESULT.md` G0-C: per-hostname TLS **PROVEN** | No |

---

## 2. Database — Bronze provides exactly one MySQL database

| Requirement | Current ETHR Implementation | Bronze Compatibility | Blocker | Evidence | Migration Needed |
|---|---|---|---|---|---|
| **One database is enough** | Single-database, single-schema multi-tenancy — `tenant_id` column plus a global scope, **not** database-per-tenant | **COMPATIBLE** | — | `api/app/Traits/BelongsToTenant.php`; 80 `Schema::create` calls all in one schema | **No** — this is the single most important compatibility fact in the audit |
| Driver | `DB_CONNECTION=mariadb` in `api/.env.example:30` and `api/.env.shared-hosting.example`; `mysql` and `mariadb` connections both defined | **COMPATIBLE** | — | `config/database.php:47`, `:67` | No |
| Version floor ≥ 10.2.7 / 5.7.9 | Generated columns require 5.7.6+ / 10.2.1+ | **UNVERIFIED** | Possible | G0-I `SELECT VERSION()` NOT VERIFIED. Local reference run: MariaDB 10.4.32 | Probe only |
| `utf8mb4` | `DB_CHARSET=utf8mb4`, `utf8mb4_unicode_ci` | **UNVERIFIED** | Unlikely | `config/database.php:56`–`57`; G0-I server charset NOT VERIFIED | Probe only |
| **`CREATE TRIGGER` privilege** | Two triggers enforce the append-only audit log. **Failure is fatal by design** — the migration rethrows and `migrate` aborts | **UNVERIFIED — highest-severity unknown** | **Yes if absent** | `2026_07_22_000001_restrict_audit_log_to_insert_only.php`; `AuditLogImmutabilityTest` drives the raw query builder. `GATE-0-RESULT.md:1158` argues the prior is favourable — `TRIGGER` is part of `ALL PRIVILEGES` on a schema and needs no `SUPER`, and Plesk's default is `ALL` on databases it creates — but calls that *"an argument about priors, not a measurement"* | Probe; if absent, a grant request, **not a code change** |
| Generated column | `serial_number_active`, **VIRTUAL**, NULL once `deleted_at` is set; carries a global unique index on `(serial_number, adapter_type)` | **COMPATIBLE** | — | `2026_09_23_000002_add_unique_index_to_device_serial_number.php`. VIRTUAL because MariaDB has no partial indexes and SQLite refuses a stored one | No |
| Fulltext index | **Removed** | **COMPATIBLE** | — | `2026_09_16_000001_drop_employee_fulltext_index.php`; `BASELINE.md` §13f/§13g record the MariaDB search defect that caused it | No |
| Read replica | Inert unless `DB_READ_HOST` is set | **COMPATIBLE** | — | `config/database.php:70`; `api/.env.shared-hosting.example` instructs *"LEAVE EMPTY. No read replica on shared hosting"* | No |
| Queue, cache, session and lock tables in the same database | `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions` — all in the one schema | **COMPATIBLE** | — | `config/queue.php:38`–`55`, `cache.php:42`, `session.php:89`; `BASELINE.md` §8 records `CACHE_STORE=database` locking live-verified on real MariaDB (`7e31519`) | No |
| Database size counts against the 5 GB quota | Nothing in the code caps it | **UNVERIFIED** | Possible at scale | G0 row *"database size limit — NOT VERIFIED"*. See §6 below | Monitoring |

**The `1 MySQL database` constraint is not a blocker and never was.** ETHR was built
single-schema. A database-per-tenant design would have made Bronze impossible; this one
makes it a capacity question rather than an architecture question.

---

## 3. Queues and background jobs — feature by feature

Every class in `api/app/Jobs` implements `ShouldQueue`. Four queues, drained in order:
`default`, `attendance`, `notifications`, `exports` (`QueueHealth::QUEUES`).

| Business feature | Mechanism | Production requirement | Bronze compatibility | Replacement |
|---|---|---|---|---|
| **Payroll run completes** | `ProcessPayrollJob`, dispatched by `PayrollController::process`, returns **202** with the run at `processing`; frontend polls | A queue worker must execute it, or a payroll run never leaves `processing` | **COMPATIBLE WITH CHANGE** | `POST /api/v1/cron/queue` — already built. But see the **execution-time risk** in the Risk Register |
| **Webhook delivery** | `DispatchWebhookJob`, `tries` with backoff to **24 h** | Worker | **COMPATIBLE WITH CHANGE** | Same. Note: a delivery deep in retry survives a queue drain — `docs/DEPLOYMENT.md` *Draining the queue before an upgrade* |
| **Biometric device polling** | `PullDeviceEventsJob` via `devices:sync`, every 5 minutes | Scheduler plus worker | **COMPATIBLE WITH CHANGE** | Same; 5-minute cadence needs a ≤ 5 min tick |
| **Missing-punch and anomaly scans** | `ScanMissingPunchesJob` 15:30, `ScanAttendanceAnomaliesJob` 15:45, per active tenant | Scheduler plus worker | **COMPATIBLE WITH CHANGE** | Same |
| **Leave accrual and carry-forward** | `AccrueLeaveBalancesJob` monthly, `CarryForwardLeaveBalancesJob` yearly | Scheduler plus worker | **COMPATIBLE WITH CHANGE** | Same. `retry_after` is 1200s, above the longest job timeout (900s), so no double-accrual — `BASELINE.md` §7a |
| **Scheduled reports and dashboard digests** | `RunScheduledReportsJob`, `RunDashboardDigestsJob`, both **hourly** on `exports` | Scheduler plus worker | **COMPATIBLE WITH CHANGE** | Same |
| **Approval reminders** | `SendApprovalRemindersJob` 06:00 | Scheduler plus worker | **COMPATIBLE WITH CHANGE** | Same |
| **Billing — invoice generation and dunning** | `GenerateMonthlyInvoicesJob` monthly 03:00, `HandleOverdueInvoicesJob` daily 04:00 | Scheduler plus worker | **COMPATIBLE WITH CHANGE** | Same. This is how the business bills |
| **Trial expiry notices** | `NotifyExpiringTrialsJob` daily 05:00 | Scheduler plus worker | **COMPATIBLE WITH CHANGE** | Same |
| **Announcement fan-out** | `NotifyAnnouncementAudienceJob` | Worker | **COMPATIBLE WITH CHANGE** | Same |
| **Data retention / cleanup** | `CleanupExpiredDataJob` daily 02:00 — notifications 90 d, webhook deliveries 30 d, import staging 7 d | Scheduler plus worker | **COMPATIBLE WITH CHANGE** | Same. **Load-bearing for the 5 GB quota** |
| **Backup** | `ethr:backup` daily 01:00, `BackupTenantJob` for tenant-scoped exports | Scheduler | **COMPATIBLE WITH CHANGE** | Same. Retention interacts with the quota — §6 |
| **Queue observability** | `QueueHealth::beat()` every minute; `ethr:queue:check`; `/api/v1/health` | Scheduler | **COMPATIBLE WITH CHANGE** | Same. This is what distinguishes *quiet* from *dead* |
| Queue driver | `QUEUE_CONNECTION=database` | A `jobs` table | **COMPATIBLE** | None needed. **Redis is not required** — no `predis`, no `ext-redis`, no `Redis::` call in `app/` |
| Worker supervision | `supervisor.conf` `[program:ethr-queue]`, `--max-time=3600` daemon | A process supervisor | **BLOCKED on Bronze** | `CronRunController::queue` runs `queue:work --stop-when-empty --max-time=50`, never a daemon, under a `Cache::lock` with a 409 on overlap |

**What must drive the runner is the open question, not the runner itself.**
`GATE-0-RESULT.md` G0-D records **FAIL — strong evidence, one confirmation short**: no
Scheduled Tasks / Task Scheduler / Cron Jobs section exists on the subscription
(owner-read 2026-09-18), and *Dev Tools* offers PHP, Git and Composer but **no Terminal**.
The HTTP endpoints exist precisely so that *anything* which fetches a URL on a timer can
drive them — see `docs/deployment/shared-hosting/cron-caller.md`. **Which timer is a
decision, not a code task**, and it is the single highest-severity open item in this audit.

---

## 4. Cache, session and locks

| Requirement | Current ETHR Implementation | Bronze Compatibility | Blocker | Evidence | Migration Needed |
|---|---|---|---|---|---|
| Cache | `CACHE_STORE=database` default | **COMPATIBLE** | — | `config/cache.php:18`; `api/.env.shared-hosting.example` marks it *LIVE-VERIFIED on MariaDB 2026-08-31* | No |
| Cache tagging | **Deliberately never used.** Three files carry explicit comments saying so, because the `database` store does not support tags | **COMPATIBLE** | — | `PlatformSetting.php:66`, `DashboardCacheVersion.php:15`, `SiteContentController.php:32`. Invalidation uses a **version key** instead | No |
| Locks (`withoutOverlapping`, cron mutex) | `Cache::lock` on the `database` store; `cache_locks` table exists | **COMPATIBLE** | — | `BASELINE.md` §8, commit `7e31519` | No |
| Session | `SESSION_DRIVER=database`, `SESSION_LIFETIME=480`, `SESSION_SECURE_COOKIE=true` | **COMPATIBLE** | — | `config/session.php:21`; `api/.env.shared-hosting.example` | No |
| Redis | **Not required at any layer** | **COMPATIBLE** | — | No `predis/predis` in `composer.lock`; no `ext-redis` platform requirement; no `Redis::` in `app/`. `api/.env.shared-hosting.example` annotates every `REDIS_*` line *"LEAVE EMPTY. Redis is not used here"* | No |
| **`api/.env.example` still selects Redis** | Lines 44–46 ship `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`; line 63 `FILESYSTEM_DISK=minio`; line 52 `BROADCAST_CONNECTION=reverb` | **COMPATIBLE WITH CHANGE** | — | The *config defaults* are all database-backed and inert, but a deployment that copies `.env.example` instead of `.env.shared-hosting.example` selects three unavailable drivers | Use the shared-hosting example. Deployment-doc concern, not code |

---

## 5. Frontend runtime

| Requirement | Current ETHR Implementation | Bronze Compatibility | Blocker | Evidence | Migration Needed |
|---|---|---|---|---|---|
| Node needed to **build** | `.nvmrc` = **24**; `next build` | **COMPATIBLE** — build offline, ship artefacts | — | `src/package.json`; `docs/deployment/shared-hosting/DEPLOYMENT.md` §1 *"Prepare the release locally"* | No |
| Node needed at **runtime** | `output: "standalone"` — `node server.js` | **UNVERIFIED, leaning COMPATIBLE** | Possible | `src/next.config.ts:24`. G0-G (2026-09-22): panel offers Node **22.23.2**, Application Startup File `app.js`, Application Mode `production`, custom env vars — **application-execution capable, nothing enabled or started** | Verify, then start |
| Node version mismatch | Build pinned at 24; host offers 22.23.2; the container runs 22 | **COMPATIBLE WITH CHANGE** | — | `.nvmrc` vs `docker/frontend/Dockerfile:1` vs G0-G. `BASELINE.md` §12d measured 22.23.2 at *2 failed, 3 passed* — **on the Vitest harness, which involves no application code** | Build on 24, run on 22 — already the container's shape |
| **`/api` reaching Laravel** **[P1]** | `.htaccess` rewrites `^/(api\|sanctum)` to `index.php` | **COMPATIBLE — mechanism VERIFIED** | **No — this is no longer a blocker** | `mod_rewrite` honoured (G0-B.1 PASS, `/REWRITE_OK` → 200); PHP-FPM answers `.php` in the document root; `Authorization` reaches PHP (G0-B.4 PASS). **Serving `/api` needs no reverse-proxy directive at all** | No |
| **Same-origin `/api` *from the frontend*** **[P1]** | `client.ts:14` `baseURL: "/api/v1"` (relative) resolved by `next.config.ts` rewrites | **CONDITIONAL** | Conditional | G0-A's cost was never about `/api` reaching Laravel (row above) — it is about **which process owns `/`**. `DEPLOYMENT.md` §5: if Plesk mounts the Node app at the domain root, `/api/v1/...` may never reach `index.php`. **Unresolved**; answered by enabling the app and fetching one route of each kind. If it fails: `GATE-0-RESULT.md:961` prices the cross-origin rebuild, **including `sw.js:59` being same-origin only**, at *"one to two weeks, and it needs an auth-security review"* | **Decision required** |
| Static export as fallback | Not configured. Would drop `middleware.ts` (hostname routing, locale negotiation) and the rewrites | **COMPATIBLE WITH CHANGE — with feature loss** | — | `src/src/middleware.ts`; `DEPLOYMENT.md` §5 *"Fallback — static export (B5 = no…)"* | Only if Node runtime fails |
| Offline-first service worker | `src/public/sw.js` | **COMPATIBLE** if same-origin holds; **BROKEN** if cross-origin | Conditional | `sw.js:59` `url.pathname.startsWith('/api/')` | Follows the G0-A decision |

---

## 6. Storage — the 5 GB constraint

| Growing store | Where it lives | Growth driver | Bounded? | Evidence |
|---|---|---|---|---|
| Employee photos, documents, logos | `storage/app/private/{tenantPrefix}/…` on the `local` disk | Per employee per document | **No cap in code.** Per-file: photos ≤ 512 KB, selfies ≤ 200 KB, re-encoded to bounded JPEG with thumbnails at 150 px and 400 px | `FileStorageService` `PHOTO_MAX_BYTES`, `SELFIE_MAX_BYTES`, `THUMBNAIL_SIZES` |
| Attendance selfies | Same | Per punch | **No cap** | `uploadDataUrlImage()` |
| Generated PDFs (payslips, reports, invoices) | dompdf, pure PHP | Per request | Streamed, not accumulated in the audited paths | `PayslipPdfService`, `ReportController`, `BillingController` |
| **Backups** | `storage/backups`, **uncompressed** | `BACKUP_KEEP` | **Yes — `BACKUP_KEEP=2`** | `config/backup.php:16`, `:41`. **`prune()` runs after `create()`, so at peak every document exists `keep + 2` = 4 times** |
| Logs | `storage/logs`, `LOG_STACK=daily`, `LOG_DAILY_DAYS=14`, `LOG_LEVEL=error` | Per error | **Yes** | `api/.env.shared-hosting.example` |
| Cache and sessions | **The database**, not the disk | Rows | Swept by TTL | `config/cache.php:42`, `session.php:89` |
| `api/vendor/` | Ships to the host | 163 packages, fixed | **Yes** | `composer.lock` |
| Frontend build output | `.next/standalone` plus `.next/static` | Fixed per release | **Yes** | `docker/frontend/Dockerfile` runner stage |
| `node_modules` | **Build machine only** — never shipped | — | **Yes** | `output: "standalone"` bundles what the server needs |

**Compatibility: COMPATIBLE WITH CHANGE, and the change is already made.** `BACKUP_KEEP`
was lowered from 7 to 2 for exactly this reason — at 7 the peak is nine copies of the
document store, at 2 it is four.

**The failure mode is the part that matters, and it is already written down correctly** in
`docs/deployment/shared-hosting/DEPLOYMENT.md` §6a: *"A full quota does not present as a
full disk. It presents as data corruption."* Every write under `storage/` fails while every
read keeps working — log writes fail first, so the event that would have told you goes
missing; uploads fail after the HTTP request already returned success; queued jobs fail
after their database rows commit; the backup that would let you undo it is what filled the
disk; and **`/api/v1/health` can still return 200, because it is not a disk check.**

**The real fix is off-host retention and it is not configured.** `--off-host` is wired
through `league/flysystem-aws-s3-v3` but the scheduled run does not pass it and no disk is
set up.

**Bandwidth (50 GB):** nothing in the repository measures or caps it. `.htaccess` sets
one-year immutable caching on static assets, which is the only mitigation present.
**UNVERIFIED** against real usage.

---

## 7. Subdomains — 5, against subdomain-based tenancy

| Requirement | Current ETHR Implementation | Bronze Compatibility | Blocker | Evidence | Migration Needed |
|---|---|---|---|---|---|
| Tenant selector | **Subdomain is *"the only selector honoured in production"*** — `{tenant}.ethr.et` | **BLOCKED today** | **Yes** | `ResolveTenant` class docblock and `resolveFromSubdomain()`; `middleware.ts` hostname model; `CORS_ALLOWED_ORIGINS_PATTERNS=#^https://[a-z0-9-]+\.ethr\.et$#` |
| Reserved hostnames | `www` (apex alias) and `admin` (platform host, resolves no tenant by any selector) | Consumes 2 of the allowance *if* each subdomain costs a slot | — | `ResolveTenant::isPlatformHost()`; `Tenant::RESERVED_SUBDOMAINS` |
| **1. Wildcard DNS** **[P1]** | A wildcard `A` record | **COMPATIBLE — VERIFIED** | — | `zzq7x-phase1-probe.ethr.et` and `another-random-cwq91.ethr.et`, never configured, both resolve to **213.55.96.154**, as do the apex, `www` and `admin`. Re-confirms 2026-08-29 / 2026-09-17 |
| **2. Wildcard vhost** **[P1]** | One vhost serving `*` | **CONDITIONAL — absent today, pending subscription activation** | **Commercial, not technical** | Measured 2026-09-25: `https://admin.ethr.et/` and `https://zzq7x-phase1-probe.ethr.et/` both **303 → `/login.php`** under the **`CN=lin6.ethiotelecom.et`** default certificate — any `*.ethr.et` host but the apex/`www` falls through to **the Plesk panel login**, `admin.ethr.et` included. **Owner statement 2026-09-25: *"wildcard is enable when i pay the subscription fee."*** Graded as **testimony, not panel output**, per the precedent `SHARED_HOSTING_PLAN.md` sets for the tier itself. **No ETHR change implied.** Re-measure after activation — [`BRONZE-BLOCKER-RESOLUTION.md`](BRONZE-BLOCKER-RESOLUTION.md) §5 has the two commands and what a real PASS looks like |
| **3. Wildcard TLS — issuance** **[P1]** | A certificate covering `*.ethr.et` | **COMPATIBLE — VERIFIED** | — | Let's Encrypt cert, `SAN: DNS:*.ethr.et, DNS:ethr.et`, valid **2026-09-16 → 2026-12-15**, served for the apex and `www`. **Contradicts `GATE-0-RESULT.md`'s *"wildcard blocked, needs DNS-01"*** — LE issues wildcards only over DNS-01, so that challenge has evidently been completed |
| **4. Wildcard TLS — in force on tenant hosts** **[P1]** | That cert bound to tenant hostnames | **BLOCKED — FAILED** | **Yes** | Tenant hostnames are served the **default** certificate, not the wildcard. Fails **because of row 2**, for no certificate reason |
| **5. Subdomain quota** | Whether `*` costs 1 slot, 5, or is outside the quota | **UNVERIFIED — `PANEL-VERIFICATION-REQUIRED`** | Unknown | **Not inferable, and deliberately not assumed.** Nothing in Phase 1 bears on it |

**Phase 1 measured this, and the four answers do not agree — which is why they are four
rows.** DNS resolves for any label; a valid `*.ethr.et` certificate already exists; **but no
wildcard vhost is configured**, so tenant hostnames land on the Plesk control-panel login
with a mismatched certificate. **`admin.ethr.et` — ETHR's reserved platform host — currently
belongs to Plesk.**

**The owner has since stated that wildcard is enabled on payment of the subscription fee**
(2026-09-25). That reclassifies the finding from *technical incompatibility* to *commercial
provisioning precondition* — **the single most consequential update of Phase 1**, because it
means no fallback tenant-selector strategy has to be designed and no ETHR code has to change.
It is owner testimony rather than a measurement, so the row stays **CONDITIONAL** until
re-measured; a wildcard certificate already issued with no vhost to bind it is at least
consistent with a feature provisioned but not switched on.

**The capacity arithmetic is a conditional, not a finding, and should not be quoted as one.**
*"5 subdomains minus `www` and `admin` leaves room for about three tenants"* holds only if
wildcards are unavailable **and** each tenant consumes a slot. Row 5 is unanswered, and row 3
makes the question live rather than academic: the wildcard certificate already exists, so if a
`*` vhost is permitted and counts as one slot, the capacity concern disappears and row 2
becomes a configuration task.

The `X-Tenant` header is **refused in production** by design, and the form-field selector only
chooses which tenant to *authenticate against* on login/register/password routes — neither is
a substitute for the hostname.

---

## 8. Email — 5 accounts

| Requirement | Current ETHR Implementation | Bronze Compatibility | Blocker | Evidence | Migration Needed |
|---|---|---|---|---|---|
| Transactional mail | `MAIL_MAILER=smtp`, `MAIL_FROM_ADDRESS=noreply@ethr.et` | **COMPATIBLE** — one mailbox suffices | — | `api/.env.shared-hosting.example` |
| Outbound 587/465 open | — | **UNVERIFIED** | **Yes if closed** — password reset, approval reminders, digests and scheduled reports all die silently | G0-H NOT VERIFIED |
| Send-rate cap | Nothing in the code respects one | **UNVERIFIED** | Possible | G0-H *mailbox send cap — known* row blank. Fan-out jobs (`NotifyAnnouncementAudienceJob`, `SendApprovalRemindersJob`) are the exposure |
| SMS | `SMS_DRIVER=log`, `SMS_DAILY_LIMIT_PER_USER=5`, Ethio Telecom endpoint blank | **COMPATIBLE** — inert until configured | — | `api/.env.shared-hosting.example`; `config/sms.php` |

---

## 9. Realtime — the one feature with no Bronze mechanism

| Requirement | Current ETHR Implementation | Bronze Compatibility | Blocker | Evidence | Migration Needed |
|---|---|---|---|---|---|
| WebSocket transport | `laravel/reverb` — a long-running `reverb:start` daemon | **BLOCKED** | **Yes** | `infrastructure/supervisor.conf` `[program:ethr-reverb]`; no shared-hosting process supervision exists |
| Broadcast driver | `BROADCAST_CONNECTION=null` on this target | **COMPATIBLE — degrades silently** | — | `config/broadcasting.php:40`; `api/.env.shared-hosting.example` |
| Events that go quiet **[P1 — corrected]** | **Three**: `AttendanceRecorded`, `DeviceOffline`, `EmployeeTransitioned`, all on `PrivateChannel("tenant.{id}")` | — | — | Read from each class declaration. **This row previously said five** — that figure came from `grep -rl ShouldBroadcast`, which matched the string inside **comments**. `DeviceSyncFailed`, `PayrollRunFailed`, `PayrollProcessed` and `TenantCreated` have **no `implements` clause**; `PayrollRunFailed`'s own comment reads *"broadcasting is deployed on neither target."* |
| Channels that go quiet | `user.{publicId}`, `tenant.{tenantId}`, `device.{devicePublicId}` | — | — | `routes/channels.php` |
| **Frontend subscribers** **[P1]** | **Exactly one** — `reverb-provider.tsx` subscribes to `echo.private(user.{userId})` for `BroadcastNotificationCreated`, mounted once at `dashboard-shell.tsx:31` | — | — | `echo.ts` and `reverb-provider.tsx` are the only two files touching Echo/Pusher. **Nothing subscribes to `tenant.*` or `device.*`**, so all three broadcasting events reach no UI on any target, Reverb running or not |
| **Polling fallback** **[P1]** | Already shipped | **COMPATIBLE** | — | `refetchInterval` on notifications (30 s), devices (30 s ×3), admin (30 s), team (60 s), payroll (conditional while processing). `reverb-provider.tsx` wraps its subscription in `try/catch` commented *"Reverb unavailable … fall back to polling"* |
| `BROADCAST_CONNECTION=log` as a fallback | **Explicitly forbidden on this target** | — | — | `HostingRequirementsConsistencyTest`: *"`log` writes a line per broadcast against a fixed disk quota, where filling the quota fails every write path including the database's"* |

**What is actually lost, measured rather than assumed:** the **notification toast** arrives on
the next 30-second poll instead of instantly. That is the whole of it. The three broadcasting
events publish to `tenant.{id}`, which **no frontend code subscribes to**, so they change
nothing user-visible on any target today.

**What is not lost:** the underlying data. Every broadcast accompanies a database write the
REST API already exposes, and polling is already the shipped default when Reverb is absent.
**The business feature survives; only its latency changes** — which is exactly the distinction
this audit is required to make rather than deleting the feature because its current transport
is VPS-only.

The three device/payroll notification listeners added on 2026-09-23 and 2026-09-25
(`NotifyDeviceOffline`, `NotifyDeviceSyncFailed`, `NotifyPayrollRunFailed`) are **queued
listeners writing database notifications**, not broadcasts — so the "someone is told when a
device or payroll run fails" feature works with `BROADCAST_CONNECTION=null`.

---

## 10. Deployment mechanics

| Requirement | Current ETHR Implementation | Bronze Compatibility | Blocker | Evidence | Migration Needed |
|---|---|---|---|---|---|
| Application outside the document root | Home sits above `httpdocs` | **COMPATIBLE — PASS** | — | `GATE-0-RESULT.md:895`: *"Home sits a level above `httpdocs`, so `.env` and `storage/` are unreachable over HTTP by construction"* — **VERIFIED** |
| Document root assembly | Three files in `httpdocs`, `index.php` repointed on three lines, `public_path()` consequences documented | **COMPATIBLE** | — | `docs/deployment/shared-hosting/DEPLOYMENT.md` §4a |
| `.htaccess` honoured **[P1]** | Deny rules, `Authorization` passthrough, `X-XSRF-Token` passthrough, ACME passthrough, security headers, one-year asset caching | **Mostly COMPATIBLE — VERIFIED** | Two rows open | **Eight canary fetches performed 2026-09-25** (the register's *"no fetch has been performed"* no longer holds). **B.1 mod_rewrite ✅**, **B.2(a) mod_headers ✅**, **B.3(a) `<FilesMatch>` deny ✅ 403**, **B.4 `Authorization` ✅**, **B.5 ✅ REWRITE WINS** on `.js` — the favourable outcome, so headers and `Cache-Control: immutable` do reach static assets. **Open:** B.3(b) and B.2(b) — see the row below |
| `.htaccess` — the two unanswered baits **[P1]** | `RewriteRule … [F,L]` deny (the mechanism the deployment actually uses) and CSP survival past Imunify | **UNVERIFIED** | **Possible, and B.3(b) blocks every branch if it fails** | The deployed canary is the **2026-09-18 revision (`eb239f2`)**, byte-matched by its header set; `secret.env.probe` 404s because it joined the set in `1edaad4` (2026-09-25). So the two baits added specifically to close a known false-pass are the two missing. **Re-upload the current canary and re-fetch — the cheapest outstanding measurement in this document** |
| `storage:link` symlink | `api/public/storage` untracked in git | **UNVERIFIED** | Possible | probe `ST5` NOT VERIFIED. Mitigation exists: switching `FILESYSTEM_DISK` off `local` removes the need |
| Deploy transport | `scripts/shared-hosting/deploy.sh`; Plesk Git and Composer present in Dev Tools | **COMPATIBLE** | — | panel read 2026-09-17 |
| Rollback | `docs/deployment/shared-hosting/rollback.md`; `ethr:restore` | **COMPATIBLE** | — | rehearsed on MariaDB in CI since 2026-09-23; **never rehearsed on the Ethio Telecom host** |
| Post-deploy smoke test | `scripts/shared-hosting/smoke-check.sh` | **COMPATIBLE** | — | — |

---

## Summary

**Not blockers, and the architecture deserves credit for it:** one database, database-backed
queue/cache/session/locks, no Redis, no Horizon, no Octane, no shell-outs, storage backend
as a pure config switch, a host-neutral backup command, and an HTTP cron runner that already
exists.

**Genuine blockers, re-ordered after Phase 1:**

1. **No timer on the host to drive `schedule:run`** (G0-D FAIL, 2026-09-18; unchanged, and
   not observable over HTTP). The runner exists; nothing starts it. **This is now the top
   technical blocker.**
2. **Reverb cannot run.** Accepted degradation — and Phase 1 measured the cost as one toast's
   latency, not a feature.

**Blocked on provisioning rather than technology:** the **wildcard vhost**. Measured absent,
and the owner states it activates on subscription payment. It was the top blocker until that
statement; it is now a precondition with no code implication. **Still to be re-measured** —
it is not VERIFIED on testimony alone.

**No longer a blocker:** `/api` reaching Laravel. `mod_rewrite`, PHP-FPM and `Authorization`
passthrough are all verified, so **no reverse-proxy directive is required.** What survives of
G0-A is the narrower question of **which process owns `/`** once the Node app is enabled.

**The largest unknowns that are not code questions:** `CREATE TRIGGER` (favourable prior,
fatal if wrong, settled by one probe run) and **B.3(b)**, the `[F,L]` deny mechanism that
protects `api/.env` — settled by one fetch after re-uploading the current canary.

---

## Related

- [`BRONZE-BLOCKER-RESOLUTION.md`](BRONZE-BLOCKER-RESOLUTION.md) — **Phase 1 evidence, exact tests, and eight documentation discrepancies**
- [`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md)
- [`../deployment/shared-hosting/DEPLOYMENT.md`](../deployment/shared-hosting/DEPLOYMENT.md)
- [`VPS-SHARED-HOSTING-MATRIX.md`](VPS-SHARED-HOSTING-MATRIX.md)
- [`../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md)
