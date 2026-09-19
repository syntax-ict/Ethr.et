# Gate 0 — Real Plesk Feasibility Verification

**Status: NOT RUN against the account.** Some rows are already settled by external probing (see *Already settled*, below), so the panel session only has to measure what is genuinely unknown.

> ### Read this first: the stack reports `Server: nginx`
>
> `docs/B1-B5_GATE_REPORT.md` records the live response headers on `213.55.96.154` as **`Server: nginx`**, `X-Powered-By: PleskLin`.
>
> That is a **signal, not a verdict**. Plesk commonly runs nginx in front of Apache, in which case `.htaccess` is still honoured for whatever Apache handles. It also commonly serves static files from nginx directly, or runs nginx-only — in which case `.htaccess` does nothing at all, **and fails silently**.
>
> **G0-B is therefore the gate most likely to come back negative**, and it is the one the whole deployment currently rests on. `docs/deployment/shared-hosting/.htaccess` routes `/api/*` to Laravel's front controller, forwards the `Authorization` and `X-XSRF-Token` headers Sanctum needs, sets every security header, and denies the repository files.
>
> Of those, the **security headers are the silent failure** — the site works perfectly without them and nothing logs their absence. Routing failure is loud. The deny rules matter less than they first appear, because in this layout the application lives in `~/ethr`, outside the document root, so `.env` is not reachable either way; they are defence in depth. The canary README ranks all four.
>
> Run the canary early, and treat `curl -i https://<host>/.env` returning **403** as a necessary condition before anything is deployed.
>
> **If it fails, the answer is already written.** [`shared-hosting/nginx-directives.conf`](shared-hosting/nginx-directives.conf) translates the silently-failing half into Plesk's *Additional nginx directives* panel, and explains why it stops short of translating the routing rules. Unverified against any live host — it is a prepared answer, not a measurement.

**Target:** Ethio Telecom Linux shared hosting (Plesk) · account `ethret` · `213.55.96.154`
**Prepared:** 2026-09-15
**Run by:** owner (requires the Plesk account — this cannot be automated from the repository)

---

## Why this document is empty

Master plan §8: *evidence before implementation.* `docs/HOSTING_VERIFICATION_CHECKLIST.md` carries roughly sixty rows, every one `NOT VERIFIED`, and states the rule this file follows — **"NOT VERIFIED is not a soft yes."**

~~Nothing in this repository has ever touched the Ethio Telecom account.~~ **Corrected 2026-09-17** — see *Account evidence* below. The probe has still never been run, and every row in the table below is still unmeasured; but the account is not untouched, and the document root was not empty. Until the table has measured values, every downstream decision — static export or Node, `.htaccess` or nginx directives, which tier to buy — is a guess, and building on it risks a week of work on a fork that does not exist.

**Do not fill any row from documentation, vendor marketing, or inference. Only from output.**

---

## How to run it — three steps, about thirty minutes

> ### Do G0-A before Step 1
>
> G0-A lives in Step 3 because it is a panel question, but the panel section says to
> *answer it first* — and that is the order that matters, not the numbering. G0-A decides
> between roughly one day and one to two weeks of frontend work (see its own section
> below). Everything in Steps 1 and 2 is worth measuring either way; G0-A is the one
> answer that changes what the rest of the migration *is*. Two minutes, one directive,
> one `curl`.
>
> ~~Operator order: **G0-A → Step 1 (probe) → Step 2 (canary) → rest of Step 3 (panel).**~~
> The step numbers below are kept as they were so existing references still resolve.
>
> ---
>
> **Superseded 2026-09-18 — G0-A is no longer first, and this file disagreed with
> `MIGRATION_STATE.md` about it.**
>
> That banner put G0-A first; `MIGRATION_STATE.md`'s **MANUAL ACTION QUEUE** puts it
> **third**, behind *0a* and *Scheduled Tasks*. Two authoritative documents naming two
> different first actions, for a session whose whole scarcity is the operator's time.
>
> **The queue is right, and this banner is what moved.** Its reasoning — *"G0-A is the one
> answer that changes what the rest of the migration is"* — was true when written, before
> B-1 and B-4 were understood. It no longer is:
>
> | | Question it answers | If the answer is bad |
> |---|---|---|
> | **Scheduled Tasks** (queue 1) | *Can this migration be performed at all?* | No route runs `artisan`, so no `migrate`, no `key:generate`, no database import, no backup/restore, no queue, no scheduler — and five of `deploy-checklist.md`'s checks cannot run either |
> | **G0-A** (queue 3) | *How much frontend work?* | **An architectural frontend deployment change** — measured 2026-09-18 (`7aed9d2`), not the "bounded, already-scoped" change this row previously claimed |
>
> G0-A sizes the work. Scheduled Tasks decides whether there is work to size. And *0a* —
> is anything still serving at the VPS — precedes both, because it decides whether any of
> this can be **undone**; `ROLLBACK_RUNBOOK.md` now depends on that answer explicitly.
>
> **Authoritative order lives in `MIGRATION_STATE.md` → MANUAL ACTION QUEUE** (`0a → 0b →
> 1 … 8`). Follow it there rather than here, so this cannot drift again. G0-A is queue
> item 3, and it is still two minutes, one directive, one `curl` — it just is not the
> thing to do first.

### Step 1 — the capability probe (sensitive; keep it out of the web root)

`scripts/hosting-verification/ethr-hosting-check.php` prints `disable_functions`, database grants and the filesystem layout verbatim. It must not be web-reachable.

> **On this account, route A is unavailable.** SSH is **Forbidden** (blocker B-1), so the
> original instruction below cannot be followed. Three routes, in preference order:

#### Route A — SSH *(blocked here)*

1. Upload to the account's **home** directory `~/`, **not** `httpdocs/`.
2. Run over SSH:
   ```bash
   php ~/ethr-hosting-check.php \
     --db-host=localhost --db-name=<db> --db-user=<user> --db-pass=<pass> \
     > ~/gate0-probe.txt
   ```
3. Download `gate0-probe.txt`, then **delete both files from the server.**

Answers everything: G0-E, G0-F, G0-H, G0-I, G0-J and the storage rows. **This is the route
to restore** — *Hosting Settings → SSH access → `/bin/bash`* is one setting, and it clears
B-1 and B-4 together.

