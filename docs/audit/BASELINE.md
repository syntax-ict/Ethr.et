# ETHR — Phase 0 Forensic Baseline

**Date:** 2026-09-15
**Tree audited:** `F:\et` — confirmed by the owner as the project
**Mode:** Read-only inspection. No application code changed. No remote git accessed.
**Next phase:** Phase 0.5 (repository/security hygiene). **Not started.**

---

## How to read this document

Every claim is typed. Nothing is asserted that was not seen.

| Tag | Meaning |
|---|---|
| **[verified]** | Observed in this tree during this pass, with a `path:line` or a command and its output |
| **[documented-done]** | A repo document claims it is done; not independently confirmed here |
| **[documented-planned]** | A repo document describes an intention, not a state |
| **NOT VERIFIED** | Not measurable from this tree — typically requires the hosting account |
| **NOT MEASURED** | Measurable in principle, but the prerequisite was unavailable this pass |

Per master plan §8, unverified hosting behaviour is never recorded as fact.

---

## 1. Repository state *(§65.1)*

**[verified]** — local inspection only, no fetch:

```
branch : main
HEAD   : 99aa6517bd4b40bfbd27c25aebb1557fea2ba47d
         2026-08-31 08:14:29 +0300
         "Merge: live-verify CACHE_STORE=database locking"
ahead of origin/main : 19 commits (local ref comparison; no network)
```

**Uncommitted working tree:**

```
 D deployment/shared-hosting/{.htaccess,DEPLOYMENT.md,ENVIRONMENT.md,
                              README.md,deploy-checklist.md,
                              health-check.md,rollback.md}
 D docs/VPS_DEPLOYMENT.md
?? docs/deployment/
```

All seven deleted files reappear byte-identically under `docs/deployment/shared-hosting/` **[verified]** — a clean content move. `docs/VPS_DEPLOYMENT.md` (20 KB) is a deletion with **no successor anywhere in the tree** **[verified]**. Left untouched: Phase 1 work (§52 forbids mixing it into this commit).

Local branches: 55, of which 49 are `claude/*` working branches. `origin/main` is the only remote-tracking ref.

### 1a. Tree identity — read this before assuming context

Master plan §5 names a different repository. All five identity markers fail against this tree **[verified]**:

| | §5 expects | This tree |
|---|---|---|
| Remote | `syntax-ict/Ethr` | `syntax-ict/Ethr.et.git` |
| Path | `C:\xampp\htdocs\Ethr-main` | `F:\et` |
| Branch | `work/frontend-audit-v2` | `main` |
| Baseline | `40805a0` | `99aa651` |
| `40805a0` resolvable | — | `fatal: Not a valid object name` |

**`C:\xampp\htdocs\Ethr-main` exists on this machine and is an unrelated product** **[verified]**: Laravel in `backend/` (not `api/`), **Vite 6 + React 19** (not Next.js), `bun.lock`, `dist/`, root `CLAUDE.md`. A third tree, `C:\xampp\htdocs\Ethr-git-verify`, is that same repository on `main` at `d81d09c`. A fourth, `ethr-access`, is an earlier unrelated project.

The owner has confirmed `F:\et` is the project. **§5's identity block is stale and should be corrected to `F:\et` / `main` / `syntax-ict/Ethr.et.git`** — flagged for Phase 1.

> Any future session must confirm which tree it is in before reusing analysis. This pass began against the wrong assumption and the mistake was caught only by the §5 identity check.

---

## 1b. Architecture overview *(§65.2)*

**The directory naming is inverted from what it looks like** **[verified]**:

- **`api/`** is the entire Laravel backend — not a thin API layer
- **`src/`** is the Next.js *project root*; actual frontend source is nested at **`src/src/`**

Measured inventory **[verified]**:

| Item | Count |
|---|---|
| Eloquent models (`api/app/Models/*.php`) | **69** |
| Migrations (`api/database/migrations/*.php`) | **55** |
| Controllers (`api/app/Http/Controllers/**`) | **102** |
| Queued job classes (`api/app/Jobs/*.php`) | **15** |
| PHP test files (`api/tests/**/*Test.php`) | **141** |
| Vitest files (`src/src/test/**`) | **70** |
| Playwright specs (`src/e2e/*.spec.ts`) | **12** |
| Markdown docs (`docs/**/*.md`) | **53** |

> These differ from figures circulating in the repo's own documents (which variously say 67 models, ~92 controllers, 144 tests). The numbers above are from `find`/`ls` in this tree today.

**Request path:** browser → Next.js `rewrites()` (`src/next.config.ts:88-100`) → Laravel `/api/v1` → controller → service → model. The frontend axios client uses a **relative** `baseURL: "/api/v1"` (`src/src/api/client.ts:14`) **[verified]**, so the rewrite is load-bearing for all API traffic.

**API contract is generated, not hand-written:** Scramble exports `api/openapi.json` (1.8 MB) → `src/scripts/generate-api.mjs` → `src/src/api/generated.ts`, with `scripts/api-types-check.sh` as a drift gate **[documented-done]**.

**Domain layer** lives in `api/app/Services/`: `Payroll/` (`PayrollEngine`, `TaxCalculator`, `PensionCalculator`, `OvertimeCalculator`, `LoanService`, `CostSharingService`, `PayslipPdfService`), `Attendance/` (`AttendanceEngine`, `ConflictResolver`, `ShiftMatcher`), `Leave/`, `Calendar/EthiopianCalendar`, plus Billing, Analytics, Report, Identity, Migration, Onboarding, Sso, Webhook.

---

## 2. Framework and runtime versions

**[verified]**

| Component | Version |
|---|---|
| PHP | `^8.2` (`api/composer.json`) |
| Laravel | `^12.0` |
| Next.js | `^16.2.9` (`src/package.json`) |
| React | `^19.2.7` |
| TypeScript | `^5.9.3` |

**Drift:** `docs/CLAUDE.md`'s "locked v1.0 stack" table states **Next.js 15**, while the lockfile and README say **16**. The table is stale on that row. *(Phase 1)*

---

## 3. Composer dependencies

Production: `laravel/framework`, `laravel/sanctum` ^4.3, `laravel/horizon` ^5.47, `laravel/reverb` ^1.4, `dedoc/scramble` ^0.13, `barryvdh/laravel-dompdf` ^3.1, `league/flysystem-aws-s3-v3`, `pragmarx/google2fa`, `sentry/sentry-laravel`.

Dev: `pestphp/pest`, `phpunit/phpunit` ^11.5.50, `larastan/larastan`, `laravel/pint`, `laravel/pail`, `laravel/sail`, `mockery`, `fakerphp/faker`.

### 3a. ~~BLOCKER~~ — Horizon hard-requires two extensions shared hosting rarely has — **RESOLVED**

> **Resolved, and this section was left reading as live. Corrected 2026-09-18.**
>
> Horizon was **removed** in `cdf85d1`. Re-verified today: `laravel/horizon` appears in
> neither `composer.json` (`require` *or* `require-dev`) nor `composer.lock` (`packages`
> *or* `packages-dev`), and a parse of every production package's platform requirements
> finds **zero** hard `ext-pcntl` / `ext-posix` requires.
>
> §15 row 6 of *this document* already records it as Resolved with the lockfile parsed.
> §3a and §13c did not carry the same note, so the same file said both things — and
> `docs/CLAUDE.md` was repeating the live version, which is the copy every session reads
> first.
>
> **Also checked, so it is not left hanging:** `SystemHealthService.php` and
> `QueueHealth.php` still mention Horizon, but only in comments explaining its removal.
> No code calls a Horizon class, so there is no runtime consequence.
>
> The evidence below is preserved as the original finding. The line reference no longer
> resolves, because the package is gone.

**[verified at the time]** `api/composer.lock:1999-2001`:

```json
"name": "laravel/horizon",
"require": { "ext-json": "*", "ext-pcntl": "*", "ext-posix": "*", ... }
```

These are `require`, **not** `suggest`. `composer install --no-dev` **fails outright** on any host without `pcntl`/`posix` — the normal case for shared PHP-FPM.

Nothing in the application depends on Horizon: `HorizonServiceProvider::class` is registered in `api/bootstrap/providers.php` **[verified]**, but there is **no `app/Providers/HorizonServiceProvider.php`**, so the `viewHorizon` gate is never defined and `/horizon` is unreachable outside `local` regardless.

**These are the only hard extension blockers in the entire dependency tree.**

---

## 4. npm dependencies

Radix UI primitives, `@tanstack/react-query` ^5, `@tanstack/react-table` ^8, `axios`, `react-hook-form` + `zod` ^4, `laravel-echo` + `pusher-js`, `recharts`, `html5-qrcode`, `@sentry/nextjs`, Tailwind ^4.3 via `@tailwindcss/postcss`.

`src/package.json` carries a hand-maintained `overrides` block pinning `postcss`, `sharp` and `nanoid`, with an `_overridesNote` recording the security rationale and a revisit condition. **Nothing re-checks that condition** — it will rot silently.

---

## 5. Database drivers — §4 finding

**[verified] There is no production PostgreSQL dependency in this codebase.**

- `DB_CONNECTION=mariadb` in both `api/.env.example` and `api/.env.production.example`
- `config/database.php` ships stock `pgsql`/`sqlsrv` connection blocks; **nothing selects them**
- Tests use SQLite in-memory (`api/phpunit.xml`)
- The only `pgsql` code path is a defensive branch inside the audit-log migration

**No PostgreSQL-to-MySQL conversion project is required.** Master plan §4's instruction to avoid an unnecessary conversion is satisfied by doing nothing.

**Migration scan (55 files)** **[verified]**:

| Feature | Present |
|---|---|
| `DB::statement` / `DB::unprepared` | 2 files only |
| Triggers | **2** (audit-log immutability — see §13b) |
| Fulltext index | 1 (`employees`) |
| JSON columns | 32 across 19 migrations |
| Stored procedures | none |
| Generated / virtual columns | none |
| CHECK constraints | none |
| Window functions / CTEs | none |
| JSON query operators | none |

The schema is materially more portable than the tooling around it.

**Index-length risk:** no `Schema::defaultStringLength()` override exists, so `string()` is `varchar(255)` at `utf8mb4` = 1020 bytes. `cache.key`, `sessions.id` and `job_batches.id` are 255-char primary keys. Fine on InnoDB `DYNAMIC` (MySQL >= 5.7.9 / MariaDB >= 10.2), but `migrate` fails on the *first* migrations under the older 767-byte limit. **Minimum server version must be confirmed on the host — NOT VERIFIED.**

---

## 6. Redis and Horizon dependencies

**[verified]** Redis coupling is **configuration-only**. A grep of `app/`, `routes/`, `bootstrap/` and `config/` (excluding `config/database.php` and `config/horizon.php`) for `Redis::`, `Illuminate\Support\Facades\Redis` and `Cache::lock` returns **nothing**. No application code calls Redis.

Horizon: see §3a. Removal is a package change plus one line in `bootstrap/providers.php`.

---

## 7. Queue architecture

**15 job classes.** Declared timeouts **[verified]**:

| Job | Timeout |
|---|---|
| `BackupTenantJob` | **900s** |
| `AccrueLeaveBalancesJob` | 600s |
| `CarryForwardLeaveBalancesJob` | 600s |
| `RunScheduledReportsJob` | 600s |
| `RunDashboardDigestsJob` | 600s |
| `ScanAttendanceAnomaliesJob` | 300s |
| `ScanMissingPunchesJob` | 300s |
| `SendApprovalRemindersJob` | 300s |
| `PullDeviceEventsJob` | 120s |
| `DispatchWebhookJob` | 30s |
| `CleanupExpiredDataJob`, `GenerateMonthlyInvoicesJob`, `HandleOverdueInvoicesJob`, `NotifyAnnouncementAudienceJob`, `NotifyExpiringTrialsJob` | not declared |

**Queue names in use:** `attendance`, `notifications`, `exports`, `default`. A bare `queue:work` reads only `default` (`DB_QUEUE=default`), so the other three would never drain.

### 7a. ~~DEFECT — `retry_after` is below five job timeouts~~ — **fixed 2026-09-15** (`76ca983`)

> Raised to 1200s, above `BackupTenantJob`'s 900s. Five jobs that declared **no** timeout at all now do — including `GenerateMonthlyInvoicesJob`, which is how the business bills; they had been silently inheriting the worker's 60s default, which also made the invariant uncomputable.
>
> `tests/Feature/QueueRetryAfterInvariantTest.php` asserts the invariant by reflection over the job classes, so a future long-running job fails the suite rather than quietly reintroducing the bug. Proven to fail at the old value.
>
> The original text follows, for the record.

**[verified]** `api/config/queue.php:43`:

```php
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
```

90 seconds, against job timeouts of 300–900 seconds. Under the database queue driver a job still running at 90s is **re-reserved and executed a second time**. Affects backups, leave accrual, carry-forward, scheduled reports and dashboard digests — that is duplicate leave entitlement and duplicate backup writes.

This is a live correctness defect on any host using `QUEUE_CONNECTION=database`, not a shared-hosting-specific one.

---

## 8. Scheduled tasks

**12 entries** in `api/routes/console.php` **[verified]**: `devices:sync` every 5 min; missing-punch scan 15:30; anomaly scan 15:45; leave accrual monthly 1st 00:30; carry-forward yearly 1 Jan; expiring-trial notices 05:00; scheduled reports hourly; dashboard digests hourly; approval reminders 06:00; invoice generation monthly 1st 03:00; overdue invoices 04:00; cleanup 02:00.

Eight use `->withoutOverlapping()`, which is cache-lock backed. The `cache_locks` table exists, and `CACHE_STORE=database` locking was live-verified against real MariaDB **[documented-done]**, commit `7e31519`.

---

## 9. Filesystem assumptions

**[verified]**

- `api/app/Services/FileStorageService.php:64` resolves `config('filesystems.default')` — previously the literal `'minio'`, fixed in `80cac67`. **Storage backend is now a pure config switch**; preserve that property, it is the escape hatch if local disk proves too small.
- `api/app/Services/Admin/SystemHealthService.php:66` **still hardcodes `Storage::disk('minio')`** — missed by `80cac67`. It sits inside `try/catch`, so it degrades to "unhealthy" rather than 500: the admin health page will show storage permanently red on any non-MinIO deployment. That page is the first thing an operator checks.
- `api/public/storage` is a symlink to the Docker-absolute `/var/www/api/storage/app/public`. **It is not tracked in git** (`git ls-files` returns nothing), so it will not ship broken — but `storage:link` must run on the host.
- No writes outside `storage/` and `bootstrap/cache`. No `exec`, `shell_exec`, `proc_open`, `symlink` or `putenv` anywhere in `app/`.

---

## 10. Environment variables

**[verified]** The shipped templates contradict the config-file defaults:

| Key | `config/*.php` default | `api/.env.production.example` |
|---|---|---|
| `CACHE_STORE` | `database` | `redis` (line 73) |
| `QUEUE_CONNECTION` | `database` | `redis` (74) |
| `SESSION_DRIVER` | `database` | `redis` (75) |
| `FILESYSTEM_DISK` | `local` | `minio` (109) |
| `BROADCAST_CONNECTION` | `reverb` | `reverb` (95) |
| `DB_CONNECTION` | `sqlite` | `mariadb` (42) |
| `DB_HOST` | — | `mariadb` (43) |
| `MAIL_MAILER` | `log` | `smtp` (121) |

"It defaults to database" is true of the code and **false of the shipped templates**. Any non-Docker deployment needs an explicit template.

**Trap:** `BROADCAST_CONNECTION=null` must not be used. Laravel's `env()` converts the string `null` to PHP `null`, returning it *instead of* the default, and `BroadcastManager` then throws `Broadcast connection [] is not defined`. Use `log`, which also satisfies the 12 notification classes guarding on `config('broadcasting.default') === 'reverb'`.

---

## 11. Frontend architecture *(§65.7)*

**[verified]** Server-side feature census across 78 pages:

| Feature | Count |
|---|---|
| Route handlers (`route.ts`) | **0** |
| Server Actions (`'use server'`) | **0** |
| `cookies()` / `draftMode()` / `unstable_cache` / `revalidate*` | **0** |
| `next/image` usages | **0** |
| ISR / `export const revalidate` | **0** |
| Async server components doing server work | **1** — `src/src/app/(auth)/layout.tsx`, reading `headers()` |
| Dynamic `[id]` routes without `generateStaticParams` | 4 |

`src/next.config.ts` sets `output: "standalone"` (line 24), defines CSP/HSTS/X-Frame-Options/Permissions-Policy in `headers()` (33-77), and proxies `/api/*` and `/sanctum/*` via `rewrites()` (88-100).

**The application is already substantively a client-rendered SPA wearing a Next.js server shell.** The binding constraint is not server components — it is that `src/src/api/client.ts:14` uses a **relative** `baseURL`, so all API traffic depends on the `rewrites()` proxy. Any deployment without that proxy must supply an equivalent at the web-server layer.

`src/src/middleware.ts` performs admin-host redirects only, is self-declared non-authoritative (lines 19-21: *"Nginx … That is the authoritative control"*), and is **inert in production** — `src/.env.production` does not set `NEXT_PUBLIC_ROOT_DOMAIN`, and line 32 returns early without it.

Service worker `src/public/sw.js` registers at `scope: "/"` (`src/src/lib/hooks/useServiceWorker.ts:24`) and precaches absolute paths — **the app must be served from an origin root**, never a subdirectory. It intercepts `/api/` same-origin only (line 59), so a cross-origin API would silently disable its offline cache.

---

## 11b. Tenant isolation *(§65.8 — P0 per §27)*

### Scope establishment: fail-closed **[verified]**

`api/app/Traits/BelongsToTenant.php:17-25`:

```php
static::addGlobalScope('tenant', function (Builder $builder): void {
    $current = app(CurrentTenant::class);
    if ($current->resolved()) {
        $builder->where($builder->getModel()->getTable().'.tenant_id', $current->id());
    } else {
        $builder->whereRaw('0 = 1');
    }
});
```

**No tenant context yields no rows, not all rows.** This satisfies §28 Layer 1 and is the single most important fact in this section. The trait also auto-stamps `tenant_id` on create.

### Scope bypasses — measured

**[verified]** `withoutGlobalScope` / `withoutGlobalScopes` appears at **147 code sites** (comments excluded) across 29 directories. Of these, **35 have no `tenant_id`/`tenantId` token within a -4/+8-line window.**

Most of the 35 are legitimate:

- **Platform-admin surfaces** — `AdminTenantController`, `AdminDashboardController`, `PlatformAnalyticsService` query *across* tenants by design, behind `EnsurePlatformContext` middleware
- **The `Tenant` model itself** is not tenant-scoped
- **Pre-authentication lookups** — `AuthIdentifierResolver`, `PasswordResetController`, `OtpController`, `LoginRequest`, `SubdomainCheckController` necessarily run before a tenant is resolved
- **Global reference data** — `TaxBracket`, `Holiday` (national holidays are shared)
- **Queued jobs** run with no HTTP tenant context, so they *must* bypass and re-scope manually

The dominant safe pattern is **scoping by derivation** rather than by predicate: `AccountingExportService.php:41` filters `->where('payroll_run_id', $run->id)`, and `LeaveBalanceService.php:113` filters `->where('id', $balance->employee_id)` — both keyed on records already fetched under tenant scope.

**That is defensible, but it is convention, not enforcement.** Nothing detects a future edit that drops the derived key. There is no automated check; §28 Layer 4 does not exist in this codebase.

### P0-1 — Cross-tenant lookup in employee import **[verified]**

`api/app/Services/Import/EmployeeImporter.php:89-94`:

```php
$tenantId = $this->currentTenant->id();

foreach ($rows as $i => $row) {
    $rowKey = $importKey.'_'.($row['employee_code'] ?? $i);

    $existing = Employee::withoutGlobalScopes()->where('import_key', $rowKey)->first();
```

`$tenantId` is computed and **not used in this query**. The de-duplication check scans `employees` across **every tenant**.

Both inputs are caller-controlled. `api/app/Http/Requests/Employee/ImportCommitRequest.php`:

```php
'import_key'           => ['required', 'string', 'max:50'],
'rows.*.employee_code' => ['nullable', 'string', 'max:30'],
```

No uniqueness, no format constraint, no tenant binding — and `import_key` has no unique index (`0001_01_01_000003_create_organization_tables.php:137` declares a plain nullable string).

**Impact.** Tenant B submits an import whose `import_key` + `employee_code` collide with a row Tenant A already imported — values such as `batch-1` + `EMP001` are trivially guessable. The lookup finds Tenant A's employee, increments `skipped`, and **silently drops Tenant B's row**:

1. **Cross-tenant existence oracle** — the `skipped` count reveals whether another tenant used a given key pair.
2. **Silent data loss** — the employee is never created, and the response reports "skipped", not an error.

The asymmetry is telling: the sibling read path `EmployeeImportController::status()` runs `Employee::whereRaw("import_key LIKE ? ESCAPE '!'")` **without** `withoutGlobalScopes()`, so the global scope applies and it is correctly tenant-scoped. **The read path is scoped; the write path is not.**

Classification: **P0 tenant-isolation defect.** Remediation (adding a `tenant_id` predicate) is one line, but it changes behaviour on an import path and needs a regression test, so per §51 it is not fixed in Phase 0. See §16.1.

### §27's seven questions

| Question | Answer |
|---|---|
| How is tenant scope established? | `CurrentTenant` resolved by `ResolveTenant` middleware; enforced by the `BelongsToTenant` global scope **[verified]** |
| How is it validated? | `EnsureUserBelongsToTenant`; `EnsurePlatformContext` for admin routes **[verified]** |
| Can a user influence `tenant_id`? | `X-Tenant` is honoured only in `local`/`testing` per `src/src/api/client.ts:33-47`; **server-side enforcement NOT VERIFIED this pass** |
| Can raw SQL bypass scope? | **Yes** — 30 raw-SQL sites exist; each must self-scope. Not centrally enforced |
| Can a job run without tenant context? | **Yes, by design** — all 15 jobs bypass and re-scope manually |
| Can an export cross tenants? | Not found. `ReportEngine` sites all carry `tenant_id` predicates **[verified]** |
| Can an uploaded file cross tenants? | Not found. Paths are `tenants/{public_id}/…`, downloads use signed URLs **[verified]** |

**Verdict: tenant isolation is soundly designed and fail-closed at the ORM layer, with one confirmed defect (P0-1) and no automated protection against regression.**

---

## 11c. File uploads and audit logging

> **Two sections in this file are numbered `11c`.** This one, and *The tenant-scope bypass
> audit — first pass* further down. Deliberate, not an oversight — §11d records why
> neither is renumbered. **Citations of "§11c" elsewhere in the repository mean the bypass
> audit, not this section.**

**Uploads [verified]** — `api/app/Services/FileStorageService.php`: MIME detected at `:72` and re-verified post-upload at `:259`; GD guarded by `extension_loaded('gd')` at `:280`, `:321`, `:404`, so thumbnails degrade rather than fail; tenant-prefixed ULID paths `tenants/{public_id}/{directory}/{ulid}.ext`; private files served through signed `temporaryUrl()` against a disk with `'serve' => true`, which needs no `public/storage` symlink.

**Audit logging [verified]** — `AuditLog::record(...)` appears at **206 call sites** in `app/`, typically inside the same transaction as the mutation it records. Immutability is enforced by two database triggers (§13b).

---

## 12. Test status *(§65.9)*

**Backend suite, measured 2026-09-15 [verified]:**

```
$ php -d memory_limit=-1 vendor/bin/pest --compact
  Tests:    1673 passed (4966 assertions)
  Duration: 584.17s
  [exited with code 0]
```

> **Amended after first publication.** This section originally read `NOT MEASURED — Docker unavailable`, which was true of Docker and wrong about the machine: XAMPP provides native PHP 8.2.12 at `/c/xampp/php/php.exe` with `pdo_sqlite`, `mbstring`, `gd`, `dom` and `fileinfo` — everything `phpunit.xml` needs.
>
> The collection guard passes natively: **140 of 140** test classes collected. The lossy directory scan documented in `README.md` and `scripts/gates.sh:108-117` is a **Docker Desktop Windows bind-mount** artefact; a native run against a local disk does not have it. So the full suite is runnable here without Docker, which also means `gates.sh` gates are reachable in later phases.

This is the first measured backend figure in the repository. The seven documents listed in §12b carry six different numbers (954 / 1328 / 1330 / 1647 / 1652 / 1669), none of them dated to a run. **1673 / 4966 is the one with a command and an exit code attached.**

