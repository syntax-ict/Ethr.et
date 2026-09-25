# ETHR — Shared Hosting Deployment Runbook

Target: Ethio Telecom Linux Bronze (Plesk), account `ethret` @ `lin6.ethiotelecom.et`
(`213.55.96.154`).

> **STATUS BANNER — added 2026-09-18. Read before running anything below.**
>
> This runbook was written against an assumed shell account. Two facts measured on the
> real account since then contradict its transport, and the steps have **deliberately not
> been rewritten**, because the replacement depends on gates that are still unverified:
>
> - **SSH is Forbidden on this subscription** (Hosting Settings, 2026-09-17; no Terminal in
>   Dev Tools). Every `ssh` and `rsync` command below **will not connect**. The intended
>   replacement is the Plesk Git extension with a deployment path of `/ethr/` — see
>   `docs/MIGRATION_STATE.md` → *GIT DEPLOYMENT ROUTE*.
> - **There is no Scheduled Tasks section** (dashboard, 2026-09-18 — **G0-D FAIL**), so the
>   single cron line this runbook and `ENVIRONMENT.md` depend on has no runner, and
>   `key:generate`, `migrate`, `db:seed` and `ethr:create-admin` have no documented
>   non-shell route. Both are one support request; see the blocker register.
>
> - ~~**Step 4 copies the wrong environment file.**~~ **FIXED 2026-09-24 in step 4 itself.**
>   It copied a `docker-compose.prod.yml` artifact selecting redis for cache, queue and
>   session, minio for storage and reverb for broadcasting, with `REDIS_HOST=redis` and
>   `MINIO_ENDPOINT=http://minio:9000` — Docker service names that resolve to nothing here.
>   Step 4 now copies
>   [`api/.env.shared-hosting.example`](../../../api/.env.shared-hosting.example), which
>   carries the identical key set with each conversion marked and explained.
>
>   **This entry is the interesting part of the defect, not the fix.** The wrong filename
>   was flagged *here*, at the top of the very file that then went on to give the wrong
>   instruction, and flagged again in `../PLESK-SETUP.md` §2 — and the body was left saying
>   it anyway, for as long as both notes existed. A warning above a runbook does not correct
>   the runbook; readers follow the commands.
>
> What *has* been corrected here is factual only — the account username (`ethret`, not
> `etrhet`), the server name, the scheduler entry count (14, not 11, which is an
> acceptance criterion in `deploy-checklist.md`), and the line above naming which
> environment template to copy. The procedures are untouched on purpose: choosing between
> Branch A and Branch B rests on **G0-A** (`NOT VERIFIED`) and **G0-G** (`PARTIAL` since the
> panel read of 2026-09-22 — this line said "both NOT VERIFIED" until then), and rewriting
> them now would still bake in a guess: G0-G being PARTIAL narrows the question without
> answering it, and G0-A is untouched.

**Before starting, read `docs/MIGRATION_STATE.md` → NEXT ACTION**, which is kept current
and ordered by what each item unblocks.

Of the facts it lists, one still changes which steps in this runbook apply: **G0-G**
(Node.js — Branch A vs Branch B), marked inline below. The other, **G0-D** (cron), has
since been **answered FAIL**, which is what the status banner above records. Do not guess;
each branch is written out so there is nothing to improvise once the answer is known.

*(This said "the four facts … Two of them (B3 cron, B5 Node.js)" until 2026-09-19. B3 is
answered, and the list it counted no longer has four entries.)*

This assumes local access to the repository (to build/upload from) and SSH access to
the account (confirmed open on port 22; whether *this* account's shell is enabled is
itself one of the still-open facts).

---

## 0. Layout

```
~/ethr/                    Laravel app — NOT web-accessible
  api/
    app/ config/ routes/ database/ vendor/ storage/ bootstrap/ .env
<DOCROOT>/                  document root — **`httpdocs/`**, Plesk's stock default
  index.php                 copy of api/public/index.php, 3 lines repointed
  .htaccess                 docs/deployment/shared-hosting/.htaccess
  .well-known/               leave alone — ACME
  (frontend build output, IF B5 says no Node.js — see step 5)
```