#### Route B — Scheduled Tasks *(depends on G0-D)*

Plesk → **Scheduled Tasks** → run once as a PHP CLI task, reading the mailed or logged
output. The probe's own header names this as the no-shell fallback. It answers everything
Route A does — **but only if G0-D offers a command-type or PHP-script task.** If Scheduled
Tasks is URL-fetch only, this route does not exist either. Read G0-D before relying on it.

**Read the output, not the panel's task status.** Until 2026-09-18 the probe exited `0`
unconditionally — including on a run whose own summary read *"Laravel 12 will not run
as-is."* Plesk judges a scheduled task by its exit status, so a probe that had found a
fatal gap would have been reported as a **successfully completed task**. Fixed, and the
contract is now:

| Exit | Meaning |
|---|---|
| `0` | nothing unsupported |
| `1` | at least one **MANDATORY** item unsupported — this account cannot run Laravel 12 as-is |
| `2` | no mandatory gap, but at least one other `FAIL` row: a degraded feature, a limit below what payroll or uploads need, or blocked outbound SMTP |

Exit status exists only under CLI. Over Route C the response is HTTP 200 and the body is
the whole report, so there the printed rows are the only signal.

#### Route C — web-served, credential-free *(works today, answers most of it)*

The last resort, and on this account it may be the only one. It is safe to take **provided
no database credentials are passed**, because the probe skips the database section
entirely when they are absent — it records `database checks · UNKNOWN · skipped` rather
than erroring (verified in the script at the `$dbHost === null` branch).

That matters because the reason this file called web-serving a last resort was *"a browser
request puts the database password in the access log."* Pass no credentials and that
hazard does not arise.

1. Upload to `httpdocs/` under a **random filename** — `httpdocs/<random>.php`, not
   `ethr-hosting-check.php`.
2. In the uploaded copy, set `ETHR_PROBE_WEB_TOKEN` near the top of the file to a second
   random value. **As shipped the constant is empty and every web request returns 403**,
   so this step is not optional — see the amendment below for why it was added.
3. Open `https://www.ethr.et/<random>.php?token=<that value>` and **pass no other query
   parameters** — in particular no database credentials.
4. Save the output.
5. **Delete the file in the same sitting.** Non-negotiable: it still prints
   `disable_functions` and the filesystem layout, which is why it normally lives in `~/`.
   Random filename plus immediate deletion is what keeps the exposure window to minutes.

**Amendment, 2026-09-18 — the token step is new, and it is there because the untokened
version was exploited by accident.** A Plesk Git deployment pointed at the document root
copied the entire repository into `httpdocs/`, so the probe became web-executable at its
own predictable path. The random filename — the whole of Route C's access control — was
bypassed not by guessing but by a deployment putting the real name there. The token is the
layer that survives that: a copy of this file reaching a document root unintentionally now
discloses nothing. `scripts/.htaccess` denies the directory as a second layer, and is
second because it is only honoured if `AllowOverride` permits it, which is G0-B.3 and is
still `NOT VERIFIED`.

Verified by running all three paths, 2026-09-18: shipped file over a web SAPI → 403, 14
bytes; wrong token → 403, 14 bytes; correct token → 200, 6,548 bytes, complete report. CLI
unchanged and still honours the exit contract above.

**Route C was validated by running it, 2026-09-17.** Served over PHP's built-in server
under a non-CLI SAPI: HTTP 200, clean `text/plain`, 6.5 KB, complete output, no query
string, database section correctly reported as skipped. It works. Two things that run
surfaced, both worth knowing before spending a panel session on it:

**It takes ~13 s on unremarkable hardware, and can plausibly exceed 30 s on the target.**
The arithmetic is from the script's own constants, not a guess: the network section waits
up to **6 s for HTTPS + 6 s x 2 for SMTP 587/465** (`fsockopen(..., 6)`), and a firewall
that DROPs rather than REFUSEs makes each one wait the full timeout — 18 s before the
performance section even starts, which measured 13 s here. Shared hosting commonly caps
`max_execution_time` at 30 s; the machine this was measured on is at 30 s, and the probe
flags that as FAIL against the 120 s ETHR wants.

**The two conditions that would time the probe out are the two conditions it exists to
detect** — blocked outbound ports (G0-H) and a slow CPU (G0-J). Worth stating plainly,
because a truncated run looks like a broken probe and is not one.

**It degrades in the right direction.** Section order is PHP -> Runtime -> Process ->
Filesystem -> Network -> Database -> **Performance last**. So a timeout costs the
performance rows and keeps everything before them:

| If the output ends... | You still have | You lost | Read it as |
|---|---|---|---|
| after the summary line | everything | nothing | normal |
| after the Network section, no Performance rows | G0-E, limits, filesystem, **G0-H** | G0-J | **not a failure** — `max_execution_time` was hit. The Limits section already printed that value; combined with the Network readings it is itself an implicit G0-J signal |
| mid-Network | G0-E, limits, filesystem | G0-H, G0-J | outbound is being silently dropped — re-run once to confirm |

Do not re-upload and retry more than once on a truncation. A second identical truncation
is a result, not a flake.

| Route C answers | Route C defers |
|---|---|
| **G0-E** — PHP version, all 18 mandatory extensions, `memory_limit`, `max_execution_time` | **G0-F** — `CREATE TRIGGER` (`DB4`) |
| **G0-H** — outbound 443, SMTP 587, 465 | **G0-I** — server version, `DB_CONNECTION`, version floor, charset (`DB1`, `DB1b`, `DB1c`, `DB10`) |
| **G0-J, CPU half** — `Performance/P1`, `P2`, `P3` | **G0-J, database half** — `P4`, `P5`, `P6` |
| Storage rows — `ST1`, `ST4`, `ST5` (`symlink()`), `ST6` | |

So Route C closes G0-E, G0-H and the storage rows outright, and half of G0-J. **G0-F and
G0-I stay open** — and G0-F is the one that aborts `migrate` by design, so it must be
answered before any deployment, by Route A or B with credentials.

