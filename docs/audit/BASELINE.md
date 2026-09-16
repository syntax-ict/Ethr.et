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

### 3a. BLOCKER — Horizon hard-requires two extensions shared hosting rarely has

**[verified]** `api/composer.lock:1999-2001`:

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

### 12d. The Frontend CI job fails and does not reproduce locally **[open]**

CI run #56 (2026-09-16, `49e2ee9`): the `Frontend (i18n, Prettier, ESLint, tsc, Vitest)` job exits 1 after 2m 23s, with no file-level annotations — only "Process completed with exit code 1".

Locally the same gate passes in the **exact CI shape**, meaning with no `.next/` directory present at all:

```
✓ i18n   ✓ Prettier   ✓ ESLint   ✓ TypeScript (tsc)   ✓ Vitest (70 files, 435 tests)
```

Ruled out by direct test:

| Hypothesis | Test | Result |
|---|---|---|
| `tsc` needs `.next/types`, absent on a clean checkout | deleted `.next/` entirely, ran the gate | passes — `tsc_gate`'s `next typegen` step handles it |
| Node engine incompatibility (CI pins Node 20, local is 24) | read `engines` from next/eslint/typescript/vitest | all declare ≥20.9 or lower; `setup-node` "20" satisfies every one |
| `node_modules` drifted from the lockfile | `npm ls --depth=0`, then a full `npm ci` | only extraneous `sharp` WASM fallbacks; nothing missing or invalid |
| Import path case-sensitivity (Linux resolves case-sensitively, Windows does not) | resolved **1,850** imports — 115 relative and 1,735 `@/` alias — comparing every path component against the real directory listing with exact string equality | zero mismatches, zero unresolved |

**Not yet read: the CI log itself.** The repository is public, but job logs are not available to an anonymous client — the REST API returns 403, `…/checks/<id>/logs` returns 404, and GitHub's log viewer does not expand steps for a signed-out viewer. Reading it needs a signed-in session.

That is the next step, and it should be the *first* step. Four hypotheses were tested and all four were wrong; a fifth guess is worth less than one look at the output. Recorded here so the work is not repeated.

**Partly fixed at the source.** `scripts/gates.sh` now emits a GitHub Actions error annotation naming each failed gate when `GITHUB_ACTIONS` is set. Annotations *are* visible without signing in — that is how §12c's four PHPStan errors were read — so the next run of this job will say which of the five gates failed, rather than only "Process completed with exit code 1". It does not give the error text, but it converts a blind guess into a one-line answer, and it applies to every job rather than just this one.

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
> **Determinism.** The tests set no `TZ`. They pass identically under `Africa/Nairobi` (this machine, UTC+3), `UTC` (CI) and `America/New_York` — verified by running all three. Removing the `timeZone` option, i.e. reverting to the old behaviour, fails **9 of 20**.
>
> **Still to migrate:** other surfaces that format dates inline rather than through these helpers — schedules, shifts, payroll dates, reports, notification and audit displays. The default makes them render Addis time today instead of the browser's, so they are correct for the common case; they become tenant-aware as each is moved onto `useDateFormatters()`.

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

> **A second environment-dependence found while writing those tests.** en-GB renders September as `Sep` under older ICU and `Sept` under newer. Node 24 locally produces `Sept`; CI pins Node 20. Asserting either literal would pass on one runtime and fail on the other, so the month is matched as `/Sept?/` while the day, year and time are asserted exactly.

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

### 12e. Backend coverage needs a PHP extension — `phpdbg` is not a way round it **[open]**

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

### 13c. Horizon blocks `composer install` — see §3a.

### 13d. Unindexable login lookups **[verified]**

`api/app/Services/Auth/AuthIdentifierResolver.php:104-118` runs a 6-deep nested `REPLACE(REPLACE(...))` on `phone` inside `whereRaw`, and lines `:86`, `:136`, `:147` plus `LoginRequest.php:69` use `LOWER(email) = ?`. Neither expression can use an index; there is no `phone` index at all and no functional index on `email`.

> **Corrected 2026-09-15 — this entry originally said "full table scan of `users`, then of `employees`". That overstated it.** Both queries filter on `tenant_id` first, and both tables carry `index('tenant_id')` (`0001_01_01_000000_create_users_table.php:77`, `0001_01_01_000003_create_organization_tables.php:31`), so the scan is bounded to **one tenant's rows**, not the whole table.
>
> The defect is real but smaller: O(rows-in-this-tenant) `REPLACE` evaluations per phone login. Negligible at a few hundred employees; it starts to matter in the thousands, on a contended shared vCPU, on the one request where slowness is most visible.
>
> Recorded rather than quietly edited, because the original claim was repeated in several commit messages that cannot now be amended — and because overstating a finding is the same failure as understating one.

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

### 13e. No performance baseline exists

