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
