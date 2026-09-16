# Contributing to ETHR

Most of what follows is not style advice. It is the set of traps this
environment actually sprang on people, written down so the next person does not
rediscover them.

The conventions themselves live in [`docs/CLAUDE.md`](docs/CLAUDE.md) — the 15
Non-Negotiable Conventions, the design system, the API rules. Read that first;
this file is about working the repository.

## The layout is inverted from what it looks like

- **`api/`** is the entire Laravel backend, not a thin API layer over something
  else.
- **`src/`** is the Next.js *project root*. The actual frontend source is nested
  one level down, at **`src/src/`**.

## Running it locally

Four processes, not two. The README is emphatic about this and it is right:
without the queue worker no job ever runs, and without Reverb a broadcast throws
so a *successful* write can still return 500 under `QUEUE_CONNECTION=sync`.

```bash
docker compose up -d
```

`RUN_ALL.ps1`, `START_BACKEND.ps1` and `START_FRONTEND.ps1` are older
Windows-only launchers that predate the Docker setup. `RUN_ALL.ps1` in
particular prints "SQLite" while the documented stack is MariaDB on port 3307,
and it starts neither the worker nor Reverb. Prefer Docker Compose unless you
know why you want otherwise.

## Quality gates

One entry point, for people and for CI alike:

```bash
./scripts/gates.sh            # everything
./scripts/gates.sh backend    # Pint, PHPStan, Pest
./scripts/gates.sh frontend   # i18n, Prettier, ESLint, tsc, Vitest
```

Two more scopes exist:

```bash
./scripts/gates.sh quick      # everything except the test suites — for the hook
./scripts/gates.sh docs       # markdown links resolve
./scripts/gates.sh security   # composer audit + npm audit (production deps)
```

### Enable the pre-push hook

```bash
git config core.hooksPath .githooks
```

Once per clone. It runs `gates.sh quick` and refuses the push if anything fails,
and it checks the diff for `.env` files and `APP_KEY` literals — both mistakes
this repository has actually made.

It runs `quick` rather than the full sweep on purpose: the backend suite takes
about ten minutes, and a hook that costs ten minutes is a hook people learn to
pass `--no-verify` to. `ETHR_PREPUSH_SCOPE=all git push` if you want everything.

### On CI

`.github/workflows/` calls `gates.sh` rather than restating the gate list, so
there is one definition of "does this pass". **It has never run** — nothing has
been pushed to the remote yet — so treat it as configuration that has not been
tested. The hook is the part that works today.

### Do not run `vendor/bin/pest` directly over a Docker bind mount

This is the trap worth knowing. PHP's recursive directory scan returns
incomplete results over a Docker Desktop Windows bind mount. Measured
2026-08-21: `pest` collected 21 of 132 test classes, ran them, and **exited 0
with a green summary** — so the suite reported success while proving almost
nothing, and did so for weeks.

`scripts/pest-isolated.sh` copies `api/` off the mount first and carries a
collection guard that fails loudly on an undercount. `scripts/gates.sh`
delegates to it automatically when there is no native PHP. Larastan has the same
problem for the same reason (990 phantom errors on the mount, 0 off it), hence
`scripts/phpstan-isolated.sh`.

**A native PHP run on a local disk does not have this problem.** If you have PHP
8.2+ with `pdo_sqlite`, `mbstring`, `gd`, `dom` and `fileinfo`, the full suite
runs directly and collects every class:

```bash
php -d memory_limit=-1 vendor/bin/pest
```

`memory_limit=-1` is required, not cosmetic — the DomPDF payslip tests exhaust
the default limit and take the whole suite down with them.

Either way, check the collection count against `find api/tests -name '*Test.php'`
before believing a green run.

## Tests

- Backend: Pest, in `api/tests/{Unit,Feature}`. `phpunit.xml` declares only
  those two as testsuites; `tests/Performance` is deliberately outside them
  because it holds benchmarks, not gates.
- Frontend: Vitest + MSW in `src/src/test/`, Playwright in `src/e2e/`.
- Helpers `createTenant()`, `createUser()`, `actingAsUser()` and
  `selfieDataUrl()` live in `api/tests/Pest.php`.

**A regression test must be shown to fail without the fix.** A test that passes
both before and after proves nothing. Revert the change, watch it go red, put
the change back. `tests/Feature/Security/TenantImportIsolationTest.php` was
written that way and says so.