### 12c. PHPStan disagrees between this machine and CI, and the reason is unknown **[open]**

CI run #56 (2026-09-16, commit `49e2ee9`) was the **first run in which PHPStan had ever executed** — until `phpstan_gate` stopped requiring a Docker container it had never run there at all. It reported exactly four errors, all the same shape:

```
app/Services/UserProvisioningService.php:109                    PasswordBroker::createToken()
app/Http/Controllers/Api/V1/Auth/PasswordResetController.php:70   PasswordBroker::createToken()
app/Http/Controllers/Api/V1/Auth/PasswordResetController.php:119  PasswordBroker::tokenExists()
app/Http/Controllers/Api/V1/Auth/PasswordResetController.php:149  PasswordBroker::deleteToken()
```

**A local run of the same PHPStan against the same lockfile reports `[OK] No errors`.** Ruled out, each by direct test rather than reasoning:

| Hypothesis | Test | Result |
|---|---|---|
| CI has no `.env`, changing Larastan's bootstrap | hid `.env`, re-ran | bootstrap threw (Reverb); **not** CI's case, which sets `BROADCAST_CONNECTION` |
| …with `BROADCAST_CONNECTION=null`, as CI has | hid `.env`, set the variable, re-ran | `[OK] No errors` |
| Local `vendor/` drifted from `composer.lock` | compared installed vs lock | identical — larastan v3.10.0, phpstan 2.2.2, framework v12.64.0, php-parser v5.8.0 |
| Stale PHPStan result cache | `clear-result-cache`, re-ran | `[OK] No errors` |

| Path case-sensitivity in `phpstan-baseline.neon` | walked all **150** distinct `path:` entries, comparing each component against the real directory listing with exact string equality | every one resolves with exact case — **not the cause** |
| Laravel using a cached config, making the `.env` test meaningless | checked `bootstrap/cache/` | only `packages.php` and `services.php`, no `config.php`; the `.env` test above stands |

Case-sensitivity was written here as "the most likely candidate" before it was checked. It was checked, and it is wrong. Recorded rather than edited away, because a plausible-sounding untested hypothesis is exactly what this section exists to warn against.

**Still untested:** PHP patch version (local 8.2.12 via XAMPP; `shivammathur/setup-php` resolves `8.2` to its newest patch) and Windows vs Linux generally.

The likeliest remaining mechanism, offered as a hypothesis and labelled as one: Larastan boots the application to resolve a facade to its container binding. Resolved that way, `Password::broker()` goes through `PasswordBrokerManager::broker()`, whose body returns a concrete `Illuminate\Auth\Passwords\PasswordBroker` — which has the three methods. If the boot fails or is skipped, Larastan falls back to the facade's `@method` docblock, which names the *contract* — which does not. That would produce exactly this split. **Untested.**

The four findings themselves are true on both platforms and were fixed on their merits (`app/Support/PasswordTokens.php`) — `Illuminate\Contracts\Auth\PasswordBroker` genuinely declares only `sendResetLink()` and `reset()`. But **the divergence means a green local PHPStan does not imply a green CI PHPStan**, which is worth knowing before trusting either.

> **Re-opened and narrowed, 2026-09-17 — the leading hypothesis above is wrong, tested directly rather than reasoned about.**
>
> First, reproduced from scratch rather than trusted from memory: `git show` restored the exact two files to their state immediately before `bff4fb3` (the commit that introduced `PasswordTokens.php`), result cache cleared (`phpstan clear-result-cache`), and the real gate function run verbatim (`bash scripts/gates.sh backend`, not a hand-tuned `analyse` invocation). Result: **`[OK] No errors`**, on this machine, today, on the exact code that produced 4 errors in CI. The divergence is confirmed live, not archaeological.
>
> Then the hypothesis above — "Larastan resolves the facade to its concrete class when the app boots successfully" — was tested directly using PHPStan's own `\PHPStan\dumpType()` diagnostic, which prints what PHPStan actually infers for an expression, not what it should infer. Added a throwaway `app/ZZZDebugPhpstan.php`:
>
> ```php
> $broker = Password::broker();
> \PHPStan\dumpType($broker);   // -> Illuminate\Contracts\Auth\PasswordBroker
> $broker->createToken(new \App\Models\User);   // -> no error
> ```
>
> **The inferred type is the narrow interface, correctly — and the call is still not flagged.** So the hypothesis is refuted: it is not that app-boot success swaps in the concrete return type. Something else is letting a method call through on a value PHPStan itself reports as typed to an interface that, reflected directly at the PHP level (`(new ReflectionClass(PasswordBroker::class))->getMethods()`), genuinely has only `sendResetLink` and `reset` — confirmed on this exact vendored file, pinned by `composer.lock`, identical to whatever CI installs.
>
> **A more specific mechanism now exists to test, in place of the disproven one.** `vendor/larastan/larastan/src/Methods/ManagersMethodsExtension.php` grants extra methods to a value by resolving it through the live container and calling `->driver()` on it — but only when `$classReflection->is(Manager::class)`, i.e. when PHPStan considers the *value's* class to be `Illuminate\Support\Manager` (which `PasswordBrokerManager` extends) rather than the plain contract interface. Whether PHPStan consults this extension for a receiver statically typed as the *interface* — and whether that consultation itself depends on the container actually being reachable — is the next concrete thing to trace, not "boot succeeded or not" in the abstract.
>
> **What this rules out, precisely:** a config or invocation mismatch on this machine. Same command, same freshly-cleared cache, same gate function, same lockfile — the isolation is real. What remains open is genuinely internal to PHPStan/Larastan's interaction with either the OS or the exact PHP patch build, which this machine cannot distinguish from itself. Attempted a cross-OS check via a static, no-root PHP 8.2.32 Linux binary in WSL (no `sudo`, no Docker available for an apt-based or containerized PHP 8.2 on this host) — the download proved unreliable over two attempts today and was not completed. Left as the next concrete step, not abandoned: a working Linux PHP 8.2 build, `composer install --ignore-platform-reqs` against this same lockfile, and the identical `dumpType()` probe would either reproduce CI's error (confirming OS/build-specific PHPStan behaviour) or also pass (pointing at something GitHub-Actions-specific in how `shivammathur/setup-php` assembles the runtime, rather than Linux itself).

**File counts are static and were measured [verified]:** 141 PHP test files, 70 Vitest files, 12 Playwright specs. Frontend Vitest and Playwright counts remain **NOT MEASURED** — not run this pass.

Suites: `api/tests/{Unit,Feature,Performance}` — `phpunit.xml` declares only Unit and Feature as testsuites; Performance is deliberately outside them. Frontend: Vitest + MSW; Playwright projects `chromium-desktop` and `webkit-mobile`.

**Known coverage gaps [verified]:** no coverage instrumentation exists at all — `api/phpunit.xml` declares `<source>` but no `<coverage>` block, and `src/vitest.config.ts` has no `coverage` key. **No coverage figure can be stated and no threshold can regress.** `api/tests/Feature/Billing/` contains one file. `src/e2e/payroll.spec.ts` is 549 bytes and asserts only that the page renders a heading.

---

## 12b. Documentation inconsistencies *(§65.10)*

**[verified]** — Phase 1 consumes this directly.

1. **Nine stale `deployment/shared-hosting/` references** survive the move, in `docs/ROLLBACK_RUNBOOK.md`, `PRODUCTION_CHECKLIST.md`, `DATABASE_MIGRATION_PLAN.md`, `MIGRATION_STATE.md`, `MIGRATION_CHANGELOG.md`, `SHARED_HOSTING_MIGRATION_PLAN.md` — **and five inside the moved files themselves**, including `docs/deployment/shared-hosting/DEPLOYMENT.md:24`, a literal copy instruction naming a directory that no longer exists.
2. **`docs/AGENTS.md` is a 236-line-divergent fork of `docs/CLAUDE.md`**, still carrying the "Ignore ALL Git-related functionality" ban that commit `0997faa` explicitly retired. Two rule files, one corrected, one not.
3. **Four documents assert CI that does not exist** — `docs/CLAUDE.md:269,716`, `docs/AGENTS.md:227,604`, `docs/SECURITY.md:298`, `docs/security-audit.md:84`.
4. **Six different test counts across seven documents** — 954 / 1328 / 1330 / 1647 / 1652 / 1669. Only `docs/security-audit.md` carries a `Last reviewed:` stamp, and it is the most stale.
5. **Ten dead cross-references**, including a live markdown link at `README.md:122` to `ETHR_AUDIT_2026-08-14.md`, and a `docs/audits/` directory cited twice that has never existed.
6. **`docs/security-audit.md` prescribes `vendor/bin/pest` directly** — the command `README.md` documents as silently collecting 22 of 132 test classes and exiting green.
7. ~~**Stack-table drift** — `docs/CLAUDE.md` says Next.js 15; the lockfile says 16.~~ **Fixed 2026-09-16.** Both occurrences now read 16. The version was the smaller half of the problem: the same "v1.0 — Locked" table still lists Redis, Horizon, Reverb and MinIO, none of which are deployed to the shared-hosting target, and calls the infrastructure "Ethiopian VPS". The table is kept — it *is* the locked v1.0 specification — with a preamble naming each row that no longer ships and pointing at the decision that retired it.
8. **`docs/phases/` (252 KB) is self-declared unmaintained** by `README.md`, and still shipped.
9. **No `docs/README.md` index** for 53 files; `README.md` maps 8 of them.
10. **Master plan §5's repository identity block is wrong for this tree** (§1a).

### 12d. The Frontend CI job ran on an end-of-life Node **[found and FIXED 2026-09-16]**

`Frontend (i18n, Prettier, ESLint, tsc, Vitest)` had failed on every run since the workflows first executed. The cause was a single line: all four `setup-node` steps pinned `node-version: "20"`, and **Node 20 reached end-of-life on 2026-04-30** (`nodejs/Release/schedule.json`) — four and a half months before this was read.

**Measured on Linux (WSL Ubuntu 26.04), the two failing files, run in isolation:**

| Node | Result |
|---|---|
| 20.20.2 | 3 failed, 2 passed |
| 22.23.2 | 2 failed, 3 passed |
| 24.21.0 | **5 passed** |

Two independent causes, not one:

1. **`employees-import.test.tsx` (2 tests) — fails on Node 20 *and* 22.** The page posts `multipart/form-data` through axios. Probed directly: MSW's handler *is* invoked (hit count 1, `content-type: multipart/form-data; boundary=----formdata-undici-…`), but the promise never settles — a bare `apiClient.post` of a `FormData` hangs until the 20s test timeout. The request is intercepted before it leaves the process, so **no application code is involved**.
2. **`alert-thresholds-dialog.test.tsx` (1 test) — fails on Node 20 only.** This one was a real defect in the test. It waited on `findByText(/Turnover rate/)`, which is *also* the text Radix renders in the metric `<Select>` trigger — so the query resolved against the form control on the first poll and never waited for the rule list, leaving `getByText(/exceeds 5/)` to race the network. It passed on 22/24 by luck of timing. Now it waits on the rule's own text and asserts metric, operator, value and severity on one row, which is what the test's name always claimed. Green on 20, 22 and 24.

**Fix:** `.nvmrc` at the repository root declares `24`; all four `setup-node` steps read `node-version-file: .nvmrc`. One declaration, the same reason CI calls `gates.sh` rather than restating the gate list.

**Verified:** the full suite on Linux + Node 24 — the exact CI configuration — `73 passed (73)`, `473 passed (473)`. That is the first time the frontend suite has been run green in a CI-equivalent environment rather than only on a developer's Windows machine.

**What this does *not* claim.** It is not a finding that the product needs Node 24. `tsc`, ESLint, Prettier and i18n always passed on Node 20, and jsdom unit tests never exercised a production runtime. The application's floor is still Next 16's Node 20.9+, and which Node the shared host offers is **gate B5 — still unanswered**.

**Four hypotheses were tested before this, and all four were wrong.** Recorded so the work is not repeated — and because one of them was wrong in an instructive way:

| Hypothesis | Test | Result |
|---|---|---|
| `tsc` needs `.next/types`, absent on a clean checkout | deleted `.next/` entirely, ran the gate | passes — `tsc_gate`'s `next typegen` step handles it |
| ~~Node engine incompatibility (CI pins Node 20, local is 24)~~ | read `engines` from next/eslint/typescript/vitest | **WRONG — this was the cause.** Every package declares `>=20.9` or lower, and `setup-node` "20" does satisfy all of them, so the hypothesis was dismissed. Declared engine ranges say what a package *claims*; they say nothing about whether the test harness works. It should have been tested by running the suite on Node 20, not by reading metadata. |
| `node_modules` drifted from the lockfile | `npm ls --depth=0`, then a full `npm ci` | only extraneous `sharp` WASM fallbacks; nothing missing or invalid |
| Import path case-sensitivity (Linux resolves case-sensitively, Windows does not) | resolved **1,850** imports — 115 relative and 1,735 `@/` alias — comparing every path component against the real directory listing with exact string equality | zero mismatches, zero unresolved |

**The lesson is the one this file keeps having to relearn: read the output.** Job logs are not available to an anonymous client (REST API 403 "Must have admin rights to Repository"), so `scripts/gates.sh` was changed to publish the failing gate's output as a GitHub Actions *annotation*, which **is** readable signed-out. That named all three tests. Everything above followed in one pass. Four rounds of reasoning produced four wrong answers; one round of reading produced the right one.

> A self-inflicted delay worth recording: the first version of that annotation grepped the captured output for `FAIL`/`×` **and then** stripped ANSI escapes. Vitest colours those markers, so the pattern never matched and the annotation came back empty — which looked like "the gate failed silently" rather than "my grep is broken". Verified after reordering by running it against a deliberately failing test, since a green run has no `FAIL` lines and would have "passed" the check while proving nothing.

### 12g. The frontend rendered timestamps in the browser's timezone **[found and FIXED 2026-09-16]**

> **Resolved.** The requirement was already settled in planning and is not an open question: **ETHR displays timestamps in the tenant's configured IANA timezone, defaulting to `Africa/Addis_Ababa`.** UTC remains the storage and transport baseline; conversion happens at the display boundary. An earlier draft of this section framed it as a product decision — it was not one, and that framing is corrected here rather than deleted.
>
> **What was implemented**
>
> | | |
> |---|---|
> | `lib/utils/date.ts` | Every formatter takes an optional IANA `timeZone`, defaulting to `DEFAULT_TIMEZONE` = `Africa/Addis_Ababa`. `resolveTimeZone()` validates it against `Intl` and falls back rather than throwing. |
> | `lib/hooks/useTenantTimezone.ts` | `useTenantTimezone()` and `useDateFormatters()` read `tenant.timezone` from the **existing** `/auth/me` TanStack query. |
> | `features/auth/api.ts` | `tenant.timezone` declared on `MeResponse`. |
> | 3 call sites | attendance page, announcements widget, recent activity — migrated to the hook. |
>
> **No new request, no provider, no prop drilling.** `TenantResource` has always returned `timezone`; the frontend type simply never declared it, so nothing could use it. That is why this is a contained change rather than a refactor.
>
> **Pure functions stay pure.** The zone is a parameter with a correct default, not injected context — so a call site that has not been migrated still renders Addis time rather than falling back to the host clock. That property is what makes this safe to land incrementally.
>
> **No fixed offset anywhere.** Zones are IANA names, never `+03:00`. Addis does not observe DST, but the tenant zone is configurable and other zones do; two tests pin a DST transition (`Europe/London` in January vs July) to keep it that way.
>
> **Determinism.** The tests set no `TZ`. Removing the `timeZone` option, i.e. reverting to the old behaviour, fails **9 of 20**.
>
> **The inline call sites are now migrated too — 2026-09-16.** 30 inline `toLocale*` date expressions across 20 files, classified one at a time rather than replaced globally, because three of them should *not* have taken a tenant zone.
>
> | Classification | Sites | Treatment |
> |---|---|---|
> | Instants — audit and event timestamps, webhook triggers, QR expiry, report schedules, last login, last sync, kiosk-session activity, trial and subscription dates, invoice `paid_at`, announcement and approval dates | 28 | `useDateFormatters()` |
> | Calendar date — `Invoice::$casts` types `due_date` as `date`; the dashboard chart axis labels `Y-m-d` days | 2 | `formatDateOnly` / `formatWeekday`, which apply **no** zone |
> | Local UI state — the leave calendar's month label | 1 | **Preserved**, and commented so it survives the next sweep |
> | Standalone — the kiosk wall clock | 3 | Pinned to the default; see below |
>
> **A defect the migration exposed.** `formatDate` applied the tenant zone to bare `YYYY-MM-DD` strings. Those carry no time of day, so there is nothing to convert — and converting one into a zone behind UTC moves it to the previous day. Measured: `formatDate("2026-10-01", "America/New_York")` returned **`30 Sept 2026`**. Ethiopian tenants sit at UTC+3 and could never have seen it, which is exactly why it could have stayed indefinitely. `formatDate` now detects a date-only value and renders it as written; `formatDateOnly` states the same intent explicitly for values that arrive from a Laravel `date` cast with a midnight time component.
>
> **The kiosk is pinned, not solved.** `/kiosk/authenticate` returns the tenant's name, subdomain and logo — not its timezone — and the kiosk has no authenticated session to ask. Its clock now uses `DEFAULT_TIMEZONE` rather than the device's own, which is the same fallback every other screen uses when the tenant is unknown and which a mis-set kiosk OS cannot skew. **A tenant on another zone still sees Addis there.** Closing it means adding `timezone` to that payload — additive, but the response is explicitly typed in `openapi.json`, so it needs a contract regeneration against a migrated database. Not done here.
>
> **Host independence, measured rather than asserted.** 44 tests across `date-utils`, `audit-log-timezone`, `webhook-deliveries` and `active-sessions`, run under six host zones on Linux + Node 24:
>
> ```
> TZ=UTC                 44 passed    TZ=America/New_York    44 passed
> TZ=Africa/Addis_Ababa  44 passed    TZ=Europe/London       44 passed
> TZ=Africa/Nairobi      44 passed    TZ=Pacific/Kiritimati  44 passed
> ```
>
> That matrix had to run in WSL: **Node on this Windows host ignores `TZ`.** `TZ=America/New_York node -e "…resolvedOptions().timeZone"` still reports `Africa/Nairobi`. Any earlier claim on this machine that a test "passes under TZ=X" was therefore measuring nothing — recorded because it is an easy mistake to repeat.
>
> The first version of the coincidence guard asserted `resolvedOptions().timeZone !== "Pacific/Kiritimati"`, and was the single failure under `TZ=Pacific/Kiritimati` — a test that fails on a legitimate host is a flaky test. It now asserts that one instant renders as four *distinct* strings across four zones, which a host-clock implementation collapses to one and which holds on every host.
>
> **Mutation-checked.** Reverting the date-only guard fails 1 test; reverting the audit-log and webhook-delivery migrations fails 5. The tests fail against the old behaviour rather than merely describing the new one.
>
> **Still open, and a different axis:** several `.toLocaleString()` calls on *numbers* pass no locale at all — `grade-salary-steps-dialog.tsx:143-144`, the device record counts — so thousands separators follow the browser. Not a timezone problem and not changed here, but the same class of host-dependent rendering.

The original finding follows, for the record.

Convention #2 is "Store all timestamps in UTC. Display in EAT (Africa/Addis_Ababa, UTC+3)."

`src/src/lib/utils/date.ts` — `formatDate`, `formatDateTime`, `formatTime`, `timeAgo` — passes **no `timeZone` option** to `toLocaleDateString` / `toLocaleString` / `toLocaleTimeString`. A grep for `timeZone` across the whole frontend returns **nothing**. So every rendered timestamp follows whatever timezone the browser is set to.

`formatTime` is what the attendance page shows for a punch (`app/(dashboard)/attendance/page.tsx:58`). A check-in stored as `05:30Z` displays as **08:30** to a browser in Addis and **05:30** to a browser in UTC — against the same employee, on the same shift.

**Why it has not been noticed:** users are in Ethiopia and their browsers are set accordingly, so the host timezone and the intended one coincide. The machine this was measured on is `Africa/Nairobi` — UTC+3, the same offset as EAT — so the formatters render correctly here by luck of the host clock.

**Why it is not fixed here.** There are two defensible answers and they are not equivalent:

1. **Always EAT.** Matches convention #2's literal wording, and is right if the product is only ever used inside Ethiopia.
2. **The tenant's configured timezone.** The product already stores one — `features/settings/components/organization-card.tsx` exposes a `timezone` field defaulting to `Africa/Addis_Ababa` — and these pure functions have no access to it, so honouring it needs the tenant timezone threaded through a context or passed by every caller.

Picking (1) would quietly break any tenant that has set a different zone, and that setting exists. **This is an owner decision, not a refactor.**

`src/test/date-utils.test.ts` pins current behaviour with `process.env.TZ` set to UTC before import, so the tests are deterministic on any runner and will fail loudly when this is changed.

> **A second environment-dependence found while writing those tests.** en-GB renders September as `Sep` under older ICU and `Sept` under newer. Asserting either literal would pass on one runtime and fail on the other, so the month is matched as `/Sept?/` while the day, year and time are asserted exactly. CI and local development now both run Node 24 (§12d), so the two agree today — the loose matcher is kept because agreeing *today* is not the same as being version-independent, and this is exactly the class of drift that hid §12d for fifty runs.

### 12h. The query cache had no session boundary **[found and FIXED 2026-09-17]**

An audit of the TanStack Query layer, prompted by the `api.ts` modules sitting at 0% coverage. The coverage number was the reason to look; it is not what was found.

**The structural fact everything else follows from.** `app/providers.tsx` creates **one** `QueryClient` in `useState` and never replaces it, so every response cached during a session stays in memory for as long as the page lives. Default `staleTime` is 60s; `/auth/me` is 5 minutes.

**No query key carries a tenant or user discriminator** — they are `["users", params]`, `["employees", …]`, `["auth", "me"]`. That is not itself wrong, because a key only has to be unique within one cache. It does mean isolation rests entirely on two things: the server scoping every response to the session, and **the cache being destroyed whenever the identity behind it changes**. The first was never in doubt. The second had a hole.

| Identity change | Behaviour | Verdict |
|---|---|---|
| Impersonation start | `window.location.href` — full reload, with a comment saying exactly why | correct |
| Impersonation exit | `window.location.href` | correct |
| Session expiry (401, refresh failed) | interceptor hard-navigates | correct |
| Impersonation claim | lands cross-host (`admin.ethr.et` → `{tenant}.ethr.et`), so always a new document | correct, structurally |
| **Log out** | cleared and navigated **only on success** | **defect** |
| **Log in** (password, MFA, OTP) | `router.push("/dashboard")` — SPA, cache survives | **defect** |

Those two compose. `AuthGuard` sends a failed session to `/login` with `router.replace`, which keeps the JS context alive; the login form then left for `/dashboard` with `router.push`. Between two SPA transitions the cache is never dropped, so the next person to sign in on that tab inherits whatever the previous one had loaded — and since the keys carry no tenant, across tenants.

**Four defects, each reproduced by a failing test before it was fixed:**

| | What was wrong |
|---|---|
| `useLogout` | Cleared the cache and navigated in `onSuccess`. Neither call site passes an `onError`, so a failed request — offline, API down, token already expired — did **nothing at all**: no navigation, no clear, no message. The person clicked Log Out and was left on a populated dashboard believing they had. For a product that advertises itself as offline-first, that is not an edge case. Now `onSettled`: the server call is how we additionally ask for the token to be revoked, but it cannot decide whether the local session ends. |
| Login / MFA / OTP forms | Now clear the cache before navigating. Authenticating starts a new session, so it starts a new cache. |
| `useUpdateUser` | Invalidated `["users"]` only. The payload carries `role` and `custom_role_id`, and every `can.*` flag and `<RoleGate>` reads the `permissions` array from `/auth/me`, so editing **your own** account left the whole interface authorizing against the role you had just left, for up to five minutes. |
| `useUpdateCustomRole` | Same, via the role rather than the user. |

**On severity, precisely.** The last two are not privilege escalation — the server enforces regardless, and these are cache-lifetime bugs in the client. What they produce is an interface that disagrees with the API it is talking to, in both directions: a removed permission keeps being offered and then fails, and a granted one stays hidden, so the admin who just granted it concludes it did not work.

