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

### 4. Tenant isolation is fail-closed, and bypassed in 156 places

`BelongsToTenant` adds a global scope that applies `whereRaw('0 = 1')` when no tenant is resolved — absence of context yields *no* rows, not *all* rows. That design is why this product is safe by default.

There are **156** `withoutGlobalScope` / `withoutGlobalScopes` call sites across 53 files, counted 2026-09-16. Most are legitimate: platform-admin surfaces, pre-authentication lookups, global reference data, and queued jobs that run with no HTTP tenant context. **Every one must re-apply a tenant predicate**, directly or by deriving from a key that is itself tenant-owned. One was measurably wrong and shipped — see `tests/Feature/Security/TenantImportIsolationTest.php` — so assume the next one can be too.

`TenantScopeBypassInventoryTest` now pins that inventory per file and fails when a count moves, so **a new bypass cannot enter unnoticed**. Be clear about what that buys: it makes adding one a deliberate act, which is exactly what was missing when the shipped defect went in. It does **not** audit the 156 that already exist — a count cannot — and that audit is still unowned.

When it fails, the message tells you the question to answer: does the new query state `tenant_id` itself, or derive from a key already tenant-owned? If yes, update `tests/Feature/Security/tenant-scope-bypasses.php`. If no, you have found the next one.

Raw SQL (`whereRaw`, `selectRaw`, `DB::raw`) carries no scope at all. Say `tenant_id` yourself.

---

## Quality gates

```bash
./scripts/gates.sh            # the full sweep
./scripts/gates.sh quick      # everything except the test suites — seconds
./scripts/gates.sh backend    # composer validate, Pint, PHPStan, Pest
./scripts/gates.sh frontend   # i18n, Prettier, ESLint, tsc, Vitest
./scripts/gates.sh docs       # markdown link integrity
./scripts/gates.sh mysql      # the backend suite against MariaDB, not SQLite
./scripts/gates.sh security   # composer audit + npm audit (production deps)
```

`security` is deliberately outside the full sweep, like `performance`. It goes
red when a third party publishes an advisory, not when you break something, and
a gate that is permanently red stops being read. **It is green as of 2026-09-16**
— `npm audit --omit=dev` reports 0 vulnerabilities and `composer audit` is
clean. It was red from the day it was added; see `docs/audit/BASELINE.md` §15a
for what closed it. Expect it to go red again without warning, because that is
what this gate is for.

`mysql` is outside it too, for a different reason: it needs a database server,
and the full sweep has to stay runnable on a fresh clone. **It fails rather than
skipping when no server is reachable** — the whole point is that the suite
otherwise only ever runs on SQLite while production runs on MariaDB. The first
time anyone pointed it at MariaDB (2026-09-15) it found a search defect that
returned nothing in production and passed every test. CI runs it on every push.

**CI calls `gates.sh` rather than restating it**, so the two cannot drift.

**The Actions tab was finally read on 2026-09-16, and every run had failed — 50 of them, since the first push.** Not one gate had ever executed. Two causes, both structural rather than code:

1. **No shell script had its executable bit.** Every `scripts/*.sh` was mode `100644`, so `./scripts/gates.sh` exited **126** (Permission denied) on a Linux runner. Git on Windows does not track the bit unless `core.filemode` is set, so it was never committed. Fixed with `git update-index --chmod=+x`.
2. **`composer install` died before any gate ran.** `config/broadcasting.php:25` defaults to `reverb` when `BROADCAST_CONNECTION` is unset, a runner has no `.env`, and `routes/channels.php` calls `Broadcast::channel()` at load time — so `package:discover` built a Reverb broadcaster with a null Pusher key and composer exited 1. Fixed with a workflow-level `BROADCAST_CONNECTION: "null"`.

3. **`phpstan_gate` required Docker.** It delegated unconditionally to `scripts/phpstan-isolated.sh`, which exits 1 with "Container et-api-1 is not running" when there is no `et-api-1`. A CI runner has native PHP and no container, so **PHPStan could never have passed in CI regardless of the code**. Now native-first with the container as fallback, the same shape `pest_gate` already had.

Once 1 and 2 were fixed the workflows ran for the first time (run #55): **Documentation integrity and Backend suite on MySQL passed**, three jobs failed. Those three were real — a stale `phpstan-baseline.neon` entry, `tsc` needing `.next/types` that only exists after a build, and genuine OpenAPI drift.

**A correction, recorded rather than quietly fixed:** the backend failure was attributed to the stale baseline entry. That was inferred from a local *native* PHPStan run, never from the CI log. Because of cause 3 above, PHPStan never ran in CI at all, so the baseline error cannot have been what CI reported. Both defects were real and both needed fixing; the attribution was wrong.

The lesson worth keeping: this file twice told readers to treat CI as *probably fine but unconfirmed*. It was not fine. **Read the Actions tab rather than reasoning about it** — the whole reason the workflows exist is to tell you something you do not already know. And when it fails, read *its* log rather than reproducing locally and assuming the cause matches.

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