Answers (all routes): G0-E, G0-F, G0-H, G0-I, G0-J, and the storage rows.

### Step 2 — the web-server canary (safe to be web-reachable)

The probe above sits in `~/` and is never served by Apache, so it structurally cannot answer *"is `.htaccess` honoured?"*. `scripts/hosting-verification/htaccess-canary/` is the opposite trade: it is web-reachable and discloses nothing — five booleans, no environment detail.

1. Upload all **five** files (`.htaccess`, `canary.php`, `secret.txt.probe`, `shadow.txt`, **`shadow.js`**) to `httpdocs/ethr-canary/`. `README.md` stays in the repository — it explains the baits, and there is no reason to publish that. **`shadow.js` was added 2026-09-18 and is the bait that counts**: without it, a host serving `.js`/`.css` directly but not `.txt` reports a false PASS on G0-B.5 and hides G0-B.2 with it.
2. Open `https://www.ethr.et/ethr-canary/canary.php` and follow the printed checks.
3. Run the four `curl` commands it gives you — **pinned to the Plesk host**, see below.
4. Save the output, **delete the directory.**

Answers: G0-B.

#### G0-B.2 measured on `canary.php` says nothing about static assets

**Found 2026-09-17 by reading the canary against the deployment `.htaccess`, before either
was run on the host.** This is a scope gap, not a defect in the canary.

The canary measures G0-B.2 by curling `canary.php` — a PHP file, which by definition
reaches Apache. But the Apache & nginx panel carries its own warning about *"Serve static
files directly by nginx"*: **"Requests for these files will be handled by nginx and never
reach Apache. Caution: Apache rewrite rules will not be applied."**

`docs/deployment/shared-hosting/.htaccess:144` applies far-future caching and
`Cache-Control: public, immutable` to exactly
`js|css|png|jpg|jpeg|gif|ico|svg|woff2?|ttf|eot` — which is precisely the extension set
such nginx lists contain by default. Two consequences follow, and both are silent:

1. **The `mod_expires` block may be dead code.** Static assets would get no far-future
   caching, the site would work perfectly, and nothing would log it.
2. **The security headers may never apply to static assets.** CSP, HSTS, X-Frame-Options
   and Permissions-Policy are set by `mod_headers` in the same file; a request that never
   reaches Apache never gets them.

So **a G0-B.2 PASS on `canary.php` does not mean headers reach a `.css` or `.js` file.**
The FAIL consequence for G0-B.2 already says to verify on three path types; this says the
static-asset check is required **even when G0-B.2 passes**, and it is the one most likely
to differ.

**Measure it with a file already on the host — no upload needed.** `httpdocs/favicon.ico`
exists (110.8 KB, dated 2026-07-25), and `.ico` is in the `FilesMatch` list above:

```bash
# Apache reaches this one — the canary's own G0-B.2 test
curl --resolve www.ethr.et:443:213.55.96.154 -sI \
  https://www.ethr.et/ethr-canary/canary.php | grep -i -E 'x-ethr-canary|x-frame-options'

# Does Apache reach this one?
curl --resolve www.ethr.et:443:213.55.96.154 -sI \
  https://www.ethr.et/favicon.ico | grep -i -E 'x-frame-options|cache-control|expires'
```

Headers on the PHP file but **not** on `favicon.ico` → nginx is serving static files
directly, the `mod_expires` block is inert, and the security headers must move to the
panel's *Additional headers* field (which **is** present on this account, unlike the
directive textareas) or to `nginx-directives.conf` §1. Headers on both → Apache is serving
static files too, and `.htaccess` governs everything.

Record the result against **G0-B.2** and cross-reference **G0-B.5**; the two are the same
mechanism seen from different ends.

#### Pin every G0-B request to the Plesk host

**Do not rely on DNS or on the vhost being the one you expect.** `docs/B1-B5_GATE_REPORT.md`
records the account host as **`213.55.96.154`** (`ethret` @ `lin6.ethiotelecom.et`); that
IP, not a hostname, is the repository's evidence for where this account lives. A request
that resolves elsewhere, or lands on a different vhost on the same box, measures the wrong
machine and returns a *plausible* answer — which is worse than an error.

So add `--resolve` to every G0-B command:

```bash
curl --resolve www.ethr.et:443:213.55.96.154 -sI https://www.ethr.et/ethr-canary/canary.php
```

Every check in the *Results* table and every command `canary.php` prints takes the same
flag. The canary fills in the hostname from the request it receives, so its printed
commands need `--resolve` added by hand.

Two reasons this is not paranoia. `docs/MIGRATION_STATE.md`'s DNS observations are dated
2026-08-29 and record the apex pointing somewhere else entirely, so the file cannot be
trusted as current on this point. And the same document records `zzq7x.ethr.et` reaching
the *server default* page rather than the `ethr.et` vhost — proof that this host already
serves more than one vhost, and that which one answers is exactly the thing in question.

#### If `canary.php` returns 404 — stop, and do not call it a G0-B failure

A 404 after a successful upload means the request is **not being served from the directory
you uploaded into.** That is a statement about the document root and the vhost, not about
whether `.htaccess` is honoured. **None of G0-B.1 – G0-B.5 is meaningful until it is
resolved**, because all five are measured through a file that is not being reached: a 404
on `secret.txt.probe` would read as "not a pass", a 404 on `REWRITE_OK` would read as
"`mod_rewrite` off", and both conclusions would be wrong.

Record it as its own result, against the `document root editable` row (currently
`NOT VERIFIED`) rather than against any G0-B row, then:

1. **Websites & Domains → Hosting Settings** — read the *Document root* field verbatim. It
   may not be `httpdocs`.
2. Establish which vhost answered. Compare a request for `www.ethr.et` against one for a
   name known to hit the server default (`zzq7x.ethr.et`, per `B1-B5_GATE_REPORT.md`). If
   they return the same page, the `ethr.et` vhost is not the one serving you.
3. Re-upload all **five** canary files into the **confirmed** document root — `.htaccess`, `canary.php`, `secret.txt.probe`, `shadow.txt` and `shadow.js`, as step 1 of the upload instructions above already says. *(Corrected 2026-09-19: this row said "four" while the same document said five 96 lines earlier. `shadow.js` is the bait that counts, so a recovery path that quietly drops it hands back a false PASS on G0-B.5.)*
4. Re-open `canary.php`. Only once it loads do G0-B.1 – G0-B.5 mean anything — then run
   them, with `--resolve`.