`api/tests/Performance/ResponseTimeTest.php` runs against SQLite and is excluded from the default sweep. The budgets at `docs/CLAUDE.md:850-862` were set against dedicated hardware. **No shared-hosting measurement exists — NOT VERIFIED.**

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
| 1 | **No backup or restore path for any non-Docker host** — **built 2026-09-15**, round-trip tested locally; **not yet rehearsed on the host** | `ethr:backup` / `ethr:restore`, `BackupRestoreRehearsalTest` **[verified locally]** | **High** (was Critical) — still a go-live gate |
| 2 | ~~**Cross-tenant import lookup (P0-1)**~~ — **fixed in Phase 0.5**; the lookup now states `tenant_id` itself (`EmployeeImporter.php:110`), and `withoutGlobalScopes()` deliberately stays so the soft-delete scope is still dropped (D-001) | `TenantImportIsolationTest` — 4 tests, 9 assertions, green **[verified]** | Resolved |
| 3 | ~~Payroll times out mid-transaction~~ — **queued** (`ProcessPayrollJob`), 202 + polling | `PayrollQueuedProcessingTest` **[verified]** | Resolved |
| 4 | ~~Duplicate job execution~~ — **fixed** (`76ca983`), invariant now tested | `QueueRetryAfterInvariantTest` **[verified]** | Resolved |
| 5 | ~~**Audit-log `DEFINER` breaks after restore** → all writes 500~~ — **fixed**; `DatabaseDumper` reconstructs triggers from `SHOW TRIGGERS` with no `DEFINER` clause, so the restoring user becomes the definer. Measured 2026-09-16 on MariaDB: a DEFINER-carrying dump is refused outright without `SUPER` (§13b), and a non-root user restores and enforces both triggers | `ethr:backup:rehearse` on MariaDB; `BackupRestoreRehearsalTest` **[verified]** | Resolved — but only for `ethr:backup`. A Plesk panel export still carries a DEFINER and may be unrestorable; see §13b |
| 6 | ~~Horizon aborts `composer install`~~ — **removed** (`cdf85d1`); lockfile carries **zero** hard `pcntl`/`posix` requires | lockfile parsed **[verified]** | Resolved |
| 7 | ~~Two critical RCE advisories in a production dependency~~ — **fixed 2026-09-15** (`ae52e08`) | `npm audit --omit=dev` **[verified]** | Resolved |
| 7b | ~~No CI of any kind~~ — **configured in Phase 2, never executed** | `.github/workflows/` **[verified]** | Medium (was High) |
| 8 | ~~19 commits exist only on this machine~~ — **pushed 2026-09-15**, 32 commits on `origin` | `git push` exit 0 **[verified]** | Resolved |
| 9 | ~~Queue can stop silently~~ — **heartbeat + `ethr:queue:check` built**; alert transport still needs G0-H | `QueueHealthTest` **[verified]** | Low (was Medium) |
| 10 | **No coverage instrumentation**; billing near-untested — first billing tests added 2026-09-15, which immediately found §15b | `phpunit.xml`, `vitest.config.ts` **[verified]** | **Half closed.** Frontend measured 2026-09-16 — 32.34% statements, **170 of 321 files at 0%** (§12f). Backend still blocked on PCOV or Xdebug (§12e) |
| 15 | ~~Monthly invoicing had no idempotency guard — any re-run double-billed every tenant~~ — **fixed** (§15b) | `MonthlyInvoiceIdempotencyTest` **[verified]** | Resolved |
| 16 | ~~Plan-change proration unclamped — an upgrade on an expired period reported a credit~~ — **fixed** (§15c) | `PlanChangeProrationTest` **[verified]** | Resolved |
| 17 | ~~A 60-day-overdue invoice was never escalated if earlier tiers were missed~~ — **fixed** (§15d) | `OverdueInvoiceEscalationTest` **[verified]** | Resolved |
| 18 | ~~Nothing transitions an invoice from `draft` to `sent`~~ — **owner decided 2026-09-15**, invoices are created `sent`; chain verified end to end | `OverdueInvoiceEscalationTest` **[verified]** | Resolved |
| 19 | ~~`due_date` stored with a time component against a `date` column — escalations fired a day late on SQLite, on time on MySQL~~ — **fixed** | §15d **[verified]** | Resolved |
| 11 | ~~**No tenant-isolation regression enforcement**~~ — **built 2026-09-16**. `TenantScopeBypassInventoryTest` pins all **156** `withoutGlobalScope(s)` call sites across **53** files, per file, and fails when the count moves. Proven to fail: injecting one bypass produced `COUNT CHANGED (1 -> 2)` | `tests/Feature/Security/tenant-scope-bypasses.php` **[verified]** | Resolved *as far as a count can* — it makes adding a bypass deliberate; it does not audit the 156 that exist. That audit is still unowned |
| 12 | **Unindexable login scans** | `AuthIdentifierResolver.php:104` **[verified]** | Medium |
| 13 | ~~**Documentation asserts controls that do not exist**~~ — **corrected in Phase 1** (D-003); the four documents now describe what is true, and the CI they claimed exists and runs | `docs/CLAUDE.md`, `SECURITY.md` + 2 **[verified]** | Resolved — the failure mode recurred in a new form, though: CI then *existed* and had never passed. See the CLAUDE.md CI section |
| 14 | **All hosting capabilities unverified** | checklist **[verified]** | Blocks Gate 0 |

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

---

## 17. What was NOT done in this phase

No application code changed. No architecture decisions taken. No dependencies added or removed. No documentation corrected — the drift in §12b is *recorded*, not fixed. The pending `deployment/ → docs/deployment/` move was left exactly as found. No remote git operation of any kind. No hosting capability asserted as fact.
