# ETHR — Deployment Guide (v2.0)

## Pre-Deployment Readiness Checklist

Read this before the Quick Start below. These are gaps a full
production-readiness audit found (see `docs/ENTERPRISE_ROADMAP.md`) that no
amount of infrastructure setup fixes — each needs a decision or a real
external credential, not a deploy step.

| Area | Status | Action required before go-live |
|---|---|---|
| **Income tax brackets** | Proclamation 979/2016, arithmetic spot-checked | **Confirm with ERCA that 979/2016 is still the operative schedule.** If a newer proclamation raised the exempt threshold, every payslip run against stale brackets over-deducts tax — worst for the lowest earners. Brackets are effective-dated and tenant-overridable once confirmed; this is a compliance check, not a code fix. |
| **SMS delivery** (`SMS_DRIVER=ethiotelecom`) | Code complete, unit-tested (`Http::fake`) | The live gateway handshake has never run — this environment has no real EthioTelecom operator credentials. Fill in the four `ETHIOTELECOM_SMS_*` values (`config/sms.php`; there is no `SMS_API_KEY`) and send one real OTP end-to-end before relying on it. |
| **Email delivery** | Code complete, verified locally against Mailpit | The `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD` values below are placeholders. Get real SMTP/Postmark/Mailgun/SES credentials and confirm one password-reset email actually lands before go-live. |
| **Plan tier feature gating** | Partial | `PlanLimitService` enforces per-tenant employee/branch/device **count** caps only. It does not gate *features* by plan tier — a Starter tenant can still use payroll/reports/webhooks, since `Plan.features` has no enforcement code anywhere. Open if pricing depends on feature-gating by tier. |
| **Google Workspace SSO** | Missing | No code exists. `SsoProviderInterface` is SAML-shaped; Google needs OAuth2/OIDC, a different contract. Don't advertise this as available. |
| **Biometric device hardware** | Protocol-verified, hardware-unverified | Hikvision/Suprema/ZKTeco adapters are real implementations with protocol-level test coverage, but have never talked to a physical device in this environment. Test against real hardware before a customer's first device install. |
| **Payroll cost-sharing** | ~~Missing~~ **Implemented and test-covered** (corrected 2026-08-29) | **No action — this row was stale.** It has since shipped: `EmployeeCostSharing` model, `CostSharingService`, `CostSharingController` (4 routes under `/payroll/cost-sharing`), request/resource classes, the `payroll/cost-sharing` page, and migration `2026_08_25_000001_create_employee_cost_sharing_table`. It is wired into `PayrollEngine` (`getActiveObligation` → `calculateDeduction` → `applyDeduction`) and carries **38 test cases** across `CostSharingTest` (18, deduction arithmetic incl. proration and balance capping), `CostSharingApiTest` (12) and `PayrollCostSharingTest` (8). Safe to offer. |

Everything else audited this cycle — tenant isolation, scheduled report
delivery (CSV attachment), SCIM group sync, bank export, phone
canonicalization, webhook SSRF hardening — is code- and test-verified with
no open gap. See `docs/ENTERPRISE_ROADMAP.md` for the full itemized audit.

---

## System Requirements

### Minimum (up to 50 tenants, 5,000 employees)

| Resource | Requirement |
|---|---|
| OS | Ubuntu Server 22.04+ |
| CPU | 4 vCPU |
| RAM | 8 GB |
| Storage | 100 GB SSD |
| Network | 10 Mbps (Ethiopian VPS) |

### Recommended (up to 200 tenants, 20,000 employees)

| Resource | Requirement |
|---|---|
| OS | Ubuntu Server 22.04+ |
| CPU | 8 vCPU |
| RAM | 16 GB |
| Storage | 500 GB SSD |
| Network | 50 Mbps |

### Below minimum (4 vCPU / 4 GB)

Below the 8 GB minimum above, use `docker-compose.lowmem.yml` instead of
`docker-compose.prod.yml` — same application, a different container topology
sized for 4 GB: one MariaDB (no replica), one Redis (no cache split), one
Horizon worker running every queue instead of three. Read the header comment
in that file for exactly what changes and the capacity trade-off it accepts
(a payroll run can occupy half the worker pool for up to 30 minutes). It is a
standalone compose file, not an overlay — use exactly one of the two.

