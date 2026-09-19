# Decommissioning the VPS / Docker production stack

Root `CLAUDE.md`: *"Production target is Ethio Telecom Linux shared hosting under
Plesk. The VPS and Docker production assets are kept only until that cutover is
verified."*

This file is the inventory that turns "kept only until" into a procedure. It
exists because the removal is currently **blocked**, and a blocked removal with
no written scope becomes an archaeology exercise later — somebody has to work
out a second time which of six `docker-compose*.yml` files are production and
which are how you run the tests.

Written 2026-09-18. Nothing has been removed.

---

## The blocker, stated once

**Gate 0 has not been run against the account.**
`deployment/GATE-0-RESULT.md` is explicit: *"Status: NOT RUN against the account"*,
*"Run by: owner (requires the Plesk account — this cannot be automated from the
repository)."* `MIGRATION_STATE.md` lists the four facts it needs — PHP version and
extensions (B4), `CREATE TRIGGER` (H1), Node presence (B5), cron (B3) — and says
all four require the Plesk panel or an SSH session to `213.55.96.154`.

**Nothing in this repository can clear it**, which is why the stack below is still
here. Removing the old deployment path before the new one is measured would leave
the product with neither.

A second, softer gate: **B5 decides the frontend**. If Node ≥ 20.9 is present the
Next.js standalone build stays; if not, the frontend becomes a static export. The
Docker assets are the only thing that currently builds and serves the standalone
form, so they cannot go before B5 is answered either way.

---

## What is VPS-only, and what merely looks like it

The word "docker" appears on both sides of this line, which is the trap.

### Remove when the blocker clears

| asset | what it does | successor on shared hosting |
|---|---|---|
| `docker-compose.prod.yml` | the production stack — app, mariadb, redis, minio, reverb, worker | Plesk-hosted PHP + the panel's own MySQL/Redis |
| `docker-compose.lowmem.yml` | prod overlay for a small VPS | — (no successor needed) |
| `infrastructure/nginx.conf`, `nginx-common.conf` | the VPS reverse proxy and security headers | `shared-hosting/.htaccess`, with `shared-hosting/nginx-directives.conf` as the fallback if G0-B shows `.htaccess` is ignored |
| `infrastructure/supervisor.conf` | keeps the queue worker and Reverb alive | Plesk *Scheduled Tasks* running `queue:work --stop-when-empty` (**B3**) |
| `infrastructure/certbot-webroot/` | Let's Encrypt renewal via webroot | Plesk issues and renews certificates itself |
| `scripts/deploy.sh`, `rollback.sh` | `docker compose` deploy/rollback | `shared-hosting/DEPLOYMENT.md`, `shared-hosting/rollback.md` — procedures, not scripts |
| `scripts/backup.sh`, `restore.sh` | Docker wrappers around dump/restore | **already exists** — `ethr:backup`, `ethr:restore`, `ethr:backup:rehearse`, pure PHP, no shell, no `mysqldump` |
| `scripts/seed.sh`, `init-storage.sh`, `setup-replication.sh` | prod-stack helpers | seeding is `php artisan db:seed`; storage is a directory; replication is not a shared-hosting concept |
| `scripts/prod-build-test.sh` | builds the prod images and smoke-tests them | no equivalent — the shared-hosting deploy is a file copy, not an image build |
| `docs/VPS_DEPLOYMENT.md` | the VPS runbook | `docs/deployment/shared-hosting/` |

### Keep — these are local development and CI, not production

| asset | why it stays |
|---|---|
| `docker-compose.yml` | **the only supported way to run the stack locally.** The PowerShell launchers were deleted on 2026-09-18; this is what replaced them, and `run-e2e.sh` and `prod-build-test.sh` both drive it |
| `docker-compose.test.yml` | the e2e stack, used by `scripts/run-e2e.sh` |
| `docker-compose.hostnames.yml` | a **local** overlay that maps `*.localhost` so tenant-from-hostname login can be seen on a developer's machine. Nothing to do with the VPS; documented in `LOCAL_SETUP.md` |

---

## One claim this inventory had to correct

`audit/BASELINE.md` §14 said **"There is no non-Docker backup or restore path in
this repository."** That was true when written and is not now. `ethr:backup` and
`ethr:restore` are Artisan commands with no shell-out; `BackupCommand.php:12` says
*"Runs from Plesk Scheduled Tasks as a PHP CLI job — no shell, no mysqldump"*, and
`DatabaseDumper` dumps in pure PHP **because** shared hosting often has neither a
shell nor `mysqldump` on PATH.

It matters here more than most stale lines: taken at face value it says removing
`backup.sh` removes the ability to back up, which would make this decommission
unsafe at exactly the point where it is actually safest. The `.sh` files are
wrappers. The capability is in `app/`, and it already targets the new host.

---

## Order of removal, when it is unblocked

1. **Gate 0 passes**, and B5 has decided standalone-vs-static-export.
2. **Prove the successors on the real host before deleting the predecessor** —
   `ethr:backup` then `ethr:restore` end to end (§30: a backup that has never been
   restored is not verified), one deploy by `shared-hosting/DEPLOYMENT.md`, one
   rollback by `shared-hosting/rollback.md`, and the queue running from Plesk cron.
3. **Then** delete the "remove" table above in one commit.
4. **Expect the documentation to fight back.** These paths are referenced from
   roughly 29 files. `./scripts/gates.sh docs` fails on a broken relative link, so
   the removal commit has to update those references in the same change — which is
   the gate doing its job, not an obstacle.

Do not do step 3 before step 2. The point of keeping the old path is that it is
the thing you fall back to when the new one does not work on the day.
