# Retiring the VPS assets — the trigger, and what is *not* removable

**Status: EXECUTED 2026-09-26 — partially, and BEFORE a verified cutover.**

> **This was done at the owner's explicit and repeated direction, against the advice in
> §3b.5.** The trigger this document defines — a verified cutover — had not fired. The static
> export has never served a request on the Ethio Telecom host, `[F,L]` is still unmeasured
> there (M1), and `AllowOverride Options` is a requirement this migration newly introduced.
>
> **There is no rollback path now.** `docs/VPS_DEPLOYMENT.md` is gone. The way back is
> `git revert` of the removal commit, which restores the files but not a running VPS.

### Removed (22 paths)

*(15 on 2026-09-26, seven more on 2026-09-27 — the second pass is marked.)*

`docker-compose.{prod,lowmem,hostnames}.yml` · `docker/php/{php.prod.ini,www.prod.conf}` ·
`docker/mariadb/{primary,replica,standalone}.cnf` ·
`infrastructure/{nginx.conf,supervisor.conf,certbot-webroot/}` · `docs/VPS_DEPLOYMENT.md` ·
`api/Dockerfile.prod` · `api/.env.production.example` ·
**`.env.production.example` (repo root)** ·
**`scripts/{deploy,rollback,init-storage,prod-build-test,setup-replication}.sh`** ·
**`infrastructure/nginx-common.conf`** — which empties `infrastructure/` entirely

### The manifest missed a ninth asset — the root `.env.production.example`

**Measured 2026-09-27.** §3b.2 names `api/.env.production.example` and stops there. There is
a **second** template, tracked, 4.3 KB, at the repository root, and its own header states its
entire purpose:

> *"docker-compose.prod.yml resolves ${VAR} placeholders (mariadb root/app credentials, redis
> password, minio keys, frontend build args) from a `.env` file in this directory … Without
> this file, `docker compose -f docker-compose.prod.yml up` resolves every ${VAR} below to an
> empty string."*

`docker-compose.prod.yml` went on 2026-09-26. **Nothing outside `docs/` referenced this file**
— measured by fixed-string grep across the tree. It is VPS-only by exactly the test §3
applies, and it is the **fifth** asset the list got wrong, after the test dependency,
`Dockerfile.prod`, the eight scripts and `docker/nginx/default.conf`. Same cause, stated in
§3's own words: *"the list was built by asking 'is this VPS-shaped?' rather than 'what reads
this?'"*

**Removed 2026-09-27.**

### Five of the eight scripts are gone; three are deliberately held

**2026-09-27.** The `git rm` below succeeded for five. **`backup.sh`, `restore.sh` and
`seed.sh` are still present, on purpose**, and the reason is §3b.2a's own two qualifications
rather than a permission refusal:

| Held | Why |
|---|---|
| `backup.sh`, `restore.sh` | `ethr:backup` / `ethr:restore` are rehearsed in CI against MariaDB and have **never been rehearsed on the Ethio Telecom host** — that is Stage 6 of the migration plan. Deleting these removes a working path in favour of an untested one |
| `seed.sh` | §3b.2a's weakest row. Its replacement is *"Plesk Git additional deployment actions"* — a documented route, not a measured one, on an account where SSH is Forbidden |

All three are inert: nothing automated calls them, and each fails at first use because
`docker-compose.prod.yml` is gone. **They come out after the Stage 6 host rehearsal**, with
these citation edits in the same change: `BackupCommand.php:16`, `BackupService.php:21`,
`BackupRestoreRehearsalTest.php:27`, `api/.env.example`, `api/.env.shared-hosting.example`.

### Dangling citations the docs gate cannot catch

**Measured 2026-09-27, and this is a class, not a list.** `./scripts/gates.sh docs` was
**green** immediately after the 2026-09-26 removal — it checks *relative markdown links*, and
every reference to a deleted asset was **prose or a code comment**. Link integrity proves
nothing about them.

Two were wrong instructions rather than stale asides:

