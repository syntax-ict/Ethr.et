# ETHR on Plesk shared hosting — the owner's guide

**Target:** `ethr.et` · account `ethret` @ `lin6.ethiotelecom.et` · PHP 8.3.33 · Node 22.23.2

This is the single entry point for putting ETHR on the Ethio Telecom Plesk account. It is
written for the person with the panel open. Everything it asks you to do is a panel action
or a file upload; nothing here needs a shell, because **this account does not have one**.

It does not restate the detail. Each part links to the document that carries it.

| If you want | Read |
|---|---|
| What you configure in Plesk, field by field | [`PLESK-SETUP.md`](PLESK-SETUP.md) |
| The deploy procedure in full | [`shared-hosting/DEPLOYMENT.md`](shared-hosting/DEPLOYMENT.md) |
| Every `.env` value and why it differs from the VPS | [`shared-hosting/ENVIRONMENT.md`](shared-hosting/ENVIRONMENT.md) |
| What is measured versus assumed | [`GATE-0-RESULT.md`](GATE-0-RESULT.md) |

---

## Read this first — one step has no route, and it is not the one you would guess

The upload works. The document root works. The database works. **Installation does not**,
and it is worth knowing before you start rather than four hours in.

`php artisan key:generate`, `migrate`, `db:seed` and `ethr:create-admin` must run once
before the application exists. On this account there is **no way to run them**:

| Route | Status | Evidence |
|---|---|---|
| SSH | **Forbidden** | Hosting Settings; no Terminal in Dev Tools. Blocker **B-1** |
| Scheduled Tasks → command task | **No such section on the subscription** | Dashboard, owner-read 2026-09-18. **G0-D = FAIL** |
| Node.js → *Run Node.js commands* | Runs **Node**, not PHP | Panel, 2026-09-22 |

**This is the cron and SSH half of [`ETHIO-TELECOM-SUPPORT-REQUEST.md`](ETHIO-TELECOM-SUPPORT-REQUEST.md), which is drafted and not yet sent.** Sending it is the
single highest-value action available, and nothing in Parts A–C below is wasted while you
wait — the account configuration and the upload are prerequisites either way.

> **Do not improvise a way round this on the production account.** A web-reachable PHP
> script that calls `Artisan::call()` is the obvious workaround, and it is a remote code
> execution surface sitting on the installation path. If that route is taken it is a
> deliberate design — token guard, deletion step, its own review — not something assembled
> at deploy time because the ticket had not come back yet.

**The background half is not blocked on that ticket.** See Part D.

---

## Part A — Plesk configuration

Do these in order. **Only A4 changes the live site.**

### A1. PHP extensions — *Websites & Domains → PHP Settings*

Enable all 18:

```
pdo *      pdo_mysql *   mbstring     openssl      tokenizer    xml
dom        ctype         json         fileinfo     filter       hash
session    curl          iconv        gd *         simplexml    libxml
```

Plus **one of** `phar` or `zip` — not both, neither alone is mandatory. `BackupService`
tries `PharData`, falls back to `ZipArchive`, and throws a named `RuntimeException` if
neither exists.

`*` marks the three that `composer install` cannot detect from a dependency. They are now
declared directly in `api/composer.json`, so the install aborts rather than failing later.

> **Read `gd` carefully — its absence is silent, and it is a security property.**
> `FileStorageService::stripExif()` opens with
> `if (! extension_loaded('gd')) { return $content; }`. Without gd, EXIF stripping on the
> primary upload path returns the original bytes: employee photographs and identity
> documents keep GPS coordinates, device serials and capture timestamps, and are served
> back that way. No error, no log line. One unchecked box.

**Do not enable `bcmath`.** It is not required — money is stored in integer minor units.

### A2. Database — *Databases*

One database, one user. Record the credentials for Part C; they go in `.env`, never in a
file under the document root.

