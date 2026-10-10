# ETHR — final cutover checklist

# `CUTOVER READY = NO`

**As of 2026-09-27.** This is the single register that decides whether ETHR may go
live on the Ethio Telecom Bronze account. **Cutover stays BLOCKED until every
mandatory gate below reads `PASS`.**

> **Recording the evidence.** The host session's findings go in
> `docs/deployment/host-evidence/HOST-EVIDENCE.json` — copied from
> [the template](host-evidence/HOST-EVIDENCE.template.json), and deliberately absent
> until a session has happened, because a filled evidence file in a commit that did
> not involve the host is fabricated evidence. `./scripts/gates.sh evidence`
> validates the *record*: it
> refuses a 404 filed as a pass, a gate silently omitted, a host gate closed by CI, a
> composite gate that stopped at its first green, and a file claiming `cutover_ready`
> it has not earned. With no file yet it prints what is outstanding, which is its mode
> today. It cannot tell whether a reading was true — nothing local can. See
> [`host-evidence/README.md`](host-evidence/README.md) for the ordered session.

> ### Known gaps — deferred by the owner on 2026-09-29, in this order
>
> The first M2 run happened on 2026-09-29 and measured everything **except the database**:
> the login was refused because the probe connected to `localhost`, and the Plesk
> Databases page shows the database server is **`10.180.50.142:3306`**. The owner chose to
> finish the rest later. Each item below is open until done — none is optional:
>
> 1. **Delete the probe.** `httpdocs/p-ec99d920a8478c8a.php` was still live when this was
>    written. It is token-gated, but it prints `disable_functions` and the filesystem
>    layout, and `host-evidence/README.md` says to delete it in the same sitting.
>    `bash .m2-kit/m2.sh gone` must say **GONE**. Upload it again only for step 4.
> 2. **Plesk → PHP Settings for `ethr.et`:** `memory_limit` **256M**, `max_execution_time`
>    **120**, `upload_max_filesize` **10M**, `post_max_size` **12M** (above the upload limit,
>    so a 10 MB file plus its form fields still fits). All four measured at stock defaults —
>    128M / 30 / 2M / 8M — and all four fail the application's floor.
>    **2026-10-06: the panel cannot do this.** It shows the four values read-only ("if the
>    hosting provider grants you the corresponding permission"), and names `.user.ini` as
>    the override. Every release now ships `httpdocs/.user.ini` with these values
>    (`scripts/shared-hosting/user.ini`), and `.htaccess` denies it with `[F,L]`. **It is
>    still unverified on the host.** The M2 re-run in step 4 is what proves the limits took.
> 3. **Plesk → Databases → user `ethr` → Access control:** **Allow remote connections from
>    `172.28.20.26`** only. *(Corrected 2026-10-06. This said "Allow local connections only".
>    The database is on its own server, `10.180.50.142`, so "local" admits only that machine.
>    phpMyAdmin then reported `Access denied for user 'ethr'@'172.28.20.26'`, which also
>    gives the web server's address as the database sees it. That address is an owner
>    reading from the panel, 2026-10-06.)*
>    If step 4 is still refused, *any host* for that one run, then narrow it before cutover.
>
>    **Item 1 is done.** The owner searched File Manager on 2026-10-06 and could not find
>    `p-ec99d920a8478c8a.php`. A 404 at `https://www.ethr.et/p-ec99d920a8478c8a.php`
>    confirms it from outside.
> 4. **Re-run M2** with database host `10.180.50.142` — `bash .m2-kit/m2.sh run`. After
>    step 2, so the one run also proves the new limits. Then delete the probe again.
> 5. **Change the `ethr` database password.** The current one was pasted into an AI chat
>    transcript on 2026-09-29. Do it before it reaches `.env`; nothing uses it yet.
>
> Step 4 is what closes **M2** and supplies the first reading of **G0-F** (`DB4`) and
> **G0-I** (`DB1`). The panel labels the server *MySQL v8.0.32*; if `DB1` confirms it,
> `DB_CONNECTION` is `mysql`, not the `mariadb` the template ships.

> ### Known gaps — re-confirmed and deferred again by the owner on 2026-10-01
>
> All five items above are **still open**, and so are the ten host gates
> `./scripts/gates.sh evidence` lists (Q6-x, M2, G0-F, G0-B.6, M6, M3, G0-H, G0-I, G0-J,
> Quotas — 4 of 14 mandatory gates PASS). The owner chose to defer them and continue
> repository work. Deferring is not closing: **no state in this register changed**, and
> `CUTOVER READY` stays **NO**.
>
> Two things this record adds:
>
> - **Item 1 is unverified, not done.** On 2026-10-01 a session tried to run
>   `bash .m2-kit/m2.sh gone` and its agent tooling refused the request to the production
>   host. Nobody has confirmed the probe is gone since 2026-09-29. Run it by hand.
> - **The repository side is ready to be cut over.** The local production rehearsal was
>   refreshed onto the current code — not the stale copy `up.sh` otherwise reuses — and
>   `verify.sh` reported **36 passed, 0 failed** on 2026-10-01. That closes no host gate.
>
> PR #133 still merges only once M1 *and* M2 are answered (decided 2026-09-28); M1 is.

> ### 2026-10-10 — live; mail blocked by the provider's certificate chain
>
> Release `v2026.10.10.1` is migrated and seeded. The super admin exists, the caches are
> built, and the GitHub scheduler runs `schedule` and `queue` (both ok). Password-reset mail
> did not arrive. The send failed with `certificate verify failed`: the SMTP provider's
> certificate names neither the `smtp.` alias in use, nor did the server send its
> intermediate certificate. The failure was logged only at `warning`, below the production
> `LOG_LEVEL=error`, so it was invisible until the level was lowered. Fixed in the repository:
> `MAIL_CA_FILE` with a verified GlobalSign chain, and the three swallowed-mail failures now
> log at `error`. **No gate status moved.**
>
> ### 2026-10-10 — the first `migrate` failed on MySQL's timestamp defaults
>
> `key` succeeded. `migrate` failed with `1067 Invalid default value for 'expires_at'`
> (`trusted_devices`), inside `0001_01_01_000000_create_users_table`. That left nine empty
> tables, which must be dropped before the next run. The host runs
> `explicit_defaults_for_timestamp` OFF and the `mysql` connection is strict. Local runs and
> CI use the non-strict `mariadb` connection, so they never saw it. Six `NOT NULL`
> timestamps now state `useCurrent()`, which also stops MySQL adding a silent
> `ON UPDATE CURRENT_TIMESTAMP` to the first one in each table. All 71 migrations, then
> `ProductionSeeder` and `ethr:create-admin`, were re-run locally through the strict `mysql`
> connection with that setting OFF. **No gate status moved.**
>
> ### 2026-10-09 — the Toolkit attached nothing; the release steps run over HTTP
>
> The Laravel Toolkit's Scan answered *"Attached 0 application(s)"* with the document root
> at `ethr/api/public`. **Owner decision**
> ([`OWNER-DECISION-MAINTENANCE-ENDPOINT.md`](../decisions/OWNER-DECISION-MAINTENANCE-ENDPOINT.md)):
> `key`, `migrate`, `seed`, `create-admin` and the caches run through
> `POST /api/v1/maintenance/*`, guarded by `MAINTENANCE_TOKEN` and switched off when it is
> empty. Measured the same day:
>
> - Plesk Git's deployment path had drifted to `/ethr/api/public`. It was corrected to `/ethr`.
> - The database is MySQL 8.0.32 on its own server.
> - The panel cannot create mailboxes; the domain's mail is Ethio Telecom's mail system.
>
> **No gate status moved.**
>
> ### 2026-10-06 — the first host deploy closed one route; the install moves to the Laravel Toolkit
>
> Plesk Git's deployment action **executed** on the host for the first time. It runs in a
> **chrooted shell with no PHP**: the log reported `dirname: command not found`,
> `cut: command not found` and `no php binary found`. Artisan cannot run there. **Owner
> decision** ([`OWNER-DECISION-LARAVEL-TOOLKIT.md`](../decisions/OWNER-DECISION-LARAVEL-TOOLKIT.md)):
> artisan runs from Plesk's Laravel Toolkit, and the document root becomes
> `ethr/api/public`. The release no longer carries `post-deploy.sh` or `httpdocs/`.
> Measured on the same day: the database user allows only the web server's address, holds
> **Trigger**, and the old probe is gone. **No gate status moved.**

> ### 2026-10-04 — the deploy route exists in the repository; no gate moved
>
> GitHub now builds the release and publishes it to a `production` branch
> ([`release.yml`](../../.github/workflows/release.yml)), and Plesk Git's one deployment
> action is `bash deploy/post-deploy.sh`. Every release is deployed twice in CI on a throwaway
> layout, and the rehearsal fails if `APP_KEY` changes on the second deploy. The old printed
> action list ran `key:generate --force` on every deploy, which would have made every
> encrypted column unreadable from the second deploy on. The owner's procedure is
> [`PLESK-GO-LIVE.md`](PLESK-GO-LIVE.md). **That is repository work.** None of the host gates
> above changed, and `CUTOVER READY` is still **NO**.

Five states, and they are not interchangeable:

| State | Means |
|---|---|
| **PASS** | Real evidence exists, on the real host or in the repository as appropriate, with a date |
| **FAIL** | Measured, and the answer was no |
| **HOST ACTION REQUIRED** | Answerable only by acting on the Ethio Telecom account. **Not a soft PASS** |
| **OWNER DECISION** | Needs a choice, not a measurement |
| **BLOCKED** | Waiting on something else in this table |

> ### Should `migration/bronze-plesk` merge to `main` yet? — **No. Decided 2026-09-28.**
>
> It is tempting, because **Q6-x is blocked on exactly that**: GitHub indexes
> `schedule:` triggers from the default branch, so the scheduler cannot fire while
> `cron.yml` lives only on this branch. Merging looks like it unblocks a gate.
>
> **It does not, and until 2026-09-28 it would have been actively harmful.** The
> schedule would start firing every 5 minutes against an account where ETHR is not
> deployed and the secrets do not exist — **~288 failed runs a day**, each notifying
> the owner, for weeks. This repository already records where that ends: *"a gate that
> is permanently red stops being read."* The scheduler's failures would be invisible
> noise by the time they mattered.
>
> **The defect that made it harmful is fixed** (`cron.yml`, 2026-09-28): with neither
> secret set it now reports **DORMANT** and exits 0 on a scheduled run, while exactly
> one secret set still **fails** — the typo case, which must never skip. So merging is
> no longer damaging.
>
> **It is still not the next step.** Merging publishes a Bronze deployment as `main`'s
> documented state while eleven host gates are open, and the branch is the honest place
> for that until they are not. Revisit when **M1 and M2 are answered** — the two
> cheapest gates — because that is the point at which the scheduler has something real
> to drive.

> **Repository readiness and host readiness are different things, and this document
> exists to keep them apart.** CI is green — 10/10 checks, runs `36574943715` +
> `36574943910` on `080f723`, verified 2026-09-29 by reading each job's conclusion
> rather than the run's colour, and each run's `headSha` against that commit — and that says the repository builds, tests and
> packages correctly. **It says nothing about the host.** No gate below may be moved
> to `PASS` on the strength of a green build.
>
> **This citation lags by one commit and always will.** A commit cannot cite the run
> that tests it, so the newest citable run is the one on the parent. `9941f19` exists
> because that lag was read as drift and chased; it is not drift, and re-chasing it
> costs a commit each time. Update it when you are editing this block anyway, not as
> an errand of its own. What *is* worth checking is the **head SHA of the run you
> cite** — `gh run view <id> --json headSha` — because a green run on a different
> commit is the failure this note is really guarding against.
>
> **A 404 on a probe is `NOT RUN`, never a pass.** That distinction has already cost
> this project one false reading (G0-B.3(b), 2026-09-25).

---

## Mandatory gates — all must be PASS

| # | Gate | State | Evidence held | What closes it | Who |
|---|---|---|---|---|---|
| **C-5** | Production frontend mode | ✅ **PASS** | **Owner decision 2026-09-27: static export served by Apache/Plesk, no Plesk Node application.** Propagated to the runbook, the contract, the matrix, the `.htaccess` and the renderer. Artifact built by CI: 689 files, 186 `_next`, 4 shells, no `server.js` | Done. [`../decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md`](../decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md) | — |
| **Q6** | External cron caller chosen | ✅ **PASS** (choice) | **Owner decision 2026-09-27: GitHub Actions.** `.github/workflows/cron.yml` committed; logic verified against 10 response cases; `CronCallerWorkflowTest` pins it, mutation-checked | Done as a *decision*. Execution is the row below | — |
| **Q6-x** | Cron caller **actually running** | 🔶 **HOST ACTION REQUIRED** + **BLOCKED** | None. The endpoints have **never been called** on the account. **And measured 2026-09-27: `cron.yml` is not on the default branch, so its `schedule:` trigger cannot fire at all** — `gh workflow list` shows four workflows and *ETHR scheduler* is not among them, because GitHub indexes scheduled workflows from the default branch only | **Three things, in order.** (1) Add `ETHR_CRON_TOKEN` + `ETHR_CRON_BASE_URL` to the GitHub `production` environment. (2) Run it once via **`workflow_dispatch`** — that *does* work from a non-default branch — and observe 200 with `"status":"ok"` from **both** endpoints. (3) **The scheduled trigger only starts once this workflow reaches `main`.** Until then the scheduler is manual-only. **A green manual dispatch is not evidence that the schedule fires** | Owner |
| **M1** | `.htaccess` `[F,L]` deny + CSP survival | ✅ **PASS** | **Measured 2026-09-28**, `www.ethr.et` pinned to `213.55.96.154`. Revision confirmed **first**, per RUN-SHEET §0 step 1: **8 canary headers**, not the 3 the stale 2026-09-18 file sets. `GET /ethr-canary/secret.env.probe` → **403**. `Content-Security-Policy` present and **untruncated**, byte-identical to the canary's `.htaccess` — nginx/Imunify does not mangle it. Verbatim transcript: [`host-evidence/session-2026-09-28/M1-EVIDENCE.txt`](host-evidence/session-2026-09-28/M1-EVIDENCE.txt) | Done — both composite parts. The 2026-09-25 404 was the bait's absence, exactly as recorded | Plesk owner |
| **M2** | Hosting probe | 🔶 **HOST ACTION REQUIRED** — *known gap, see top* | **Partial, 2026-09-29** — [`probe-2026-09-29.json`](host-evidence/probe-2026-09-29.json). PHP 8.3.35 `fpm-fcgi`; all 18 mandatory extensions ✅; all four PHP limits ❌ at stock defaults; **database login refused** (wrong host — the server is `10.180.50.142`) | Run `ethr-hosting-check.php` with database credentials. Closes G0-E extensions/limits, G0-F, G0-H, G0-I and most of G0-J in one run. **No shell on this account:** run it over the web with the credentials in a POST body, connected as the application's own database user — [`host-evidence/README.md`](host-evidence/README.md) §2 (route added 2026-09-29) | Plesk owner |
| **G0-F** | `CREATE TRIGGER` | 🔶 **HOST ACTION REQUIRED** | Favourable prior only, and the register itself calls that *"an argument about priors, not a measurement"* | Probe `DB4`, **plus** `SHOW TRIGGERS LIKE 'audit_log'` after `migrate`, **plus** one refused `UPDATE`. All three — [`G0-F-CREATE-TRIGGER.md`](G0-F-CREATE-TRIGGER.md) §5. **Owner decision 2026-09-27: request the grant; do not weaken the migration** | Ethio Telecom → Plesk owner |
| **G0-B.6** | `AllowOverride Options` | 🔶 **HOST ACTION REQUIRED** | None. The Apache harness ran under `AllowOverride All` — the permissive case | Deploy and fetch a page. Without `DirectorySlash Off`, `mod_dir` 301s `/admin` before the SPA rules and **20+ routes are dead**. **No workaround exists** | Plesk owner |
| **M6** | Restore rehearsal on the host | 🔶 **HOST ACTION REQUIRED** — **hard blocker** | **NOT PERFORMED.** CI rehearses on MariaDB in a container only | The nine steps in [`M6-RESTORE-REHEARSAL.md`](M6-RESTORE-REHEARSAL.md), ending with a real write through the application. **`3db9904` removed the VPS, so this is the only recovery evidence this deployment will ever have** | Plesk owner |
| **M3** | Wildcard vhost + certificate + routing | 🔶 **HOST ACTION REQUIRED** | DNS **VERIFIED**; a valid `*.ethr.et` certificate **exists** (LE, 2026-09-16 → **2026-12-15**); **no vhost** — every tenant host 303s to the Plesk login | All four: wildcard DNS, vhost routing, the **served** certificate, and a tenant subdomain reaching the application. DNS alone is not it, and the certificate existing is not it | Owner (payment) → Plesk owner |
| **G0-H** | SMTP 587/465 + mailbox send cap | 🔶 **HOST ACTION REQUIRED** | **Ports half measured 2026-09-29:** outbound 443, 587 and 465 all open. The send cap is unread | M2 for the ports; panel for the cap. Nothing in the code respects a send cap, and two jobs fan out | Plesk owner |
| **G0-I** | MySQL version, `DB_CONNECTION`, charset | 🔶 **HOST ACTION REQUIRED** | None measured. The panel *labels* the server MySQL v8.0.32 — a label, not `DB1` | M2, probes `DB1`/`DB1b`/`DB1c`/`DB10`. The `devices` VIRTUAL generated column needs 5.7.6+ / 10.2.1+ | Plesk owner |
| **G0-J** | CPU + database performance / hosting limits | 🔶 **HOST ACTION REQUIRED** | **CPU half measured 2026-09-29:** P1 56 ms, P2 2 ms, P3 70 ms; `pm.max_children` 5. Database half (`P4`–`P6`) did not run. **Added as its own row 2026-09-27** — it was previously reachable only through M2's *"closes … most of G0-J"*, which is not a row, so nothing reported it outstanding. Same gap G0-B.6 had | The probe's Performance section (`P1`–`P6`). Then the product question behind the number: payroll runs inside the 50-second `queue:work` window against a budget of *500 employees < 30s* measured on **dedicated** hardware, and `ProcessPayrollJob` has `tries = 1`, so a crossed boundary is a **failed run**, not a retried one | Plesk owner |
| **Quotas** | 5 GB disk · 50 GB bandwidth | 🔶 **HOST ACTION REQUIRED** | Published figures only. Nothing measured, nothing capped in code | Read the panel. Then check the deployment's own footprint against it — see §*Quota arithmetic* below | Plesk owner |
| **Secrets** | Secret / artifact audit | ✅ **PASS**, with one rotation requirement | Audited 2026-09-27: no `.env` tracked (`src/.env.production` is `NEXT_PUBLIC_*` only, deliberately tracked); no key literal in the tree; every example env has **empty** credential values; no workflow prints a secret; release archive no longer carries `api/.env` — **verified by test, after the first patch silently failed** | Done, **except**: see *Rotation* below. It is not a blocker but it must not be forgotten | — |

---

## Non-blocking, tracked

| Gate | State | Note |
|---|---|---|
| **G0-A** reverse-proxy directive | **NOT VERIFIED**, not load-bearing | `/api` needs no proxy directive — measured 18/18 on Apache 2.4.58 |
| **G0-D** Plesk Scheduled Tasks | ❌ **FAIL** | Measured absent. **Designed around**, not blocking: Q6's caller is off-host |
| **G0-G** Node.js on the host | ✅ **N/A** by C-5 | No Node runtime on the host at all |
| **ST5** `symlink()` | **NOT VERIFIED**, not a dependency | ETHR serves files through signed `temporaryUrl()`; there is no `storage:link` step |
| **M4** who owns `/` | ✅ **DROPPED** | Moot under static export — one server, nothing contends for `/` |
| **M5** delete the canary | **HOST ACTION** — **now unblocked** | M1 closed 2026-09-28, so the directory is no longer needed. Two things measured 2026-09-28 that change how it is deleted: `README.md` and `RUN-SHEET.md` were uploaded too and are **web-readable** at `/ethr-canary/`, which the canary's own README says they should never be; and Plesk's default `index.html`, `favicon.ico`, `css/` and `cgi-bin/` are **inside** that directory, so deleting it deletes them. Losing them is harmless — the deployment ships its own front controller and favicon, and no CGI is used |
| **Support request** | **DRAFTED, NOT SENT** | Four asks. Cron and `TRIGGER` are the two that change anything |

---

## Rotation — one item, and it is not urgent

**A real Laravel `APP_KEY` was committed to `START_BACKEND.ps1` and remains reachable
in git history.** Removed from the working tree in `090ca50` (2026-09-15); history is
not being rewritten, so it is still retrievable by anyone who can clone.

**What the evidence says**, from that commit's own message and
`B1-B5_GATE_REPORT.md:168`: it was a **local-development fallback** for
`php artisan serve`, and ETHR **has no production instance**, so it is not known to have
reached a deployed environment.

**The requirement, stated as a requirement:**

- **The production `APP_KEY` must be generated fresh on the host** — `key:generate` is
  step 1 of the deployment actions — **and must never be that value.**
- Anything encrypted with it in a **local** database should be treated as readable by
  anyone who has cloned this repository.
- No other credential was found exposed. Not a cutover blocker, because the key it
  concerns was never a production key; listed so it cannot be lost.

---

## Quota arithmetic — check before cutover, not after

**5 GB total**, shared by the application, `vendor/`, the exported frontend, logs, every
uploaded employee document **and every retained backup.**

- `BACKUP_KEEP=2`, and `prune()` runs **after** `create()` — so at peak **four** copies
  of the document store exist simultaneously.
- The static export is ~689 files; `vendor/` is 163 packages.
- **A full quota presents as data corruption, not as an outage:** writes fail while reads
  succeed, logs fail first so the evidence goes missing, and `/api/v1/health` still
  returns 200.

Configure off-host retention (`--off-host` with a real S3-compatible disk) or accept
local-only retention and **watch free space in the panel from week one.**

---

## The rule this document exists to enforce

**Do not convert `HOST ACTION REQUIRED` into `PASS` without host evidence.** Not because
a green build is worthless — it is why the repository half is finished — but because the
two halves answer different questions, and this project has already recorded what
happens when they are conflated:

- `vendor/bin/pest` collected 21 of 132 classes and **exited 0 green** for weeks.
- Fifty CI runs had never executed a single gate, while the documentation said CI was
  *"probably fine but unconfirmed"*.
- Six routes served the wrong page while a 20-of-20 Apache matrix reported success.

**Every one of those was green.**

---

## Related

- [`GATE-0-RESULT.md`](GATE-0-RESULT.md) → *Gate status reconciliation* — the per-gate evidence register
- [`../MIGRATION_STATE.md`](../MIGRATION_STATE.md) — the manual queue (M1, M2, M3, M5, M6)
- [`../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md) — the stage sequence
- [`shared-hosting/DEPLOYMENT.md`](shared-hosting/DEPLOYMENT.md) — the executable procedure
- [`M6-RESTORE-REHEARSAL.md`](M6-RESTORE-REHEARSAL.md) · [`G0-F-CREATE-TRIGGER.md`](G0-F-CREATE-TRIGGER.md)
- [`../decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md`](../decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md) · [`shared-hosting/cron-caller.md`](shared-hosting/cron-caller.md)
