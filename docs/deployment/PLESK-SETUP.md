# Plesk setup — what the repository handles, and what you configure

**Target:** `ethr.et` · account `ethret` @ `lin6.ethiotelecom.et` · app at `~/ethr/` ·
document root **`httpdocs/`** — Plesk's default, target chosen 2026-09-24.
Measured today it is still `ethr.et/`; moving it is a panel action, see §5

This document exists to keep one distinction sharp: **leaving something for you to
configure in Plesk does not mean the repository is unprepared for it.** Each section below
states what is already true of the code, and what you then click.

It does **not** restate [`shared-hosting/ENVIRONMENT.md`](shared-hosting/ENVIRONMENT.md) or
[`shared-hosting/DEPLOYMENT.md`](shared-hosting/DEPLOYMENT.md) — those describe the
deployment procedure and are frozen pending G0-A/G0-G. This is the account-configuration
side only.

**Nothing here is verified against the real account.** Gate 0 stands at 1 verified,
1 failed, 28 outstanding; see [`GATE-0-RESULT.md`](GATE-0-RESULT.md).

---

## 1. PHP version and extensions

### Repository

- `api/composer.json` requires `php: ^8.2`. The account reports **8.3.33** — satisfied.
- **`ext-gd`, `ext-pdo` and `ext-pdo_mysql` are now declared in `api/composer.json`.**
  They were not before, and no production dependency declares them either, so
  `composer install` could not see them missing. It can now: **it aborts** rather than
  installing an application that fails later.
- The other 15 mandatory extensions are declared by production packages, so composer has
  always enforced them transitively.

### Plesk — *Websites & Domains → PHP Settings*

Enable all 18. The ones composer cannot catch are marked; the rest abort the install.

```
pdo *      pdo_mysql *   mbstring     openssl      tokenizer    xml
dom        ctype         json         fileinfo     filter       hash
session    curl          iconv        gd *         simplexml    libxml
```

`*` = **composer cannot detect this one from a dependency.** `ext-gd`, `ext-pdo` and
`ext-pdo_mysql` are now declared directly for exactly that reason.

**Read `gd` carefully — its absence is silent and it is a security property.**
`FileStorageService::stripExif()` begins `if (! extension_loaded('gd')) { return $content; }`.
Without gd, EXIF stripping on the primary upload path returns the original bytes: employee
photographs and identity documents keep GPS coordinates, device serials and capture
timestamps, and are served back that way. No error, no log line. One unchecked box.

**One of `phar` or `zip` is required** — not both, and neither alone is mandatory.
`BackupService` tries `PharData`, falls back to `ZipArchive`, and throws a
`RuntimeException` naming the remedy if neither exists. A loud failure in one feature.

Nice to have, none blocking: `intl`, `sodium`, `xmlwriter`, `opcache`, `exif`.
`redis` is listed as optional and **is not used on this target**.

> **Corrected 2026-09-18:** `bcmath` was previously listed as mandatory. It is not
> required — it appears in `composer.lock` only under `suggest`, and the application calls
> no `bc*` function, because money is stored in integer minor units (`salary_cents`,
> `price_cents`). Do not enable it on our account.

---

## 2. Environment file

### Repository

**`api/.env.shared-hosting.example` is new and is the one to copy.**

`api/.env.production.example` is a `docker-compose.prod.yml` artifact — its own header says
so. It selects `redis` for cache, queue and session, `minio` for storage, `reverb` for
broadcasting, and a read-replica user, and it sets `REDIS_HOST=redis` and
`MINIO_ENDPOINT=http://minio:9000`, which are Docker service names that resolve to nothing
here. `DEPLOYMENT.md` step 4 says to copy it. **On this target that produces an
application that cannot boot.**

The shared-hosting template carries the identical key set — verified, no variable dropped —
with every conversion marked `# [shared-hosting]` and its reason.

| Key | VPS template | Shared hosting |
|---|---|---|
| `CACHE_STORE` | `redis` | `database` — live-verified on MariaDB 2026-08-31 |
| `QUEUE_CONNECTION` | `redis` | `database` |
| `SESSION_DRIVER` | `redis` | `database` |
| `BROADCAST_CONNECTION` | `reverb` | `null` |
| `FILESYSTEM_DISK` | `minio` | `local` |
| `DB_HOST` | `mariadb` | `localhost` |
| `DB_ROOT_PASSWORD`, `DB_READ_*`, `MINIO_*`, `REVERB_*`, `HORIZON_PREFIX` | set | empty |

`BROADCAST_CONNECTION=null` rather than `reverb` is deliberate: with `reverb` set and
nothing listening, a broadcast **throws**, so a successful write returns 500. `null`
degrades the feature instead of breaking the write.

