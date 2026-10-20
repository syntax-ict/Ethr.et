# Organisation data export

*Added 2026-10-20, blueprint assessment items A12 and P12.*

An organisation's administrator can download a copy of everything the organisation
holds in ETHR, from **Settings → Data export**. Until this existed, only a platform
operator could produce a backup, as a JSON file that left out every uploaded
document and that the customer had no way to download.

## Who can use it

Anyone holding `settings.manage` (the organisation's administrators). The archive
holds every employee's record, so it sits with the rest of the organisation's
settings rather than with HR's permissions. Each request and each download is
written to the audit log (`settings.data_export_requested`,
`settings.data_export_downloaded`).

## What happens

1. `POST /settings/data-export` creates a `tenant_exports` row (`queued`) and
   dispatches `ExportTenantDataJob` on the `exports` queue. It is refused with
   **409** while another export is queued or running, and with **429** after
   three requests in 24 hours.
2. The job runs when the queue is next drained (the cron caller, every few
   minutes). It sets the organisation as the current tenant and reads through
   ordinary tenant-scoped queries, so it adds no tenant-scope bypass.
3. The archive is written to `exports/{tenant public_id}/{export public_id}.zip`
   on the default disk, and the requester is notified (in the app and by email).
4. The archive is kept for **7 days** (`TenantExport::EXPIRES_DAYS`).
   `CleanupExpiredDataJob` then deletes the file and marks the row `expired`.
   The row stays so the page and the audit trail can still say it existed.

An export left `queued` or `running` for more than three hours is taken to have
died with its worker and is marked `failed`, so it does not block the next request.

## What the archive contains

| Path | Content |
|---|---|
| `data/<table>.jsonl` | One JSON object per line for every row of every table whose model uses `BelongsToTenant`, deleted rows included. Internal ids are kept so rows can be joined. A table added later is exported without a code change |
| `files/documents/` | The employee documents uploaded to ETHR, each named after its row's `public_id` |
| `README.txt` | What the files are, what is left out, and the row count per table |

**Left out on purpose:**

- passwords, two-factor secrets, sign-in codes and tokens, keys and their hashes
  (`ExportTenantDataJob::SECRET_COLUMNS`, applied to every table whether or not the
  model hides the column);
- every attribute with an `encrypted` cast: TIN, national ID, bank account
  numbers, integration secrets;
- attendance photos, which are biometric data kept only for their review window
  ([ATTENDANCE-PHOTO-RETENTION.md](ATTENDANCE-PHOTO-RETENTION.md));
- five tables of sign-in state or import scratch rows: kiosk sessions, OTP codes,
  trusted devices, migration batches and their staging rows.

## Host limits it is built for

The host runs PHP with 128 MB and a 30-second limit, and drains the queue inside
a web request ([../../CLAUDE.md](../../CLAUDE.md), section 3). So the job:

- lifts the time limit for itself (`set_time_limit(0)`);
- writes each table to a file in chunks of 500 rows, never holding a table in
  memory;
- adds documents to the archive by path, so no file is read into memory;
- packs a ZIP where the `zip` extension exists and a `.tar.gz` where only `phar`
  does. The host is guaranteed one of the two
  ([../deployment/GATE-0-RESULT.md](../deployment/GATE-0-RESULT.md), G0-E).

The archive counts against the hosting account's storage quota for the seven days
it is kept, which is why it is deleted rather than kept indefinitely.

## Offboarding

An organisation leaving ETHR should download an export **before** it is deleted
([TENANT-DELETION.md](TENANT-DELETION.md)). Deletion keeps every record, so an
export can still be produced by restoring the organisation, but that needs a
platform operator.
