# Backup and Restore

**Status: built and round-trip tested locally. The rehearsal on the real host has not happened, so the go-live gate is not closed.**

ETHR holds employee records, bank details, salary history and scanned identity documents for multiple organizations. Master plan §30 sets the standard this document has to meet: *a backup that has never been restored is not considered verified.*

---

## What existed before, and why it was the largest risk in the system

`scripts/backup.sh` is:

```bash
docker compose exec -T mariadb mysqldump ...     # :53
docker run --rm --volumes-from ethr-minio ...    # :63
```

`restore.sh` and `rollback.sh` are the same shape. **None of it means anything on shared hosting** — no Docker, no `--volumes-from`, possibly no shell. `docs/MIGRATION_STATE.md` logged this as risk R10 ("would need rebuilding") and it was never scheduled.

`docs/audit/BASELINE.md` §15 ranked it the **single largest risk in the system, above the cross-tenant defect**. Not because it was more likely, but because it removes the floor: a product with a tenant leak has a bad day, and a product that cannot be restored has no recoverable failure mode at all.

There was also no restore *command* anywhere — only prose. Recovery procedures that exist only as prose get improvised during the one event they matter for.

## The commands

```bash
php artisan ethr:backup                     # database + employee documents
php artisan ethr:backup --off-host          # and copy an archive off the account
php artisan ethr:backup --keep=2            # retention; omit it and config backup.keep governs
php artisan ethr:restore <name>             # the half that makes it a backup
php artisan ethr:restore <name> --force     # non-interactive
```

Both run as plain PHP CLI with no shell and no `mysqldump` — that half of the constraint is
right and is why `DatabaseDumper` reads through PDO.

> **Corrected 2026-09-25 — this said they "must work from *Plesk → Scheduled Tasks*", and
> this file contradicts that 120 lines further down.** The *Route on this account* table
> states it plainly: there is **no Scheduled Tasks section** (G0-D FAIL), and `ethr:backup`
> reaches the host through `schedule:run` driven by `POST /api/v1/cron/schedule`. Naming
> Scheduled Tasks as the design constraint is also forbidden by the
> [hard rule](SHARED-HOSTING-CONTRACT.md) — it is a capability this plan does not have.
>
> **The real constraint is tighter than the old sentence, not looser.** The cron endpoints
> run under the **web SAPI**, not CLI, so a backup must complete within that SAPI's
> `max_execution_time` and `memory_limit` — values which `MIGRATION_STATE.md` records as
> *different from the CLI ones a probe would report*, and which are **unmeasured** (G0-E,
> G0-J). A `mysqldump`-free PDO dump is portable, which is the property that matters; it is
> also slower, which is the property that collides with a request timeout.
>
> The go-live checklist already carries the right action — *"Time a backup against
> production-sized data; confirm it fits `max_execution_time`"*. What it did not say is
> **which** `max_execution_time`: the web one, because under Plan B there is no CLI path in
> normal operation.

### Why not `mysqldump`

Shared hosting commonly disables `proc_open`, `exec` and `shell_exec`, and even where a shell exists `mysqldump` is often absent — it is absent from the development machine this was written on, which is how the constraint got noticed rather than assumed. `DatabaseDumper` reads through PDO like any other query. It is slower than `mysqldump` and does not try to compete; it is portable, which is the property the go-live gate needs.

### What is captured

| | |
|---|---|
| Schema | Every `CREATE TABLE`, from `SHOW CREATE TABLE` / `sqlite_master` |
| Data | Every row, chunked 500 at a time so memory stays flat on `attendance_records` |
| Triggers | Both `audit_log` triggers — **without a `DEFINER` clause**, see below |
| Documents | `storage/app/private`, where employee contracts and IDs live |
| Manifest | Counts, timestamp, and a SHA-256 of the dump |

Written with `umask(0077)`. Every artefact contains the whole dataset; default `0644` would leave salary history readable by any account on a shared host.

---

## The `DEFINER` problem, and why the backup path is where it gets fixed

