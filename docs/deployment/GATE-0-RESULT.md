# Gate 0 — Real Plesk Feasibility Verification

**Status: NOT RUN. Every row below is `NOT VERIFIED`.**

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

The probe above sits in `~/` and is never served by Apache, so it structurally cannot answer *"is `.htaccess` honoured?"*. `scripts/hosting-verification/htaccess-canary/` is the opposite trade: it is web-reachable and discloses nothing — four booleans, no environment detail.

1. Upload all three files (`.htaccess`, `canary.php`, `secret.txt.probe`) to `httpdocs/ethr-canary/`.
2. Open `https://<host>/ethr-canary/canary.php` and follow the printed checks.
3. Run the three `curl` commands it gives you.
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
| **G0-A** | reverse proxy for `/api/` | permitted | | NOT VERIFIED | panel |
| **G0-C** | wildcard subdomain `*` as one vhost | works | | NOT VERIFIED | panel |
| G0-C | wildcard TLS | issued | | NOT VERIFIED | panel |
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
| — | parent of docroot writable | yes | | NOT VERIFIED | probe ST6 |

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
| **G0-B.3** | **Deployment blocker.** Not an error — a silent one: `api/.env` becomes web-readable while the application appears to work. Move every rule into the nginx directives panel and do not deploy until a request for `/.env` returns **403**. A 404 is not a pass. |
| **G0-A** | Frontend goes cross-origin. ~1 day becomes ~2 weeks plus an auth-security review. |
| **G0-C** | Per-tier subdomain cap becomes a hard tenant cap. Settle before purchasing a tier. |
| **G0-D** | Scheduler and queue move behind an authenticated HTTP endpoint. Unbuilt; must be costed. |
| **G0-F** | `migrate` aborts by design (`2026_07_22_000001`). Raise a support request for the `TRIGGER` grant — it is not a code change. Note the separate `DEFINER` hazard in `docs/audit/BASELINE.md` §13b. |
| **G0-G** | Frontend must ship as static files. Bounded work; see the baseline §11. |
| **G0-H** | Mail may cap tenant count before CPU or storage does, and the queue dead-man's-switch needs a non-email alert path. |
| **G0-I** | Below the floor, `migrate` fails on the *first* migrations (255-char `utf8mb4` primary keys at 1020 bytes vs the old 767-byte limit). |
| **G0-J** | Payroll already runs synchronously in the request (`PayrollController.php:38`) against a documented *dedicated-hardware* budget of 30s for 500 employees. A slow shared CPU makes queueing it mandatory rather than advisable. |

---

## After the run

1. Fill this table. Every row gets a value and a date.
2. Update `docs/HOSTING_VERIFICATION_CHECKLIST.md` from `NOT VERIFIED` to measured.
3. Confirm the probe and canary are **deleted from the server**.
4. Record the tier decision and its reason.
5. Only then unfreeze the fenced work: `docs/deployment/shared-hosting/*` stays untouched until the facts exist, because editing it now means writing it twice.
