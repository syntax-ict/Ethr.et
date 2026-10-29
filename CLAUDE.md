# ETHR

Multi-tenant, offline-first HCM SaaS for Ethiopian organizations.

**The conventions live in [`docs/CLAUDE.md`](docs/CLAUDE.md).** Read it before writing code — the 15 Non-Negotiable Conventions, the design system, the API and testing rules. This file exists because agent tooling loads `CLAUDE.md` from the repository root, and nothing here pointed there.

Also worth reading: [`CONTRIBUTING.md`](CONTRIBUTING.md) for how to run and test the project, and [`docs/audit/BASELINE.md`](docs/audit/BASELINE.md) for what is actually measured versus merely documented.

**This file states rules, not history.** How each rule was learned — the measurements, the audits, the corrections — is in [`docs/audit/BASELINE.md`](docs/audit/BASELINE.md) §25 and the sections it cites. When a figure here moves, change its one line here and record why in BASELINE.

---

## Four things that will otherwise cost you a day

### 1. The directory names are inverted

- **`api/`** is the entire Laravel 12 backend — not a thin API layer over something else.
- **`src/`** is the Next.js *project root*. The real frontend source is nested one level down at **`src/src/`**.

### 2. A green test run can be a lie

Over a Docker Desktop Windows bind mount, PHP's recursive directory scan returned incomplete results. Measured 2026-08-21: `vendor/bin/pest` collected **21 of 132** test classes, ran them, and **exited 0 with a green summary**. It did that for weeks. Larastan had the same problem in the other direction — ~990 phantom errors from migrations it could not see. The Docker stack is gone (2026-09-30), but any lossy filesystem fails the same silent way.

Use `./scripts/gates.sh`, which fails loudly on an undercount. If you run the suite directly, check the collection count against `find api/tests/Unit api/tests/Feature -name '*Test.php'` before believing the result.

- **Count only those two directories, not `api/tests` whole.** `phpunit.xml` declares exactly `tests/Unit` and `tests/Feature`; `tests/Performance` is opt-in via `./scripts/gates.sh performance`. A healthy run collects one fewer class than `find api/tests` reports — `tests/Performance/ResponseTimeTest.php`, every time.
- **Compare against `find`'s current output, never a number written down earlier.** The suite grows; a dated figure makes a clean run look wrong. The ratio is what matters.
- **A native PHP run on a local disk does not have this problem.** The trap is the lossy filesystem, not PHP.

### 3. There is no Docker, and no worker process

**Local development is the production shape: XAMPP's Apache, PHP and MariaDB.** `scripts/local-production/up.sh` builds and serves the Bronze deployment on `:8081` and `verify.sh` must end `0 failed` (52 checks as of 2026-10-20; the count grows as checks are added); for hot reload, run `php artisan serve` and `npm run dev` against the same MariaDB. [`docs/LOCAL_SETUP.md`](docs/LOCAL_SETUP.md) has both. The Docker development stack — compose files, `docker/`, and the container-only scripts — was **removed on 2026-09-30** by owner decision, because production is shared hosting and a dev stack running Redis, MinIO and nginx tested a different system.

**Queued jobs run only when something drains the queue.** Production has no worker process: the GitHub Actions caller (`.github/workflows/cron.yml`) hits `POST /api/v1/cron/queue` and `/cron/schedule`. Locally, run `php artisan queue:work --queue=attendance,notifications,default,exports --stop-when-empty`. Do not paper over it with `QUEUE_CONNECTION=sync`, which hides every bug that only appears on a real worker. `BROADCAST_CONNECTION=null` locally, as in production — with `reverb` selected and no Reverb running, a broadcast throws and a *successful* write returns 500.

The `RUN_ALL.ps1` / `START_BACKEND.ps1` / `START_FRONTEND.ps1` launchers were **removed on 2026-09-29**: they printed "SQLite" against a MariaDB stack and started neither the worker nor Reverb.

### 4. Tenant isolation is fail-closed, and deliberately bypassed

`BelongsToTenant` adds a global scope that applies `whereRaw('0 = 1')` when no tenant is resolved — absence of context yields *no* rows, not *all* rows. That design is why this product is safe by default.

There are **175** `withoutGlobalScope` / `withoutGlobalScopes` call sites across **70** files *(2026-10-28)*, counted with PHP's tokeniser against `tests/Feature/Security/tenant-scope-bypasses.php`. `TenantScopeBypassInventoryTest` pins that inventory per file and fails when a count moves, so a new bypass cannot enter unnoticed. That makes adding one a deliberate act; it does **not** audit the ones that exist. Most are legitimate — platform-admin surfaces, pre-authentication lookups, global reference data, queued jobs with no HTTP tenant — and one that was measurably wrong did ship (`tests/Feature/Security/TenantImportIsolationTest.php`), so assume the next one can be too. The count's log and both audits of every site are in BASELINE §11c–§11j and §25b.