### Deploy path — target is Plesk's default, owner decision 2026-09-24

**`<DOCROOT>` throughout this file means `httpdocs/`** — Plesk's stock document root for a
subscription's main domain. Every operational step below already says `<DOCROOT>`, so this
section is the only place the value is set, and changing the target changed one line rather
than fourteen.

**This is a reading, and it needs no action.** Twelve HTTP requests on 2026-09-24 measured
the served directory as `httpdocs/` — see `../GATE-0-RESULT.md` → *Account evidence*. The
block below, which called `httpdocs/` a *target* the owner had chosen to *move* to, was
wrong on both counts: it was already the root, and **editing that field is B-7's Trap 1.**
**Do not change the Document root field.**

**Before you save that field, three things, in this order.**

1. **Confirm `httpdocs/.well-known/` exists and holds `acme-challenge/`.** The certificate
   is live to 2026-12-15 and renews itself, and ACME can only be served from the document
   root. `docs/B1-B5_GATE_REPORT.md` recorded `.well-known/` inside `httpdocs/` on
   2026-09-17, which is evidence and not a guarantee eight days on. If it is missing, the
   renewal fails silently in about eleven weeks — the slowest possible way to find out.
2. **Know what stops being served.** Whatever `ethr.et/` currently serves goes dark the
   moment the field is saved. Read `httpdocs/` first and know what is in it.
3. **Read the field back character by character.** `~/ethr/` is the Laravel application.
   Pointing the document root at it publishes `.env`, `storage/` and the whole `.git` tree in
   one action, with no error at any step. `httpdocs`, `ethr`, `ethr.et` — three plausible
   values, one of which is a total credential disclosure.

**`httpdocs/ethr.et/` is blocker B-3 and is unrelated to this.** It is a subdirectory served
at `https://ethr.et/ethr.et/`, still unidentified. Do not delete it on a guess — deleting a
vhost is not deleting a folder.

**The depth question is settled.** `httpdocs/` sits one level below home, so the `__DIR__`
prefix is `'/../ethr/api/…'`. The table below is what makes that derivation checkable.

> **Corrected 2026-09-24 — a superseded block stood here and every word of it was wrong.**
> It said the root was `ethr.et/`, that it was *"not wrong and has not been retracted"*, and
> that deploying to `httpdocs/` *"before the root is moved"* would be a silent no-op.
> **There was never a move, and `httpdocs/` was always the served directory** — twelve HTTP
> requests measured it (`../GATE-0-RESULT.md` → *Account evidence — 2026-09-24*).
> PR #104 struck this section's heading and its instruction but left the block itself
> standing, so the file contradicted its own correction for one commit. Deploying to
> `httpdocs/` is simply correct.

**The safety property is unchanged and still holds**, for a reason worth stating precisely:
`~/ethr/api/` is a **sibling** of the document root, not a descendant, so `.env`, `storage/`,
`vendor/` and `.git` are unreachable over HTTP. That is a property of *where the root points*,
not of any `.htaccess` — and it fails the moment the root is pointed at `~/` or at `~/ethr/`.
**Moving the root to `httpdocs/` preserves it**: `~/ethr/` is a sibling of `~/httpdocs/` just
as it was of `~/ethr.et/`. What the move does *not* do is make the property automatic — it
still holds only because the root points somewhere that is neither `~/ethr/` nor an ancestor
of it, and `~/ethr/`, `~/ethr.et/` and `httpdocs` are three values one typo apart.

**The `__DIR__` prefix follows from the root's depth, and on the Plesk default it is settled.**
`httpdocs/` is one level below home, so the prefix is `'/../ethr/api/…'`:

| Root's depth below home | `__DIR__` prefix in `index.php` |
|---|---|
| **one level — `~/httpdocs/` (the target), or `~/ethr.et/`** | **`__DIR__.'/../ethr/api/…'`** |
| two levels (`~/httpdocs/ethr.et/`) | `__DIR__.'/../../ethr/api/…'` |

