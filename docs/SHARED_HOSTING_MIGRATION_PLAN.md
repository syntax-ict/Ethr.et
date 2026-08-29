# ETHR — Shared-Hosting Migration Plan (Phase 3)

**Date:** 2026-08-29
**Status:** DECISION PENDING. No implementation has started.
**Inputs:** `docs/SHARED_HOSTING_AUDIT.md`,
`docs/ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`

---

## 1. The decision this document exists to make

ETHR currently runs as 13 Docker services on a VPS. The request is to move it to
Ethio Telecom **shared** hosting. The audit found that the application itself is far
more portable than its deployment suggests — but portability is not the constraint.
The constraint is five hosting capabilities nobody has confirmed yet.

The options below are ranked by how much of ETHR survives, not by how little work
they are.

---

## 2. Options

### OPTION A — Stay on the VPS (do nothing)

**Architecture:** unchanged.

| | |
| --- | --- |
| Functionality preserved | 100% |
| Work | none |
| Risk | none |
| Cost | current VPS |

**When this wins:** if capability B1 (wildcard subdomain) or B3 (cron) comes back
negative. In that case shared hosting cannot host a multi-tenant SaaS with scheduled
payroll and leave processing, and no amount of adaptation changes it.

This option is listed first deliberately. It is the correct answer if the blocking
questions resolve badly, and "we already have a working deployment" is a real asset.

---

### OPTION B — Shared hosting, minimal change

Everything on the Ethio Telecom Linux + MySQL plan. Requires **B1–B4** to all pass.

```
                          Browser
                             │  https://{ethr.et | admin.ethr.et | *.ethr.et}
                             ▼
              ┌──────────────────────────────┐
              │  Ethio Telecom shared host   │
              │  Plesk · Apache/nginx · PHP  │
              │  docroot → api/public        │
              │                              │
              │  .htaccess:                  │
              │    /api, /sanctum  → index.php (Laravel)
              │    everything else → SPA
              └───────┬──────────────┬───────┘
                      │              │
                 MySQL (1 DB)   storage/app  (or external S3)
                      ▲
                      │  cron (every minute)
              ┌───────┴───────────────────────┐
              │ php artisan schedule:run      │  ← 11 scheduled entries
              │ php artisan queue:work        │  ← 15 job classes
              │     --stop-when-empty         │
              │     --max-time=55             │
              └───────────────────────────────┘

  Removed: Redis · Horizon · Reverb · MinIO · read replica · Docker · nginx conf
  Degraded: live WebSocket toasts → existing 30 s poll
            Horizon dashboard → `failed_jobs` table + logs
```

Two sub-variants, decided by capability **B5**:

#### B1 — Node.js runtime available

Deploy the Next.js `standalone` build under the Plesk Node.js extension. `.htaccess`
proxies non-`/api` traffic to the Node process.

- **Frontend code change: none.** `middleware.ts` and the `headers()` auth layout keep
  working exactly as today.
- Risk: Plesk Node.js apps are Passenger-managed and can be restarted aggressively;
  Next 16 needs Node 20.9+.

#### B2 — No Node.js runtime → static export

Switch to `output: "export"` and serve the built HTML/JS as static files. Justified by
the audit: 107 of 126 page files are already `"use client"`, there are **zero** API
routes and **zero** server actions.

Required changes, all scoped and named:

| Change | File | Reason |
| --- | --- | --- |
| `output: "export"`, drop `rewrites()`, drop `headers()` (CSP moves to `.htaccess`) | `src/next.config.ts` | Static export supports neither. |
| Delete `middleware.ts`; reimplement the host rules in `.htaccess` | `src/src/middleware.ts` | Not executed under static export. Its own comment already names nginx as the authoritative control. |
| Read host client-side instead of via `headers()` | `src/src/app/(auth)/layout.tsx` | Accepts a first-paint flash on the tenant login page in exchange for removing the last SSR dependency. |
| `generateStaticParams` returning `[]` + client-side param read | `employees/[id]`, `payroll/[id]`, `devices/[id]`, `admin/tenants/[id]` | Static export requires known params or a shell page. |

**Cost of B2:** the SPA becomes fully client-rendered. Marketing pages lose SSR (SEO
impact on `/`, `/features`, `/pricing`, `/faq`, `/contact` — worth stating explicitly to
the business). No authenticated feature is affected: those pages were already client
components fetching over `/api/v1`.

#### Backend changes required in Option B (both variants)

Environment only, except where a file is named:

```diff
- CACHE_STORE=redis          + CACHE_STORE=database
- QUEUE_CONNECTION=redis     + QUEUE_CONNECTION=database
- SESSION_DRIVER=redis       + SESSION_DRIVER=database
- BROADCAST_CONNECTION=reverb + BROADCAST_CONNECTION=log
- FILESYSTEM_DISK=minio      + FILESYSTEM_DISK=local   (or an external S3 endpoint)
- DB_READ_HOST=...           + (unset)
- REDIS_* / REVERB_* / MINIO_*  + (unset or repointed)
```