If the document root cannot be pointed at a directory you can write to, that is a finding
in its own right: it blocks `DEPLOYMENT.md` step 4a, which assembles `~/httpdocs/`, and it
blocks G0-B with it.

### Step 3 — the Plesk panel checklist

The questions no script can reach. Section below.

Answers: G0-A, G0-C, G0-D, and the tier comparison.

---

## Account evidence — 2026-09-17

**The first observations ever taken from the real account.** Panel readings and a File
Manager listing, reported by the owner. None of it is probe or canary output, so **no
gate moves**; what it does is resolve rows the repository had left open, and surface four
blockers.

### Resolved

| Item | Result | Previously |
|---|---|---|
| **SSH / shell access** | **FORBIDDEN** — Hosting Settings says so, and Dev Tools offers no Terminal | *"Strong evidence, unconfirmed — Plesk can still set the shell to `/bin/false`; confirm by logging in"* (the row below). The repository asked the right question and the answer is no. |
| **PHP version** | **8.3.33** — satisfies Laravel 12's `^8.2` | NOT VERIFIED. Extensions and limits remain unmeasured; this closes the version row only. |
| **Composer** | Present as a Plesk extension | inferred from `~/.composer` |
| **Git** | Present as a Plesk extension | not recorded |
| Imunify, Web Application Firewall | Present | not recorded — **may influence G0-B.2 and G0-B.3 readings**, since a WAF can add or strip headers independently of `.htaccess` |

### Document root — CONFIRMED `httpdocs`

Hosting Settings displays `Document root: /`, which read literally would mean the home
directory is web-served and `~/ethr/api/.env` reachable over HTTP. **It does not.**
`.well-known/acme-challenge/` was observed **inside** `httpdocs/`, and an ACME challenge
can only be served from the document root. The certificate is live and renewing, so this
is current evidence.

Plesk's `/` is therefore **relative to the webspace root**. `B1-B5_GATE_REPORT.md:79`, W6,
the `parent of docroot writable` row below and `DEPLOYMENT.md` §0 all stand unchanged.

*This evidence is no longer re-observable in the same form — the document root was cleared
later the same evening. The observation was made and recorded first, which is the only
reason the conclusion survives.*

### Apache & nginx Settings — three readings

1. **Proxy mode is ON** — *"nginx proxies requests to Apache."* Apache is in the request
   path, so `.htaccess` **is** processed. G0-B.1–B.4 are live questions rather than dead
   ones, which is the more hopeful half of the `Server: nginx` warning at the top of this
   file.
2. **"Serve static files directly by nginx"** exists, carrying Plesk's own warning:
   *"Requests for these files will be handled by nginx and never reach Apache. Caution:
   Apache rewrite rules will not be applied."* That **is** G0-B.5. The panel answers it
   without the canary — **its current value is still unread**.
3. **Neither "Additional directives for HTTP/HTTPS" nor "Additional nginx directives"
   appears**, on a page that otherwise renders every field with its Default/custom toggle.
   In Plesk both are gated by a service-plan permission. **Strong evidence that G0-A =
   FAIL. Not confirmed** — the reading came from a flattened page capture, and the bottom
   of the page has not been read.

### The account was not untouched

- `httpdocs/backend/` held a **stock Laravel + Breeze scaffold, not ETHR** — it carries
  `tailwind.config.js`, `postcss.config.js` and `CHANGELOG.md`, none of which exist in
  `api/`, and lacks `lang/`, `scripts/`, `phpstan.neon`, `phpunit.mysql.xml` and
  `Dockerfile.prod`, all of which `api/` has. Dated 2026-09-05, **no `.env`**, **no
  `vendor/`** — so no credential exposure, and it could not run. Removed by the owner.
- `httpdocs/dist/`, `httpdocs/public/` and `httpdocs/et/` also removed. The last two were
  the ones `B1-B5_GATE_REPORT.md` flagged as *"pre-existing and unexplained… confirm as
  disposable before the docroot is populated"*; that confirmation never happened. `et/`
  was recorded empty.
- `~/production.ethr.et/` is a **second vhost** at home level. `~/git/` exists. The home
  directory carries a chroot skeleton (`bin`, `etc`, `lib`, `lib64`, `usr`, `var`).
- `httpdocs/` is now essentially a pristine Plesk default — `.well-known/`, `cgi-bin/`,
  `css/`, `favicon.ico`, all stamped 2026-07-25, the vhost provisioning date. That is a
  **better** starting point for G0-B than what preceded it.

### Blockers

| # | Blocker | Effect |
|---|---|---|
| **B-1** | SSH **Forbidden** | The probe has no shell route. Its repo-defined fallback — *Scheduled Tasks as a one-off PHP CLI task* — depends on **G0-D, unverified**. |
| **B-2** | No directive fields on Apache & nginx Settings | G0-A cannot be run as written. See the amended consequence below. |
| **B-3** | `httpdocs/ethr.et/` — a Plesk-provisioned vhost skeleton created 2026-09-17 23:48, document root **inside** `httpdocs/` | Purpose unknown; possible collision with the live `ethr.et` vhost carrying the certificate; inverts the `~/ethr` layout. Identify what was created in the panel before removing it — deleting a vhost is not deleting a folder. |
| **B-4** | No route to run `artisan` | `key:generate`, `migrate`, `db:seed`, `ethr:create-admin` (`DEPLOYMENT.md` step 4) have no non-shell equivalent defined anywhere in this package. |

**B-1 and B-4 are one support request**, and it reframes G0-D. Without a shell, Scheduled
Tasks is no longer just how the scheduler runs — **it is the only way to migrate the
database at all.** If G0-D returns "Fetch a URL only", there is no documented route to
perform this migration, and that is a hard blocker rather than the costed design change
the rest of this package describes. **G0-D is now the highest-value panel read.**

---

## Results

Fill `Actual` and `Status` from real output. Cite the evidence — `probe:DB4`, `canary G0-B.3`, `panel screenshot`.

