# ETHR — Shared Hosting Deployment Runbook

Target: Ethio Telecom Linux Bronze (Plesk), account `etrhet` @ `213.55.96.154`.

**Before starting, confirm the four facts in `docs/MIGRATION_STATE.md` → NEXT ACTION.**
Two of them (B3 cron, B5 Node.js) change which steps in this runbook apply — they are
marked inline below. Do not guess; each branch is written out so there is nothing to
improvise once the answer is known.

This assumes local access to the repository (to build/upload from) and SSH access to
the account (confirmed open on port 22; whether *this* account's shell is enabled is
itself one of the still-open facts).

---

## 0. Layout

```
~/ethr/                    Laravel app — NOT web-accessible
  api/
    app/ config/ routes/ database/ vendor/ storage/ bootstrap/ .env
~/httpdocs/                 document root
  index.php                 copy of api/public/index.php, 2 lines repointed
  .htaccess                 docs/deployment/shared-hosting/.htaccess
  favicon.ico  robots.txt    copied from api/public/
  .well-known/               leave alone — ACME
  (frontend build output, IF B5 says no Node.js — see step 5)
```

Chosen because it is **already verified possible on this account**: the Plesk File
Manager listing showed the home directory sits one level above `httpdocs`
(`docs/B1-B5_GATE_REPORT.md`), so `.env`, `storage/`, and the whole application are
unreachable over HTTP by construction — not by an `.htaccess` rule that could be
misconfigured or bypassed by a document-root change. If Plesk turns out to allow a
custom document root pointed straight at `~/ethr/api/public`, that is marginally
cleaner and this layout still works unmodified — do not switch to it speculatively.

---

## 1. Prepare the release locally

```bash
# From the repo root, on main, after confirming `git status` is clean.
cd api
composer install --no-dev --optimize-autoloader
```

`--no-dev` matters more here than on the VPS: shared hosting has no `composer` cache
warmed by a prior CI run, and `phpunit`/`pest`/`larastan` in `require-dev` are ~40% of
`vendor/`'s size for zero production benefit.

> **`composer install` needs a readable `.env` to exist first.** It is safe here
> because this step runs on your own machine, where one does. If you ever run it
> *on the host* — through Plesk's Composer UI, or over SSH after uploading — do it
> **after** step 3 writes `.env`, never before.
>
> The failure is not obvious from the message. `composer install` runs
> `package:discover` as a post-autoload-dump script; `config/broadcasting.php`
> defaults to `reverb` when `BROADCAST_CONNECTION` is unset, `routes/channels.php`
> calls `Broadcast::channel()` at load time, and Pusher is handed a null key:
>
> ```
> Failed to create broadcaster for connection "reverb" with error:
> Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given
> ```
>
> It reads like a Reverb problem on a deployment that does not run Reverb. Measured
> in CI on 2026-09-16, where it failed every workflow run since the first push.

Do **not** run `npm run build` locally and upload `.next/standalone` unconditionally —
that decision depends on B5. See step 5.

## 2. Create the database

Plesk → *Databases* → create one MySQL database and one user with full privileges on
it. Record host (will be `localhost` — port 3306 is not internet-exposed on this
account, confirmed), database name, username, password.

**Before migrating, confirm H1** (`docs/MIGRATION_STATE.md` NEXT ACTION #4): does this
user have the `TRIGGER` privilege? If not, `2026_07_22_000001_restrict_audit_log_to_insert_only.php`
will abort the migration run **by design** (see `docs/AUDIT_LOG_INTEGRITY_DECISION.md`
— this is a deliberate compliance gate, not a bug). Resolve it with Ethio Telecom
support *before* step 4, not after a failed deploy.

## 3. Upload

```bash
# Everything except node_modules/vendor/tests — those are rebuilt or excluded below.
rsync -avz --exclude='.git' --exclude='node_modules' --exclude='vendor' \
  --exclude='.env*' --exclude='tests' \
  ./api/ etrhet@213.55.96.154:~/ethr/api/

rsync -avz api/vendor/ etrhet@213.55.96.154:~/ethr/api/vendor/
```

If SSH shell access turns out to be disabled for this account despite port 22 being
open (unconfirmed — see NEXT ACTION), fall back to the Plesk File Manager's own upload
+ "Extract archive" flow: `tar czf ethr.tar.gz api/` locally, upload the single archive,
extract server-side. Slower, but needs nothing beyond what File Manager already proved
it can do (it is how the hosting-check probe script would be run too).

## 4. Configure and migrate

```bash
ssh etrhet@213.55.96.154
cd ~/ethr/api

cp .env.production.example .env
nano .env   # apply every change in ../ENVIRONMENT.md, plus the DB credentials from step 2

php artisan key:generate --show
# paste the output into APP_KEY= in .env

php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
php artisan ethr:create-admin
```

Identical sequence to the VPS runbook (`docs/DEPLOYMENT.md`) from `key:generate`
onward — nothing about *what* these commands do changed, only that they run over SSH
against a shared-hosting PHP CLI instead of inside a container. If PHP CLI turns out to
be a different build/version from the web-facing PHP-FPM (common on shared hosting —
verify both when running the hosting-check probe), migrations must run under whichever
one matches `config/database.php`'s expectations; mismatched PHP versions between CLI
and FPM is itself worth flagging to Ethio Telecom support if found, not worked around.

**No `storage:link` step.** Unlike a default Laravel deployment, ETHR never symlinks the
`public` disk — every file URL in the product is a signed `temporaryUrl()`
(`FileStorageService`), so there is nothing under `public/storage` to serve. Do not add
this step; it would create a broken symlink pointing at a `storage/app/public` that
nothing writes to.

## 5. Deploy the frontend

**Branch on B5.**

### If Node.js is available (B5 = yes)

```bash
cd src
npm ci
npm run build   # produces .next/standalone
```

Upload `.next/standalone`, `.next/static` (into `standalone/.next/static`), and
`public/` to wherever Plesk's Node.js integration expects the app (its own document
root, separate from `~/httpdocs` — Plesk's Node.js apps are not served through
`.htaccess`). Configure the Node.js app's entry point as `server.js` (the standalone
build's own entry) and its port per Plesk's assignment.