**Then, separately: the `TRIGGER` privilege.** Migration `2026_07_22_000001` creates
audit-log triggers and **aborts by design** without it. That is not a panel setting — it is
ask 3 of the support request. Tracked as **G0-F**.

Server version and charset are **G0-I, NOT VERIFIED**. Below MariaDB 10.2.7 / MySQL 5.7.9,
`migrate` fails on the first migrations.

### A3. Node.js — **do not enable it.** Forbidden by the hard rule

> **[`SHARED-HOSTING-CONTRACT.md`](SHARED-HOSTING-CONTRACT.md) → *HARD RULE — repository only*
> (owner, 2026-09-25) forbids requiring a Plesk extension, and forbids requiring the
> *Additional nginx directives* field that a Node app would need to split `/api/*` from `/`.**
> Both rules bite this branch independently.
>
> **This closes the question PR #100 left open and PR #107 narrowed.** #107 recorded the
> plan's foreclosure but left an escape hatch — *viable if G0-A turns out to work*. The
> rule removes it: even if that field appeared tomorrow, requiring it is forbidden.
> **Static export is the frontend path.**
>
> The panel readings below are kept as measurements, not as instructions.

> ### The plan forecloses this branch, and says why — read before enabling anything
>
> **`SHARED_HOSTING_PLAN.md` §5.1 states it outright:**
>
> > **#8 / G0-A path routing** — *"Consequence: §3A's **B (Node on Plesk)** is foreclosed, and
> > **A (static export)** is the only frontend path."*
> > **#7 / G0-G Node runtime — MOOT** — *"the panel offers a startable Node app MEASURED
> > (2026-09-22) — but with #8 denied there is nothing to split `/api/*` from `/`, so the
> > runtime being present buys nothing."*
>
> **The runtime being available is not the question. Routing is.** A Node application and a
> PHP front controller both want `/`. Splitting them needs the *Additional nginx directives*
> field — **G0-A** — and neither directive textarea appears on *Apache & nginx Settings*,
> read twice. That is `NOT VERIFIED` with **strong evidence of FAIL**, and §5 adopts denial
> as its working assumption.
>
> **This is the "who serves `/`" question raised in PR #100, and the plan had already
> answered it.** It was recorded there as unverified; §5.1 shows the analysis was done.
>
> **What that means practically.** The Node branch is viable *only* if G0-A turns out to
> work — a single panel reading nobody has taken since. Under the plan's working assumption
> it does not, and **static export (§3A Option A, ≈8 days, 6–11 realistic) is the only
> frontend path**. Do not enable the Node application expecting it to serve the product until
> G0-A is answered; enabling it to *answer* the routing question is fine and cheap.
>
> **Nothing here reverses the owner's choice** — it states the dependency that choice has,
> which was not on the table when it was made.


~~Set **Application Startup File** to **`server.js`**.~~ **Do not.** Kept only because it records a real mismatch: the panel's default `app.js` is wrong for a Next standalone build, so if this branch were ever unforbidden it would fail at start. That is evidence about the panel, not a step to take.

The panel read `app.js` on 2026-09-22. That is Plesk's default and it is wrong for this
app: `src/next.config.ts` sets `output: "standalone"`, whose entry point is `server.js`. A
mismatched entry fails at start — loudly, which is the good kind.

**The account's Node 22.23.2 is correct for this job and nothing needs relaxing.** The
repository pins two versions for two jobs:

| Job | Version | Declared in | 22.23.2 |
|---|---|---|---|
| CI build, gates, test suite | 24 | `.nvmrc` | does not satisfy — **and is not relaxed to fit the host** |
| Frontend application runtime | 22 | `docker/frontend/Dockerfile` | **satisfies exactly** |

The host never runs the gates.

### A4. Document root — **DO NOTHING. Do not change this field.**

**Corrected 2026-09-24, same day this guide was written.** This section previously read
*"Target: `httpdocs` — LIVE CHANGE"* and told you to move the document root. **Do not do
that.** The instruction was wrong and following it ranged from pointless to dangerous.

