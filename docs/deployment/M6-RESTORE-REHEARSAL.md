# M6 — the restore rehearsal: a hard production blocker

**Status: `HOST ACTION REQUIRED`. NOT PERFORMED.**
**No rehearsal has ever been run on the Ethio Telecom host. Do not cut over until one has.**

---

## 1. Why this is blocking rather than confirmatory

It used to be belt and braces. It is not any more, and the reason is a decision that
has already been taken:

**`3db9904` removed the VPS production assets before a verified cutover**, at the
owner's explicit direction. `docs/VPS_DEPLOYMENT.md` went with them. So there is no
repository-level rollback path, and **`ethr:restore` is the only recovery capability
this deployment will have.** A recovery capability that has never been exercised on the
host it must run on is a claim, not a capability.

Three further facts make this specific rather than cautious:

- **The backup path has already shipped a defect while every test of it was green.**
  `docs/audit/BASELINE.md` §15f/§15g: a dump was replayable only on a permissive
  engine, CI was green throughout, and *the first written account of the defect was
  itself wrong in two places.*
- **CI rehearses on MariaDB, in a container, against a schema a test built.** Production
  is a Plesk-managed MySQL-compatible server, on a host nobody has run this code on,
  with a schema built by `migrate`. `BackupRehearsalCommand`'s own docblock says the two
  *"differ in ways that have already bitten this project once."*
- **The `DEFINER` hazard fails in a way a smoke test does not see.** The audit-log
  triggers store `DEFINER = CURRENT_USER`; a panel-driven restore commonly recreates the
  database under a different user, and when the definer no longer exists **every
  `INSERT` into `audit_log` fails** — 206 call sites inside mutation transactions. The
  application boots, pages load, and every create or update returns 500. See
  [`G0-F-CREATE-TRIGGER.md`](G0-F-CREATE-TRIGGER.md) §7 and `BASELINE.md` §13b.

**The tool already exists.** `php artisan ethr:backup:rehearse` backs up, **drops every
table**, restores, and compares. It is not new and this document does not replace it —
it is the host procedure around it.

---

## 2. Safety — read before running anything

`ethr:backup:rehearse` **destroys the database it is pointed at.** That is its method,
not a side effect. Two guards stand in front of it and `--force` is required to pass
either:

| Guard | Passes when |
|---|---|
| Environment | `APP_ENV` is **not** `production` |
| Database name | contains one of `test`, `rehears`, `scratch`, `staging`, `sandbox` |

**Rehearse into a scratch database, never the live one.** Bronze provides **one**
database, which is the practical constraint here: either create a second database if the
plan permits it, or run the rehearsal **before the first real employee record exists** —
which is item 3 of [`BACKUP-RESTORE.md`](BACKUP-RESTORE.md)'s checklist and the window
this deployment is currently in.

> **Do not run this against the production database with `--force` to "save time".**
> There is no rollback path. That is the whole reason this gate exists.

---

## 3. The procedure — nine steps, and step 9 is not optional

### 1 · Produce the exact deployable artifact

Build it the way a real deploy does, so the thing rehearsed is the thing shipped:

```bash
scripts/shared-hosting/deploy.sh --build-only
```

That runs `composer install --no-dev`, builds the static export via
`npm run build:shared-hosting`, and packages `.deploy-artifacts/{api,frontend}.tar.gz`.

### 2 · Verify the artifact carries no secrets

`deploy.sh` now asserts this itself and **refuses to continue** if it fails — but check
the output, because the whole point of step 2 is not taking it on trust:

```
verified: api.tar.gz carries no .env
```

Confirm by hand if you prefer:

```bash
tar -tzf .deploy-artifacts/api.tar.gz | grep -E '(^|/)\.env' && echo LEAK || echo clean
```

**Expect `clean`.** Until 2026-09-27 this archive contained `api/.env` — APP_KEY, the
database password and the mail credentials — on every machine that can run the gates.

### 3 · Take the backup on the host

```bash
php artisan ethr:backup
```

Note the path it reports and the size. On Bronze, remember `BACKUP_KEEP=2` and that
`prune()` runs after `create()`, so at peak **four** copies of the document store exist
against a **5 GB** quota shared with the database and every upload
([`BACKUP-RESTORE.md`](BACKUP-RESTORE.md) carries the arithmetic).

### 4 · Restore into a safe target

```bash
php artisan ethr:backup:rehearse
```

Point it at the scratch database from §2. It will refuse if the name does not read as
disposable, which is the guard working — **fix the target, not the guard.**

Add `--keep` to leave the backup directory behind for inspection.

### 5 · Verify database integrity

