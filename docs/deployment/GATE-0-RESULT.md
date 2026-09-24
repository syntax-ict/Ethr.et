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

> **G0-D WAS READ — 2026-09-18 — and neither condition applies. ROUTE B DOES NOT EXIST.**
> There is no *Scheduled Tasks* section on this subscription at all: not command-type, not
> URL-fetch, absent. See the G0-D row in the gate table below. With Route A Forbidden and
> Route D withdrawn when the Plesk Git repository was removed, **Route C is the only live
> probe route.** The paragraphs below are kept because they describe how to read a
> scheduled task's output correctly, which applies again the moment the **cron** or **SSH** ask of
> `ETHIO-TELECOM-SUPPORT-REQUEST.md` is granted.

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
   **This is the baseline reading and it does not answer G0-B.1** — the script reports that
   row as `[ ???? ]` until it is reached through the rewrite. See step 3.
3. Run the checks it gives you — **pinned to the Plesk host**, see below. **Five checks,
   six fetches**, because G0-B.5 needs both baits. *(Corrected 2026-09-23: this said "the
   four `curl` commands", which is the same undercount the file already corrected once for
   the file list — and dropping one leaves a gate unanswered rather than erroring.)*
   **G0-B.1 is answered by `/REWRITE_OK` and by nothing else**: `canary.php:26` sets
   `$rewriteHit` from `isset($_GET['rewrite'])`, and only
   `RewriteRule ^REWRITE_OK$ canary.php?rewrite=1` supplies it, so opening the script by
   its own filename bypasses the rewrite it is testing.
   **On G0-B.5 the two baits can legitimately disagree — record both and score the gate
   from `shadow.js`**, because `.js`, `.css` and `.woff2` are what the deployment ships and
   `.txt` never is.
4. Save the output, **delete the directory.**

**Without a shell**, work through
[`../../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md`](../../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md)
instead — the same six fetches with browser DevTools steps for the two that need request or
response headers.

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
   them, with `--resolve`. **Loading it still does not answer G0-B.1**; fetch `/REWRITE_OK`
   for that, as step 3 above says.

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

### Document root — ~~CONFIRMED `httpdocs`~~ **CONTESTED 2026-09-24 — see *Panel readings — 2026-09-24* below**

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