`audit_log` is append-only, enforced by two database triggers. MySQL records a `DEFINER` with every trigger, defaulting to the user that created it.

Restore a conventional dump under a *different* database user — which Plesk's own backup tooling and phpMyAdmin routinely do — and the recorded definer no longer exists. At that point **every `INSERT` into `audit_log` fails**. `AuditLog::record()` is called at **206 sites**, inside the write transaction of nearly every mutating endpoint. The failure mode is not "audit logging is degraded"; it is **every create and update in the product returns 500**, immediately after a restore, which is exactly when you have least patience for a second problem.

`DatabaseDumper` emits `CREATE TRIGGER` with no `DEFINER` clause, so the triggers belong to whoever ran the restore. That is both what you want and the only option on a host where `SUPER` cannot be granted.

### Measured 2026-09-15 — and it is worse than the paragraph above

The description above was inference. Tested against MariaDB 10.4.32 with a restricted user holding `ALL PRIVILEGES` on its own database and no `SUPER` — the shared-hosting shape:

| Trigger as restored | Result |
|---|---|
| `CREATE DEFINER=\`ghost\`@\`localhost\` TRIGGER …` | **refused outright** — `1227 Access denied; you need (at least one of) the SUPER privilege(s)` |
| `CREATE TRIGGER …`, no DEFINER | created; `INSERT` succeeds, `UPDATE` rejected, definer becomes the restoring user |

So the damage does not arrive later as failing inserts. **The restore stops dead at the trigger statement**, because naming a definer other than yourself needs `SUPER`. And `SHOW CREATE TRIGGER` on this schema returns `CREATE DEFINER=\`root\`@\`localhost\` TRIGGER \`audit_log_no_update\` …` verbatim — confirmed, not assumed — which is exactly what `mysqldump` and Plesk's panel export write into a dump file.

The consequence for operations: **a Plesk-generated database backup of ETHR may be unrestorable on ETHR's own host.** Do not treat the panel's backup as the recovery path until someone has restored one on the host and watched both triggers come back. `ethr:backup` exists precisely so there is a path that does not have this property.

#### Scope of the `root@localhost` reading — added 2026-09-18

The 1227 mechanism above is general. **The specific definer is not**, and the distinction
changes which operation it bites.

`2026_07_22_000001_restrict_audit_log_to_insert_only.php` emits `CREATE TRIGGER` with **no
`DEFINER` clause** (lines 53 and 95), so the engine assigns whoever ran `migrate`. That is
the connection's `DB_USERNAME` — `ethr` under `docker-compose.yml`, and on Plesk a
restricted per-database user issued by the panel (`ENVIRONMENT.md:98`), never `root`. The
`root@localhost` reading above is therefore a true measurement **of the schema it was run
against**, not a property ETHR's migration produces everywhere.

What follows, and it sharpens rather than softens the warning:

- **Importing the VPS dump into shared hosting — the migration step itself — is where this
  definitely bites.** That dump carries the *VPS's* definer, which is by construction not
  the Plesk user restoring it. Different account, no `SUPER`, `1227`, restore stops at the
  trigger. This is `MIGRATION_STATE.md` B-5, and it is not conditional.
- **A Plesk backup of the shared host in steady state is conditional.** Once ETHR's own
  `migrate` has created the triggers as the Plesk database user, a panel dump names *that*
  user as definer and that same user is the one restoring — which is permitted. It fails
  only if the accounts differ, including the case where they differ **only in the host
  part** (`ethr@localhost` vs `ethr@%`), which is easy to hit and gives the identical
  `1227`.

So the operational instruction is unchanged — verify a restore before trusting the panel's
backup — but the reason to expect trouble is strongest at import, and a steady-state panel
backup is worth actually testing rather than written off.

Recorded in `docs/audit/BASELINE.md` §13b.

---

## Restoring

```bash
php artisan ethr:restore ethr-20260915-010000
```

Destructive by definition — the dump begins with `DROP TABLE` for every table it contains. The command prints the manifest and asks for confirmation unless `--force`.

