# ETHR — VPS Deployment Runbook (91.99.81.71 / ethr.et)

> **DEPRECATED — but deliberately kept. Do not delete yet.**
>
> The production target is Ethio Telecom Linux shared hosting under Plesk
> (owner decision 2026-08-29, "NO VPS"). Nothing here describes the target
> platform, and no new work should follow it.
>
> It is retained for two reasons:
>
> 1. **It is the rollback path.** Master plan §60 keeps VPS production assets
>    until the Plesk cutover has actually succeeded and been observed. Deleting
>    the only server-specific runbook before then removes the fallback at
>    precisely the moment it might be needed.
> 2. **It carries corrections that are not written down anywhere else** —
>    `27f2f0b` (backup directory and restore usage) and `f20c169` (the stale
>    `/api/health` path, which is actually `/api/v1/health`). Those were learned
>    from a real first deploy, and the same class of mistake is waiting on
>    Plesk.
>
> This file was staged for deletion in the working tree with no successor and
> was restored during Phase 1 (`docs/audit/BASELINE.md` §1). Its replacement,
> `docs/deployment/PLESK_DEPLOYMENT.md`, cannot be written until Gate 0 has
> measured the host — see `docs/deployment/GATE-0-RESULT.md`. Writing it from
> assumption is what this whole sequence exists to avoid.
>
> **Retire it when:** the Plesk cutover is verified, the rollback rehearsal has
> been performed, and the observation period in master plan §60 has passed.
>
> **The inventory that condition governs is in
> [`deployment/VPS-DECOMMISSION.md`](deployment/VPS-DECOMMISSION.md)** — including the five
> assets that look like VPS files and must not be removed, two of which the *Plesk* target
> depends on. This file goes last of all of them.

The concrete, copy-pasteable procedure for this one server. `docs/DEPLOYMENT.md`
is the reference manual — it explains *why* each piece is shaped the way it is.
This is the *order of operations*, with the traps that actually break a first
deploy called out where you will hit them.

| | |
|---|---|
| **Server** | `91.99.81.71` |
| **Domain** | `ethr.et` (apex + `admin.ethr.et` + `*.ethr.et` tenants) |
| **Deploy path** | `/opt/ethr` |
| **Health endpoint** | `https://ethr.et/api/v1/health` — note `/v1/` |

`infrastructure/nginx.conf` already hardcodes `ethr.et` in all four server
blocks, and `.env.production.example` already defaults every `NEXT_PUBLIC_*` to
it. **You do not need to edit either file.** The only things you supply are
secrets, a DNS API token, and the certificate.

---

## Step 0 — Preflight (do this before touching the server)

### 0.1 DNS must resolve first

TLS issuance and tenant routing both depend on DNS being live. Point these at
the server, then wait for propagation before continuing:

| Record | Type | Value |
|---|---|---|
| `ethr.et` | A | `91.99.81.71` |
| `*.ethr.et` | A | `91.99.81.71` |
| `admin.ethr.et` | A | `91.99.81.71` |

`admin.ethr.et` is listed explicitly on purpose. It *would* match the wildcard,
but nginx prefers an exact `server_name` over a wildcard — an explicit record
keeps DNS and nginx agreeing about which host is which.

Verify from your workstation, not the server:

```bash
dig +short ethr.et            # expect 91.99.81.71
dig +short admin.ethr.et      # expect 91.99.81.71
dig +short anything.ethr.et   # expect 91.99.81.71 (proves the wildcard)
```

Do not proceed until all three answer. A wildcard that has not propagated is
the single most common cause of a failed first deploy — certbot fails, and you
spend an hour debugging Docker.

### 0.2 Pick your compose file — this decides everything downstream

```bash
ssh root@91.99.81.71 'free -g | awk "/^Mem:/{print \$2\" GB RAM\"}"; nproc'
```

| RAM | Use | Notes |
|---|---|---|
| **8 GB+** | `docker-compose.prod.yml` | Full topology: replica, split Redis, 3 workers |
| **4–8 GB** | `docker-compose.lowmem.yml` | No replica, one Redis, one worker |

Every script under `scripts/` honours a `COMPOSE_FILE` override. If you are on
the lowmem tier, prefix **every** command below:

```bash
export COMPOSE_FILE=docker-compose.lowmem.yml
```

and **skip Step 5 entirely** — there is no replica to configure. Also note
`scripts/prod-build-test.sh` asserts the replica and the three worker
containers by name, so it does not apply to the lowmem topology.

The rest of this runbook assumes the **8 GB+ full topology**.

---

## Step 1 — Server preparation