| File | Was | Now |
|---|---|---|
| `api/.env.example` | *"copy `api/.env.production.example`, the complete, commented template"* | Names `api/.env.shared-hosting.example`, and records what the old one was |
| `docs/DEPLOYMENT.md` §Quick Start, §Environment Variables | `cp .env.production.example .env` and `cp api/.env.production.example api/.env.production` | **A supersession banner at the top of the file**, naming every deleted asset, what survives (*Draining the queue before an upgrade* — the root `CLAUDE.md` cites it as a deploy precondition) and where the live procedure is. The `cp` lines are marked in place |

Three were stale asides, corrected to point at what is actually true:
`.dockerignore` (two comments explained themselves by `Dockerfile.prod`; the rules still
stand for `docker/php/Dockerfile`, which is why they were re-justified rather than deleted),
`docker/php/php.ini` (*"production uses php.prod.ini"* — there is no production counterpart
any more), `docker-compose.yml:81` (queue order and `--max-time` cited
`infrastructure/supervisor.conf`; the reasoning is now inline).

The root `.gitignore`'s `.prod-test-tmp/` entry went with `prod-build-test.sh`, which created it.

**Verified after:** docs gate green (390 links, 88 files); `HostingRequirementsConsistencyTest`,
`DeploymentWorkerConsistencyTest` and `DocumentRootInventoryTest` — **10 passed, 37
assertions**, native PHP 8.2, no Docker.

### `infrastructure/` no longer exists

`infrastructure/nginx-common.conf` was the only file left in it, and it went on 2026-09-27.
§2's row for it is struck through — *"freed 2026-09-25 … it can be deleted without touching
either"* — and §4 step 2 had already moved it to the removable set. `shared-hosting/.htaccess`
says the same thing in its own words, which is what made this safe: *"can be deleted without
touching this file, which is what the sync claim was preventing."*

**What the deletion costs, stated rather than omitted:** the CSP comparison table in
`shared-hosting/.htaccess` (seven differences, six of which make the shared-hosting pair the
stricter one) now compares against a file nobody can open. It is a **record**, not a live
check, and it says so. That was already true before the deletion — the "byte-identical" sync
claim it replaced was measured false on 2026-09-25 — so nothing that was being enforced
stopped being enforced. The citations in `MIGRATION_STATE.md`, `REPOSITORY-INVENTORY.md` and
`SHARED_HOSTING_AUDIT.md` are provenance in historical documents and are marked, not rewritten.

### NOT removed on 2026-09-26 — eight scripts, and they are now broken

*(Five of them were removed on 2026-09-27 — see the section above. The original account follows.)*

`scripts/{deploy,rollback,backup,restore,seed,init-storage,prod-build-test,setup-replication}.sh`

The permission layer refused every deletion under `scripts/`, individually as well as in
bulk. **All eight drive `docker-compose.prod.yml`, which no longer exists**, so they will
fail at first use. Nothing automated calls them — `gates.sh` and the workflows reference none
of them — so they are inert rather than dangerous, but they must go:

```bash
git rm scripts/deploy.sh scripts/rollback.sh scripts/backup.sh scripts/restore.sh \
       scripts/seed.sh scripts/init-storage.sh scripts/prod-build-test.sh \
       scripts/setup-replication.sh
```

### §3 misclassified one file, and deleting it broke local development

**`docker/nginx/default.conf` is NOT VPS-only.** §3 lists it as *"VPS nginx vhost"*. It is
also mounted by the **local development** stack — `docker-compose.yml:7`,
`./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro` — which is what
`scripts/gates.sh` execs into. It was deleted, the mount was caught in the post-deletion
audit, and it has been **restored**. It is not part of this removal.

This is the fourth asset §3 got wrong, after the test dependency, `Dockerfile.prod` and the
eight scripts. The pattern is consistent and worth stating: **the list was built by asking
"is this VPS-shaped?" rather than "what reads this?"**

### Code changes made in the same commit

`DeploymentWorkerConsistencyTest` (`ETHR_WORKER_ASSETS` 4 → 1, and its third test deleted —
it existed solely for `prod.yml` and `lowmem.yml`); `HostingRequirementsConsistencyTest`
(**re-baselined, not deleted** — it read `.env.production.example` to catch a silently
dropped key, so the 66 keys are now pinned explicitly and the check survives);
`middleware.ts` (its docblock named nginx as *"the authoritative control"* for `/admin`);
`build-target.ts`, `CronRunController`, `config/database.php`,
`document-root-inventory.php`, `scripts/shared-hosting/deploy.sh`, and the three markdown
links to `VPS_DEPLOYMENT.md`.