4. **Re-read 2026-09-22 — what the page *does* offer, enumerated.** VERIFIED from the
   panel: per-domain **Apache** settings, per-domain **nginx** settings, **proxy mode**
   (still described as nginx proxying to Apache), **smart static files processing**,
   **serve static files directly by nginx**, **maximum HTTP request body size**, **nginx
   caching**, and configurable **additional headers, MIME types, index files and
   handlers**. The Plesk build is **18.0.80** on `lin6.ethiotelecom.et`.

   **This does not move G0-A, and the enumeration is the reason it cannot.** Reading 3
   said the two directive textareas are absent; this list, gathered independently, does
   not contain them either — so it *corroborates* that absence rather than resolving it.
   And corroborated absence is still not the measurement G0-A asks for.

   **Do not read any of these fields as routing evidence.** G0-A asks a specific question:
   can `/` be served by a Node application while `/api/*` reaches the Laravel front
   controller, on this account? None of the controls above has been shown to do that.
   "Plesk exposes an nginx settings page" and "ETHR's routing works here" are different
   claims, and only the first is VERIFIED. **G0-A stays `NOT VERIFIED` until routing is
   actually demonstrated against this host.**

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
| **B-1** | SSH **Forbidden** — **downgraded to OPTIONAL 2026-09-22** by [`SHARED-HOSTING-CONTRACT.md`](SHARED-HOSTING-CONTRACT.md); its absence is a constraint to design around, not a blocker to wait on | The probe has no shell route. Its repo-defined fallback — *Scheduled Tasks as a one-off PHP CLI task* — depended on G0-D, **which was answered FAIL on 2026-09-18**: there is no Scheduled Tasks section, so the fallback does not exist either. |
| **B-2** | No directive fields on Apache & nginx Settings | G0-A cannot be run as written. See the amended consequence below. |
| **B-3** | ~~`httpdocs/ethr.et/` — an unidentified Plesk-provisioned vhost skeleton~~ **IDENTIFIED 2026-09-24 — it is the live document root.** | **DO NOT REMOVE IT.** Owner-confirmed: the served root is `ethr.et/`. Deleting it takes the site down and breaks ACME renewal. The blocker is closed as identified, not as removed — `PANEL-SESSION-RUNBOOK.md` Part 2, which says to remove it once unambiguous, is **withdrawn for this directory**. Superseded reasoning follows. ~~REASSESSED 2026-09-24 — it may be the live document root.~~ The canary uploaded per the runbook now shows at `ethr.et/ethr-canary`, which fits a vhost provisioned with a `<domain>/` root rather than `httpdocs/`. **Do not remove it until a fetch resolves the root** — see *Panel readings — 2026-09-24*. Original entry: purpose unknown; possible collision with the live `ethr.et` vhost carrying the certificate; inverts the `~/ethr` layout. Identify what was created in the panel before removing it — deleting a vhost is not deleting a folder. |
| **B-4** | ~~No route to run `artisan`~~ **Route defined 2026-09-22** | `key:generate`, `migrate`, `db:seed`, `ethr:create-admin` now have a non-shell route in the deployment contract: **Plesk Git *additional deployment actions***, which execute as the subscription user on deploy. The field is confirmed present (owner-read 2026-09-18); nothing has been entered or run. This covers **one-off install work only** — deployment actions fire on deploy and cannot drive anything recurring, so the scheduler and queue worker still need **G0-D**. See [`SHARED-HOSTING-CONTRACT.md`](SHARED-HOSTING-CONTRACT.md). |
| **B-5** | No route to get a schema into the database | Both paths need something this account does not offer: the existing-data path runs `mysql < dump.sql` **on the shared host**, and the fresh path is B-4. Distinct from B-4 because the remedy differs — B-4 needs something that runs `artisan`; B-5 needs that **or** a database import UI, which is the still-open half of manual queue #1. |
| **B-6** | The DNS cutover already happened and the rollback target may not serve | Not a gate — a safety property that stopped holding. `ROLLBACK_RUNBOOK.md` Scenario A calls "before DNS cutover" *the current state*; `ethr.et` resolves to `213.55.96.154` (the Plesk host), measured twice on 2026-09-17. Whether the VPS still serves, and whether it still holds tenant data and its `APP_KEY`, is unanswered. |

| **B-7** | **Hosting Settings → *Save* hangs.** Owner report, 2026-09-23: the button sits in its loading state and the form never completes | **Not a gate, and it blocks nothing currently queued** — no step in this migration needs a Hosting Settings save. Recorded because of what the form carries and what people try to do with it. See below. |

*B-5 and B-6 were added to this table on 2026-09-19. They were recorded in
`MIGRATION_STATE.md` on 2026-09-17/18 and this register — the one that governs — still
listed four. `MIGRATION_CHANGELOG.md` has said "six blockers" since.* **B-7 was added
2026-09-23.**

#### B-7 — what is known, and the two traps around it

**Status: ASSUMED, owner report.** The hang is testimony, not output. **Nobody has read the
failing request**: no HTTP status, no response body, no log line, no DevTools capture. Until
one exists, the cause is unknown — a Plesk-side operation still running, a gateway timeout,
a 500, and client-side validation all present identically as a spinning button. The one
conclusive read is the browser's own Network tab while reproducing the save once; it needs
no panel privileges and no shell.

**Trap 1 — the *Document root* field reads `/`, and that is correct. Do not change it.**
This register already confirms the document root is `httpdocs` (see *Document root —
CONFIRMED `httpdocs`* above): Plesk displays the path relative to the webspace root, and the
live certificate's `.well-known/acme-challenge/` sits inside `httpdocs/`, which only works if
that is the document root. A save that ever completes writes whatever the form holds, so
"correcting" `/` would move the document root to the home directory — exposing
`~/ethr/api/.env` over HTTP and breaking certificate renewal. That is the exact failure this
register recorded as **ruled out**, and it would be reintroduced by hand.

