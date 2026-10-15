# ETHR on your own server — single-tenant mode

For organisations that must run ETHR on their own hardware: a government
office, a security-sensitive employer, a site with no reliable route to
ethr.et. One installation serves exactly one organisation. The build is the
same release tree the SaaS deploys, and the mode is two lines of `.env`.

## What `ETHR_MODE=single` changes

| Area | Multi (the SaaS, the default) | Single |
|---|---|---|
| Tenant resolution | Custom domain, subdomain, `X-Tenant`, form field — else no tenant, fail closed | The one organisation resolves on **every** request, with no host, slug or header. Hostnames carry no tenant meaning (a bare IP works); the explicit selectors still win when present |
| Registration | Open, self-service | Open only while the install has **no** organisation (the setup window), then 403 `registration-closed` — including the subdomain availability check |
| Mail links | The organisation's canonical address (`ethr.et/{slug}`, subdomain or custom domain) | Plain `FRONTEND_URL/login` |
| Platform console | `admin.<APP_DOMAIN>` | Unchanged — `admin.<your host>` stays tenant-free |
| Everything else | — | Unchanged: same permissions, same audit log, same payroll, same gates |

A misconfigured `ETHR_SINGLE_TENANT` fails loudly by design: a slug that names
no organisation answers 404 on every API request, so the mistake surfaces at
smoke-test time rather than as weeks of empty pages. The enforcement tests are
`api/tests/Feature/Security/SingleTenantModeTest.php`.

## What you need

- A Linux or Windows server with **Apache** (`.htaccess` processed,
  `AllowOverride All`), **PHP 8.2+** and **MariaDB 10.11+**. The reference
  implementation of this exact shape is
  [`deployment/LOCAL-PRODUCTION-SETUP.md`](deployment/LOCAL-PRODUCTION-SETUP.md),
  which assembles and serves it on XAMPP in three commands.
- A way to run two scheduled HTTP calls every 5 minutes: cron, Windows Task
  Scheduler, or any URL-fetch service that can reach the server.
- A **build machine** (can be the server) with git, PHP 8.2+, composer and
  Node 24 (`.nvmrc`). The host that serves ETHR needs none of these beyond PHP.
- Two hostnames the server answers to: the main one (say `ethr.corp.local`)
  and `admin.` in front of it for the platform console. An internal DNS entry
  or a `hosts` line each is enough.

## 1. Build the release

```bash
npm --prefix src ci
ETHR_ADMIN_HOST=admin.ethr.corp.local scripts/shared-hosting/build-release.sh /srv/ethr-release
```

The output tree's document root is `/srv/ethr-release/api/public`, with the
rendered `.htaccess` inside it. The frontend's baked `NEXT_PUBLIC_*` values are
the committed SaaS ones and are host-agnostic at runtime — the local-production
rehearsal serves the same build on `localhost:8081` — so no frontend
configuration is needed.

Do **not** deploy the public `production` branch here: its `.htaccess` is
rendered for ethr.et's hosts, so the console would be denied on yours. Build
with your own `ETHR_ADMIN_HOST`.

## 2. Serve it

An Apache vhost per hostname, both with the document root
`<release>/api/public` and `AllowOverride All`. TLS as your network requires;
set `SESSION_SECURE_COOKIE=false` only if you genuinely serve plain HTTP on a
trusted network.

## 3. Configure `api/.env`

Start from `api/.env.example`. The keys that matter here:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ethr.corp.local
FRONTEND_URL=https://ethr.corp.local
APP_DOMAIN=ethr.corp.local

ETHR_MODE=single
ETHR_SINGLE_TENANT=        # set after step 5
TENANCY_SUBDOMAINS=false

DB_CONNECTION=mariadb      # plus DB_HOST/DATABASE/USERNAME/PASSWORD
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
BROADCAST_CONNECTION=null

MAIL_MAILER=smtp           # your mail relay; `log` if you have none yet
CRON_TOKEN=                # 32+ random chars — the endpoints answer 404 without it
BACKUP_PATH=/srv/ethr-backups
```

Then once, from `api/`:

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
php artisan ethr:create-admin
```

