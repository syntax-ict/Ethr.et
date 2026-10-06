# ETHR — go live through Plesk Git and the Laravel Toolkit

**The route:** local → GitHub `main` → the *Plesk release* workflow → the `production`
branch → Plesk Git deploys it into `<APP_ROOT>` (`~/ethr/`), whose **`api/public` is the
document root**. Artisan runs from Plesk's **Laravel Toolkit**.

> **Placeholders — HARD RULE 2**, [`SHARED-HOSTING-CONTRACT.md`](SHARED-HOSTING-CONTRACT.md):
> `<APP_DOMAIN>`, `<PANEL_URL>`, `<DB_HOST>`, `<DB_NAME>`, `<DB_USER>`. Fill them from the
> panel. *Written 2026-10-04, re-routed 2026-10-06* by
> [`OWNER-DECISION-LARAVEL-TOOLKIT.md`](../decisions/OWNER-DECISION-LARAVEL-TOOLKIT.md).
> The first host deploy showed that Plesk Git's deployment action runs in a chrooted
> shell with **no PHP**, so artisan cannot run there. **Steps not marked as measured are
> procedure, not record.**

This supersedes the "installation has no route" banner in
[`PLESK-HOSTING-GUIDE.md`](PLESK-HOSTING-GUIDE.md) Part C,
[`shared-hosting/DEPLOYMENT.md`](shared-hosting/DEPLOYMENT.md) §3–4, and that guide's A4
("do not change the document root"). **This route changes it on purpose, to one exact
value.**

---

## What GitHub already provides

| Piece | Where | What it does |
|---|---|---|
| `production` branch | published by [`release.yml`](../../.github/workflows/release.yml) | The finished release: `api/` with `vendor/` (no dev packages, no tests, **no `.env`**). `api/public/` holds Laravel's own `index.php`, the static export, the rendered `.htaccess` and `.user.ini` |
| Build | [`scripts/shared-hosting/build-release.sh`](../../scripts/shared-hosting/build-release.sh) | Builds from **committed** files only, then refuses a tree holding an `.env`, a config cache, dev packages or a modified `index.php` |
| Rehearsal | [`scripts/shared-hosting/rehearse-release.sh`](../../scripts/shared-hosting/rehearse-release.sh) | Runs the Toolkit sequence on a throwaway copy, serves `api/public` and calls `/api/v1/ping`, then redeploys and checks `.env` and `APP_KEY` survive |
| PHP limits | [`scripts/shared-hosting/user.ini`](../../scripts/shared-hosting/user.ini) → `api/public/.user.ini` | 256M / 120 / 10M / 12M. The panel shows these read-only on this account; `.htaccess` refuses to serve the file |
| Scheduler | [`cron.yml`](../../.github/workflows/cron.yml) | Drives `/cron/schedule` and `/cron/queue` every 5 minutes once its two secrets exist; dormant until then |

`production` moves only after **Quality gates** pass on `main` (or on a manual run of the
workflow). It never deploys by itself — pressing **Deploy** in Plesk does.

---

## Part 1 — GitHub settings (once)

1. **Repository variable** `ETHR_ADMIN_HOST` = `admin.<APP_DOMAIN>`. *(Set 2026-10-04.)*
2. **Scheduler environment** — *Settings → Environments → New environment* `production`, with
   two secrets ([`shared-hosting/cron-caller.md`](shared-hosting/cron-caller.md)):
   `ETHR_CRON_BASE_URL` = `https://<APP_DOMAIN>` (no trailing slash) and `ETHR_CRON_TOKEN` =
   the same value as `CRON_TOKEN` in `.env` (32+ random characters). **Do this after Part 5.**

## Part 2 — Panel prerequisites

1. **Files → `httpdocs`** — the old probe `p-*.php` is gone *(owner, 2026-10-06)*.
2. **PHP Settings** — PHP **8.3** and the extensions in
   [`PLESK-HOSTING-GUIDE.md`](PLESK-HOSTING-GUIDE.md) A1 (`gd` above all). The four limits are
   read-only here and come from the shipped `.user.ini` instead.