**Trap 2 — saving this form cannot grant SSH.** `SSH access: Forbidden` is a service-plan
restriction (**B-1**), not a per-domain toggle. Repeated saves will not move it; only Ethio
Telecom can. If the save attempts were made to enable shell access, they were never going to
succeed whatever the hang turns out to be.

**And one piece of advice that will waste a reader's time:** diagnosing this via *Tools &
Settings → Server Management → Logs* is not possible here. That is the **server
administrator** panel; this is a subscription. The dashboard offers *Logs* under **Dev
Tools**, at domain level, and nothing above it.

**It does not block the canary.** The dashboard carries **Files** (File Manager) and **FTP**
under *Files & Databases*, and either is a complete upload route for
`httpdocs/ethr-canary/`. G0-B.1–B.5 remain runnable today without SSH, without cron and
without this form.

**B-1 and B-4 are one support request**, and it reframes G0-D. Without a shell, Scheduled
Tasks is no longer just how the scheduler runs — **it is the only way to migrate the
database at all.** If G0-D returns "Fetch a URL only", there is no documented route to
perform this migration, and that is a hard blocker rather than the costed design change
the rest of this package describes. ~~**G0-D is now the highest-value panel read.**~~

**G0-D returned worse than that on 2026-09-18 — the section is absent — so the hard
blocker is the live case, not the hypothetical one.** The highest-value panel read is now
**G0-G**, the Node.js page, because it is the one remaining gate whose answer changes what
the repository must contain: Node present means Branch A stands and the frontend needs
nothing; Node absent means Branch B, which measurement at `7aed9d2` shows to be an
architectural frontend deployment change rather than a few route edits. The highest-value
*action* is sending `ETHIO-TELECOM-SUPPORT-REQUEST.md`, which is what could reopen G0-D.

---

## Panel readings — 2026-09-24 **[MEASURED]**

Read from the Plesk panel by the owner on 2026-09-24. Recorded verbatim. **No gate status
moves on these** — see *What these readings do not settle* at the end.

| Reading | Value | Source page |
|---|---|---|
| Document root | `/` | Websites & Domains → Hosting Settings |
| Canary folder location | `ethr.et/ethr-canary` | File Manager |
| Web scripting | **FastCGI, CGI, SSI** | Hosting Settings |
| PHP version on that page | **not shown** | Hosting Settings |
| SSH access | **Forbidden** | Hosting Settings |
| IP address | `213.55.96.154` | — |
| Certificate | Let's Encrypt on `ethr.et` | — |
| Preferred domain | `www.ethr.et` via **301** | — |

Three of these confirm what was already recorded and are not restated: SSH Forbidden (B-1),
the IP, and the certificate. **Web scripting listing FastCGI/CGI/SSI with no PHP version on
that page is new**, and it does not answer G0-E: the handler list says PHP can run, not which
version. PHP 8.3.33 remains a separate reading against the version row only.

### Document root — **RESOLVED `ethr.et/` (owner-confirmed 2026-09-24)**, **TARGET CHANGED to `httpdocs/` the same day**

> **The owner has chosen to move the document root to Plesk's stock default, `httpdocs/`.**
> That is a decision about where the site *will* be served from, not a new measurement. The
> reading below stands unchanged and still describes the account: **today the served
> directory is `ethr.et/`.**
>
> **No gate status moves on a decision.** G0-B.1–B.5 stay `NOT VERIFIED`; a canary fetched
> against the old root would not score them, and one has still never been fetched.
>
> **Three consequences of the move, recorded because each is a way to lose something:**
>
> - **ACME.** The certificate renews from the document root. `httpdocs/.well-known/` was
>   observed on 2026-09-17 — evidence, not a guarantee eight days later. Confirm it before
>   saving the field, or renewal fails silently around 2026-12-15.
> - **B-3 changes character rather than closing.** `httpdocs/ethr.et/` stops being the live
>   document root and becomes a subdirectory at `https://ethr.et/ethr.et/`. It is still not
>   to be deleted as part of this change.
> - **The `__DIR__` sub-question is answered by derivation, not by reading.** `httpdocs/` is
>   one level below home, so the prefix is `'/../ethr/api/…'`. Confirm from File Manager's
>   breadcrumb anyway — a wrong prefix is a loud failure, which makes it the cheap check.


