# Environment Variables — Ethio Telecom Shared Hosting

Every line below is a delta against `api/.env.production.example`, the VPS template.
Where a variable is unchanged from that file it is not repeated here — copy the VPS
template first, then apply exactly the changes below. Do not start from a blank file;
that file's comments explain *why* each unchanged variable has the value it has, and
duplicating that reasoning here would drift the moment one file is edited and not the
other.

**Every value below is grounded in a decision already made and recorded in
`docs/MIGRATION_STATE.md` ("DECISIONS TAKEN ON DELEGATION")** — this file does not
introduce a single new judgement call, it only writes the decisions down as `.env`
lines.

---

## Remove entirely — no shared-hosting equivalent

```
DB_READ_USERNAME=
DB_READ_PASSWORD=
REDIS_CLIENT=
REDIS_HOST=
REDIS_PASSWORD=
REDIS_PORT=
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=
REVERB_PORT=
MINIO_ENDPOINT=
MINIO_ACCESS_KEY=
MINIO_SECRET_KEY=
MINIO_BUCKET=
MINIO_USE_PATH_STYLE=
HORIZON_PREFIX=
```

Leaving these **unset** is correct, not merely tidy: `config/database.php`'s read
split, `config/broadcasting.php`'s reverb connection and `config/filesystems.php`'s
minio disk all read these via `env()` with no default that would make a stray value
dangerous — but an unset `REDIS_HOST` etc. means nothing on this deployment ever tries
to reach a service that does not exist here. `HORIZON_PREFIX` is inert once Horizon is
never booted (see "Queue and scheduler" below).

## Change — driver swaps (D-8 in MIGRATION_STATE.md)

```diff
- CACHE_STORE=redis
+ CACHE_STORE=database

- QUEUE_CONNECTION=redis
+ QUEUE_CONNECTION=database

- SESSION_DRIVER=redis
+ SESSION_DRIVER=database

- BROADCAST_CONNECTION=reverb
+ BROADCAST_CONNECTION=log

- FILESYSTEM_DISK=minio
+ FILESYSTEM_DISK=local
```

No application code needed any of these to change — see `docs/SHARED_HOSTING_AUDIT.md`
§D: zero `Redis::` calls anywhere in `app/`, the broadcast leg of every notification is
already guarded on `config('broadcasting.default') === 'reverb'`, and
`FileStorageService` (fixed in commit `80cac67`) resolves `filesystems.default` instead
of a hardcoded disk name.

**`CACHE_STORE=database` specifically live-verified, not just asserted** (2026-08-31):
`config/database.php`'s `cache_locks` table is what Laravel's database cache driver
needs for `Cache::lock()` — the primitive `->withoutOverlapping()` uses internally, and
the *only* thing in `routes/console.php` (8 call sites) that could behave differently
under a driver swap this invisible-looking. Confirmed against real MariaDB, not the
SQLite test suite: `Cache::lock()` acquire → contended-refuse → release → reacquire all
correct; `RateLimiter` (backing every named limiter in `AppServiceProvider` — `api`,
`auth`, `health`, `platform-admin`, `uploads`, `otp`, `payroll-process`, `imports`,
`dashboard`, `webhooks-test`) hit 32 times against a 30/min limit: 30 allowed, 2
correctly blocked. `cache_locks` is already created by
`0001_01_01_000001_create_cache_table.php` — nothing to add.

`FILESYSTEM_DISK=local` assumes Bronze's own storage quota is sufficient — see
"Storage sizing" below. If a customer's storage need exceeds it, repoint this at an
external S3-compatible endpoint instead (`s3` disk, same six `AWS_*` variables the disk
already declares in `config/filesystems.php`) — no code change either way, `local` and
`s3` are both already-wired disks.

## Change — database (D-7)

```diff
- DB_HOST=mariadb
+ DB_HOST=localhost
```