### Plesk

Place the filled copy at `~/ethr/api/.env` — **inside `~/ethr/`, never under
the document root**. Set `APP_KEY`, `DB_*` and `MAIL_*`. Everything else has a working default.

---

## 3. Database

### Repository

`DB_CONNECTION=mariadb`, read from the environment throughout; no host is hard-coded. The
read/write split is disabled by leaving `DB_READ_*` empty, which is correct here.

### Plesk — *Databases*

Create one database and one user. Then, separately, the **`TRIGGER` privilege**: migration
`2026_07_22_000001` creates audit-log triggers and **aborts by design** without it. That is
a support request, not a panel setting — see
[`ETHIO-TELECOM-SUPPORT-REQUEST.md`](ETHIO-TELECOM-SUPPORT-REQUEST.md).

Server version and charset are **G0-I, NOT VERIFIED**. Below the floor (5.7.9 / 10.2.7)
`migrate` fails on the first migrations.

---

## 4. Scheduler and queue — **KNOWN BLOCKER**

### Repository

`routes/console.php` declares **14** scheduled entries; `app/Jobs/` holds **16** job
classes. `QUEUE_CONNECTION=database` needs no daemon — only something to drain it.

One command does both:

```
* * * * *   cd ~/ethr/api && php artisan schedule:run >> /dev/null 2>&1
```

### Plesk — *Scheduled Tasks*

**There is no Scheduled Tasks section on this subscription** (dashboard, 2026-09-18).
**G0-D = FAIL.** Until that changes, the scheduler never runs, the queue never drains, no
queued email is ever sent — and `key:generate`, `migrate`, `db:seed` and `ethr:create-admin`
have no runner, so the application cannot be *installed*.

Nothing in the repository can fix this. It is the **cron** and **SSH** asks of the support request.

---

## 5. Git deployment

### Repository

The layout maps directly: deploying to `/ethr/` yields `~/ethr/api/`, which is what the
runbook expects.

### Plesk — *Git*

**The Git repository was REMOVED from Plesk on 2026-09-18** (owner action, to be
reconfigured later). There is no deployment configured, so nothing can deploy anywhere —
which neutralises the worst setting found in this engagement rather than merely warning
about it.

**When you reconfigure it, the deployment path is `/ethr/` — and the directory to keep it
out of is whatever the document root currently is.** Deploying `main` into the document root
publishes the entire repository at the web root, **including `.git/` and `.env` if it is ever
committed** — which already happened once. Plesk's deployment path is relative to the
webspace root, so `/ethr/` resolves to `~/ethr/` and yields `~/ethr/api/`, which is what the
runbook expects.

> **The directory to avoid has changed twice in one day, which is the reason to state the
> rule and not the name.** It was `/httpdocs/` (where the removed configuration pointed, and
> where the repository was in fact published); then `ethr.et/`, once the served root was
> resolved on 2026-09-24; and it becomes `httpdocs/` again once the root is moved to Plesk's
> default. **The rule that held through all three: never deploy the repository into the
> served directory.** If you are reading this while the move is half-done, the answer is
> "both" — keep it out of `ethr.et/` and out of `httpdocs/` until you know which one is live.

**The near-miss is worth naming, because Plesk makes it easy.** The safe target is `/ethr/`,
and every candidate document root — `ethr.et/`, `httpdocs/` — is a directory Plesk is liable
to offer as the *default* deployment path. `/ethr/` and `ethr.et/` are one character apart.
Read the field back before saving.

Set the path **before** the first deploy, not after: Plesk runs deployment actions *after*
the files are written, so no action can guard the target.

Two repository-side defences now exist for the part of that incident that mattered:
`scripts/hosting-verification/ethr-hosting-check.php` refuses web execution without a
token, and `scripts/.htaccess` denies the directory. Neither contains a copy already on the
host.

Keep the deploy key **read-only**. Already done.

> **Open question, not an assumption.** Plesk offers to keep or delete the checked-out
> files when a repository is removed. Which happened here is unrecorded, so whether
> `~/ethr/` still holds the repository is **unknown**. It is answerable from *File Manager*
> in one look, and it decides whether reconfiguring is a fresh clone or a re-attach.

---

## 5a. Document root — **already correct; do not edit the field**

### Repository

Nothing to change. `shared-hosting/DEPLOYMENT.md` refers to the served directory as
`<DOCROOT>` throughout and sets its value in one place, so the repository follows the root
rather than asserting it.

### Plesk — *Websites & Domains → Hosting Settings*