**Candidate B. A and C are excluded.** The served directory is `ethr.et/`, which is why the
canary uploaded per the runbook appears at `ethr.et/ethr-canary`. The reasoning that
produced the three candidates is kept below, superseded, because two of its consequences
survive the answer and one does not.

**The security question is answered NO.** `~/ethr/api/` is a **sibling** of the document
root, not a descendant, so `.env`, `storage/`, `vendor/`, `storage/app/` and the `.git` tree
are not reachable over HTTP. **`APP_KEY` and the database password are not exposed.** The
exposure table below describes candidate C, which did not happen, and is retained only so
the reasoning can be checked.

**§0's safety property holds — for a different reason than it states.**
`shared-hosting/DEPLOYMENT.md` justifies the layout by "the home directory sits one level
above `httpdocs`". That sentence is about the wrong directory. The property that actually
holds is narrower and worth stating exactly: **the application lives in a directory that is
not the document root and not beneath it.** It would fail the moment the served root were
changed to `~/` or to `~/ethr/`, which no `.htaccess` in any other directory could prevent.

**The deploy-path defect is real, not hypothetical.** Every instruction that writes to
`~/httpdocs/` writes to a directory that is not served. See *Deploy path — corrected* in
`shared-hosting/DEPLOYMENT.md`.

**One sub-question is still open, and it is load-bearing.** Whether the served directory is
`~/ethr.et/` or `~/httpdocs/ethr.et/` has not been read. It sets the `__DIR__` prefix in the
relocated `index.php` — `'/../ethr/api/…'` if the root is one level below home,
`'/../../ethr/api/…'` if two. A wrong prefix is a fatal `require` on every request, loud and
immediate rather than silent. **Read the full path from File Manager's breadcrumb before
step 5.**

---

### ~~The document root is now AMBIGUOUS~~ **SUPERSEDED — resolved above. Retained for the reasoning.**

This register has said since 2026-09-17 that the document root is `httpdocs`, reasoning that
Plesk displays the field relative to the webspace root and that
`.well-known/acme-challenge/` was observed **inside** `httpdocs/` — which only works if that
is the served directory.

**Today's second reading does not fit that conclusion.** The canary was uploaded per
`PANEL-SESSION-RUNBOOK.md` Part 3, which says `httpdocs/ethr-canary/`, and File Manager now
shows it at **`ethr.et/ethr-canary`**. Two readings, three candidate roots:

| # | Candidate root | Fits "Document root: `/`" | Fits canary at `ethr.et/ethr-canary` | Fits ACME inside `httpdocs/` |
|---|---|---|---|---|
| **A** | `~/httpdocs/` | yes, if displayed relative to webspace | only if File Manager's root **is** `httpdocs/` and a subdirectory `ethr.et/` exists inside it | yes |
| **B** | `~/ethr.et/` | yes, if the webspace uses `<domain>/` rather than `httpdocs/` | yes, directly | **no** — unless ACME was observed in a different tree |
| **C** | `~/` (home) | yes, read literally | yes | yes |

**A and B cannot both be right, and C is the one that matters.** Marked **AMBIGUOUS** until a
fetch settles it. It is not a judgement call and needs no panel access:

```
https://www.ethr.et/ethr-canary/canary.php   → 200  ⇒ root contains ethr-canary directly (B, or A with the runbook path)
                                             → 404  ⇒ it does not; try /ethr.et/ethr-canary/canary.php
```

If `/ethr.et/ethr-canary/canary.php` returns 200 instead, the served root is one level
**above** the `ethr.et` directory, which is candidate **C** and is the dangerous one.

### If the root is at or above the home directory — what would be exposed