Code changes — **four sites, three files**, each justified by the audit:

| File | Change | Why |
| --- | --- | --- |
| `api/app/Services/FileStorageService.php:44` | `$this->disk = config('filesystems.default')` | Currently hardcodes `'minio'`, so `FILESYSTEM_DISK` is ignored on every upload and download path. |
| `api/app/Http/Controllers/Api/V1/HealthController.php:29,30,43` | Use the configured cache store and disk | Otherwise `/api/v1/health` reports permanently unhealthy. |
| `api/app/Console/Commands/HealthCheckCommand.php:20` | Same | Otherwise `health:check` always fails. |

Plus one migration guard, conditional on capability **H1**:

| File | Change | Why |
| --- | --- | --- |
| `database/migrations/2026_07_22_000001_restrict_audit_log_to_insert_only.php` | Wrap `CREATE TRIGGER` in try/catch that logs a loud warning, matching the pattern the same file **already uses** for the PostgreSQL branch | Without `SUPER` on a binlog-enabled managed MySQL this raises error 1419 and aborts the entire `migrate` run. The append-only guarantee then rests on application-level enforcement, and **that trade-off must be recorded** — it is a security control, and downgrading it is a decision for the owner, not for the deployment. |

**Nothing else changes.** No business logic, no schema, no API contract, no domain
model, no tenancy model, no payroll calculation, no Ethiopian calendar, no localisation.

| | |
| --- | --- |
| Functionality preserved | ~95% |
| Lost | Horizon dashboard, live WebSocket push (falls back to existing poll), sub-minute queue latency, read replica; under B2 also marketing-page SSR |
| At risk | audit-log DB triggers (H1); payroll/import runtime under `max_execution_time` (H2) |
| Work | Small backend, moderate deployment, plus a scoped frontend refactor if B2 |
| Risk | **High until B1–B5 are answered** |
| Cost | one shared plan (tier 3–4 for storage headroom) |

---

### OPTION C — Hybrid: shared hosting + a small VPS for what shared hosting cannot do

Shared hosting serves the web tier; one Ethio Telecom VPS Gold (4 GB, ETB 10,379/yr)
carries the components that need a long-running process.

```
        Browser ──────────────► Shared host  (Plesk, PHP, docroot → api/public)
           │                          │  full Laravel API + SPA
           │                          ▼
           │                    MySQL on the shared plan
           │                          ▲
           │                          │  same DB, same code, cron-free
           └──── wss://realtime ──► VPS Gold
                                      ├── Reverb        (WebSockets)
                                      ├── Horizon       (Redis queues, real workers)
                                      ├── Redis
                                      ├── scheduler     (schedule:work)
                                      └── MinIO         (object storage)
```

**This option is listed because the brief asks for it, and it must be assessed
honestly rather than adopted by default.**

The hybrid only helps if the shared host can reach the VPS's MySQL/Redis over the
public internet, and vice versa. That means:

- Exposing MySQL or Redis across the internet between two providers (or a VPN between
  them, which shared hosting cannot terminate).
- Every DB query from the web tier crossing a network hop it does not cross today.
- Two `.env` files, two deploy paths, two backup regimes for one application.

And critically: **once a VPS is in the picture, Option A is strictly better.** The VPS
in this hybrid is doing the hard part — Redis, workers, WebSockets, storage — while the
shared host contributes only PHP execution that the same VPS could do for free. The
hybrid costs more than the VPS alone, is harder to operate, and is slower.

| | |
| --- | --- |
| Functionality preserved | ~100% |
| Work | Highest of the three |
| Risk | Highest — cross-provider network dependency on the hot path |
| Cost | shared plan **plus** ETB 10,379/yr VPS |

**Recommendation: reject Option C** unless the requirement is specifically "the domain
and web tier must be hosted at Ethio Telecom" for a reason other than cost. If that
requirement exists, say so and this becomes the right answer.

---

## 3. Comparison

| | **A — VPS** | **B — Shared** | **C — Hybrid** |
| --- | --- | --- | --- |
| Product features preserved | 100% | 100% | 100% |
| Realtime push | Native | Degrades to 30 s poll | Native |
| Queue latency | Sub-second | 1 min (cron) | Sub-second |
| Queue observability | Horizon UI | `failed_jobs` + logs | Horizon UI |
| Audit-log DB triggers | Yes | **At risk (H1)** | Yes |
| Wildcard tenant subdomains | Yes | **At risk (B1/B2)** | At risk |
| Payroll execution headroom | 1800 s | **At risk (H2)** | 1800 s |
| Marketing-page SSR | Yes | B1 yes / **B2 no** | Yes |
| Application code changed | 0 files | **3 files, 4 sites** (+4 frontend files if B2) | 3 files |
| Business logic changed | None | **None** | None |
| Schema changed | None | **None** | None |
| API contract changed | None | **None** | None |
| Operational complexity | Medium | Low | **High** |
| Cost | VPS | Lowest | Highest |