**The document root is already `httpdocs/`.** Measured over HTTP on 2026-09-24 — twelve
requests, recorded in [`GATE-0-RESULT.md`](GATE-0-RESULT.md) → *Account evidence*. Every
directory under the root answers 403; every home-directory entry answers 404. `Document
root: /` is Plesk showing the path relative to the webspace root.

> **Corrected 2026-09-24.** This section previously said the root was `ethr.et/` and told
> you to move it to `httpdocs`. Both halves were wrong: it was already `httpdocs`, and
> editing that field is **B-7's Trap 1** — a no-op at best, and a value resolving to the
> home directory would web-serve `~/ethr/api/.env` and break certificate renewal. **Leave
> the field alone.**

**B-3 is unchanged.** `httpdocs/ethr.et/` is still unidentified. `/ethr.et/` returning 404
where existing directories return 403 is consistent with it being gone, but a 404 is not a
panel reading and deleting a vhost is not deleting a folder.

---

## 5b. Node.js — the chosen frontend branch

### Repository

`src/next.config.ts` sets `output: "standalone"`, so the build produces its own server. Two
Node versions are in play and **neither moves to fit the host**:

| Job | Version | Declared in | Account's 22.23.2 |
|---|---|---|---|
| CI build, gates, test suite | **24** | `.nvmrc` | does not satisfy — **not relaxed** |
| Frontend application runtime | **22** | `docker/frontend/Dockerfile` | **satisfies exactly** |

The host never runs the gates, so 22.23.2 is not a blocker for this branch. Build the
artifact on Node 22 to match the runtime.

### Plesk — *Node.js*

Read 2026-09-22: version **22.23.2**, npm, *Enable Node.js* and *Run Node.js commands* both
offered. Set **Application Startup File to `server.js`** — the panel read `app.js`, which is
Plesk's default and wrong for a Next standalone build. A mismatched entry point fails at
start, loudly, which is the good kind.

> **One thing is unverified and it decides the branch.** *Application URL* read
> `http://ethr.et` — the domain root. If Plesk mounts the Node application there, requests
> for `/api/v1/...` may never reach `index.php`, and the same-origin arrangement this layout
> depends on does not exist. **Do not infer the answer from Plesk's documentation.** Enable
> the app, fetch one API route and one frontend route, and see which process replies. The
> static-export fallback in `DEPLOYMENT.md` step 5 has no such question.

---

## 6. SSL, mail, storage

| | Repository | Plesk |
|---|---|---|
| **SSL** | `SESSION_SECURE_COOKIE=true`, `APP_URL` https | Certificate is live to 2026-12-15 and renewed on its own. **Do not delete `<DOCROOT>/.well-known/`.** ACME is served from the document root, so moving the root moves this requirement with it: **confirm `httpdocs/.well-known/acme-challenge/` exists before saving the new root**, or renewal fails silently in ~11 weeks |
| **Wildcard TLS** | per-tenant subdomains assumed | **Blocked** — the zone is on `ns1`/`ns2.telecom.net.et`, so Plesk cannot do DNS-01 |
| **Mail** | `MAIL_MAILER=smtp`, all values from env | Provide SMTP host and credentials. Outbound 587/465 is **G0-H, NOT VERIFIED** |
| **Storage** | `FILESYSTEM_DISK=local` → `api/storage/app`, outside the document root | Nothing to configure. Watch quota in *Statistics* |

---

## Summary

**Repository — done**

- `ext-gd`, `ext-pdo`, `ext-pdo_mysql` declared so composer aborts rather than failing late
- extension list corrected: 18 mandatory, `bcmath` removed, `phar`/`zip` stated as a pair
- `api/.env.shared-hosting.example` added, identical key set, VPS drivers converted
- probe refuses web execution; `scripts/.htaccess` denies the directory

**Plesk — yours**

1. Enable the 18 extensions (`gd`, `pdo`, `pdo_mysql` especially)
2. Copy `.env.shared-hosting.example` → `~/ethr/api/.env`, fill `APP_KEY`, `DB_*`, `MAIL_*`
3. Create the database and user
4. Reconfigure Git deployment when you are ready — path **`/ethr/`**, set before the first deploy (it was removed 2026-09-18)
5. **Document root — do nothing.** It is already `httpdocs`, measured over HTTP 2026-09-24. Editing that field is B-7's Trap 1 (§5a)
6. **Set the Node.js startup file to `server.js`** (§5b), then answer the Application-URL question
7. Send the support request — cron, SSH, `TRIGGER`

**Blocked on the provider**

- **G0-D** no Scheduled Tasks → no scheduler, no queue, no installation route
- **G0-F** no `TRIGGER` grant → `migrate` aborts by design
- **B-1** SSH Forbidden