`shared-hosting/DEPLOYMENT.md` §0 places the application at `~/ethr/api/` and states plainly
that it is **NOT web-accessible**. That property is the whole basis of the layout, and it is
a property of *where the root is*, not of anything in the application. Under candidate **C**
it does not hold:

| Planned path | URL under root `~/` | What it discloses |
|---|---|---|
| `~/ethr/api/.env` | `/ethr/api/.env` | **`APP_KEY` and the database password.** Also mail, S3 and Sentry credentials |
| `~/ethr/api/storage/logs/laravel.log` | `/ethr/api/storage/logs/laravel.log` | Stack traces; Laravel writes connection details into some database exceptions |
| `~/ethr/api/vendor/` | `/ethr/api/vendor/` | Full dependency inventory with versions — a ready-made CVE checklist |
| `~/ethr/.git/` (Plesk Git deploys here) | `/ethr/.git/` | Entire history, including anything ever committed and later removed |
| `~/ethr/api/storage/app/` | `/ethr/api/storage/app/` | Tenant uploads and generated backups |

**`.env` is the one that matters.** `APP_KEY` decrypts `Employee.tin` and `national_id`
(both use the `encrypted` cast) and signs sessions; the database password is direct access to
every tenant's data. Neither is protected by anything in the application — an unauthenticated
`GET` would return the file as plain text, because under **C** no `.htaccess` in `httpdocs/`
governs `~/ethr/` at all.

**This is exactly the case the 2026-09-17 entry recorded as "ruled out."** It was ruled out on
evidence that is now contested, so it is reopened rather than assumed.

### Candidate B is not a security problem but is a deploy-path problem

If the root is `~/ethr.et/`, `~/ethr/` remains a sibling and nothing above is exposed — the
layout's safety property holds. But `DEPLOYMENT.md` step 5 writes `index.php` and `.htaccess`
to **`~/httpdocs/`**, which under B is not served. The deploy would complete, report success,
and the site would not change. **A silent no-op, not an error**, which is the harder failure
to notice.

### The naming near-miss, worth stating before someone hits it

The application directory is `~/ethr/` and a candidate root is `~/ethr.et/` — **one character
apart**. If the served root is ever set to `~/ethr/` by a typo, autocomplete or a glob, the
entire Laravel application including `.env` is published in one action, with no error at any
step. Any change to the *Document root* field should be read back character by character.

### This is a live explanation for B-3

B-3 records `httpdocs/ethr.et/` as an unidentified Plesk-provisioned vhost skeleton created
2026-09-17 23:48. Candidate **B** explains it exactly: a vhost provisioned with a
`<domain>/` document root rather than `httpdocs/`, which is Plesk's other convention.

**If that is what it is, it is the live document root — not a stray.**
`PANEL-SESSION-RUNBOOK.md` Part 2 says to remove it once its purpose is unambiguous, with a
stop condition if it holds the certificate or is the live site. **The canary landing inside it
is evidence for that stop condition**, and the runbook's own instruction is therefore now the
risky one. **Do not remove `ethr.et/` until the fetch above resolves the root.** Deleting the
live document root takes the site down and breaks ACME renewal.

### What these readings do not settle

- **G0-B.1–B.5** stay `NOT VERIFIED`. The canary is uploaded; **no fetch has been performed**,
  and this register does not score a gate from a file's existence.
- **G0-E** stays `NOT VERIFIED`. FastCGI/CGI/SSI is a handler list, not a version, and the
  page showed no PHP version.