---

## 4. Recommendation

**Conditional Go for Option B, gated on the five blocking capabilities.**

The audit's central finding is that ETHR is far better positioned for this migration
than its 13-service deployment implies. Redis, Horizon, Reverb and MinIO are all
*configuration*, not *architecture*: there is not a single `Redis::` call in
`app/`, the broadcast leg is already guarded by a config check in twelve notifications,
notifications already poll, and tenancy is a single database with a global scope.
The entire hardcoded-infrastructure surface is four lines in three files.

That is the argument for attempting Option B rather than declaring shared hosting
unsuitable.

But the decision cannot be made yet, because the failure modes are not in the code:

| Gate | If it fails |
| --- | --- |
| **B1** wildcard subdomain | **No-Go.** Self-service tenant signup produces unreachable tenants. Path-based tenancy is a product redesign, explicitly out of scope. → Option A |
| **B2** wildcard TLS | **No-Go.** `SESSION_SECURE_COOKIE=true` means auth cookies are withheld over an untrusted connection; tenants cannot log in. → Option A |
| **B3** cron | **No-Go.** Leave accrual, invoicing, anomaly scanning, cleanup and every queued email stop. → Option A |
| **B4** PHP ≥ 8.2 | **No-Go.** Laravel 12 does not boot. → Option A |
| **B5** Node.js runtime | **Not fatal.** Fails → Option B2 (static export), a scoped, costed frontend refactor. |
| **H1** `CREATE TRIGGER` | **Not fatal, but a security downgrade that needs sign-off.** |
| **H2** `max_execution_time` | **Not fatal.** Mitigation: move payroll processing fully behind the queue so the HTTP request only enqueues. |

**Go / No-Go: BLOCKED — pending answers to B1–B5.**

Nothing in this repository can answer them. They require the account, or a written
answer from Ethio Telecom support. The verification procedure is in
`docs/ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md` §5.

---

## 5. If approved — implementation order

Not started. Listed so the shape of the work is visible before approval.

| # | Commit group | Contents | Depends on |
| --- | --- | --- | --- |
| 1 | `migration/audit` | These three documents + `docs/MIGRATION_STATE.md` | — |
| 2 | `migration/config` | Driver-agnostic health check and `FileStorageService` disk; conditional trigger migration guard | H1 |
| 3 | `migration/deployment` | `deployment/shared-hosting/` — `.htaccess`, env template, deploy checklist, health-check and rollback docs | B1–B4 |
| 4 | `migration/frontend` | Static export **only if B5 fails** | B5 |
| 5 | `migration/queue` | Cron entries for `schedule:run` and `queue:work --stop-when-empty` | B3 |
| 6 | `migration/storage` | Local-disk or external-S3 configuration; verify `temporaryUrl` signing | — |
| 7 | `migration/security` | `.htaccess` denials for `.env`, `.git`, `storage/`, `vendor/`; disable Scramble in production; verify security headers survive the nginx → `.htaccess` move | — |
| 8 | `migration/tests` | Full backend + frontend suites, then the 24 acceptance tests from the brief | all |
| 9 | `migration/docs` | `DATABASE_MIGRATION_PLAN.md`, `DEPLOYMENT_RUNBOOK.md`, `ROLLBACK_RUNBOOK.md`, `PRODUCTION_CHECKLIST.md`, `MIGRATION_CHANGELOG.md` | all |

Rollback throughout is trivial and is the reason this is worth attempting: the VPS
deployment is untouched. DNS is the switch. `scripts/rollback.sh` and
`scripts/restore.sh` already exist.

---

## 6. Estimated complexity

| Area | Complexity | Note |
| --- | --- | --- |
| Backend code | **Very low** | 4 lines across 3 files, plus one conditional migration guard. |
| Backend configuration | **Low** | One `.env`. Every driver swap is already supported. |
| Database migration | **Low–Medium** | `mysqldump` → import. One privilege risk (H1). |
| Frontend (B1, Node available) | **None** | Deploy the existing standalone build. |
| Frontend (B2, static export) | **Medium** | 4 named files, plus `.htaccess` reimplementation of the middleware host rules and the CSP. |
| Deployment tooling | **Medium** | Every existing script assumes Docker + SSH; all need shared-hosting equivalents. |
| Testing | **Medium** | Suites run unchanged; the 24 acceptance tests must run against the real host. |
| **Overall** | **Medium** — *conditional on the gates* | The application is ready. The hosting environment is unverified. |