**The document root is already `httpdocs/`.** Twelve HTTP requests against the live host
measured it: every directory under the document root answers **403** (`/css/`, `/cgi-bin/`,
`/.well-known/acme-challenge/`), every home-directory entry answers **404** (`/logs/`,
`/usr/`, `/var/`, `/ethr/`), and `/favicon.ico` answers **200** while `/httpdocs/favicon.ico`
answers 404. A document root at the home directory would invert every row. See
[`GATE-0-RESULT.md`](GATE-0-RESULT.md) → *Account evidence — 2026-09-24*.

So `Document root: /` is Plesk showing the path **relative to the webspace root**, which is
normal and correct. There is nothing to move.

> **Why this was worth a correction and not a quiet edit.** Editing that field is **B-7's
> Trap 1**. At best it is a no-op. At worst — if the value ever resolves to the home
> directory — it web-serves `~/ethr/api/.env`, publishing `APP_KEY` and the database
> password, and breaks certificate renewal at the same time. This guide told you to open
> that field and type in it. **The safest action on a field that is already correct is to
> leave it shut.**

**How the earlier `ethr.et/` reading happened**, because the reasoning is worth keeping: the
canary uploaded per the runbook appeared at `ethr.et/ethr-canary` in File Manager, and that
was read as locating the document root. But `/ethr.et/` returns **404** where real
document-root directories return 403 — the canary went into a directory that **is not
served**. *File Manager shows where a file went; it does not show what Apache serves.* The
canary must be re-uploaded under `httpdocs/` before G0-B.1–B.5 can be fetched at all.

**Nothing else in this guide changes.** `<DOCROOT>` already meant `httpdocs/` everywhere,
and that was right — it was only the claim that it needed *moving there* that was wrong.

### A5. Git deployment — *Git* · optional, and **set the path before the first deploy**

Deployment path is **`/ethr/`**, which resolves to `~/ethr/` and yields `~/ethr/api/`.

The repository was removed from Plesk on 2026-09-18, so nothing deploys anywhere today.

> **The rule, not the directory name: never deploy the repository into the served
> directory.** Deploying `main` into the document root publishes the whole repository at the
> web root, `.git/` included — **which already happened once on this account.** The
> directory to avoid has changed twice in one day (`httpdocs/` → `ethr.et/` → `httpdocs/`),
> which is exactly why the rule is worth memorising and the name is not.

Plesk runs deployment actions *after* files are written, so no action can guard the target.
Keep the deploy key read-only — already done.

---

## Part B — Upload

Two directories, and the relationship between them is the security property:

```
~/ethr/                    Laravel application — NOT web-accessible
  api/
    app/ config/ routes/ database/ vendor/ storage/ bootstrap/ .env
httpdocs/                  document root
  index.php                copy of api/public/index.php, 3 lines repointed
  .htaccess                from docs/deployment/shared-hosting/
  .well-known/             leave alone — ACME
```

**`~/ethr/api/` is a sibling of the document root, not a descendant.** That is why `.env`,
`storage/`, `vendor/` and `.git` are unreachable over HTTP — a property of where the root
points, not of any `.htaccess` rule that could be misconfigured or bypassed.

`httpdocs/` is one level below home, so `index.php`'s three repointed lines use
`__DIR__.'/../ethr/api/…'`. **Confirm from File Manager's breadcrumb** — a wrong prefix is a
fatal `require` on the first request, which makes it the cheap check.

Full procedure, including what must **not** be copied into the document root:
[`shared-hosting/DEPLOYMENT.md`](shared-hosting/DEPLOYMENT.md) steps 3 and 4a.

---

## Part C — Install · **blocked, see the top of this guide**

Copy **`api/.env.shared-hosting.example`** to `~/ethr/api/.env` and fill `APP_KEY`, `DB_*`
and `MAIL_*`.