```bash
ssh root@91.99.81.71

apt update && apt upgrade -y
apt install -y docker.io docker-compose-v2 git certbot

systemctl enable --now docker
docker --version && docker compose version   # need Engine 24+, Compose v2
```

### Swap — do not skip this on an 8 GB box

The three worker containers alone declare a 5.8 GB ceiling. Without swap, a
month-end payroll run and a MariaDB buffer-pool spike at the same moment gets
something OOM-killed, and because every service gates on `mariadb-replica`
being healthy, one kill can cascade into a full stack outage.

```bash
fallocate -l 4G /swapfile && chmod 600 /swapfile
mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
sysctl -w vm.swappiness=10 && echo 'vm.swappiness=10' >> /etc/sysctl.d/99-ethr.conf
```

### Firewall

Only 80/443 and SSH face the internet. Every database, Redis, MinIO and
PHP-FPM port lives on the internal Compose network and publishes nothing.

```bash
ufw default deny incoming && ufw default allow outgoing
ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp
ufw --force enable && ufw status verbose
```

> If this is a Hetzner box, the cloud firewall in the console is a *second*
> layer and defaults to permissive. Mirror these rules there too, or skip the
> cloud firewall entirely — but know which one you are relying on.

---

## Step 2 — Clone

```bash
git clone https://github.com/syntax-ict/Ethr.et.git /opt/ethr
cd /opt/ethr
```

---

## Step 3 — Generate secrets (one block, both files, guaranteed in sync)

**This is where first deploys usually go wrong.** There are two independent env
files and Docker links them for you in exactly zero places:

- **`/opt/ethr/.env`** — resolves `${VAR}` inside `docker-compose.prod.yml`
  (MariaDB/Redis/MinIO credentials, plus the `NEXT_PUBLIC_*` **build args**).
- **`/opt/ethr/api/.env.production`** — injected into the `api`, `worker-*`,
  `scheduler` and `reverb` containers.

The same passwords must appear in both. Editing them by hand is how you get a
stack that boots and then fails every database read. The block below generates
each secret once and writes it to both files:

```bash
cd /opt/ethr
cp .env.production.example .env
cp api/.env.production.example api/.env.production

# Both targets are gitignored; the .example files are the committed templates
# and stay pristine, so you can always diff against them or start over.

# Generate once, reuse everywhere
DB_ROOT_PASSWORD=$(openssl rand -hex 24)
DB_PASSWORD=$(openssl rand -hex 24)
DB_READ_PASSWORD=$(openssl rand -hex 24)
REDIS_PASSWORD=$(openssl rand -hex 24)
MINIO_ACCESS_KEY=$(openssl rand -hex 12)
MINIO_SECRET_KEY=$(openssl rand -hex 24)
REVERB_APP_KEY=$(openssl rand -hex 16)
REVERB_APP_SECRET=$(openssl rand -hex 16)

# No SUPER_ADMIN_PASSWORD here. `ethr:create-admin` in Step 6 does not read that
# variable — it prompts — and generating one produces a password that unlocks
# nothing. It is consulted only by DatabaseSeeder, the development seeder that
# also creates a demo tenant and must never run against this host.

# --- root .env (compose variable resolution + frontend build args) ---
sed -i \
  -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" \
  -e "s|^DB_READ_PASSWORD=.*|DB_READ_PASSWORD=$DB_READ_PASSWORD|" \
  -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$REDIS_PASSWORD|" \
  -e "s|^MINIO_ACCESS_KEY=.*|MINIO_ACCESS_KEY=$MINIO_ACCESS_KEY|" \
  -e "s|^MINIO_SECRET_KEY=.*|MINIO_SECRET_KEY=$MINIO_SECRET_KEY|" \
  .env

# --- api/.env.production (application runtime) ---
sed -i \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" \
  -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD|" \
  -e "s|^DB_READ_PASSWORD=.*|DB_READ_PASSWORD=$DB_READ_PASSWORD|" \
  -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$REDIS_PASSWORD|" \
  -e "s|^MINIO_ACCESS_KEY=.*|MINIO_ACCESS_KEY=$MINIO_ACCESS_KEY|" \
  -e "s|^MINIO_SECRET_KEY=.*|MINIO_SECRET_KEY=$MINIO_SECRET_KEY|" \
  -e "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=$REVERB_APP_KEY|" \
  -e "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=$REVERB_APP_SECRET|" \
  api/.env.production

# The SAME Reverb key must also reach the browser bundle at BUILD time.
# api/.env.production holds the key the server checks; root .env holds the one
# compiled into the JS. If these two ever drift, every WebSocket handshake is
# rejected and real-time features die silently — the REST API keeps working, so
# nothing looks broken.
sed -i "s|^NEXT_PUBLIC_REVERB_APP_KEY=.*|NEXT_PUBLIC_REVERB_APP_KEY=$REVERB_APP_KEY|" .env

chmod 600 .env api/.env.production

# Every sed anchor above must have matched something. `s|^VAR=.*|` that finds no
# such line succeeds and changes nothing, so a renamed or missing variable leaves
# a blank secret behind — and the stack boots fine and fails only at the first
# authentication. Print anything still empty:
for v in DB_ROOT_PASSWORD DB_PASSWORD DB_READ_PASSWORD REDIS_PASSWORD \
         MINIO_ACCESS_KEY MINIO_SECRET_KEY REVERB_APP_KEY REVERB_APP_SECRET; do
  grep -qE "^${v}=[^[:space:]]" api/.env.production || echo "STILL BLANK: $v"
done
```