**Confirm it from File Manager's breadcrumb anyway**, because the derivation is only as good
as the assumption that the root ended up where the field said. A wrong prefix is a fatal
`require` on the first request — loud and immediate, not silent, so this is a five-minute
error rather than a dangerous one. That is also why this is checked *after* the root moves
and not before: the cheap failure is allowed to be the one that catches a wrong assumption.

**Nothing else from `api/public/` is copied here.** An earlier version of this layout
listed `favicon.ico` and `robots.txt` as copied from `api/public/`; both are wrong, and
`robots.txt` is wrong in the silent direction. Step 4a says why and what replaces them.

Chosen because it is **already verified possible on this account**. The Plesk File Manager
listing showed the home directory sits one level above `httpdocs`
(`docs/B1-B5_GATE_REPORT.md`).

> **This sentence was struck on 2026-09-24 and is restored on the same day, which is worth
> a line rather than a silent revert.** It was struck because the served root had just been
> resolved to `ethr.et/`, making a claim about `httpdocs`'s depth irrelevant to where the
> site actually is. The owner's decision to move the root to the Plesk default makes it
> load-bearing again. **Both edits were right when made**; what changed underneath them is
> the target, not the evidence. The general property is the one to hold onto, because it
> survives the next such change: **the application sits in a directory which is not the
> document root and not beneath it.** The `httpdocs` depth claim is one instance of that,
> not a replacement for it.

So
`.env`, `storage/`, and the whole application are unreachable over HTTP by construction — not by an `.htaccess` rule that could be
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
> *used to* default to `reverb` when `BROADCAST_CONNECTION` was unset,
> `routes/channels.php` calls `Broadcast::channel()` at load time, and Pusher was
> handed a null key:
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

**Before migrating, confirm H1 — now `G0-F`** (`deployment/GATE-0-RESULT.md`, still
`NOT VERIFIED`; ask 3 of `deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`): does this
user have the `TRIGGER` privilege? If not, `2026_07_22_000001_restrict_audit_log_to_insert_only.php`
will abort the migration run **by design** (see `docs/AUDIT_LOG_INTEGRITY_DECISION.md`
— this is a deliberate compliance gate, not a bug). Resolve it with Ethio Telecom
support *before* step 4, not after a failed deploy.

## 3. Upload

```bash
# Everything except node_modules/vendor/tests — those are rebuilt or excluded below.
rsync -avz --exclude='.git' --exclude='node_modules' --exclude='vendor' \
  --exclude='.env*' --exclude='tests' \
  ./api/ ethret@213.55.96.154:~/ethr/api/

rsync -avz api/vendor/ ethret@213.55.96.154:~/ethr/api/vendor/
```

If SSH shell access turns out to be disabled for this account despite port 22 being
open (unconfirmed — see NEXT ACTION), fall back to the Plesk File Manager's own upload
+ "Extract archive" flow: `tar czf ethr.tar.gz api/` locally, upload the single archive,
extract server-side. Slower, but needs nothing beyond what File Manager already proved
it can do (it is how the hosting-check probe script would be run too).

## 4. Configure and migrate

> ### This step has no route on this account. Read before following it.
>
> **`ssh` is Forbidden (B-1)** and **Scheduled Tasks offers no command-type task
> (G0-D, read 2026-09-18)**, so there is no way to execute `php artisan` here. The Node.js
> extension's *Run Node.js commands* runs Node, not PHP. That makes this step — not the
> upload, not the document root — the thing that blocks installation, because
> `key:generate`, `migrate`, `db:seed` and `ethr:create-admin` have no runner.
>
> **The commands below are correct and are kept as the specification.** What is missing is a
> way to run them. Do not improvise one on the production account: a web-reachable script
> that calls `Artisan::call()` is the obvious workaround and it is a remote code execution
> surface on the installation path, which is the worst place to put one. If it is taken, it
> is a deliberate design with a token guard, a deletion step and its own review — not an
> improvisation at deploy time.
>
> **This is the cron and SSH half of the support request** —
> [`../ETHIO-TELECOM-SUPPORT-REQUEST.md`](../ETHIO-TELECOM-SUPPORT-REQUEST.md).