The command does this and prints it: a census **before and after** — table count, row
count and **trigger count** — plus a comparison. Record the numbers.

Then confirm the triggers by name, because a count can match while the wrong ones exist:

```sql
SHOW TRIGGERS LIKE 'audit_log';   -- expect audit_log_no_update AND audit_log_no_delete
```

### 6 · Verify the application boots

On the host, against the restored database:

```bash
php artisan about
php artisan migrate:status     # every migration should read "Ran"
```

`migrate:status` matters: a restore that produced a schema the migration table
disagrees with will fail the *next* deployment, not this one.

### 7 · Verify it can actually write — the `DEFINER` check

**This is the step that catches the failure mode step 6 cannot see.**
`ethr:backup:rehearse` already runs `checkAuditLogStillAppendOnly()`, which inserts into
`audit_log` after the restore; a failure there is reported as

```
audit_log INSERT failed after restore: …
```

and that string **is** the DEFINER failure's signature. Read the command's output for
it rather than assuming.

Then do one real write **through the application**, not through SQL — create an employee,
or change a setting — because that is the path with `AuditLog::record()` inside its
transaction. A page that loads proves nothing about a page that saves.

### 8 · Verify the served paths

With the restored database and the deployed artifact:

```bash
scripts/shared-hosting/smoke-check.sh https://<APP_DOMAIN>
```

Then, by hand, at least one of each shape — these are the four the `.htaccess` rules
distinguish, and BASELINE §21d/§22b measured all of them locally:

| Path | Expect |
|---|---|
| `/api/v1/health` | 200, JSON, from PHP |
| `/dashboard` | 200, the exported HTML |
| `/employees/<a real ULID>` | 200, byte-identical to `employees/__id__.html` |
| `/employees/new` | 200, **the "Add employee" page — not the detail shell.** This is the §22b defect; six routes were wrong |
| `/nope` | a real **404**, not 200 |
| `/.env` | **403** |

### 9 · Record the evidence

In [`GATE-0-RESULT.md`](GATE-0-RESULT.md) → *Gate status reconciliation*, and in
`MIGRATION_STATE.md`'s manual queue against **M6**:

- the date, the host, the server version from the census
- before/after table, row and trigger counts
- the `audit_log` INSERT result **verbatim**
- which served paths were checked and what each returned
- anything that differed from CI's MariaDB rehearsal

**Only then does M6 become `PASS`.**

---

## 4. What does not close M6

Stated because each of these has been offered as "good enough" for a rehearsal
somewhere in this repository's history:

| Not sufficient | Why |
|---|---|
| A backup file exists | *"A backup that has never been restored is not considered verified"* — master plan §30, quoted in the command's own docblock |
| CI's `backup-rehearsal-mysql` job is green | Different engine instance, different schema origin, different host. It is a regression guard, not host evidence |
| `BackupRestoreRehearsalTest` passes | SQLite, in memory |
| The dump was inspected and looks right | §15f's defect was *in a dump that looked right* |
| The application boots after restore | The DEFINER failure boots fine and fails on write — step 7 exists for exactly this |
| A rehearsal on a developer machine | The risk is the host's MySQL and the host's restore mechanism |

---

## 5. Disposition of `scripts/backup.sh` and `scripts/restore.sh`

**They stay until this rehearsal passes, and then they go.**

Both are currently **retired** — they `exit 64` before touching anything, because they
drove `docker-compose.prod.yml`, which `3db9904` removed. They were held on the grounds
that deleting them would "remove a working path in favour of an untested one"; the
2026-09-27 audit measured that and it was false, so what is preserved now is the record
of the VPS backup, not a capability.

When M6 passes, remove both, with these citation edits in the same commit:
`BackupCommand.php:16`, `BackupService.php:21`, `BackupRestoreRehearsalTest.php:27`,
`api/.env.example`, `api/.env.shared-hosting.example`.

`ScriptComposeFileReferencesTest` asserts they refuse-before-acting while they remain, so
they cannot quietly become half-working in the meantime.

---

## Related

- `api/app/Console/Commands/BackupRehearsalCommand.php` — the tool, and its safety guards
- [`BACKUP-RESTORE.md`](BACKUP-RESTORE.md) — retention arithmetic and the checklist this is item 3 of
- [`G0-F-CREATE-TRIGGER.md`](G0-F-CREATE-TRIGGER.md) §7 — the `DEFINER` hazard in full
- [`../audit/BASELINE.md`](../audit/BASELINE.md) §15f, §15g, §13b — the measured defects
- [`../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md) — Stage 6
- [`GATE-0-RESULT.md`](GATE-0-RESULT.md) → *Gate status reconciliation*
