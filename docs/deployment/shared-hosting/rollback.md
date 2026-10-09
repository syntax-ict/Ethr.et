# Rollback

> **The VPS is gone, so there is no "point DNS back" option.** An earlier version of this
> page said to repoint `ethr.et` at the VPS. That server, `docker-compose.prod.yml`, and
> `scripts/rollback.sh` were removed on 2026-09-26/27 and the Docker development stack on
> 2026-09-30 (`docs/deployment/VPS-DECOMMISSION.md`). Anyone following the old advice
> during an incident would have converted one outage into two.

What exists now is a **redeploy of an earlier commit** for code, and **`ethr:restore`** for
data. Neither is automatic, and nothing on the host keeps the previous release — the deploy
script (`scripts/shared-hosting/deploy.sh`) builds a tarball and replaces the tree.

## Code is wrong, data is fine

1. Find the last good commit (`git log`, or the last green `Quality gates` run).
2. Check it out on a machine that can run the gates, rebuild, and run
   `./scripts/shared-hosting/deploy.sh` exactly as for a normal release
   (`docs/deployment/shared-hosting/deploy-checklist.md`).
3. Do **not** run migrations backwards on production. Every migration here has a `down()`,
   but rolling data-bearing schema back discards what was written since. If the bad release
   added a migration, leave it applied — the previous code ignores columns it does not know.

## Data is wrong

Restore from a backup made by `php artisan ethr:backup`. The procedure, what a restore
overwrites, and the requirement to keep the `APP_KEY` the backup was taken under (the
`Employee.tin` and `national_id` columns are `encrypted` casts and cannot be read without it)
are in `docs/deployment/BACKUP-RESTORE.md`.

**The restore rehearsal on the real host has not been performed** (`CUTOVER-CHECKLIST.md`).
Until it has, treat this path as built and locally round-trip tested, not as proven.

## Before you do either

- Confirm the failure is not the **scheduler**: if `.github/workflows/cron.yml` is not
  running (it must be on the default branch to fire), nothing drains the queue and the
  symptoms resemble a bad release. `docs/deployment/shared-hosting/cron-caller.md`.
- Take a fresh `ethr:backup` first. A rollback that goes wrong on top of an unbacked-up
  state has nowhere left to go.

The historical three-scenario plan is `docs/ROLLBACK_RUNBOOK.md`; it describes the cutover
from the VPS and is **superseded** by this page.
