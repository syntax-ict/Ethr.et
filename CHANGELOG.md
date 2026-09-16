# Changelog

Notable changes to ETHR. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning is not yet applied — ETHR has not had a production release.

This file starts at the professionalization and Plesk-migration work of
September 2026. Earlier history is in `git log` and in `docs/phases/`, which
`README.md` marks as unmaintained. `docs/MIGRATION_CHANGELOG.md` covers the
shared-hosting migration in more detail than belongs here.

## [Unreleased]

### Security

- **Cleared both critical advisories in production frontend dependencies.**
  `next` 16.2.12 → 16.3.5 (GHSA-p293-qw3h-jr36, GHSA-2xp9-vwfh-vxw4 —
  unauthenticated RCE) and the `sharp` override ^0.35.3 → ^0.35.4
  (GHSA-rgj7-g3m4-5g8c). Production advisories 5 → 3, criticals 2 → 0. Verified
  against a baseline captured before the upgrade: Vitest 70 files / 435 tests
  identical either side, `next build` exit 0, all frontend gates green.
- **Fixed a cross-tenant lookup in employee import.**
  `EmployeeImporter::commit()` de-duplicated on `import_key` through
  `withoutGlobalScopes()` with no tenant predicate, so it read every tenant's
  rows. Both halves of the key are caller-supplied and unconstrained, so a
  colliding key silently skipped another tenant's row and reported it as
  `skipped` — a cross-tenant existence oracle and silent data loss in one. The
  sibling read path (`EmployeeImportController::status()`) was always scoped;
  only the write path was not. Covered by
  `tests/Feature/Security/TenantImportIsolationTest.php`.
- **Removed a committed `APP_KEY`.** `START_BACKEND.ps1` hardcoded a literal
  key, which signs every signed URL and decrypts every `encrypted` cast. It was
  a local-development convenience, but nothing said so and any developer without
  `APP_KEY` set adopted it silently. The script now generates one.
- Documented the env policy at the repository root: `api/.env.production` is
  ignored (Laravel's own `api/.gitignore` already covered it; the root rule now
  states it too), and `src/.env.production` is labelled as the deliberate
  exception — `NEXT_PUBLIC_*` only, public by definition.

### Added

- **Quality-gate automation.** `.githooks/pre-push` runs `gates.sh quick` and
  blocks a push that fails it, or that adds a `.env` file or `APP_KEY` literal.
  `.github/workflows/` calls the same `gates.sh` rather than restating the gate
  list, so the two cannot drift. **The workflows have never executed** — nothing
  has been pushed — so they are untested configuration, not a control.
- **New gate scopes:** `quick` (no test suites, for the hook), `docs` (markdown
  link integrity), `security` (composer + npm audit). `composer validate` added
  to `backend`. `security` sits outside the full sweep like `performance`: it
  goes red when a third party publishes an advisory, and a permanently red gate
  stops being read.
- **`php artisan ethr:backup:rehearse`** — destructively verifies the backup
  path against whatever database it is pointed at: back up, drop every table,
  restore, then check tables, rows and triggers all came back and that
  `audit_log` still accepts an INSERT and refuses an UPDATE. Master plan §30's
  standard is a restore *performed*, and a test that only ever runs on SQLite
  does not meet it for a MySQL host. Two guards, because it drops everything:
  not in `production`, and the database name must look disposable.
- **`api/phpunit.mysql.xml` and a `mysql` gate, run by CI on every push.** The
  same suite against MariaDB rather than SQLite `:memory:`, for the divergences
  a SQLite run structurally cannot see — the first time anyone ran it, it found
  a search defect that returned nothing in production and passed every test.
  `./scripts/gates.sh mysql` **fails rather than skips** when no server is
  reachable, because a skipped gate reports success. It stays out of the full
  sweep so a fresh clone can still run `gates.sh` with no services; the
  `backend-mysql` workflow job calls it by name against a `mariadb:10.11`
  service container, **as a non-root user** — which also exercises whether the
  `audit_log` trigger migration survives without `SUPER`. See
  `docs/decisions/DECISIONS.md` D-011.