> **Not `.env.production.example`.** That one is a `docker-compose.prod.yml` artifact: it
> selects `redis` for cache, queue and session, `minio` for storage, `reverb` for
> broadcasting and `DB_HOST=mariadb` — Docker service names that resolve to nothing here.
> Copying it produces an application that cannot boot.

Then the four commands that have no runner on this account:

```bash
php artisan key:generate --show     # paste into APP_KEY=
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
php artisan ethr:create-admin
```

**There is no `storage:link` step.** Every file URL in the product is a signed
`temporaryUrl()`, so nothing under `public/storage` is ever served. Adding it creates a
broken symlink.

---

## Part D — Background work · **not blocked**

Without a scheduler the queue never drains and no queued email is ever sent. G0-D is FAIL,
so nothing on the host can start `schedule:run` — **but the driver does not have to be the
host.** Two authenticated endpoints exist for exactly this:

```
POST https://ethr.et/api/v1/cron/schedule
POST https://ethr.et/api/v1/cron/queue
```

**Both, not one.** Eleven of the fourteen scheduled entries do nothing but insert rows into
the `jobs` table, and all 16 job classes implement `ShouldQueue`. Driving only the first
fills a queue that nothing drains.

Set `CRON_TOKEN` in `.env` to at least 32 random characters and present it as the
`X-Cron-Token` header. A `?token=` query form is accepted for callers that cannot set
headers — it puts the token in access logs and `Referer`, so treat it as rotatable.

**A 404 means check your token**, not that the route is missing: an unconfigured deployment
must not confirm these routes exist. `409` means the previous run is still going, which is
normal under load.

**Which caller** — uptime monitor, GitHub Actions cron, any always-on machine — is open
question **Q6**, and it is yours. The trade-offs are in
[`shared-hosting/cron-caller.md`](shared-hosting/cron-caller.md); its recommendation is
explicitly *not* the obvious one. Endpoint mechanics are in
[`shared-hosting/cron.txt`](shared-hosting/cron.txt).

---

## Part E — Verify

```bash
curl https://www.ethr.et/api/v1/ping
curl https://www.ethr.et/api/v1/health
```

`health` reports against the **configured** cache store and disk rather than a hardcoded
`redis`/`minio`, specifically so it tells the truth on a deployment like this one.

Then [`shared-hosting/deploy-checklist.md`](shared-hosting/deploy-checklist.md) before
pointing DNS anywhere. If it goes wrong:
[`shared-hosting/rollback.md`](shared-hosting/rollback.md).

---

## What is still open

Three of these are yours; none of them can be answered from the repository.

| | What | Why it matters |
|---|---|---|
| **1** | **Send the support request** — cron, SSH, `TRIGGER` | Unblocks installation (Part C) and `migrate` (G0-F) |
| **2** | **G0-A — is there an *Additional nginx directives* field at all?** | **This supersedes the "who serves `/`" question, which the plan had already answered.** `SHARED_HOSTING_PLAN.md` §5.1 forecloses the Node branch: without directive support there is nothing to split `/api/*` from `/`, so a startable Node runtime *"buys nothing"*. One panel reading settles it. If absent — the standing strong evidence — **static export is the only frontend path** and the Node branch is dead, not deferred |
| **3** | **Q6 — pick the cron caller** | Part D is otherwise ready |
| **4** | **G0-B.1–B.5** — the canary has never been fetched | Whether `.htaccess` is honoured at all. A canary exists in `ethr.et/ethr-canary`; if the root moves first it must be re-uploaded |

**Nothing in this guide has been verified against the live account.** Gate 0 stands at
1 verified, 1 failed, 28 outstanding — [`GATE-0-RESULT.md`](GATE-0-RESULT.md). Where this
guide states a panel reading it gives the date it was read; where it states a repository
fact it is checkable from the source. It states no hosting capability that has not been
observed.
