# ETHR — Deployment Guide (v2.0)

## Pre-Deployment Readiness Checklist

Read this before the Quick Start below. These are gaps a full
production-readiness audit found (see `docs/ENTERPRISE_ROADMAP.md`) that no
amount of infrastructure setup fixes — each needs a decision or a real
external credential, not a deploy step.

| Area | Status | Action required before go-live |
|---|---|---|
| **Income tax brackets** | Proclamation 979/2016, arithmetic spot-checked | **Confirm with ERCA that 979/2016 is still the operative schedule.** If a newer proclamation raised the exempt threshold, every payslip run against stale brackets over-deducts tax — worst for the lowest earners. Brackets are effective-dated and tenant-overridable once confirmed; this is a compliance check, not a code fix. |
| **SMS delivery** (`SMS_DRIVER=ethiotelecom`) | Code complete, unit-tested (`Http::fake`) | The live gateway handshake has never run — this environment has no real EthioTelecom operator credentials. Get a real `SMS_API_KEY` and send one real OTP end-to-end before relying on it. |
| **Email delivery** | Code complete, verified locally against Mailpit | The `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD` values below are placeholders. Get real SMTP/Postmark/Mailgun/SES credentials and confirm one password-reset email actually lands before go-live. |
| **Plan tier feature gating** | Partial | `PlanLimitService` enforces per-tenant employee/branch/device **count** caps only. It does not gate *features* by plan tier — a Starter tenant can still use payroll/reports/webhooks, since `Plan.features` has no enforcement code anywhere. Open if pricing depends on feature-gating by tier. |
| **Google Workspace SSO** | Missing | No code exists. `SsoProviderInterface` is SAML-shaped; Google needs OAuth2/OIDC, a different contract. Don't advertise this as available. |
| **Biometric device hardware** | Protocol-verified, hardware-unverified | Hikvision/Suprema/ZKTeco adapters are real implementations with protocol-level test coverage, but have never talked to a physical device in this environment. Test against real hardware before a customer's first device install. |
| **Payroll cost-sharing** | Missing | Zero implementation — not a stub, doesn't exist. Don't offer it. |

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

# 2. Copy environment files
cp .env.example .env                     # docker-compose secrets (DB/Redis/MinIO passwords)
cp api/.env.production api/.env
cp src/.env.production src/.env.local

# 3. Edit environment files (see Environment Variables below)
#    Passwords in .env and api/.env must match (REDIS_PASSWORD, DB_PASSWORD, MinIO keys).
nano .env
nano api/.env
nano src/.env.local

# 4. Generate application key
docker compose -f docker-compose.prod.yml run --rm api php artisan key:generate

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
from `.env.example` rather than left as a trap.

---

## Environment Variables

### API (`api/.env`)