Verified: port 3306 on `213.55.96.154` is refused from the public internet — MySQL is
local-only on this account, which settles this value rather than leaving it a guess.
`DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` come from Plesk's own database
creation screen, not chosen by this deployment.

## Change — canonical host (D-5)

```diff
- APP_URL=https://ethr.et
+ APP_URL=https://www.ethr.et

- CORS_ALLOWED_ORIGINS=https://ethr.et
+ CORS_ALLOWED_ORIGINS=https://www.ethr.et
```

Plesk already 301-redirects the bare apex to `www.ethr.et` (verified — see
`B1-B5_GATE_REPORT.md`), and `www` is already a reserved subdomain
(`Tenant::RESERVED_SUBDOMAINS`), so this is not a new design decision — it is naming the
host Plesk has already chosen. `SANCTUM_STATEFUL_DOMAINS=ethr.et,*.ethr.et` needs no
change: the wildcard already covers `www.ethr.et`.

`APP_DOMAIN` stays `ethr.et` — that one is the tenancy root, not the canonical display
host, and `www` is excluded from tenant resolution by `RESERVED_SUBDOMAINS` regardless
of which one is canonical.

## Unresolved — fill in once the corresponding gate answers

```
# B5 — Node.js. If absent, these three are inert (no server-side Sentry
# process ever runs); if present, unchanged from the VPS template.
SENTRY_LARAVEL_DSN=
NEXT_PUBLIC_SENTRY_DSN=
SENTRY_AUTH_TOKEN=

# B3 — cron. If the panel only offers URL-fetch tasks rather than command
# execution, the scheduler/queue need an authenticated HTTP endpoint instead
# of `schedule:run`/`queue:work` — NOT built speculatively (see
# MIGRATION_STATE.md, "Deliberately not built"). This variable is the shared
# secret that endpoint would require; leave unset until B3's answer is known.
# SCHEDULER_HTTP_TOKEN=
```

## New — not present in the VPS template at all

```
# storage/ and bootstrap/cache/ live at ~/ethr, one level above the ~/httpdocs
# document root — see docs/deployment/shared-hosting/DEPLOYMENT.md "Layout" for why.
# Laravel resolves these relative to the app's own base path regardless of
# where the document root points, so no variable is actually required for
# this — noted here only so the reason isn't rediscovered from scratch later.
```

(No new variables are actually needed — the layout decision is structural, not
configured via `.env`.)

---

## Storage sizing

`FILESYSTEM_DISK=local` puts every upload — employee photos, attendance selfies,
documents, generated payslip PDFs, tenant backups from `BackupTenantJob` — on the
account's own quota. Bronze's exact quota is one of the still-open panel facts — the
**disk quota** row of `deployment/GATE-0-RESULT.md`, still `NOT VERIFIED`, and the tier
question in `deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`. *(This said "NEXT ACTION #3"
until 2026-09-19. That section was renumbered when it was rewritten against the
measurements, which is what a positional reference costs; it now names the row.)* `FileStorageService` already bounds photo
uploads to 512 KB and selfies to 200 KB after compression (`PHOTO_MAX_BYTES`,
`SELFIE_MAX_BYTES`), which caps *growth rate*, not the ceiling — monitor consumption
once live rather than assuming a number that hasn't been confirmed.

## Queue and scheduler

`QUEUE_CONNECTION=database` needs no daemon — a queued job sits in the `jobs` table
until something calls `queue:work`. On shared hosting that "something" is cron, not a
long-running process:

```cron
* * * * * cd ~/ethr/api && php artisan schedule:run >> /dev/null 2>&1
```

`schedule:run` itself dispatches `queue:work --stop-when-empty` where the schedule in
`routes/console.php` needs it — that file's 14 entries are otherwise unchanged from the
VPS, because nothing about *what* runs changed, only *how* it's triggered. This line is
correct **only if B3 confirms command-type cron tasks at ≤5-minute granularity**; if
Plesk offers URL-fetch tasks only, this whole section is replaced by an authenticated
HTTP endpoint design that has not been built (see above).