When the inventory test fails, it asks the question to answer: does the new query state `tenant_id` itself, or derive from a key already tenant-owned? If yes, update `tests/Feature/Security/tenant-scope-bypasses.php` and this line. If no, you have found the next defect.

The rules every bypass and every tenant-aware query must follow:

- **Re-apply a tenant predicate, on the same chain, unconditionally.** State `tenant_id` in the same statement, or derive from a key that is itself tenant-owned. A predicate wrapped in `if ($this->tenantId !== null)` is stated by the dispatcher, not the query — **having a predicate is necessary, not sufficient; having one conditionally is neither.** `QueuedJobTenantPredicateTest` fails if a scoped job's tenant argument goes back to optional or to `?int`.
- **Never derive the tenant from the row you are fetching.** A queued job carries the tenant id in its payload, as a required `readonly int`, and its root lookup states it.
- **A job that needs tenant context sets it itself.** `CurrentTenant` is a `scoped` binding, flushed between jobs; do not assume it is absent, and do not assume it is right. (It was a `singleton`, and the next job inherited the previous job's tenant.)
- **A queue drain is a deploy precondition** whenever a job's constructor changes — PHP restores queued jobs without running the constructor. `docs/DEPLOYMENT.md` → *Draining the queue before an upgrade*; `DispatchWebhookJob` backs off to 24 hours, so a delivery deep in retry survives a drain.
- **A cross-tenant lookup keyed on a column needs a unique index that matches the lookup**, and the query must not rely on it: `devices.webhook_token` is unique; live devices are globally unique on `(serial_number, adapter_type)` through the generated `serial_number_active` column (MariaDB has no partial indexes; VIRTUAL because SQLite refuses a stored one), and `resolveWebhookDevice()` still rejects when it finds two candidates.
- **A related `public_id` from another tenant is a 422, not a silent null.** The check is a `withValidator()` hook (`Http/Requests/Employee/Concerns/ValidatesRelationTenancy.php`), not a rule, so `rules()` and the generated contract stay unchanged. It re-runs the resolver's own scoped lookup, and the field/model map lives once, in `App\Support\EmployeeRelations::MAP`. The message is Laravel's generic `validation.exists`, identical to a nonexistent id — a distinguishable one would confirm a `public_id` across tenants.
- **Raw SQL (`whereRaw`, `selectRaw`, `DB::raw`) carries no scope at all.** Say `tenant_id` yourself.

### Never put a comment where Scramble reads it

Scramble generates `src/src/api/generated.ts` and publishes PHP comments as OpenAPI `description`s. **Never put an explanatory comment above a key in any array the spec is derived from** — FormRequest `rules()` and controller `response()->json([...])` arrays alike — **and never directly above the `return`** of a response. Put it above an earlier statement, or in `BASELINE.md`. Each of those placements has cost a red API-contract gate.

One Pint trap from the same work: `@mixin FormRequest` must use the imported name (a leading-slash FQN fails `fully_qualified_strict_types`).

### Running the gates locally needs a GitHub token

`composer install` fetches **163 packages** from `api.github.com`, which allows **60 unauthenticated requests per hour per IP** — a cold install stops at `AuthHelper.php:132` with `Could not authenticate against github.com` and nothing installed. **The fix is a token with no scopes at all**; everything fetched is public.

```bash
composer config --global --auth github-oauth.github.com <token>   # writes COMPOSER_HOME/auth.json
composer diagnose | grep 'github.com oauth'                       # → "OK  expires on <date>"
```

A classic PAT with **no boxes checked**, or a fine-grained token with read-only access to public repositories, is sufficient. **Do not use a `repo`-scoped token** — it would hand every one of those 163 packages' install scripts a credential to your private repositories for no benefit. Never commit `auth.json` or `.env`; `CONTRIBUTING.md` carries the longer version of this.

In an agent sandbox, set the token in the environment's own settings, not as a shell export — each command starts a fresh shell.

**In a Claude Code cloud sandbox, a token does not help.** The egress proxy scopes GitHub per repository, so every `dist` zipball 403s, and composer reports it as the same authentication error. Run `./scripts/composer-install-sandbox.sh` instead: it installs from source, supplies the `source` that `composer.lock` omits for `phpstan/phpstan`, and lifts composer's 300-second process timeout, which a slow clone otherwise trips into that same misleading error. It patches gitignored `composer.sandbox.*` copies and never touches `composer.json` or `composer.lock`. The measurements are in BASELINE §25c.

### Install order for a fresh checkout

`cp api/.env.example api/.env` and set `BROADCAST_CONNECTION=null` (`.env.example` ships `reverb`), **then** `composer install`, **then** `php artisan key:generate`. `.env` must exist before the install because `package:discover` runs during it; `key:generate` comes last because it needs `vendor/`. A checkout with no `.env` at all selects the null broadcaster by default (`config/broadcasting.php`, pinned by `BroadcastConnectionConfigTest`) — do not reintroduce a workflow-level override.

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
./scripts/gates.sh coverage   # backend line coverage — needs PCOV or Xdebug
```

Three gates sit outside the full sweep, each for a reason:

- **`performance`** prints what it measured, not only whether it passed, so its output is a baseline to compare against (first one: BASELINE §13e).
- **`security`** goes red when a third party publishes an advisory, not when you break something, and a permanently red gate stops being read. Expect it to go red without warning; that is its job.
- **`mysql`** needs a database server, and the full sweep must run on a fresh clone. **It fails rather than skipping when no server is reachable**, because otherwise the suite only ever runs on SQLite while production runs on MariaDB. CI runs it on every push.

**CI calls `gates.sh` rather than restating it**, so the two cannot drift. Node is declared once, in `.nvmrc`. Every `scripts/*.sh` must be committed executable (`git update-index --chmod=+x`) — Git on Windows does not track the bit.

**Read the Actions tab rather than reasoning about it.** Every CI run failed for the project's first 50 pushes, from five structural causes invisible in the working tree (BASELINE §25d). When CI fails, read *its* log rather than reproducing locally and assuming the cause matches.

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
| Run it locally, production-shaped | [`docs/deployment/LOCAL-PRODUCTION-SETUP.md`](docs/deployment/LOCAL-PRODUCTION-SETUP.md) — `scripts/local-production/up.sh`, then http://localhost:8081. Real Apache + MariaDB; 52/52 as of 2026-10-20, zero errors. **Closes no host gate** |
| **Can we go live? — read this first** | [`docs/deployment/CUTOVER-CHECKLIST.md`](docs/deployment/CUTOVER-CHECKLIST.md) — the one register that decides. **`CUTOVER READY = NO` as of 2026-09-27.** Every gate carries one of `PASS` / `FAIL` / `HOST ACTION REQUIRED` / `OWNER DECISION` / `BLOCKED`, and **a green CI run moves none of them** |
| Deployment target status | [`docs/deployment/GATE-0-RESULT.md`](docs/deployment/GATE-0-RESULT.md) — read its *Gate status reconciliation* section at the end for current status; the tables above it are dated |
| Production frontend mode | **Static export served by Apache/Plesk — owner decision 2026-09-27.** No Plesk Node application. [`docs/decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md`](docs/decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md). Render the deployed rules with `render-htaccess.php --target=static-export` — **not `--branch=a`**, which is the Node branch |
| Scheduler | **GitHub Actions — owner decision 2026-09-27.** [`.github/workflows/cron.yml`](.github/workflows/cron.yml), documented in [`docs/deployment/shared-hosting/cron-caller.md`](docs/deployment/shared-hosting/cron-caller.md). Chosen because **G0-D is FAILED** — the account has no Scheduled Tasks section |
| How a tenant is reached | **`ethr.et/{slug}` or the organisation's own domain — owner decision 2026-10-06**, because M3 serves no subdomain. On the apex the tenant travels in `X-Tenant`. Build links with `FrontendUrl::forTenant()`, never by hand. [`docs/decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md`](docs/decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md) |
| **Go live: GitHub → Plesk Git** | [`docs/deployment/PLESK-GO-LIVE.md`](docs/deployment/PLESK-GO-LIVE.md). `release.yml` publishes a built `production` branch after the gates pass on `main`. Plesk Git deploys it to `/ethr`. The document root is `ethr/api/public`, and artisan runs from the **Laravel Toolkit**: the deployment shell is chrooted with no PHP (owner decision 2026-10-06). **Never point Plesk Git at `main`, and never `key:generate` after the first deploy** |
| Deploying to Plesk shared hosting | [`docs/deployment/PLESK-HOSTING-GUIDE.md`](docs/deployment/PLESK-HOSTING-GUIDE.md) |
| **Hard rule — repository only** | [`docs/deployment/SHARED-HOSTING-CONTRACT.md`](docs/deployment/SHARED-HOSTING-CONTRACT.md) — work happens here, not in hosting settings. Outranks every deployment doc |

Production target is Ethio Telecom Linux shared hosting under Plesk. The VPS production assets were removed in `3db9904` (2026-09-26/27) and the Docker development stack on 2026-09-30, both before a verified cutover — there is no container path of any kind, and local development runs the shared-hosting shape on XAMPP.