```bash
# Requires shell access, which this account does not have. See the box above.
cd ~/ethr/api

cp .env.shared-hosting.example .env
nano .env   # apply every change in ../ENVIRONMENT.md, plus the DB credentials from step 2

php artisan key:generate --show
# paste the output into APP_KEY= in .env

php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
php artisan ethr:create-admin
```

> **Corrected 2026-09-24 — this said `cp .env.production.example .env`.**
> `PLESK-SETUP.md` §2 has recorded since it was written that doing so *"produces an
> application that cannot boot"*: that template is a `docker-compose.prod.yml` artifact and
> selects `redis` for cache, queue and session, `minio` for storage, `reverb` for
> broadcasting and `DB_HOST=mariadb` — Docker service names that resolve to nothing on this
> host. **The defect was documented in one file and left live in the other**, which is the
> documented-but-not-done pattern this repository criticises elsewhere. The shared-hosting
> template carries the identical key set with every conversion marked `# [shared-hosting]`.
>
> The `ssh` line was removed from the block for the same reason: it named a route that does
> not exist, and a runbook whose first line is impossible teaches readers to skim.

**If you imported existing data first**, `migrate --force` can abort on a data condition
rather than a privilege one: `2026_09_23_000002` makes live device
`(serial_number, adapter_type)` unique and refuses to run against duplicates, naming
them. Run the check in `docs/DATABASE_MIGRATION_PLAN.md` → "A data condition that aborts
the run" against the imported copy before this step. A fresh deployment cannot hit it —
an empty `devices` table has no duplicates.

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

## 4a. Assemble the document root

Steps 1–4 put the application in `~/ethr/api/`, which is **not web-accessible** — that
is the whole point of the layout in §0. Nothing has yet been placed in `<DOCROOT>/`,
so at this point the site still serves Plesk's placeholder page. This step builds the
document root, and it is the step G0-B is a gate on: every rule in `.htaccess` is inert
until the file is actually here.

### The three files

```bash
# Requires shell access, which this account does not have — B-1, see step 4.
# Over File Manager this is an upload and a copy, not a shell session; the
# commands below say what must end up where.

# 1. The front controller.
cp ~/ethr/api/public/index.php <DOCROOT>/index.php

# 2. The rules under test by G0-B.
#    (upload docs/deployment/shared-hosting/.htaccess from the repo first)
#    -> <DOCROOT>/.htaccess

# 3. Nothing else. Do not copy the rest of api/public/ — see "What is
#    deliberately not copied" below.
```

### Repoint `index.php` — **three** lines, not two

`api/public/index.php` resolves everything relative to its own directory, one level
below the application root. Moved into the document root, `__DIR__.'/..'` no longer resolves
to the application, so all three `require` paths must name `ethr/api` explicitly — **with the
prefix set by the root's depth below home, per the table in *Deploy path — corrected*:**

| Line | From | To |
| --- | --- | --- |
| 9 | `__DIR__.'/../storage/framework/maintenance.php'` | `__DIR__.'/../ethr/api/storage/framework/maintenance.php'` |
| 14 | `__DIR__.'/../vendor/autoload.php'` | `__DIR__.'/../ethr/api/vendor/autoload.php'` |
| 18 | `__DIR__.'/../bootstrap/app.php'` | `__DIR__.'/../ethr/api/bootstrap/app.php'` |

Line 9 is the one previous versions of this runbook missed by saying "2 lines". It is
not decorative: it is how `php artisan down` takes the site offline. Left unrepointed it
resolves to `~/storage/framework/maintenance.php`, which never exists, so maintenance
mode would report success on the CLI and change nothing about what the web server
serves — a silent failure during exactly the window you would rely on it.