### Running the suite against MySQL

The default suite runs on SQLite `:memory:`. Production runs on MariaDB, and
the two are not interchangeable — every defect below was invisible on SQLite
and real on MySQL:

- `DATE` columns truncate the time component on insert; SQLite keeps it, so a
  `due_date` carrying a time made dunning fire a day late on one driver and on
  time on the other.
- `PDO::quote()` escapes newlines on MySQL and not on SQLite, so a backup dump
  could tear on the driver every backup test happened to use.
- `TRUNCATE` is DDL on MySQL and implicitly commits; SQLite has no TRUNCATE at
  all and Laravel compiles it to `DELETE FROM`.

So run it on MySQL before a release, and after touching anything that writes
dates, raw SQL, or schema:

```bash
mysql -e "CREATE DATABASE ethr_suite_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
./scripts/gates.sh mysql
```

`phpunit.mysql.xml` differs from `phpunit.xml` in seven `<env>` lines and
nothing else. Only `DB_CONNECTION` is forced; export `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME` or `DB_PASSWORD` to point it at your own server.

The `mysql` scope is **outside the full sweep**, like `security` and
`performance`, so `./scripts/gates.sh` still runs on a fresh clone with no
services. **It fails rather than skips when no server is reachable** — a gate
that skips reports success, and this repository has twice been caught by checks
that were green because they had not run.

CI runs it on every push (`backend-mysql` in `.github/workflows/gates.yml`),
against a `mariadb:10.11` service container and as a **non-root user**, which
also exercises whether the `audit_log` trigger migration works without `SUPER`.

**The trap, already paid for twice:** on MySQL, DDL implicitly commits —
`TRUNCATE`, `CREATE`, `ALTER`, `DROP`, `LOCK TABLES`. `RefreshDatabase` wraps
each test in a transaction, and DDL ends that transaction for good. There is no
rollback afterwards. Two different shapes of this bit:

- **In a `beforeEach`.** `PermissionSeeder` called `truncate()`. Laravel finds
  the transaction gone at teardown, sets `RefreshDatabaseState::$migrated =
  false`, and runs a full `migrate:fresh` before the next test — ~20s each,
  putting the suite at roughly nine hours. See
  `database/seeders/PermissionSeeder.php:32`.
- **In a test body.** `BackupRestoreRehearsalTest` drops every table on purpose.
  On SQLite the rollback undoes it; on MySQL the database simply stays destroyed
  and every later test fails on a missing table — including tests in other files
  that have nothing to do with it. That file now skips on non-SQLite drivers and
  `php artisan ethr:backup:rehearse` covers MySQL instead.

So: a test that must issue DDL needs a driver guard, not a hopeful rollback.

## Tenant isolation

The single thing most worth being careful about. `BelongsToTenant` adds a
fail-closed global scope: with no tenant context it applies `whereRaw('0 = 1')`,
so the absence of scope yields *no* rows rather than *all* rows.

There are ~147 `withoutGlobalScope` / `withoutGlobalScopes` call sites. Some are
legitimate — platform-admin surfaces, pre-authentication lookups, global
reference data like national holidays, and queued jobs, which run with no HTTP
tenant context and must re-scope by hand.

**Every one of them must re-apply a tenant predicate, directly or by deriving
from a key that is itself tenant-owned.** Nothing enforces this automatically.
One site was measurably wrong (`EmployeeImporter`, fixed with a regression
test); assume the next one can be too.

If you write raw SQL — `whereRaw`, `selectRaw`, `DB::raw` — it carries no scope
at all. Say `tenant_id` yourself.

## Commits

Conventional Commits, one logical change each:

```
fix(payroll): apply income tax Proclamation 1395/2025
docs(audit): establish Phase 0 forensic baseline
```

Work on a short-lived branch and merge, as the existing history does. Explain
*why* in the body — the comments and commit messages in this repository carry
an unusual amount of hard-won detail about measured failures, and that is
deliberate. Keep it up.

## Security

Do not open a public issue for a vulnerability. See [`SECURITY.md`](SECURITY.md).

Never commit a real `.env`, an `APP_KEY`, or a credential. `START_BACKEND.ps1`
used to hardcode an `APP_KEY` and it took a security pass to notice.