| # | Capability | Required | Actual | Status | Evidence |
|---|---|---|---|---|---|
| **G0-E** | PHP version | >= 8.2 | **8.3.33** (2026-09-17) | **PANEL-READ** — satisfies `^8.2`; not probe output | panel |
| G0-E | **18** mandatory extensions | **all 18 present** — list below; was 20 until `bcmath` and `zip` were corrected 2026-09-18 | | NOT VERIFIED | probe `PHP/ext` rows |
| G0-E | `memory_limit` | >= 256M | | NOT VERIFIED | probe |
| G0-E | `max_execution_time` | >= 120s | | NOT VERIFIED | probe |
| **G0-B.1** | `mod_rewrite` honoured | yes | | NOT VERIFIED | canary |
| **G0-B.2** | `mod_headers` honoured | yes | | NOT VERIFIED | canary |
| **G0-B.3** | `.htaccess` deny rules enforced | **403** | | NOT VERIFIED | canary |
| **G0-B.4** | `Authorization` reaches PHP | yes | | NOT VERIFIED | canary |
| **G0-B.5** | a real file shadows the rewrite | *record which* — no failing answer | *panel answers this directly — "Serve static files directly by nginx", value unread* | NOT VERIFIED | canary **or panel** |
| **G0-A** | reverse proxy for `/api/` | permitted | neither directive textarea present on the settings page (2026-09-17) — **strong evidence of FAIL, unconfirmed** | NOT VERIFIED | panel |
| **G0-C** | wildcard subdomain `*` as one vhost | works | DNS half **PASS** (2026-08-29, re-confirmed 2026-09-17); panel accepts `*` per **owner report**, not a measurement; vhost not yet created | PARTIAL | B1-B5 + panel |
| G0-C | wildcard TLS | issued | per-hostname **PROVEN**; wildcard blocked, needs DNS-01 | PARTIAL | B1-B5 |
| **G0-D** | cron type | "Run a command" | **No Scheduled Tasks / Task Scheduler / Cron Jobs section exists on the subscription dashboard** (owner-read 2026-09-18). The listing is otherwise complete — Files, Databases, FTP, Backup &amp; Restore, Website Copying, Statistics, Dev Tools, PHP 8.3.33, Logs, Git, PHP Composer, Security/SSL, Imunify, Password Protected Directories — and *Dev Tools* was separately read on 2026-09-17 (PHP, Git, Composer; no Terminal). | **FAIL — strong evidence, one confirmation short** | panel |
| G0-D | minimum cron interval | <= 1 min | | NOT VERIFIED | panel |
| **G0-F** | `CREATE TRIGGER` permitted | yes | | NOT VERIFIED | probe DB4 |
| **G0-G** | Node.js (build only) | >= 20.9 is Next's floor — **this repo pins 24**, see below | | NOT VERIFIED | probe / panel |
| **G0-H** | outbound SMTP 587/465 | open | | NOT VERIFIED | probe |
| G0-H | mailbox send cap | known | | NOT VERIFIED | panel |
| **G0-I** | `SELECT VERSION()` | verbatim | | NOT VERIFIED | probe DB1 |
| G0-I | `DB_CONNECTION` to use | `mysql` or `mariadb` | | NOT VERIFIED | probe DB1b |
| G0-I | version floor | >= 5.7.9 / 10.2.7 | | NOT VERIFIED | probe DB1c |
| G0-I | server charset | `utf8mb4` | | NOT VERIFIED | probe DB10 |
| **G0-J** | CPU 3M-loop | < 400 ms | | NOT VERIFIED | probe **Performance/P1** |
| G0-J | 1000 inserts / txn | < 3000 ms | | NOT VERIFIED | probe Performance/P4 |
| G0-J | 200 indexed SELECTs | < 1500 ms | | NOT VERIFIED | probe Performance/P5 |
| — | disk quota | measured | | NOT VERIFIED | panel |
| — | database size limit | measured | | NOT VERIFIED | panel |
| — | document root editable | yes | | NOT VERIFIED | panel |
| — | `symlink()` works | yes | | NOT VERIFIED | probe ST5 |
| — | parent of docroot writable | yes | **PASS** — home sits above `httpdocs` | VERIFIED | B1-B5 |

### G0-E — the extension list, in full

The probe emits one `PHP/ext` row per extension and marks it `VERIFIED` or `UNSUPPORTED`.
Reproduced here in full rather than as a count, because when one is missing the operator
needs its **name** to raise the right request with Ethio Telecom support — and "18
extensions" is not a name. Source of truth is `scripts/hosting-verification/ethr-hosting-check.php`;
this list is that file's, not a new requirement.

**Mandatory — absence of any one means Laravel 12 will not run, or will not install:**

```
pdo *      pdo_mysql * mbstring   openssl
tokenizer  xml         dom        ctype
json       fileinfo    filter     hash
session    curl        iconv      gd *
simplexml  libxml
```

`*` marks the three **no production package declares**, so `composer install` could not
detect them missing. They are now declared directly in `api/composer.json`
(`ext-gd`, `ext-pdo`, `ext-pdo_mysql`), which makes the install abort instead of
succeeding into an application that fails later. The other 15 were always enforced
transitively.

**Two entries left this list on 2026-09-18, both by measurement. The count is 18, not 20.**

**`bcmath` was never required.** It appears in `api/composer.lock` four times and every one
is under `suggest` — *"to improve IPV4 host parsing"*, *"Enables faster math with
arbitrary-precision integers"*, *"For comparing BcMath\Number objects"* — never under
`require`. The application calls no `bc*` function anywhere, because money is stored in
integer minor units (`salary_cents`, `price_cents`), which is why it never needed arbitrary
precision. Asking Ethio Telecom to enable it was asking for something nothing uses.

**`zip` moved to the optional list, because the requirement is `phar` OR `zip`.**
`BackupService` tries `PharData` first, falls back to `ZipArchive`, and if neither exists
throws a `RuntimeException` naming the remedy. A loud failure in one feature is not a
deployment blocker, so neither is mandatory alone — but the *pair* is, and only `zip` was
ever listed. `phar` was absent from both lists entirely while being the first choice.

