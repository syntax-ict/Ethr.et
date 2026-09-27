# ETHR — final cutover checklist

# `CUTOVER READY = NO`

**As of 2026-09-27.** This is the single register that decides whether ETHR may go
live on the Ethio Telecom Bronze account. **Cutover stays BLOCKED until every
mandatory gate below reads `PASS`.**

Five states, and they are not interchangeable:

| State | Means |
|---|---|
| **PASS** | Real evidence exists, on the real host or in the repository as appropriate, with a date |
| **FAIL** | Measured, and the answer was no |
| **HOST ACTION REQUIRED** | Answerable only by acting on the Ethio Telecom account. **Not a soft PASS** |
| **OWNER DECISION** | Needs a choice, not a measurement |
| **BLOCKED** | Waiting on something else in this table |

> **Repository readiness and host readiness are different things, and this document
> exists to keep them apart.** CI is green — 10/10 checks, run `36327393387` — and that
> says the repository builds, tests and packages correctly. **It says nothing about the
> host.** No gate below may be moved to `PASS` on the strength of a green build.
>
> **A 404 on a probe is `NOT RUN`, never a pass.** That distinction has already cost
> this project one false reading (G0-B.3(b), 2026-09-25).

---

## Mandatory gates — all must be PASS

| # | Gate | State | Evidence held | What closes it | Who |
|---|---|---|---|---|---|
| **C-5** | Production frontend mode | ✅ **PASS** | **Owner decision 2026-09-27: static export served by Apache/Plesk, no Plesk Node application.** Propagated to the runbook, the contract, the matrix, the `.htaccess` and the renderer. Artifact built by CI: 689 files, 186 `_next`, 4 shells, no `server.js` | Done. [`../decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md`](../decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md) | — |
| **Q6** | External cron caller chosen | ✅ **PASS** (choice) | **Owner decision 2026-09-27: GitHub Actions.** `.github/workflows/cron.yml` committed; logic verified against 10 response cases; `CronCallerWorkflowTest` pins it, mutation-checked | Done as a *decision*. Execution is the row below | — |
| **Q6-x** | Cron caller **actually running** | 🔶 **HOST ACTION REQUIRED** | None. The endpoints have **never been called** on the account | Add `ETHR_CRON_TOKEN` + `ETHR_CRON_BASE_URL` to the GitHub `production` environment, run the workflow once by hand, and observe a 200 with `"status":"ok"` from **both** endpoints | Owner |
| **M1** | `.htaccess` `[F,L]` deny + CSP survival | 🔶 **HOST ACTION REQUIRED** | `secret.env.probe` → **404 = NOT RUN**; only 2 of 7 headers returned, because the host serves the **2026-09-18** canary | [`../../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md`](../../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md) **§0** — replace the directory, confirm 7 headers, fetch 2 URLs. **403 = pass, 200 = blocks deployment, 404 = NOT RUN** | Plesk owner |
| **M2** | Hosting probe | 🔶 **HOST ACTION REQUIRED** | None. Could not be placed: no shell, no FTP, no panel in that session | Run `ethr-hosting-check.php` with database credentials. Closes G0-E extensions/limits, G0-F, G0-H, G0-I and most of G0-J in one run | Plesk owner |
| **G0-F** | `CREATE TRIGGER` | 🔶 **HOST ACTION REQUIRED** | Favourable prior only, and the register itself calls that *"an argument about priors, not a measurement"* | Probe `DB4`, **plus** `SHOW TRIGGERS LIKE 'audit_log'` after `migrate`, **plus** one refused `UPDATE`. All three — [`G0-F-CREATE-TRIGGER.md`](G0-F-CREATE-TRIGGER.md) §5. **Owner decision 2026-09-27: request the grant; do not weaken the migration** | Ethio Telecom → Plesk owner |
| **G0-B.6** | `AllowOverride Options` | 🔶 **HOST ACTION REQUIRED** | None. The Apache harness ran under `AllowOverride All` — the permissive case | Deploy and fetch a page. Without `DirectorySlash Off`, `mod_dir` 301s `/admin` before the SPA rules and **20+ routes are dead**. **No workaround exists** | Plesk owner |
| **M6** | Restore rehearsal on the host | 🔶 **HOST ACTION REQUIRED** — **hard blocker** | **NOT PERFORMED.** CI rehearses on MariaDB in a container only | The nine steps in [`M6-RESTORE-REHEARSAL.md`](M6-RESTORE-REHEARSAL.md), ending with a real write through the application. **`3db9904` removed the VPS, so this is the only recovery evidence this deployment will ever have** | Plesk owner |
| **M3** | Wildcard vhost + certificate + routing | 🔶 **HOST ACTION REQUIRED** | DNS **VERIFIED**; a valid `*.ethr.et` certificate **exists** (LE, 2026-09-16 → **2026-12-15**); **no vhost** — every tenant host 303s to the Plesk login | All four: wildcard DNS, vhost routing, the **served** certificate, and a tenant subdomain reaching the application. DNS alone is not it, and the certificate existing is not it | Owner (payment) → Plesk owner |
| **G0-H** | SMTP 587/465 + mailbox send cap | 🔶 **HOST ACTION REQUIRED** | None | M2 for the ports; panel for the cap. Nothing in the code respects a send cap, and two jobs fan out | Plesk owner |
| **G0-I** | MySQL version, `DB_CONNECTION`, charset | 🔶 **HOST ACTION REQUIRED** | None | M2, probes `DB1`/`DB1b`/`DB1c`/`DB10`. The `devices` VIRTUAL generated column needs 5.7.6+ / 10.2.1+ | Plesk owner |
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
| **M5** delete the canary | **HOST ACTION** | After M1, not before — M1 needs the directory |
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