- `docs/deployment/shared-hosting/nginx-directives.conf` — the prepared answer
  if Gate 0's G0-B finds `.htaccess` is not honoured. Covers the silently
  failing half (headers, deny rules, timeouts) and deliberately leaves routing
  alone until the gate says which way nginx is configured.
- `scripts/docs-link-check.js`, `.github/dependabot.yml`, `CODEOWNERS`,
  a pull-request template.
- `docs/README.md` — an index for 37 documents that had none.
- `docs/decisions/DECISIONS.md` — six decisions, each with what would reverse it.
- `docs/operations/QUEUE-MONITORING.md` — detecting silent queue death on a host
  with no supervisor.
- `docs/deployment/GATE-0-RESULT.md` and a web-server canary under
  `scripts/hosting-verification/`, for verifying the Plesk target.
- `docs/audit/BASELINE.md` — Phase 0 forensic baseline. Every claim typed
  `[verified]` / `[documented-done]` / `NOT VERIFIED` / `NOT MEASURED`.
- `SECURITY.md`, `CONTRIBUTING.md`, `LICENSE`, `.editorconfig`, this file.

### Fixed

- **Searching an employee by their full employee code returned nothing in
  production.** `Employee::scopeSearch()` had two implementations — `MATCH …
  AGAINST` on MySQL, `LIKE` on SQLite — and since the suite runs on SQLite, the
  branch that runs in production had never been executed by a test. It stripped
  boolean operators from the term, `-` among them, so `EMP-1234` became
  `EMP1234`; FULLTEXT had indexed that value as the tokens `EMP` and `1234`, and
  no token is `EMP1234`. Searching `1234` worked, searching `EMP-1234` found
  nobody. Now one `LIKE` implementation on every driver, so the tested path is
  the production path. Measured cost at 5,000 employees in one tenant: ~13ms
  against a 100ms budget. See `docs/decisions/DECISIONS.md` D-012 and
  `docs/audit/BASELINE.md` §13f.

- **CI had never passed — 50 runs, zero gates executed.** Read the Actions tab
  for the first time on 2026-09-16. Two structural causes: every `scripts/*.sh`
  was committed mode `100644`, so `./scripts/gates.sh` exited **126**
  (Permission denied) on Linux runners — Git on Windows does not track the
  executable bit unless `core.filemode` is set; and `composer install` itself
  died in `package:discover`, because `config/broadcasting.php` defaults to
  `reverb` with no `.env` present and `routes/channels.php` builds the
  broadcaster at load time, handing Pusher a null key. Fixed with
  `git update-index --chmod=+x` and a workflow-level `BROADCAST_CONNECTION:
  "null"`. The same ordering trap applies to deployment — `composer install`
  needs `.env` to exist first — now noted in `shared-hosting/DEPLOYMENT.md`.

- **A third structural cause: `phpstan_gate` required Docker.** It delegated
  unconditionally to `scripts/phpstan-isolated.sh`, which exits 1 when there is
  no `et-api-1` container. A CI runner has native PHP and no container, so
  PHPStan could never have passed there whatever the code said — and a developer
  with Docker stopped hit the same wall on a machine where PHPStan runs fine.
  Now native-first with the container as fallback, the shape `pest_gate` already
  had. The isolation is for one specific defect — Larastan enumerating
  migrations over a Windows bind mount — which a native run on a local disk does
  not have.

  This also corrects the entry below: the backend CI failure was attributed to
  the stale `phpstan-baseline.neon` entry, but that was inferred from a local
  native run rather than read from the CI log. PHPStan never ran in CI, so the
  baseline error cannot have been what CI reported. Both defects were real; the
  attribution was not.

