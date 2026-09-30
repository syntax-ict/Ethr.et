# ETHR — Local Development Setup

**Shape: the same as production.** Production is Ethio Telecom Plesk shared hosting —
Apache + PHP-FPM + MariaDB/MySQL, the `database` cache/queue/session drivers, the local
disk, no Redis, no Docker, no long-running processes. Local development uses XAMPP's
Apache, PHP and MariaDB for the same reason: a development environment that runs a
different system from the one deployed tests the wrong thing.

The Docker development stack (compose files, `docker/`, and the container-only scripts)
was **removed on 2026-09-30** by owner decision. Anything below that mentions it is
history.

Requirements: **XAMPP** (Apache 2.4, PHP 8.2+, MariaDB — installed at `C:\xampp`),
**Node 24** (`.nvmrc`), **Composer**, and **Git Bash** to run the `scripts/*.sh` files.
`composer install` needs a GitHub token — see the root [`CLAUDE.md`](../CLAUDE.md).

---

## 1. The production rehearsal — one command

```bash
scripts/local-production/up.sh           # build, assemble, migrate, start on :8081
scripts/local-production/verify.sh       # 36 checks; must end "36 passed, 0 failed"
scripts/local-production/down.sh         # stop it
```

Then open **http://localhost:8081**. `up.sh --no-build` skips the frontend build when it
has not changed.

This is a real Apache serving the Bronze static export through the rendered `.htaccess`,
with `index.php` repointed exactly as on the host, and its **own** database and user — it
refuses to run against the ones `api/.env` uses. The header of `up.sh` records what it
does and does not touch. It proves our procedure and our code; it proves **nothing** about
the Ethio Telecom host, whose gates live in
[`deployment/CUTOVER-CHECKLIST.md`](deployment/CUTOVER-CHECKLIST.md).

---

## 2. Day-to-day development

For hot reload while you work, run the API and the Next dev server natively against
XAMPP's MariaDB.

### Environment and database

```bash
cp api/.env.example api/.env      # BEFORE composer install — see the root CLAUDE.md
cd api && composer install && php artisan key:generate
```

`api/.env.example` already selects the production drivers (`database` cache, queue and
session; `FILESYSTEM_DISK=local`; `BROADCAST_CONNECTION=null`; `MAIL_MAILER=log`). Create
the `ethr` database and user in XAMPP's MariaDB (port **3306**), set `DB_PASSWORD`, then:

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

### Processes

```bash
cd api && php artisan serve --host=127.0.0.1 --port=8000
```
```bash
cd src && npm run dev
```

App at **http://localhost:3000**. Next proxies `/api` and `/sanctum` to the API, so the
browser talks to one origin and there is no CORS involved.

**Queued work only runs when something drains the queue.** Production has no worker
process: `.github/workflows/cron.yml` calls `POST /api/v1/cron/queue` and
`POST /api/v1/cron/schedule`. Locally, drain it on demand with the same queue list
(`QueueHealth::QUEUES`):

```bash
cd api && php artisan queue:work --queue=attendance,notifications,default,exports --stop-when-empty
```

and run due scheduled tasks with `php artisan schedule:run`. Do not switch to
`QUEUE_CONNECTION=sync` to avoid this — see below.

---

## Why not SQLite + `sync`

An earlier local config used `DB_CONNECTION=sqlite` and **`QUEUE_CONNECTION=sync`**. That
last one is not a harmless convenience: `sync` runs "queued" work inline inside the HTTP
request, so anything that only breaks on a real worker — no `CurrentTenant` bound, no
request context — is invisible locally. A tenant-scoped lookup that silently returns zero
rows on a worker looks perfect under `sync`. Two bugs found on 2026-08-02 had exactly that
shape.

---

## 3. Optional: run the production hostname model locally

Default local development is **single-host**: everything is `localhost:3000` and the tenant
travels in the `X-Tenant` header from `localStorage.tenant`. That is deliberate — a bare
`localhost` has no subdomain to read — but it means the model the login is actually built
around cannot be seen or manually tested on your machine. Turn it on when you are working on
authentication, tenant resolution, the admin console, or impersonation.

Browsers resolve any `*.localhost` name to 127.0.0.1 themselves (RFC 6761), so there is no
hosts file to edit and no DNS involved. Set the root domain on both halves and restart both
processes:

```bash
# api/.env
APP_DOMAIN=localhost

# src/.env.local
NEXT_PUBLIC_ROOT_DOMAIN=localhost
```

`NEXT_PUBLIC_*` is inlined into the client bundle at build time, so restart `npm run dev`.
The production rehearsal (§1) already runs this model: `admin.localhost:8081` is the
platform host and any other `*.localhost:8081` name is a tenant.

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

Turn it off by dropping the two settings and restarting.

---

## Gotchas that have each cost real time

- **Larastan reads the live dev database.** After adding a migration, run
  `php artisan migrate` *and* `vendor/bin/phpstan clear-result-cache`, or PHPStan reports a
  wall of false positives on the new model.
- **Check the collected test count, not only the colour.** PHP's recursive directory scan
  once returned incomplete results (over a Docker Desktop bind mount): `pest` collected
  **22 of 132 test classes** and exited 0 green (measured 2026-08-21). `scripts/gates.sh`
  compares the collected count against `find` and fails loudly on an undercount. Keep the
  checkout on a plain local disk. The DomPDF payslip tests need `memory_limit=-1`, which
  `gates.sh` passes.
- **Run the gates with `./scripts/gates.sh`** — Pint, PHPStan, Pest, i18n, Prettier, ESLint,
  tsc, Vitest, docs links, the static export and the API contract, on native PHP and Node.
  If `npx` misbehaves, `node node_modules/typescript/bin/tsc --noEmit` and
  `node node_modules/vitest/vitest.mjs run` work directly.
- **Only one Next dev server per project** (shared `.next`); a second starts and dies.
- **Driving the UI programmatically:** the React-Hook-Form login cannot be filled by
  injected values. Hit `/sanctum/csrf-cookie`, then `POST /api/v1/auth/login` with **both**
  `X-Tenant` and `X-XSRF-TOKEN` (omitting either gives 419). Tenancy rides the `X-Tenant`
  header from `localStorage.tenant` on localhost, so no subdomain is needed — **unless you
  have switched on the hostname model (§3)**, where `X-Tenant` is ignored and the tenant must
  come from the host you request.
- **Real-time features:** `BROADCAST_CONNECTION=null` is the default because production has
  no WebSocket server. To work on them, set `reverb` and run `php artisan reverb:start`;
  with `reverb` selected and no server running, every broadcast throws and a successful
  write returns 500.

---

## Audit-log immutability

Convention #5 is enforced by database triggers on `audit_log` (`audit_log_no_update`,
`audit_log_no_delete`), created by the migration on MariaDB/MySQL and SQLite.

This replaced a table-level `REVOKE` that **could never have worked**: MySQL/MariaDB cannot
subtract a table privilege from a database-level grant, so it failed with *"There is no such
grant defined"* — and on a least-privilege user it raised `1142 GRANT command denied` and
aborted the entire migration run. Verify with XAMPP's client:

```bash
/c/xampp/mysql/bin/mysql.exe -u ethr -p ethr -e "DELETE FROM audit_log LIMIT 1;"
```

which must fail with `audit_log is append-only (ETHR convention #5)`.

---

## Health check

```bash
cd api && php artisan health:check
```