**`simplexml` and `libxml` were added on 2026-09-17, and the reason matters.** The list
was cross-checked against `api/composer.lock` — every `ext-*` that a *production* package
declares. Two were missing, and **`simplexml` was in the optional list**, which is the
wrong direction to be wrong in: it is a hard requirement of `aws/aws-sdk-php`, pulled in by
`league/flysystem-aws-s3-v3`, which sits in `require` rather than `require-dev`.

`composer install --no-dev` **validates platform requirements and aborts on a missing
one**. So a host without `simplexml` cannot have dependencies installed at all — and the
probe would have reported it as an optional nicety. That failure would have surfaced at
deployment, after Gate 0 had passed.

Note that the S3 adapter is not *used* on this target (`FILESYSTEM_DISK=local`), but it is
still installed, and composer checks the platform requirements of what it installs, not of
what you call.

**`ext-pcre` is deliberately not listed.** `aws/aws-sdk-php` and `vlucas/phpdotenv` declare
it, but PCRE is compiled into PHP core and cannot be absent, so a row for it could never
fail.

**Wanted but not fatal** — record which are present; none blocks deployment:

```
intl   sodium   xmlwriter   redis   opcache   exif
```

**`gd` is deliberately in the mandatory list, and it is the one to read carefully.** Its
absence is *not* fatal to booting Laravel — it is fatal to a feature, silently.
`FileStorageService` returns the original bytes and skips thumbnail generation, so uploads
keep working, nothing errors, and nothing logs it; the product just quietly stops
compressing images and generating thumbnails. The probe's own comment says this is what
makes it more dangerous than a hard failure, not less. `redis` sitting in the *optional*
list is the mirror image: the shared-hosting target does not use it
(`MIGRATION_STATE.md` D2), so its absence is expected and means nothing.

### A note on probe IDs — read the section, not just the number

The probe numbers IDs **within** sections, and two collide across them:

| ID | Section `PHP` | Section `Performance` |
|---|---|---|
| `P1` | PHP >= 8.2 | CPU: 3M-iteration loop |
| `P2` | SAPI / handler | CPU: 20k string+hash ops |

The *Results* table above now cites `probe PHP/P1` and `probe Performance/P1` explicitly.
When transcribing results, carry the section name — otherwise a CPU figure lands in the
PHP row, or a PHP version in the performance row, and both read as plausible.

### Already settled — do not re-measure

External probing on 2026-08-29 (`docs/B1-B5_GATE_REPORT.md`) closed these without account access:

| Item | Result | Evidence |
|---|---|---|
| Wildcard **DNS** | **PASS** | A wildcard `A` record resolves for names never configured |
| App outside the document root | **PASS** | Home sits a level above `httpdocs`, so `.env` and `storage/` are unreachable over HTTP by construction |
| Plesk panel | **CONFIRMED** | Port 8443 open, `X-Powered-By: PleskLin` |
| MySQL not internet-exposed | **CONFIRMED** | Port 3306 refused — connections must use `DB_HOST=localhost` |
| Let's Encrypt via HTTP-01 | **PROVEN on this account** | A valid per-hostname certificate is already live; `httpdocs/.well-known/` corroborates the challenge path |
| Wildcard TLS | **BLOCKED, understood** | Needs DNS-01, and the zone is on `ns2.telecom.net.et`, not Plesk. Per-hostname issuance is the fallback — budget for Let's Encrypt's 50-certs-per-week ceiling |
| Composer | **CONFIRMED** | Present as a Plesk extension (2026-09-17) |
| SSH / shell | **FORBIDDEN** | Hosting Settings, 2026-09-17. The `/bin/false` caveat this row warned about is what happened. Blockers B-1 and B-4. |

### What G0-B does and does not cover — clarified 2026-09-17

G0-B's four checks measure **mechanisms**: does Apache read `.htaccess` at all, and are
`mod_rewrite`, `mod_headers`, the deny rules and `Authorization` forwarding honoured.
That is the right scope for a pre-deployment canary, which runs from
`httpdocs/ethr-canary/` against an otherwise empty document root.

It does **not** cover which file ends up answering a given public path once the real
document root is assembled, and that is a separate failure mode with its own silent
case. `DEPLOYMENT.md` step 4a now writes the assembly out and gives the per-path table
for `/api/*`, `/`, `/robots.txt`, `/sitemap.xml` and `/.well-known/` under both B5
branches; `deploy-checklist.md` → *Public paths* verifies it after deployment.

The specific case found on 2026-09-17: this runbook's §0 listed `robots.txt` as copied
from `api/public/` into the shared document root. On the VPS that file serves the API
vhost and reads `User-agent: * / Disallow:`. Copied here it would be served at
`https://www.ethr.et/robots.txt`, silently replacing the frontend's generated
`robots.txt` — losing the `Disallow` list and the `Sitemap:` pointer, with the site
fully functional and nothing logged.

**Corrected, and then pinned**, because the first correction was itself incomplete in the
way prose is: it named `robots.txt` and `favicon.ico` and missed `api/public/.htaccess`,
which `ls` does not show and whose stock Laravel catch-all would swallow every frontend
route. `api/tests/Feature/DocumentRootInventoryTest.php` now enumerates `api/public/`
against a manifest and fails on any file with no recorded decision — the same shape as
`Security/TenantScopeBypassInventoryTest`, and for the same reason: a rule that only
exists in prose is one edit from being wrong again. It found the `.htaccess` omission on
its first run.

That test asserts **repository shape**, not host behaviour. It cannot tell you anything
about Ethio Telecom's server, and it is not evidence for any row in the table above.

**This changes no G0-B row.** Every one of them remains `NOT VERIFIED` and requires the
Plesk account. `G0-B.5` is new and equally unverified — added because the runbook had
asserted the shadowing behaviour as fact, and the canary can measure it in the same
session as the other four.

**Every FAIL needs four lines recorded underneath it: impact · workaround · decision · owner action.** A FAIL with no decision is an open gate, not a closed one.

---

## Plesk panel checklist

Thirty minutes of clicking. Record the answer, not the impression.

### G0-A — reverse proxy *(answer this first)*

**Websites & Domains → Apache & nginx Settings → Additional nginx directives.**

