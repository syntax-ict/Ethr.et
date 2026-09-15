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

- `docs/audit/BASELINE.md` — Phase 0 forensic baseline. Every claim typed
  `[verified]` / `[documented-done]` / `NOT VERIFIED` / `NOT MEASURED`.
- `SECURITY.md`, `CONTRIBUTING.md`, `LICENSE`, `.editorconfig`, this file.

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
- No CI exists; four documents state that it does.
