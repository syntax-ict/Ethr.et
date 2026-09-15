# Changelog

Notable changes to ETHR. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning is not yet applied — ETHR has not had a production release.

This file starts at the professionalization and Plesk-migration work of
September 2026. Earlier history is in `git log` and in `docs/phases/`, which
`README.md` marks as unmaintained. `docs/MIGRATION_CHANGELOG.md` covers the
shared-hosting migration in more detail than belongs here.

## [Unreleased]

### Security

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
- **Two critical RCE advisories against `next` 16.2.12**, a production
  dependency (GHSA-p293-qw3h-jr36, GHSA-2xp9-vwfh-vxw4). The fix is in-range —
  16.3.3 fixes it, 16.3.5 is current, and `package.json` already declares
  `^16.2.9`. Not applied here: upgrading the frontend framework needs its own
  slice with the suite run against it. See `docs/audit/BASELINE.md` §15a.
- CI is configured but has never run, because nothing has been pushed.
