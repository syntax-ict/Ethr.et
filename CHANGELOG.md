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
