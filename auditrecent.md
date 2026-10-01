# Audit — recent state of the repository (2026-09-30)

**Scope:** `migration/bronze-plesk` at `15492c3` (55 commits ahead of `origin/main`, PR #133, draft),
the GitHub remote (branches, PRs, workflows, issues), every tracked document, and the backend and
frontend source. **Method:** four independent read-only reviews (docs, infrastructure remnants, backend,
frontend) plus direct checks. Every finding marked **VERIFIED** was re-checked against the tree or
GitHub by the author of this file, not only reported by a reviewer. Findings a reviewer could not
confirm are marked **SUSPECTED** and are not acted on.

**Baseline before any change:** `./scripts/gates.sh quick` → *All gates passed* (Composer, Pint, i18n,
Prettier, ESLint 0 errors / 10 warnings, tsc, docs links 489/96). Remote CI on `15492c3`: green.
The repository is healthy; the problems below are the kind a green gate does not see.

**Status column:** `FIXED` (changed and verified in this pass) · `OPEN` (deliberately left, reason given) ·
`OWNER` (a decision that is not mine to make, or an action that is outward-facing).

---

## 1. Git and remote

| ID | Sev | Finding | Status |
|---|---|---|---|
| G1 | **High** | **The scheduler does not exist on the default branch.** `.github/workflows/cron.yml` is on this branch only. GitHub fires `schedule:` triggers from the default branch, so nothing drains the production queue or runs `schedule:run` until PR #133 merges. `main` has three workflows and no `cron.yml` (VERIFIED, `git ls-tree origin/main`). | OWNER — merge is the fix; PR #133 is a draft marked do-not-merge |
| G2 | Med | 41 of 61 remote branches are stale: their PRs are MERGED. `git branch --merged` reports none because PRs are squash-merged, so ancestry cannot see it (VERIFIED via `gh pr list`). | FIXED 2026-09-30 — 40 deleted on the owner's explicit instruction (see §9) |
| G3 | Med | PRs #17 and #21 are CONFLICTING/DIRTY (opened 09-19, 09-22). #18 (docs-only, 09-17) is clean but idle. | **#21 RESOLVED 2026-10-01** — its one unsuperseded change (the `Password::broker()` guard) landed on `main` via #135 (`4f64327`) and #21 is closed with its branch kept. #17 (tenant landing pages, ~14,400 lines) and #18 remain the owner's call |
| G4 | Med | Dependabot PRs #8, #10, #12, #13 (09-15) show **9 failing checks each** — they predate the CI repair and were never rebased. #9 has 1 failing, #134 has 3. | OWNER — `@dependabot rebase` |
| G5 | Low | Issue #120 ("move `laravel/reverb` and `flysystem-aws-s3-v3` to `require-dev`") is still open and is contradicted by the code: both are referenced at runtime (see I9). | OPEN — recorded in I9 |
| G6 | Info | Local-only directories `.deploy-artifacts/` (38 MB), `.local-production/` (205 MB, 16,747 files), `.m2-kit/` (host probe kit, excluded via `.git/info/exclude`) are untracked. Correct; noted so nobody commits them. | none |

## 2. Security (act first)

| ID | Sev | Finding | Status |
|---|---|---|---|
| S1 | **High** | **Password-reset and account-activation emails link to `http://localhost:3000` in production.** Both notifications read `config('app.frontend_url', 'http://localhost:3000')`; no config file, `.env*` or doc defines `app.frontend_url` (VERIFIED, `git grep`). `PasswordResetLinkNotification.php:28`, `AccountActivationNotification.php:38`. | FIXED — `config/app.php` `frontend_url` (`FRONTEND_URL`, else `APP_URL` outside `local`); 3 tests |
| S2 | **High** | **SAML signature verification is forgeable.** `SamlProvider::verifySignature` verifies `openssl_verify` over the non-canonicalised `saveXML()` of `SignedInfo`, and **never checks the `<Reference>` digest** (VERIFIED: no `DigestValue`/C14N anywhere in the file). The signature therefore covers none of the assertion: any IdP-signed `SignedInfo` can be attached to a forged assertion. Also `LIBXML_DTDLOAD` at load. **Running the new tests against the old code showed a second defect: it rejected a *legitimately* signed response too** (it verified non-canonical XML), so SAML SSO was probably broken for compliant IdPs as well as forgeable. | FIXED — verifier rewritten (exclusive-C14N, Reference digest, enveloped-signature bound to the one assertion, SHA-2 only, DOCTYPE refused, Issuer/Audience/expiry required, replay rejected). 12 tests with real signatures; 10 fail on the old code. **Not implemented:** `InResponseTo` (needs request-ID storage; IdP-initiated flows legitimately lack it) |
| S3 | Med | `printPayslip()` (`payroll/payslips/page.tsx:41-85`) interpolates `employeeName` and every `labels.*` into an HTML string and `document.write()`s it into a same-origin window; CSP allows `'unsafe-inline'`. A name containing markup executes in the app origin (VERIFIED). | FIXED — `escapeHtml` on every interpolated value; unit-tested |
| S4 | Med | Frontend opens a WebSocket on every logged-in page in production. `src/.env.production` (tracked) sets `NEXT_PUBLIC_REVERB_HOST=ethr.et:443`; `ReverbProvider` mounts unconditionally and calls `initEcho()`, which defaults to `localhost:8080` / key `ethr-key`. The host is Apache with `connect-src 'self'` and `BROADCAST_CONNECTION=null` (VERIFIED). Every page load attempts a handshake that cannot succeed. | FIXED — realtime is opt-in on `NEXT_PUBLIC_REVERB_APP_KEY`; no `localhost`/`ethr-key` defaults; `REVERB_*` removed from `.env.production`; 3 tests |
| S5 | Med | Device `connection_config` was persisted raw and no host check ran, while the server connects to that host from `devices:sync`, the pull job and `GET /devices/{id}/status`, whose response carries the connection error. **Traced and confirmed:** a blind SSRF and port scan of the host's own network. Also: three adapters interpolate `ip` into `http://{ip}:{port}`, so `127.0.0.1:8443/admin#` rewrote the URL; the generic adapter's `base_url + path` let `@evil/x` change host; `UpdateDeviceRequest` made generic devices unupdatable; and `ExternalUrl` (webhooks) let `http://[::1]/` through because `parse_url` keeps the brackets. | FIXED (Phase 2) — `DeviceConnectionConfig` rule per adapter; `DeviceHost` guard before every request (covers rows stored before the rule); `OutboundHost` shared with `ExternalUrl`; private addresses behind `DEVICE_ALLOW_PRIVATE_HOSTS`, off on shared hosting because biometric devices legitimately use `192.168.x.x` on-premise. 31 tests, 28 fail on the old code. Fixing the adapters' config typing also retired 15 PHPStan baseline entries. Residual: DNS rebinding between check and connect |
| S6 | Low | `ScimAuth` resolves the tenant from the API key without `isActive()` (unlike `ResolveTenant`); a suspended tenant's SCIM key may still provision. **Traced and confirmed:** the middleware set the tenant from the key with no `isActive()` check, and a null tenant hit a `TypeError`. | FIXED — 403 for an inactive or missing tenant; test added; the baselined `ScimAuth:35` entry is gone |
| S7 | ~~Low~~ **High** | Public catalogue endpoints (`plans`, `site-content`, `templates`) carry no throttle beyond the shared `api` limiter. **Corrected 2026-10-01: there was no shared limiter.** Since Laravel 11 the api group carries no throttle unless `throttleApi()` is called, and it was not — so the whole authenticated API and the public catalogue took unlimited requests (measured: 400 of 400 returned 200). Separately, `throttle:auth` keyed on the IP alone counted successful logins too, so an office behind one NAT address locked its 11th person out at 8:00 | **FIXED 2026-10-01** — `throttleApi('api-global')`: 300/min per user (per IP for guests) on every api route; `public-catalogue` 120/min per IP; `auth` keyed per account+IP (10/min) under a 100/min per-IP ceiling. 8 tests, including one that every `api/v1` route passes through the global limiter |

## 3. Dead and redundant backend code

| ID | Sev | Finding | Status |
|---|---|---|---|
| B1 | Med | **`LoginAttemptService` (132 lines) and its lockout notification never run in production.** Zero references outside its own test (VERIFIED). The live throttle is `RateLimitLoginAttempts` with its own constants. The test passes for a feature that is not wired in. Two lockout implementations. | OWNER — wire in or delete is a product decision (lockout behaviour); recorded |
| B2 | Med | Methods alive only through tests that exercise logic production does not use: `AttendanceIntelligence::detectLate/detectEarlyLeave/calculateOvertime/detectMissingPunch`, `HolidayService::getHolidays/isHoliday`, `LeaveBalanceService::calculateBalance`, `BankExportService::generateCbeFormat`. The tests overstate coverage of the live path. | OPEN — deleting also deletes tests; needs a keep/delete call per method |
| B3 | Low | Methods with no caller anywhere: `User::hasRole/hasAnyRole/isTenantAdmin`, `Tenant::hasFeature`, `DeviceManager::supportedTypes`, `ScopesEmployeeAccess::accessibleEmployeeIds`, `LeaveTypeCatalog::knownCodes`, unused relations. `TaxCalculator::forgetCachedBrackets` uncalled. *(First draft suspected stale tax rates after a bracket edit; traced and **dismissed** — the cache is a per-instance memo array, not a shared store, and workers are short-lived on this target.)* | OPEN |
| B4 | Med | Four health implementations repeat a DB ping and cache probe (`HealthController`, `SystemHealthService`, `HealthCheckCommand`, `QueueHealth`); `SystemHealthService` hardcodes the queue list that `QueueHealth::QUEUES` already owns. `HealthCheckCommand` untested. | FIXED — queue list now `QueueHealth::QUEUES`; and (Phase 4) a real defect found while reviewing: `/health` degraded only on `unhealthy`, so a cache or storage probe that *threw* (`unavailable`) left it at 200. **Consolidation decided against**: the overlap is ~5 lines per probe and the four serve different audiences with deliberately different rules (uptime never 503s on scheduler staleness; the admin panel shows timings; the CLI exits non-zero) |
| B5 | Low | `config/backup.php` `off_host_disk` (`BACKUP_OFF_HOST_DISK`) is never read; `BackupService::copyOffHost` hard-defaults `'s3'`. | FIXED |
| B6 | Low | `EmployeeTransitioned` is `ShouldBroadcast` with no listener; 12 notifications and 5 events use a broadcast leg while production is `BROADCAST_CONNECTION=null`. Whether each still queues a no-op job is SUSPECTED. | OPEN |
| B7 | Low | `phpstan-baseline.neon`: 4,849 lines / 808 entries; ~340 `property.notFound` in `Http/Resources` because no resource said which model it wraps. | FIXED (Phase 4) — `@mixin` on 34 resources and the missing `@property` lines on 15 models (date casts, enum casts, relations). **Baseline 877 → 439 occurrences**; 5 hand-written `phpstan.neon` workarounds for the old `Employee::$status` typing retired. **Two real bugs surfaced: every payslip PDF printed a blank employee name and a blank position** (`full_name` and `position->name` do not exist; it is `name` and `title`) — fixed, with a test that fails on the old code. The API contract became more accurate as a side effect (`is_primary` boolean not string, `priority` number, nullable relations marked), 46 lines, tsc clean. `checkModelProperties` was measured and **not enabled**: 69 findings, none a missing column. `RetirementCase` given the same treatment: its 11 `phpstan.neon` workarounds retired, including two "comparison will always be false" suppressions that typing proved were not a hidden logic bug |
| B8 | Low | Near-duplicate Store/Update request rules (Employee 17/20 lines, PayrollRule 8/8, Branch 9/10, Shift 8/11) and Position/Grade controllers (~22 of ~90 lines differ). Scramble reads `rules()`, so a shared trait must stay statically analysable. | OPEN |
| B9 | Low | `DeviceController` is 598 lines with three near-identical webhook handlers on raw `Request`; adapters build `$baseUrl` from raw `$config['ip']` over plain `http://`; `GenericHttpAdapter` already has a normaliser that is not shared. | OPEN |
| B10 | Low | 23 raw `'status','active'` literals vs 16 `TenantStatus::` uses; two notification patterns (`SendsNotifications` trait vs 32 direct `->notify()`). | OPEN |
| B12 | Med | **Announcement targeting is enforced only when notifying, never when reading.** `AnnouncementController::index` applies `published()`/`notExpired()` but no `target_type` rule, so an announcement aimed at one department is listed to the whole tenant; `NotifyAnnouncementAudienceJob` does honour the target. Also `Announcement` casts `target_id` to `integer` while role targeting compares it to a role name, so a role-targeted announcement notifies nobody. No UI sets a target today — API only. Found 2026-10-01 during F2 | **FIXED 2026-10-01** — decided when the owner delegated: targeting is enforced on reads (`Announcement::scopeVisibleTo` — managers see all; others see tenant-wide plus their own department and branch), `target_id` takes a department/branch **public_id** resolved through the tenant scope (a foreign id is refused exactly like a missing one), and `role` targeting is removed because the bigint column cannot hold it. 7 tests; 6 fail on the old code — and two first passed by coincidence, because `(int) "01K…"` is `1` and so is the first row id in a fresh database |
| B11 | Low | No direct test: `AttendanceCorrection*Notification`, `MissingPunchNotification`, `PasswordResetLinkNotification`, `ProvisionTenant`, `PayslipPdfService`, `RestoreCommand`, `QueueCheckCommand`, `BackupRehearsalCommand`, `HealthCheckCommand`. Thin: SSO 1, SCIM 1, kiosk 1, disciplinary 1. | OPEN (S1 adds the reset-notification test) |

**Verified clean:** no `dd/dump/var_dump`, no TODO/FIXME, no commented-out code, no unrouted controller
methods, every `tenant_id` table has a tenant-scoped model (bar Laravel's notifications table), every
permission string exists in `PermissionSeeder`, 85 unguarded controller methods all pinned in
`routes-without-authorization.php`, 67 migrations all with `down()`. The tenant-bypass pin (159/57) holds.

## 4. Infrastructure remnants (Docker / Redis / MinIO / VPS)

The Docker stack was removed today; the *live* paths (`gates.sh`, hooks, workflows, `phpunit.xml`) carry no
container fallback. What is left is documentation-in-config and tests that pin it.

| ID | Sev | Finding | Status |
|---|---|---|---|
| I1 | **High** | `api/.env.shared-hosting.example:6-21` asserts `.env.production.example` still exists "for the rollback target" and that DEPLOYMENT step 4 says to copy it. The file was deleted on 2026-09-26 (VERIFIED). | FIXED |
| I2 | **High** | Dead env keys pinned as *mandatory* by a test: `MINIO_*`, `REDIS_CLIENT`, `HORIZON_PREFIX`, `DB_ROOT_PASSWORD` (read by nothing in `app/`/`config/`); `HostingRequirementsConsistencyTest:148-186` fails if they are removed. `REDIS_HOST/PORT/PASSWORD` only feed the inert `reverb.scaling` block. | FIXED |
| I3 | Med | `.env.example:106-115` documents `DEPLOY_NOTIFY_EMAIL`, `BACKUP_NOTIFY_EMAIL`, `BACKUP_RETENTION_DAYS` (read by nothing; the script they cite is retired) and omits the real `BACKUP_KEEP`. Same stale claim at `.env.shared-hosting.example:232-241`. | FIXED |
| I4 | Med | Inert lines: `.env.example:5` ("copy to .env.production"), `MAIL_ENCRYPTION` (mail config reads `MAIL_SCHEME`), Mailpit port 1025 left from a removed container, `APP_TIMEZONE` (config hardcodes `'UTC'`). | PARTIAL — line 5 and `MAIL_ENCRYPTION` fixed; `APP_TIMEZONE` and the port-1025 placeholder left: the key is pinned by a test and documented in ENVIRONMENT.md, so removing it is a doc-and-test change of its own |
| I5 | Med | `composer.json`: `laravel/sail` (Docker tooling) is unused; `dev` script runs `queue:listen` which drains only `default`, contradicting the four-queue guidance; `setup` builds the untouched Laravel skeleton front end. | FIXED — `laravel/sail` and `symfony/yaml` removed from manifest, lock and `vendor/`; `dev` drains all four queues; `setup` no longer builds the unused skeleton front end. The skeleton `api/package.json`/`vite.config.js`/`resources/js` remain: OPEN |
| I6 | Med | `phpunit.xml`/`phpunit.mysql.xml`/`tests/bootstrap.php` pin `PULSE_*`, `TELESCOPE_*`, `NIGHTWATCH_*` for packages that are not installed. | FIXED |
| I7 | Med | `HorizontalScalingTest` is partly tautological (sets a config value, asserts the value it set); `DeploymentWorkerConsistencyTest:7-34` is an orphaned docblock describing `supervisor.conf`; `InfrastructureAgnosticTest:9-18` claims production sets `FILESYSTEM_DISK=minio` / `CACHE_STORE=redis` (false). | FIXED |
| I8 | Low | `phpstan-divergence-probe.yml` is obsolete — the divergence was closed in BASELINE §12c (cause: `.env` presence) and the probe only re-fires on edits to itself. | FIXED (deleted; BASELINE holds the result) |
| I9 | Low | `laravel/reverb` and `league/flysystem-aws-s3-v3` are `require`. **Split decision, on evidence:** nothing in `app/ config/ routes/ database/` references a Reverb, Pusher or ReactPHP class, so Reverb moves to `require-dev` (production installs with `--no-dev`); the S3 adapter stays, because `ethr:backup --off-host` uses the `s3` disk at runtime. No `AWS_*` keys in either example, so off-host backup is still undocumented. | FIXED for Reverb — same 114 packages at the same versions, 13 move to `packages-dev` (114 → 101 in production). S3: kept by decision. Issue #120's premise (Docker needs both) lapsed on 2026-09-30 |
| I10 | Low | `verify-without-fix.yml` pins PHP 8.4 (everything else 8.2) with a default filter specific to one past fix; `gates.yml:546-553` says "no threshold" but `gates.sh` uses `--min=85`; `backend-coverage` re-runs the whole suite. | OPEN |
| I11 | Low | Stale comments: `CODEOWNERS` "~147 bypass sites" (159), `AppServiceProvider:161-166` (Redis/MinIO/nginx), `SystemHealthService:~105` (MinIO), `next.config.ts:98-100` CSP keeps `127.0.0.1:9000` "local MinIO", `api-types-check.sh` cites a nonexistent audit doc, `backup.sh:38` hints at `docker compose exec`. | FIXED |
| I12 | Low | `ScriptComposeFileReferencesTest:166-199` forces the two retired scripts `backup.sh`/`restore.sh` to stay. Deleting them is deferred in the docs to the Stage 6 restore rehearsal. | OWNER |

## 5. Frontend

| ID | Sev | Finding | Status |
|---|---|---|---|
| F1 | **High** | **E2E was in no CI workflow and not in `gates.sh`.** 13 specs, never run automatically. | FIXED (Phase 2) — `./scripts/gates.sh e2e` and `.github/workflows/e2e.yml` (advisory, not required until green on several runs). **First measured local run: 339 tests, 217 passed.** 119 of the 122 failures were `ux-audit`, of which 110 were WebSocket errors from this machine's leftover Docker Reverb container; blanking the realtime key, as CI has it, took `ux-audit` to 189/207. The gate runs the **functional suite — 125/125 passed** — and excludes `ux-audit` (trips on dev-server chunk aborts) and the offline specs (need the production service worker), both documented in `gates.sh`. Also fixed: a CI skip of the admin specs is now a failure (`E2E_REQUIRE_SUPER_ADMIN`), and two Node-side `*.localhost` lookups that fail on Windows. Not yet seen: a CI run, which needs a push. `ux-audit` left 5 intermittent detail-link misses and one dashboard `color-contrast` flag that is probably axe measuring mid-fade (unconfirmed) |
| F2 | **High** | **Parallel API layer.** 44 page files call `apiClient.*` inline while 41 hooks/download helpers in `features/*/api.ts` are referenced nowhere outside their own file/tests (`useCreateDevice`, `useCreateShift`, `useAssignShift`, `useManualAttendance`, `useLoan`, `downloadPayslipPdf`, …). `docs/CLAUDE.md:857-861` makes `features/{x}/api.ts` the convention. | **IN PROGRESS** — **devices DONE 2026-10-01** (`f21b6c1`): all three pages on `features/devices/api.ts`, typed from the contract, verified in the running app. The hooks had drifted (wrong sync-log fields, no `connection_config`, a polling probe), and the move surfaced **four real defects**: adding a mock device always 422'd and editing a device wiped its stored credentials (`96a7c7a`), editing only the IP reset the port to 80 (`3dc7493`), and the Events tab never paginated because it read `meta.last_page` from a bare paginator (`f21b6c1`). **Announcements DONE 2026-10-01** (`5fea418`), and it surfaced its own: **the dashboard card never showed an announcement** — it filtered on a `status` field the resource has never returned — plus a page gated on `employee.create` where the API checks `announcement.manage` (diverges for custom roles), a silent failed delete, and unnamed form fields (`26402bc`). Next: reports. The `["org", "branches"]` query repeats inline on six pages — the organization feature's pass |
| F3 | Med | `api/generated.ts` is 21,512 lines / ~1,364 schemas but imported by `features/employees/types.ts` and two tests; ~195 hand-written exported types duplicate it. | IN PROGRESS — `features/devices/api.ts` now derives `Device`, `DeviceSyncLog`, the dashboard/status/sync-all responses and the events page from `generated.ts`. Two contract gaps worked around and documented there: `sync_stats_24h` values are `unknown` (Scramble cannot see through `pluck`), and event rows are untyped (built inline in the controller) |
| F4 | Med | Backend routes with no frontend caller that look intended: `employees/contracts/expiring`, `employees/documents/expiring`, `employees/import/status/{}`, `attendance/import/legacy`, `admin/users/search`, four `onboarding/*` (v1 wizard orphans). | OPEN |
| F5 | Low | Static export is sound (VERIFIED: `out/` current; four `[id]` routes export `__id__`; route handlers `force-static`; `next/headers` gated). `docs/MIGRATION_STATE.md:1323` still says "Does not build — three blockers". `images.remotePatterns` is dead config (no `next/image`). `middleware.ts` is inert under export (duplicated by client redirect). | FIXED (stale line, dead `images` config, and the `127.0.0.1:9000` MinIO CSP entry) |
| F6 | Low | Unused devDependencies: `@eslint/eslintrc`, `@vitejs/plugin-react`, `@vitest/coverage-v8` (no coverage config). Orphan `components/shared/currency-input.tsx`; zero-reference exports (`getEcho`, `FormPageSkeleton`, `statusTextClass/DotClass`, `ethiopianMonthStart`); v1 onboarding leftovers. Three table layers (`DataTable` 1 user, `simple-table` 33, `ui/table` 2). | OPEN |
| F7 | Low | Hardcoded English the i18n gate cannot see (it only inspects `t()` calls). **The kiosk page did not use `useT` at all**: ~30 strings, on the one screen every employee at a site shares. | FIXED (Phase 2) — kiosk page and `SearchInput` fully translated (32 keys, en + am); earlier pass fixed MFA QR `alt`, selfie `alt`, notification-templates title. Errors are held as keys and translated at render; the backspace button gained an `aria-label`. **Behaviour change:** a fresh kiosk now renders in the app default, Amharic, where it was English-only. 5 tests; 3 fail on the old code. **The new Amharic strings want a native speaker's review** — they are the `kiosk_page.*` and `search_input.*` keys in `am.json`, plus `devices_page.keep_connection_hint` (added 2026-10-01) |
| F8 | Low | `text-white` ×11 where `DESIGN_SYSTEM.md` says `text-text-inverse`; `var(--x, #hex)` fallbacks redundant. | OPEN |

**Verified clean:** 0 `TODO/FIXME`, 0 `console.log`, 0 `@ts-ignore`, all 35 production deps imported, all 12 PWA
icons present, i18n en/am in sync (3,369 keys), no raw Tailwind palette classes.

## 6. Documentation

The link checker passes (489 links / 96 files). The problem is **prose that is stale or cites files that are
gone** — invisible to a link checker that only follows markdown links.

| ID | Sev | Finding | Status |
|---|---|---|---|
| D1 | **High** | `docs/deployment/shared-hosting/rollback.md` (19 lines, the one opened during an incident) says "Repoint ethr.et back to the VPS's IP. That's it." `ROLLBACK_RUNBOOK.md:113-121` cites deleted `scripts/rollback.sh`. The VPS no longer exists as a target (VERIFIED). | FIXED |
| D2 | **High** | `docs/PRODUCTION_CHECKLIST.md`: row 17 `BROADCAST_CONNECTION=log` (code default and conventions say `null`; `log` is called forbidden); row 22 "`scripts/backup.sh` assume Docker" (script is retired); row 2 "1673 tests"; "old B3/B4/B5/H1 taxonomy". Overlaps CUTOVER-CHECKLIST, HOSTING_VERIFICATION_CHECKLIST, deploy-checklist. | FIXED (marked historical, pointed at the live register) |
| D3 | **High** | `docs/SECURITY.md` still describes the VPS stack as current (MinIO bucket, Redis cache, Nginx headers) and says CI "has never executed" (false: run #66 green 2026-09-16). Root `SECURITY.md:34` lists deleted `docker/` and `infrastructure/` as in scope. | FIXED |
| D4 | **High** | Live docs cite files that do not exist: `docs/TENANT_PUBLIC_PAGES.md` (never tracked), `docker/nginx/default.conf`, `scripts/{pest,phpstan}-isolated.sh`, `api-reload.sh`, `run-e2e.sh`, `seed.sh`, `seed-demo.sh`, `src/scripts/docker-refresh.mjs`; `ARCHITECTURE.md`/`DESIGN_SYSTEM.md` use `src/api/...` where the real path is `src/src/api/...`. *(Correction to the reviewer: `docs/TENANT_PUBLIC_PAGES.md` is **not** dead — it exists on unmerged PR #17, so those are forward references.)* | PARTIAL — prefixes fixed in ARCHITECTURE, DESIGN_SYSTEM, SECURITY, DEPLOYMENT; supersession notes on ENTERPRISE_ROADMAP and SHARED-HOSTING-CONTRACT. Dated decision records/plans (C5, SHARED_HOSTING_PLAN, phases) and the two `seed*.sh` mentions left as written |
| D5 | Med | `AGENTS.md:46` says the C-5 frontend decision "is open. Do not choose" — it was DECIDED 2026-09-27 (static export). | FIXED |
| D6 | Med | `README.md` quotes 102 controllers / 69 models / 55 migrations / 1673 tests and says GATE-0 has "every row NOT VERIFIED" (five are VERIFIED). Measured: 105 / 70 / 67 / 187 classes, 2012 tests. | FIXED |
| D7 | Med | The "collected N of 132" figure is **21** in five files and **22** in four (`README.md:101`, `LOCAL_SETUP.md:160`, `docs/CLAUDE.md:711`). The dated measurement is 21. | FIXED |
| D8 | Med | `docs/CLAUDE.md` contradicts itself: the tech table still lists Horizon/Redis 7+/Reverb under a banner saying they are gone; the repo tree shows a nonexistent `/tests/Security`, `/src/app` without nesting, "/docs Generated API documentation"; ~230 lines of model-routing policy and a restated Quality-Gates section duplicate other files. | FIXED (table and tree); split-out is OPEN |
| D9 | Med | `VPS-DECOMMISSION.md §2` still lists `docker/*` and compose files as load-bearing "Why it stays"; only a late addendum says they were removed. `MIGRATION-DOCUMENT-INVENTORY.md` repeats it. | FIXED (banner on both) |
| D10 | Med | `docs/DEPLOYMENT.md` (802 lines), `ARCHITECTURE.md` (1,395), `PRD`, `PRODUCT_VISION`, `DATABASE`, `BACKUP-RESTORE` first half narrate the VPS/Docker/MinIO stack as current; the DEPLOYMENT banner omits today's removal. | PARTIAL — DEPLOYMENT, ARCHITECTURE, ENTERPRISE_ROADMAP bannered; PRD, PRODUCT_VISION, DATABASE, BACKUP-RESTORE untouched; extraction OPEN |
| D11 | Med | `docs/README.md` omits `PANEL-SESSION-RUNBOOK`, the C-5 decision, `BACKUP-RESTORE`, `G0-F-CREATE-TRIGGER`, `M6-RESTORE-REHEARSAL`, `SHARED_HOSTING_PLAN`, and five `shared-hosting/*.md`. | FIXED |
| D12 | Low | Migration cluster is over-duplicated (MIGRATION_STATE 200 KB, MIGRATION_CHANGELOG, SHARED_HOSTING_AUDIT/MIGRATION_PLAN/PLAN, DATABASE_MIGRATION_PLAN, TCO_COMPARISON, PHASE_A_CHANGE_REVIEW, B1-B5_GATE_REPORT). Canonical set: SHARED-HOSTING-CONTRACT, GATE-0-RESULT, shared-hosting/DEPLOYMENT, CUTOVER-CHECKLIST, AUTHORITATIVE-BRONZE-MIGRATION-PLAN. | OPEN — archival move is a large rename churn |
| D13 | Low | Root `SECURITY.md` (reporting policy), `docs/SECURITY.md` (posture), `docs/security-audit.md` (OWASP evidence) are distinct, not duplicates; `AGENTS.md` ×2 are pointers. No merge needed; add cross-links. | FIXED (cross-link) |
| D14 | Gap | No operator on-call runbook ("cron caller down / disk quota full / DB unreachable"), no Artisan command reference, no doc for webhooks / impersonation / the OpenAPI contract workflow, no single current production-env reference that excludes the VPS. | PARTIAL — `docs/operations/COMMANDS-AND-SCHEDULE.md` added (every command, the full 14-entry schedule, first checks). **`docs/operations/ON-CALL.md` added (Phase 4)**: symptom → cause → action for the no-shell host, built from the repository's records with unmeasured host facts flagged. Still missing: webhook / impersonation / OpenAPI docs |
| D15 | Low | `QUEUE-MONITORING.md:199-218` says `SystemHealthService.php:66` hardcodes `disk('minio')`; file lives at `Services/Admin/` and the claim is unchecked (SUSPECTED). | OPEN |

## 7. Commands left for the owner

The branch deletion below has since been run (§9). The dependabot rebase was also requested.

```bash
# G2 — delete the 41 remote branches whose PRs are merged (each is restorable from its PR page)
gh pr list --state merged --limit 200 --json headRefName --jq '.[].headRefName' \
  | grep -v -E '^(main|migration/bronze-plesk)$' | sort -u \
  | xargs -I{} git push origin --delete {}

# G4 — rebase the pre-repair dependabot PRs
for n in 8 10 12 13 9; do gh pr comment $n --body '@dependabot rebase'; done
```

G1 (merge #133, or cherry-pick only `cron.yml` to `main`) is the one item in this file that blocks go-live.

---

## 8. Outcome of this pass

> **This section is the 2026-09-30 snapshot, taken before anything was pushed.** It is kept as
> measured. The status columns in §1–§6 are the live record; where this section and those tables
> disagree, the tables win. Since this was written, everything was committed and pushed (head
> `d8a384c` on 2026-10-01, CI green), and Phases 2–4 closed several items the table below still
> called open — the *Now* column says which.

**Verified on the final tree with `./scripts/gates.sh` (full sweep): all 12 gates passed** — Composer, Pint,
PHPStan level 6 (zero errors), Pest **2027 passed / 6621 assertions** with the collection guard at
**189/189 classes**, i18n (3,371 keys, en/am in sync), Prettier, ESLint, tsc, Vitest **91 files passed**,
docs links, the Bronze static-export build, and the API-contract gate. Baseline before any change was
2015 tests / 187 classes / 89 Vitest files; 2015 + 15 new − 3 removed tautologies = 2027.

**Nothing was committed or pushed** at the time of this pass. All changes were in the working tree of
`migration/bronze-plesk` for review; nothing outward-facing (remote branches, PRs, workflows on GitHub)
was touched. *(Superseded the same day: the work was pushed to PR #133 and the remote actions in §9
were taken.)*

### Fixed

| Area | Change |
|---|---|
| Security | S1 reset/activation links; S2 SAML verifier rewritten; S3 payslip print escaping; S4 phantom production WebSocket |
| Backend | B4 queue list; B5 `--disk` now honours `backup.off_host_disk`; one stale PHPStan baseline entry removed (baseline only shrank) |
| Config | dead `MINIO_*`/`REDIS_*`/`HORIZON_PREFIX`/`DB_ROOT_PASSWORD`/notify keys removed from both env templates and from the test that pinned them; `FRONTEND_URL` added; `PULSE/TELESCOPE/NIGHTWATCH` env removed; `laravel/sail` + `symfony/yaml` removed (lock and vendor); composer `dev`/`setup` scripts corrected |
| Tests | three tautological tests and an orphaned docblock removed; stale narratives corrected; **+4 test files** (`MailLinkFrontendUrlTest`, `SsoSamlSignatureTest`, `echo-opt-in.test.ts`, `escape-html.test.ts`) — 15 backend + 6 frontend tests |
| CI | `phpstan-divergence-probe.yml` deleted (result preserved in BASELINE §12c) |
| Frontend | realtime opt-in; `escapeHtml`; 3 hardcoded strings translated; dead `images` config and MinIO CSP entry removed |
| Docs | rollback page rewritten for a world with no VPS; 7 documents bannered as historical/superseded; SECURITY/PERMISSIONS/README/AGENTS/docs/CLAUDE corrected against code; docs index completed; `COMMANDS-AND-SCHEDULE.md` added; `21 of 132` made consistent |

### Still open — and why

| Item | Why it was not done here | Now (2026-10-01) |
|---|---|---|
| **G1 scheduler not on `main`** | Merging PR #133 is the fix, and it is a draft marked do-not-merge. **This is the one item that blocks go-live** | Unchanged. Merge is line 1 of the cutover procedure (§9) |
| G2–G4 remote branches, stale PRs, dependabot rebases | Outward-facing; commands in §7 | G2 done (40 deleted). G3: #21 resolved via #135; #17 and #18 the owner's. G4: rebases requested |
| F1 E2E in no CI workflow | Needs a served stack and browsers in CI; the largest remaining test gap | **FIXED** (Phase 2) — `gates.sh e2e` + advisory `e2e.yml`, 125/125 in CI |
| F2 parallel API layer (44 pages vs 41 unused hooks), F3 generated types barely used | Per-feature design decisions; too large and too risky to batch | In progress — devices and announcements done 2026-10-01; reports next |
| B1 `LoginAttemptService` inert, B2 test-only methods | Whether to wire in or delete is a product decision (lockout behaviour) | Open. B1 deliberately held until after cutover (§9) |
| S5 device `connection_config` validation / SSRF, S6 SCIM suspended-tenant | Needs per-adapter rules and a traced exploit path; not safe to guess | **Both FIXED** — S6 in this pass, S5 in Phase 2 (see §2) |
| I9 reverb/S3 packages, I12 retired scripts | Owner decisions (issue #120 is contradicted by the code) | I9: Reverb moved to `require-dev`, S3 kept by decision. I12: kept until the Stage 6 restore rehearsal |
| Kiosk page wholly untranslated; `SearchInput` default string | Needs a full string pass with reviewed Amharic | **FIXED** (Phase 2, F7). The Amharic still wants a native speaker's review |

### What I got wrong on the first pass, for the record

- **D4:** a reviewer called `docs/TENANT_PUBLIC_PAGES.md` a dead citation. It exists on unmerged PR #17.
- **B3:** a suspected stale-tax-rate bug did not survive tracing (per-instance memo, short-lived workers).
- **F7:** I listed hardcoded attributes; the real finding is an entire untranslated page.
- **Line endings:** my first scripted edits wrote CRLF into four LF files and Pint caught it — the gate did its job.
- **Import order / FQNs / DOM types** in code I wrote failed Pint and PHPStan on the first run and were fixed.

---

## 9. Decisions taken when asked to decide (2026-09-30, after the push)

| Item | Decision | Reasoning |
|---|---|---|
| **G1 scheduler** | **Do not merge PR #133 yet; merge it at cutover.** | The owner's "draft, do not merge" hold stands, and `CUTOVER READY = NO`. `cron.yml` on `main` would hit an endpoint of a host that is not yet serving the app every five minutes and fail every time. The queue has no work to drain until the host is live. The risk is real only if cutover happens without the merge, so **merging is now line 1 of the cutover procedure** |
| **G2 stale branches** | **Done, after the owner asked for it explicitly.** The first attempt was refused by the auto-mode classifier and was not retried another way; the owner then instructed the deletion | 40 of 41 deleted, each verified first: branch tip identical to its merged PR's head. `claude/announcement-show-visibility` has commits beyond its merged PR and was kept. Remote branches: 61 → 20 (`main`, this branch, five `claude/*`, thirteen `dependabot/*`). A first run deleted nothing because the list file had CRLF endings; the second run was sound |
| **G3 PRs #17, #21** | Leave open | They carry unmerged work and conflict with `main`; closing discards it. The owner should rebase or close them deliberately |
| **G4 dependabot** | `@dependabot rebase` requested on #8, #9, #10, #12, #13 | Benign and reversible; they predate the CI repair. #134 has 3 real failures and was left for review |
| **B1 lockout service** | **Keep as is; do not touch the login path before cutover** | Dead, but changing authentication behaviour days before go-live costs more than carrying 132 unused lines. Revisit after cutover: delete, or wire in behind a test |
| **B2/B3 test-only methods** | Leave | Deleting them deletes their tests, and nobody has said which behaviour is intended |
| **I12 retired scripts** | Keep `backup.sh` / `restore.sh` until the Stage 6 host restore rehearsal | The docs already tie their removal to that rehearsal |
| **F1 E2E in CI** | Defer *(reversed in Phase 2 — FIXED, see §5)* | Needs a served stack and browsers in CI; a half-configured job that is always red is worse than none. Phase 2 answered this by making the job advisory until it has been green on several runs |
| **S5 device SSRF** | Still open *(closed in Phase 2 — FIXED, see §2)* | Needs per-adapter rules and a traced path; guessing risks breaking device sync. Phase 2 did the tracing and wrote the per-adapter rules |
| **Dependabot PR #9** (Scramble 0.13.36 → 0.13.45) | **Closed, with evidence** | Regenerating to pass the contract gate would have written a *worse* contract: paginated `data` typed as `string`, paginator `links` collapsed to `unknown`, nullable page URLs losing `| null`. Its advisory failure (`brace-expansion` via `@sentry`) is on `main`'s lockfile and already fixed on this branch (`b8ca0fb`) |
| **Phase 3 (cutover)** | **Not attempted — host gates marked known gaps, 2026-10-01** | `CUTOVER READY = NO`: 4 of 14 mandatory gates PASS and all 10 outstanding need the Ethio Telecom account. Recorded as owner-deferred known gaps in `CUTOVER-CHECKLIST.md`, with no state changed. Repository side verified ready: local production rehearsal on current code, 36/36. Not verified: whether the M2 probe is still live (the check was refused by agent tooling) |