`middleware.ts` and the `headers()`-reading `(auth)/layout.tsx` need **zero code
changes** in this branch — see `docs/SHARED_HOSTING_AUDIT.md` §E. Delete the entire
"Everything else → frontend, BRANCH B" block from `docs/deployment/shared-hosting/.htaccess`
before deploying it (leave BRANCH A as a comment for documentation, per that file's own
instructions).

### If Node.js is not available (B5 = no)

Requires the code changes named in `docs/SHARED_HOSTING_AUDIT.md` §E and
`docs/MIGRATION_STATE.md` D6 — **not yet made**, because making them before knowing B5
risks doing frontend work that turns out to be unnecessary. When B5 resolves negative:

1. `next.config.ts`: add `output: "export"`, remove `rewrites()` (nothing to proxy to
   locally once nginx/nginx-dev is gone — the SPA already calls the relative
   `/api/v1/...`), move the CSP block into `.htaccess`'s `mod_headers` section (already
   present in this package, currently duplicated from the VPS nginx config — keep it in
   sync if this branch is taken).
2. Delete `middleware.ts`; its host-based `/admin` rule is reimplemented in
   `docs/deployment/shared-hosting/.htaccess`'s commented BRANCH B block — uncomment it.
3. `(auth)/layout.tsx`: replace the `headers()` read with a client-side
   `window.location.host` read. Accepts a first-paint flash on the tenant login page
   (React hydration error #418's original cause) in exchange for removing the last SSR
   dependency — documented trade-off, not an oversight.
4. Add `generateStaticParams` returning `[]` to the four dynamic routes
   (`employees/[id]`, `payroll/[id]`, `devices/[id]`, `admin/tenants/[id]`) — each is
   already a client component that fetches by id, so this only satisfies the exporter.
5. `npm run build`, upload the exported `out/` directory into `~/httpdocs/`, alongside
   `index.php`. Uncomment BRANCH B's two rewrite rules in `.htaccess`.

**Cost of this branch, stated plainly:** marketing pages (`/`, `/features`, `/pricing`,
`/faq`, `/contact`) lose server-side rendering — an SEO consideration worth flagging to
whoever owns that decision, not something to absorb silently.

## 6. Cron

**Branch on B3.**

### If command-type tasks are available (expected — most Plesk plans offer this)

Plesk → *Scheduled Tasks* → add:

```
* * * * * cd ~/ethr/api && php artisan schedule:run >> /dev/null 2>&1
```

One line. `schedule:run` is what dispatches everything else — the 11 entries in
`routes/console.php`, unchanged, plus `queue:work --stop-when-empty` wherever the
schedule needs it (see `ENVIRONMENT.md` "Queue and scheduler"). If the panel's minimum
interval is coarser than 1 minute (5 minutes is common and tolerable), no code change —
Laravel's scheduler is idempotent about "was this due since last checked".

### If only URL-fetch tasks are available (B3 = no)

**Not built.** This needs an authenticated HTTP endpoint accepting a shared secret
(`SCHEDULER_HTTP_TOKEN` in `ENVIRONMENT.md`, currently a placeholder) that runs
`schedule:run` and `queue:work --stop-when-empty --max-time=50` on request, with the
fetch interval as the trigger. This is real, scoped work — new attack surface on a
route that can trigger payroll-adjacent jobs — and deliberately **not implemented
speculatively**; build it only once B3 confirms it's actually needed, sized to the
panel's actual fetch-interval floor.

## 7. Verify

```bash
curl https://www.ethr.et/api/v1/ping
curl https://www.ethr.et/api/v1/health
```

`health` should report every service as either `healthy` or (for anything genuinely
absent on this deployment) a state that is not silently wrong — `HealthController` was
fixed in commit `80cac67` to check the *configured* cache store and disk rather than a
hardcoded `redis`/`minio`, specifically so this endpoint tells the truth on a
deployment like this one.

Then work through `docs/deployment/shared-hosting/deploy-checklist.md` and
`docs/PRODUCTION_CHECKLIST.md` before pointing DNS at this host.

## 8. DNS cutover

`ethr.et` currently resolves to a dormant Hetzner VPS with every port closed (verified
— see `docs/B1-B5_GATE_REPORT.md`), so **there is no live traffic to protect**; this is
not a blue-green cutover with real risk of dropped requests. Repoint the `A`/`AAAA`
records for `ethr.et`/`www` (and the wildcard, once the vhost from step "wildcard
subdomain" below exists) to `213.55.96.154` when steps 1–7 are verified.

**Before cutover:** create the wildcard subdomain in Plesk (`* .ethr.et` → same
document root as `www`) — confirmed accepted by the panel but deliberately not yet
created (owner's choice, `docs/B1-B5_GATE_REPORT.md`). Tenant subdomains resolve to
nothing until this exists.