**It refuses to write a dump whose INSERTs name a generated column.** Added 2026-09-23.
`SELECT *` returns generated columns like any other, and an INSERT that supplies a value
for one is not portable: SQLite rejects it outright, and MariaDB accepts it on the
connection this project configures but rejects it under a strict `sql_mode` — so
replayability had become a property of whoever runs the restore rather than of the backup.
`BackupService::create()` now scans the dump it has just written and throws rather than
finish, deleting the half-written directory first so nothing is left that looks like a
backup. If you meet that error the fault is in `DatabaseDumper`, not in your data;
`../audit/BASELINE.md` §15f and §15g have the full reading. Deliberately **not** checked at
restore time: an older backup taken before the fix may still restore on a permissive
engine, and refusing to try would turn a recoverable situation into an unrecoverable one.

**It refuses a dump whose SHA-256 does not match its manifest.** Half-applying a corrupted dump is worse than refusing it: it restores *something*, and nobody can say what. This also catches truncation by a failed transfer, not just editing.

### After any restore, verify — do not assume

1. Triggers are back: `SHOW TRIGGERS` should list `audit_log_no_update` and `audit_log_no_delete`.
2. Sign in.
3. Read a payroll run.
4. **Write something that touches `audit_log`** — an employee edit will do. This is the step that catches the `DEFINER` problem, and nothing else will: the application looks healthy until the first write.
5. Download an employee document.

A silently missing trigger means `audit_log` has stopped being append-only. Every endpoint keeps working; the log just quietly stops being evidence.

---

## On the Ethio Telecom target, backup has a runner and restore does not

Added 2026-09-23, because everything above assumes a command line and that account has
none. **The two halves of this document do not have the same status there**, and nothing
said so.

**Backup has a route.** `ethr:backup` is a `Schedule::command` entry
(`api/routes/console.php:107`), and `POST /api/v1/cron/schedule` runs `schedule:run`
(`CronRunController::schedule()`), so an external caller drives the nightly backup with no
shell and no cron. That is why `deployment/SHARED_HOSTING_PLAN.md` §5.1 grades blocker #21
*"RESOLVED — degraded"*.

**Restore does not, in the sense that matters.** `ethr:restore` is not scheduled and not
exposed; the cron controller runs exactly two commands, `schedule:run` and `queue:work`
(`CronRunController.php:48,66`). SSH is **Forbidden** and **G0-D is FAIL** — there is no
Scheduled Tasks section — so no ordinary route on that account runs it.

| | Route on this account | Ever executed there |
|---|---|---|
| `ethr:backup` | `schedule:run` via `POST /api/v1/cron/schedule` | No |
| `ethr:restore` | **None of the above.** See the deployment-action route below | No |

### There is one route, it is undocumented until now, and it is a footgun

Plesk Git *additional deployment actions* run shell commands as the subscription user on
deploy, and that is the only thing on this account that can run `artisan` at all
(`SHARED_HOSTING_PLAN.md` §5.3b). `ethr:restore <name> --force` is non-interactive, so it
would run there. Three things make that a last resort rather than a procedure:

1. **A deployment action fires on every deploy.** A restore line left in the field restores
   the database the next time anyone pushes. It has to be added, deployed once, and removed
   — and the removal is the step that gets forgotten.
2. **It is the same field the entire install depends on**, and §5.3b records that it has
   **never been executed**. Discovering it does not work while trying to recover is the
   worst possible time to find out.
3. **It restores from an archive that must already be on the host.** That covers a bad
   migration. It does not cover losing the account, which is the failure a backup exists
   for.

### The case with no route at all

**Host loss.** `--off-host` is deliberately absent from the scheduled line (see *Off-host
copies* below), so until a remote disk is configured every archive lives only on the 5 GB
account it protects. If that account goes, the backups go with it, and the question of how
to run `ethr:restore` never arises.

And a copy retrieved from somewhere else is not automatically readable: `Employee.tin` and
`national_id` use the `encrypted` cast, so **a dump without its `APP_KEY` cannot be read**
(`PRODUCTION_CHECKLIST.md` row 22).

### What would close this

