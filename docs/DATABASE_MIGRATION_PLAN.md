# Database Migration Plan — VPS MariaDB → Shared Hosting MySQL

Referenced by `docs/deployment/shared-hosting/DEPLOYMENT.md` step 2. This assumes there is
**existing data to migrate** — if the shared-hosting deployment is instead a fresh
start with `ProductionSeeder` and no prior tenants, skip straight to "Fresh deployment"
at the end.

## Compatibility, already verified

`docs/SHARED_HOSTING_AUDIT.md` §B covers the schema-level risk in full; summarized here
because it's what this plan depends on:

| Feature | Migrations using it | Risk |
| --- | --- | --- |
| `utf8mb4` charset | all, via `config/database.php` | Low — standard on any MySQL ≥ 5.5, confirm with the hosting-check probe (B4) regardless, since Amharic depends on it |
| `json()` columns | 32 migrations | Low — MySQL ≥ 5.7 / MariaDB ≥ 10.2, effectively universal now |
| InnoDB FULLTEXT (employee search) | 1 | Low |
| Foreign keys | 32 | Low — InnoDB default |
| `CREATE TRIGGER` (audit-log immutability) | 1 | **The real risk — see H1 below** |

**MariaDB → MySQL is not assumed identical without reason:** `config/database.php`
declares a separate `mariadb` connection (with the read-replica split) distinct from
`mysql`. If the shared-hosting database server identifies as MySQL rather than MariaDB,
`DB_CONNECTION` must say so — set from whatever the hosting-check probe's
`SELECT VERSION()` actually reports, not copied from the VPS `.env` unchanged.

## H1 — the one gate that can block the whole migration

`2026_07_22_000001_restrict_audit_log_to_insert_only.php` requires the `TRIGGER`
privilege (and, if binary logging is on, `SUPER` or
`log_bin_trust_function_creators=1`). As of the fail-fast fix (`docs/MIGRATION_STATE.md`
D8), this migration **aborts the entire run** if denied — deliberately, per
`docs/AUDIT_LOG_INTEGRITY_DECISION.md`, rather than silently shipping an audit log that
can be tampered with. Confirm this before doing anything else in this plan; the
hosting-check probe script answers it in one pass.

## Backup — before touching anything on the source side

The VPS's own `scripts/backup.sh` is the reference for what "backed up" means for this
application; replicate its two halves manually since this is a one-time cross-host
migration, not the recurring job that script is built for:

```bash
# Database — --single-transaction avoids locking a live table; --routines
# --triggers is not optional here, it's what captures the audit_log guards
# themselves so a restore recreates them rather than silently omitting them.
mysqldump -u"$USER" -p"$PASS" --single-transaction --routines --triggers "$DB" \
  | gzip > ethr_pre_migration_$(date +%Y%m%d).sql.gz

# Storage — VPS backup.sh backs up MinIO's Docker volume directly; the
# equivalent here is whatever the VPS's `local`/`minio` disk resolves to on
# disk. Confirm the exact path from the VPS's own FILESYSTEM_DISK setting
# before assuming a location.
tar czf ethr_storage_pre_migration_$(date +%Y%m%d).tar.gz /path/to/vps/storage
```

Verify both archives are non-corrupt (`gzip -t`, `tar tzf`) before proceeding — the
existing `restore.sh` already does this check on restore; do it on backup too, so a
corrupt archive is caught before it's the only copy.

## Schema transfer

```bash
mysql -h localhost -u"$SHARED_HOST_USER" -p"$SHARED_HOST_PASS" "$SHARED_HOST_DB" \
  < ethr_pre_migration_YYYYMMDD.sql
```

This restores the schema **and** the data in one step, which means the trigger-creation
statements captured by `--routines --triggers` run as part of the restore — if H1 turns
out negative, this `mysql` import itself will surface the same `CREATE TRIGGER` failure
the fresh-migration path would, just wrapped in a `.sql` file's error output instead of
`php artisan migrate`'s. Same block, same resolution (grant the privilege, or accept the
gap explicitly — see `AUDIT_LOG_INTEGRITY_DECISION.md`).

## Storage transfer

Extract the storage archive to wherever `FILESYSTEM_DISK=local` resolves on the new
host (`storage/app/private` and `storage/app/public`, per `config/filesystems.php`) —
or, if repointing to an external S3-compatible service instead (see `ENVIRONMENT.md`
"Storage sizing"), upload the archive's contents to that bucket with `aws s3 sync` or
the provider's equivalent, preserving the `tenant-{public_id}/...` path structure
`FileStorageService::tenantPrefix()` expects. Either way, spot-check that a handful of
signed URLs for pre-existing files still resolve after the move — the presigning logic
depends on the disk driver matching what actually holds the bytes, not on the path
string alone.

## Verification

```sql
-- Row counts match the source dump, table by table. A mismatch here is the
-- single most reliable "did this actually work" signal — more useful than
-- "the import command exited 0", which a partial import can also do.
SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = ?;
```

Then the application-level checks: `docs/deployment/shared-hosting/deploy-checklist.md`'s
"Database" section, plus logging in as an existing (migrated) user to confirm
authentication survived the move — password hashes are portable (bcrypt is
implementation-independent), but this is worth confirming rather than assumed.

## Rollback of this step specifically

If the import fails partway or verification fails: `DROP DATABASE` on the shared-hosting
side and re-import from the same backup — the source VPS database is untouched by
anything in this plan (a `mysqldump`, never a move). This is a much smaller-scoped
rollback than `docs/ROLLBACK_RUNBOOK.md`'s DNS-level one, and doesn't require it unless
the shared-hosting deployment has already gone live and accepted new writes.

## Fresh deployment (no data to migrate)

If there is no existing tenant data — e.g., this shared-hosting deployment is the first
production instance of ETHR rather than a migration of a live one — skip everything
above. `docs/deployment/shared-hosting/DEPLOYMENT.md` step 4 (`migrate --force`,
`db:seed --class=ProductionSeeder`) is the entire database setup; there is nothing to
back up or transfer.
