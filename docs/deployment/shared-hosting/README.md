# ETHR — Shared Hosting Deployment Package

Everything needed to deploy ETHR to Ethio Telecom shared hosting, once
`docs/MIGRATION_STATE.md`'s four remaining facts (B3, B4, B5, H1) are answered. Built
2026-08-31, ahead of those answers, deliberately: most of this package does not depend
on them, and the parts that do are written as explicit branches rather than left for
later — see each file's own "branch on B5/B3" sections.

| File | What it's for |
| --- | --- |
| `DEPLOYMENT.md` | The runbook — step by step, upload to DNS cutover |
| `ENVIRONMENT.md` | Every `.env` change against the VPS template, with the reasoning |
| `.htaccess` | The actual document-root file — copy to `<DOCROOT>/.htaccess` (`ethr.et/` on this account) (`DEPLOYMENT.md` step 4a assembles the whole document root, and says what must *not* be copied into it) |
| `deploy-checklist.md` | Pre-cutover checklist — run once, before pointing DNS here |
| `health-check.md` | What to check post-deploy, and on an ongoing basis |
| `rollback.md` | Short pointer — the real plan is `docs/ROLLBACK_RUNBOOK.md` |

Related, at the `docs/` level rather than in this package: `SHARED_HOSTING_AUDIT.md`
(why each change is safe), `B1-B5_GATE_REPORT.md` (what's verified about this specific
account), `AUDIT_LOG_INTEGRITY_DECISION.md` (the one security trade-off this migration
could force), `DATABASE_MIGRATION_PLAN.md`, `ROLLBACK_RUNBOOK.md`,
`PRODUCTION_CHECKLIST.md`, `MIGRATION_CHANGELOG.md`.

**No secrets are in this package.** `.htaccess` and `ENVIRONMENT.md` name every
variable that needs a real value; neither contains one.