**The fixes are narrow on purpose.** `useUpdateUser` compares the edited `publicId` against the cached current user and refetches `/auth/me` **only** when they match — an admin working down a list of staff should not refetch their own identity on every row. `useUpdateCustomRole` refetches only when the payload contains `permissions`, since renaming a role cannot change anyone's abilities. Both distinctions are pinned by tests that assert the *absence* of a refetch, so a later change cannot pass by invalidating everything.

**Audited and found correct**, recorded so the work is not repeated: `reports/api.ts` (save/delete invalidate saved *and* scheduled; generate and export are stateless), `shifts/api.ts`, `announcements/api.ts` (prefix invalidation covers list, detail and schedule), `dashboard/team-api.ts` (no mutations; `period` and `month` are correctly part of the keys), and the two `employees/page.tsx` "mutations", which are CSV exports with nothing to invalidate.

**What remains true and worth watching.** The keys are still tenant-agnostic, so this class is closed by *behaviour*, not by structure: any future path that changes identity without clearing or reloading reopens it. The four tests added here (`logout-cache`, `login-cache-boundary`, `users-roles-cache`) assert the boundary rather than the scenario, so they fail on the mechanism rather than on one route.

Gates on the change: frontend **80 files / 513 tests**, i18n, Prettier, ESLint, tsc, `next build`, docs — all green.

---

### 12f. Frontend coverage — first measurement **[verified 2026-09-16]**

```
$ cd src && npx vitest run --coverage --coverage.reporter=text-summary
  Test Files  70 passed (70)
       Tests  435 passed (435)

  Statements   : 32.34% ( 15287/47260 )
  Branches     : 69.81% ( 1781/2551 )
  Functions    : 58.97% ( 700/1187 )
  Lines        : 32.34% ( 15287/47260 )
```

**321 files in the report, and 170 of them at 0%** (measured before the offline-pipeline tests of 2026-09-16; see the note below) — better than half the frontend is never imported by any test. That is the number worth carrying, more than the percentage: the 32% is diluted by large files partially touched, while the 170 are untouched entirely.

The denominator is honest. Vitest 2's `coverage.all` counts files no test imports, which is why the zero-coverage files appear at all; a report that only counted imported files would have shown a much higher and much less useful figure.

Branches at 69.81% against statements at 32.34% is the expected shape: the code that *is* exercised is exercised fairly thoroughly, and the gap is breadth, not depth.

Needed `@vitest/coverage-v8`, pinned to `2.1.9` to match `vitest` — the provider and the runner are versioned together. No extension, unlike the backend (§12e).

**Not wired into `scripts/gates.sh`.** It roughly doubles the frontend gate's wall time (127s against ~60s), and a threshold set at today's 32% would be a number nobody chose. Run it deliberately, the way `security` and `performance` are run.

#### What was done with the measurement, 2026-09-16

The 170 were classified by what the file does rather than by how many statements it holds. 77 of them are `app/(dashboard)` pages — presentational, and better served by Playwright than by unit tests. The risk was concentrated somewhere else entirely, and in one place the coverage was **inverted**:

| File | Before | After |
|---|---|---|
| `components/shared/offline-banner.tsx` — the badge that *displays* the pending count | 97.05% | 97.05% |
| `lib/offline-queue.ts` — the IndexedDB store holding unsent punches | **2.7%** | **91.89%** |
| `lib/hooks/useOfflineSync.ts` — the engine that empties the queue | **0%** | **96.66%** |

ETHR is offline-first. A punch captured with no signal lives only in that store until the sync engine delivers it, and **the backend cannot compensate for a failure there** — a record that never arrives cannot be reconciled server-side. The UI reporting the queue was thoroughly tested; the code responsible for not losing it was not tested at all.

18 tests across the two files, each written against a way a punch is lost or double-counted. Both suites were verified by mutation rather than assumed: making `markSyncError` also mark a record synced, and making the sync engine treat `duplicate` as a failure, each failed exactly the tests that assert the property and no others.

Total coverage moved **32.34% → 32.74%**. That the headline barely moved is the point — the files were chosen for what they do, not for their statement count.

**Still at 0% and worth a look next**, in rough risk order: `lib/utils/date.ts` (35 stmts — date handling under convention #2's UTC-store / EAT-display split), `components/shared/auth-guard.tsx` (26 — a route authorization control, though defence-in-depth since the API enforces independently), `features/auth/sessions-api.ts` (50 — session revocation), and `app/kiosk/page.tsx` (417 — shared-device PIN check-in, which captures attendance).

### 12e. Backend coverage needs a PHP extension — `phpdbg` is not a way round it **[instrument built 2026-09-23; no figure yet]**

> **The extension is now supplied in CI.** `.github/workflows/gates.yml` carries a
> `Backend coverage (PCOV)` job — `coverage: pcov` on `setup-php`, its own job so the other
> four keep `coverage: none` and stay fast — running `./scripts/gates.sh coverage`.
>
> **MEASURED 2026-09-23, first run: `Total: 86.7 %`** — CI run 35891128495, job
> *Backend coverage (PCOV)*, green. Reproduced on `main` the same day (run 35894050411).
>
> **Floor set 2026-09-23: `--min=85`**, owner's decision, against that measured 86.7%
> baseline — 1.7 points of headroom. It is a **ratchet against regression, not a target**:
> it fails when coverage drops and says nothing about whether 86.7% is enough. Raising it
> means measuring again first.
>
> The gate **refuses rather than reporting 0%** when no driver is loaded, and that refusal is
> the reason it is worth having. A coverage run with no driver does not error in any obvious
> way — it is the trap described below — and a gate answering "0%" because the instrument is
> missing would be the same class of green lie as the 21-of-132 Pest collection. The refusal
> path was exercised on a machine with neither extension before the job was written.

Risk 10 is "no coverage instrumentation". The obvious dodge is `phpdbg`, which ships with XAMPP and historically produced coverage without installing anything:

```
phpdbg -qrr vendor/bin/pest --coverage
```

**It does not work, and the reason is not obvious from the error.** Pest reports `Coverage not found in path: vendor/pestphp/pest/.temp/coverage.php`, which reads like a Pest bug. It is not. `phpunit/php-code-coverage` **removed the PHPDBG driver in v10**; this project is on **11.0.12**, and `vendor/phpunit/php-code-coverage/src/Driver/` contains exactly two drivers:

```
PcovDriver.php
XdebugDriver.php
```

Nor can PHPUnit be called directly to route around Pest — `vendor/bin/phpunit` refuses with `InvalidPestCommand: Please run [./vendor/bin/pest] instead`.

So backend coverage requires **PCOV or Xdebug installed into the PHP runtime**. PCOV is the better choice for a coverage-only job: it is far faster than Xdebug and does nothing else, whereas Xdebug slows every run it is loaded into.

This is recorded because `phpdbg` being on PATH makes the dodge look available, and the failure mode points at the wrong component. Anyone who tries it will lose the same twenty minutes.

Frontend coverage is unaffected — Vitest uses v8 coverage and needs no extension.

### 12g. What 86.7% hides — the zero-coverage files **[measured 2026-09-23]**

A high total is the least interesting thing a first coverage run tells you. These files are
at **0.0%** with 1903 tests running around them, and two of them enforce conventions this
repository states as non-negotiable:

| File | Coverage | Why it matters |
|---|---|---|
| `Traits/HasAuditLog` | ~~**0.0%**~~ **covered 2026-09-23** | Convention 5 is the immutable audit log. **The zero was not "the audit log is untested" — it was that `audit()` has no callers at all**: zero in `app/`, zero in `tests/`, while **210** sites call `AuditLog::record()` directly. `HasAuditLogTest` now covers it, and says in its own header that this raises the number on code nothing uses. **Whether to delete the trait instead is open and is the owner's** |
| `Traits/NeverDelete` | ~~**0.0%**~~ **covered 2026-09-23** | Convention 14's "Never delete" rows — attendance, payroll entries, payroll runs, audit logs. `docs/CLAUDE.md` already records the soft-delete table as *"a convention, not a control"* with four tests against fifteen rows. **This is the same finding from the other side, now measured.** The zero had a specific cause: `AuditLogImmutabilityTest` proves a row cannot be deleted **via `DB::table(...)->delete()`**, which exercises the database trigger and never the model. `NeverDeleteTest` covers the Eloquent layer, and pins the policy-to-code binding — removing the trait from one of the four models now fails a test |
| `Traits/DispatchesWebhooks` | 14.3% | The dispatch path is covered at the service; the trait that triggers it is not |
| `Services/Sso/SsoUser`, `Notifications/PasswordResetLinkNotification`, `Notifications/MissingPunchNotification`, `Notifications/AttendanceCorrectionRequestedNotification` | **0.0%** | — |
| **Twelve policies** — `Announcement`, `ApiKey`, `AttendanceCorrection`, `AttendanceRecord`, `Branch`, `CustomRole`, `Department`, `Device`, `Holiday`, `Shift`, `Tenant`, `Webhook` | **0.0%** | **Verified 2026-09-23 — see §12h; eleven DELETED 2026-09-23, see §12i.** Not an authorisation gap: the endpoints are authorised and these classes were simply never called. **`AttendanceRecord` was held back — it was the one of the twelve that is not a duplicate, and §12i records the under-scoped endpoint that finding exposed.** *(The row said "Eleven" and listed twelve.)* |

Lowest non-zero, all device adapters and SSO: `ZktecoAdapter` 46.2%, `SupremaAdapter` 47.1%,
`HikvisionAdapter` 59.8%, `SamlProvider` 37.2%. Every one talks to hardware or an external
IdP this project has never had in front of it — expected, and the reason **G0-J and the
device rows of Gate 0 are measurements nobody has taken**.

**No threshold is set anywhere.** 86.7% is a baseline to compare against, not a bar to clear,
and picking a floor is the owner's call. *(Superseded 2026-09-23: a floor of `--min=85` was
set against this baseline — §12e.)*

### 12h. The twelve policies at 0.0% — resolved, and §12g's first explanation was wrong **[verified 2026-09-23]**

§12g said the policy classes were *"reached through HTTP tests that assert the outcome, so
the policy classes themselves show no lines"*, and flagged it unverified. **That reasoning is
false, and it is worth saying why before the conclusion:** PCOV records lines that *execute*.
If a policy method ran — whatever the test then asserted — its lines would be covered. 0.0%
cannot mean "covered indirectly"; it can only mean **the method never runs**.

**It never runs. Here is the mechanism, read from the code:**

- The controllers on these resources authorise with a **permission-string ability**:
  `Gate::authorize('org.view')` (`Organization/BranchController.php:72`), not
  `$this->authorize('view', $branch)`.
- `AppServiceProvider:263` installs a **`Gate::before`** hook: any ability
  `Permission::isKnownAbility()` recognises returns `$user->hasPermission($ability)` — a
  definitive `true`/`false` that **short-circuits the rest of the gate pipeline, model
  policies included**.
- So `Gate::policy(Branch::class, BranchPolicy::class)` (`:243`) is registered and never
  consulted. **Zero model-style `authorize()` calls exist for any of the twelve.**

**This is not a security gap, and convention 7 holds.** The convention reads *"authorized via
`Policy` **or** `Gate`"*, and these take the Gate path, backed by the permission system.
Sixteen model-style `authorize()` calls do exist elsewhere — `EmployeeController:187,200,225`,
`PayrollController:105,114` — which is why `EmployeePolicy` (57.1%), `PayrollRunPolicy`
(87.5%) and `LeaveRequestPolicy` (76.2%) carry coverage. **Both patterns are in use; these
twelve are on the side that does not reach a policy.**

**Nor do the two paths disagree.** `BranchPolicy::view()` is
`return $user->hasPermission('org.view')` — byte for byte what the controller's
`Gate::authorize('org.view')` resolves to through the hook. The policies **duplicate** the
check they would delegate to, so they are redundant rather than divergent, and no behaviour
is hiding behind the choice of path.

**What is left is a latent trap, not a live defect.** Twelve registered policies that no test
has ever executed. Write `$this->authorize('view', $branch)` tomorrow — `view` is not a known
Permission ability, so `Gate::before` returns null and `BranchPolicy` **would** be consulted,
for the first time ever, in production. Its logic is currently unverified by anything.
**Deleting them or exercising them is an owner decision**; this section only establishes
which one is being decided.

### 12i. Eleven of the twelve policies deleted — and the twelfth was not dead **[done 2026-09-23]**

Owner decision on the choice §12h put up: **delete**. Eleven are gone, with their
`Gate::policy()` registrations, their imports and the eleven now-orphaned model imports in
`AppServiceProvider`. Nine policy classes remain and nine registrations remain, one per file.

`AttendanceRecordPolicy` was **held back**, because reading the twelve before deleting them
showed §12h's central claim is true of eleven and **false of one**.

**§12h said the policies "duplicate the check they would delegate to, so they are redundant
rather than divergent."** Eleven are exactly that — a single
`return $user->hasPermission('...')`. `AttendanceRecordPolicy::view()` is not:

```php
if (! $user->hasPermission('attendance.view')) { return false; }
if ($user->orgScope() === OrgScope::ALL)       { return true; }
if (! $record->relationLoaded('employee'))     { $record->load('employee'); }

return $record->employee && $user->canAccessEmployee($record->employee);
```

That is a **row-level** check. No permission string performs it, so deleting this class would
have discarded the only written expression of it — and it is missing from the live path.

#### The finding that fell out of the deletion — `GET /attendance/{attendanceRecord}` is not org-scoped

`AttendanceController::show()` authorises on the ability alone and returns the record:

```php
public function show(AttendanceRecord $attendanceRecord): AttendanceRecordResource
{
    Gate::authorize('attendance.view');
    $attendanceRecord->load('employee', 'shift');

    return new AttendanceRecordResource($attendanceRecord);
}
```

Five facts, each read from the code rather than inferred:

1. `attendance.view` is in the **`$everyone`** grant list — `PermissionSeeder.php:215`. Every
   authenticated user in a tenant holds it, down to `OrgScope::SELF`.
2. `Gate::before` recognises it as a known ability and returns `hasPermission(...)` — **true** —
   which short-circuits the pipeline, so `AttendanceRecordPolicy::view()` is never consulted.
3. ~~The route binds on the **primary key**, so the identifier is a sequential integer.~~
   **WRONG, corrected 2026-09-23 — see the correction below.** The route binds on
   `public_id`, a ULID.
4. `show()` applies **no** `scopeAccessibleEmployees()`. Every sibling endpoint does —
   `AttendanceController:131`, `:154`, `:210`, `:214`.
5. `AttendanceRecordResource` returns the employee, `check_in`, `check_out`, `status` and
   **`latitude` / `longitude`** — where a colleague physically was when they punched.

~~**So any authenticated employee can read any attendance record in their own tenant by
incrementing an integer.**~~ **The claim is right about the missing check and wrong about how
it is reached — corrected immediately below.**

**Scope of it, stated precisely.** This is **within-tenant**, not cross-tenant.
`AttendanceRecord` uses `BelongsToTenant` (`AttendanceRecord.php:27`), so the global scope
applies to route-model binding and another tenant's row resolves to a 404. Tenant isolation —
the property this codebase is most careful about — holds. What does not hold is the
**org-hierarchy** boundary *inside* a tenant, which `ScopesEmployeeAccess` exists to enforce
and which the list endpoints enforce correctly.

**Not fixed here, deliberately.** The fix is one line — `Gate::authorize('view', $attendanceRecord)`
in place of `Gate::authorize('attendance.view')`, which routes through the policy that already
holds the correct logic — but it is a **behaviour change on a live endpoint**: today every
employee can read every record, and afterwards most cannot. That is the intended behaviour and
also a visible change for anyone relying on the current one, so it is the owner's to authorise,
not a cleanup to slip into a deletion PR. `AttendanceRecordPolicy` stays registered and intact
so the fix stays one line.

**The lesson is the one this file keeps recording.** §12h reasoned about the twelve as a class
and reached a conclusion that was right eleven times out of twelve. The whole point of §11d's
"106 of 156 prove their own safety" was that a property holding for a class says nothing about
a member — and the same mistake was made one section later, on a smaller scale, by a reader who
had just written that warning down. **Reading the twelve took about four minutes.** Nothing but
opening the files would have found this: the coverage number said 0.0% for all twelve alike,
and 0.0% is exactly as consistent with "harmless duplicate" as with "the only copy of a missing
check."

**What the deletion does and does not buy.** Eleven fewer classes that no test executes, so the
latent trap §12h named is eleven-twelfths closed: writing `$this->authorize('view', $branch)`
tomorrow now denies with a 403 — Laravel finds no policy, no ability, and fails closed — rather
than silently consulting unverified logic. It buys **no** behaviour change on any live endpoint;
every one of the eleven was unreachable, verified four ways before removal (no `authorizeResource`,
no `can:` middleware, no model-style `authorize()`, and no reference anywhere outside the
registration and its import).

### 12j. §12i said "incrementing an integer". It binds on a ULID — and the real route in was worse **[corrected and FIXED 2026-09-23]**

**The correction first, because it is the part that was shipped wrong.** §12i point 3 said the
route binds on the primary key and the identifier is "a sequential integer". It is not.
`AttendanceRecord` uses `HasPublicId`, and that trait declares
`getRouteKeyName(): string { return 'public_id'; }` — a ULID. The check that produced the
error was `grep getRouteKeyName app/Models/AttendanceRecord.php`, which returns nothing,
read as "no override, so the primary key". **A model's traits are part of the model.** The
same claim went into the commit message and PR #86's body and was merged before it was caught.

**A ULID is not guessable**: 48 bits of millisecond timestamp and 80 bits of randomness, so
knowing roughly when a record was created still leaves 80 bits. So the endpoint was never
enumerable, and §12i overstated how the gap is reached.

**Everything else in §12i stands, and understated the gap in a different direction.** The
missing check was real, and there was a second endpoint with the same defect and an
identifier that is simply *handed out*:

| Endpoint | Authorised on | Org scope | Identifier |
|---|---|---|---|
| `GET /attendance/{attendanceRecord}` | `attendance.view` — `$everyone` | **none** | record ULID |
| `GET /employees/{employee}/attendance/timeline` | `attendance.view` — `$everyone` | **none** | employee ULID |
| `GET /employees/reporting-tree` | `employee.viewAny` — supervisor+ | **none** | *returns* every employee ULID |

The timeline returns **90 days by default** of one named employee's check-in and check-out
times. `EmployeeController::reportingTree()` builds the whole tenant's chart from every root
with `directReportsRecursive` and applies no `scopeAccessibleEmployees()` — its sibling
`index()` on the next line does.

**So the chain needs no guessing at all.** A `SUPERVISOR` — `OrgScope::DIRECT_REPORTS`, holding
`employee.viewAny` — reads the reporting tree, gets every employee's `public_id`, and reads the
90-day attendance timeline of anyone in the tenant. That defeats org scoping for every role
whose scope is narrower than `ALL`, which is what `ScopesEmployeeAccess` exists to enforce.
It is a bigger hole than the one §12i described, reached by a supported feature rather than
by brute force.

**Fixed, both reads:**

- `AttendanceController::show()` now calls `Gate::authorize('view', $attendanceRecord)`,
  routing through `AttendanceRecordPolicy::view()` — the policy §12i kept for exactly this.
- `EmployeeAttendanceTimelineController` now states both dimensions separately:
  `Gate::authorize('attendance.view')` for "may you read attendance" and
  `Gate::authorize('view', $employee)` for "may you read this employee", the latter through
  `EmployeePolicy::view()`, which already pairs `employee.view` with `canAccessEmployee()`.

**Neither fix costs self-service.** Both paths end at `canAccessEmployee()`, which returns true
for `OrgScope::SELF` on your own record. The existing test *can view single attendance record*
passes unchanged, and the timeline's only frontend caller is the employee detail page, which
already required `employee.view` + `canAccessEmployee()` to load the employee it is a tab on.

`AttendanceOrgScopeTest` pins all four outcomes — denied out of scope, allowed in scope, on
each endpoint — plus the premise that `attendance.view` is granted to every role, so the file
fails loudly rather than quietly if that grant ever changes. **Each of the four returns 200
without the fix.**

**`reporting-tree` is NOT changed here, and that is a decision rather than an oversight.** *(Confirmed by the owner 2026-09-24: it stays tenant-wide. Open question 10 records the decision and the standing rule it establishes.)* A
tenant-wide org chart is a legitimate product feature and plenty of companies publish one. The
defect was never that it lists people; it was that two other endpoints trusted an identifier
to be secret when a supported endpoint hands it out. With both reads scoped, the tree leaks a
name and a reporting line — which an org chart is *for* — and no longer unlocks anyone's
movements. Whether the chart itself should respect `orgScope()` is **open question 10**.

**The lesson, and it is the same one twice in one day.** §12h reasoned about twelve policies as
a class and was wrong about one of them. §12i then read one file for a route key and was wrong
about which key. Both were cheap to check and neither was checked. The rule this file keeps
re-deriving: **a grep that returns nothing is evidence about the grep, not about the code.**

### 12k. The fail-without-fix claim — **VERIFIED IN CI 2026-09-24**

§12j's tests were verified to **pass with the fix** (CI run #425, seven of seven on
`f3a41f4`). That they **fail without it** was never demonstrated, and this section records
the attempt, the decision not to force it, and the change that makes the gap matter less.

**The attempt.** The suite cannot run in this environment. `api/vendor` existed as an empty
skeleton — 63 package directories holding no files, no `installed.json`. Three install modes
were tried and all three end at `Could not authenticate against github.com`:

| Mode | Result |
|---|---|
| `composer install --prefer-dist` | `AuthHelper.php:132` — auth failure, exit 100, 0 packages |
| `COMPOSER_AUTH` built from `$GITHUB_TOKEN` | rejected earlier: the variable's literal value is `proxy-injected`, a placeholder |
| `composer install --prefer-source` | same auth failure; the proxy's `gitConfigInjection` covers `git` but not composer's GitHub fetches |

`/root/.config/composer/auth.json` is an empty skeleton with `github-oauth` present and zero
hosts. **What it would need: a real GitHub token with `public_repo`**, as
`github-oauth."github.com"` in that file or in `COMPOSER_AUTH`.

**The decision: do not obtain the evidence from CI.** `gates.yml` triggers on
`push: branches: [main]` and `pull_request` — a pushed branch runs nothing. Demonstrating the
failure would require **opening a pull request that reverts an authorisation check**, which
stays in the repository's history permanently, plus a branch that cannot be deleted from this
environment (`git push --delete` fails at the transport layer; the GitHub MCP has no
delete-branch tool, which is why nine branches are already stranded). A permanent
"reverts the attendance fix" PR costs the audit trail more than the confirmation is worth.
~~**The claim stays reasoned, and is labelled as such wherever it appears.**~~ **Superseded 2026-09-24 — it is now measured.** The decision below not to buy the evidence from CI was correct *as a reading of the options then available*, and wrong about there being no other option: a `workflow_dispatch` workflow reverts the fix inside a runner and never writes to the repository, which is neither a pull request nor a branch. See the verification at the end of this section.

**What did change, because re-reading the tests against "fails for the right reason" found a
real weakness.** Both denial tests asserted only a 403. **An unseeded permission also produces
a 403** — so if `attendance.view` were somehow not granted, the test would pass *and would
have passed with the fix reverted*. A green test proving nothing, which is the exact failure
mode this file exists to catch, and it was sitting in the file written to prove a fix.

The premise was pinned by a *separate* test (`attendance.view` is granted to every role). That
is not enough: if that test fails, the two denial tests still report green.

Each denial test now reads an **in-scope record first and asserts 200**, in the same test,
before asserting the out-of-scope 403. The control proves the ability is held and the endpoint
works, so the denial can only be `orgScope()`/`canAccessEmployee()`. A setup failure now fails
the control rather than passing the assertion.

**This does not make the tests fail-without-fix — it makes them unable to pass for the wrong
reason.** The remaining claim is narrow and structural: with the fix reverted, `show()`
authorises on `attendance.view` alone, the control proves that ability is held, so the
out-of-scope read returns 200 and `assertForbidden()` fails. That is an argument, not a run.

#### The owner ran it — graded as an owner report, not a measurement

**2026-09-24, against `main` at `9a8e74a`.** Million ran the check locally, where composer
*can* authenticate, using:

```bash
cd api && composer install
FIX=f3a41f4                                     # PR #87 — the commit that scoped both reads
git diff $FIX^ $FIX -- app/ | git apply -R      # reverts the two controllers, keeps the tests
vendor/bin/pest --filter=AttendanceOrgScope     # without the fix
git checkout -- app/
vendor/bin/pest --filter=AttendanceOrgScope     # with the fix restored
```