- **A test failed every Wednesday.** `NotificationDispatchTest`'s leave-request
  case asked for `now()->addDays(10)` to `addDays(11)`. That range is
  Saturday–Sunday exactly when today is a Wednesday, and the endpoint rejects a
  request containing no working day — `"The selected dates do not include any
  working days."` One day in seven, on both drivers, since the day it was
  written; found on 2026-09-16, which was one. Now pinned to a Monday and the
  Tuesday after it, verified across all seven weekdays with `Carbon::setTestNow`.

- **A test passed an Employee id where a User id was required, and SQLite hid
  it.** `WriteEndpointSmokeTest` built a `SavedReport` with `created_by =>
  $employee->id`; that column is a foreign key to `users`. It was green because
  under SQLite `:memory:` with per-test rollback both tables sit at the same low
  auto-increment value, so the employee id was coincidentally a valid user id.
  MySQL does not roll back `AUTO_INCREMENT`, the counters diverge, and the
  constraint fails.

- **Five tests encoded SQLite-only behaviour and failed on MySQL.**
  `DemoTenantSeederTest` called `strftime()`, an SQLite built-in that does not
  exist in MySQL; `TenantIsolationTest` hardcoded `tenant_id => 1`, which only
  resolves because SQLite's rowid effectively restarts after a rollback while
  MySQL's `AUTO_INCREMENT` does not; and `InfrastructureAgnosticTest`'s
  read-replica probe used `mariadb` as its "connection we are not using" decoy,
  which is the connection actually in use under `phpunit.mysql.xml` — so its two
  `config()` calls collided and the test asserted the opposite of its intent.
  `BackupRestoreRehearsalTest` drops every table by design; on MySQL that DDL
  implicitly commits and cannot be rolled back, so it left the database
  destroyed for every test scheduled after it. It now skips on non-SQLite
  drivers, with `ethr:backup:rehearse` covering MySQL.

- **The test suite could not be run against MySQL in any practical time.**
  `PermissionSeeder` runs in `beforeEach` for the whole suite and called
  `DB::table('role_permissions')->truncate()`. `TRUNCATE` is DDL on MySQL and
  implicitly commits, which ended the transaction `RefreshDatabase` had opened;
  Laravel detects the missing transaction at teardown and runs a full
  `migrate:fresh` before the next test. Measured on MariaDB 10.4.32: ~20s per
  test, putting the 1720-test suite at roughly nine hours. SQLite has no
  `TRUNCATE` — Laravel compiles it to `DELETE FROM` — so the driver the suite
  ran on was the one where this could not appear. Now `delete()`, which differs
  only in leaving an auto-increment counter nothing reads.

- **A backup could be unrestorable if any stored text contained a semicolon
  immediately followed by a newline.** The dump's one-statement-per-line layout
  is what `BackupService::executeSqlFile()` uses to find statement boundaries,
  and `PDO::quote()` does not close this uniformly: MySQL escapes the newline,
  SQLite leaves it literal. So production was safe and every backup test in the
  repository — all SQLite — was validating the weaker path. Measured: the
  restore throws `unrecognized token` partway through, after tables have already
  been dropped and recreated. `DatabaseDumper` now emits `char(10)`
  concatenation so no literal can carry a raw newline on either driver.

- **Monthly invoicing had no idempotency guard.** `generateMonthlyInvoice()`
  created an invoice unconditionally, so any second execution of
  `GenerateMonthlyInvoicesJob` billed every active tenant again — reachable both
  through the queue (the job declared no timeout against a 90s `retry_after`) and
  through the job's own failure advice to "re-run manually if needed". Found
  while writing the billing tests the baseline had flagged as missing.