- **G0-A** is unchanged. No directive field was read today.
- The document root row moves from **CONFIRMED** to **AMBIGUOUS** — a reduction in certainty,
  which is a status change this evidence does settle.

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
| **G0-G** | Node.js — **build and application execution** *(was "Node.js (build only)"; corrected 2026-09-22 — the panel offers a startable application, so build-only was the wrong thing to be testing)* | **Two requirements, and only one is missed.** *CI build/test toolchain:* **Node 24**, this repository's pin in `.nvmrc` — **not** Next's 20.9 floor, and **not relaxed to fit the host**; observed 22.23.2 does **not** meet it. *Frontend application runtime:* **Node 22**, declared in `docker/frontend/Dockerfile` (`FROM node:22-alpine`, which both builds and runs `server.js`); observed 22.23.2 **is** that version. See the corrected note below — do not read this row as "22 fails ETHR's Node requirement". | **PRESENT, and application-execution capable.** Panel 2026-09-22: Node.js Version **22.23.2**, package manager npm, Document Root `/ethr`, Application Root `/ethr`, Application Startup File `app.js`, Application Mode `production`, Application URL `http://ethr.et`, custom environment variables available; the panel offers *Enable Node.js* and *Run Node.js commands*. **Not build-only** — the startup-file/mode/URL trio answers this gate's third question. **But 22.23.2 is below the pinned 24**, and is the exact version `audit/BASELINE.md` §12d measured at *2 failed, 3 passed* — **on the Vitest harness**, which §12d itself says involves no application code. Nothing was enabled or started. | **PARTIAL** | panel 2026-09-22 |
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

ETHR is multi-tenant by subdomain. If a literal `*` vhost is not accepted, each tenant needs its own Plesk entry and the plan's subdomain cap becomes a **hard limit on how many tenants the product can have** — **PUBLISHED** plan figures: 5 on Bronze, 10 on Silver, 15 on Gold, unlimited on Platinum. That is a business ceiling, not a hosting detail.

**OPEN QUESTION — do not record the published number as the tenant cap.** Whether it *is* the ceiling turns on a second question nobody has answered: **how Ethio Telecom counts a wildcard against the quota.** If `*.ethr.et` counts as **one**, the number does not bind at all; if each host under it counts individually, the number *is* the customer ceiling. Elsewhere this repository has assumed the first (`MIGRATION_STATE.md` item 2 and R12, `B1-B5_GATE_REPORT.md`) — that assumption is **NOT VERIFIED**, because "Plesk stores a wildcard vhost as one entry" is a panel mechanic, while the quota is a **service-plan accounting policy** and the two need not agree. It is asked explicitly in the higher-plans ask of [`ETHIO-TELECOM-SUPPORT-REQUEST.md`](ETHIO-TELECOM-SUPPORT-REQUEST.md), and it should be settled **before** any tier is purchased — evidence first, then the purchase.

Also check **SSL/TLS Certificates**: a wildcard certificate needs DNS-01, and the `ethr.et` zone is on `ns2.telecom.net.et`, not Plesk — so automatic issuance is likely impossible and renewals may be manual. Confirm rather than assume.

### G0-D — cron

**Tools & Settings → Scheduled Tasks** (or **Websites & Domains → Scheduled Tasks**).

- Which task types are offered — "Run a command", "Fetch a URL", "Run a PHP script"?
- Minimum interval? Every minute, or only every 5/15/30?
- Full path to the PHP binary as Plesk invokes it.

Everything asynchronous depends on this: the scheduler, the queue worker, invoice generation, leave accrual, payslip notifications. **"Fetch a URL" only** means the scheduler and queue must move behind an authenticated HTTP endpoint.

**That endpoint was built and merged on 2026-09-22 (`b61cb05`)**, so the warning this paragraph used to carry — *"a real design change that is currently unbuilt, and it must be costed rather than assumed"* — is discharged. It is `POST /api/v1/cron/schedule` and `POST /api/v1/cron/queue`: token-authenticated, 404 when `CRON_TOKEN` is unset, one `Cache::lock` per task so a tick firing mid-run cannot start a second worker.

**G0-D is unaffected and remains FAIL, and the No-Go → Option A decision it fired stands.** The gate asks whether *the host* can start a task. It still cannot — this subscription has no Scheduled Tasks section at all, so not even a URL-fetch task exists to point at the new route. What changed is only that the driver no longer has to be the host: anything that fetches a URL on a timer will do.

### G0-G — Node.js

**Websites & Domains → Node.js.** Present at all? Which versions? Can an app be started, or is it build-only?