---

**The original text follows, and its trigger language is now historical:**


**Directed on 2026-09-25, trigger notwithstanding.** The owner instructed removal — "remove
all until no vps dependency and the remains shared webhosting" — after the rollback-path
cost in §1 and the load-bearing set in §2 were put to them. That is their call to make, and
this line records it rather than the earlier "nothing here should be done yet", which no
longer describes the instruction in force.

**It was attempted and did not complete.** The deletion step was refused by the agent
sandbox's permission classifier, so **no file has been removed** and the three trigger
clauses in §1 remain unmet — the cutover is unverified, the rehearsal unperformed, the
observation period unstarted. The measurement taken before that step is in §3 below, and it
found the inventory here materially incomplete. Read it before trying again.

The owner has asked that the VPS files be removed once the migration plan ends
successfully. This file exists so that instruction is a **defined action with a testable
trigger and a checked inventory**, rather than a promise someone acts on from memory on a
day when the details are no longer fresh.

**The headline finding is that "remove the VPS files" is not a clean delete.** Of the
sixteen assets *(an undercount — see the 2026-09-25 amendment in §3, which adds
`api/Dockerfile.prod`, eight scripts and two env templates)*, **five are load-bearing for
the shared-hosting target or for local development and CI** — two of them are cited by the very documents that describe the *new*
deployment. Deleting the set wholesale would break the thing the migration is moving *to*.

---

## 1. The trigger

**This is not a new condition.** It is already stated in
`../VPS_DEPLOYMENT.md` (removed 2026-09-26), and is restated here only so the inventory
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

*(One row below is struck through: `infrastructure/nginx-common.conf` was freed on
2026-09-25 and is now removable. It is kept in the table rather than deleted from it,
because the reason it was thought load-bearing is worth reading before something else is
assumed to be.)*

| Asset | Why it stays |
|---|---|
| **`docker/frontend/Dockerfile`** | **It is the only declaration of the frontend runtime version** — `FROM node:22-alpine`, which both builds and runs `server.js`. The Plesk **Node.js branch depends on it**: `PLESK-HOSTING-GUIDE.md` §5b and `shared-hosting/DEPLOYMENT.md` step 5 both cite it as the reason the account's Node 22.23.2 is correct. Cited by **8 documents**. Deleting it removes the new target's version pin, not the old target's. |
| ~~`infrastructure/nginx-common.conf`~~ | **NO LONGER LOAD-BEARING — freed 2026-09-25, and it is now removable.** This read: *"It is the source of the shared-hosting security headers... the two translations lose the thing they must be kept in sync with."* The sync obligation was **never met**: the `.htaccess` and nginx CSPs differ in six places, measured today, and the shared-hosting pair is the *stricter* one — it adds `frame-ancestors`, `base-uri` and `form-action`, which nginx never carried, and drops `wss:` because Reverb is not deployed. Honouring the obligation would have **loosened** the deployment. Both files now declare the shared-hosting headers as their own authority and name this file only as provenance, so it can be deleted without touching either. |
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

### The §3 list above is incomplete, and its citation count is wrong — measured 2026-09-25