```bash
# Application
APP_NAME=ETHR
APP_ENV=production
APP_DEBUG=false
APP_KEY=                          # CHANGE: php artisan key:generate
APP_URL=https://ethr.et
APP_TIMEZONE=UTC

# Database
DB_CONNECTION=mysql
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=ethr
DB_USERNAME=ethr
DB_PASSWORD=                      # CHANGE: strong random password
DB_ROOT_PASSWORD=                 # CHANGE: strong random password

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null

# MinIO (S3-compatible)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=                # CHANGE
AWS_SECRET_ACCESS_KEY=            # CHANGE
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=ethr
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true

# Mail
MAIL_MAILER=smtp
MAIL_HOST=                        # CHANGE: SMTP server
MAIL_PORT=587
MAIL_USERNAME=                    # CHANGE
MAIL_PASSWORD=                    # CHANGE
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@ethr.et
MAIL_FROM_NAME=ETHR

# Queue
QUEUE_CONNECTION=redis
HORIZON_PREFIX=ethr_horizon:

# Session
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# Reverb (WebSocket)
REVERB_APP_ID=ethr
REVERB_APP_KEY=                   # CHANGE: random string
REVERB_APP_SECRET=                # CHANGE: random string
REVERB_HOST=reverb
REVERB_PORT=8080

# Tenancy root domain — REQUIRED in production.
# Tells ResolveTenant which host is the apex and which is a tenant. Without it
# the middleware falls back to counting labels, which cannot distinguish
# `ethr.et` (apex, no tenant) from `acme.com` (would be read as tenant "acme"),
# and gets a multi-label TLD such as `ethr.co.uk` wrong outright.
APP_DOMAIN=ethr.et

# Sanctum
# BOTH entries are required. Sanctum matches with Str::is(), and `*.ethr.et`
# does NOT match the bare apex `ethr.et` — with the wildcard alone, requests
# from https://ethr.et are not treated as stateful, so EncryptCookies /
# StartSession never run and login on the apex cannot set a session cookie.
SANCTUM_STATEFUL_DOMAINS=ethr.et,*.ethr.et

# CORS — required, and easy to miss. config/cors.php defaults to
# http://localhost:3000 with supports_credentials=true, so leaving these unset
# blocks every tenant browser in production. The pattern covers both tenant
# subdomains and admin.ethr.et.
CORS_ALLOWED_ORIGINS=https://ethr.et
CORS_ALLOWED_ORIGINS_PATTERNS=https://*.ethr.et

# Session cookies
# Leave SESSION_DOMAIN EMPTY. An empty value gives host-only cookies, which is
# what stops habru.ethr.et's session from ever being sent to woldia.ethr.et.
# Setting it to `.ethr.et` would "fix" cross-subdomain flows by turning every
# user's session into a domain-wide credential — do not.
SESSION_DOMAIN=
SESSION_SECURE_COOKIE=true

# Super admin — REQUIRED in production.
# The seeder refuses to run without it rather than create a platform account,
# which bypasses every permission check, with a known password.
SUPER_ADMIN_PASSWORD=              # CHANGE: strong random secret

# Trusted proxies are deliberately NOT configured — see api/bootstrap/app.php.
# nginx talks to PHP over FastCGI and already passes REMOTE_ADDR and HTTPS, so
# trusting X-Forwarded-* would only let a client choose its own IP.

# SMS
SMS_DRIVER=ethiotelecom           # or 'log' for testing
SMS_API_KEY=                      # CHANGE (production only)

# Error Alerting
#
# ERROR_ALERT_EMAIL used to be documented here and is read by nothing — there is
# no mail-based error alerting in the codebase. Errors and failed queue jobs go
# to the log channel and, when SENTRY_LARAVEL_DSN is set, to Sentry:
#   - unhandled exceptions via bootstrap/app.php
#   - failed queued jobs via the Queue::failing listener in AppServiceProvider
#
# A failed job otherwise leaves only a `failed_jobs` row and an admin-screen
# count, which is how a scheduled job once failed on every run unnoticed. If you
# want paging, alert on that log event or on Sentry — not on a variable that
# nothing reads.

# Slow Query Log
DB_SLOW_QUERY_TIME=1000           # Log queries > 1 second
```

### Frontend (`src/.env.local`)

```bash
NEXT_PUBLIC_API_URL=https://ethr.et/api/v1

# WebSocket (Reverb).
#
# NEXT_PUBLIC_WS_URL used to be documented here and is NOT read by anything —
# src/lib/echo.ts builds the connection from the three variables below. It also
# carried the wrong path: Reverb's client path is /app, which is what
# infrastructure/nginx.conf proxies; /ws has no location and 404s.
NEXT_PUBLIC_REVERB_HOST=ethr.et
NEXT_PUBLIC_REVERB_PORT=443
NEXT_PUBLIC_REVERB_SCHEME=https
NEXT_PUBLIC_REVERB_APP_KEY=          # must match REVERB_APP_KEY in the API env

NEXT_PUBLIC_APP_NAME=ETHR
NEXT_PUBLIC_DEFAULT_LOCALE=en

# Makes the hostname the tenant selector. With this set, the apex login form
# routes the browser to https://{tenant}.ethr.et/login rather than signing in
# on the apex (the session cookie is host-only and would not survive the hop),
# and the SPA stops sending X-Tenant — which the API refuses outside
# local/testing anyway. Leave unset only for single-host local development.
NEXT_PUBLIC_ROOT_DOMAIN=ethr.et
```

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

### Deploy (`scripts/deploy.sh`)

```bash
#!/bin/bash
set -e

cd /opt/ethr
echo "$(date): Starting deployment..."

# Pull latest code
git pull origin main

# Build and restart services
docker compose -f docker-compose.prod.yml build --no-cache api frontend
docker compose -f docker-compose.prod.yml up -d

# Run migrations
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force

# Clear and rebuild caches
docker compose -f docker-compose.prod.yml exec api php artisan config:cache
docker compose -f docker-compose.prod.yml exec api php artisan route:cache
docker compose -f docker-compose.prod.yml exec api php artisan view:cache
docker compose -f docker-compose.prod.yml exec api php artisan event:cache

# Restart queue workers (pick up new code)
docker compose -f docker-compose.prod.yml exec api php artisan horizon:terminate

# Health check
sleep 10
curl -sf https://ethr.et/api/v1/health || { echo "HEALTH CHECK FAILED"; exit 1; }

echo "$(date): Deployment complete."
```