Every script under `scripts/` that talks to Compose (`deploy.sh`, `backup.sh`,
`restore.sh`, `rollback.sh`, `seed.sh`, `init-storage.sh`) defaults to
`docker-compose.prod.yml` but honours a `COMPOSE_FILE` override, e.g.:

```bash
COMPOSE_FILE=docker-compose.lowmem.yml ./scripts/deploy.sh
```

`scripts/setup-replication.sh` does not apply on this tier — there is no
replica to configure; skip that step entirely. `scripts/prod-build-test.sh`
has not been adapted to this topology yet (it asserts the replica and the
three worker containers by name) — do not rely on it for a lowmem smoke test
until it has.

### Software Prerequisites

| Software | Version | Purpose |
|---|---|---|
| Docker Engine | 24+ | Container runtime |
| Docker Compose | v2 | Service orchestration |
| Git | 2.30+ | Code deployment |
| Certbot | Latest | SSL certificate management |

---

## Quick Start (First-Time Setup)

```bash
# 1. Clone repository
git clone https://github.com/your-org/ethr.git /opt/ethr
cd /opt/ethr

# 2. Copy environment files — TWO files, and only these two.
#    Root .env resolves ${VAR} inside docker-compose.prod.yml (DB/Redis/MinIO
#    credentials and the NEXT_PUBLIC_* frontend BUILD args).
#    api/.env.production is injected into api, worker-*, scheduler and reverb.
#    Both are gitignored; the .example files are the committed templates.
cp .env.production.example .env
cp api/.env.production.example api/.env.production

# 3. Edit both (see Environment Variables below).
#    DB_PASSWORD, DB_READ_PASSWORD, REDIS_PASSWORD, MINIO_ACCESS_KEY and
#    MINIO_SECRET_KEY appear in BOTH files and nothing keeps them in sync —
#    a mismatch boots cleanly and then fails every connection.
nano .env
nano api/.env.production
chmod 600 .env api/.env.production

#    There is no src/.env.local in production: the Next.js image bakes every
#    NEXT_PUBLIC_* at BUILD time from the root .env build args above.

# 4. Generate the application key
#    --show, then paste. A bare `key:generate` writes to a .env INSIDE the
#    container — api/Dockerfile.prod excludes api/.env*, and the container is
#    thrown away by --rm — so the key never reaches the file the stack reads.
docker compose -f docker-compose.prod.yml run --rm api php artisan key:generate --show
nano api/.env.production                 # paste into APP_KEY=

# 5. Start all services
docker compose -f docker-compose.prod.yml up -d

# 6. Set up primary -> replica replication
#    Must come BEFORE any migration. docker-compose.prod.yml sets
#    DB_READ_HOST=mariadb-replica on the api service, so Laravel's read/write
#    split is live from the very first boot — and `migrate` opens with a read
#    (an information_schema probe) that goes to the replica. Until this step has
#    run, that replica holds no schema, and step 7 dies with
#    "Access denied for user ... Host: mariadb-replica".
./scripts/setup-replication.sh

# 7. Create the MinIO bucket
#    MinIO does not create buckets on demand. Skipping this leaves every upload
#    and payslip write failing, and the health endpoint reports
#    "storage":"unavailable" while still returning HTTP 200 — so neither the
#    deploy script nor a load balancer will flag it.
./scripts/init-storage.sh

# 8. Run database migrations
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force

# 9. Seed system data (plans, permissions, templates)
docker compose -f docker-compose.prod.yml exec api php artisan db:seed --class=ProductionSeeder --force

# 10. Create super admin account
docker compose -f docker-compose.prod.yml exec api php artisan ethr:create-admin

# 11. Set up SSL — see "SSL Setup (Let's Encrypt)" below and follow it there.
#     Do NOT use `certbot --nginx`: it cannot issue the wildcard this platform
#     needs (Let's Encrypt validates `*.` names over dns-01 only), and it cannot
#     edit a containerised nginx that is already holding ports 80/443. Issuance
#     is `certbot certonly` with a DNS plugin; the container reads the result
#     from the host's /etc/letsencrypt bind mount.
sudo certbot certonly --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/dns-credentials.ini \
  -d ethr.et -d '*.ethr.et' --non-interactive --agree-tos -m ops@ethr.et

# 12. Verify health — note /api/v1/, not /api/
curl https://ethr.et/api/v1/health
```

