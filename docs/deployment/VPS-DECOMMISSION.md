# Retiring the VPS assets — the trigger, and what is *not* removable

**Status: NOT TRIGGERED. Nothing here has been done, and nothing here should be done yet.**

The owner has asked that the VPS files be removed once the migration plan ends
successfully. This file exists so that instruction is a **defined action with a testable
trigger and a checked inventory**, rather than a promise someone acts on from memory on a
day when the details are no longer fresh.

**The headline finding is that "remove the VPS files" is not a clean delete.** Of the
sixteen assets, **five are load-bearing for the shared-hosting target or for local
development and CI** — two of them are cited by the very documents that describe the *new*
deployment. Deleting the set wholesale would break the thing the migration is moving *to*.

---

## 1. The trigger

**This is not a new condition.** It is already stated in
[`../VPS_DEPLOYMENT.md`](../VPS_DEPLOYMENT.md), and is restated here only so the inventory
below has something to point at:

> **Retire it when:** the Plesk cutover is verified, the rollback rehearsal has been
> performed, and the observation period in master plan §60 has passed.

All three clauses, not any one of them. The rollback rehearsal is the clause most likely to
be skipped, and it is the one that makes the others safe to act on: **the VPS assets are the
rollback path.** Removing them converts a reversible cutover into an irreversible one.

### Where the plan actually stands, measured

| | |
|---|---|
| Gate 0 | **1 verified, 1 failed, 28 outstanding** |
| Application installed on the Plesk host | **No** — `key:generate`, `migrate`, `db:seed` and `ethr:create-admin` have no runner (SSH Forbidden, no Scheduled Tasks) |
| DNS cut over | **No** |
| Rollback rehearsal | **Not performed** |
| Observation period | **Not started** |

**Nothing has been deployed to the Plesk account.** The trigger is not close to firing, and
the distance is not a formality — the installation step has no route at all. See
[`PLESK-HOSTING-GUIDE.md`](PLESK-HOSTING-GUIDE.md).

---

## 2. What must NOT be removed, and why

Read this section before the removable list, because it is the part that is easy to get
wrong and expensive to get wrong.

| Asset | Why it stays |
|---|---|
| **`docker/frontend/Dockerfile`** | **It is the only declaration of the frontend runtime version** — `FROM node:22-alpine`, which both builds and runs `server.js`. The Plesk **Node.js branch depends on it**: `PLESK-HOSTING-GUIDE.md` §5b and `shared-hosting/DEPLOYMENT.md` step 5 both cite it as the reason the account's Node 22.23.2 is correct. Cited by **8 documents**. Deleting it removes the new target's version pin, not the old target's. |
| **`infrastructure/nginx-common.conf`** | **It is the source of the shared-hosting security headers.** `shared-hosting/.htaccess` and `shared-hosting/nginx-directives.conf` are translations of it and say so. Delete it and the deployment headers lose their origin — and the two translations lose the thing they must be kept in sync with. |
| **`docker-compose.yml`** | Local development. `CLAUDE.md` names it as *the* way to run the four processes; `scripts/gates.sh` falls back to `docker exec` against its container in five places. |
| **`docker-compose.test.yml`** | Testing. |
| **`docker/php/*`** (`Dockerfile`, `php.ini`, `www.conf`) | The development container `gates.sh` execs into. `php.prod.ini` and `www.prod.conf` are the production halves and belong in the removable list. |

**None of these is a VPS production asset.** They were swept up by the phrase "VPS files"
because they live in `docker/` and `infrastructure/`. The distinction that matters is
*production-VPS-only* versus *development, CI, or new-target*.

---

## 3. What comes out when the trigger fires

Verified as production-VPS-only, 2026-09-24:

| Asset | Note |
|---|---|
| `docker-compose.prod.yml` | The VPS production stack. **Cited by 13 documents** — they need updating in the same change, not afterwards |
| `docker-compose.lowmem.yml` | VPS memory-constrained variant. Cited by 4 |
| `docker-compose.hostnames.yml` | VPS hostname variant. Cited by 2 |
| `docker/php/php.prod.ini`, `docker/php/www.prod.conf` | Production PHP-FPM tuning |
| `docker/nginx/default.conf` | VPS nginx vhost |
| `docker/mariadb/*.cnf` | VPS MariaDB primary/replica/standalone — the read-replica split is disabled on shared hosting |
| `infrastructure/nginx.conf`, `infrastructure/supervisor.conf` | VPS-only. **`nginx-common.conf` is not in this list** — see §2 |
| `infrastructure/certbot-webroot/` | Plesk manages the certificate |
| `docs/VPS_DEPLOYMENT.md` | The rollback path. **Out last, not first** |

### Separate question, deliberately not bundled

`RUN_ALL.ps1`, `START_BACKEND.ps1`, `START_FRONTEND.ps1` are **not VPS assets** — they are
Windows launchers that predate the Docker setup. `CLAUDE.md` already records that
`RUN_ALL.ps1` prints "SQLite" while the documented stack is MariaDB and starts neither the
queue worker nor Reverb. They are arguably worse than the VPS files and should be removed or
fixed on their own merits, **on their own schedule**. Bundling them here would make one
change answer two unrelated questions.

---

## 4. Order of operations

1. **Confirm all three trigger clauses**, not just the cutover. The rehearsal is the one that
   makes this reversible-to-irreversible step safe.
2. **Relocate `infrastructure/nginx-common.conf`'s content first**, or keep the file. Nothing
   else in the removable list has a dependency that must move ahead of it.
3. **Remove the §3 assets and update the documents that cite them in the same change.** 13
   documents cite `docker-compose.prod.yml` alone; a removal that leaves them pointing at
   nothing trades a stale asset for a stale document, which is the worse of the two because
   it is harder to notice.
4. **`docs/VPS_DEPLOYMENT.md` goes last.** It is the rollback path; it stops being one only
   when nothing else could need it.
5. **Run `./scripts/gates.sh docs`.** Link integrity is what catches a citation left dangling.

> **The VPS host itself is out of scope for this document.** Standing instruction: do not
> delete, destroy or shut down the VPS. This file is about files in this repository.