`ethr:create-admin` makes the platform super admin, who signs in at
`https://admin.ethr.corp.local` (MFA is required there). Never regenerate
`APP_KEY` after this point: employee TINs and national IDs are encrypted
with it.

## 4. Background work

Nothing on the server runs a worker. Ordinary traffic drains the queue
opportunistically after responses, but the scheduler — and quiet nights — need
the two cron endpoints called every 5 minutes, exactly as the SaaS does
([`deployment/shared-hosting/cron-caller.md`](deployment/shared-hosting/cron-caller.md)):

```bash
# Linux: crontab -e
*/5 * * * * curl -fsS -m 50 -X POST -H "X-Cron-Token: <CRON_TOKEN>" https://ethr.corp.local/api/v1/cron/schedule >/dev/null
*/5 * * * * curl -fsS -m 50 -X POST -H "X-Cron-Token: <CRON_TOKEN>" https://ethr.corp.local/api/v1/cron/queue >/dev/null
```

```powershell
# Windows Task Scheduler: one task per endpoint, trigger "repeat every 5 minutes"
schtasks /Create /TN "ETHR schedule" /SC MINUTE /MO 5 /TR "curl.exe -fsS -m 50 -X POST -H \"X-Cron-Token: <CRON_TOKEN>\" https://ethr.corp.local/api/v1/cron/schedule"
schtasks /Create /TN "ETHR queue" /SC MINUTE /MO 5 /TR "curl.exe -fsS -m 50 -X POST -H \"X-Cron-Token: <CRON_TOKEN>\" https://ethr.corp.local/api/v1/cron/queue"
```

A 404 from either endpoint means `CRON_TOKEN` is unset, shorter than 32
characters, or wrong. `GET /api/v1/health` shows the scheduler heartbeat and
last queue drain, so a stalled caller is visible.

## 5. Register the organisation

With `ETHR_MODE=single` and no organisation yet, the install is in its **setup
window**: open `https://ethr.corp.local/register` and register the organisation
through the normal flow. The window closes by itself with that first row.

Then pin it in `.env` — recommended, though a sole organisation resolves by
itself — and clear the config:

```ini
ETHR_SINGLE_TENANT=<the slug you registered>
```

```bash
php artisan config:clear
```

From here `https://ethr.corp.local/` goes straight to the organisation's
sign-in page, and every mail link points at `/login`.

## 6. Backups

The scheduler runs `ethr:backup` daily at 01:00 UTC into `BACKUP_PATH`
(database dump, private files, checksummed manifest; retention
`BACKUP_KEEP`, default 2). Point `BACKUP_PATH` at a disk that is not the
database's, and copy it off the machine —
[`deployment/BACKUP-RESTORE.md`](deployment/BACKUP-RESTORE.md) has the restore
drill. Unlike shared hosting you have a shell, so restore is simply:

```bash
php artisan ethr:restore <backup-name> --force
```

## 7. Updates

```bash
git pull
npm --prefix src ci
ETHR_ADMIN_HOST=admin.ethr.corp.local scripts/shared-hosting/build-release.sh /srv/ethr-release-new
```

Swap the vhost (or rsync over the old tree, keeping `api/.env` and
`api/storage/`), then:

```bash
php artisan migrate --force
php artisan config:clear
```

Drain the queue before swapping when a release changes queued job signatures —
[`DEPLOYMENT.md`](DEPLOYMENT.md) → *Draining the queue before an upgrade*.

## Known limits

- **Mail** needs an SMTP relay you provide; without one, password resets and
  notifications go to the log.
- **SMS** needs an HTTP gateway reachable from the server, configured at
  `/admin/sms`.
- **Custom domains and tenant subdomains** are meaningless here and stay off.
- **Plans and billing** exist but bill nobody; the platform console can extend
  the organisation's trial or set it active.
- Biometric devices on the LAN can push to the server directly (ZKTeco ADMS,
  webhooks) — better than the SaaS can do over the internet.