This single answer swings roughly two weeks of work, so it is worth doing before anything else. The frontend's axios client uses a **relative** `baseURL: "/api/v1"` (`src/src/api/client.ts:14`) and reaches Laravel only through the Next.js `rewrites()` proxy. Remove the Node server and something must replace that proxy.

Paste a harmless test directive and save:

```nginx
location = /ethr-proxy-probe { return 200 "proxy-directives-accepted"; }
```

- Saves without error, and `curl https://<host>/ethr-proxy-probe` returns the string → **PASS**. Remove the directive afterwards.
- Panel rejects it, or the field is absent/read-only → **FAIL** → the frontend must go cross-origin: CORS with credentials, `SameSite=None; Secure`, Sanctum stateful-domain config, a widened CSP, and the service worker's API cache silently stops working (`src/public/sw.js:59` intercepts same-origin only). Roughly one day becomes one to two weeks, and it needs an auth-security review.

### G0-C — wildcard subdomain

> **Two documents disagreed about this gate, and this is the resolution.**
> `MIGRATION_STATE.md:131` records `B1b wildcard vhost VERIFIED PASS (owner: '*' accepted;
> not yet created)` and its NEXT ACTION says *"B1b is answered … do not put that question
> again"* — quoted from the 2026-09-19 rewrite; it read *"already answered — do not
> re-ask"* before, with the same meaning. This
> file records the same gate `PARTIAL`. Both describe the same fact and grade it
> differently: an **owner report** that the panel accepted the literal name `*`, with **no
> vhost actually created** and no output recorded.
>
> **This file governs**, per its own rule at the top: *"Do not fill any row from
> documentation, vendor marketing, or inference. Only from output."* An owner report is
> testimony, not output, and the distinction is the whole point of Gate 0.
>
> What that means in practice is narrow, so it is worth stating: **do not re-ask the owner
> whether `*` is accepted** — that would be re-litigating an answer already given. Do
> **create** the wildcard subdomain during this session and record what the panel does,
> which is the step neither document claims has happened. The DNS half is independently
> re-confirmed (2026-09-17: `zzq7x.ethr.et` resolves to `213.55.96.154`).

**Websites & Domains → Add Subdomain**, name it `*`.

ETHR is multi-tenant by subdomain. If a literal `*` vhost is not accepted, each tenant needs its own Plesk entry and the plan's subdomain cap becomes a **hard limit on how many tenants the product can have** — 5 on Bronze, 10 on Silver, unlimited only at the top tier. That is a business ceiling, not a hosting detail, and it should be settled before any tier is purchased.

Also check **SSL/TLS Certificates**: a wildcard certificate needs DNS-01, and the `ethr.et` zone is on `ns2.telecom.net.et`, not Plesk — so automatic issuance is likely impossible and renewals may be manual. Confirm rather than assume.

### G0-D — cron

**Tools & Settings → Scheduled Tasks** (or **Websites & Domains → Scheduled Tasks**).

- Which task types are offered — "Run a command", "Fetch a URL", "Run a PHP script"?
- Minimum interval? Every minute, or only every 5/15/30?
- Full path to the PHP binary as Plesk invokes it.

Everything asynchronous depends on this: the scheduler, the queue worker, invoice generation, leave accrual, payslip notifications. **"Fetch a URL" only** means the scheduler and queue must move behind an authenticated HTTP endpoint — a real design change that is currently unbuilt, and it must be costed rather than assumed.

### G0-G — Node.js

**Websites & Domains → Node.js.** Present at all? Which versions? Can an app be started, or is it build-only?

Needed only to *build* the frontend, unless the answer to G0-A forces a Node server.

> **Record the exact versions offered, and do not read "≥ 20.9" as the bar.** That figure
> is Next.js 16's own floor. This repository pins **Node 24** in `.nvmrc`, and CI reads it
> via `node-version-file` — because `CLAUDE.md` records that **Node 20 reached end of life
> on 2026-04-30** and that two frontend test files fail on Node 20 *and* 22. That was cause
> 5 of the five structural defects that kept CI red for fifty runs; it is measured, not
> theoretical.
>
> So: **Node 20.x offered is not a pass.** It would mean building production assets on an
> end-of-life runtime that this repository has measured as broken for its own suite. If 24
> is unavailable, record what *is* offered and treat the gap as a finding rather than
> rounding it up to "≥ 20.9, fine."


### Tier comparison — and the question that decides whether any of this is worth doing

Record for the current plan **and each tier above it**: disk, bandwidth, database count, **database size limit**, subdomain count, and whether PHP versions / Node / cron granularity / proxy directives **differ by tier** (they often do — G0-A, G0-D and G0-G may all have different answers on a higher plan).

Then the one that matters most:

> **What is the actual price, and is it per month or per year?**

`docs/TCO_COMPARISON.md` opens by flagging that the portal quotes ETB 452–1,009 with the period unstated, while a third-party directory says Bronze is ETB 650/month and Silver 2,000/month — and warns: *"If the monthly reading is right … the migration costs more money, loses functionality, and adds risk."* One question, and it decides whether this migration has a rationale at all.

---

## Consequences — decided in advance, so results are read honestly

Written now, before any number exists, so a disappointing result cannot be argued into a pass.