**Reported: 5 passed, 2 failed.** Consistent with the predicted shape — without the fix, the
three positives (two controls plus the grant premise) pass and the two denials fail; with the
fix restored, all five pass.

**Graded `ASSUMED, owner report`, the same grade this file gives blocker B-7, and for the same
reason: the counts are testimony, not captured output.** A tally of 5/2 does not distinguish
the result we want from the one that would matter. Two failures look identical whether

- the **denials** failed because the out-of-scope read returned **200** — the evidence, or
- the **controls** failed on a setup problem while the denials passed for the wrong reason —
  which would mean the tests are still vacuous and the fix unproven.

Only the per-assertion failure text separates those, and it was not recorded here. **So the
in-repo status is unchanged: reasoned, corroborated by an owner run, not measured in this
repository.** Upgrading it to verified needs the run output — specifically that both denials
fail with `Expected response status code [403] but received 200` on the *second* request of
each test, and that `attendance.view is granted to every role` passes.

**This section is deliberately not claiming more than it has.** §12h reasoned about a class and
was wrong about a member; §12i read one file for a route key and got the key wrong. Both are
recorded above. Writing "verified" over a two-number summary would be the third instance, in
the section that exists to document the first two.

#### Measured — CI run 36010809626, 2026-09-24

**Run:** <https://github.com/syntax-ict/Ethr.et/actions/runs/36010809626> ·
workflow `.github/workflows/verify-without-fix.yml` (#95) ·
`fix_sha=f3a41f439db5b09d9e484a1ebc9bc28ac57835c8` (#87) · `filter=attendance`

**Without the fix — `Tests: 2 failed, 126 passed (326 assertions)`:**

| Test | Result |
|---|---|
| an employee **can still read their own** attendance record | ✅ pass — control |
| a supervisor **can still read** the timeline of a direct report | ✅ pass — control |
| attendance.view is granted to every role | ✅ pass — premise |
| an employee **cannot** read a colleague's attendance record | ❌ **fail** |
| a supervisor **cannot** read the timeline of a non-report | ❌ **fail** |

Both failures, verbatim:

```
Expected response status code [403] but received 200.
Failed asserting that 200 is identical to 403.
  at tests/Feature/Security/AttendanceOrgScopeTest.php:57      (and :109)
```

**Both fail on the *second* request of their test**, which is the distinction that
mattered. The trace shows line 54 (`assertOk()` on the in-scope read) passing and line
57 (`assertForbidden()` on the out-of-scope read) failing; likewise 106 and 109. So the
out-of-scope record was **returned with 200**, and the failure is the missing org-scope
check rather than a setup problem — the exact ambiguity §12j's controls were added to
remove, now demonstrated rather than argued.

**With the fix restored — `Tests: 128 passed (326 assertions)`.** All five pass.

**Scope of the revert.** `git status --short` after restoring printed **nothing**, so the
patch touched only paths under `app/`; and `git diff f3a41f4^ f3a41f4 -- api/app/` is
2 files, 12 insertions, 1 deletion — the two controllers. *(The run's own `DIFF STAT`
line sits earlier in the log than the retrievable tail, so that pairing is how the scope
was established, not by reading the printed line.)*

**Why this was possible after §12k said it was not.** The earlier reasoning held that
getting CI evidence meant opening a pull request that reverts an authorisation check,
permanently in the history. That was true of the options then considered and false in
general: `workflow_dispatch` with `permissions: contents: read` reverts inside the
runner's working tree for one step and restores it before the step ends. Nothing reverted
is committed, pushed, or visible outside the run. **The constraint was real; the conclusion
that no route existed was not.**


---

## 13. Known blockers

### 13a. ~~Payroll runs synchronously in the HTTP request~~ — **fixed 2026-09-15**

> `PayrollController::process` now calls `PayrollEngine::begin()` (reserve the run) and dispatches `ProcessPayrollJob`, returning **202** with the run at `processing`. The engine was **split, not rewritten** — `process()` still calls `begin()` plus `runEntries()`, so every existing caller and all 34 payroll tests pass unchanged. Master plan §20 says do not redesign payroll calculation, and none of it moved.
>
> `tries = 1` deliberately: idempotency protects against a client replaying the request, not against the job running twice on the same run, which would append a second set of entries. `failed()` marks the run `failed` so a crashed run cannot sit at `processing` — that ambiguity was half of what made the 504 bad.
>
> Frontend polls while a run is in flight and stops on `completed`, `failed`, `approved` or `voided`.
>
> Six tests fake the queue, because the rest of the suite runs under `QUEUE_CONNECTION=sync` where the job executes inline and would pass either way. Reverted to inline processing, three go red.
>
> Original finding follows.

`api/app/Http/Controllers/Api/V1/Payroll/PayrollController.php:38` calls `$engine->process(...)` inline. `api/app/Services/Payroll/PayrollEngine.php:89` chunks (`chunkById(100)`) over every active employee, computing tax, pension, overtime, loans, allowances and cost-sharing per row. **No job wrapper, no `set_time_limit`.**

The project's own budget at `docs/CLAUDE.md:858` is *"Payroll calculation (500 employees) < 30s"* — on dedicated hardware. Typical shared-hosting `max_execution_time` is 30–120s with a FastCGI read timeout behind it. **The documented best case already sits at the limit.** Highest-impact functional risk found, and recorded in no existing repo document.

### 13b. Audit-log triggers **[verified]**

`api/database/migrations/2026_07_22_000001_restrict_audit_log_to_insert_only.php:53` creates `audit_log_no_update` and `audit_log_no_delete`, each raising `SIGNAL SQLSTATE '45000'`. The migration **fails fast and aborts the whole run** if `CREATE TRIGGER` is denied — deliberate, per `docs/AUDIT_LOG_INTEGRITY_DECISION.md`.

Two risks are recorded nowhere:

- **No explicit `DEFINER`.** MySQL stores `DEFINER = CURRENT_USER`. Panel-driven backup/restore commonly recreates a database under a different user; when the definer no longer exists, **every `INSERT` into `audit_log` fails** — and that is 206 call sites inside mutation transactions. Restore the database and every create/update in the product returns 500.
- **Operational lock-in** — `audit_log` is permanently append-only at engine level; any repair or assisted restore touching it requires dropping the trigger first.

Whether the production DB user holds `TRIGGER` is **NOT VERIFIED**.

#### Measured 2026-09-15 — MariaDB 10.4.32, local **[verified]**

The first bullet above was reasoning, not measurement. It has now been measured, and the reality is **worse in a different way than it described**. Against a restricted user (`ALL PRIVILEGES` on its own database, no `SUPER` — the shared-hosting shape):

| Trigger as restored | Result |
|---|---|
| `CREATE DEFINER=\`ghost\`@\`localhost\` TRIGGER …` | **`CREATE TRIGGER` itself is refused** — `SQLSTATE[42000] 1227 Access denied; you need (at least one of) the SUPER privilege(s)` |
| `CREATE TRIGGER …`, no DEFINER clause | Created. `INSERT` succeeds, `UPDATE` rejected, definer recorded as the restoring user |

So the failure does not arrive later as failing inserts. **The restore aborts at the trigger statement**, because naming any definer other than yourself requires `SUPER` and shared hosting does not grant it. A conventional `mysqldump` — and `SHOW CREATE TRIGGER`, which emits `CREATE DEFINER=\`root\`@\`localhost\` TRIGGER …` verbatim, confirmed here — therefore produces a dump this product **cannot restore on its own production host at all**.

That makes `DatabaseDumper`'s reconstruction of triggers from `SHOW TRIGGERS` without a DEFINER clause a requirement rather than a precaution, and it means Plesk's own database backup tooling must not be relied on as the recovery path until someone has restored one of its dumps on the host and watched the triggers come back.

**Still NOT VERIFIED:** any of this on Ethio Telecom's server. 10.4.32 is the local XAMPP build; the documented target is MariaDB 10.11, and the host's version is unread (G0-E).

### 13c. ~~Horizon blocks `composer install`~~ — **RESOLVED**, see §3a and §15 row 6. Horizon was removed in `cdf85d1`; the lockfile carries zero hard `pcntl`/`posix` requires.

### 13d. ~~Unindexable login lookups~~ **[verified 2026-09-15; FIXED 2026-09-23 — see §15e]**

> **Fixed 2026-09-23.** The measurements and the reasoning below stand and are kept in full — including the conclusion *not* to fix it, which was correct for the two options it weighed. A third option closed it: generated columns carrying the same normalisation, indexed, identical on both drivers. §15e says what changed and what is still outstanding (the benchmark below has not been re-run).
>
> **The same wrappers elsewhere are a different question, and the answer is "leave them".**
> `App\Services\Identity\IdentityResolver::fetchCandidates()` wraps `badge_number`,
> `email` and `name` in `LOWER()`, and the obvious reading of this entry is that §15e's fix
> applies there too. **It does not.** That query is one tenant-scoped `AND` over a six-way
> `OR`, and a disjunction cannot use an index on any of those columns however they are
> written — measured three ways (as it stood, with two terms moved to generated columns,
> and with *every* term indexed) against a control with the `OR` removed, which is the only
> variant that seeks. **Do not tidy those wrappers expecting a speed-up; there is none to
> get.** The full reading is §15h. If the path ever needs to be fast the fix is structural
> — one indexed equality per identifier, unioned in PHP — and that changes matching
> behaviour.

`api/app/Services/Auth/AuthIdentifierResolver.php:104-118` runs a 6-deep nested `REPLACE(REPLACE(...))` on `phone` inside `whereRaw`, and lines `:86`, `:136`, `:147` plus `LoginRequest.php:69` use `LOWER(email) = ?`. Neither expression can use an index; there is no `phone` index at all and no functional index on `email`.

> **Corrected 2026-09-15 — this entry originally said "full table scan of `users`, then of `employees`". That overstated it.** Both queries filter on `tenant_id` first, and both tables carry `index('tenant_id')` (`0001_01_01_000000_create_users_table.php:77`, `0001_01_01_000003_create_organization_tables.php:31`), so the scan is bounded to **one tenant's rows**, not the whole table.
>
> The defect is real but smaller: O(rows-in-this-tenant) `REPLACE` evaluations per phone login. Negligible at a few hundred employees; it starts to matter in the thousands, on a contended shared vCPU, on the one request where slowness is most visible.
>
> Recorded rather than quietly edited, because the original claim was repeated in several commit messages that cannot now be amended — and because overstating a finding is the same failure as understating one.

> **G0-J measured locally, 2026-09-17 — real numbers before any index decision.** The instruction this followed was explicit: measure the actual query behaviour before proposing a login index. `api/scripts/login-path-benchmark.php` seeds one tenant at 500 / 5,000 / 20,000 employees+users against the real migrated schema on local MariaDB 10.4.32 (matching production's engine; not production's hardware — see the caveat below), and times the actual `AuthIdentifierResolver` code path, not a synthetic query.
>
> **Finding, corrected from the line above: a matching index already exists.** `users` carries `users_tenant_id_email_unique` on `(tenant_id, email)` and `users_tenant_id_username_unique` on `(tenant_id, username)`; `employees` carries `employees_tenant_id_employee_code_unique` on `(tenant_id, employee_code)`. All three columns are `utf8mb4_unicode_ci` (case-insensitive), set from `config/database.php`'s connection-level `collation`, not per-column. So `LOWER(column) = ?` is not compensating for a case-sensitive column — the column already matches case-insensitively — it is only defeating the index that would otherwise serve the query. "No functional index on email" above is true and also not the relevant fact: **no functional index is needed**, because a plain index already fits.
>
> Measured (ms per lookup, average of 49 distinct values per tenant size, one warm-up call discarded):
>
> | n (employees) | email current | email if plain `=` | username current | username if plain `=` | employee_code current | employee_code if plain `=` | phone (REPLACE, current) |
> |---|---|---|---|---|---|---|---|
> | 500 | 3.0 | 1.6 | 3.2 | 1.6 | 5.0 | 2.1 | 3.8 |
> | 5,000 | 3.7 | 1.4 | 10.3 | 1.2 | 5.2 | 1.3 | 13.1 |
> | 20,000 | 11.6 | 1.3 | 39.8 | 1.4 | 12.8 | 1.8 | 52.0 |
>
> `EXPLAIN` on the current email query at n=20,000: `type=ref key=users_tenant_id_email_unique rows=10000` — the composite index *is* used to narrow to the tenant, then every one of that tenant's ~10,000 rows is evaluated through `LOWER()` row-by-row, exactly the "O(rows-in-this-tenant)" shape already described above, now with a number attached. The plain-equality version: `type=const key=users_tenant_id_email_unique rows=1` — a single index seek regardless of tenant size. This is the O(n)-vs-O(1) signature predicted, measured on the real schema, not inferred.
>
> **Why this is not fixed here.** The obvious edit — drop the `LOWER()` wrapper on the column — is correct on MariaDB because of the collation above, but was checked and found **incorrect on SQLite**, which the primary test suite runs on:
>
> ```
> SQLite, stored value "Abc@X.com":
>   WHERE email = 'abc@x.com'          -> 0 rows  (SQLite TEXT is case-sensitive by default)
>   WHERE LOWER(email) = 'abc@x.com'   -> 1 row
> ```
>
> SQLite has no collation-level case-folding for a bare `=`; matching case-insensitively there needs either `LOWER()` in the query or a `NOCASE` column collation applied specifically for that driver. A fix that behaves identically on every driver is therefore a **schema change** — a driver-conditional column collation — not a one-line code edit, which puts it in the same risk class as adding an index. It has not been made without that being a deliberate decision, for the same reason a login index has not been added speculatively.
>
> **What would justify acting, read against ETHR's own numbers.** `email` is the default and only identifier every tenant has (`AuthIdentifierResolver::DEFAULT`); `username`/`employee_code`/`phone` are opt-in per tenant (ONBOARDING_V2 D6/D7). At the size the payroll SLA already treats as large (500 employees, `docs/CLAUDE.md`'s 30s/500-employee budget), the current email cost is **3.0ms** — not worth a schema change on its own. At 20,000 — a tenant size nothing in this codebase's documented targets currently assumes — email is 11.6ms and username 39.8ms: real, but still small against typical request/network overhead, and this was measured on ordinary local hardware, not the production shared vCPU Gate 0 has not yet characterized. **The number that would justify acting is a real tenant's employee count approaching four figures with `username` or `employee_code` enabled**, or a G0-J Plesk CPU-loop result (Step 1 of the Gate 0 probe) showing the shared vCPU is materially slower than this machine, which would scale every row above proportionally.
>
> **How to repeat this after any change.** Re-run `php scripts/login-path-benchmark.php` against a scratch `*_bench*` database (it refuses any other name) before and after. A real fix should collapse every "current" column to match its "if plain `=`" column at every tenant size; if it does not, the fix did not reach the index.

### 13f. Employee search behaved differently on the driver production uses **[verified 2026-09-15, MariaDB 10.4.32 — FIXED]**

`api/app/Models/Employee.php:130` has **two implementations of the same feature**:

```php
if (DB::getDriverName() === 'mysql' || 'mariadb') {
    // MATCH (name, name_am, email, employee_code) AGAINST (? IN BOOLEAN MODE)
}
// else: LIKE %term% across the same four columns
```

The suite runs on SQLite, so **only the `LIKE` branch has ever been executed by a test**. Running the suite against MariaDB executes the other branch for the first time. Measured against a real `FULLTEXT` index (`emp_search`, 4 columns), with committed rows:

| A user types | Expression built | Rows found |
|---|---|---|
| `1234` (part of a code) | `1234*` | 1 ✓ |
| `Meseret` | `Meseret*` | 1 ✓ |
| **`EMP-1234`** (the whole code) | `EMP1234*` | **0** ✗ |

**The defect.** `scopeSearch()` stripped boolean operators — including `-` — from the term before appending `*`, so `EMP-1234` became `EMP1234`. `FULLTEXT` had indexed that value as the two tokens `EMP` and `1234`; no token is `EMP1234`, so the most natural thing a user can type found nothing. Invisible on SQLite, where `LIKE '%EMP-1234%'` matches.

**A correction to an earlier draft of this section.** It also claimed Amharic search returned nothing through `FULLTEXT`, with a table of zeroes. That was wrong, and wrong through my own error: the probe that produced it seeded through a PDO connection with no `charset`, so the Ethiopic text was stored double-encoded and every number taken from it described mojibake rather than behaviour. Re-measured over `utf8mb4`, `MATCH` and `LIKE` agree on every Amharic term tried — `አበበ`, `ከበደ`, `ትዕግስት`, `መሰረት`, `አበበ ከበደ`, all 1/1. **Amharic search was never broken.** Recorded rather than quietly deleted, because a fabricated defect wastes the same attention a missed one does.

**Why the fulltext expression was not repaired instead.** The obvious fix is to tokenise and require each token, `+EMP* +1234*`. Measured, that fixes the code (1 row) and **regresses a name**: an employee called `Ab Kebede` is found by `Ab Kebede*` and is *not* found by `+Ab* +Kebede*`, because `innodb_ft_min_token_size = 3`, the two-character token `Ab` was never indexed, and a required term matching nothing eliminates the row. Working around that means reading a server variable this project cannot see on its target host.

**Fixed 2026-09-15 by deleting the branch.** `scopeSearch()` now uses `LIKE` on every driver, so the tested path and the production path are the same code. Cost, measured on MariaDB 10.4.32 with 5,000 employees in one tenant: **~13ms**, against the **100ms** budget at `docs/CLAUDE.md:850-862`. The scan is bounded by `tenant_id`, which is indexed. On that hardware the budget is reached somewhere near 40,000 employees in a single tenant — revisit if a tenant approaches that. §13e still applies: no measurement of any budget exists on the actual host.

**Left behind — removed 2026-09-16** by `2026_09_16_000001_drop_employee_fulltext_index`. While it stood it cost write throughput on most employee writes and implied a fulltext search that no longer existed. Round-tripped on MariaDB 10.4.32 (drop, restore, drop) with existence guards both ways; SQLite skips.

### 13g. Fulltext indexes are invisible inside a transaction **[verified — no longer affects search]**

While diagnosing §13f: **InnoDB maintains `FULLTEXT` indexes at commit.** `RefreshDatabase` wraps every test in a transaction it never commits, so rows a test creates cannot be found by `MATCH … AGAINST` at all. Measured:

| | plain `WHERE name = ?` | `MATCH … AGAINST` |
|---|---|---|
| Committed row | 1 | 1 |
| Row inside an open transaction | **1** | **0** |

This meant the MySQL search branch was not merely untested but **untestable** under this harness at any driver setting — a second, independent reason the §13f defect survived. The §13f fix removes it as a search concern, because `LIKE` reads uncommitted rows normally; the two `EmployeeManagementTest` search cases now pass on MariaDB.

Recorded because the property is general, not specific to search: **any future feature built on `MATCH … AGAINST` will appear broken under the test suite and work in production.** The schema now has no fulltext index at all — `emp_search` was the only one, and it was dropped on 2026-09-16 — so this is a warning for whoever adds the next one, not a description of anything present.

### 13e. No performance baseline existed — **first one recorded 2026-09-17**

`api/tests/Performance/ResponseTimeTest.php` runs against SQLite and is excluded from the default sweep. The budgets at `docs/CLAUDE.md:850-862` were set against dedicated hardware. **No shared-hosting measurement exists — NOT VERIFIED**, and that part is unchanged.

> **Why there was no local baseline either, which turned out to be a gate defect rather than an oversight.**
>
> `./scripts/gates.sh performance` delegated **unconditionally** to `scripts/pest-isolated.sh`, which exits 1 with "Container et-api-1 is not running" when there is no container. So the scope was unrunnable on any machine without Docker — every CI runner, and any developer with native PHP and Docker stopped.
>
> That is the **identical defect** that left PHPStan unable to pass in CI for fifty runs (CI cause 3 in the root `CLAUDE.md`). `pest_gate` and `phpstan_gate` were both made native-first when that was found; this scope was missed, because it sits outside the blocking sweep and so nothing ever ran it. A gate nobody runs does not report its own breakage. It is now native-first with the container as fallback, the same shape as the other two.
>
> **The benchmark also threw its own measurements away.** Each case did `expect($ms)->toBeLessThan(500)` and nothing else — enough to answer "did it pass", but it meant a route could drift from 20ms to 490ms and stay green the whole way with nobody seeing it coming. `assertWithinBudget()` now prints the measured figure, the budget, and the percentage consumed, so the gate output *is* the baseline.
>
> **Measured, native PHP 8.2.12 on this machine, SQLite `:memory:`, best-of-3 per case** (the existing `timedRequest()` helper's own method — three runs, fastest kept, to filter GC and disk noise rather than to flatter the result):
>
> | Case | Measured | Budget | Used |
> |---|---|---|---|
> | employee list, 1000 rows, filtered+sorted | 14.8 ms | 500 ms | 3% |
> | employee search (LIKE) over 1000 rows | 5.3 ms | 100 ms | 5% |
> | attendance list, 50 employees × 30 days | 32.5 ms | 300 ms | 11% |
> | executive dashboard, cache miss | 10.7 ms | 200 ms | 5% |
> | manager dashboard | 2.6 ms | 200 ms | 1% |
> | payroll run detail with entries | 39.6 ms | 300 ms | 13% |
> | leave balance calculation | 3.8 ms | 100 ms | 4% |
>
> Two consecutive runs agreed within noise (worst case 37.2 → 39.6 ms).
>
> **What this is, and is not.** It is a regression baseline on *this* hardware against SQLite, which is what the file itself says it is for. It is **not** a production benchmark: production is MariaDB on a shared vCPU, and neither variable is represented here. What it does give is a scaling factor that was previously unavailable — the tightest case uses **13%** of its budget, so these routes would tolerate roughly a **7–8× slower** environment before any budget fires. Gate 0's P1 (`3M-iteration loop`, `GATE-0-RESULT.md`) measures exactly that ratio on the real host, which converts this table into a prediction rather than leaving it a local curiosity.
>
> The search figure also corroborates §13f independently: 5.3 ms over 1000 rows on the `LIKE` implementation that replaced the `MATCH…AGAINST` branch, consistent with the ~13 ms at 5,000 employees measured there.
>
> **The frontend half of the same question is §18**, measured the same day by a separate session via `./scripts/gates.sh lighthouse`. The two are complementary and neither substitutes for the other: this section is server response time under load, §18 is what a browser experiences on the public site. Both were absent until 2026-09-17, which is why §13e's original title claimed there was no baseline at all.

---

## 14. Hosting dependencies

**[verified]** Production assets assuming VPS / Docker / root / SSH:

- Six `docker-compose*.yml` files at root; `docker/` build inputs; `infrastructure/` (nginx, supervisor, certbot)
- **`scripts/backup.sh:53`** — `docker compose exec -T mariadb mysqldump`; **`:63`** — `docker run --rm --volumes-from ethr-minio`. `restore.sh`, `rollback.sh`, `deploy.sh` and `prod-build-test.sh` are the same shape. **There is no non-Docker backup or restore path in this repository.**
- `RUN_ALL.ps1` / `START_BACKEND.ps1` / `START_FRONTEND.ps1` — Windows dev launchers predating the Docker work. `RUN_ALL.ps1` prints "SQLite" where README prescribes MariaDB, and starts neither the queue worker nor Reverb, both of which README calls mandatory.

Application-level hosting coupling is low: no shell-outs, no Redis calls, no absolute paths in `app/`.

**Every hosting capability question — PHP version, extensions, `.htaccess`, cron type, `CREATE TRIGGER`, Node.js, SMTP, quotas — is NOT VERIFIED.** `docs/HOSTING_VERIFICATION_CHECKLIST.md` records roughly 60 rows, all `NOT VERIFIED`, and states its own position plainly: *"NOT VERIFIED is not a soft yes."* The probe `scripts/hosting-verification/ethr-hosting-check.php` has only ever been run against the local Docker stack.

---

## 15. Production risks (ranked)

| # | Risk | Evidence | Severity |
|---|---|---|---|
| 1 | **No backup or restore path for any non-Docker host** — **built 2026-09-15**, round-trip tested locally, and **rehearsed on MariaDB in CI on every push since 2026-09-23**; **still not rehearsed on the Ethio Telecom host** | `ethr:backup` / `ethr:restore`, `BackupRestoreRehearsalTest` **[verified locally]**; the `Backup restore rehearsal on MariaDB` job, first green on `main` at `8b11904` **[verified in CI]** | **High** (was Critical) — still a go-live gate. §15f/§15g are why the severity has not moved further: the path carried a defect that made a dump replayable only on a permissive engine, every test of it was green, **and the first written account of the defect was itself wrong in two places**. A backup guard now runs at create time and the fixtures cover every table with a generated column |
| 2 | ~~**Cross-tenant import lookup (P0-1)**~~ — **fixed in Phase 0.5**; the lookup now states `tenant_id` itself (`EmployeeImporter.php:110`), and `withoutGlobalScopes()` deliberately stays so the soft-delete scope is still dropped (D-001) | `TenantImportIsolationTest` — 4 tests, 9 assertions, green **[verified]** | Resolved |
| 3 | ~~Payroll times out mid-transaction~~ — **queued** (`ProcessPayrollJob`), 202 + polling | `PayrollQueuedProcessingTest` **[verified]** | Resolved |
| 4 | ~~Duplicate job execution~~ — **fixed** (`76ca983`), invariant now tested | `QueueRetryAfterInvariantTest` **[verified]** | Resolved |
| 5 | ~~**Audit-log `DEFINER` breaks after restore** → all writes 500~~ — **fixed**; `DatabaseDumper` reconstructs triggers from `SHOW TRIGGERS` with no `DEFINER` clause, so the restoring user becomes the definer. Measured 2026-09-16 on MariaDB: a DEFINER-carrying dump is refused outright without `SUPER` (§13b), and a non-root user restores and enforces both triggers | `ethr:backup:rehearse` on MariaDB; `BackupRestoreRehearsalTest` **[verified]** | Resolved — but only for `ethr:backup`. A Plesk panel export still carries a DEFINER and may be unrestorable; see §13b |
| 6 | ~~Horizon aborts `composer install`~~ — **removed** (`cdf85d1`); lockfile carries **zero** hard `pcntl`/`posix` requires | lockfile parsed **[verified]** | Resolved |
| 7 | ~~Two critical RCE advisories in a production dependency~~ — **fixed 2026-09-15** (`ae52e08`) | `npm audit --omit=dev` **[verified]** | Resolved |
| 7b | ~~No CI of any kind~~ — **configured in Phase 2, never executed** | `.github/workflows/` **[verified]** | Medium (was High) |
| 8 | ~~19 commits exist only on this machine~~ — **pushed 2026-09-15**, 32 commits on `origin` | `git push` exit 0 **[verified]** | Resolved |
| 9 | ~~Queue can stop silently~~ — **heartbeat + `ethr:queue:check` built**; alert transport still needs G0-H | `QueueHealthTest` **[verified]** | Low (was Medium) |
| 10 | **No coverage instrumentation**; billing near-untested — first billing tests added 2026-09-15, which immediately found §15b | `phpunit.xml`, `vitest.config.ts` **[verified]**; `Backend coverage (PCOV)` job + `gates.sh coverage` **[verified in CI]** | **Closed as instrumentation; open as coverage.** Backend **86.7% MEASURED 2026-09-23** (§12e), frontend **32.34%** with 170 of 321 files at 0% (§12f). The backend total is far higher than a row reading "no coverage instrumentation" since the baseline would suggest — the suite was better than its instrumentation. **What the total hides is the point**: §12g lists the zero-coverage files, and two of them are the traits enforcing conventions 5 and 14 |
| 15 | ~~Monthly invoicing had no idempotency guard — any re-run double-billed every tenant~~ — **fixed** (§15b) | `MonthlyInvoiceIdempotencyTest` **[verified]** | Resolved |
| 16 | ~~Plan-change proration unclamped — an upgrade on an expired period reported a credit~~ — **fixed** (§15c) | `PlanChangeProrationTest` **[verified]** | Resolved |
| 17 | ~~A 60-day-overdue invoice was never escalated if earlier tiers were missed~~ — **fixed** (§15d) | `OverdueInvoiceEscalationTest` **[verified]** | Resolved |
| 18 | ~~Nothing transitions an invoice from `draft` to `sent`~~ — **owner decided 2026-09-15**, invoices are created `sent`; chain verified end to end | `OverdueInvoiceEscalationTest` **[verified]** | Resolved |
| 19 | ~~`due_date` stored with a time component against a `date` column — escalations fired a day late on SQLite, on time on MySQL~~ — **fixed** | §15d **[verified]** | Resolved |
| 11 | ~~**No tenant-isolation regression enforcement**~~ — **built 2026-09-16**. `TenantScopeBypassInventoryTest` pins every `withoutGlobalScope(s)` call site in `app/`, per file, and fails when the count moves — **156 across 53 files** when built, **157 across 55** today — the 161 recorded on 2026-09-18 overcounted by exactly five, every one a docblock rather than a call, and the real figure then rose by one in §11h. Proven to fail: injecting one bypass produced `COUNT CHANGED (1 -> 2)` | `tests/Feature/Security/tenant-scope-bypasses.php` **[verified]** | Resolved. The count makes adding a bypass deliberate; the audit it could not do was then done by hand — §11c, §11d, §11i, §11j. **113 of 157 sites prove their own safety, 44 do not, and none can reach another tenant's rows.** The 44 are gates, pre-authentication secrets, global models and sweeps, where a predicate is impossible or contrary to the feature |
| 12 | ~~**Unindexable login scans**~~ — **fixed 2026-09-23** (§15e). All four identifier lookups wrapped the column in a SQL function, so each one scanned every row belonging to the tenant — and on the phone path the tenant's employees too. Five VIRTUAL generated columns and a `(tenant_id, …)` index on each; matching semantics unchanged | `LoginIdentifierIndexTest` — plan read per driver; `LoginIdentifierTest` untouched and green **[verified]** | Resolved |
| 13 | ~~**Documentation asserts controls that do not exist**~~ — **corrected in Phase 1** (D-003); the four documents now describe what is true, and the CI they claimed exists and runs | `docs/CLAUDE.md`, `SECURITY.md` + 2 **[verified]** | Resolved — the failure mode recurred in a new form, though: CI then *existed* and had never passed. See the CLAUDE.md CI section |
| 14 | **All hosting capabilities unverified** | checklist **[verified]** | Blocks Gate 0 |

---

### 11c. The tenant-scope bypass audit — first pass **[2026-09-16]**

> **This is the `11c` that other documents cite.** A second section earlier in this file —
> *File uploads and audit logging* — also carries the number. §11d below records why
> neither is renumbered.

Risk #11 recorded that `TenantScopeBypassInventoryTest` pins all 156 `withoutGlobalScope(s)` call sites but "does not audit the 156 that exist — that audit is still unowned". This is the first pass of it.

**Method.** For every call site, read ±8 lines and ask whether a tenant predicate is stated or derived there — `tenant_id`, `tenant()`, `CurrentTenant`. **123 of 156 had one. 33 did not**, and all 33 were read individually.

**Result: one real defect, and it was reachable.**

| Verdict | Sites | Notes |
|---|---|---|
| Cross-tenant by design, behind `Gate::authorize('admin.manage')` | 12 | `AdminTenantController` ×7, `AdminDashboardController`, `PlatformAnalyticsService` ×3, plus the platform billing jobs' super-admin lookups |
| Pre-authentication, where the presented secret *is* the authority | 4 | `ScimAuth` (API key hash), `PersonalAccessToken::tokenable()`, `SubdomainCheckController` (a signup availability check is global by definition), `EnsurePlatformContext` (asserts *no* tenant is resolved) |
| Derived from a key that is itself tenant-owned | 12 | `PayrollEntry` by a `$run` already scoped by `tenant_id`; `Invoice` by `$subscription`; `Employee` by `$balance->employee_id`; the device-webhook resolver, already hardened by audit finding F-1 — a token authenticates, a serial only *selects* and needs an allowlisted source IP |
| False positive of the ±8-line window | 1 | `EmployeeImporter` states its predicate 10 lines below the bypass |
| **Defect** | **4** | `DispatchWebhookJob` / `WebhookDispatcher`, below |

**What was wrong.** `WebhookDispatcher::queue()` wrote `WebhookDelivery::create()` with no `tenant_id`, leaving `BelongsToTenant::creating` to supply one from `CurrentTenant`. Two ways that fails, both measured:

1. **A queue worker resolves no tenant.** `webhook_deliveries.tenant_id` is `foreignId()->constrained()` — NOT NULL — so the insert died on `SQLSTATE[23000]: NOT NULL constraint failed: webhook_deliveries.tenant_id`. The payload JSON being inserted *contained* `"tenant_id":1`; the correct value was in hand and simply never written to the column.
2. **`CurrentTenant` is a `singleton`, not a `scoped` binding**, so it is not flushed between jobs. A worker still holds whatever the previous job set. Measured: dispatching for tenant 1 while tenant 2 was resolved filed the delivery **against tenant 2**.

**Why nobody saw it.** `DispatchesWebhooks::webhook()` wrapped the call in `catch (\Throwable)` with an empty body — "never let webhook dispatch fail a business operation". That reasoning is right; the empty body is not. It swallowed an integrity violation that meant `payroll.processed` could never be delivered from a queued context at all, and logged nothing.

**Reachable, not hypothetical.** `ProcessPayrollJob` uses the trait and dispatches `payroll.processed`. Its own comment says jobs "carry no HTTP tenant context, so the global scope would resolve to `whereRaw('0 = 1')`" — and it scopes its own `PayrollRun` lookup by hand because of it. The webhook path beneath it did not.

**The read side had the same hole.** `DispatchWebhookJob::handle()` looked the delivery up through the global scope, so in a worker it resolved to `0 = 1`, found nothing, and returned through its `! $delivery` guard — indistinguishable from a delivery that had been deleted. The endpoint was never called.

**Fixed.** `queue()` states `tenant_id` from the webhook, which is itself tenant-owned; the job re-applies the predicate by deriving from the webhook; the trait still suppresses the failure but logs it at `error`. `WebhookDeliveryTenantScopeTest` — 3 tests — fails on each half of the old behaviour. The inventory for `DispatchWebhookJob.php` moves 1 → 2, which is the gate working as designed.

**What this pass does not claim.** The 123 sites with a nearby predicate were *not* individually audited. Having a predicate is necessary, not sufficient — it could be the wrong one. This pass covered the 33 that had none, which is where the one known prior defect (P0-1) would also have shown up.

**And the webhook defect was a symptom of something wider.** `CurrentTenant` was bound with `$this->app->singleton(...)`.

Inside an HTTP request `singleton` and `scoped` are indistinguishable — resolved once, cached. The difference is the queue worker, which is a long-running process handling many tenants' jobs in turn. `Illuminate\Queue\QueueServiceProvider:262` hands the Worker a `$resetScope` callback that calls `$app->forgetScopedInstances()`, and `Worker::daemon()` invokes it at the top of every loop iteration, *before* reserving the next job (`Worker.php:191`). A `scoped` binding is cleared between jobs. A `singleton` is not.

**Eight queued jobs and one queued listener call `$currentTenant->set($tenant)`. Nothing in `app/` calls `forget()` — not once.** So the next job on that worker inherited whichever tenant ran before it, and `BelongsToTenant` scoped its queries to a tenant it had never heard of instead of failing closed.

Measured, calling the framework's own reset rather than simulating one:

```
Tenant A resolved, one employee created   ->  Employee::count() === 1
app()->forgetScopedInstances()            ->  Employee::count() === 1   <-- leaked
```

Fail-closed is the property this whole product rests on, and it only holds while "no tenant resolved" is actually reachable. Bound as a singleton in a worker, it was not.

Now `$this->app->scoped(...)`. The same measurement returns 0. `TenantContextLeakBetweenJobsTest` pins both halves, and the full backend suite is green on the change — **1730 passed, 5115 assertions** — so nothing was relying on the context surviving.

**Failing closed made two more defects findable, and they are the same defect twice.** With the context no longer inherited by accident, "this code needs a tenant and does not have one" becomes deterministic instead of intermittent. A scan of `app/Jobs`, `app/Listeners`, `app/Console/Commands`, `app/Notifications` and `app/Observers` for queries against the 60 tenant-scoped models, in files that neither set a tenant nor drop the scope, returned exactly two:

| Site | What happened |
|---|---|
| `DispatchWebhookJob::failed()` | A second `WebhookDelivery::find()`, in the failure handler. After the last retry is exhausted the job writes "Permanently failed" onto the delivery row — but looked it up through the global scope, so on a worker it found nothing. The log line fired; the row the tenant reads in the deliveries dialog kept whatever transient state it had. |
| `NotifyDeviceOffline` | Queued listener. It **did** state the predicate — `User::where('tenant_id', $device->tenant_id)` — but never dropped the scope, so the query became `where 0 = 1 and tenant_id = <the right tenant>` and matched nobody, always. A device going offline is a monitoring alert; the failure mode was notifying no one, silently. |

The second is worth dwelling on: **stating the tenant is not sufficient on its own.** `withoutGlobalScope` and the predicate are two halves of the same requirement, and a call site with only the predicate fails exactly as completely as one with neither — just more confusingly, because the code looks correct. It is also the same shape §15d already recorded in `HandleOverdueInvoicesJob` ("the job carries no HTTP tenant context, so `BelongsToTenant` resolved to `whereRaw('0 = 1')` and it found nobody, every time"). Fixed there in September; still live here until now.

Both are fixed and pinned — `WebhookDeliveryTenantScopeTest` (4) and `QueuedListenerTenantScopeTest` (2), each failing against the old behaviour. The second listener test also checks the fix did not simply widen the query: another tenant's admin must still not be notified.

**Running total for this audit: four call-site defects plus the container binding, across four files** — `WebhookDispatcher::queue()`, `DispatchWebhookJob::handle()` and `::failed()`, `NotifyDeviceOffline`, and `TenantServiceProvider`. One of them wrote a row against the wrong tenant; the other three were the opposite — a silent no-op where a tenant should have been told something.

That asymmetry follows from the design. `BelongsToTenant` fails closed, so getting the context wrong usually loses the operation rather than exposing it — and a lost operation has nothing to report itself. It is the quieter failure, and therefore the one that survives longest.

**The same question, asked of the audit trail.** `AuditLog::record()` takes its tenant from `CurrentTenant` and nothing else. `audit_log.tenant_id` is **nullable**, so with no tenant resolved the insert succeeds and the row is written with no owner — no error, no warning, nothing to notice.

The consequence is not a missing row. It is a row **the tenant cannot see**: their audit view scopes by `tenant_id` and NULL never matches, while the platform-admin view (`withoutGlobalScopes()`) shows it unattributed. Two call sites reach `record()` from a context with no tenant, and the first is the one that matters:

- **`ProcessPayrollJob`** — `payroll.processed` and `payroll.failed`. In an HCM product those are close to the most audit-relevant events there are, and both were landing unowned.
- **`BackupRehearsalCommand`** — a console command, same shape.

`record()` already receives the auditable model, and that model carries the tenant. Using it when the context has none corrects **all 206 call sites without touching one of them**, in the same shape as the timezone default: a correct fallback rather than 206 edits. A resolved tenant still wins, and a platform action with no auditable stays unowned rather than having an owner invented for it — `AuditLogTenantAttributionTest` pins all four cases, and the two controls passed before the change as well as after, which is what makes the other two meaningful.

---

### 11d. The tenant-scope bypass audit — second pass **[2026-09-22]**

> **Numbering note.** Two sections in this file are numbered `11c` — *File uploads and
> audit logging* and *The tenant-scope bypass audit — first pass*. Neither is renumbered
> here: root `CLAUDE.md` cites "§11c" for the bypass audit, and renaming either would break
> that reference to fix a cosmetic collision. This section is `11d` regardless, since no
> other section claims it.

§11c above — *the bypass audit*, not the file-uploads one — covered the 33 sites its method
flagged. This pass re-ran the question over
**all** of them with a stricter test, and reaches a different — and less comfortable —
shape of answer.

**It found no site that can reach another tenant's rows.** It found five that are safe for
reasons nothing enforces.

#### Method, and why the numbers do not compare to §11c

| | §11c (2026-09-16) | §11d (2026-09-22) |
|---|---|---|
| Enumeration | `preg_match_all` over raw text | `token_get_all()` — comments and string literals excluded |
| Population | **156** = 151 real calls + 5 docblock mentions | **156** real calls |
| Predicate test | a `tenant_id` / `tenant()` / `CurrentTenant` reference **within ±8 lines** | a `where`-family predicate on `tenant_id` **in the same statement** |

**The two 156s are different quantities that coincide.** §11c's population included five
comments and excluded five call sites that had not yet been written; today's is 156 real
calls. Root `CLAUDE.md` §4 records why, and it is the same trap that made the count read
161 for four days.

The stricter test is the point. §11c asked *is a predicate nearby*; this asks *does the
query state one*. A predicate eight lines away can be on a different query.

#### Counts

| Class | n |
|---|---|
| **SAFE** — `where`-family predicate on `tenant_id` in the same statement | **106** |
| **SAFE-BY-DESIGN** — target is a global model (`Tenant`), so no tenant scope exists to drop | **11** |
| **SAFE-BY-DESIGN** — no same-statement predicate, justified individually below | **39** |
| **UNSAFE** | **0** |

The 39, read one at a time:

| Sub-class | n | Why it holds |
|---|---|---|
| Platform admin | 9 | every public method of all three `Admin*Controller`s carries `Gate::authorize('admin.manage')`; the route group adds `EnsurePlatformContext` + `RequirePlatformMfa` |
| Derived from a tenant-owned key | 15 | `payroll_run_id`, `user_id`, `webhook_id`, `subscription_id`, a `$run` already scoped |
| Platform billing / super-admin sweeps | 6 | invoices and subscriptions across all tenants, which is the feature |
| Pre-authentication | 4 | the presented secret is the authority — API key hash, device token, PAT |
| Scheduler sweeps that set tenant per row | 2 | `RunScheduledReportsJob:92` and `RunDashboardDigestsJob:79` both call `$currentTenant->set()` per row |
| Console super-admin creation | 1 | `tenant_id => null` deliberately |
| Builder helper / relation-constrained | 2 | predicate supplied by the caller or by the relation's foreign key |

#### The five that are safe for reasons nothing enforces

**1. `DeviceController.php:537` — keyed on a non-secret with no unique index.** *[Superseded 2026-09-23: live devices now carry a unique `(serial_number, adapter_type)` index, built on a generated column so soft deletes do not burn serials, and the lookup rejects ambiguity outright — §11h.]*
`->where('serial_number', $serialNumber)->first()`. The 2026-08-23 migration's own comment
calls serials *"printed on the hardware, enumerable"*, and **`serial_number` carries no
unique index** (`0001_01_01_000004:53` is a plain nullable string), so two tenants can hold
the same serial and `first()` picks arbitrarily. Saved by defence in depth, verified rather
than assumed: a device with a token is rejected (`token_required`), and a token-less one
must pass `webhookIpAllowed()`, which is **fail-closed** — null or empty allowlist returns
`false` (`Device.php:68-75`). Residual: two token-less devices sharing a serial *and*
overlapping allowlists. Note `DemoTenantSeeder.php:315` creates devices with no
`webhook_token`, so token-less devices are reachable by a path that is not the controller.

**2. `DeviceController.php:523` — `webhook_token` also has no unique index.** *[Superseded 2026-09-23: it has one now — §11e.]* 256-bit
`bin2hex(random_bytes(32))`, so collision is negligible *in practice* and unprevented *in
schema*.

**3. `OrganizationProvisioner.php:335` — a builder factory that cannot enforce its own
contract.** `(new $modelClass)->newQuery()->withoutGlobalScope('tenant')` — dynamic model,
no predicate. Safe **only** because both current callers add one immediately (`:301` and
`:438`, each `->where('tenant_id', …)`). A third caller that forgot would be a silent
cross-tenant read and nothing would catch it. *[Superseded 2026-09-23: the tenant is now a required argument and the predicate is inside the helper — §11e.]*

**4. Fifteen sites derived from a key, stating nothing.** *[Count corrected 2026-09-23:
there are **nine** bare `::find()` sites, not fifteen — see §11g. The fifteen is the size
of the "derived from a tenant-owned key" row in the class table above, which also counts
sites stating `webhook_id`, `payroll_run_id` and the like; this prose conflated that class
with the bare-`find` subset. Resolved in §11g.]* Bare `::find($id)` on
tenant-scoped models. Each is safe because the id arrives from a trusted dispatch, but the
query asserts nothing — the asymmetry root `CLAUDE.md` already flags inside
`DispatchWebhookJob`, where `handle()` states `tenant_id` and `failed()` derives from
`webhook_id`.

**5. `StoreEmployeeRequest.php:52` — validation that does not scope.** *[Superseded
2026-09-23: a foreign `public_id` is now rejected with 422 — §11f. The six sibling relation
fields named there still behave as described below.]* Not a bypass, but
adjacent and worth recording. `'supervisor_id' => ['nullable', 'exists:employees,public_id']`
uses Laravel's presence verifier, which **does not apply Eloquent global scopes**, so
another tenant's `public_id` passes validation. It does not become a leak:
`EmployeeController::resolveRelationIds():287` resolves via a *scoped*
`where('public_id', …)->first()`, so a foreign id resolves to `null`. The effect is a
**silently nulled supervisor** — a data-integrity wart, and the reason
`ScanMissingPunchesJob:84`'s unscoped supervisor lookup is in fact safe.

#### What this pass does not claim

It judged each statement, and each caller where a statement alone was not enough. It did
**not** trace every dispatch path for all fifteen derived-key sites; the highest-risk ones
were checked individually (`BackupTenantJob`'s requester is always `$request->user()->id`
from the admin controller; `$runs` in `ExecutiveDashboardService` derives from a
`where('tenant_id', $tenantId)` query at `:564`/`:597`) and the pattern taken as sound for
the rest. Tracing those fifteen is a separate pass.

**Nothing was changed.** This is a measurement, recorded so the next reader starts from it
rather than repeating it.

#### The shape, stated plainly

It is not a list of bugs. It is that **106 of 156 sites prove their own safety and 50 do
not** — they are safe because of something elsewhere: a gate, a dispatcher, a caller, a
backfill, an unenforced uniqueness assumption. `TenantScopeBypassInventoryTest` counts all
156 identically and audits none of them.

*[Re-measured 2026-09-23 after §11e–§11h: **108 of 157 and 49** (§11i), then **113 and
44** once the five conditional predicates became unconditional (§11j). The figures above
are left as the 2026-09-22 reading they were.]*

---

### 11e. Enforcing three of the five — **2026-09-23**

§11d found nothing that can reach another tenant's rows, and five sites that are safe for
reasons *nothing enforces*. This pass closes three of them, choosing the mechanism that
fails loudest at the point where the mistake would be made: a unique index over a test, a
test over a comment. Two are left open deliberately, because enforcing them would change
behaviour rather than guard it, and that is the owner's call rather than an audit's.

#### What changed

**§11d site 3 — `OrganizationProvisioner.php:335`. Code change; the strongest of the
three.** The helper returned a ready-to-use unscoped builder and stated nothing; its safety
lived entirely in the two callers that added `->where('tenant_id', …)` afterwards. The
tenant is now a **required argument** and the predicate is applied inside the helper:

```php
private function query(string $modelClass, int $tenantId): Builder
{
    $query = (new $modelClass)->newQuery()
        ->withoutGlobalScope('tenant')
        ->where('tenant_id', $tenantId);
```

Both callers passed the same value one line later, so the generated SQL is unchanged — this
is a pure guard, not a behaviour change. It is better than a test because it removes the
failure mode instead of detecting it: a third caller *cannot* forget an argument the
signature requires, and PHPStan says so before the code runs. This was judged the most
likely of the five to break, because breaking it needed no unusual reasoning — just
ordinary reuse of a `private` method that looks like it hands you a query.

**§11d site 2 — `devices.webhook_token`. Unique index.**
`2026_09_23_000001_add_unique_index_to_device_webhook_token`. The token lookup at
`DeviceController.php:523` drops the tenant scope and lets the token both select and
authenticate; that was safe only because `Device::generateWebhookToken()` happens to be
`bin2hex(random_bytes(32))`. The invariant now lives in the schema, so a weaker generator —
a short `Str::random()`, a derived value, an operator-supplied token — fails at the insert
instead of silently resolving to another tenant's device.

NULL is still allowed, and a unique index permits unlimited NULLs on both SQLite and
MariaDB. Token-less devices are a supported mode (they authenticate by allowlist instead)
and `DemoTenantSeeder.php:315` creates them, so **the column was deliberately not made NOT
NULL** — that would have been a behaviour change, and a breaking one.

The migration refuses to run if duplicate non-null tokens already exist, rather than failing
with an opaque driver error. By construction there should be none: tokens are generated per
row on create (`:101`), on rotate (`:251`), and by the 2026-08-23 backfill. Its message
reports how many values are shared and **never a token value** — those are credentials.

**§11d site 1 — `devices.serial_number`. Tests, not an index.** A unique index is the
louder mechanism and the wrong one here: the correct index would be tenant-scoped
(`tenant_id, serial_number, adapter_type`), and a unique index on a tuple the bypassed query
does not fully state still permits the wrong row. What actually holds this site safe is
`Device::webhookIpAllowed()` being fail-closed, so that is what is now pinned, at the level
where someone would loosen it.

`tests/Feature/Security/DeviceWebhookTenantScopeTest.php` adds: seven table-driven
assertions that `webhookIpAllowed()` returns false for a null IP, an empty IP, a null
allowlist, an empty allowlist, a non-matching IP, a whitespace-only allowlist and the case
where both are absent; the positive cases (exact address, CIDR block); the token's
64-hex-character shape and non-repetition; the unique index refusing a shared token while
still admitting any number of token-less devices; and the cross-tenant case §11d called the
residual — **two tenants holding the same serial**.

That last test asserts the invariant that actually holds rather than the one that looks
natural. Which row `->first()` returns is undefined, so it does not assert "the right device
is chosen". It asserts that **the wrong tenant gets nothing either way**: selecting tenant
A's device means the source IP is not on A's allowlist and the call is rejected; selecting
tenant B's means the record is filed against B, because `webhookZkteco()` files against
`$device->tenant_id` and resolves the employee with `->where('tenant_id', $device->tenant_id)`
— verified, not assumed.

Pre-existing coverage was better than the plan for this pass assumed:
`DeviceManagementTest.php:357-450` already exercised `token_required`, the allowlisted-IP
accept, the non-allowlisted reject and the no-allowlist fail-closed case at the HTTP level.
What was missing was the unit-level edge cases and the two-tenant collision, which is what
was added.

#### What was left open, and why

Both remaining sites need a decision, not an audit.

**§11d site 4 — the fifteen bare `::find($id)` sites.** No database constraint can express
"this id came from a trusted source", and a blanket lint against `::find()` under a bypass
would fire on all fifteen legitimate sites and be suppressed — a comment in a linter's
voice. Two real options: tests at the boundary (assert request-facing routes bind by
`public_id` rather than integer id, and that each job fails closed on a foreign id), or
stating `tenant_id` explicitly at all fifteen, which is mechanical and would move them into
the self-proving column. The second carries a caveat worth stating: if any of the fifteen is
today relying on a cross-tenant read that is *intentional*, adding the predicate turns it
into a null and a downstream error. No evidence of that was found, but fifteen sites is
enough that it should not be asserted without running the suite.

**§11d site 5 — `supervisor_id` validation.** *[Authorised and done on 2026-09-23 — §11f.
The reasoning below is what it was weighed against, and the message concern it raises is
the one the implementation had to satisfy.]* Scoping the `exists:employees,public_id` rule
is **a behaviour change, not a guard**. Today a foreign `public_id` is accepted and silently
nulled by `resolveRelationIds()`; a scoped rule returns 422 instead. That is visible to every
API client, needs the OpenAPI contract updated, and carries a subtlety that makes it worth
doing carefully rather than quickly: the current silent null tells an attacker nothing, while
a 422 that distinguishes "no such employee" from "employee exists in another tenant" would
confirm a `public_id` across tenants. A scoped rule must return the *same* message as a
genuinely non-existent id, or it leaks what the scope was added to hide.

A third flag, recorded so it is not re-derived: a unique index on `devices.serial_number`
would **reject existing duplicate rows at migration time** and change what device
registration accepts. It is a behaviour change, it needs a production data audit first, and
that audit is not runnable from here. *[Authorised and done on 2026-09-23 — §11h. The
audit still is not runnable here, so the migration refuses to run against duplicates and
names them instead. §11h also records two things this paragraph did not anticipate: a plain
index would have burned serials permanently because devices soft-delete with no restore
route, and a tenant-scoped index would not have closed the site at all.]*

#### What this pass does not claim

It does not audit the 50 sites §11d found that do not prove their own safety (44 since §11j); it enforces
three specific invariants that §11d found unenforced. The count in
`tests/Feature/Security/tenant-scope-bypasses.php` is unchanged at **156 across 54 files** —
verified with the tokeniser after the change — because no bypass was added or removed.

The new tests were **not run locally**. `composer install` fails in this environment with
`Could not authenticate against github.com` (`AuthHelper.php:132`), so there is no
`vendor/autoload.php` and Pest cannot execute here. They are verified by CI.

---

### 11f. Site 5 closed — scoped employee relation validation — **2026-09-23**

§11e closed three of §11d's five and left two, both because enforcing them changes
behaviour rather than guarding it. The owner authorised one of those changes: **an
employee relation `public_id` belonging to another tenant now returns 422 instead of 201
with the field silently nulled.**

It landed in two passes on the same day, and this section records both. The first scoped
`supervisor_id` alone, which is the field §11d named. It also surfaced that the same
defect sat on **six sibling fields** in the same two request classes; those were listed
for the owner rather than changed, and then authorised. *"Six siblings"* below is that
second pass. Where this section says "the rule" or "the field" in the singular, it is
describing the first pass; all seven behave identically now.

#### What was wrong

`'supervisor_id' => ['nullable', 'exists:employees,public_id']` goes through Laravel's
DatabasePresenceVerifier, which queries the table directly and **does not apply Eloquent
global scopes**. Another tenant's `public_id` therefore validated. It never became a
cross-tenant *read* — `EmployeeController::resolveRelationIds():287` resolves with a scoped
`where('public_id', …)->first()`, so the value became `null` — but the two layers disagreed
about what a valid supervisor is and only one of them said so. The caller got 201 Created
with the supervisor quietly missing.

#### The fix, and why it is not a validation rule

The obvious form is `Rule::exists('employees', 'public_id')->where('tenant_id', $tenantId)`.
It was written, then withdrawn, because `Http/Requests/Billing/ChangePlanRequest.php:16-21`
already records why this repository does not use that builder:

> *"Scramble derives the public schema from these rules and emits a plain `string` for the
> string form, which is what `generated.ts` already carries; the builder object is untested
> here and the contract gate fails on drift."*

That warning is load-bearing in this environment for a second reason: `composer install`
fails here, so `artisan scramble:export` cannot run, so a drift failure could be neither
predicted nor repaired locally. Scramble also lifts comments above an array key into the
published schema as a `description` — visible at `generated.ts:6904` where
`create_login`'s PHP comment appears verbatim — so the explanatory comment first written
above `supervisor_id` would have been published into the client-facing contract, and it
described the vulnerability.

So the check moved to a `withValidator()` hook, in
`Http/Requests/Employee/Concerns/ValidatesSupervisorTenancy.php` (renamed to
`ValidatesRelationTenancy.php` in the second pass). Scramble reads `rules()`;
a hook is invisible to it. **`rules()` is byte-identical to the previous commit in both
requests** — verified by diff — so the contract provably cannot move.

The hook does not restate the predicate. It runs `Employee::where('public_id', $id)
->exists()` — *the resolver's own lookup*, with both the tenant global scope and the
soft-delete scope applying for the same reasons they apply there. A hand-written
`where('tenant_id', …)` could drift from the resolver; this cannot, because it is the
resolver's query. Soft-deleted supervisors are consequently rejected too, which is not an
extra rule but the same consequence: they also resolved to `null`.

It is shared between `StoreEmployeeRequest` and `UpdateEmployeeRequest` as a trait rather
than copied, for the reason root `CLAUDE.md` gives about `DispatchWebhookJob`: two halves of
one control that state things differently is the shape a later defect takes.

#### The message is part of the fix

The rejection uses Laravel's own `validation.exists` line — *"The selected supervisor id is
invalid."* — identical to what a genuinely nonexistent `public_id` produces.
`validation.attributes` is empty (`lang/en/validation.php:198`), so Laravel derives
"supervisor id" itself and the hook supplies the same string.

This is required, not cosmetic. A message distinguishing "no such employee" from "that
employee is in another tenant" would confirm a `public_id`'s existence across tenants —
precisely what the scope exists to hide, and a worse leak than the silent null it replaces.
`EmployeeRelationScopedValidationTest` (then `SupervisorScopedValidationTest`) asserts the
two messages are equal rather than merely both 422, so the assumption about Laravel's
attribute derivation cannot rot silently.

#### A consequence worth recording

`ScanMissingPunchesJob:84` looks the supervisor up by integer id with the tenant scope
removed. §11d classed it safe *because* `supervisor_id` can only hold a same-tenant id —
which was true only by accident, as a side effect of the silent null. It is now enforced
upstream. The job did not change; what changed is that its premise is now guaranteed
instead of coincidental.

#### Six siblings — **authorised and closed the same day**

`resolveRelationIds()` maps **seven** fields and treats them identically; all seven carried
the same unscoped `exists:…,public_id` rule in both requests. `supervisor_id` was
authorised first and the other six were listed here as the owner's call. That call came
the same day, so **all seven are now scoped**: `department_id`, `branch_id`, `position_id`,
`grade_id`, `team_id`, `cost_center_id` and `supervisor_id`.

Three things changed to get there, and the first is the one that matters most:

**The field/model map now has one home.** It lived twice — as a literal in
`resolveRelationIds()` and, once the hook existed, implicitly in the hook. Two copies of
the set a control iterates is the `DispatchWebhookJob` shape root `CLAUDE.md` warns about,
and here it would have been worse than untidy: an *eighth* field added to the resolver and
not to the validator is a silently nulled relation again — precisely the defect this
section exists to close. It is now `App\Support\EmployeeRelations::MAP`, read by both.
`EmployeeRelationScopedValidationTest` asserts every mapped field has a rule in both
requests, so the map and the rules cannot cover different sets either.

**The trait generalised** from `ValidatesSupervisorTenancy` to `ValidatesRelationTenancy`,
iterating that map rather than naming a field. The per-field logic is unchanged: skip
absent or already-rejected values, ask the resolver's own scoped query, and on a miss add
Laravel's generic `validation.exists` line with the attribute name Laravel itself would
derive.

**One asymmetry is written down rather than worked around.** `CostCenter` is the only
model in the map without `SoftDeletes`. The validator asks the model instead of assuming a
`deleted_at` column, and the soft-delete test runs over a six-field dataset that omits
`cost_center_id`, because there is no deleted state for it to be rejected in.

The constraints from the `supervisor_id` pass all still hold and were re-verified: the
check is a `withValidator()` hook and not a rule; **`rules()` is byte-identical to the
previous commit in both requests**, confirmed by diffing the extracted method; no comment
sits above an array key in `rules()`, so nothing new reaches the published schema; and
`@mixin FormRequest` uses the imported name, since `fully_qualified_strict_types` rejects
the leading-slash form — that one cost a red Pint gate on PR #60.

#### What this pass does not claim

The new tests were **not run locally**; `composer install` fails here with `Could not
authenticate against github.com` (`AuthHelper.php:132`). CI is the verification. The bypass
inventory is untouched at 156 across 54 files — this change adds no `withoutGlobalScope`
call. No application behaviour outside these seven validation fields was altered.

It does not claim the *other* unscoped `exists:` rules in this repository are safe. It
fixed the seven that `resolveRelationIds()` owns, because those are the ones §11d traced
end to end. Any request elsewhere validating a `public_id` with the string form has the
same presence-verifier gap, and whether it matters depends on what resolves the value
afterwards — which is a separate pass, not an inference from this one.

---
### 11g. Site 4 closed — and the count was wrong — **2026-09-23**

§11d's fourth fragile site read *"Fifteen sites derived from a key, stating nothing. Bare
`::find($id)` on tenant-scoped models."* The owner authorised stating `tenant_id` at all
fifteen. **There are nine, and only five of them can or should carry a predicate.** The
measurement came first and it changed the work, so it is recorded before the fix.

#### Where fifteen came from

The class table above §11d's fragile-site list has a row *"Derived from a tenant-owned key
| 15 | `payroll_run_id`, `user_id`, `webhook_id`, `subscription_id`, a `$run` already
scoped"*. That row counts every site whose safety rests on a key — including ones that
**do** state a predicate, just not on `tenant_id`. The prose beneath it then described that
count as bare `::find()`, which is a strict subset. Two different quantities, one number —
the same trap this file records for the 156/161 bypass count, repeated at smaller scale.

Enumerated with the tokeniser (walking each `withoutGlobalScope(s)` statement to its
terminating `;` and collecting the method chain, so multi-line chains are not missed):
**156 bypass statements, 11 whose chain calls `find()`/`findOrFail()`, of which 2 already
state a predicate** — `DispatchWebhookJob:50` states `tenant_id` and `id`, `:168` states
`webhook_id`. Nine bare.

#### The nine, and what each can actually take

| # | Site | Model | Outcome |
|---|---|---|---|
| 1 | `Jobs/BackupTenantJob.php:57` | `Tenant` | **Impossible** — global model |
| 2 | `Jobs/BackupTenantJob.php:113` | `User` | **Must not** — platform-admin requester |
| 3 | `Jobs/DispatchWebhookJob.php:41` | `Webhook` | **Scoped** |
| 4 | `Jobs/NotifyAnnouncementAudienceJob.php:50` | `Announcement` | **Scoped** |
| 5 | `Jobs/ProcessPayrollJob.php:74` | `PayrollRun` | **Scoped** |
| 6 | `Jobs/ProcessPayrollJob.php:120` | `PayrollRun` | **Scoped** |
| 7 | `Services/Migration/WorkforceMigrationService.php:238` | `Employee` | **Scoped** |
| 8 | `Admin/AdminTenantController.php:344` | `Tenant` | **Impossible** — global model |
| 9 | `Admin/AdminTenantController.php:365` | `User` | **Must not** — super-admin recovery |

**Two are impossible, not overlooked.** `Tenant` is on the Global Model List in
`docs/CLAUDE.md`: no `BelongsToTenant`, no `tenant_id` column. There is no tenant scope to
re-apply, and `withoutGlobalScopes()` there removes nothing tenant-related at all.

**Two would be regressions.** `BackupTenantJob:113` notifies whoever asked for the export,
and the job is dispatched only from `AdminTenantController:382` — a platform-admin surface.
The requester is a super admin whose `tenant_id` is null, never the backed-up tenant's, so
`where('tenant_id', $tenant->id)` would match nobody and **silently drop every backup
notification**. `AdminTenantController:365` recovers the super admin behind an impersonation
token: during impersonation the resolved tenant is the *impersonated* one, so any predicate
finds nobody and exiting an impersonation stops working. Its authority is the
`isSuperAdmin()` re-check on the next line, not the lookup. This is exactly the
"intentional cross-tenant read" §11e warned would be turned into a null. Both now carry a
comment saying so, so the next reader does not "fix" them.

#### The five that were scoped, and where the predicate comes from

Site 7 was the straightforward one: `mergeTarget()` already receives `$tenantId` as an
argument, and its *other* branch — `$this->resolver->resolve($tenantId, $signals)` — scopes
by it. Only the `if` branch trusted `$row->resolved_employee_id` outright, a staging column
written earlier and possibly stale. Two halves of one method disagreeing; now they do not.

Sites 3–6 had **no tenant id in scope at all**. Each is the *root* lookup of a queued job
whose payload carried only a row id, so the tenant was derived *from the row being
fetched* — and a predicate read off the row you just fetched proves nothing about which row
you were entitled to fetch. `ProcessPayrollJob`'s own comment claimed it was "scoped
explicitly by the run's own `tenant_id`", which is that circularity stated as a virtue.

The fix is to carry the tenant in the payload. Each job has exactly one dispatch site and
each dispatcher already holds the tenant, so the change is three call sites:

| Job | Dispatcher | Source |
|---|---|---|
| `DispatchWebhookJob` | `WebhookDispatcher::queue()` | `$webhook->tenant_id` |
| `NotifyAnnouncementAudienceJob` | `AnnouncementController` | `$announcement->tenant_id` |
| `ProcessPayrollJob` | `PayrollController` | `$run->tenant_id` |

What this buys is not theoretical. A wrong id in a payload — a bug, a replay, a
hand-requeued job — previously meant acting on another tenant's row: `ProcessPayrollJob`
computes and writes payroll, `failed()` marks a run failed, `DispatchWebhookJob` signs a
payload and POSTs it to an endpoint, and `NotifyAnnouncementAudienceJob` calls
`CurrentTenant::set()` from the row it found and then notifies **that** audience. It now
finds nothing instead.

`DispatchWebhookJob::failed()` also gained `tenant_id`, which is slightly beyond the
bare-`find` brief and worth stating: it was not a bare `find` — it stated `webhook_id`. But
root `CLAUDE.md` records its asymmetry with `handle()` as *"the shape a later defect
takes"*, and the id is now in hand, so leaving the two halves disagreeing to stay inside a
line-item boundary would have been the wrong call.

#### A compatibility decision worth your attention

`$tenantId` is declared **nullable with a default**, trailing the existing arguments, and
the predicate is applied only when it is present. That is not hedging: PHP restores an
object from `unserialize()` without running the constructor, so a property absent from an
older serialized payload takes its declared default rather than staying uninitialized. A
required `readonly int` would have made **every job already on the queue at deploy time
fail permanently** — `DispatchWebhookJob` has `tries = 5` with backoff to 24h, so those
failures would trickle in for a day.

The cost is that the null branch is not self-proving. It is transitional: every dispatch
since this change supplies the id, so once a queue drain has passed the deployment, the
argument can be made a required `int` and the null branches deleted. Both halves are
tested — the rejection and the compatibility contract.

*[Superseded 2026-09-23 — §11j. The drain has passed: `$tenantId` is a required
`readonly int` on all three jobs, the null branches are deleted, and the compatibility
test with them. The deploy precondition this paragraph describes is now written out in
`docs/DEPLOYMENT.md` → *Draining the queue before an upgrade*, including the 24-hour
`DispatchWebhookJob` backoff that a drain alone does not clear.]*

#### The Scramble comment trap is wider than §11f recorded it

§11f drew the rule as *"never put an explanatory comment above a key in `rules()`"*. That
reading is too narrow, and it cost a red API-contract gate on the first push of this work.
The `AdminTenantController` site is reached through `response()->json([...])`, and a
comment written above the `'tenant' =>` key was lifted verbatim into the published schema:

```
18521a18522,18524
>   *  `Tenant` is a global model with no `tenant_id` column, so there is
>   *  no tenant scope to re-apply — and the id read is the
>   *  impersonator's own. Reviewed under BASELINE.md §11g.
```

So the rule is **any array literal the spec is derived from** — FormRequest rules and
controller response arrays alike. A comment attached to a *statement* is fine, which is why
the note on `resolveImpersonator()`'s lookup survived the same run untouched. The one
adjacent to a response key was deleted rather than reworded: anything left there publishes,
and `scramble:export` cannot run in this environment to check a reword. That site's reason
lives in the table above instead.

Worth recording as a pattern rather than an incident: the rule was written down two passes
earlier, by the same hand that then broke it, because it was written about the place it was
first met instead of about the mechanism.

#### What this pass does not claim

The bypass inventory is unchanged at **156 across 54 files**, verified with the tokeniser:
no `withoutGlobalScope` call was added or removed, only predicates attached to existing
ones. Four of the nine sites still state nothing, deliberately, and are now commented
rather than left to be rediscovered.

It does not re-audit the other six members of §11d's fifteen-strong "derived from a key"
class — the ones stating `webhook_id`, `payroll_run_id` and similar. Those were already
counted as deriving from a tenant-owned key and are unchanged.

The new tests were **not run locally**; `composer install` fails here with `Could not
authenticate against github.com` (`AuthHelper.php:132`). CI is the verification.

---
### 11h. Site 1 closed — the last of §11d's five — **2026-09-23**

`DeviceController::resolveWebhookDevice()` selects a device by
`(serial_number, adapter_type)` with the tenant scope dropped, then `->first()`.
Nothing made that pair unique, so with two tenants holding the same serial the
row returned was undefined. §11e deliberately did **not** add an index, pinning
the fail-closed IP allowlist instead and recording the index as an owner
decision because it rejects existing rows at migration time. The owner
authorised it.

Two things turned up that the §11e plan had not, and both changed the shape of
the work.

#### The index cannot simply be added

`Device` soft-deletes, `DeviceController::destroy()` soft-deletes, and **there is
no restore route**. A plain unique index counts soft-deleted rows, so deleting a
device would burn its serial permanently with no way to re-register it through
the API — a worse and more likely regression than the ambiguity being fixed.
MariaDB has no partial indexes, so `WHERE deleted_at IS NULL` is not available.

The index is therefore built on a generated column that goes NULL once the row
is deleted:

```php
$table->string('serial_number_active')
    ->nullable()
    ->virtualAs('CASE WHEN deleted_at IS NULL THEN serial_number ELSE NULL END');
$table->unique(['serial_number_active', 'adapter_type'], 'devices_live_serial_unique');
```

NULLs repeat freely in a unique index on both drivers, so any number of deleted
rows may share a serial while live ones may not. The column is VIRTUAL rather
than STORED because SQLite permits adding a virtual generated column with
`ALTER TABLE` and refuses a stored one, and the suite runs on SQLite while
production runs on MariaDB. **There was no precedent for a generated column in
this repository**, and none of it could be executed locally, so CI was the first
thing to run it.

#### A tenant-scoped index would not have worked

The obvious shape — `(tenant_id, serial_number, adapter_type)` — is the one that
leaves the defect in place. The bypassed lookup does **not** state `tenant_id`;
two *different* tenants holding the same serial is precisely the case it cannot
tell apart. Only global uniqueness makes that `->first()` sound.

That has a cost, stated rather than buried: registering a serial another tenant
already holds now fails, which tells the caller that serial exists somewhere on
the platform. It is a cross-tenant existence oracle of the kind §11f went to
some trouble to avoid in `supervisor_id` validation. The difference is that here
it is inherent — any global uniqueness constraint is observable — and the
exposure is narrow: an authenticated operator, registering hardware they
physically hold, learns existence and nothing else. The message names no tenant,
no device and no owner.

#### Two layers, because the index alone would be weaker than it looks

The query was also changed to treat ambiguity as a rejection:

```php
$candidates = Device::withoutGlobalScope('tenant')->…->limit(2)->get();

if ($candidates->count() > 1) { … return null; }
```

The index is a schema fact; this is the code that would act on a violation of
it. An older database, an unrun migration, or a future change that drops the
index all leave this path reachable, and the guard costs one query shape. An
ambiguous serial now authenticates nobody, which is the same fail-closed default
`BelongsToTenant` and `webhookIpAllowed()` already take.

Testing the guard requires dropping the index inside the test, because the index
covers `UPDATE` as well as `INSERT` and there is no way to forge a duplicate
while it exists. That is written into the test rather than worked around.

#### The inventory moved, and the honest answer to its own question

`ValidatesSerialUniqueness` — the hook that turns a constraint violation into a
422 rather than a 500 — queries across tenants, so the pin went **156/54 →
157/55**. The inventory test asks one question of every new bypass: does the
query state `tenant_id` itself, or derive from a key already tenant-owned?

**Neither.** It is cross-tenant on purpose, for the same reason the index is
global. Recorded as such in `tenant-scope-bypasses.php` rather than dressed up:
it reads existence only, returns no row, reads no field, and names nothing.

The hook is a `withValidator()` hook and not a `Rule::unique(...)`, and `rules()`
is byte-identical in both device requests — verified by diffing the extracted
method. §11g cost a red contract gate learning that Scramble publishes from
array literals generally, and that lesson is now applied rather than relearned.

#### What this pass does not claim

It does not claim production has no duplicate serials. That audit is not
runnable from here, so the migration refuses to run against them and prints the
offending device `public_id`s and owning `tenant_id`s to act on. **If it fails on
deploy, that is the audit reporting, not the migration breaking.**

The generated column is the first in this repository and was verified only by
CI, on both SQLite and MariaDB. The `down()` path drops the index and the column
but was never executed against MariaDB.

With this, all five of §11d's "safe for reasons nothing enforces" sites are
closed: §11e took three, §11f the `supervisor_id` rule and its six siblings,
§11g the bare `::find()` sites, and §11h the device serial.

---

### 11i. Re-measuring §11d's shape after the five closures — **2026-09-23**

§11d's headline — **106 of 156 sites prove their own safety, 50 do not** — was measured on
2026-09-22, the day before §11e–§11h changed seven call sites and added an eighth. It is a
dated measurement and it stays where it is; what it is no longer is a description of the
code. §12b of this file is a catalogue of figures that drifted into fiction by being
correct once, so this section re-runs it rather than leaving the reader to.

Same test as §11d: a `where`-family predicate on `tenant_id` **in the same statement** as
the bypass, over the **157** sites `tenant-scope-bypasses.php` now pins.

| Class | §11d (156) | now (157) |
|---|---|---|
| **SAFE** — same-statement predicate on `tenant_id` | 106 | **108** |
| **SAFE-BY-DESIGN** — target is a global model (`Tenant`), no scope to drop | 11 | 11 |
| **SAFE-BY-DESIGN** — no same-statement predicate, justified individually | 39 | **38** |
| **UNSAFE** | 0 | **0** |

**The headline is now 108 of 157 prove their own safety; 49 do not.**

#### What moved

Two sites moved into SAFE, both by a code change rather than by a re-reading:

- **`OrganizationProvisioner::query()`** (§11e). It was §11d's *builder helper* entry — an
  unscoped builder whose safety lived in the two callers that added the predicate a line
  later. The predicate is now on the same chain as the `withoutGlobalScope`, so the query
  states it.
- **`WorkforceMigrationService::mergeTarget()`** (§11g).
  `->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->find(...)`, one statement.
  It was inside §11d's *derived from a tenant-owned key* row, which drops 15 → 14 before
  the paragraph below takes five more out of it.

One site entered the justified-individually class, and it is the reason 39 falls by one
rather than two:

- **`ValidatesSerialUniqueness`** (§11h) — cross-tenant on purpose, guarded by a **global**
  unique index instead of a predicate. §11h states that trade in full; the classification
  here just declines to launder it.

#### What did not move, and why that is the finding

**Five sites gained a `tenant_id` predicate in §11g and still do not count.** They are
`DispatchWebhookJob::handle()` and `::failed()`, `NotifyAnnouncementAudienceJob::handle()`,
and `ProcessPayrollJob::handle()` and `::failed()`. Each has this shape:

```php
$query = PayrollRun::withoutGlobalScopes();

if ($this->tenantId !== null) {
    $query->where('tenant_id', $this->tenantId);
}

$run = $query->find($this->payrollRunId);
```

The predicate is in a **separate, conditional statement**, so §11d's test does not see it —
and the test is right not to. A predicate guarded by `!== null` on a nullable payload field
is not stated by the query; it is stated by the dispatcher, which is the dependency §11g
opened in order to remove. The null branch exists for a good reason and §11g records it:
PHP restores a queued job through `unserialize()` without running the constructor, so a
required property would make every job already on the queue at deploy time fail
permanently. It is transitional by design.

That makes the closing figure **113 of 157**, not 108, and it is one commit behind a queue
drain: make `$tenantId` required on the five, delete the five `if` blocks, and the
predicate is on the chain. *[Done the same day — §11j. The figure is now **113 of 157**.]* No other class in the 49 is a deletion away — by §11d's own
table the rest are platform-admin gates, pre-authentication secrets, global models and
scheduler sweeps, where a `tenant_id` predicate is either impossible or contrary to the
feature.

Until then the five are better than they were — the tenant is in the payload, one dispatch
site each, and `QueuedJobTenantPredicateTest` fails if any of them stops scoping — without
being self-proving, which is the exact distinction §11d exists to draw. **Having a
predicate is necessary, not sufficient; having one conditionally is neither.**

#### What this pass does not claim

It does not re-audit the 49. §11d read them individually on 2026-09-22 and that reading is
unchanged for every site this session did not touch. This is arithmetic over a known delta
— four merged pull requests, read against the same test — not a third pass. **No code
changed here.**

---

### 11j. The drain deletions — the last five sites close — **2026-09-23**

§11i left one item: five queued-job sites that carried a `tenant_id` predicate behind
`if ($this->tenantId !== null)` and therefore did not satisfy §11d's test. This is that
deletion.

`$tenantId` is now a **required `readonly int`** on `ProcessPayrollJob`,
`DispatchWebhookJob` and `NotifyAnnouncementAudienceJob`, the five `if` blocks are gone,
and each lookup states the predicate on its own chain:

```php
$run = PayrollRun::withoutGlobalScopes()
    ->where('tenant_id', $this->tenantId)
    ->find($this->payrollRunId);
```

#### The measure

| Class | §11d (156) | §11i (157) | now (157) |
|---|---|---|---|
| **SAFE** — same-statement predicate on `tenant_id` | 106 | 108 | **113** |
| **SAFE-BY-DESIGN** — global model (`Tenant`) | 11 | 11 | 11 |
| **SAFE-BY-DESIGN** — no same-statement predicate, justified individually | 39 | 38 | **33** |
| **UNSAFE** | 0 | 0 | **0** |

**113 of 157 prove their own safety; 44 do not.** That is the figure §11i named as the
ceiling reachable by deletion, and it is reached. The remaining 44 are the classes §11d
read individually — platform-admin gates, pre-authentication secrets, global models,
billing sweeps, scheduler sweeps that set the tenant per row — where a `tenant_id`
predicate is impossible or contrary to the feature. None of them is a deletion away, and
closing any further would mean changing behaviour, not tightening a signature.

#### What the deletion costs, and why it is not free

§11g made the argument nullable for a real reason, restated because it is the whole risk
here: **PHP restores a queued job from `unserialize()` without running the constructor.** A
payload serialized before this deploy carries no `tenantId`. The property is typed with no
default, so it stays *uninitialized* rather than taking null, and the first read throws
`Error: Typed property … must not be accessed before initialization`.

So this change **requires a queue drain before it deploys**, and that is now written where
a deployer will meet it — `docs/DEPLOYMENT.md` → *Draining the queue before an upgrade* —
rather than asserted in a commit message. The three job docblocks point at it by name.

**A drain does not close the window completely, and the runbook says so.**
`--stop-when-empty` stops when the queue has nothing *ready*; a delayed retry is not ready.
`DispatchWebhookJob` backs off `[60, 300, 1800, 7200, 86400]`, so a delivery already four
attempts in has its last attempt scheduled **24 hours out** and survives any drain run
immediately before the deploy. The runbook gives three ways to handle that — a 24-hour
quiet window, accepting a bounded loss, or flushing the delayed set — and names the trap in
the second: `queue:retry` re-queues the *old* payload and fails identically, so recovery is
a fresh dispatch from the deliveries dialog, not a retry.

Worth being exact about the blast radius, because "fails permanently" reads worse than it
is. The failure is an `Error` at the first property read, before any query runs: nothing is
written, nothing is read across a tenant boundary, and the job lands in `failed_jobs` where
it is visible. What is lost is the work, not the isolation. `ProcessPayrollJob`
(`$tries = 1`) and `NotifyAnnouncementAudienceJob` (`$tries = 2`, no backoff) have no
delayed-retry window worth planning around; the exposure is webhook deliveries alone.

#### Tests

`QueuedJobTenantPredicateTest`'s compatibility test —
`omitting the tenant id keeps the old behaviour for jobs already queued` — is **deleted**,
because the behaviour it pinned is the behaviour this section removes. Keeping it passing
would have meant keeping the default.

Two replace it, and they fail on different regressions:

- `the tenant id is required on every job that scopes by it` reflects over all three
  constructors and asserts the parameter is **not optional** and typed **non-nullable
  `int`**. Optional is how the predicate became conditional in the first place; required
  but `?int` is the same defect one step later, since a caller could pass null explicitly
  and get the unscoped lookup back.
- `a job that scopes by tenant cannot be constructed without one` asserts the
  `ArgumentCountError`, which is what the signature actually buys.

Every other construction site in the suite now passes a tenant id — six in
`PayrollQueuedProcessingTest`, three in `NotificationDispatchTest`, two in
`WebhookDeliveryTenantScopeTest`. The three production dispatch sites already did, since
§11g; none of them changed.

---

### 15h. The other `LOWER()` wrappers — measured, and mostly left alone — **2026-09-23**

§15e closed the login path and recorded, as out of scope, that
`App\Services\Identity\IdentityResolver` still wraps four columns in `LOWER()`. That note
implied the same fix would apply there. **It does not, and the reason is worth keeping.**

`fetchCandidates()` is one tenant-scoped query with six OR'd predicates —
`national_id_hash`, `employee_code`, `badge_number`, `email`, `phone`, `name` — and a
disjunction is not the shape §15e fixed. Measured with `EXPLAIN QUERY PLAN` on SQLite
3.45.1 against the real index set, in three variants:

| Variant | Plan |
|---|---|
| As `main` stood | `SEARCH employees USING INDEX … (tenant_id=?)` |
| `employee_code` and `phone` moved to their generated columns | identical |
| **Every** term given an index | identical |
| *Control:* the same predicate with no `OR` | `SEARCH … (tenant_id=? AND employee_code_normalized=?)` |

The seek is on `tenant_id` alone in all three; the disjunction is then evaluated row by
row. The control is what makes this a measurement rather than a guess about the optimiser:
the index works, the `OR` is what stops it being reachable.

**So removing these wrappers cannot make the query faster, and three of them stay.**
`badge_number`, `email` and `name` keep `LOWER()`, with the measurement recorded at the
call site so the next reader does not "fix" them and conclude nothing happened. Note that
two of the three — `email` and `name` — have no index at all to defeat, which is a
separate observation and equally moot here.

**No input matches differently — measured, not argued.** Saying "the class now follows the
column's rule" would be misleading, because the class's rule *was* the column's rule: the
six-replacement expression in `IdentityResolver` was **byte-identical** to
`PHONE_EXPRESSION` in `2026_09_23_000003_index_login_identifier_lookups.php`, compared
programmatically rather than by eye. The class wrote the column's definition out longhand;
this change stops it doing that.

Confirmed differentially on SQLite 3.45.1: both forms — the inline `REPLACE` chain and
`phone_normalized` — run against 12 stored values including a slash (`091/234-5678`),
embedded letters, a non-breaking space, Arabic-Indic digits, empty and NULL, queried with
every value `normalizePhone()` accepts. **12 query values × 12 rows, 0 differences.**

The asymmetry this section records is therefore **unchanged, not introduced**:
`091/234-5678` normalises to `091/2345678` — the slash is not one of the six characters —
so a query for `0912345678` misses it, before and after, identically.

**What did change, and why it is not a performance claim.** `employee_code` and `phone`
now compare against `employee_code_normalized` and `phone_normalized`, the generated
columns added in §15e. Those columns already carry exactly these expressions, so the
comparison is unchanged and `IdentityResolutionTest` — exact code, case-insensitive email,
phone-only, name-resemblance, cross-tenant — is untouched and is the evidence. The gain is
that the normalisation now has **one** definition instead of two. The duplicate mattered:
§15e records a known asymmetry in the phone rule (`normalizePhone()` strips every
non-digit, the column strips six characters, so `091/234-5678` has never matched), and
that asymmetry existed **identically and separately** in this class. Anyone fixing it in
one place would have left the other behind.

**If this path ever needs to be fast**, the fix is structural: one indexed equality per
identifier, unioned in PHP, which also means deciding what `MAX_CANDIDATES` means across a
union rather than a single `LIMIT`. That changes matching behaviour and belongs in its own
review, not in a wrapper cleanup.

---

### 15g. §15f's account of itself was wrong, and the fixture gap that hid it is closed — **2026-09-23**

**Read this before §15f.** Two of §15f's claims were reasoned from the SQLite result rather
than measured on MariaDB, and both are false:

| §15f said | Measured |
|---|---|
| *"The value specified for generated column … is not allowed"* on MariaDB | **MariaDB accepts it** on the `mariadb` connection this project uses. `config/database.php` sets `'strict' => false` there, and every template — `.env.example`, `.env.production.example`, `.env.shared-hosting.example` — selects `DB_CONNECTION=mariadb` |
| *"the MariaDB rehearsal job passed on `main` for the same reason [no device rows]"* | **`DemoTenantSeeder` creates three devices**, the rehearsal job runs `db:seed --force`, and the job passed on `main` at `8b11904` **with those rows present** — a full destroy-and-restore, with `BackupService::restore()` throwing on the first failed statement. So the dump replayed |

**What that changes, and what it does not.** The defect was real and narrower than §15f
said: **a backup taken on the documented configuration was restorable.** The exposure was
never "no restore at all" on MariaDB; it was that replayability had become a property of
*whoever runs the restore* rather than of the backup — a strict `sql_mode`, a restore
through phpMyAdmin or a Plesk import, or `DB_CONNECTION=mysql` (which `config/database.php`
gives `'strict' => true`) each turn the same file into one that will not go back. On SQLite
it fails outright, measured. The fix and the guard are worth what they were; the severity
sentence was not.

`BackupGeneratedColumnReplayTest` now measures all three on both drivers rather than
leaving them to recollection — SQLite's refusal, MariaDB's acceptance on the default
connection, and MariaDB's refusal under `STRICT_ALL_TABLES`.

**All three are measured, including the mechanism.** When this section was first written
`sql_mode` was a *hypothesis* for why MariaDB accepted what SQLite refused. The test
settled it on MariaDB 10.11 in CI: the same statement is accepted on the connection as
configured and rejected once the session is put in `STRICT_ALL_TABLES`. Recorded that way
round — hypothesis, then measurement — because the entire subject of §15f and this section
is what happens when the two are confused.

**The fixture gap, which is the part that generalises.** The round-trip tests in
`BackupRestoreRehearsalTest` created a tenant and an employee and nothing else, so no
`devices` row ever reached a dump there and `serial_number_active` was never exercised.
Three tables carry generated columns today:

| Table | Generated columns |
|---|---|
| `users` | `email_normalized`, `username_normalized`, `phone_normalized` |
| `employees` | `employee_code_normalized`, `phone_normalized` |
| `devices` | `serial_number_active` |

`generatedColumnFixture()` creates a row in each, and
**`it('has a fixture row in every table that carries a generated column')`** reads that list
from `PRAGMA table_xinfo` rather than from a constant, so a generated column added to a
table the fixtures do not touch fails and names the table. A hardcoded list is what failed
here; replacing it with a longer hardcoded list would have failed the same way later.

**And the guard the manifest cannot be.** `BackupService::create()` now calls
`DatabaseDumper::findGeneratedColumnInserts()` and refuses to finish a backup whose INSERTs
name a generated column, deleting the half-written directory first so nothing is left that
could be mistaken for one. sha256 proves the dump is the file that was written; it cannot
prove the file can be put back, and those are different properties. Schema-driven, so a
future generated column is covered without the guard changing.

Deliberately **not** at restore time: an older backup taken before the dumper was fixed may
still restore on a permissive engine, and refusing to try would turn a recoverable
situation into an unrecoverable one.

Verified against a dump produced the pre-fix way — `SELECT *`, then an INSERT naming every
column it returned — because a check that only passes after the fix proves nothing unless
it failed before it:

```
=== pre-fix (SELECT *) ===
  first INSERT : INSERT INTO "users" ("id", "tenant_id", "email", "phone", "email_normalized", "phone_normalized") VALUES …
  guard        : FAIL — {"users":["email_normalized","phone_normalized"]}
  replay       : REJECTED — SQLSTATE[HY000]: General error: 1 cannot INSERT into generated column "email_normalized"

=== post-fix (explicit column list) ===
  first INSERT : INSERT INTO "users" ("id", "tenant_id", "email", "phone") VALUES ('1', '1', 'Selam@Acme.test', '0911 55-66-77');
  guard        : PASS — no INSERT names a generated column
  replay       : accepted
```

In the suite the same thing runs end to end: `PreFixDumper` is `writeRows()` as it stood
before the fix, bound over `DatabaseDumper`, and `create()` is asserted to throw and to
leave no directory behind.

**On both drivers, and it took a second pass to get there.** That test was written inside
`BackupRestoreRehearsalTest`, which skips on anything but SQLite because its *other* tests
drop every table and MySQL commits DDL implicitly. Nothing about the guard is
SQLite-specific — the throw path is one branch on an array — but `PreFixDumper` read
`sqlite_master` and `PRAGMA table_xinfo` and quoted identifiers with `"`, and the file-wide
skip did the rest. The result was a guard **proven to fire on SQLite and assumed to fire on
the engine production uses**, which is the same species of claim §15g exists to correct.
`PreFixDumper` now classifies columns through `DatabaseDumper::generatedColumns()` and
takes its quote character from the driver, and the test lives in
`BackupGeneratedColumnReplayTest`, which has no driver guard because it destroys nothing.

**Was a real backup affected?** Not answerable from this repository. Nothing here records a
`ethr:backup` run on any host, the product has never been deployed to Ethio Telecom (every
Gate 0 row is NOT VERIFIED), and whether a Docker or VPS install ever ran the command is
not something the repo can be read for. What *can* be said: on every configuration this
repository documents, such a backup restores.

---

### 15f. The backup could not be restored once any table had a generated column — **found and fixed 2026-09-23** *(severity corrected by §15g — read that first)*

Found while adding the generated columns in §15e, which is the only reason it was found at
all: it had already shipped, and every test of the backup path was green.

`DatabaseDumper::writeRows()` read each table with `SELECT *` and wrote an INSERT naming
every column it got back. `SELECT *` returns **generated** columns like any other, and
replaying an INSERT that supplies a value for one is rejected outright:

```
SQLite    cannot INSERT into generated column "email_normalized"
MariaDB   accepted on the `mariadb` connection — this line originally claimed an
          error, was never measured, and was wrong. See §15g.
```

`BackupService::restore()` throws on the first statement that fails — correctly, it
refuses to half-apply a dump — so the effect is not a partial restore. **It is no restore
at all.**

**Measured**, PHP 8.4.19 / SQLite 3.45.1, replaying a dump-shaped INSERT against the same
schema it came from:

```
SELECT * returns: id, email, email_normalized
replaying: INSERT INTO t ("id", "email", "email_normalized") VALUES ('1', 'Bob@X', 'bob@x');
RESULT: REJECTED — SQLSTATE[HY000]: General error: 1 cannot INSERT into generated column
```

**This was already live.** `devices.serial_number_active` (§11h) put a generated column in
the schema earlier the same day. From that commit, any database with **at least one device
row** produced a `database.sql` that was written without complaint, verified against its
own sha256, listed in the manifest — and **on some engines** could not be restored.
*(The original sentence ended "could not be restored", flatly. §15g measured it: on the
`mariadb` connection this project configures, it restored. Corrected here rather than
deleted.)* The round-trip tests kept passing because the tables they exercise held no
device rows. **The `Backup restore rehearsal on MariaDB` CI job did have them and passed
anyway — §15g.**

That is the property worth naming: **a backup defect of this shape is invisible at backup
time and only observable at restore time**, which is the one moment when there is nothing
to fall back on. It is exactly the failure mode `BackupRestoreRehearsalTest` exists to
catch, and it slipped past because the fixture data did not happen to populate the one
table that had the new column.

**Fixed.** `writeRows()` now selects an explicit column list from `writableColumns()`,
which drops generated columns per driver — `PRAGMA table_info` omits them on SQLite
(`table_xinfo` is the pragma that includes them, marked `hidden` 2 or 3), and
`SHOW FULL COLUMNS` reports `VIRTUAL GENERATED` / `STORED GENERATED` in `Extra` on
MariaDB. `SHOW FULL COLUMNS` rather than `information_schema` deliberately: it needs only
a privilege on the table itself, which is what a shared-hosting account is given.

Nothing is lost by dropping them. `SHOW CREATE TABLE` and `sqlite_master.sql` carry the
column *definition* into the restored schema, which recomputes the value from the columns
that are replayed — the new test asserts the recomputed value on the far side of a
destroy-and-restore, not merely that the dump parses.

**Pinned** by `BackupRestoreRehearsalTest` → *"never replays a generated column, so a dump
stays restorable"*, which asserts both halves: the schema still carries `email_normalized`,
and no `INSERT` line names it. The existing round-trip tests now cover it by construction
too, since `users` and `employees` always have rows — but a test that covers something by
accident is what let this through in the first place, so the property is stated explicitly.

---

### 15e. Every login scanned the tenant's rows — **found and fixed 2026-09-23**

Risk #12 read *"Unindexable login scans"* and cited one line. It was right, and it
understated the shape: **three of the four identifier lookups had a composite index
sitting right there that the query could not reach.**

`AuthIdentifierResolver` wrapped the column in a SQL function on every path —
`LOWER(email)`, `LOWER(username)`, `LOWER(employee_code)`, and a six-deep `REPLACE()`
chain over `phone`. A predicate on a *function of* a column cannot use an index on that
column, so each lookup fell back to the only index it could use, `tenant_id`, and then
examined every row belonging to that tenant.

**Measured, not reasoned** — `EXPLAIN QUERY PLAN` on SQLite 3.45.1, against the real
schema (`users_tenant_id_email_unique`, `users_tenant_id_username_unique`,
`users_tenant_id_index` present):

| Lookup | Before | After |
|---|---|---|
| email | `SEARCH users USING INDEX users_tenant_id_index (tenant_id=?)` | `SEARCH users USING INDEX users_tenant_id_email_normalized_index (tenant_id=? AND email_normalized=?)` |
| username | `SEARCH users USING INDEX users_tenant_id_index (tenant_id=?)` | `… (tenant_id=? AND username_normalized=?)` |
| phone | `SEARCH users USING INDEX users_tenant_id_index (tenant_id=?)` | `… (tenant_id=? AND phone_normalized=?)` |

**State the magnitude honestly: this was never a full table scan.** `tenant_id` is
indexed, so the engine narrowed to one tenant and scanned that. §13d's G0-J benchmark had
already measured the cost on MariaDB 10.4.32 against the real schema, and those numbers —
not the plan dump above — are the reason this was worth doing (ms per lookup):

| n (employees) | email | email if indexed | username | username if indexed | phone |
|---|---|---|---|---|---|
| 500 | 3.0 | 1.6 | 3.2 | 1.6 | 3.8 |
| 5,000 | 3.7 | 1.4 | 10.3 | 1.2 | 13.1 |
| 20,000 | 11.6 | 1.3 | 39.8 | 1.4 | 52.0 |

Flat against tenant size once indexed; linear in the tenant's rows before — and on the
phone path, linear in the tenant's **employees** as well, with six nested `REPLACE()`
calls evaluated per row. On an endpoint that requires no authentication to reach, against
a MariaDB instance shared with other Ethio Telecom accounts.

**§13d is where this was measured, and it is also where it was deliberately not fixed.**
Its reasoning was sound and its conclusion is now obsolete, so it is worth being exact
about which part changed. §13d established that `LOWER(column)` is redundant on MariaDB —
the columns are `utf8mb4_unicode_ci`, so a bare `=` already matches case-insensitively —
but that dropping it outright **breaks SQLite**, where `TEXT` compares case-sensitively
and the primary test suite runs. It concluded that a driver-identical fix meant *"a
driver-conditional column collation"*, priced that as a schema change, and left it.

A generated column is the third option it did not consider: it is a schema change, but
the *same* schema change on both engines. The normalisation moves from the predicate into
the column, so `LOWER()` still runs — once per write, on the engine's own terms — and the
comparison the query makes is a plain equality that an index can serve. Nothing becomes
driver-conditional.

**Fixed** by `2026_09_23_000003_index_login_identifier_lookups.php`: five VIRTUAL
generated columns — `users.{email,username,phone}_normalized`,
`employees.{employee_code,phone}_normalized` — each carrying the expression the query
used to apply, plus a plain `(tenant_id, …)` index on each. The resolver now compares a
plain column to a value normalised in PHP.

Four constraints, each load-bearing:

- **The expressions are copied character for character.** This must not change which
  identifier matches which user. `LoginIdentifierTest` is untouched and still green,
  which is the evidence for that claim; `LoginIdentifierIndexTest` covers the part a
  response cannot show.
- **VIRTUAL, not STORED**, and **no `->after()`** — the two traps
  `2026_09_23_000002_add_unique_index_to_device_serial_number.php` and
  `2026_09_17_000002_add_catalog_columns_to_plans.php` already record. SQLite refuses a
  stored generated column in `ALTER TABLE`; `after()` diverges column order between the
  engines and Scramble builds the OpenAPI component from column order.
- **Plain indexes, never unique.** On SQLite the existing `unique(tenant_id, email)`
  compares BINARY, so `Bob@x` and `bob@x` are two legal rows today and a unique index on
  `email_normalized` could fail on existing data. Uniqueness is a behaviour change; this
  is not.
- **The columns are `$hidden` on both models**, like `national_id_hash`. `devices.serial_number_active`
  is precedent that Scramble does not publish generated columns
  (it appears nowhere in `generated.ts`), so this is hygiene rather than the fix for a
  known leak — a derived column is not part of the resource.

**Two normalisation asymmetries were preserved deliberately**, and are recorded rather
than fixed because both are *matching semantics* and changing them inside a performance
migration would be exactly the silent behaviour change this section exists to avoid:

1. The phone input is normalised in PHP with `preg_replace('/\D+/', '')`, which strips
   **every** non-digit; the column strips six specific characters. A number stored as
   `091/234-5678` has never matched and still does not.
2. `LOWER()` is ASCII-only on SQLite and collation-aware on MariaDB, while the input side
   uses `mb_strtolower()`. A non-ASCII identifier already behaves differently per driver.

**What this does not cover, stated rather than implied.**

- `LoginRequest::authenticate()`'s super-admin lookup carried the same `LOWER(email)`
  wrapper and runs on *every* login attempt in the system. It is changed here too, for
  consistency and because it is one line, but it was never the expensive one: it also
  states `tenant_id IS NULL`, which is selective down to the handful of platform admins.
- `App\Services\Identity\IdentityResolver` still wraps four columns —
  `employee_code`, `badge_number`, `email`, `name` — in `LOWER()` inside `orWhereRaw`.
  That is a different subsystem (device identity matching), it is not on the login path,
  and two of those columns have no index to defeat in the first place. Out of scope here;
  recorded so the next reader knows the pattern was not swept from the codebase.
  *(Revisited 2026-09-23 — **§15h**. This bullet implied the §15e fix would apply there.
  It does not: that query is a six-way disjunction, and measurement shows it cannot use an
  index on any of those columns however they are written. Two now use the generated
  columns to remove a duplicated normalisation rule; three keep `LOWER()` deliberately;
  none of it is a performance change.)*
- **An indexed VIRTUAL generated column is now a hosting requirement, and the host's
  MariaDB version has never been read.** Verified on **MariaDB 10.11** — both CI database
  jobs run that image, and §11h's unique index on `devices.serial_number_active` has been
  green on it. Not verified anywhere else. Older MariaDB releases restricted indexes on
  virtual columns under InnoDB; the exact threshold is **not established here**, and this
  section does not invent one. §11h created the dependency first, on a table an install
  may legitimately leave empty; this change makes it unavoidable, because `users` and
  `employees` exist on every install and the migration adds the indexes at deploy time.
  **Read the host's `SELECT VERSION()` and run the migration against it before deploy** —
  it belongs in G0-E alongside the PHP extensions, and nothing in
  `deployment/GATE-0-RESULT.md` records a database version today.
- **The §13d benchmark was not re-run.** `composer install` cannot authenticate to
  github.com from this environment, so neither the suite nor
  `api/scripts/login-path-benchmark.php` could be executed locally; CI is the only gate
  this change passed through. §13d's own instruction — re-run the benchmark before and
  after, and expect every "current" column to collapse onto its "if plain `=`" column —
  **is still outstanding**, and is the evidence that would close the loop on the table
  above. The plan dumps in this section are measured; the *improvement* on MariaDB is
  inferred from §13d's `EXPLAIN` (`type=const … rows=1` for the plain-equality form)
  rather than timed.

**One thing found while reading the path, not fixed, and not a defect in the resolver.**
`AuthIdentifierResolver` drops `SoftDeletingScope` along with the tenant scope, so it can
return a soft-deleted row. For **users** that is safe, by two lines in another class:
`UserController::destroy()` sets `status = 'inactive'` before `delete()`, and
`LoginController` refuses a non-active account with 403. Measured 2026-09-23 — it is the
only site in `app/` that deletes a `User`.

For **employees** it is an open question and an owner decision:
`EmployeeController::destroy()` soft-deletes the employee and leaves the linked `User`
untouched and active, with no observer or model hook in between. An offboarded employee
therefore keeps their login — **by email as much as by employee number**, which is why
this is not a resolver defect and not something to patch here. Whether deleting an
employee should deactivate their account is a product decision about offboarding; it is
listed in §16.

---

### 15d. Dunning: one gap fixed, one is an owner decision — **2026-09-15**

Third and fourth findings from the billing tests §12 flagged as missing.

**Fixed — a long-overdue invoice was never escalated.** `HandleOverdueInvoicesJob` runs three tiers: remind at 7 days, mark the subscription past-due at 30, suspend the tenant at 60. Tiers 1 and 2 are bounded *above* (they stop at 30 and 60 days), and tier 3 matched only `overdue` and `past_due`. An invoice the job never saw during the earlier windows was therefore still `sent` at 60 days and **matched no tier at all** — the tenant was never suspended, however long they went without paying.

Reachable exactly when the scheduler stopped for a few weeks. That is the third defect today whose trigger is a dead cron, alongside §15b and §15c. Tier 3 now takes any unpaid status, because it is the safety net and cannot assume the tiers before it ran.

**~~Open~~ — resolved by owner decision 2026-09-15: invoices are created `sent`.**

`BillingService::generateMonthlyInvoice()` writes `status => 'draft'`. Tier 1 matches `status = 'sent'`. **Nothing in `app/` transitions an invoice from `draft` to `sent`** — the only occurrences of that string are the job's own three queries.

So on current behaviour no invoice generated by this system ever enters dunning: nobody is reminded, no subscription is marked past due, and no tenant is ever suspended for non-payment. The escalation chain is inert end to end.

**Resolved.** The owner decided a generated invoice counts as sent. `generateMonthlyInvoice()` now writes `status => 'sent'`, and the `todo` that pinned the broken behaviour is replaced by an end-to-end test that generates a real invoice and drives it through every tier to suspension.

**What is now live, and worth watching:** there is still no email step. An invoice is `sent` in the sense that it exists and is visible in the product, not that anything delivered it. Dunning therefore escalates on a bill the tenant has only seen in-app, and tier 3 **suspends** them at 60 days. The 7-day reminder also still sends nothing — it logs a warning saying so.

**And it exposed a further defect.** `due_date` was `Carbon::now()->addDays(15)` against a `$table->date()` column. MySQL truncates the time on insert, so it worked there by accident; SQLite does not, so it stored `2026-09-30 15:59:20`. The job compares against `Carbon::today()->subDays(7)`, which binds midnight, and `15:59:20 <= 00:00:00` is false — **every escalation fired a day late on SQLite and on time on MySQL**. A whole-day difference in when a customer is reminded, marked past due and suspended, present in tests and absent in production, in a codebase whose target is MySQL and whose suite is SQLite. Now normalised with `->startOfDay()` so both agree.

**Two smaller defects in the same tier, both fixed:**

- The 7-day tier logged `'Invoice 7-day overdue reminder sent'` **while sending nothing** — the comment beside it said so ("in production we'd have a dedicated OverdueInvoiceNotification"). That is the fabricated-success pattern this project has already been bitten by once: `AdminTenantController::backup()` told admins *"Backup job queued. You will be notified when the export is ready"* without queuing anything, which is why `BackupTenantJob` exists and says so in its header. The log now states plainly that no reminder was sent, at `warning` rather than `info`, because an invoice has gone unpaid for a week and nobody has been told.
- The admin lookup beneath it used `$invoice->tenant?->users()`. The job carries no HTTP tenant context, so `BelongsToTenant` resolved to `whereRaw('0 = 1')` and it found nobody, every time — the log could never have fired at all. Now scoped explicitly by `tenant_id`.

**A third, found by reading back the diff of the fix above rather than by a failing test.** The invoice status update sat *inside* the `tenant->status !== SUSPENDED` guard, so an invoice belonging to an already-suspended tenant kept `sent` for ever — re-selected on every nightly run and permanently mislabelled as merely sent while 60+ days overdue. Not a money error; a data-truth error, in a job whose only purpose is recording how far a non-payment has escalated. Lifted out of the guard, with an idempotency test so repeated nightly runs reach a stable state.

**Owner decision required.** See §16.

---

### 15c. Plan-change proration was unclamped — **found and fixed 2026-09-15**

Second defect found by writing the billing tests §12 flagged as missing, in the same file as §15b.

`BillingService::changePlan()` scales both plans by `daysRemaining / totalDays` and never bounded the result. Two money errors follow, both measured:

| Scenario | Reported | Correct |
|---|---|---|
| Upgrade on an **expired** period | **−126,667** — a *credit* of 1,266.67 ETB | 0 |
| Upgrade with a **future** period start | **406,667** — 4,066.67 ETB | ≤ 200,000 |

The first inverts an upgrade into a refund. Carbon 3 returns **signed** floats from `diffInDays`, so a `current_period_end` in the past makes `daysRemaining` negative. An `ACTIVE` subscription past its period end is reachable whenever renewal has not run — on shared hosting, whenever the scheduler stopped, which is precisely what `ethr:queue:check` now watches for. The two defects compound.

The second charges **more than the entire difference between the two plans**.

Fixed by clamping the factor to `[0, 1]`. Six tests; the two above were proven to fail first.

---

### 15b. Monthly invoicing had no idempotency guard — **found and fixed 2026-09-15**

Not a Phase 0 finding. It surfaced while writing the billing tests §12 flagged as missing, which is the argument for writing them.

`BillingService::generateMonthlyInvoice()` created an `Invoice` unconditionally, and `GenerateMonthlyInvoicesJob` called it for every active subscription without checking. **Any second execution billed every active tenant again.**

Reachable two ways, both real:

1. **The queue.** Until the same day, this job declared no `$timeout`, inheriting the worker's 60s default against a `retry_after` of 90s (§7a). A run over 90 seconds — plausible, it walks every subscription — was re-reserved and executed twice while the first was still going. Both halves were fixed on 2026-09-15; this is the half that survives the queue being configured correctly.
2. **The documented recovery.** The job's own `failed()` handler tells operators to *"re-run manually if needed"*. Without a guard, that advice double-bills everyone invoiced before the failure.

Fixed by keying on the calendar month, since no billing-period column exists and the schedule is `monthlyOn(1)`. Honest about its limit: a run starting at 23:59 on the last day and retrying after midnight would still produce two. A `period` column on `invoices` is the fuller fix and a schema change — **still open**.

Five tests, and the first two were **proven to fail before the fix** ("a second run in the same month created a duplicate invoice", 2 instead of 1). One deliberately guards the opposite failure: that the guard must not be so broad it stops billing altogether, which would be worse and silent.

---

### 15a. Frontend dependency advisories — amended 2026-09-15, **closed 2026-09-16**

> **The production half of this gate is now green.** `npm audit --omit=dev` reports **0 vulnerabilities**, and `scripts/gates.sh security` passes both halves for the first time.
>
> The two criticals went on 2026-09-15 with the `next` 16.2.12 → 16.3.5 upgrade (`ae52e08`). The remaining three — `browserslist`, `fast-uri` and `baseline-browser-mapping` — went on 2026-09-16 via `npm audit fix`, which moved five transitive packages by patch or minor and nothing else:
>
> | Package | From | To |
> |---|---|---|
> | `baseline-browser-mapping` | 2.10.40 | 2.11.24 |
> | `browserslist` | 4.28.4 | 4.29.0 |
> | `caniuse-lite` | 1.0.30001799 | 1.0.30001810 |
> | `electron-to-chromium` | 1.5.380 | 1.5.430 |
> | `fast-uri` | 3.1.5 | 3.1.8 |
>
> All three arrived transitively through `@sentry/nextjs`'s webpack and babel chain, so they are **build-time** tools rather than code that reaches a browser. That lowers the exposure; it does not change that the fix was in range and free.
>
> The lockfile change is 26 insertions and 26 deletions — pure version swaps, no package added or removed. Verified with `npm ci` (which also proves the lockfile installs cleanly, as CI does) followed by all five frontend gates.
>
> The text below is the state before that, kept because point 2 is a standing lesson about revisit conditions.

`scripts/gates.sh security` was added in Phase 2 and was **red on first run**.

**Production dependencies: 5 advisories (1 critical, 3 high, 1 moderate)** — these ship to users:

| Package | Severity | Advisory |
|---|---|---|
| `next` 16.2.12 | **CRITICAL** | [GHSA-p293-qw3h-jr36](https://github.com/advisories/GHSA-p293-qw3h-jr36) — unauthenticated RCE on Windows-hosted servers |
| `next` 16.2.12 | **CRITICAL** | [GHSA-2xp9-vwfh-vxw4](https://github.com/advisories/GHSA-2xp9-vwfh-vxw4) — unauthenticated RCE in the Image Optimization API via AVIF |
| `sharp` | high | libheif vulnerabilities, reached transitively through `next` |
| `browserslist` | high | unbounded memory growth; prototype write via untrusted stats |
| `fast-uri` | high | SSRF and host confusion |

A further 22 advisories are in dev-only tooling (`@lhci/cli`, `vitest`, `puppeteer`, `@redocly/openapi-core`). Real, but they do not ship, which is why the gate separates them — 27 reported together buries the 5 that reach users.

**Three things make this more actionable than it looks:**

1. **The fix is in range.** Affected versions end at `16.3.2`; `16.3.3` is fixed and `16.3.5` is current. `package.json` declares `^16.2.9`, so this is `npm update`, not a major upgrade.
2. **`docs/security-audit.md`'s plan would not have worked.** It records "no clean in-range fix … revisit on Next 16.3.0 stable". The affected range now extends past 16.3.0, so that revisit would have found the problem unfixed.
3. **Exposure is narrower than the headline** — but not zero, and not by design. The Windows RCE needs a Windows-hosted Next server; the production target is Linux, though development is Windows. The AVIF RCE needs the Image Optimization endpoint, and §11 records **zero `next/image` usages** — but `/_next/image` exists whenever the Next server runs, whatever the application calls.

### Resolved 2026-09-15 — `ae52e08`

`next` 16.2.12 → **16.3.5**, `sharp` override `^0.35.3` → **`^0.35.4`**.

| | Before | After |
|---|---|---|
| Critical | 2 | **0** |
| High | 3 | 2 |
| Moderate | 1 | 1 |

Verified against a pre-upgrade baseline captured deliberately first, so a failure would have been attributable rather than ambiguous:

| Check | Before | After |
|---|---|---|
| Vitest | 70 files / 435 tests | **70 / 435, identical** |
| i18n · Prettier · ESLint · tsc | pass | **pass** |
| `next build` | — | **exit 0** |

**Still open:** `fast-uri` (high, SSRF) and `browserslist` (high), both transitive with no direct override path. Reported rather than forced.

**Two things the upgrade surfaced:**

- **`middleware` is deprecated in Next 16.3**, in favour of `proxy`; the build now reports `ƒ Proxy (Middleware)`. Low impact here — §11 records `src/src/middleware.ts` as already inert in production and self-declaring nginx as the authoritative control, and under static export it would be deleted rather than migrated. Do not spend a codemod on it before G0-G answers whether the frontend keeps a Node server at all.
- **Nearly every route printed `○ (Static) prerendered as static content`.** That corroborates §11's finding that this is substantively a client-rendered SPA, and makes static export look more tractable than a count of 78 pages suggests. It does not resolve the actual constraint, which remains the relative `baseURL` at `src/src/api/client.ts:14` and its dependence on `rewrites()`.

**Backend is clean:** `composer audit` passes.

---

## 16. Decisions required from the owner

1. **P0-1 remediation timing** — fix the import scope leak in Phase 0.5 (security hygiene) or defer to the tenant-isolation phase? One line plus a regression test. Recommendation: Phase 0.5, since §10 classes it as a security finding.
2. **The 19 unbacked commits** — the owner elected to leave them. Re-flagged only because §15.8 rates it Medium and it is the cheapest risk on the list to retire.
3. **Master plan §5 identity block** — confirm it should be corrected to `F:\et` / `main` / `syntax-ict/Ethr.et.git` in Phase 1.
4. **Docker availability** — the API-contract gate still needs a migrated database. Native PHP covers the rest (§12), so this is narrower than first recorded.
5. ~~Upgrade `next`~~ — **done** (`ae52e08`), verified, both criticals cleared.
6. ~~Merge `docs/phase-0-baseline` into `main`~~ — done (`2b47bdf`).
7. **Does a generated invoice count as `sent`?** (§15d, risk 18.) Nothing transitions `draft` → `sent`, so no invoice ever enters dunning and no non-paying tenant is ever suspended. Either `generateMonthlyInvoice()` should create them as `sent`, or there is a send step that was never built. This is a billing-process decision and is the last thing blocking the dunning chain from working at all.
8. **Should deleting an employee deactivate their login?** (§15e.) `EmployeeController::destroy()` soft-deletes the employee and leaves the linked `User` active — no observer, no model hook, nothing in between. An offboarded employee keeps their account and can still log in, **by email as much as by employee number**, so this is not a tenancy or identifier-resolution bug and was deliberately not patched alongside §15e. It is an offboarding-policy decision with a real argument on each side: deleting a record that was created in error should probably not lock someone out, and a user may hold an account without being staff. Recommendation: deactivate, with an explicit reactivation path, since an HCM product that leaves ex-employees able to sign in is the more surprising default.
9. ~~**Should `GET /attendance/{attendanceRecord}` be org-scoped?**~~ **ANSWERED and FIXED 2026-09-23 — §12j.** Yes, and so should `GET /employees/{employee}/attendance/timeline`, which had the same missing check and a far easier identifier to obtain. Both now route through a policy that pairs the ability with `canAccessEmployee()`. **This entry as first written claimed the record id was "a sequential integer" — it is a ULID, and the correction is §12j.** The gap was real and the stated route in was wrong.

10. ~~**Should the reporting tree respect `orgScope()`?**~~ **ANSWERED 2026-09-24 — owner decision: leave it tenant-wide.** (§12j.) `EmployeeController::reportingTree()` returns every employee in the tenant with `directReportsRecursive`, gated on `employee.viewAny` (supervisor and above) and applying no `scopeAccessibleEmployees()` — its sibling `index()` applies one. That asymmetry is now **intended and recorded**, not a gap awaiting a fix. It was the identifier source in the §12j chain: a supervisor read it to obtain the `public_id` of anyone in the tenant. With both attendance reads scoped (§12j), what it discloses is a name and a reporting line, which is what an org chart is **for**. **The standing rule this establishes: never authorise an endpoint on the secrecy of an employee `public_id`.** The tree hands those out to every supervisor by design, so any endpoint treating one as a secret is already wrong. If a tenant ever needs a scoped chart, that is a feature request with a permission of its own — not a patch to this method, and not a reason to revisit this decision.

---

## 17. What was NOT done in this phase

No application code changed. No architecture decisions taken. No dependencies added or removed. No documentation corrected — the drift in §12b is *recorded*, not fixed. The pending `deployment/ → docs/deployment/` move was left exactly as found. No remote git operation of any kind. No hosting capability asserted as fact.

---

## 18. Public-site baseline — **measured 2026-09-17**

§13e recorded that no performance baseline existed. Two now do, measured the
same day from opposite ends of the stack and neither a substitute for the other:
**§13e covers the backend** (API response times, Pest, SQLite), and **this
section covers the public site** — a production build served by `next start`,
measured by `./scripts/gates.sh lighthouse`, three runs per URL, medians below.

| route | perf | a11y | best-pr. | SEO | FCP | LCP | TBT |
|---|---|---|---|---|---|---|---|
| `/am` | 99 | 100 | 96 | 100 | 298 ms | 881 ms | 5 ms |
| `/en` | 99 | 100 | 96 | 100 | 340 ms | 890 ms | 0 ms |
| `/en/contact` | 100 | 100 | 96 | 100 | 293 ms | 790 ms | 0 ms |
| `/en/features` | 99 | 100 | 96 | 100 | 339 ms | 875 ms | 1 ms |
| `/en/pricing` | 99 | 100 | 96 | 100 | 337 ms | 881 ms | 2 ms |
| `/login` | 99 | 100 | 100 | 63 | 301 ms | 881 ms | 0 ms |
| `/register` | 99 | 100 | 100 | 63 | 294 ms | 874 ms | 0 ms |

**Read the `preset` before reading the scores.** `.lighthouserc.cjs` uses
`preset: "desktop"` — no CPU throttling worth the name and a fast simulated
network. These numbers say the pages are well built; they say **nothing** about
a mid-range Android on an Ethiopian mobile network, which is the audience. Phase
8 is about the 427 KB below, and a desktop 99 is not evidence against it.

**SEO 63 on `/login` and `/register` is correct and deliberate.** The single
failing audit is `is-crawlable`: `app/robots.ts` disallows both. The config
asserted SEO ≥ 0.9 on them as an *error* until 2026-09-17, which was a target the
site's own robots.txt guaranteed could never be met; the assertion is now scoped
away from those two URLs and they are still measured for everything else.

### First Load JS

| | gzipped | raw |
|---|---|---|
| Landing page, all client chunks | **427 KB** | 1,415 KB |
| The same with `instrumentation-client.ts` stubbed out | 340 KB | 1,131 KB |
| → Sentry's share | **87 KB** | 284 KB |
| Dashboard, for comparison | 470 KB | 1,562 KB |

Measured by summing the gzipped size of every `/_next/static/chunks/*.js` the
built `.next/server/app/en.html` references. Sentry's figure is a difference of
two builds, not an estimate. Its docblock argues deliberately for the static
import — read it before changing anything there.

Per-locale page weight, which the dictionary projection decides:
`/en/pricing` is 14.0 KB gzipped of HTML against `/am/pricing`'s 7.4 KB, because
the `[locale]` layout ships a 7.5 KB projection of `en.json` and `am.json` is
already eager.

### Four defects this measurement found

None were visible from the source, and the first is the reason the rest were
found at all.

1. **The gate measured the wrong pages.** `.lighthouserc.cjs` still collected
   `/`, `/pricing`, `/features` and `/contact` — the unprefixed URLs, which are
   now redirectors rendering an empty div. Lighthouse has no stored locale, so it
   would have scored blank pages and reported them passing.
2. **`/favicon.ico` 404'd on every page load.** `baseMetadata` declared it and
   the file did not exist — `public/` had eight PNG icons and no `.ico`. A real
   ICO container (PNG payload, 96×96) is now committed. `best-practices` on
   `/login` and `/register` went 96 → 100 when it landed.
3. **Heading levels skipped.** `product-flow.tsx` used `h3` directly under the
   hero's `h1`; the footer used `h4` after an `h2`; the pricing plan cards used
   `h3` under the page `h1`. `accessibility` was 98 across the public site and is
   now 100.
4. **The language switcher's accessible name did not contain its visible text.**
   The button shows the current language's own name and announced only "Change
   language" — WCAG 2.5.3, and a voice-control user could not say what they could
   see.

### Three findings left open, deliberately

- **`--text-secondary` (#6c7b91) is 4.3:1 on white.** AA needs 4.5:1 for text
  below 18.66px bold / 24px, so *every* `text-sm text-muted-foreground` on a white
  surface is marginally under. Lighthouse flags it intermittently, which is what a
  4.3 against a 4.5 threshold looks like. Fixing it means darkening the token and
  repainting the whole product — a Phase 8 decision, not a landing-page one. One
  10px label in `product-flow.tsx` was moved to `text-foreground` because 10px is
  the worst case; nothing else was touched.
- **`/icons/badge-72.png` does not exist**, and both `public/manifest.json` and
  `public/sw.js:110` reference it. Push-notification badges are therefore broken
  app-wide. Not touched: it is service-worker behaviour, not the public site.
- **Two manifests.** `public/manifest.json` is the one linked from every page;
  `app/manifest.ts` generates `/manifest.webmanifest`, which nothing references.
  Both serve 200. One of them is dead, and deciding which is a PWA question.

### The 403s in the report are not a defect

Every run logs `403` for `/api/v1/site-content` and `/api/v1/plans`. No backend
was running — the pages are built to render with no network at all, which is
exactly what they did. `best-practices` sits at 96 on the marketing pages for
that reason alone; `/login` and `/register` make no such call and score 100.

### How to reproduce

```bash
cd src && npm run build
node .next/standalone/server.js      # or `npx next start -p 3000`
CHROME_PATH=/path/to/chrome LHCI_BASE_URL=http://localhost:3000 \
  ./scripts/gates.sh lighthouse
```

The gate refuses rather than skips when nothing is serving. A Lighthouse run that
silently measures nothing is worse than no run, because the report still renders
and still looks like evidence — which is precisely how defect 1 above survived.