> **Verifying a release before it ships.** `./scripts/prod-build-test.sh` builds
> the production images and boots the full stack under an isolated project name,
> then asserts migrations, seeders, replication, the read/write split and the
> health matrix. It runs unattended and tears itself down, and it does not touch
> a running dev or e2e stack. Run it after any change to a Dockerfile, to
> `docker-compose.prod.yml`, or to anything under `docker/`.

---

## Docker Compose Services

### Production Configuration (`docker-compose.prod.yml`)

`docker-compose.prod.yml` is the authority; read it directly. This section used
to inline a full copy of it, which drifted until it described a stack that had
never been deployed — wrong volume names, wrong build contexts, a
`frontend-build` volume that does not exist, and no read replica. What follows
is a map, not a duplicate.

| Service | Role | Limits | Notes |
|---|---|---|---|
| `nginx` | TLS termination, static assets, routing | 1 cpu / 256M | Only service on the public `ethr` network |
| `api` | PHP-FPM, the Laravel application | 2 cpu / 1G | `replicas: 1` — see below |
| `worker-realtime` | Queues: attendance, devices, sync | 2 cpu / 2G | `horizon --environment=production-realtime` |
| `worker-notifications` | Queues: notifications, mail, sms, default | 1 cpu / 1.5G | `horizon --environment=production-notifications` |
| `worker-heavy` | Queues: payroll, exports | 2 cpu / 2.25G | `horizon --environment=production-heavy` |
| `scheduler` | `schedule:run` every 60s | 0.5 cpu / 256M | Singleton by `container_name`; never scale it |
| `reverb` | WebSocket server | 0.5 cpu / 256M | `replicas: 1` — see below |
| `frontend` | Next.js standalone server | 1 cpu / 512M | `NEXT_PUBLIC_*` are baked in at build time |
| `mariadb` | Primary database | 2 cpu / 2G | |
| `mariadb-replica` | Read replica | 2 cpu / 1G | Needs `scripts/setup-replication.sh` once |
| `redis` | Queue, sessions, cache locks, Horizon, Reverb pub/sub | 1 cpu / 768M | `noeviction` + AOF — nothing here is safe to drop |
| `redis-cache` | Application cache only | 1 cpu / 640M | `allkeys-lru`, no persistence |
| `minio` | S3-compatible object storage | 1 cpu / 512M | |

Note that the `memory` values are *limits*, not reservations — they cap a
misbehaving service, and their sum deliberately exceeds host RAM. Only `api`,
`mariadb` and `mariadb-replica` declare reservations (512M each).

#### Three worker containers, not one

`config/horizon.php` defines six supervisors totalling 23 processes. Running
all of them in one container put `supervisor-payroll`'s 1800-second jobs in the
same cgroup as `supervisor-attendance`, whose whole purpose is the 30-second
wait threshold in that same file — so a month-end payroll run delayed live
check-ins, and the combined process ceiling far exceeded the container's memory
limit.

Each supervisor now declares an explicit per-worker `memory`, and each
container's limit is derived from it (supervisor memory x maxProcesses, plus
~256 MB for the master supervisor process):

| container | ceiling | limit | slack |
|---|---|---|---|
| `worker-realtime` | 6x128 + 4x192 + 256 = 1792 MB | 2048 MB | 256 MB |
| `worker-notifications` | 5x128 + 3x128 + 256 = 1280 MB | 1536 MB | 256 MB |
| `worker-heavy` | 3x384 + 2x256 + 256 = 1920 MB | 2304 MB | 384 MB |

Change one and you must change the other: Docker OOM-kills the container before
Horizon's per-worker cap can recycle anything, so a limit below its ceiling
kills the container instead of one worker. Note that `horizon.memory_limit`
(256) is **not** that cap — it applies to the master supervisor process alone
and only logs when exceeded. Reading it as a worker limit is what mis-sized
these containers originally; supervisors that omit `memory` silently run at
Horizon's 128 MB default, which payroll had been doing.

Each worker selects its supervisor set with `--environment`. **A name that
matches no key in `config/horizon.php` starts cleanly and processes nothing** —
Horizon returns silently rather than erroring. Each worker's healthcheck greps
`horizon:supervisors` for a supervisor unique to it, which is what turns that
silence into an unhealthy container. Rename a Horizon environment and you must
change three places: the config key, the `--environment` flag, and the grep.

#### Two Redis containers, not one

