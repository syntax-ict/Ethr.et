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
| POST | `/batches/{batch}/commit` | apply all actions; the answer also carries the two fields below |
| GET | `/batches/{batch}/logins` | `without_login` and `hire_dates_defaulted`, read again |

## After the commit: a way to sign in (2026-10-28)

A migration creates employees and no logins, and sends nothing. Names read off a terminal
are often wrong ("guest card", "test"), so HR looks first. The commit answer lists
`without_login`: each person from the batch with no login, or with one invited and not yet
activated, and the channel they would be invited by (`email`, `sms` while text invitations
are on, else `in_person`).

**Send invitations** posts every listed id to `POST /api/v1/employees/logins`
(`users.invite` and `employee.update`). `EmployeeLogins` gives each person a login as the
employee import does (the importer uses the same class), queues one batch of invitations,
and sends nothing inside the request. A person with no way to be reached gets no login
yet: HR issues an in-person code from their profile. Another organisation's employee id is
refused with the message an unknown id gets.

**Hire dates.** `createEmployee` sets today's date when the source has none, and a device
never has one. `hire_dates_defaulted` counts those people, and the screen asks HR to
correct the dates, because leave and severance are counted from them. Owner decision
(2026-10-28): warn, change no data.

**Outside onboarding.** The device enrollments dialog links to `/employees/from-device`,
which opens the same `MigrationStep` with that device chosen.

Attendance-history import runs through the existing device pull /
`AttendanceImporter` paths, which record at event time (see
[DEVICE_INTEGRATION.md](DEVICE_INTEGRATION.md)).

Matching details: [IDENTITY_RESOLUTION.md](IDENTITY_RESOLUTION.md).