Not a document — these are owner decisions, listed so they are not mistaken for writing
tasks:

- **Configure the off-host disk** and add `--off-host` to the scheduled line. This is the
  one that turns "backups exist" into "backups are a recovery path", and
  `SHARED_HOSTING_PLAN.md` Q5 (what outbound access the account has) gates it.
- **Decide whether restore gets a real runner** on that account, and if so which — there is
  no third option today beyond the deployment-action route above.
- **Rehearse a restore on the host.** `audit/BASELINE.md` item 1 still reads *"not rehearsed
  on the Ethio Telecom host"*, and this section is why that line has not moved.

---

## Scheduling

One entry, already registered in `routes/console.php`:

```
0 1 * * *   php artisan ethr:backup
```

**No `--keep` on that line, deliberately.** The command falls back to
`config('backup.keep')`, so `BACKUP_KEEP` sets retention per environment — **7**
on the VPS, **2** on shared hosting. Passing `--keep` here would override the env
var on every host, which is the defect this had until 2026-09-23: the command's
signature carried its own `--keep=7` default, so `config('backup.keep')` and
`BACKUP_KEEP` were read by nothing and lowering retention on a fixed quota did
nothing at all.

**What is retained is not an archive.** `create()` writes an *uncompressed*
directory — `database.sql` plus a full copy of every file in
`storage/app/private` — and `prune()` runs *after* it, so the peak is
`keep + 1` directories. Every employee document therefore exists **`keep + 2`**
times at peak: once live, once per retained copy, once in the copy being made.
The tar.gz only happens in `copyOffHost()`, which the scheduled run does not
call. On a fixed quota see
[`shared-hosting/DEPLOYMENT.md`](shared-hosting/DEPLOYMENT.md) → *What a full
disk actually looks like*.

01:00 UTC (04:00 EAT), deliberately **before** the 02:00 cleanup job, so a backup always exists from before data was pruned rather than after.

On shared hosting this arrives through a single Plesk Scheduled Task running the scheduler every minute:

```
* * * * *   cd ~/ethr/api && php artisan schedule:run
```

Whether Plesk offers "Run a command" at all, and at what minimum interval, is **gate G0-D** in `GATE-0-RESULT.md` and is still `NOT VERIFIED`. If only URL-fetch tasks are available, the scheduler has to move behind an authenticated HTTP endpoint — a real design change that is not built.

`--off-host` is deliberately absent from the scheduled line. Off-host credentials may not exist yet, and a scheduled task that fails every night is a scheduled task people mute. Add it once the disk is configured; until then the command warns on **every run** that the backup exists only on the host it protects.

---

## Off-host copies

A backup on the same 5 GB account it is protecting is a convenience, not a backup — it survives an application fault and nothing else, and it competes with the data for space.

`--off-host` archives the directory and pushes it to the configured disk (default `s3`). No new dependency: `league/flysystem-aws-s3-v3` is already installed, so any S3-compatible endpoint works.

**This is optional in the code and not optional before go-live.**

---

## What is tested, and what that does not cover

`api/tests/Feature/BackupRestoreRehearsalTest.php` performs the §31 sequence for real: populate representative data, back up, **drop every table**, restore, and assert the schema, rows, triggers and documents came back. It also asserts a tampered dump is refused and that retention prunes correctly.

Seven tests, and the destructive one verifies the database was genuinely empty before restoring — otherwise it would pass by accident.

### The rehearsal command

```bash
php artisan ethr:backup:rehearse          # back up, DROP EVERY TABLE, restore, check
php artisan ethr:backup:rehearse --keep   # leave the backup behind to inspect
```

A test proves the round-trip on the driver the test suite uses. This proves it on
whatever database it is pointed at, which is the only way to answer the question
for a host nobody has run this code on. It drops every table, so two guards stand
in front of it: the environment must not be `production`, and the database name
must contain one of `test`, `rehears`, `scratch`, `staging`, `sandbox`. `--force`
overrides both, and should be needed about never.

