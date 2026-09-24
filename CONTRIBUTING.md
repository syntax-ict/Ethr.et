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

**Node comes from `.nvmrc`** — currently 24, which `nvm use` / `fnm use` picks
up from the repository root and which CI reads via `node-version-file`. It is
not a preference. Two frontend test files fail on Node 20 and one of them on 22
as well, for reasons that have nothing to do with the application code; that
pin is what kept `Frontend (i18n, Prettier, ESLint, tsc, Vitest)` red on every
CI run until 2026-09-16. `docs/audit/BASELINE.md` §12d has the measurements.

`RUN_ALL.ps1`, `START_BACKEND.ps1` and `START_FRONTEND.ps1` are older
Windows-only launchers that predate the Docker setup. `RUN_ALL.ps1` in
particular prints "SQLite" while the documented stack is MariaDB on port 3307,
and it starts neither the worker nor Reverb. Prefer Docker Compose unless you
know why you want otherwise.

### `composer install` needs a GitHub token

Every one of the 163 `dist` URLs in `api/composer.lock` points at
`api.github.com`, and `api/composer.json` declares no custom `repositories` —
so a cold install (empty Composer cache) makes well over a hundred API calls.
Unauthenticated, GitHub allows **60 per hour per IP**. The install gets partway,
starts receiving 403s, and Composer stops to prompt for a token — which under
`--no-interaction`, in a hook, or in a script is simply a failure.

Store a token once, globally:

```bash
composer config --global --auth github-oauth.github.com <your-token>
```

That writes to `COMPOSER_HOME/auth.json`, outside the repository. A per-project
`api/auth.json` works too and is already gitignored, but the global file keeps
the credential out of the working tree entirely. **Never commit either one.**

**The scope required is none.** Every dependency is a public package from
Packagist, and this repository is public — the token is doing nothing but
lifting a rate limit. A classic PAT with *no* boxes checked, or a fine-grained
token with read-only access to public repositories, is sufficient and grants
nothing else. A `repo`-scoped token would hand every dependency's install
script a credential to your private repositories for no benefit.

Verify it without printing it:

```bash
composer diagnose | grep 'github.com oauth'
# Checking github.com oauth access: OK  expires on <date>
```

That line reports the expiry as well. An expired token fails exactly the way a
missing one does, so check here first when a previously working `composer
install` starts rate-limiting.

CI does not need one: the `Install PHP dependencies` step in
`.github/workflows/gates.yml` passes no token and the runs are green. This is a
workstation prerequisite, not a pipeline secret.

### `composer install` also needs `BROADCAST_CONNECTION` set

On a machine with no `api/.env` — a fresh clone — `composer install` fails
*after* downloading everything, in the `package:discover` post-autoload-dump
script:

```
Failed to create broadcaster for connection "reverb" with error:
Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given
```

`config/broadcasting.php` defaults to `reverb` when `BROADCAST_CONNECTION` is
unset and `routes/channels.php` calls `Broadcast::channel()` at load time. This
is the same defect that blocked CI for fifty runs; the workflow fixes it with a
job-level `BROADCAST_CONNECTION: "null"`. Locally, do the equivalent before the
first install:

```bash
cd api
cp .env.example .env         # ships BROADCAST_CONNECTION=reverb — change it to null
php artisan key:generate     # encrypted casts on Employee.tin refuse to boot without APP_KEY
composer install
```

Without the `key:generate` the suite dies with `MissingAppKeyException` rather
than failing a test, which reads like a broken suite instead of an unconfigured
one.

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
there is one definition of "does this pass".

*(This section used to end "**It has never run** — nothing has been pushed to the
remote yet". That stopped being true long ago and is corrected rather than
deleted, because the reason it was written is worth keeping: for the first 50
pushes the workflows really did fail before any gate executed, and
`docs/audit/BASELINE.md` records the five structural causes. Run #66 was the
first fully green one. As of run #431 the seven jobs pass on every push to
`main`.)*

The seven jobs, named as GitHub reports them — these strings are what a required
status check must match exactly:

```
Documentation integrity
API contract (OpenAPI drift)
Backup restore rehearsal on MariaDB
Frontend (i18n, Prettier, ESLint, tsc, Vitest)
Backend (Pint, PHPStan, Pest)
Backend coverage (PCOV)
Backend suite on MySQL
```

`security.yml` is a separate workflow and is **not** one of them. It triggers on
a schedule and on pull requests touching `composer.json`, `composer.lock`,
`package.json` or `package-lock.json` — so on most pull requests it never starts.

### Branch protection on `main`

**Intended configuration, recorded here so it is reviewable rather than living
only in the repository settings UI.** Nothing in the repository enforces it; it
is applied under *Settings → Branches*, or *Settings → Rules → Rulesets*.

| Setting | Value |
|---|---|
| Branch name pattern | `main` |
| Require a pull request before merging | on |
| — Required approvals | **0** |
| Require status checks to pass | on, with the seven jobs listed above |
| — Require branches to be up to date first | on |
| Block force pushes | on |
| Restrict deletions | on — **targeting `main` only** |
| Do not allow bypassing the above settings | off |

Four of those are counter-intuitive enough to state the reason:

- **Required approvals is 0, deliberately.** GitHub does not let anyone approve
  their own pull request. On a repository with a single maintainer, requiring one
  approval makes every pull request permanently unmergeable by the only person
  who can merge it. Zero still forces the pull-request flow and still requires the
  checks; it only declines to demand a second human who does not exist. Raise it
  the day there is a second reviewer.
- **Do not require `security.yml`.** It does not run on most pull requests (see
  above), and a required check that never starts blocks the pull request forever.
  This is the same reason `CLAUDE.md` gives for keeping `security` out of the full
  sweep: a gate that is permanently red, or permanently pending, stops being read.
- **Scope *Restrict deletions* to `main`.** A ruleset targeting all branches with
  that option on also blocks ordinary branch cleanup, which is a slow thing to
  diagnose — the failure surfaces as an HTTP 403 on the ref update while ordinary
  pushes keep working, and `git push` then prints a misleading
  `Everything up-to-date` before exiting 1.
- **Bypass stays off.** With it on, administrators are bound too, which on a
  solo repository removes the only escape hatch when a check is stuck. Off, the
  rules apply to normal work and can still be overridden deliberately.

**Why this is worth doing at all:** until it is applied the gates are advisory.
Seven green jobs block nothing, `main` accepts a direct push, and a force-push
over it is permitted. The greens are real; the enforcement is not. That gap is
the same shape as the one `BASELINE.md` documents at length — a control that is
documented and believed in, but not actually in the path of the thing it is meant
to stop.

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
- Frontend coverage, run deliberately rather than as a gate — it roughly doubles
  the frontend gate's wall time:

  ```bash
  cd src && npx vitest run --coverage
  ```

  Baseline 2026-09-16: **32.34% statements, and 170 of 321 files at 0%** — better
  than half the frontend is never imported by a test. See `docs/audit/BASELINE.md`
  §12f. Backend coverage needs PCOV or Xdebug installed and is not currently
  possible; `phpdbg` looks like a way round it and is not (§12e).
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
