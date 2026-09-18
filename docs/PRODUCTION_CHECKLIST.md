# Production Checklist — Shared Hosting Migration

The 24-item acceptance list from the original migration brief, with the real status of
each against what has actually been verified. **Not a checkbox exercise** — every row
either points at the evidence or says plainly what's still needed and from whom. Run
this pass after `docs/deployment/shared-hosting/deploy-checklist.md` (the pre-cutover
technical pass) and after DNS cutover, against the real `www.ethr.et` domain.

| # | Item | Status | Evidence / what's left |
| --- | --- | --- | --- |
| 1 | ETHR builds successfully | ✅ | All 9 gates green. *(The `main` SHA quoted here was `92c9f03`; `main` is now `51fca3a`. Kept as a dated observation rather than chased — CI is the live answer.)* |
| 2 | Existing tests pass | ✅ | **1673 passed, 4966 assertions** (measured 2026-09-15). The suite has grown since — PR #16 and the document-root inventory test — so treat the figure as dated and CI's `Backend` job as current |
| 3 | Production environment works | ⬜ | Not yet deployed. The gate list here uses the old `B3/B4/B5/H1` taxonomy; the current register is `MIGRATION_STATE.md` → blockers **B-1** (SSH Forbidden), **B-2** (no custom-directive field), **B-3** (unidentified vhost), **B-4** (nothing runs `artisan`), **B-5** (no database import route), **B-6** (rollback target). B-1/B-4 are the ones that decide whether deployment is possible at all |
| 4 | Database works | ◐ | Schema and migrations verified against real MariaDB locally; not yet run against the actual Plesk MySQL — `docs/DATABASE_MIGRATION_PLAN.md` |
| 5 | Authentication works | ✅ (locally/live-tested) | Real Sanctum login exercised this session against the demo tenant; not yet against the target host |
| 6 | MFA works | ✅ | Pre-existing, untouched by this migration, covered by the existing suite |
| 7 | Tenant isolation works | ✅ | `TenantIsolationTest` — pre-existing, unaffected |
| 8 | Tenant subdomains work | ◐ | Wildcard DNS confirmed externally and re-confirmed 2026-09-17. "Plesk accepts `*`" is an **owner report, not recorded output** — `deployment/GATE-0-RESULT.md` holds G0-C at PARTIAL under its own *only from output* rule. The vhost has **never been created**, which is the step neither document claims has happened |
| 9 | Employee management works | ✅ | Untouched |
| 10 | Attendance works | ✅ | Untouched; live-verified this session as a side effect of plan-gating tests (Starter tenant, real data returned) |
| 11 | Leave works | ✅ | Untouched |
| 12 | Payroll works | ✅ **fixed this migration** | Was applying a superseded tax schedule (979/2016 instead of 1395/2025) — corrected, effective-dated, live-verified against real MariaDB. **Needs a tax adviser/ERCA confirmation before the first live run** — see `docs/MIGRATION_STATE.md` |
| 13 | File storage works | ◐ | Made configuration-driven (was hardcoded to `minio`); not yet exercised against the actual `local`/S3 disk on the target host |
| 14 | Email works | ⬜ | Needs real SMTP credentials — nobody has supplied them; code path unchanged from the working VPS configuration |
| 15 | Background jobs work | ◐ | `QUEUE_CONNECTION=database` needs zero code change (verified: no `Redis::` calls anywhere); needs cron actually configured (B3) to run at all |
| 16 | Scheduled jobs work | ◐ | Same as #15 — the 11 entries in `routes/console.php` are unchanged; delivery mechanism depends on B3 |
| 17 | Realtime functionality works OR has an approved external equivalent | ✅ **equivalent approved** | `BROADCAST_CONNECTION=log`; every notification's broadcast leg already guards on this; the one frontend consumer already falls back to its existing 30s poll on failure — this was a design review, not a code change |
| 18 | Amharic works | ✅ | Untouched; `i18n` gate green |
| 19 | Ethiopian calendar works | ✅ | Untouched |
| 20 | Security audit passes | ◐ | This session's own audit found and fixed one real regression risk (the audit-log migration was briefly weakened to fail-open, caught and reverted before merge — `docs/AUDIT_LOG_INTEGRITY_DECISION.md`) and found the plan-tier billing gate had no enforcement at all (fixed). No external/independent security audit has been run against the shared-hosting deployment itself, because it doesn't exist yet. |
| 21 | No secrets exposed | ◐ | `.htaccess` denies `.env`/`.git`/etc. explicitly; the chosen layout (`~/ethr` above `~/httpdocs`) makes this structural rather than rule-dependent — but unverified against the real server until deployed |
| 22 | Backup works | ⬜ | VPS backup scripts (`scripts/backup.sh`) assume Docker + SSH; they do not apply, and **SSH is Forbidden on the target**, so the manual equivalent in `DATABASE_MIGRATION_PLAN.md` does not apply either (blocker B-5). Separately and more urgently: `deployment/BACKUP-RESTORE.md:119` records that `--off-host` was never configured, so any existing VPS backup lives **only on the VPS** — and `Employee.tin`/`national_id` are `encrypted` casts, so a dump without its `APP_KEY` cannot be read |
| 23 | Restore has been tested | ⬜ | Depends on #22 existing first |
| 24 | Rollback procedure exists | ⬜ **downgraded from ✅ on 2026-09-18** | `docs/ROLLBACK_RUNBOOK.md` exists, but **blocker B-6** found its premise false: Scenario A is headed *"before DNS cutover (the current state)"* and says `ethr.et` still points at the VPS — it resolves to the Plesk host `213.55.96.154`, measured twice on 2026-09-17. And Scenario B says to repoint *back to the VPS IP*, whose ports this repository recorded as all closed. **A rollback plan whose target may serve nothing is not a rollback procedure.** The code-level `git revert -m 1` path is unaffected and still holds. See `MIGRATION_STATE.md` → B-6 |
| 25 | Production deployment is documented | ✅ | `docs/deployment/shared-hosting/` (this package) — `DEPLOYMENT.md`, `ENVIRONMENT.md`, `.htaccess`, `deploy-checklist.md`, `health-check.md`, `rollback.md`, plus this file |

**Legend:** ✅ done · ◐ partial / code-ready but not verified against the real target ·
⬜ not started, and honestly says so rather than being marked partial to look further
along than it is.

## Reading this table honestly

**Revised 2026-09-18.** Row 24 was ✅ and is now ⬜; the count below predates that and
predates blockers B-1 to B-6. Treat `MIGRATION_STATE.md` as the live register and this
table as the acceptance *shape* rather than the current tally.

**7 of 25 are genuinely blocked on external input** (14 email, 22–23 backup/restore
need building, 3–4–8–13 need the real host to exist). **None of the rest are blocked on
more code** — the application-layer work for this migration is finished; what remains
is execution (deploy, configure, verify) and two credentials/confirmations (SMTP, ERCA
tax sign-off) nobody but the owner can supply.