Then generate the Laravel app key:

```bash
docker compose -f docker-compose.prod.yml run --rm api php artisan key:generate --show
# copy the base64:... output into APP_KEY= in api/.env.production
nano api/.env.production
```

### Still to fill in by hand

`openssl` cannot invent these — each needs a real external credential:

```bash
nano api/.env.production
```

| Variable | Notes |
|---|---|
| `APP_KEY` | from `key:generate --show` above |
| `MAIL_HOST` / `MAIL_USERNAME` / `MAIL_PASSWORD` | real SMTP. Password reset is dead without it |
| `ETHIOTELECOM_SMS_ENDPOINT` / `_USERNAME` / `_PASSWORD` / `_SENDER_ID` | EthioTelecom, all four (`config/sms.php` — there is no `SMS_API_KEY`). Leave `SMS_DRIVER=log` until you have them |
| `SENTRY_LARAVEL_DSN` | optional but strongly recommended — see Step 9 |

Confirm these are already correct in the template (they should be):

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ethr.et
APP_DOMAIN=ethr.et
SANCTUM_STATEFUL_DOMAINS=ethr.et,*.ethr.et
CORS_ALLOWED_ORIGINS=https://ethr.et
CORS_ALLOWED_ORIGINS_PATTERNS=#^https://[a-z0-9-]+\.ethr\.et$#
SESSION_DOMAIN=                  # MUST stay empty — see below
SESSION_SECURE_COOKIE=true
```

> **Leave `SESSION_DOMAIN` empty.** An empty value gives host-only cookies,
> which is precisely what stops `habru.ethr.et`'s session from being sent to
> `woldia.ethr.et`. Setting it to `.ethr.et` turns every user's session into a
> domain-wide credential across all tenants. This is a tenant-isolation
> boundary, not a convenience setting.

---

## Step 4 — TLS certificate (before the stack boots)

nginx will not start without a certificate at the paths it expects, so issue it
first.

**Tenants are subdomains, so you need a wildcard, and wildcards can only be
validated over dns-01.** Two consequences that bite people:

- `certbot --nginx` **cannot work here.** nginx runs in a container with a
  read-only bind-mounted config and holds ports 80/443, so `--nginx` cannot
  edit it and `--standalone` cannot bind.
- **Never use `--manual`.** certbot refuses to auto-renew a `--manual` cert, and
  `certbot renew --quiet` swallows that refusal. It looks healthy for 90 days,
  then the cert expires — and with HSTS at `max-age=31536000`, every tenant is
  hard-down instantly with no click-through.

Use your DNS provider's plugin. Cloudflare shown; swap for `-dns-route53`,
`-dns-digitalocean`, etc.:

```bash
apt install -y python3-certbot-dns-cloudflare

install -m 600 /dev/null /etc/letsencrypt/dns-credentials.ini
cat > /etc/letsencrypt/dns-credentials.ini <<'EOF'
dns_cloudflare_api_token = <scoped token with DNS:Edit on ethr.et>
EOF

certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/dns-credentials.ini \
  -d ethr.et -d '*.ethr.et' \
  --non-interactive --agree-tos -m ops@ethr.et

# Prove unattended renewal works NOW, not in 90 days
certbot renew --dry-run
```

`certbot renew --dry-run` is the step that catches the `--manual` trap. If it
fails, fix it before going live.

Wire up renewal, reloading nginx only when a cert is actually replaced:

```bash
echo '17 3,15 * * * root certbot renew --quiet --deploy-hook "docker compose -f /opt/ethr/docker-compose.prod.yml exec -T nginx nginx -s reload"' \
  > /etc/cron.d/certbot-renew
```

---

## Step 5 — Build, boot, provision (order matters)

```bash
cd /opt/ethr