| Gate | If it fails |
|---|---|
| **G0-E** | **Terminal.** Laravel 12 requires PHP `^8.2`. If the host caps at 8.1 with no upgrade path, it cannot run ETHR at any tier. Stop and re-evaluate the target. Do not attempt a framework downgrade. |
| **G0-B.2** | **The silent one.** CSP, HSTS, X-Frame-Options and Permissions-Policy stop being sent and nothing reports it. Paste [`shared-hosting/nginx-directives.conf`](shared-hosting/nginx-directives.conf) §1, then verify the headers arrive on **three** path types — an HTML route, a static asset, an API response. nginx `add_header` does not inherit into a location that has one of its own, so one passing URL proves nothing about the others. |
| **G0-B.3** | Paste [`shared-hosting/nginx-directives.conf`](shared-hosting/nginx-directives.conf) §2 and do not deploy until `/.env` returns **403**. A 404 is not a pass. Lower severity than it reads: in this layout `.env` sits outside the document root, so the deny rules are the second line, not the first. But a host that ignores them ignores G0-B.2 as well, which is the real damage. |
| **G0-A** | **Amended 2026-09-17 — the original text was overstated.** It read *"frontend goes cross-origin; ~1 day becomes ~2 weeks plus an auth-security review"*, which is true **only if the Node server stays** (B5 = yes). Under B5 = no the frontend is static files in the *same* document root as `index.php`, and `shared-hosting/.htaccess` already routes `^/(api\|sanctum)` to the front controller — **same-origin, no nginx directives required**. `SHARED_HOSTING_AUDIT.md` §E says so itself about `rewrites()`: *"In production nginx already routes `/api` to PHP before the SPA sees it… `.htaccess` must reproduce this."* So a FAIL does not force cross-origin; it forecloses Branch A and makes **static export the way to stay same-origin** — **an architectural frontend deployment change — re-costed 2026-09-18 (`7aed9d2`) after the export was actually attempted, and the earlier "bounded change already scoped in §E and D6" wording is withdrawn.** The export **does not build**: `app/manifest.ts` needs a `force-static` directive (§E missed it), and the four `[id]` routes are `"use client"`, which Next forbids combining with `generateStaticParams` — so the prescribed one-line addition is not implementable and each route needs a server-component split. Beyond the build, those ids are **tenant data**, so `generateStaticParams` can only return `[]` and every real `/employees/123` 404s; the routes need client-side routing. Still true and unchanged: delete `middleware.ts`, client-side host read in `(auth)/layout.tsx`, marketing pages lose SSR. It also reduces **G0-G to build-only Node**. Cross-origin remains the cost only if Branch A is chosen anyway. |
| **G0-C** | Per-tier subdomain cap becomes a hard tenant cap. Settle before purchasing a tier. |
| **G0-D** | Scheduler and queue move behind an authenticated HTTP endpoint. Unbuilt; must be costed. |
| **G0-F** | `migrate` aborts by design (`2026_07_22_000001`). Raise a support request for the `TRIGGER` grant — it is not a code change. Note the separate `DEFINER` hazard in `docs/audit/BASELINE.md` §13b. **Less likely to fail than it looks — see below.** |
| **G0-G** | Frontend must ship as static files. **Not bounded work** — measured 2026-09-18 (`7aed9d2`): the export does not build, and making it build still leaves entity routes 404ing because their ids are tenant data. See `SHARED_HOSTING_AUDIT.md` §E *MEASURED 2026-09-18*. |
| **G0-H** | Mail may cap tenant count before CPU or storage does, and the queue dead-man's-switch needs a non-email alert path. |
| **G0-I** | Below the floor, `migrate` fails on the *first* migrations (255-char `utf8mb4` primary keys at 1020 bytes vs the old 767-byte limit). |
| **G0-J** | Payroll already runs synchronously in the request (`PayrollController.php:38`) against a documented *dedicated-hardware* budget of 30s for 500 employees. A slow shared CPU makes queueing it mandatory rather than advisable. |

### G0-F — what a non-root user can actually do **[measured 2026-09-16, MariaDB 10.4.32, local]**

G0-F is still `NOT VERIFIED` on the host and nothing below changes that. But the gate reads as more dangerous than the evidence supports, so:

A user holding **`GRANT ALL PRIVILEGES ON <database>.*` and nothing else — no `SUPER`, no global grants** — runs `2026_07_22_000001` successfully. Both `audit_log` triggers are created, and the recorded definer becomes that user:

```
audit_log_no_update  definer=ethr@localhost
audit_log_no_delete  definer=ethr@localhost
```

`TRIGGER` is part of `ALL PRIVILEGES` on a schema and does **not** require `SUPER`. `SUPER` is only needed to name a definer *other than yourself* — which is the §13b restore hazard, and is why `DatabaseDumper` emits no `DEFINER` clause.

So the failure mode G0-F guards against is not "shared hosting withholds `TRIGGER`" in general; it is a provider that grants something narrower than `ALL` on the account's own database. Plesk's default is `ALL` on the databases it creates. **Still run the probe** — this is an argument about priors, not a measurement of Ethio Telecom's server.

Evidence: `.github/workflows/gates.yml`, job `backend-mysql`, which connects as a non-root user for exactly this reason and migrates on every push.

---

## After the run

1. Fill this table. Every row gets a value and a date.
2. Update `docs/HOSTING_VERIFICATION_CHECKLIST.md` from `NOT VERIFIED` to measured.
3. Confirm the probe and canary are **deleted from the server**.
4. Record the tier decision and its reason.
5. Only then unfreeze the fenced work: `docs/deployment/shared-hosting/*` stays untouched until the facts exist, because editing it now means writing it twice.

   **The freeze stands. One narrow exception has been taken, and it is recorded here rather than left as a silent contradiction.**

   The rule exists so that *branch-dependent* content is not written twice — anything whose text depends on how B3 or B5 resolves. It was never written to cover a step that is **absent under every branch**, and on 2026-09-17 exactly that was found: §0 of `DEPLOYMENT.md` drew the document root and named its contents, but no step in the runbook assembled it. Steps 3–4 fill `~/ethr/api/`, step 5 deploys the frontend, and nothing in between copied `index.php` or `.htaccess` into `~/httpdocs/`. Every rule G0-B measures is inert until that file is in place, so the gate rested on a step that did not exist — and leaving it that way until Gate 0 runs would have meant running Gate 0 against a deployment nobody could perform.

   Scope of the exception, deliberately narrow:

   - `DEPLOYMENT.md` — step 4a (document-root assembly) and the §0 file list it corrects.
   - Nothing branch-dependent was touched. Step 4a's B5 = yes / B5 = no material is written as the same explicit branches the rest of the package already uses, so it is not written twice either.
   - No gate row was filled, and no gate moved.

   **This is not a general unfreeze.** `ENVIRONMENT.md`, `nginx-directives.conf`, `.htaccess`, `health-check.md` and `rollback.md` remain frozen. A further exception needs the same test this one passed: *is the defect branch-independent, and does leaving it block Gate 0 itself?* If the answer to either is no, wait for the facts.