Verify all three at once before going further:

```bash
php -l <DOCROOT>/index.php
curl -si https://www.ethr.et/api/v1/ping | head -1     # expect 200, not 500
```

A 500 here is almost always one of the three paths; `~/ethr/api/storage/logs/laravel.log`
will name it.

### `public_path()` no longer points at the document root

With the app at `~/ethr/api` and the front controller at `<DOCROOT>`, Laravel's
`public_path()` resolves to `~/ethr/api/public` — a directory nothing serves. This is
harmless **in this codebase** and was checked rather than assumed: the only reference is
`config/filesystems.php:88`'s `links` array, which is consumed solely by
`storage:link`, and §4's "No `storage:link` step" already forbids running it. Do not
"fix" this with `Application::usePublicPath()`; nothing reads it, and pointing it at the
document root would make `storage:link` look runnable again.

### What is deliberately not copied

`api/public/` contains three other files: `.htaccess`, `robots.txt` and `favicon.ico`.
On the VPS all three serve the **API vhost** (`infrastructure/nginx.conf` roots three
server blocks at `api/public`) — a different origin from the marketing site. This
deployment merges the API and the public site into one document root, so copying them
changes what they mean.

**This list is pinned by a test**, because an earlier version of it was wrong in exactly
the way prose is wrong: it named two files and missed `.htaccess`, which `ls` does not
show. `api/tests/Feature/DocumentRootInventoryTest.php` enumerates `api/public/` against
`document-root-inventory.php` and fails on any file with no recorded decision, so a
fourth file cannot arrive here unnoticed. Add a file to `api/public/` and answer its
question before this table can go stale again.

| File | If copied here | Do instead |
| --- | --- | --- |
| `.htaccess` | **The dangerous one.** Laravel's stock rules end in a catch-all — `RewriteCond !-d`, `!-f`, `RewriteRule ^ index.php [L]` — that sends *every* unmatched path to Laravel. This document root also serves the frontend, so under B5 = no that swallows `/pricing`, `/dashboard` and every other client-routed path. Loud rather than silent, unlike the two below — but the confusion is easy, because both files are called `.htaccess`. | Use `docs/deployment/shared-hosting/.htaccess`, which is the purpose-built replacement: its front-controller rule is restricted to `^/(api\|sanctum)` for exactly this reason, and it adds the security headers and deny rules Laravel's has no reason to carry. |
| `robots.txt` | `api/public/robots.txt` is `User-agent: * / Disallow:` — **allow everything, no sitemap**. Served at `https://www.ethr.et/robots.txt` it silently replaces the frontend's own `robots.txt` (`src/src/app/robots.ts`), dropping the `Disallow` list for `/admin`, `/dashboard`, `/login`, `/register` and the reset-password routes, and dropping the `Sitemap:` pointer that is how a crawler finds `/sitemap.xml` at all. Nothing logs this; the site works perfectly. | Leave it in `~/ethr/api/public/`. `/robots.txt` is the frontend's, in both B5 branches. |
| `favicon.ico` | Laravel's default icon shadows the frontend's, which ships its own under `src/public/`. Cosmetic, not silent — but the same shadowing mechanism. | Leave it. |

The general rule, worth stating once rather than per file: **a real file in
`<DOCROOT>/` wins over the rewrite**, so anything copied into the document root is a
permanent override of whatever the application would otherwise have produced at that
path. Copy only what §0 lists.

Be precise about how much of that rule is measured, because this file's own standard is
that inference is not evidence:

- **Apache-side: verifiable from the repository.** The front-controller rule in
  `shared-hosting/.htaccess` is guarded by `RewriteCond %{REQUEST_FILENAME} !-f` and
  `!-d`. A request matching a real file therefore never reaches the rewrite. Read it in
  the file; nothing about the host is assumed.
- **nginx/Plesk-side: NOT measured.** Whether Plesk's nginx serves a static file from
  disk before Apache ever sees the request is a property of this host's configuration and
  has never been tested. An earlier version of this section stated it as fact. It is now
  gate **G0-B.5**, which the canary answers in the same session as the other four —
  see `docs/deployment/GATE-0-RESULT.md`.

