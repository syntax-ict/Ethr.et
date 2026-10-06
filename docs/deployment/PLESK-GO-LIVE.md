# ETHR — go live through Plesk Git

**The route:** local → GitHub `main` → the *Plesk release* workflow → the `production`
branch → Plesk Git pulls it into `<APP_ROOT>` (`~/ethr/`) and runs one deployment action.

> **Placeholders — HARD RULE 2**, [`SHARED-HOSTING-CONTRACT.md`](SHARED-HOSTING-CONTRACT.md):
> `<APP_DOMAIN>`, `<PANEL_URL>`, `<DB_HOST>`, `<DB_NAME>`, `<DB_USER>`. Fill them from the
> panel. *Written 2026-10-04.* **Nothing below has been executed on the host yet**, so
> every step is a procedure, not a record. What it changes in
> [`CUTOVER-CHECKLIST.md`](CUTOVER-CHECKLIST.md) is nothing until someone does it there.

This supersedes the "installation has no route" banner in
[`PLESK-HOSTING-GUIDE.md`](PLESK-HOSTING-GUIDE.md) Part C and
[`shared-hosting/DEPLOYMENT.md`](shared-hosting/DEPLOYMENT.md) §3–4: the install commands
run as the Plesk Git *additional deployment action*, a field the owner confirmed present on
2026-09-18 and nobody has executed yet. The first deploy below is that test.

---

## What GitHub already provides

| Piece | Where | What it does |
|---|---|---|
| `production` branch | published by [`release.yml`](../../.github/workflows/release.yml) | The finished release: `api/` with `vendor/` (no dev packages, no tests, **no `.env`**), `httpdocs/` (static export + repointed `index.php` + rendered `.htaccess`), `deploy/post-deploy.sh`, `RELEASE` |
| Build | [`scripts/shared-hosting/build-release.sh`](../../scripts/shared-hosting/build-release.sh) | Builds from **committed** files only, then refuses a tree holding an `.env`, a config cache or dev packages |
| Deployment action | [`scripts/shared-hosting/post-deploy.sh`](../../scripts/shared-hosting/post-deploy.sh) | Safe on every deploy: `APP_KEY` only when empty, migrate, one-shot admin, caches, then publish `httpdocs/` |
| Rehearsal | [`scripts/shared-hosting/rehearse-release.sh`](../../scripts/shared-hosting/rehearse-release.sh) | Deploys every release twice on a throwaway layout; fails if `APP_KEY` moves on the second deploy |
| Scheduler | [`cron.yml`](../../.github/workflows/cron.yml) | Drives `/cron/schedule` and `/cron/queue` every 5 minutes once its two secrets exist; dormant until then |

`production` moves only after **Quality gates** pass on `main` (or on a manual run of the
workflow). It never deploys by itself — pressing **Deploy** in Plesk does.

---

## Part 1 — GitHub settings (once)

1. **Repository variable** — *Settings → Secrets and variables → Actions → Variables*:
   `ETHR_ADMIN_HOST` = `admin.<APP_DOMAIN>`. The release refuses to publish without it.
2. **Run the release once** — *Actions → Plesk release (production branch) → Run workflow* on
   `main`. When it is green, the `production` branch exists.
3. **Scheduler environment** — *Settings → Environments → New environment* `production`, with
   two secrets ([`shared-hosting/cron-caller.md`](shared-hosting/cron-caller.md)):
   `ETHR_CRON_BASE_URL` = `https://<APP_DOMAIN>` (no trailing slash) and `ETHR_CRON_TOKEN` =
   the same value you will put in `.env` as `CRON_TOKEN` (32+ random characters).
   **Do this after Part 4**, or every 5-minute run reports an endpoint that is not there yet.

## Part 2 — Panel prerequisites

These are [`CUTOVER-CHECKLIST.md`](CUTOVER-CHECKLIST.md)'s known gaps, in its order.

1. **Files → `httpdocs`** — delete the old probe `p-*.php` if it is still there.
2. **Websites & Domains → `<APP_DOMAIN>` → PHP Settings**: PHP **8.3**, and the
   extensions in [`PLESK-HOSTING-GUIDE.md`](PLESK-HOSTING-GUIDE.md) A1 (`gd` above all).
   **The limits are not set here.** On this account the page shows them read-only, because
   the provider has not granted the permission (panel reading, 2026-10-06). Every release
   ships `httpdocs/.user.ini` with `memory_limit` **256M**, `max_execution_time` **120**,
   `upload_max_filesize` **10M** and `post_max_size` **12M**, which is the override that page
   names. `.htaccess` refuses to serve that file. The source is
   [`scripts/shared-hosting/user.ini`](../../scripts/shared-hosting/user.ini).
