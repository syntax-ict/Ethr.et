# Phase A — Change Review

**Date:** 2026-08-29
**Scope:** every file modified or added during Phase A (`migration/config`).
**Status:** **IMPLEMENTED, NOT COMMITTED, NOT APPROVED FOR PRODUCTION.**
Working tree only — `git status` shows 6 modified, 1 added (plus documentation).

Phase A was deliberately scoped to changes that are correct **regardless of how gates
B1–B5 resolve**. Six of the seven items below meet that bar. **One does not** and is
recommended for reversal.

---

## Summary table

| File | Change | Reason | Production impact | Reversible? | Approved? |
| --- | --- | --- | --- | --- | --- |
| `app/Services/FileStorageService.php` | `$this->disk = 'minio'` → `(string) config('filesystems.default')` | `FILESYSTEM_DISK` was inert for every upload, download, thumbnail and delete | **None on the current VPS** — production sets `FILESYSTEM_DISK=minio`, so the resolved value is identical to the old literal | Yes — one line | ✅ **Recommend approve** |
| `app/Http/Controllers/Api/V1/HealthController.php` (cache) | `cache()->store('redis')` → `cache()->store()` | Probe asked "is Redis up?" not "is the configured cache working?" | **None on VPS** — `CACHE_STORE=redis`, so it resolves to the same store | Yes | ✅ **Recommend approve** |
| `app/Http/Controllers/Api/V1/HealthController.php` (storage) | `Storage::disk('minio')` → `Storage::disk()` | Same defect class | **None on VPS** — `FILESYSTEM_DISK=minio` | Yes | ✅ **Recommend approve** |
| `app/Http/Controllers/Api/V1/HealthController.php` (replica) | `config('database.connections.mariadb.read')` → the connection actually in use | Inspected a connection the app may never open; could not distinguish "no replica" from "wrong place" | **None on VPS** — `DB_CONNECTION=mariadb`, so `getName()` returns `mariadb` | Yes | ✅ **Recommend approve** |
| `app/Console/Commands/HealthCheckCommand.php` | `cache()->store('redis')` → `cache()->store()` | Same defect class | **None on VPS** | Yes | ✅ **Recommend approve** |
| `phpunit.xml` + `tests/bootstrap.php` | Pin `FILESYSTEM_DISK=minio` in the test environment | Keeps 18 existing `Storage::fake('minio')` sites working once the service resolves config | **None** — test-only files, not deployed | Yes | ✅ **Recommend approve** |
| `tests/Feature/InfrastructureAgnosticTest.php` | **New**, 5 tests | Rule 7 — every modification must be testable | **None** — test-only | Yes (delete) | ✅ **Recommend approve** |
| `database/migrations/2026_07_22_000001_…` — **`up()`** | `CREATE TRIGGER` failure: fatal → log-and-continue | Anticipated shared-hosting privilege denial (error 1419) | **Changes a security control.** A deployment that cannot make `audit_log` immutable now proceeds instead of stopping | Yes | 🔴 **RECOMMEND REVERT** — see below |
| `database/migrations/2026_07_22_000001_…` — **`down()`** | `DROP TRIGGER` failure: fatal → logged | `IF EXISTS` suppresses "no such trigger", not "no such privilege"; an unguarded rollback strands the migration | **None** — rollback-path only, protects nothing | Yes | ✅ **Recommend approve** |

---

## Why six of these are safe

All five production-code changes share one property that makes them low-risk: **on the
current VPS they are behavioural no-ops.** Production sets `FILESYSTEM_DISK=minio`,
`CACHE_STORE=redis` and `DB_CONNECTION=mariadb`, so every literal that was removed
resolves to the identical value it had before. The change is that the value now comes
from configuration rather than from source, which is what makes any future environment
switch possible at all.

That is also why they are *not* gate-dependent: they improve the VPS deployment whether
or not the migration to shared hosting ever happens.

### Verification performed

| Check | Result |
| --- | --- |
| Backend suite, **baseline before any edit** | **1647 passed**, 4896 assertions, 0 failed, 325.31s, exit 0 |
| Backend suite, after Phase A | *see `docs/MIGRATION_STATE.md` — running at time of writing* |
| `php -l` on all 7 changed files | Pass |
| Laravel Pint (`--test`) on all 7 changed files | Pass |
| PHPStan level 6 | **Not yet run.** `phpstan.neon` analyses `app/` only, so the migration and the new test are outside its paths; the three `app/` files are in scope and must be checked before commit |
| Frontend suites / production build | Not run — **Phase A touches no frontend file** |

Note on the suite's scope: it was run via `scripts/pest-isolated.sh`, not `pest`
directly. Running `pest` inside the container collects a fraction of the suite over the
Windows bind mount and still exits 0 — a green run from the wrong command would have
been meaningless.

### One correction to an earlier claim

An earlier summary described these as "four sites in three files". Implementation found a
**fifth**: `HealthController::checkReadReplica()` hardcoded the `mariadb` connection
name. Same defect class, same file, same method group — it is included above.

---

## The one item recommended for reversal

`up()` in the audit-log trigger migration. Full analysis in
**`docs/AUDIT_LOG_INTEGRITY_DECISION.md`**; classification **SECURITY DOWNGRADE**.

Three findings drove the reversal:

1. **The trigger is load-bearing.** `AuditLog`'s `update()`/`delete()` overrides are
   plain instance methods — no model event hooks, no custom Eloquent builder. Every
   mass-operation path (`DB::table('audit_log')->update()`, `AuditLog::where(...)
   ->delete()`, raw SQL) bypasses them entirely.
2. **The repository already knows this.** `tests/Feature/AuditLogImmutabilityTest.php`
   asserts `QueryException` from exactly those raw builder paths. Those tests pass only
   because the trigger exists. Letting a deployment proceed without it produces a
   production state the project's own tests contradict — while CI stays green, because
   CI runs SQLite, where the trigger *is* created.
3. **The problem is unverified.** Denial of `CREATE TRIGGER` on the Ethio Telecom
   account is inferred from general shared-hosting behaviour, not observed. The change
   trades away a real control against a hypothetical constraint.

The `down()` half of the same file should be **kept**: it is not a security control, it
only stops a rollback from throwing on a trigger that was never created.

**No reversal has been made** — the freeze prohibits further code changes. The change sits
uncommitted in the working tree awaiting a decision.

---

## Reversibility

Nothing in Phase A is committed. Complete reversal is:

```bash
git checkout -- api/ && rm api/tests/Feature/InfrastructureAgnosticTest.php
```

No schema was changed, no API contract altered, no data migrated, no DNS touched, and
the VPS deployment is untouched.
