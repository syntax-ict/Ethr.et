# ETHR — Local Development Setup

Verified working on Windows 11 + Docker 29.4.3 + PHP 8.2 + Node on **2026-08-02**.

**Shape:** Docker runs the *infrastructure*; PHP and Node run *natively*. That keeps hot
reload fast while giving the CLAUDE.md locked stack (MariaDB 10.11 / Redis 7 / MinIO) where
correctness actually depends on it.

---

## Why not SQLite + `sync`

The previous local config used `DB_CONNECTION=sqlite`, `CACHE_STORE=file`,
`SESSION_DRIVER=file` and **`QUEUE_CONNECTION=sync`**. That last one is not a harmless
convenience: `sync` runs "queued" work inline inside the HTTP request, so anything that
only breaks on a real worker — no `CurrentTenant` bound, no request context — is invisible
locally. A tenant-scoped lookup that silently returns zero rows on a worker looks perfect
under `sync`. Two bugs found on 2026-08-02 had exactly that shape.

The old config is preserved at `api/.env.sqlite.bak` if you need to fall back.

---

## 1. Infrastructure (Docker)

```bash
docker compose up -d mariadb redis minio mailpit
```

| Service | Host port | Notes |
|---|---|---|
| MariaDB 10.11 | **3307** | `3307`, not 3306 — remapped by `docker-compose.override.yml` |
| Redis 7 | 6379 | cache + queue + session |
| MinIO | 9000 (API), **9002** (console) | console login `ethr_minio` / `ethr_minio_secret` |
| Mailpit | 1025 (SMTP), **8025** (inbox) | catches all outbound mail |

Wait for all four to report `healthy`:

```bash
docker compose ps
```

### One-time: create the MinIO bucket

Without it, every upload fails.

```bash
docker run --rm --network et_ethr --entrypoint sh minio/mc:latest -c "mc alias set local http://minio:9000 ethr_minio ethr_minio_secret && mc mb --ignore-existing local/ethr"
```

---

## 2. Database

```bash
cd api && php artisan migrate --seed
```

Seeds a demo tenant with 150 employees, 6 months of attendance, 3 months of payroll,
leave requests, devices and holidays.

| Login | Password | Role |
|---|---|---|
| `admin@demo.ethr.et` | `password` | tenant_admin |
| `hr@demo.ethr.et` | `password` | hr_admin |
| `emp@demo.ethr.et` | `password` | employee |
| `superadmin@ethr.et` | `password` | super_admin (platform) |

Tenant subdomain is `demo`.

---

## 3. Application processes

Four processes. **All four matter** — skipping the worker or Reverb produces failures that
look like application bugs.

```bash
cd api && php artisan serve --host=127.0.0.1 --port=8000
```
```bash
cd api && php artisan queue:work --queue=attendance,payroll,devices,notifications,exports,sync,default --tries=3 --timeout=300
```
```bash
cd api && php artisan reverb:start --host=127.0.0.1 --port=8080
```
```bash
cd src && node node_modules/next/dist/bin/next dev src
```

Optional — runs the daily scheduled jobs (leave accrual, missing-punch scan, 48h approval
reminders, invoice generation):

```bash
cd api && php artisan schedule:work
```

App at **http://localhost:3000**. Next proxies `/api` and `/sanctum` to the API, so the
browser talks to one origin and there is no CORS involved.

---

## 4. Optional: run the production hostname model locally

Default local development is **single-host**: everything is `localhost:3000` and the tenant
travels in the `X-Tenant` header from `localStorage.tenant`. That is deliberate — a bare
`localhost` has no subdomain to read — but it means the model the login is actually built
around cannot be seen or manually tested on your machine. Turn it on when you are working on
authentication, tenant resolution, the admin console, or impersonation.

Browsers resolve any `*.localhost` name to 127.0.0.1 themselves (RFC 6761), so there is no
hosts file to edit and no DNS involved.

**Native setup** — set the root domain on both halves and restart both processes:

```bash
# api/.env
APP_DOMAIN=localhost

# src/.env.local
NEXT_PUBLIC_ROOT_DOMAIN=localhost
```

**Docker setup** — the overlay carries the same two settings plus the CORS patterns:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml \
               -f docker-compose.hostnames.yml up -d --build frontend
```

Rebuild, not restart: `NEXT_PUBLIC_*` is inlined into the client bundle at build time.

Then:

| URL | Context | Login page |
|---|---|---|
| `http://localhost:3000/login` | public (apex) | "Sign in to ETHR", organisation field shown, submit routes to the tenant's host |
| `http://admin.localhost:3000/login` | platform | "Sign in to ETHR Platform", no tenant field, no SSO/OTP/register |
| `http://demo.localhost:3000/login` | tenant | "Sign in to Demo…", field replaced by a read-only indicator |
| `http://nope.localhost:3000/login` | unknown tenant | controlled "Organization not found" state |

**What changes when it is on** — these are the point, not side effects:

- `X-Tenant` stops being consulted; the hostname decides. A tenant you had been reaching the
  old way will look empty rather than missing.
- `/api/v1/admin/*` answers **404** on tenant hosts, and the console is served only from
  `admin.localhost`.
- A super admin is refused on tenant hostnames — the sanctioned route to tenant data is
  impersonation.
- Impersonation uses the cross-host handoff nonce instead of planting a cookie directly.