`maxmemory-policy` is instance-wide and does not respect the database split, so
a single instance serving both cache and queue can only ever have a policy that
is wrong for one of them. `redis` runs `noeviction` (queue jobs, sessions, cache
locks — none of it has a TTL that makes dropping it safe, so a full instance
must fail writes loudly); `redis-cache` runs `allkeys-lru` (derived data that
regenerates on a miss). Only the `cache` connection moves, via
`REDIS_CACHE_HOST` — note that Laravel keeps cache *locks* on the `default`
connection, so every `withoutOverlapping()` mutex stays on the durable instance.

**Upgrading an existing deployment — flush the old cache database once.** Before
the split, the cache lived in database 1 of the `redis` instance. After it,
nothing reads those keys, but they still occupy memory there — and that instance
now runs `noeviction`, so they will never be reclaimed and count permanently
against its 512 MB `maxmemory`. A large enough stale cache will make `redis`
start refusing writes, which means refusing to enqueue jobs. Run once, after the
first deploy that includes the split:

```bash
docker compose -f docker-compose.prod.yml exec redis \
  redis-cli -a "$REDIS_PASSWORD" -n 1 FLUSHDB
```

`-n 1` matters: `FLUSHALL`, or `FLUSHDB` without it, would drop database 0 —
the queue, sessions and locks. Expect a cold cache for a few minutes afterwards;
that is the intended cost and nothing is lost.

#### Why `replicas: 1` on `api` and `reverb`

`infrastructure/nginx.conf` declares static upstreams (`server api:9000;`), and
nginx resolves those names **once at startup**. With two replicas, Compose DNS
returns two addresses and nginx pins to whichever it saw first: the second
container served nothing while still consuming its reservations, and `ip_hash`
on the reverb upstream was inert for the same reason. The `dc restart nginx`
step in `scripts/deploy.sh` exists to flush exactly this cache.

Genuine horizontal scaling requires `resolver 127.0.0.11 valid=10s;` plus a
variable-based `fastcgi_pass`/`proxy_pass` in nginx first. Until that is done,
raising a replica count buys nothing but memory pressure — which is why the
`API_REPLICAS` / `WORKER_REPLICAS` / `REVERB_REPLICAS` variables were removed
from `.env.production.example` rather than left as a trap.

---

## Environment Variables

Two files, both gitignored, each with a committed template:

| File | Read by | Template |
|---|---|---|
| `.env` (repo root) | `${VAR}` interpolation inside `docker-compose.prod.yml` — MariaDB/Redis/MinIO credentials and the `NEXT_PUBLIC_*` frontend **build args** | `.env.production.example` |
| `api/.env.production` | `env_file:` on `api`, `worker-*`, `scheduler`, `reverb` | `api/.env.production.example` |

The templates carry the complete variable list and the reason behind every
non-obvious value; read them there. This section used to inline both, which is
how it came to document `DB_CONNECTION=mysql`, `AWS_*` storage keys, a
`SMS_API_KEY` nothing reads, an `api/.env` no container opens and an
`src/.env.local` that production never sees. What follows is only what needs a
decision, plus the settings whose wrong value is silent.

### Must be identical in both files

`DB_PASSWORD`, `DB_READ_USERNAME`, `DB_READ_PASSWORD`, `REDIS_PASSWORD`,
`MINIO_ACCESS_KEY`, `MINIO_SECRET_KEY` — and `REVERB_APP_KEY` in
`api/.env.production` must equal `NEXT_PUBLIC_REVERB_APP_KEY` in the root `.env`.
Nothing links the two files. A mismatch starts cleanly and then fails every
connection of that kind; the Reverb pair fails only the WebSocket handshake, so
real-time features die while the REST API looks perfectly healthy.

### Wrong values that fail silently