**Superseded 2026-09-22 by the measurement below.** This read: *"Needed only to build the
frontend, unless the answer to G0-A forces a Node server."* The panel shows Plesk provides a
**startable Node.js application** capability — a startup file, an application mode and an
application URL — so this gate is not build-only. Two things gate whether that capability is
actually usable for **Branch A**, and neither is settled by it being present: the **version**,
observed at **22.23.2**, which misses the `.nvmrc` **24** CI/test pin but **matches the frontend
runtime this repository already declares** in `docker/frontend/Dockerfile` — see the corrected
note below — and **G0-A** routing, still `NOT VERIFIED`. The pre-registered **No-Go → Option A** decision
is untouched by this: it fired on **G0-D**, which remains FAIL.

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



> **MEASURED 2026-09-22 — panel read, nothing enabled.**
>
> | Field | Value, verbatim |
> |---|---|
> | Node.js Version | **22.23.2** |
> | Package Manager | npm |
> | Document Root | `/ethr` |
> | Application Root | `/ethr` |
> | Application Startup File | `app.js` |
> | Application Mode | `production` |
> | Application URL | `http://ethr.et` |
> | Custom environment variables | available |
> | Actions offered | *Enable Node.js*, *Run Node.js commands* |
>
> **Question 1 — present at all? YES.**
>
> **Question 3 — can an app be started, or is it build-only? IT CAN BE STARTED.** A startup
> file, an application mode and an application URL are not build-only fields. This gate's
> row has read *"Node.js (build only)"* since it was written, on the assumption that the
> frontend would at most be compiled here. That assumption is now measured false, and the
> consequence runs the other way from the one this register anticipated — see the G0-A note
> below.
>
> **CORRECTED 2026-09-22, same day — "the requirement" was one word doing two jobs.**
> The paragraph below says 22.23.2 fails *the* requirement. That is right about the CI
> toolchain and wrong as a blanket statement, and the distinction decides whether Node is a
> blocker here or a non-issue. **`docker/frontend/Dockerfile` line 1 is `FROM node:22-alpine`**,
> and all three stages derive from it: it **builds** the frontend on Node 22 (`RUN npm run
> build`) and **runs** the standalone server on Node 22 (`CMD ["node", "server.js"]`).
> `src/package.json` declares no `engines` floor. So:
>
> | Requirement | Version | Where it is declared | Does 22.23.2 meet it? |
> |---|---|---|---|
> | CI build/test toolchain | **24** | `.nvmrc`, read by `setup-node` at 4 sites in `gates.yml` and `security.yml` | **No** |
> | Frontend application runtime | **22** | `docker/frontend/Dockerfile` | **Yes — it is the declared version** |
>
> §12d's two failures are `employees-import.test.tsx` and `alert-thresholds-dialog.test.tsx`
> — **Vitest files**, and §12d says of the failing one that the request *"is intercepted
> before it leaves the process, so no application code is involved."* Node 22 broke the
> **test harness**, not the application. That is why the pin is in `.nvmrc` and not in
> `engines`.
>
> **Consequence: the Plesk version is not a Branch A blocker.** 22.23.2 is the version this
> repository already ships its frontend on. **The `.nvmrc` 24 pin stays exactly as it is** —
> it is the toolchain two tests genuinely fail on, and it is not relaxed to fit anything.
> These are two different requirements and only one of them is missed.
>
> What still gates Branch A is **G0-A** routing, `NOT VERIFIED` — and upstream of all of it,
> **G0-D** remains FAIL, so nothing runs `artisan` regardless.