An attempt to execute this removal was made on 2026-09-25 and stopped at the deletion step
(the sandbox's permission classifier refused it). The measurement taken first is recorded
here, because **every one of these findings would have been discovered mid-removal**, which
is the worst moment to discover them.

**1. A test hard-depends on three of the assets, and §3 does not mention it.**
`api/tests/Feature/DeploymentWorkerConsistencyTest.php` reads files from disk through
`ETHR_WORKER_ASSETS`:

```php
const ETHR_WORKER_ASSETS = [
    'infrastructure/supervisor.conf',
    'docker-compose.yml',
    'docker-compose.prod.yml',
    'docker-compose.lowmem.yml',
];
```

Three of those four are on the removal list. Two of its four tests iterate that constant,
and a **third test exists solely for `docker-compose.prod.yml` and `docker-compose.lowmem.yml`**
— `it('keeps the unfixed worker stacks marked as broken')`. Deleting the files without
editing the test turns the **Backend** gate red on the removal commit itself.

The edit is not a deletion of the test. It keeps its purpose against the surviving asset:
`ETHR_WORKER_ASSETS` becomes `['docker-compose.yml']`, the third test goes with the two
files it guards, and the header docblock moves to the past tense. The property being
protected — that the deployment asset starting a worker uses a command that exists and
drains every queue in `QueueHealth::QUEUES` — still applies to the local stack.

**2. `api/Dockerfile.prod` is missing from §3 entirely.** It exists, it is
production-VPS-only, and nothing in this document names it.

**3. `docker-compose.prod.yml` is cited by 36 files, not 13.** The 13 in §3 is an
undercount by a factor of nearly three. Full citation surface across every removal
candidate: **55 tracked files**.

**4. Four of those citers are application code and tests, not documents** — a category §3
does not anticipate:

| File | What it says |
|---|---|
| `api/config/database.php:186` | comment — the read-replica split's own container |
| `api/app/Http/Controllers/Api/V1/Cron/CronRunController.php:23` | comment — `infrastructure/supervisor.conf` and the compose workers |
| `src/src/middleware.ts:26` | comment — nginx refuses `/admin` on non-platform hosts |
| `api/tests/Feature/document-root-inventory.php:9` | comment — the API vhost's files on the VPS |

All four are **comments**, so nothing breaks at runtime — but each becomes a pointer to a
file that no longer exists. (`DeploymentWorkerConsistencyTest` is the exception: it is code
that reads the files, per finding 1.)

**5. Eight VPS-only shell scripts are not in §3**, four of which open with the literal line
`# Override with COMPOSE_FILE=docker-compose.lowmem.yml on the 4 GB tier`:

`scripts/deploy.sh` · `scripts/rollback.sh` · `scripts/restore.sh` · `scripts/seed.sh` ·
`scripts/backup.sh` · `scripts/init-storage.sh` (creates the MinIO bucket) ·
`scripts/prod-build-test.sh` · `scripts/setup-replication.sh` (MariaDB primary→replica)

`scripts/shared-hosting/deploy.sh` and `scripts/shared-hosting/smoke-check.sh` are the
replacements and stay.

**6. Two `.env.production.example` templates** — at the repository root and at `api/` —
plus `.dockerignore`, all carry VPS-only values.

**What was verified safe, so the removal is not blocked on it:** every workflow in
`.github/workflows/` invokes only `./scripts/gates.sh` (backend, mysql, frontend, docs,
coverage, security) and `./scripts/api-types-check.sh`. `scripts/gates.sh` references
**none** of the removal candidates — its five `docker exec` fallbacks target
`docker-compose.yml`, which stays. So the Pest test in finding 1 is the **only** gate at
risk.

**Revised order of operations**, superseding §4 for the assets above:

1. Edit `DeploymentWorkerConsistencyTest.php` **first**, in the same commit as the
   deletions — not after. A commit that deletes the files alone is red.
2. Delete the §3 assets **plus** `api/Dockerfile.prod`.
3. Repoint or retire the four code comments in finding 4.
4. Fix every **relative markdown link** to a deleted file; `./scripts/gates.sh docs` is what
   catches these and is the gate that proves it.
5. Prose mentions in dated records — `audit/BASELINE.md`, `MIGRATION_STATE.md`, the phase
   documents — describe measurements taken when those files existed. Annotate; do not
   rewrite. Rewriting a record to match the present is how a measurement stops being one.
6. The scripts and env templates of findings 5 and 6 are a **separate change**. They are
   removable on the same trigger, but bundling them makes one commit answer two questions.

### Separate question, deliberately not bundled

`RUN_ALL.ps1`, `START_BACKEND.ps1`, `START_FRONTEND.ps1` are **not VPS assets** — they are
Windows launchers that predate the Docker setup. `CLAUDE.md` already records that
`RUN_ALL.ps1` prints "SQLite" while the documented stack is MariaDB and starts neither the
queue worker nor Reverb. They are arguably worse than the VPS files and should be removed or
fixed on their own merits, **on their own schedule**. Bundling them here would make one
change answer two unrelated questions.

---

---

## 3b. The executable manifest — measured 2026-09-26

§3 names ten assets. **Deleting exactly those ten breaks fourteen code and script files and
twenty documents**, and §3 mentions one of them. This section is the list that can actually
be executed, measured against the tree at `edb7493`.

It changes nothing about the trigger: **still NOT TRIGGERED.** This is the removal made
ready, not the removal done.

### 3b.1 Citation counts, re-measured

§3 says `docker-compose.prod.yml` is *"cited by 13 documents"*. It is **20**, plus **14**
code and script files.

| Asset | Docs | Code/scripts |
|---|---|---|
| `docker-compose.prod.yml` | **20** | **14** |
| `infrastructure/nginx.conf` | 13 | 2 |
| `infrastructure/supervisor.conf` | 11 | 2 |
| `docker-compose.lowmem.yml` | 9 | 6 |
| `docs/VPS_DEPLOYMENT.md` | 8 | 1 |
| `infrastructure/certbot-webroot/` | 5 | 0 |
| `docker-compose.hostnames.yml` | 4 | **0** |
| `docker/nginx/default.conf` | 2 | 1 |
| `docker/php/php.prod.ini` | 2 | 1 |
| `docker/php/www.prod.conf` | 1 | 1 |
| `docker/mariadb/{primary,replica,standalone}.cnf` | — | — |

`docker-compose.hostnames.yml` is the only asset with **no code reference at all**. It is
still not removable on its own: four documents cite it, and pulling one file out of an atomic
change buys nothing.

### 3b.2 Assets §3 omits entirely

These are VPS-production-only by the same test §3 applies, and deleting §3's list without
them leaves references pointing at nothing:

| Asset | Why it is in scope |
|---|---|
| `api/Dockerfile.prod` | The VPS production image. It is what references `php.prod.ini` and `www.prod.conf`, so those two cannot go while it stays |
| `scripts/deploy.sh`, `rollback.sh`, `backup.sh`, `restore.sh`, `seed.sh`, `init-storage.sh`, `prod-build-test.sh`, `setup-replication.sh` | Eight scripts driving `docker-compose.prod.yml`. **Classified 2026-09-26 — all eight are VPS-only.** See §3b.2a |
| `api/.env.production.example` | The VPS production template. VPS-only. `api/.env.shared-hosting.example` **stays** — only its citation of `prod.yml` changes |

#### 3b.2a The eight scripts, classified — measured, not assumed

This replaces *"classify each before deleting"*. Every one drives Docker Compose, and every
capability it provides has a shared-hosting replacement that **exists in the tree today**:

| Script | What it does | Shared-hosting replacement | Verified present |
|---|---|---|---|
| `deploy.sh` | `docker compose` deploy to the VPS | `scripts/shared-hosting/deploy.sh` | ✅ |
| `rollback.sh` | Compose rollback | Same script's own path | ✅ |
| `backup.sh` | `docker compose exec` mysqldump + env copy | `ethr:backup` | ✅ `BackupCommand.php` |
| `restore.sh` | Compose restore | `ethr:restore` | ✅ same file |
| `seed.sh` | `docker compose exec artisan db:seed` | Plesk Git deployment actions — **no shell on the account** | ⚠ route exists, never exercised |
| `init-storage.sh` | Creates the MinIO bucket | None needed — `FILESYSTEM_DISK=local` | ✅ |
| `prod-build-test.sh` | Builds the VPS production images and boots the stack | None — there is no production image on this target | ✅ n/a |
| `setup-replication.sh` | MariaDB primary→replica | None — `.env.shared-hosting.example:59` says **"Read replica — NOT APPLICABLE"**, and Bronze provides one database | ✅ |

**Two qualifications, because "a replacement exists" is weaker than it sounds.**

`ethr:backup` / `ethr:restore` exist and are rehearsed in CI against MariaDB, and have
**never been rehearsed on the Ethio Telecom host** — that is Stage 6 of the migration plan,
not a thing this classification closes. Deleting `backup.sh` before that rehearsal removes
the working path in favour of an untested one.

`seed.sh` is the weakest row. Its replacement is *"Plesk Git additional deployment actions"*,
which is a documented route rather than a measured one, on an account where **SSH is
Forbidden and there is no Scheduled Tasks section**. Treat this script as the last of the
eight to go.

### 3b.3 Code edits required in the same commit

Deletion alone turns gates red. Each of these must change in the removal commit:

| File | Edit |
|---|---|
| `api/tests/Feature/DeploymentWorkerConsistencyTest.php` | `ETHR_WORKER_ASSETS` names `supervisor.conf`, `docker-compose.yml`, `prod.yml`, `lowmem.yml` — three are being deleted. Two tests iterate it and **a third exists solely for `prod.yml` and `lowmem.yml`**. Reduce the constant to `docker-compose.yml` and delete that third test |
| `api/tests/Feature/document-root-inventory.php` | Reads `infrastructure/nginx.conf` |
| `api/tests/Feature/HostingRequirementsConsistencyTest.php` | Reads deployment assets — re-check its list before deleting |
| `api/tests/bootstrap.php` | Same |
| `src/src/middleware.ts` | Its docblock cites `infrastructure/nginx.conf` as *"the authoritative control"* for the `/admin` boundary. **That sentence stops being true when the file goes** — and on shared hosting the control is `.htaccess` group 0, which is `LOCAL VERIFIED` only |
| `api/app/Http/Controllers/Api/V1/Cron/CronRunController.php` | Cites `supervisor.conf` to explain what it replaces |
| `api/config/database.php` | Cites `prod.yml` for the replication split |
| `api/config/auth.php` | Cites `docker/nginx/default.conf` |
| `api/.env.production.example` | VPS-only — classify alongside |
| `api/.env.shared-hosting.example` | Cites `prod.yml` in a conversion note. **Stays**; the citation is what changes |
| `scripts/shared-hosting/deploy.sh` | **New-target script citing a VPS asset.** Stays; the reference goes |

### 3b.4 Order

1. Cutover verified — M1, M2, the timer, a restore rehearsal on the host.
2. Classify the eight `scripts/*.sh` and the two `.env.*.example` files.
3. One commit: delete the assets, apply every edit in §3b.3, update all citations.
4. `./scripts/gates.sh` — `docs` for link integrity, `backend` for the test edits.

**Do not split it.** A partial removal leaves the docs gate red and a test reading a file
that no longer exists.

### 3b.5 The thing to weigh before any of it

`docs/VPS_DEPLOYMENT.md` is *"out last, not first"* for a reason that has not changed: the
static export has **never served a request on the Ethio Telecom host**, `/admin`'s protection
rests on `[F,L]` which is **still unmeasured there**, and `AllowOverride Options` is a *new*
requirement this migration introduced (`audit/BASELINE.md` §21). Until those are answered on
the host, this list is the only way back.

## 4. Order of operations

1. **Confirm all three trigger clauses**, not just the cutover. The rehearsal is the one that
   makes this reversible-to-irreversible step safe.
2. ~~**Relocate `infrastructure/nginx-common.conf`'s content first**, or keep the file.~~
   **Done 2026-09-25 — nothing to relocate.** The shared-hosting files were not
   translations needing a source; they had already diverged, deliberately and in the safe
   direction. They now say so and stand alone. This step is closed, and the file moves from
   §2 to the removable set. Nothing else in the removable list has a dependency that must
   move ahead of it.
3. **Remove the §3 assets and update the documents that cite them in the same change.** 13
   documents cite `docker-compose.prod.yml` alone; a removal that leaves them pointing at
   nothing trades a stale asset for a stale document, which is the worse of the two because
   it is harder to notice.
4. **`docs/VPS_DEPLOYMENT.md` goes last.** It is the rollback path; it stops being one only
   when nothing else could need it.
5. **Run `./scripts/gates.sh docs`.** Link integrity is what catches a citation left dangling.

> **The VPS host itself is out of scope for this document.** Standing instruction: do not
> delete, destroy or shut down the VPS. This file is about files in this repository.