3. **Databases → User Management → `<DB_USER>`** — a **new password**, and Access control →
   **Allow remote connections from** the web server's address only. **Not** *local
   connections only*: the database is on its own server (`<DB_HOST>`), and "local" refuses
   the web server. The refusal names the address:
   `Access denied for user '<DB_USER>'@'<web server address>'`. *(Measured 2026-10-06: set,
   and **Trigger** is among the user's privileges.)*

## Part 3 — Plesk Git

**Websites & Domains → `<APP_DOMAIN>` → Git**. Measured 2026-10-06 as already set:

| Field | Value |
|---|---|
| Repository | `https://github.com/syntax-ict/Ethr.et.git` (public — no key) |
| Branch | **`production`** — never `main`, which has no `vendor/` and no build |
| Deployment mode | **Manual** |
| Deployment path | **`/ethr`** |
| Post deployment actions | **Untick "Enable post deployment actions".** The shell they run in has no PHP |

Then **Pull now → Deploy now**. That writes the release into `~/ethr/`, which nothing serves
yet.

## Part 4 — Document root

**Websites & Domains → `<APP_DOMAIN>` → Hosting & DNS → Hosting** → *Document root*:

```
ethr/api/public
```

**Read it back character by character before saving.** `ethr` or `ethr/api` would serve
`.env` with the database password and `APP_KEY`. `ethr/api/public` holds only the front
controller and the static site. Plesk keeps `.well-known/` working for certificate renewal
in whichever directory is the document root.

The site switches over the moment you save. It will show errors until Part 5 is done.

## Part 5 — `.env`, then the Toolkit

1. **Files → `ethr/api/`** — copy `.env.shared-hosting.example` to **`.env`** and edit it:

   | Key | Value |
   |---|---|
   | `APP_KEY` | leave as shipped — the Toolkit generates it |
   | `APP_URL` / `APP_DOMAIN` | as the template says, for `<APP_DOMAIN>` |
   | `DB_CONNECTION` | `mysql` (the panel reports MySQL 8.0.32) |
   | `DB_HOST` | `<DB_HOST>` from *Databases* — **not** `localhost` |
   | `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `<DB_NAME>` / `<DB_USER>` / the new password |
   | `MAIL_*` | the mailbox ETHR sends from |
   | `CRON_TOKEN` | 32+ random characters |

2. **Laravel (sidebar) → Scan.** It should find the application at `ethr/api`.
3. **Toolkit → Artisan**, in this order:
   - `key:generate --force` — **once, on this first deploy only.** Running it again later
     replaces the key, and every encrypted column becomes unreadable.
   - `migrate --force` — stops by design if the database user lacks `TRIGGER` (G0-F).
   - `db:seed --class=ProductionSeeder --force` — permissions, plans, Ethiopian tax brackets
     and organisation templates. **Without it every role gets 403**, and there are no plans
     or tax brackets. It is idempotent and creates no accounts.
   - `ethr:create-admin --email=<you@APP_DOMAIN> --password=<12+ characters> --force`. Then
     sign in and change the password on the Security page, so the one you typed is gone.
   - `config:cache`, `route:cache`, `view:cache`.
4. **Download `ethr/api/.env` and keep it offline.** It holds `APP_KEY`.

## Part 6 — Verify

```bash
curl -s https://<APP_DOMAIN>/api/v1/ping
curl -s https://<APP_DOMAIN>/api/v1/health
```

Then open `https://<APP_DOMAIN>/login`, sign in, and run
[`shared-hosting/deploy-checklist.md`](shared-hosting/deploy-checklist.md). Now do Part 1
step 2 so the scheduler starts.

## Part 7 — Subdomains (M3)

Set the document root of `admin.<APP_DOMAIN>`, and of a wildcard `*.<APP_DOMAIN>` subdomain,
to the same **`ethr/api/public`**, and serve the `*.<APP_DOMAIN>` certificate on both. Until
then, tenant hosts land on the Plesk login. The apex (`/login`, *Find your organisation*)
works without it.

## Every release after this

1. Merge to `main`, and wait for **Plesk release** to go green.
2. Plesk Git → **Pull now → Deploy now**.
3. Toolkit → Artisan: `migrate --force`, `db:seed --class=ProductionSeeder --force` (it picks up
   permissions a release adds), then `config:cache`, `route:cache`, `view:cache`.
   **Never `key:generate` again.**

Rolling back is a revert on `main`, which produces a new release. A deploy does not undo
migrations. See [`shared-hosting/rollback.md`](shared-hosting/rollback.md).

## Still open after go-live

M1 (whether the host honours `[F,L]`), M2 (the probe with database credentials, which
also proves `.user.ini` took), M6 (a restore rehearsal on the host) and the Amharic review
stay as [`CUTOVER-CHECKLIST.md`](CUTOVER-CHECKLIST.md) records them. Whether the Toolkit's
Artisan works on this account is itself unverified until Part 5 runs.
