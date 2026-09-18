# Workforce Migration

> **Not the hosting migration.** This document is the *product feature* that imports an
> existing workforce from a device, spreadsheet or manual entry. It has nothing to do with
> the VPS → Ethio Telecom shared-hosting move.
>
> The name collides, and `docs/CLAUDE.md`'s session protocol lists `MIGRATION.md` among
> the files to read "for … migration … work", which sends a reader here for the wrong
> reason. For the hosting migration, read `MIGRATION_STATE.md` (live register),
> `deployment/GATE-0-RESULT.md` (gates and blockers) and
> `deployment/shared-hosting/DEPLOYMENT.md` (the runbook).

The migration workspace discovers an existing workforce (from a device, a
spreadsheet, or manual entry), stages each person with an identity-resolution
verdict for human review, and commits the reviewer's decisions. Nothing about a
person is created until commit, and a known person is never duplicated.

See ONBOARDING_V2.md decision D6.

## Model

- `migration_batches` — one discovery run (`source_type`, `status`, `totals`).
- `migration_staging_rows` — one discovered person: the `raw` source signals, the
  resolver verdict (`match_outcome`, `match_confidence`, `candidates`,
  `resolved_employee_id`), and the chosen `action`.

Both are transient staging and are **hard-deleted after 7 days** by
`CleanupExpiredDataJob` (CLAUDE.md soft-delete policy).

## Service — `App\Services\Migration\WorkforceMigrationService`

- `stageFromDevice(Device)` — pulls enrollments, resolves each, stages rows.
- `stageFromRows(rows, tenantId, sourceType)` — CSV/spreadsheet/manual.
- `setAction(row, action)` — reviewer override.
- `commit(batch)` — applies actions; idempotent once committed.

### Suggested action from the verdict

| verdict | default action |
|---|---|
| `matched` | `merge` (link identity, no new employee) |
| `new` | `create` |
| `probable` / `ambiguous` | `defer` (needs a human) |

`commit` honours the final action: `create` makes an employee and links its
external identity (verified); `merge` links to the resolved employee only;
`skip`/`defer` do nothing.

## Endpoints (`/api/v1/onboarding/migration`)

| method | path | purpose |
|---|---|---|
| POST | `/devices/{device}` | stage everyone enrolled on a device |
| POST | `/rows` | stage rows from a spreadsheet/manual entry |
| GET | `/batches/{batch}` | review queue with verdicts and summary |
| PATCH | `/rows/{row}` | set a row's action |
| POST | `/batches/{batch}/commit` | apply all actions |

Attendance-history import runs through the existing device pull /
`AttendanceImporter` paths, which record at event time (see
[DEVICE_INTEGRATION.md](DEVICE_INTEGRATION.md)).

Matching details: [IDENTITY_RESOLUTION.md](IDENTITY_RESOLUTION.md).