Both paths lead to the same instruction, which is why the rule is safe to act on now: on
Apache the file wins by the `!-f` guard, and on nginx-first it wins even harder. G0-B.5
tells you *which*, which matters when something behaves unexpectedly, not *whether* to
copy the file.

### The public paths, and what serves each

**On the platform host** — `www.ethr.et`, and `ethr.et` which 301s to it. A tenant host
(`{tenant}.ethr.et`) is a different vhost with a different answer for three of these
rows; see the note below the table.

Written out per B5 branch because the answer differs, and because two of these paths are
generated by the frontend at build time rather than existing as source files — which is
what makes the `robots.txt` collision above easy to miss.

| Path | B5 = yes (Node.js) | B5 = no (static export) |
| --- | --- | --- |
| `/api/*`, `/sanctum/*` | `.htaccess` → `index.php` → Laravel | same |
| `/` and the marketing routes | Node app (SSR) | `out/index.html` etc., via BRANCH B's two rewrite rules |
| `/robots.txt` | Node app, from `src/src/app/robots.ts` | `out/robots.txt`, generated by the same file at build |
| `/sitemap.xml` | Node app, from `src/src/app/sitemap.ts` | `out/sitemap.xml`, same |
| `/.well-known/acme-challenge/*` | Apache, from disk — `.htaccess` exempts it before every other rule | same |

**A tenant host is not this table.** Tenant public pages route `/`, `/media/`,
`/robots.txt`, `/sitemap.xml` and `/preview` to **Laravel**, not the frontend — a
tenant's `robots.txt` and sitemap are that organisation's own, generated per host. Two of
those paths therefore carry the same name as a row above and are answered by the other
half of the stack. That work is on PR #17 (`claude/ethr-tenant-landing-pages-a59gly`),
which is unmerged at the time of writing and which deliberately leaves its Plesk
`.htaccess` equivalent unwritten until G0-B answers whether `.htaccess` is honoured at
all. When it merges, this section needs a pointer to `docs/TENANT_PUBLIC_PAGES.md` →
*Deployment*, not a copy of its rows: the two tables describe different vhosts and
keeping one authoritative for each is what stops them drifting.

Two caveats, neither of which this repository can close:

- **B5 = yes is gated on G0-A, not just G0-B.** §5's Node branch puts the Node app in
  its own document root, separate from `<DOCROOT>`. For one domain to serve both
  `/api/*` from `<DOCROOT>/index.php` and `/` from the Node app, something has to split
  the traffic. That split is `docs/deployment/GATE-0-RESULT.md` G0-A, and it is
  `NOT VERIFIED`. There are exactly two shapes it can take, written out here so that
  when G0-A answers this is a lookup rather than a design session:

  | | **A1 — nginx splits the traffic** | **A2 — the frontend goes cross-origin** |
  | --- | --- | --- |
  | Selected when | G0-A **PASS** — *Additional nginx directives* accepts a `location` block | G0-A **FAIL** — the field is absent, read-only, or rejects the probe |
  | Shape | One domain. A `location ^~ /api/` (and `/sanctum/`) directive proxies to the PHP vhost; everything else reaches the Node app. `<DOCROOT>/` keeps `index.php` and `.htaccess` exactly as step 4a builds them. | Two origins. The Node app serves `www.ethr.et`; Laravel moves to a hostname of its own, and the frontend calls it absolutely. |
  | Frontend change | **None.** `src/src/api/client.ts:14`'s relative `baseURL: "/api/v1"` keeps working, and so does the service worker's same-origin API cache (`src/public/sw.js:59`). | `baseURL` becomes absolute; CORS with credentials; `SameSite=None; Secure` cookies; Sanctum stateful-domain config; a widened CSP `connect-src`; **and the service worker's API cache silently stops working**, because it intercepts same-origin only. |
  | Cost | ~1 day | ~1–2 weeks **plus an auth-security review** — the cookie and CORS changes are exactly the surface where a mistake is both easy and serious |

  A1 is strongly preferred and is what the rest of this package assumes. Do not start A2
  on a guess: it is the expensive branch, and G0-A is one directive and one `curl` to
  settle (`GATE-0-RESULT.md` → *Plesk panel checklist* → G0-A, which says to answer it
  first for this reason).