| Setting | Correct value | What a wrong value does |
|---|---|---|
| `DB_CONNECTION` | `mariadb` | Only the `mariadb` connection in `config/database.php` declares a `read` array. `mysql` parses fine, discards `DB_READ_HOST`, sends every read to the primary, and the health endpoint reports `database_read: not_configured`. |
| `APP_DOMAIN` | `ethr.et` | Unset, `ResolveTenant` falls back to counting labels and reads the apex `ethr.et` as a tenant named "ethr". Note `APP_DOMAIN=` is an empty *string*, not null — hence `App\Support\TenancyDomain`. |
| `SANCTUM_STATEFUL_DOMAINS` | `ethr.et,*.ethr.et` | Sanctum matches with `Str::is()`, and `*.ethr.et` does **not** match the bare apex. With the wildcard alone, `EncryptCookies`/`StartSession` never run for apex requests and login there cannot set a session cookie. |
| `SESSION_DOMAIN` | *(empty)* | Empty gives host-only cookies — what stops `habru.ethr.et`'s session from being sent to `woldia.ethr.et`. `.ethr.et` turns every session into a domain-wide credential. A tenant-isolation boundary, not a convenience setting. |
| `CORS_ALLOWED_ORIGINS_PATTERNS` | `#^https://[a-z0-9-]+\.ethr\.et$#` | `config/cors.php` hands this straight to `preg_match`. A glob like `https://*.ethr.et` is not a valid pattern — it errors on the delimiter and matches nothing. Invisible from a browser (nginx serves SPA and API same-origin), fatal for genuine cross-origin clients. |
| `FILESYSTEM_DISK` | `minio` | The `s3` disk reads `AWS_ACCESS_KEY_ID`/`AWS_BUCKET`/`AWS_DEFAULT_REGION`, which these files do not define, so the default disk resolves to a credential-less client and the SDK throws "A 'region' configuration value is required" at first use. `BackupTenantJob` is the one caller of the default disk. |
| Mail TLS | `MAIL_SCHEME` (or unset) | There is no `MAIL_ENCRYPTION` in Laravel 12 — `config/mail.php` reads `MAIL_SCHEME`. Leave it unset on port 587 (STARTTLS is negotiated); set `smtps` only for port 465. |
| SMS credentials | `ETHIOTELECOM_SMS_ENDPOINT` / `_USERNAME` / `_PASSWORD` / `_SENDER_ID` | `config/sms.php` reads these four. `SMS_API_KEY` is read by nothing; with `SMS_DRIVER=ethiotelecom` and no endpoint, every send fails at the gateway rather than at boot. |
| `SUPER_ADMIN_PASSWORD` | *(leave unset)* | Not part of the production path. The platform account is created interactively by `php artisan ethr:create-admin`, which never reads this variable. It is consulted only by `DatabaseSeeder` — the development seeder, which also creates a demo tenant — where it exists to refuse a known-password super admin. |

`DB_SLOW_QUERY_TIME` used to be documented here and is read by nothing; the slow
query threshold is `long_query_time` in `docker/mariadb/primary.cnf`.

Trusted proxies are deliberately not configured — see `api/bootstrap/app.php`.
nginx talks to PHP over FastCGI and already passes `REMOTE_ADDR` and `HTTPS`, so
trusting `X-Forwarded-*` would only let a client choose its own IP.

`ERROR_ALERT_EMAIL` likewise used to be documented here and is read by nothing —
there is no mail-based error alerting. Errors and failed queue jobs go to the log
channel and, when `SENTRY_LARAVEL_DSN` is set, to Sentry: unhandled exceptions
via `bootstrap/app.php`, failed queued jobs via the `Queue::failing` listener in
`AppServiceProvider`. A failed job otherwise leaves only a `failed_jobs` row and
an admin-screen count, which is how a scheduled job once failed on every run
unnoticed. Alert on that log event or on Sentry — not on a variable nothing reads.

### Frontend

**There is no production `src/.env.local`.** Next.js inlines `NEXT_PUBLIC_*` into
the browser bundle at image **build** time, so the values that matter come from
the build args in the root `.env` (`docker-compose.prod.yml` passes them to
`docker/frontend/Dockerfile`). `env_file: ./src/.env.production` on the frontend
service only reaches the Node process at runtime, which is too late for anything
`NEXT_PUBLIC_`. Changing one of these requires
`docker compose -f docker-compose.prod.yml build frontend`, not a restart —
a restart appears to succeed and changes nothing.

Set in the root `.env`: `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_APP_URL`,
`NEXT_PUBLIC_APP_NAME`, `NEXT_PUBLIC_ROOT_DOMAIN`, `NEXT_PUBLIC_REVERB_HOST`,
`NEXT_PUBLIC_REVERB_PORT`, `NEXT_PUBLIC_REVERB_SCHEME`,
`NEXT_PUBLIC_REVERB_APP_KEY`.

- `NEXT_PUBLIC_ROOT_DOMAIN` makes the hostname the tenant selector: the apex
  login form routes the browser to `https://{tenant}.ethr.et/login` rather than
  signing in on the apex (the session cookie is host-only and would not survive
  the hop), and the SPA stops sending `X-Tenant`, which the API refuses outside
  local/testing anyway. Leave it unset only for single-host local development.
