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
php artisan ethr:backup --keep=14           # retention
php artisan ethr:restore <name>             # the half that makes it a backup
php artisan ethr:restore <name> --force     # non-interactive
```

Both run as plain PHP CLI, which is the entire design constraint: they must work from **Plesk → Scheduled Tasks** with no shell and no `mysqldump`.

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

Recorded as an open risk in `docs/audit/BASELINE.md` §13b; this is the half of it that can be fixed without the host.

---

## Restoring

```bash
php artisan ethr:restore ethr-20260915-010000
```

Destructive by definition — the dump begins with `DROP TABLE` for every table it contains. The command prints the manifest and asks for confirmation unless `--force`.

**It refuses a dump whose SHA-256 does not match its manifest.** Half-applying a corrupted dump is worse than refusing it: it restores *something*, and nobody can say what. This also catches truncation by a failed transfer, not just editing.

### After any restore, verify — do not assume

1. Triggers are back: `SHOW TRIGGERS` should list `audit_log_no_update` and `audit_log_no_delete`.
2. Sign in.
3. Read a payroll run.
4. **Write something that touches `audit_log`** — an employee edit will do. This is the step that catches the `DEFINER` problem, and nothing else will: the application looks healthy until the first write.
5. Download an employee document.

A silently missing trigger means `audit_log` has stopped being append-only. Every endpoint keeps working; the log just quietly stops being evidence.

---

## Scheduling

One entry, already registered in `routes/console.php`:

```
0 1 * * *   php artisan ethr:backup --keep=7
```

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

Six tests, and the destructive one verifies the database was genuinely empty before restoring — otherwise it would pass by accident.

**What that does not prove:** that a restore works on Ethio Telecom's MySQL. The suite runs on SQLite. Every hosting capability in `GATE-0-RESULT.md` is still `NOT VERIFIED`, including whether the database user may `CREATE TRIGGER` at all (G0-F). A green suite here is a necessary condition, not the rehearsal that closes the gate.

## Before the first real employee record

- [ ] Run `ethr:backup` on the host and read the output
- [ ] Configure the off-host disk and confirm `--off-host` lands an archive
- [ ] **Restore into a scratch database and complete all five verification steps above**
- [ ] Confirm `BACKUP_PATH` is not web-reachable — `curl` it and expect 403 or 404
- [ ] Time a backup against production-sized data; confirm it fits `max_execution_time`
- [ ] Record the result in `GATE-0-RESULT.md`

Until the third box is ticked, this is a backup procedure that has never been performed.