**A failed rehearsal leaves the database destroyed.** That is not a defect — it is
what "destructively verify" means — but it is why the name guard exists and why
this is run against a scratch database, before the first real record.

**First real-MySQL run, 2026-09-15, MariaDB 10.4.32 (local XAMPP), demo tenant —
150 employees, 6 months attendance, 3 months payroll:**

| | |
|---|---|
| Tables / rows / triggers before | 80 / 18,688 / 2 |
| Dump size | 11,296,908 bytes |
| `CREATE TRIGGER` in dump | 2 |
| `DEFINER` clauses in dump | none |
| Statements executed on restore | 18,855 |
| Tables / rows / triggers after | 80 / 18,688 / 2 |
| `audit_log` INSERT after restore | ok |
| `audit_log` UPDATE after restore | rejected |

That is the first time `DatabaseDumper::dumpMysql()` has ever executed — `SHOW
CREATE TABLE`, `SHOW TRIGGERS` and the DEFINER omission were, until this run,
code that had never run anywhere.

**And it stayed a one-off for a week.** `BackupRestoreRehearsalTest` skips itself on
MySQL — correctly, since it drops every table and DDL implicitly commits there — and
`MIGRATION_STATE.md` recorded on 2026-09-18 that the named MySQL equivalent, this
command, was *"invoked nowhere. Not by `scripts/gates.sh`, not by either CI workflow, not
by any test."* So the largest risk `audit/BASELINE.md` §15 names was covered on SQLite and
nowhere else.

**It now runs on every push and pull request** — the `Backup restore rehearsal on MariaDB`
job in `.github/workflows/gates.yml`, against its own disposable `ethr_rehearsal` database,
as the non-root `ethr` user.

**CI run, 2026-09-23, MariaDB 10.11.19-ubu2204, reference seeders only:**

| | |
|---|---|
| Tables / rows / triggers before | 81 / 19,067 / 2 |
| Dump size | 11,372,238 bytes |
| `CREATE TRIGGER` in dump | 2 |
| `DEFINER` clauses in dump | **none** |
| Tables remaining after the drop | 0 |
| Statements executed on restore | 19,236 |
| Tables / rows / triggers after | 81 / 19,067 / 2 |
| `audit_log` INSERT after restore | ok |
| `audit_log` UPDATE after restore | rejected |

Two things this recurring run adds over the 2026-09-15 one. It is on **10.11**, the
documented target, rather than a local XAMPP 10.4.32. And the `DEFINER clauses: none`
line is now asserted continuously on a driver where `DEFINER` exists at all — the SQLite
rehearsal reads `sqlite_master WHERE type='trigger'`, and SQLite triggers have no `DEFINER`
concept, so the one check that looks like it covers the B-6 hazard is on the one driver
where that hazard cannot occur.

**One half it does not exercise: `files restored` is 0.** The reference seeders create no
uploads, so the document side of the round-trip is covered by the SQLite test and not by
this job. Seeding a tenant with documents would close that, and would cost CI time; it is
recorded here rather than left to be discovered from a zero in a table.

**What none of this proves:** that a restore works on Ethio Telecom's MySQL. The CI run is
MariaDB 10.11 in a container and the 2026-09-15 run was a local XAMPP 10.4.32; the host's
own version is unread (G0-E), and whether the production user may `CREATE TRIGGER`
at all is still `NOT VERIFIED` (G0-F). Every hosting capability in
`GATE-0-RESULT.md` remains unmeasured. A green rehearsal here is a necessary
condition, not the one that closes the gate.

## Before the first real employee record

- [ ] Run `ethr:backup` on the host and read the output
- [ ] Configure the off-host disk and confirm `--off-host` lands an archive
- [ ] **Run `php artisan ethr:backup:rehearse` against a scratch database on the host** and complete all five verification steps above
- [ ] Confirm `BACKUP_PATH` is not web-reachable — `curl` it and expect 403 or 404
- [ ] Time a backup against production-sized data; confirm it fits `max_execution_time`
- [ ] Record the result in `GATE-0-RESULT.md`

Until the third box is ticked, this is a backup procedure that has never been performed.