- `NEXT_PUBLIC_REVERB_APP_KEY` has no default, deliberately: `src/lib/echo.ts`
  falls back to the literal `ethr-key`, so a default here would make an unset
  value look intentional while every handshake fails against the real server key.
- `NEXT_PUBLIC_WS_URL` is read by nothing — `src/lib/echo.ts` builds the
  connection from the three `REVERB` variables. It also carried the wrong path:
  Reverb's client path is `/app`, which is what `infrastructure/nginx.conf`
  proxies; `/ws` has no location and 404s.

---

## SSL Setup (Let's Encrypt)

ETHR resolves tenants as subdomains (`{tenant}.ethr.et`), so the deployment
needs a **wildcard** certificate. That constrains how the certificate can be
issued and, more importantly, how it can be *renewed*:

- **Wildcards can only be validated over dns-01.** Let's Encrypt does not
  accept http-01 for a `*.` name, so no amount of webroot configuration will
  renew the wildcard.
- **`--manual` certificates do not auto-renew.** certbot refuses to renew a
  cert obtained with `--manual` unless it was given a `--manual-auth-hook`, and
  `certbot renew --quiet` swallows the refusal. A cron job built that way looks
  healthy for 90 days and then the certificate expires — with HSTS set to
  `max-age=31536000`, every tenant is hard-down at that moment, browsers
  included, with no click-through.

Use a DNS provider plugin so renewal is unattended. Substitute the plugin for
your DNS host (`python3-certbot-dns-cloudflare`, `-dns-route53`,
`-dns-digitalocean`, …):

```bash
sudo apt install certbot python3-certbot-dns-cloudflare

# API credentials, readable only by root — certbot warns and continues on
# loose permissions, which is exactly how these tokens end up world-readable.
sudo install -m 600 /dev/null /etc/letsencrypt/dns-credentials.ini
sudo tee /etc/letsencrypt/dns-credentials.ini >/dev/null <<'EOF'
dns_cloudflare_api_token = <scoped token with DNS:Edit on ethr.et>
EOF

# Issue apex + wildcard in one certificate
sudo certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/dns-credentials.ini \
  -d ethr.et -d '*.ethr.et' \
  --non-interactive --agree-tos -m ops@ethr.et
```

Renewal then needs no flags — certbot replays the issuance parameters from
`/etc/letsencrypt/renewal/ethr.et.conf`:

```bash
# Verify unattended renewal actually works BEFORE relying on it.
# This is the step that would have caught the --manual trap.
sudo certbot renew --dry-run

# Renew twice daily (staggered, per Let's Encrypt guidance); reload nginx only
# when a certificate was actually replaced.
echo '17 3,15 * * * root certbot renew --quiet --deploy-hook "docker compose -f /opt/ethr/docker-compose.prod.yml exec -T nginx nginx -s reload"' \
  | sudo tee /etc/cron.d/certbot-renew
```

Note that the host `certbot --nginx` plugin is **not** usable here: nginx runs
inside a container whose config is bind-mounted read-only, and it holds ports
80/443, so `--nginx` cannot edit the config and `--standalone` cannot bind. The
container reads certificates from the host's `/etc/letsencrypt` mount instead.

If you ever deploy without wildcard subdomains, http-01 works via the
`./infrastructure/certbot-webroot` bind mount that `docker-compose.prod.yml`
exposes at `/var/www/certbot`:

```bash
sudo certbot certonly --webroot -w /opt/ethr/infrastructure/certbot-webroot -d ethr.et
```

---

## Operational Scripts

The scripts under `scripts/` are the authority — read them directly. This
section used to inline full copies, which drifted until they described behaviour
the real scripts never had (a `mc mirror` MinIO backup that was actually a volume
tar, `mysqldump -u root` where the script uses the container's own account, and
file names `restore.sh` cannot parse). What follows is a map, not a duplicate.

Every script honours `COMPOSE_FILE` (default `docker-compose.prod.yml`; set
`docker-compose.lowmem.yml` on the 4 GB tier) and `BACKUP_PATH`.

### Deploy (`scripts/deploy.sh`)

```bash
cd /opt/ethr && ./scripts/deploy.sh
```