3. **Databases → User Management → `<DB_USER>`** — set a **new password** (the old one is in a
   chat transcript), and Access control → **Allow remote connections from** the web server's
   address, and nothing wider. **Not** *Allow local connections only*: the database runs on its
   own server (`<DB_HOST>`), so "local" means the database machine itself, and that setting
   refuses the web server, phpMyAdmin and ETHR. To find the address, open phpMyAdmin
   once. The refusal names it: `Access denied for user '<DB_USER>'@'<web server address>'`.
   *(Corrected 2026-10-06. This step said "local connections only", and following it
   produced exactly that refusal.)*
4. **Ask for `TRIGGER`** if it is not granted. `migrate` **stops by design** without it
   (G0-F); the first deploy in Part 4 will tell you.
5. **Do not touch** *Hosting Settings → Document root*. It is already `httpdocs`
   ([`PLESK-HOSTING-GUIDE.md`](PLESK-HOSTING-GUIDE.md) A4).

## Part 3 — Plesk Git

**Websites & Domains → `<APP_DOMAIN>` → Git → Add Repository**

| Field | Value |
|---|---|
| Repository | Remote, `https://github.com/syntax-ict/Ethr.et.git` (public — no key needed) |
| Branch | **`production`** — never `main`, which has no `vendor/` and no build |
| Deployment mode | **Manual** to start; automatic later if you want every release live |
| Deployment path | **`/ethr`** — *not* `httpdocs` and nothing under it. A repository inside the document root publishes `api/.env` |
| Additional deployment actions | `bash deploy/post-deploy.sh` |

If the action log later shows a PHP older than 8.2, change the line to
`ETHR_PHP=/opt/plesk/php/8.3/bin/php bash deploy/post-deploy.sh`.

## Part 4 — First deploys

1. **Deploy** (Pull Updates, then Deploy). **Expect it to fail** with
   `api/.env does not exist`. That failure is the first proof the action field executes,
   and its log shows which PHP it found. Nothing was published.
2. **Files → `ethr/api/`** — copy `.env.shared-hosting.example` to **`.env`** and edit it:

   | Key | Value |
   |---|---|
   | `APP_KEY` | **leave empty** — the deploy generates it once |
   | `APP_URL` / `APP_DOMAIN` | as the template says, for `<APP_DOMAIN>` |
   | `DB_CONNECTION` | `mysql` (the panel labels the server MySQL 8.0.32; M2's `DB1` confirms) |
   | `DB_HOST` | `<DB_HOST>` from *Databases* — **not** `localhost` |
   | `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `<DB_NAME>` / `<DB_USER>` / the new password |
   | `MAIL_*` | the mailbox ETHR sends from |
   | `CRON_TOKEN` | 32+ random characters — the same value as `ETHR_CRON_TOKEN` |

3. **Files → `ethr/`** — create `admin-bootstrap.env`:

   ```
   ADMIN_EMAIL=<you@APP_DOMAIN>
   ADMIN_PASSWORD=<12+ characters>
   ```

   The deploy creates the super admin from it and **deletes the file**.
4. **Deploy again.** The log should end with `APP_KEY generated`, the migrations, `super admin
   … is ready`, and `files copied into …/httpdocs`.
5. **Download `ethr/api/.env` and keep it offline.** It holds `APP_KEY`; without it the
   encrypted columns cannot be read after a restore.

## Part 5 — Verify

```bash
curl -s https://<APP_DOMAIN>/api/v1/ping
curl -s https://<APP_DOMAIN>/api/v1/health
```

Then open `https://<APP_DOMAIN>/login`, sign in as the super admin, and run
[`shared-hosting/deploy-checklist.md`](shared-hosting/deploy-checklist.md). Now do Part 1
step 3 so the scheduler starts.

## Part 6 — Subdomains (M3)

Tenants live on `<tenant>.<APP_DOMAIN>` and the console on `admin.<APP_DOMAIN>`. Both must
serve the **same** `httpdocs`: set the document root of `admin.<APP_DOMAIN>` and of a
wildcard `*.<APP_DOMAIN>` subdomain to `/httpdocs`, and serve the `*.<APP_DOMAIN>`
certificate on both. Until that works every tenant host lands on the Plesk login, which is
M3 today. The apex (`<APP_DOMAIN>/login`, including *Find your organisation*) works without it.

## Every release after this

1. Merge to `main`.
2. Wait for **Plesk release** to go green — `production` has moved.
3. Plesk Git → **Pull Updates → Deploy**. The same action runs; `APP_KEY` stays put.

Rolling back is a revert on `main`, which produces a new release; migrations are not undone
by a deploy. See [`shared-hosting/rollback.md`](shared-hosting/rollback.md).

## Still open after go-live

M1 (does the host honour `[F,L]`), M2 (the probe with database credentials), M6 (a restore
rehearsal on the host) and the Amharic review stay as
[`CUTOVER-CHECKLIST.md`](CUTOVER-CHECKLIST.md) records them. A green deploy closes none of
them by itself.