# 1. Build. NEXT_PUBLIC_* are inlined into the browser bundle HERE, at build
#    time — changing them later needs a rebuild, not a restart.
docker compose -f docker-compose.prod.yml build

# 2. Boot and wait for every healthcheck
docker compose -f docker-compose.prod.yml up -d --wait

# 3. Replication — BEFORE any migration.
#    The api service runs with DB_READ_HOST=mariadb-replica, so Laravel's
#    read/write split is live from first boot, and `migrate` OPENS WITH A READ
#    (an information_schema probe) that lands on the replica. Until this has
#    run, the replica holds no schema and step 5 dies with
#    "Access denied for user ... Host: mariadb-replica".
./scripts/setup-replication.sh

# 4. MinIO bucket. MinIO does not create buckets on demand.
#    Skip this and every upload and payslip write fails, while the health
#    endpoint reports "storage":"unavailable" and STILL returns HTTP 200 —
#    so neither deploy.sh nor a load balancer will flag it.
./scripts/init-storage.sh

# 5. Schema
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force

# 6. System data (plans, permissions, tax brackets, templates)
docker compose -f docker-compose.prod.yml exec api php artisan db:seed --class=ProductionSeeder --force

# 7. Super admin. Prompts for email and a password of at least 12 characters,
#    so no platform account ever exists with a password written in a file.
#    ProductionSeeder above deliberately creates no accounts at all.
docker compose -f docker-compose.prod.yml exec api php artisan ethr:create-admin

# 8. Flush nginx's upstream DNS cache (it resolves api:9000 once, at startup)
docker compose -f docker-compose.prod.yml restart nginx
```

---

## Step 6 — Verify

```bash
# Every container healthy — no "unhealthy", no restart loops
docker compose -f docker-compose.prod.yml ps

# Health matrix. Note /api/v1/ — /api/health does not exist.
curl -s https://ethr.et/api/v1/health | jq

# TLS chain is publicly valid (no -k)
curl -sI https://ethr.et/ | head -1

# Vhost routing
curl -s -o /dev/null -w '%{http_code}\n' https://ethr.et/          # 200
curl -s -o /dev/null -w '%{http_code}\n' https://admin.ethr.et/    # 200
curl -s -o /dev/null -w '%{http_code}\n' https://ethr.et/admin     # 404 — admin host only

# Unknown tenant reaches Laravel and is rejected AS A TENANT.
# Proves nginx preserved the Host header and ResolveTenant parsed it.
curl -s https://nosuchtenant.ethr.et/api/v1/health   # expect tenant-not-found

# Security headers
curl -sI https://ethr.et/ | grep -iE 'strict-transport|x-frame|content-security'

# All six Horizon supervisors present
docker compose -f docker-compose.prod.yml exec worker-realtime php artisan horizon:supervisors

# The browser bundle must carry the REAL Reverb key, not the "ethr-key"
# fallback. This is the one check that catches silently-dead real-time features.
docker compose -f docker-compose.prod.yml exec -T frontend \
  grep -rq 'ethr-key' .next/static/chunks \
  && echo 'BAD — fallback key baked in; fix .env and rebuild frontend' \
  || echo 'OK — real Reverb key inlined'
```

Then confirm the socket actually connects: open `https://ethr.et` in a browser,
and check the Network tab's WS entry reaches status 101. A failed handshake here
means the two Reverb keys drifted — fix `.env` and
`docker compose -f docker-compose.prod.yml build frontend`, because the key is
compiled in and a restart will not change it.

Expect in the health payload: `"database"`, `"database_read"`, `"redis"`,
`"storage"` all healthy. **`"storage":"unavailable"` still returns HTTP 200** —
read the body, not just the status code.

---

## Step 7 — Backups (do this on day one)

`scripts/backup.sh` writes to `/var/backups/ethr` by default (override with
`BACKUP_PATH`), creates that directory itself, and dumps three artefacts per run
— `ethr_backup_<ts>_db.sql.gz`, `_files.tar.gz` (the MinIO volume), and `.env`.
It sets `umask 077` because that env copy holds every production secret.

```bash
cat > /etc/cron.d/ethr-backup <<'EOF'
# Daily 02:00 EAT (23:00 UTC)
0 23 * * * root /opt/ethr/scripts/backup.sh >> /var/log/ethr-backup.log 2>&1
EOF
```

**Verify a restore before you need one.** An untested backup is not a backup:

```bash
./scripts/backup.sh
ls -lh /var/backups/ethr/          # expect ethr_backup_<ts>_{db.sql.gz,files.tar.gz,.env}

# Restore is by backup name (no _db.sql.gz suffix). Run with no args to list:
./scripts/restore.sh
```