Pulls, rebuilds `api`+`frontend`, `up -d`, migrates `--force`, rebuilds the
config/route/view/event caches, `horizon:terminate` so workers reload code, then
health-checks. Reads `HEALTH_URL` (default `https://localhost/api/v1/health`,
hit with `-k`) with 10 retries. Auto-rollback is opt-in — `ROLLBACK_ON_FAIL=true`
invokes `rollback.sh` on a failed health check; leave it off until the check is
trusted, since a false negative reverts a good release. `DEPLOY_NOTIFY_EMAIL`
mails on failure if `mail` is present.

### Backup (`scripts/backup.sh`)

```bash
./scripts/backup.sh          # writes to $BACKUP_PATH, default /var/backups/ethr
```

Runs `umask 077` (every artefact holds secrets), then writes three files per run:

- `ethr_backup_<ts>_db.sql.gz` — `mysqldump --single-transaction --routines
  --triggers`, run as the container's `$MARIADB_USER` so the host never handles
  DB credentials
- `ethr_backup_<ts>_files.tar.gz` — the MinIO data volume via
  `--volumes-from ethr-minio` (no `mc`, no keys, project-name independent)
- `ethr_backup_<ts>.env` — a `chmod 600` copy of `api/.env.production`

Verifies the dump with `gunzip -t`, prunes past `BACKUP_RETENTION_DAYS`
(default 30). `BACKUP_NOTIFY_EMAIL` mails on failure.

### Restore (`scripts/restore.sh`)

```bash
./scripts/restore.sh                                 # lists available backups
./scripts/restore.sh ethr_backup_20260828_120000     # restore by NAME, not path
```

Takes the backup **name** (no `_db.sql.gz` suffix), not a file path. Verifies
integrity, stops the workers and scheduler, restores the database, then the
MinIO volume and `api/.env.production` if their artefacts are present, rebuilds
caches, and restarts services. Reads from the same `$BACKUP_PATH` as backup — if
you customise it for one, set it for both.

### Rollback (`scripts/rollback.sh`)

```bash
./scripts/rollback.sh 1       # arg = migration steps to undo (default 1)
```

The argument is the number of `migrate:rollback --step` migrations to undo
(default 1); the script then reverts the code to the previous commit and
re-runs a health check. Invoked automatically by `deploy.sh` when
`ROLLBACK_ON_FAIL=true`.

---

## Monitoring

### Health Endpoint

`GET /api/v1/health` returns a flat `services` map — one string per service, no
timings, no queue depths, no disk or memory checks:

```json
{
  "status": "healthy",
  "services": {
    "database": "healthy",
    "database_read": "healthy",
    "cache": "healthy",
    "queue": "healthy",
    "storage": "healthy",
    "api": "healthy"
  },
  "timestamp": "2026-07-15T10:30:00Z",
  "version": "1.0.0"
}
```

Read the values carefully — they are not interchangeable, and the HTTP status
follows only one of them:

- `unhealthy` (database, database_read) → `status: degraded` and **HTTP 503**.
- `unavailable` (cache, queue, storage) → `status: healthy` and **HTTP 200**.
  A missing MinIO bucket, a Redis that refuses writes, or a broken queue
  connection all report this. Neither `scripts/deploy.sh` nor a load balancer
  watching the status code will notice. Alert on the body, not the code.
- `database_read: not_configured` means `config('database.connections.mariadb.read')`
  is empty — usually `DB_CONNECTION=mysql`, or `DB_READ_HOST` not reaching the
  container. The replica is then idle and every read goes to the primary.

### Queue and worker monitoring

`/horizon` is **not exposed in production**, and two independent things stop it:
`infrastructure/nginx-common.conf` routes only `/api`, `/sanctum` and `*.php` to
PHP-FPM, so `/horizon` falls through `location /` to the Next.js container and
404s; and Horizon's default gate authorises the `local` environment only, so it
would return 403 even if routed. Use the CLI on any worker container instead:

```bash
dcp() { docker compose -f docker-compose.prod.yml "$@"; }

dcp exec worker-realtime php artisan horizon:status        # cluster-wide
dcp exec worker-heavy    php artisan horizon:supervisors   # per-container
dcp exec api             php artisan queue:failed
```

Failed jobs also surface on the Super Admin dashboard with retry/dismiss
actions, and every failure goes through the `Queue::failing` hook in
`AppServiceProvider` (a `Log::error`, plus Sentry when `SENTRY_LARAVEL_DSN` is
set). **Open item:** exposing the dashboard needs both an nginx `location
/horizon` pointing at `api_backend` and a `Horizon::auth` gate restricted to
super admins — it is a deliberate change to the platform's auth surface, not a
config toggle.