- **B5 = no is where the collision actually bites.** Both `api/public/` and the exported
  `out/` land in the same directory, so whichever is copied last wins and neither step
  says so. Following this runbook as written — copy nothing from `api/public/` but
  `index.php` — removes the ambiguity rather than relying on step order.

Confirm the result rather than the intent: `deploy-checklist.md` → *Public paths* turns
each row of this table into a check that fails loudly.

## 5. Deploy the frontend

**Node.js is the chosen branch — owner decision 2026-09-24.** The static-export branch is
retained below as the fallback, not deleted: it is what this step reverts to if the routing
question in *One thing must be verified* comes back wrong.

### The two Node versions, and why neither moves

This repository pins **two different Node versions for two different jobs**, and reading
them as one number is how the host's 22.23.2 gets mistaken for a blocker.

| Job | Version | Declared in | Account's 22.23.2 |
|---|---|---|---|
| CI build, gates, test suite | **24** | `.nvmrc` | does **not** satisfy — and **is not relaxed to fit the host** |
| Frontend application runtime | **22** | `docker/frontend/Dockerfile` (`FROM node:22-alpine`, which both builds and runs `server.js`) | **satisfies exactly** |

**The host never runs the gates**, so the 24 pin is not a hosting requirement and nothing
here asks you to weaken it. The host runs a built artifact, and 22 is the runtime the
repository already ships against.

**Build the deployable artifact on Node 22, matching the host's major version** — the same
thing `docker/frontend/Dockerfile` does. Building on 24 and running on 22 would usually work
and is not worth the risk: `npm ci` compiles any native dependency against the build
platform, and a 24-built binary is not guaranteed to load on 22. That failure appears at
first request, not at build time.

```bash
cd src
npm ci          # on Node 22
npm run build   # produces .next/standalone
```

Upload `.next/standalone`, `.next/static` (into `standalone/.next/static`), and `public/` to
the Node.js application root. Set the entry point to **`server.js`** — the standalone build's
own entry. **The panel read `app.js` on 2026-09-22**; that is Plesk's default and it is wrong
for this app. A mismatched entry point fails at start, loudly.

**You can build on the host if you prefer**, but only through one route: the Node.js
extension's *Run Node.js commands*, read in the panel on 2026-09-22. There is no other —
SSH is Forbidden (**B-1**) and Scheduled Tasks offers no command-type task (**G0-D**, read
2026-09-18). Off-host is still the recommendation, because `npm ci` on a shared account is
the step most likely to hit a memory or time limit, and **G0-J**'s CPU and memory rows are
`NOT VERIFIED`.

### One thing must be verified before this branch is committed to

**Who serves `/` — Node or PHP?** The panel read *Application URL* `http://ethr.et` and
*Document Root* `/ethr` on 2026-09-22. If Plesk mounts the Node application at the domain
root, requests for `/api/v1/...` may never reach `index.php` in `<DOCROOT>`, and the
same-origin arrangement this whole layout depends on does not exist.

**This is not answered here, and must not be guessed from Plesk's general documentation.**
It is a panel-and-fetch question: enable the application, then fetch one API route and one
frontend route and see which process answers. Until it is answered, treat the Node branch as
*chosen* but not *verified* — and note that the static-export branch below has no such
question, because there is only one server.

`middleware.ts` and the `headers()`-reading `(auth)/layout.tsx` need **zero code
changes** in this branch — see `docs/SHARED_HOSTING_AUDIT.md` §E. Delete the entire
"Everything else → frontend, BRANCH B" block from `docs/deployment/shared-hosting/.htaccess`
before deploying it (leave BRANCH A as a comment for documentation, per that file's own
instructions).