**Question 2 — version. 22.23.2 misses the `.nvmrc` CI/test pin, and the miss is exact.**
*(Heading corrected 2026-09-22: it read "does NOT satisfy the requirement", which the note above
shows is true of the CI toolchain and false of the frontend runtime. The measurement below is
unchanged; only the scope of the claim is.)*
> The rule above says the bar is this repository's pinned **24**, not Next's 20.9 floor, and
> says to record the gap rather than round it up. `audit/BASELINE.md` §12d ran the frontend
> suite version by version:
>
> | Node | Result |
> |---|---|
> | 22.23.2 | **2 failed, 3 passed** |
> | 24.21.0 | 5 passed |
>
> The offered version is not merely *below* 24 — it is **the same version string** that
> matrix measured as failing. `employees-import.test.tsx` fails on 20 *and* 22: MSW's
> interceptor never settles a `multipart/form-data` request, and the promise hangs to the
> 20s timeout.
>
> **State the limit of that evidence honestly.** §12d also records that the request *"is
> intercepted before it leaves the process, so no application code is involved"* — it is an
> MSW/undici test-harness failure, not a demonstrated production-runtime defect on Node 22.
> What is demonstrated is that **this repository's own suite does not pass on 22.23.2**, so
> 22 is a runtime nothing here has ever been verified green on. That is why the rule says
> treat the gap as a finding. It is not a pass, and it is not a catastrophe either; it is an
> unverified runtime, recorded as such.
>
> **Not asked, and therefore not known:** whether other Node versions are selectable from
> that page. The panel shows one value; whether it is a fixed version or the current
> selection of a dropdown was not established. See the next action.
>
> **One thing to resolve before anything is enabled.** Document Root and Application Root
> both read `/ethr`. This register records the account's document root as `httpdocs`
> (confirmed by `.well-known/acme-challenge/` observed inside it), and the migration layout
> places the Laravel application at `~/ethr/` **deliberately outside** the web root — that
> placement is the control that makes `api/.env` unreachable regardless of the `.htaccess`
> deny rules. Whether these fields are Plesk defaults shown before enabling, or a
> configuration that would serve `~/ethr/`, was not established and **must not be inferred**.
> Nothing was enabled, so nothing is currently exposed by this.


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
| **G0-A** | **Amended 2026-09-17 — the original text was overstated.** It read *"frontend goes cross-origin; ~1 day becomes ~2 weeks plus an auth-security review"*, which is true **only if the Node server stays** (B5 = yes). Under B5 = no the frontend is static files in the *same* document root as `index.php`, and `shared-hosting/.htaccess` already routes `^/(api\|sanctum)` to the front controller — **same-origin, no nginx directives required**. `SHARED_HOSTING_AUDIT.md` §E says so itself about `rewrites()`: *"In production nginx already routes `/api` to PHP before the SPA sees it… `.htaccess` must reproduce this."* So a FAIL does not force cross-origin; it forecloses Branch A and makes **static export the way to stay same-origin** — **an architectural frontend deployment change — re-costed 2026-09-18 (`7aed9d2`) after the export was actually attempted, and the earlier "bounded change already scoped in §E and D6" wording is withdrawn.** The export **does not build**: `app/manifest.ts` needs a `force-static` directive (§E missed it), and the four `[id]` routes are `"use client"`, which Next forbids combining with `generateStaticParams` — so the prescribed one-line addition is not implementable and each route needs a server-component split. Beyond the build, those ids are **tenant data**, so `generateStaticParams` can only return `[]` and every real `/employees/123` 404s; the routes need client-side routing. Still true and unchanged: delete `middleware.ts`, client-side host read in `(auth)/layout.tsx`, marketing pages lose SSR. It would also reduce this repository's *use* of Node to build-only — ~~and so **G0-G to build-only Node**~~ **withdrawn 2026-09-22**: the panel shows a startable Node application (startup file, application mode, application URL), so G0-G is not a build-only gate whatever Branch is chosen; choosing Branch B would leave that capability unused, not absent. Cross-origin remains the cost only if Branch A is chosen anyway. |
| **G0-C** | Per-tier subdomain cap becomes a hard tenant cap — **but only if the provider counts a wildcard host-by-host.** That counting rule is an **OPEN QUESTION**, asked in the higher-plans ask of the support request; the published number is not by itself the tenant ceiling. Settle it before purchasing a tier. |
| **G0-D** | Scheduler and queue move behind an authenticated HTTP endpoint. **Built and merged 2026-09-22 (`b61cb05`)** — `POST /api/v1/cron/schedule` and `/cron/queue`. There is no longer a cost to estimate; what remains is choosing a driver, and it need not be this host. **The gate still reads FAIL** — it measures the host, and the host is unchanged — so the No-Go → Option A decision it fired is untouched. |
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