Turn it off by dropping the two settings (and rebuilding the frontend). Details and the full
reasoning are in the header of `docker-compose.hostnames.yml`.

---

## Gotchas that have each cost real time

- **Reverb must be running.** Notifications broadcast over cURL; with Reverb down the send
  throws, and because notifications fire inline from HTTP requests that turns a *successful*
  write into a 500. Same for Mailpit and the `mail` channel.
- **MariaDB is on 3307 on the host**, 3306 only inside the compose network.
- **Larastan reads the live dev database.** After adding a migration, run
  `php artisan migrate` *and* `vendor/bin/phpstan clear-result-cache`, or PHPStan reports a
  wall of false positives on the new model.
- **The backend suite silently runs a fraction of itself over the bind mount.**
  PHP's recursive directory scan returns incomplete results on Docker Desktop for
  Windows: `pest` collected **22 of 132 test classes**, ran them, and exited 0
  with a green summary (measured 2026-08-21). `scripts/gates.sh` now compares the
  collected count against `find` and fails loudly instead. Run the suite with
  `bash scripts/pest-isolated.sh`, which copies `api/` into the container's own
  filesystem first — collection there is complete (132/132). It also passes
  `memory_limit=-1`, which the DomPDF payslip tests need or they OOM the run.
- **All four frontend quality gates run locally now** — `npx tsc --noEmit`,
  `npx vitest run`, `npx prettier --check src/`, `npx eslint src/` (verified 2026-08-21).
  This entry previously said `npm`/`npx` were broken (`spawn C:\xampp\php ENOENT`), Prettier
  was missing, and ESLint 9 could not load its config; the ESM config fix and the dependency
  install have since resolved all three. If `npx` does misbehave on your machine, the fallback
  still works: `node node_modules/typescript/bin/tsc --noEmit`,
  `node node_modules/vitest/vitest.mjs run`.
- **Recreating a container changes its IP, and nginx caches the old one.** After
  `docker compose up -d --force-recreate api`, every request through `:8888` answers **502**
  (`connect() failed (111: Connection refused)` in the nginx log) until you
  `docker compose restart nginx`. Nothing is actually broken; nginx resolved `api` once at
  startup.
- **Switching the hostname model (§4) off requires a `--no-cache` frontend build** if the
  build arg is ever left undeclared. `NEXT_PUBLIC_*` is baked into the bundle in the builder
  stage, so a cached layer will keep serving the previous root domain while the container's
  own environment reads empty — the stack looks restored and is not. `docker-compose.yml`
  now declares `NEXT_PUBLIC_ROOT_DOMAIN: ""` explicitly to keep it in the cache key.
- **PHP is not on PATH here**; the backend gates run inside the container:
  `… ./vendor/bin/pint --test`, `… ./vendor/bin/phpstan analyse` (and the suite
  via `scripts/pest-isolated.sh`, per the collection note above).
- **PHP edits do not take effect until you reload the container. Run
  `bash scripts/api-reload.sh`.** The api container runs with
  `opcache.validate_timestamps = 0`, so opcache never notices an edited file —
  a fix you just made keeps returning the old result with nothing to say why.
  This cost real debugging time on 2026-08-15 and again on 2026-08-22.
  It is a deliberate, measured trade-off, not an oversight: turning validation on
  makes **every** API request take 7–18 s instead of ~0.5 s, because each one
  re-stats the include graph across the Windows bind mount (`revalidate_freq = 2`
  does not help — any request more than 2 s after the last still pays in full).
  The measurement is recorded in `docker/php/php.ini`; re-measure before changing
  it. The reload script also restarts nginx (which caches the old container IP,
  see the next entry) and absorbs the ~20 s cold-opcache request so the next real
  one is fast. The root fix is to stop bind-mounting on Windows — a named volume
  or a WSL2-native checkout — which also fixes the lossy test collection above.
- **Only one Next dev server per project** (shared `.next`); a second starts and dies.
- **Driving the UI programmatically:** the React-Hook-Form login cannot be filled by
  injected values. Hit `/sanctum/csrf-cookie`, then `POST /api/v1/auth/login` with **both**
  `X-Tenant` and `X-XSRF-TOKEN` (omitting either gives 419). Tenancy rides the `X-Tenant`
  header from `localStorage.tenant` on localhost, so no subdomain is needed — **unless you
  have switched on the hostname model (§4)**, where `X-Tenant` is ignored and the tenant must
  come from the host you request.

---

## Audit-log immutability

Convention #5 is enforced by database triggers on `audit_log` (`audit_log_no_update`,
`audit_log_no_delete`), created by the migration on MariaDB/MySQL and SQLite.

This replaced a table-level `REVOKE` that **could never have worked**: MySQL/MariaDB cannot
subtract a table privilege from the database-level grant that
`MARIADB_USER`/`MARIADB_DATABASE` creates, so it failed with *"There is no such grant
defined"* — and on a least-privilege user it raised `1142 GRANT command denied` and aborted
the entire migration run. Verify with:

```bash
docker exec et-mariadb-1 mariadb -uethr -pethr_secret ethr -e "DELETE FROM audit_log LIMIT 1;"
```

which must fail with `audit_log is append-only (ETHR convention #5)`.

---

## Health check

```bash
cd api && php artisan health:check
```