### Backup (`scripts/backup.sh`)

```bash
#!/bin/bash
set -e

BACKUP_DIR="/opt/backups/ethr"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

# Database backup
docker compose -f /opt/ethr/docker-compose.prod.yml exec -T mariadb \
  mysqldump -u root -p"$DB_ROOT_PASSWORD" ethr | gzip > "$BACKUP_DIR/db_$TIMESTAMP.sql.gz"

# MinIO backup (sync to local)
docker compose -f /opt/ethr/docker-compose.prod.yml exec -T minio \
  mc mirror /data "$BACKUP_DIR/minio_$TIMESTAMP/" --quiet

# Keep last 30 days of backups
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +30 -delete
find $BACKUP_DIR -name "minio_*" -type d -mtime +30 -exec rm -rf {} +

echo "$(date): Backup complete: $BACKUP_DIR"
```

### Restore (`scripts/restore.sh`)

```bash
#!/bin/bash
set -e

BACKUP_FILE=$1
if [ -z "$BACKUP_FILE" ]; then
  echo "Usage: ./restore.sh /path/to/db_TIMESTAMP.sql.gz"
  exit 1
fi

echo "WARNING: This will overwrite the current database. Continue? (yes/no)"
read CONFIRM
[ "$CONFIRM" != "yes" ] && exit 1

# Stop workers
docker compose -f /opt/ethr/docker-compose.prod.yml exec api php artisan horizon:terminate

# Restore database
gunzip -c "$BACKUP_FILE" | docker compose -f /opt/ethr/docker-compose.prod.yml exec -T mariadb \
  mysql -u root -p"$DB_ROOT_PASSWORD" ethr

# Restart services
docker compose -f /opt/ethr/docker-compose.prod.yml restart api scheduler \
  worker-realtime worker-notifications worker-heavy

echo "$(date): Restore complete."
```

---

## Monitoring

### Health Endpoint

`GET /api/v1/health` returns:

```json
{
  "status": "healthy",
  "checks": {
    "database": { "status": "up", "response_time_ms": 2 },
    "redis": { "status": "up", "response_time_ms": 1 },
    "minio": { "status": "up", "response_time_ms": 15 },
    "queue": { "status": "up", "depth": { "attendance": 0, "payroll": 0, "default": 3 } },
    "disk": { "status": "up", "used_percent": 45 },
    "memory": { "status": "up", "used_percent": 62 }
  },
  "version": "1.0.0",
  "timestamp": "2026-07-15T10:30:00Z"
}
```

### Horizon Dashboard

Access at: `https://admin.ethr.et/horizon` (super admin only)

Monitors: queue depth, job throughput, failed jobs, worker status.

### Slow Query Log

Enabled in MariaDB: queries > 1 second logged to `mariadb-data/slow-query.log`.

Review: `docker compose exec mariadb tail -f /var/lib/mysql/slow-query.log`

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

# Weekly full MinIO sync on Sunday
0 1 * * 0 /opt/ethr/scripts/backup-minio-full.sh >> /var/log/ethr-backup.log 2>&1
```

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
# Verify MariaDB is running
docker compose -f docker-compose.prod.yml exec mariadb mysql -u root -p -e "SELECT 1"

# Check credentials match .env
grep DB_ api/.env
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
# Check slow query log
docker compose -f docker-compose.prod.yml exec mariadb tail -20 /var/lib/mysql/slow-query.log

# Check Redis memory — both instances. `redis` is the one that matters under
# pressure: it runs noeviction, so a full instance starts REFUSING writes
# (queued jobs, sessions, locks) rather than degrading. redis-cache filling up
# is normal and self-correcting — allkeys-lru just evicts the coldest keys.
docker compose -f docker-compose.prod.yml exec redis redis-cli info memory
docker compose -f docker-compose.prod.yml exec redis-cache redis-cli info memory

# Check PHP-FPM status
docker compose -f docker-compose.prod.yml exec api php-fpm-healthcheck

# Check queue depth
docker compose -f docker-compose.prod.yml exec api php artisan horizon:status
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
