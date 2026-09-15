# ETHR

Multi-tenant, offline-first HCM SaaS for Ethiopian organizations.

**The conventions live in [`docs/CLAUDE.md`](docs/CLAUDE.md).** Read it before writing code — the 15 Non-Negotiable Conventions, the design system, the API and testing rules. This file exists because agent tooling loads `CLAUDE.md` from the repository root, and nothing here pointed there.

Also worth reading: [`CONTRIBUTING.md`](CONTRIBUTING.md) for how to run and test the project, and [`docs/audit/BASELINE.md`](docs/audit/BASELINE.md) for what is actually measured versus merely documented.

---

## Four things that will otherwise cost you a day

### 1. The directory names are inverted

- **`api/`** is the entire Laravel 12 backend — not a thin API layer over something else.
- **`src/`** is the Next.js *project root*. The real frontend source is nested one level down at **`src/src/`**.

### 2. A green test run can be a lie

Over a Docker Desktop Windows bind mount, PHP's recursive directory scan returns incomplete results. Measured 2026-08-21: `vendor/bin/pest` collected **21 of 132** test classes, ran them, and **exited 0 with a green summary**. It did that for weeks. Larastan has the same problem in the other direction — ~990 phantom errors from migrations it could not see.

Use `./scripts/gates.sh`, which routes around it and fails loudly on an undercount. If you run the suite directly, check the collection count against `find api/tests -name '*Test.php'` before believing the result.

**A native PHP run on a local disk does not have this problem** — measured 140/140 classes collected, 1673 tests passing. The trap is the bind mount, not PHP.

### 3. Four processes, not two

`docker compose up -d`. Without the queue worker no job ever runs; without Reverb a broadcast throws, so a *successful* write can still return 500 under `QUEUE_CONNECTION=sync`.

The `RUN_ALL.ps1` / `START_BACKEND.ps1` / `START_FRONTEND.ps1` launchers predate the Docker setup. `RUN_ALL.ps1` prints "SQLite" while the documented stack is MariaDB, and starts neither the worker nor Reverb.

### 4. Tenant isolation is fail-closed, and bypassed in 147 places

`BelongsToTenant` adds a global scope that applies `whereRaw('0 = 1')` when no tenant is resolved — absence of context yields *no* rows, not *all* rows. That design is why this product is safe by default.

But there are ~147 `withoutGlobalScope` / `withoutGlobalScopes` call sites. Most are legitimate: platform-admin surfaces, pre-authentication lookups, global reference data, and queued jobs that run with no HTTP tenant context. **Every one must re-apply a tenant predicate**, directly or by deriving from a key that is itself tenant-owned. Nothing enforces this. One was measurably wrong and shipped — see `tests/Feature/Security/TenantImportIsolationTest.php` — so assume the next one can be too.

Raw SQL (`whereRaw`, `selectRaw`, `DB::raw`) carries no scope at all. Say `tenant_id` yourself.

---

## Quality gates

```bash
./scripts/gates.sh            # the full sweep
./scripts/gates.sh quick      # everything except the test suites — seconds
./scripts/gates.sh backend    # composer validate, Pint, PHPStan, Pest
./scripts/gates.sh frontend   # i18n, Prettier, ESLint, tsc, Vitest
./scripts/gates.sh docs       # markdown link integrity
./scripts/gates.sh security   # composer audit + npm audit (production deps)
```

`security` is deliberately outside the full sweep, like `performance`. It goes
red when a third party publishes an advisory, not when you break something, and
a gate that is permanently red stops being read. **It is red right now** — see
`docs/audit/BASELINE.md` §15.

**CI calls `gates.sh` rather than restating it**, so the two cannot drift. `main` has been pushed, so the workflows should now be running — but no run has been read from here (`gh` is not installed and the repository is private), so treat a green build as unconfirmed until someone has actually looked at the Actions tab.

This paragraph previously said the workflows had "executed zero times, because `main` is 19 commits ahead and nothing has been pushed". That was true on 2026-09-15 and stopped being true the same day. It is the third stale fact this file has carried; if you are reading it long after that date, check rather than trust it.

What *is* active is the pre-push hook, once you enable it:

```bash
git config core.hooksPath .githooks
```

It runs `gates.sh quick` — every gate except the test suites — plus a check for `.env` files and `APP_KEY` literals in the diff.

---

## Where things are

| | |
|---|---|
| Conventions | [`docs/CLAUDE.md`](docs/CLAUDE.md) |
| How to contribute | [`CONTRIBUTING.md`](CONTRIBUTING.md) |
| Measured state of the codebase | [`docs/audit/BASELINE.md`](docs/audit/BASELINE.md) |
| Security reporting | [`SECURITY.md`](SECURITY.md) |
| Documentation index | [`docs/README.md`](docs/README.md) |
| Deployment target status | [`docs/deployment/GATE-0-RESULT.md`](docs/deployment/GATE-0-RESULT.md) |

Production target is Ethio Telecom Linux shared hosting under Plesk. The VPS and Docker production assets are kept only until that cutover is verified.