> `BACKUP_PATH` must be set identically for backup and restore. If you point the
> cron backup somewhere custom, `restore.sh` needs the same value or it will
> report "Backup file not found" against the default path.

---

## Step 8 — Routine deploys

```bash
cd /opt/ethr && ./scripts/deploy.sh
```

`deploy.sh` pulls, rebuilds, migrates, re-caches config/routes/views/events,
terminates Horizon so workers pick up new code, and health-checks
`https://localhost/api/v1/health` with `-k`.

Auto-rollback is **off** by default. Turn it on only once you trust the health
check, because a false negative reverts a good release:

```bash
ROLLBACK_ON_FAIL=true ./scripts/deploy.sh
```

Manual rollback: `./scripts/rollback.sh 1`

> Before any deploy that touches a Dockerfile, `docker-compose.prod.yml`, or
> anything under `docker/`, run `./scripts/prod-build-test.sh` on a build host
> first. It boots the whole production stack under an isolated project name,
> asserts 48 checks, and tears itself down without touching your live stack.

---

## Step 9 — Two things that fail silently

Both of these are invisible until you go looking, which is exactly why they
belong in a deploy runbook.

**1. Failed queue jobs.** A job exhausting its retries looks identical to
nothing happening — it leaves a `failed_jobs` row and an admin-screen count and
nothing else. Set `SENTRY_LARAVEL_DSN` in `api/.env.production`, or alert on the
`Queue::failing` log event. There is no email alerting; `ERROR_ALERT_EMAIL` is
read by nothing.

```bash
docker compose -f docker-compose.prod.yml exec api php artisan queue:failed
```

**2. Redis filling up.** The `redis` instance runs `noeviction` because it holds
queue jobs, sessions and cache locks — none of which are safe to drop. When it
fills it starts **refusing writes**, which means refusing to enqueue jobs.
(`redis-cache` filling is normal and self-correcting under `allkeys-lru`.)

```bash
docker compose -f docker-compose.prod.yml exec redis redis-cli -a "$REDIS_PASSWORD" info memory | grep used_memory_human
```

---

## Troubleshooting

**`Access denied for user ... Host: mariadb-replica`**
Step 5.3 was skipped or failed. Re-run `./scripts/setup-replication.sh`, then
retry the migration.

**`mariadb-replica` unhealthy / restart-looping**
Check for an OOM kill: `docker inspect ethr-mariadb-replica --format '{{.State.OOMKilled}}'`.
The container limit must stay at least 2× `innodb_buffer_pool_size` in
`docker/mariadb/replica.cnf` — InnoDB's resident set runs 25–40% above the pool
once the log buffer, dictionary cache and per-connection buffers are counted.
Because `api`, `scheduler`, every worker and `nginx` all gate on this service
being healthy, one OOM takes down the entire stack.

**`"storage":"unavailable"` but HTTP 200**
`./scripts/init-storage.sh` never ran. Run it; no restart needed.

**Login works on a tenant subdomain but not the apex**
`SANCTUM_STATEFUL_DOMAINS` needs **both** entries. Sanctum matches with
`Str::is()`, and `*.ethr.et` does **not** match the bare apex.

**Browser blocked by CORS**
`config/cors.php` defaults to `http://localhost:3000`. Both
`CORS_ALLOWED_ORIGINS` and `CORS_ALLOWED_ORIGINS_PATTERNS` must be set.

**Frontend calling the wrong API URL after an env change**
`NEXT_PUBLIC_*` are inlined at image build time. A restart changes nothing —
`docker compose -f docker-compose.prod.yml build frontend` and bring it back up.

**Certificate expired and every tenant is down**
HSTS `max-age=31536000` means no click-through. Re-issue immediately, then find
out why renewal was silent: `certbot renew --dry-run`.

---

## Before you take real customers

Deploy-time infrastructure is not the whole readiness question. From
`docs/DEPLOYMENT.md`'s pre-deployment checklist, these need a decision or a real
external credential, not a deploy step:

- **Income tax brackets** — confirm with ERCA that Proclamation 979/2016 is
  still operative. Stale brackets over-deduct on every payslip, worst for the
  lowest earners.
- **SMS** — the live EthioTelecom handshake has never run. Send one real OTP.
- **Email** — send one real password-reset and confirm it lands.
- **Biometric devices** — adapters are protocol-verified but have never talked
  to physical hardware.
- **Not implemented:** payroll cost-sharing, Google Workspace SSO, per-tier
  feature gating (only count caps are enforced). Do not sell these.
