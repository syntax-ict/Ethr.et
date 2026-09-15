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
./scripts/gates.sh            # all nine
./scripts/gates.sh backend    # Pint, PHPStan, Pest
./scripts/gates.sh frontend   # i18n, Prettier, ESLint, tsc, Vitest
```

**There is no CI.** Several documents in this tree say there is; they are wrong, and `docs/audit/BASELINE.md` §15 records it as an open risk. Until that changes, the gate is you.

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
