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

**Target:** Ethio Telecom Linux shared hosting (Plesk) · account `etrhet` · `213.55.96.154`
**Prepared:** 2026-09-15
**Run by:** owner (requires the Plesk account — this cannot be automated from the repository)

---

## Why this document is empty

Master plan §8: *evidence before implementation.* `docs/HOSTING_VERIFICATION_CHECKLIST.md` carries roughly sixty rows, every one `NOT VERIFIED`, and states the rule this file follows — **"NOT VERIFIED is not a soft yes."**

Nothing in this repository has ever touched the Ethio Telecom account. The probe has only been run against the local Docker stack. Until the table below has measured values, every downstream decision — static export or Node, `.htaccess` or nginx directives, which tier to buy — is a guess, and building on it risks a week of work on a fork that does not exist.

**Do not fill any row from documentation, vendor marketing, or inference. Only from output.**

---

## How to run it — three steps, about thirty minutes

### Step 1 — the capability probe (sensitive; keep it out of the web root)

`scripts/hosting-verification/ethr-hosting-check.php` prints `disable_functions`, database grants and the filesystem layout verbatim. It must not be web-reachable.

1. Upload to the account's **home** directory `~/`, **not** `httpdocs/`.
2. Run over SSH:
   ```bash
   php ~/ethr-hosting-check.php \
     --db-host=localhost --db-name=<db> --db-user=<user> --db-pass=<pass> \
     > ~/gate0-probe.txt
   ```
   No shell? Plesk → **Scheduled Tasks** → run once as a PHP CLI task and read the output.
   Over the web as a last resort only — a browser request puts the database password in the access log.
3. Download `gate0-probe.txt`, then **delete both files from the server.**

Answers: G0-E, G0-F, G0-H, G0-I, G0-J, and the storage rows.

### Step 2 — the web-server canary (safe to be web-reachable)

The probe above sits in `~/` and is never served by Apache, so it structurally cannot answer *"is `.htaccess` honoured?"*. `scripts/hosting-verification/htaccess-canary/` is the opposite trade: it is web-reachable and discloses nothing — five booleans, no environment detail.

1. Upload all four files (`.htaccess`, `canary.php`, `secret.txt.probe`, `shadow.txt`) to `httpdocs/ethr-canary/`.
2. Open `https://<host>/ethr-canary/canary.php` and follow the printed checks.
3. Run the four `curl` commands it gives you.
4. Save the output, **delete the directory.**

Answers: G0-B.

### Step 3 — the Plesk panel checklist

The questions no script can reach. Section below.

Answers: G0-A, G0-C, G0-D, and the tier comparison.

---

## Results

Fill `Actual` and `Status` from real output. Cite the evidence — `probe:DB4`, `canary G0-B.3`, `panel screenshot`.

| # | Capability | Required | Actual | Status | Evidence |
|---|---|---|---|---|---|
| **G0-E** | PHP version | >= 8.2 | | NOT VERIFIED | probe |
| G0-E | 18 mandatory extensions | all present | | NOT VERIFIED | probe |
| G0-E | `memory_limit` | >= 256M | | NOT VERIFIED | probe |
| G0-E | `max_execution_time` | >= 120s | | NOT VERIFIED | probe |
| **G0-B.1** | `mod_rewrite` honoured | yes | | NOT VERIFIED | canary |
| **G0-B.2** | `mod_headers` honoured | yes | | NOT VERIFIED | canary |
| **G0-B.3** | `.htaccess` deny rules enforced | **403** | | NOT VERIFIED | canary |
| **G0-B.4** | `Authorization` reaches PHP | yes | | NOT VERIFIED | canary |
| **G0-B.5** | a real file shadows the rewrite | *record which* — no failing answer | | NOT VERIFIED | canary |
| **G0-A** | reverse proxy for `/api/` | permitted | | NOT VERIFIED | panel |
| **G0-C** | wildcard subdomain `*` as one vhost | works | DNS half **PASS** (2026-08-29); vhost not yet created | PARTIAL | B1-B5 + panel |
| G0-C | wildcard TLS | issued | per-hostname **PROVEN**; wildcard blocked, needs DNS-01 | PARTIAL | B1-B5 |
| **G0-D** | cron type | "Run a command" | | NOT VERIFIED | panel |
| G0-D | minimum cron interval | <= 1 min | | NOT VERIFIED | panel |
| **G0-F** | `CREATE TRIGGER` permitted | yes | | NOT VERIFIED | probe DB4 |
| **G0-G** | Node.js (build only) | >= 20.9 | | NOT VERIFIED | probe / panel |
| **G0-H** | outbound SMTP 587/465 | open | | NOT VERIFIED | probe |
| G0-H | mailbox send cap | known | | NOT VERIFIED | panel |
| **G0-I** | `SELECT VERSION()` | verbatim | | NOT VERIFIED | probe DB1 |
| G0-I | `DB_CONNECTION` to use | `mysql` or `mariadb` | | NOT VERIFIED | probe DB1b |
| G0-I | version floor | >= 5.7.9 / 10.2.7 | | NOT VERIFIED | probe DB1c |
| G0-I | server charset | `utf8mb4` | | NOT VERIFIED | probe DB10 |
| **G0-J** | CPU 3M-loop | < 400 ms | | NOT VERIFIED | probe P1 |
| G0-J | 1000 inserts / txn | < 3000 ms | | NOT VERIFIED | probe P4 |
| G0-J | 200 indexed SELECTs | < 1500 ms | | NOT VERIFIED | probe P5 |
| — | disk quota | measured | | NOT VERIFIED | panel |
| — | database size limit | measured | | NOT VERIFIED | panel |
| — | document root editable | yes | | NOT VERIFIED | panel |
| — | `symlink()` works | yes | | NOT VERIFIED | probe ST5 |
| — | parent of docroot writable | yes | **PASS** — home sits above `httpdocs` | VERIFIED | B1-B5 |

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
| Composer, SSH | **Strong evidence, unconfirmed** | `~/.composer` and `~/.ssh` exist; port 22 open. Plesk can still set the shell to `/bin/false` — confirm by logging in |

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
| **G0-A** | Frontend goes cross-origin. ~1 day becomes ~2 weeks plus an auth-security review. |
| **G0-C** | Per-tier subdomain cap becomes a hard tenant cap. Settle before purchasing a tier. |
| **G0-D** | Scheduler and queue move behind an authenticated HTTP endpoint. Unbuilt; must be costed. |
| **G0-F** | `migrate` aborts by design (`2026_07_22_000001`). Raise a support request for the `TRIGGER` grant — it is not a code change. Note the separate `DEFINER` hazard in `docs/audit/BASELINE.md` §13b. **Less likely to fail than it looks — see below.** |
| **G0-G** | Frontend must ship as static files. Bounded work; see the baseline §11. |
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