### Fallback — static export (B5 = no, or the routing question above comes back wrong)

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
4. Add `generateStaticParams` returning `[]` to the four dynamic routes — **this step does not work as written.** All four are `"use client"` and Next rejects the combination; each needs a server-component wrapper first, and `[]` still 404s every real id because those ids are tenant data. Measured 2026-09-18, `7aed9d2`; see `SHARED_HOSTING_AUDIT.md` §E
   (`employees/[id]`, `payroll/[id]`, `devices/[id]`, `admin/tenants/[id]`) — each is
   already a client component that fetches by id, so this only satisfies the exporter.
5. `npm run build`, upload the exported `out/` directory into `<DOCROOT>/`, alongside
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

~~One line.~~ **Two — corrected 2026-09-19.** `schedule:run` drives the 14 entries in
`routes/console.php`, unchanged, but it does **not** dispatch `queue:work`: that file
contains no such entry, and eleven of its fourteen entries do nothing but enqueue. The
worker needs its own recurring command:

```
* * * * * cd ~/ethr/api && php artisan queue:work --queue=attendance,notifications,default,exports --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

The queue list is not optional — a bare `queue:work` drains only `default` and starves the
other three. See `ENVIRONMENT.md` "Queue and scheduler" for the measurement. If the panel's minimum
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

## 6a. What a full disk actually looks like

**Set backup retention before the first scheduled run, not after.** On Bronze the
plan is **5 GB in total** *(PUBLISHED)*, shared by the application, `vendor/`,
the exported frontend, logs, every uploaded employee document and every backup.
There is no off-host disk configured, so `ethr:backup` writes to the machine it
protects.

**Retention is now 2, not 7** — `BACKUP_KEEP` in the environment,
`config/backup.php` if unset. The arithmetic that decides it is in
[`../BACKUP-RESTORE.md`](../BACKUP-RESTORE.md) → *Scheduling*; the short version
is that what is retained is an **uncompressed** directory containing a full copy
of `storage/app/private`, and `prune()` runs *after* `create()`, so at peak every
employee document exists **`keep + 2`** times. At 7 that is nine copies. At 2 it
is four.

### The failure mode, stated plainly

**A full quota does not present as a full disk. It presents as data corruption.**

Nothing on this account reports "out of space" to a user. What happens instead is
that **every write under `storage/` fails while every read keeps working**, so
the application stays up and looks healthy:

- **Log writes fail**, so the event that would have told you goes missing first.
  The failure erases its own evidence before anyone sees it.
- **Uploads fail after the HTTP request has already succeeded** — a payslip or a
  scanned ID that the UI reported as saved is not on disk.
- **Queued work fails** mid-job, and jobs that write files fail *after* their
  database rows are committed. That is the shape that looks like corruption: the
  record says the document exists and the document does not.
- **The backup that would have let you undo it is the thing that filled the
  disk**, and it fails too — leaving the newest retained copy older than the
  damage.
- **`/api/v1/health` can still return 200.** It is not a disk check.

The same mechanism is already recorded elsewhere in this repository for a
different cause — `HostingRequirementsConsistencyTest` explains why
`BROADCAST_CONNECTION` must not be `log` on this target, because *"`log` writes a
line per broadcast against a fixed disk quota, where filling the quota fails
every write path including the database's."* Same quota, same silence.

**So treat free space as a monitored quantity on this target, not as an
assumption.** Read it in the Plesk panel before the first scheduled backup runs
and after the first week, and keep `BACKUP_KEEP` at the lowest number that still
gives you two restore points — one is not a retention policy, it is a single
point of failure, because a backup you cannot read leaves nothing behind it.

**The real fix is off-host retention, and it is not configured.** `--off-host`
exists and is wired through `league/flysystem-aws-s3-v3`, but the scheduled run
does not pass it and no disk is set up. Until it is, local retention is a stopgap
and depth must stay small.

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