### Slow Query Log

`docker/mariadb/primary.cnf` (and `replica.cnf`) set `slow_query_log = 1` with
`long_query_time = 1`, writing to `/var/log/mysql/slow.log` — inside the
container, **not** under `/var/lib/mysql`, which is the only path on a volume.
The log therefore does not survive `docker compose down`; copy it out before
recreating the container if you need it.

```bash
docker compose -f docker-compose.prod.yml exec mariadb tail -f /var/log/mysql/slow.log
```

### Log Aggregation

All services log to Docker's JSON file driver with rotation:

```yaml
logging:
  driver: json-file
  options:
    max-size: "10m"
    max-file: "3"
```

View logs: `docker compose -f docker-compose.prod.yml logs -f api`

---

## Automated Backup Schedule

Add to server crontab:

```cron
# Daily backup at 2 AM EAT (23:00 UTC)
0 23 * * * /opt/ethr/scripts/backup.sh >> /var/log/ethr-backup.log 2>&1
```

One entry, not two. A second weekly `backup-minio-full.sh` line was documented
here for months; no such script exists, and cron mailed the failure to a root
mailbox nobody reads. `backup.sh` already captures MinIO in full every run — it
tars the `ethr-minio` data volume via `--volumes-from`, so there is nothing a
separate full sync would add.

No `cd` is needed — `backup.sh` relocates to the repo root itself. Set
`BACKUP_PATH` / `BACKUP_RETENTION_DAYS` / `COMPOSE_FILE` on the cron line if you
need non-defaults: the scripts read them from their own environment, and nothing
sources an env file for them, so a value written into `api/.env.production` is
inert here.

---

## Troubleshooting

### Services won't start

```bash
# Check service logs
docker compose -f docker-compose.prod.yml logs api --tail=50
docker compose -f docker-compose.prod.yml logs mariadb --tail=50

# Check disk space
df -h

# Check memory
free -h
```

### Database connection refused

```bash
# Verify MariaDB is running (root password is DB_ROOT_PASSWORD in the root .env)
docker compose -f docker-compose.prod.yml exec mariadb mysql -u root -p -e "SELECT 1"

# The two files that must agree — api/.env.production, not api/.env
grep -E '^(DB_|REDIS_PASSWORD|MINIO_)' .env api/.env.production

# Reads failing but writes fine? That is the replica, not the primary.
curl -sk https://localhost/api/v1/health | grep database_read
docker compose -f docker-compose.prod.yml exec mariadb-replica \
  mysql -u"$DB_READ_USERNAME" -p -e "SELECT 1"
```

### Queue jobs failing

```bash
# Check failed jobs
docker compose -f docker-compose.prod.yml exec api php artisan queue:failed

# Retry specific job
docker compose -f docker-compose.prod.yml exec api php artisan queue:retry {id}

# Retry all failed
docker compose -f docker-compose.prod.yml exec api php artisan queue:retry all

# Check Horizon status
docker compose -f docker-compose.prod.yml exec api php artisan horizon:status
```

### Slow performance

```bash
# Check slow query log — /var/log/mysql, not /var/lib/mysql
docker compose -f docker-compose.prod.yml exec mariadb tail -20 /var/log/mysql/slow.log

# Check Redis memory — both instances. `redis` is the one that matters under
# pressure: it runs noeviction, so a full instance starts REFUSING writes
# (queued jobs, sessions, locks) rather than degrading. redis-cache filling up
# is normal and self-correcting — allkeys-lru just evicts the coldest keys.
# Both instances require AUTH — a bare redis-cli returns NOAUTH, not a reading.
docker compose -f docker-compose.prod.yml exec redis \
  redis-cli -a "$REDIS_PASSWORD" info memory
docker compose -f docker-compose.prod.yml exec redis-cache \
  redis-cli -a "$REDIS_PASSWORD" info memory

# Check queue depth and worker health
docker compose -f docker-compose.prod.yml exec worker-realtime php artisan horizon:status
```

### SSL certificate renewal failed

```bash
# Manual renewal
sudo certbot renew --force-renewal

# Verify certificate
sudo certbot certificates

# Restart nginx
docker compose -f docker-compose.prod.yml restart nginx
```