- **Payroll ran synchronously in the HTTP request.** `PayrollEngine` chunks over
  every active employee computing tax, pension, overtime, loans, allowances and
  cost-sharing per row, with no job wrapper and no `set_time_limit`. The
  project's own budget (`docs/CLAUDE.md:858`, "500 employees < 30s") is measured
  on dedicated hardware and already sits at or past a typical shared host's
  `max_execution_time`; the failure mode was a 504 partway through, leaving the
  run at `processing` with no way to tell what had been written. The endpoint now
  returns **202** with the run and queues `ProcessPayrollJob`; the UI polls while
  a run is in flight. Idempotency and the `calculation_log` trace are unchanged.
- **Added a backup and restore path that works without Docker.** `ethr:backup`
  and `ethr:restore`, plain PHP CLI, no `mysqldump`. The dumper emits
  `CREATE TRIGGER` without a `DEFINER`, which also fixes the restore hazard that
  would have made every `audit_log` write fail after a restore under a different
  database user.
- **`retry_after` was below five job timeouts.** At the old default of 90s
  against timeouts of 300–900s, the database queue driver re-reserved jobs that
  were still running and executed them a second time — duplicate leave accrual,
  duplicate year-end carry-forward, duplicate tenant backups. Nothing errors
  when this happens; both runs succeed and the data is wrong afterwards. Raised
  to 1200s, and five jobs that declared no timeout at all now declare one.
  `QueueRetryAfterInvariantTest` asserts the invariant by reflection, so a new
  long-running job fails the suite instead.
- **Removed `laravel/horizon`.** It declared `ext-pcntl` and `ext-posix` as hard
  requirements, so `composer install --no-dev` failed outright on any host
  lacking them. Nothing depended on it — no `HorizonServiceProvider` existed in
  the app, so `/horizon` was unreachable anyway. The lockfile now carries zero
  hard requires of either extension.
- **`BROADCAST_CONNECTION=null` threw on the first broadcast.** A `null`
  connection is defined, so that value reads as the obvious way to disable
  broadcasting, but `env()` parses the string into PHP null and returns it
  instead of the default.
- **The admin health panel reported three things that were not true**: a
  hardcoded MinIO disk (permanently red on any other backend), three queue names
  nothing dispatches to, and a Reverb row pointing at Horizon.

### Fixed (documentation)

- **Repaired 33 broken links** found by the new checker, none of them visible to
  a reader: ten phase documents linked to `ENTERPRISE_ROADMAP.md` as a sibling
  when it lives one directory up, and twenty cited audit files that are not in
  the tree and never were. All sat in one header whose only job was routing
  readers to current status.
- **Stopped four documents asserting a CI pipeline that did not exist.** A
  documented control nobody runs is worse than an admitted gap.
- Finished the `deployment/` → `docs/deployment/` move: 15 stale path
  references, five of them inside the moved files.
- Retired `docs/AGENTS.md`, a fork of `docs/CLAUDE.md` that had drifted 288
  lines and still forbade Git.
- Replaced six contradictory test counts with one measured figure and the
  command that produces it.

### Fixed

- Nothing else. Phase 0 changed no code; Phase 0.5 is limited to the security
  and hygiene items above.

### Known issues

Carried from `docs/audit/BASELINE.md` §15, unfixed and ranked:

- No backup or restore path exists for any non-Docker host
  (`scripts/backup.sh:53,63`).
- Payroll runs synchronously in the HTTP request and will time out on a host
  with a request limit (`PayrollController.php:38`).
- `DB_QUEUE_RETRY_AFTER` defaults to 90s against job timeouts of 300-900s, so
  five jobs execute twice under the database queue driver
  (`config/queue.php:43`).
- The `audit_log` triggers carry an implicit `DEFINER`, which breaks every
  audit write after a restore under a different database user.
- `laravel/horizon` hard-requires `ext-pcntl` and `ext-posix`, so
  `composer install --no-dev` fails on most shared hosting.
- `fast-uri` (high, SSRF) and `browserslist` (high) remain in production
  dependencies. Both transitive, neither with a direct override path.
- The `audit_log` triggers carry an implicit `DEFINER`; a Plesk-assisted restore
  under a different database user breaks every audit write.
