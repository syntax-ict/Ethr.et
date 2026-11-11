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

- `migration_batches` — one discovery run (`source_type`, `status`, `totals`,
  and `commit_cursor`, the last row a commit has dealt with). `status` is
  `reviewing`, then `committing` while a long list is being added, then
  `committed`.
- `migration_staging_rows` — one discovered person: the `raw` source signals, the
  resolver verdict (`match_outcome`, `match_confidence`, `candidates`,
  `resolved_employee_id`), the chosen `action`, and `changes`: what the row
  would write to the employee it matched.

`raw` and `changes` are **stored encrypted** (`App\Casts\EncryptedJson`,
2026-11-11). They are a person's details as a file or a device gave them, kept
for as long as the list is, and they sat in a JSON column in clear text. A row
staged before that still reads; it is rewritten encrypted the next time it is
saved, and there is no backfill.

Both are transient staging and are **hard-deleted 30 days after their last
activity** by `CleanupExpiredDataJob` (CLAUDE.md soft-delete policy;
`MigrationBatch::RETENTION_DAYS`). Activity is a change to the batch (staging,
committing) or to any of its rows (choosing an action), so a list someone is
still working through is never the one deleted. Until 2026-11-10 the rule was
seven days from the day of staging, whatever the batch's state: a list of a few
hundred people lost every decision a week after it was pasted, and a committed
batch lost its "who still cannot sign in" view on the same day.

The trade is that staged rows, which hold names and contact details, now stay
longer when a batch is abandoned: up to thirty days rather than seven.

## Service — `App\Services\Migration\WorkforceMigrationService`

- `stageFromDevice(Device)` — pulls enrollments, resolves each, stages rows.
- `stageFromRows(rows, tenantId, sourceType)` — CSV/spreadsheet/manual. Rows
  are keyed as `EmployeeImportRow::staged()` names them.
- `setAction(row, action)` — reviewer override.
- `commit(batch)` — applies actions; idempotent once committed.

### One reader for every list (2026-11-11)

A list of people was read in two places that disagreed. The guided setup's
paste box read its list in the browser, with its own table of column names and
its own date rule; the file import read its file on the server with
`App\Support\EmployeeImportRow`. "Badge" was an employee number in one and a
badge in the other, Amharic column names were read in one and dropped in the
other, `15/03/2024` was refused in one and read in the other, and a pasted list
could carry six fields.

**`EmployeeImportRow` is now the only place a column name is given a meaning**,
and `/employees/import`, the guided setup and a device's roster are one screen
(`MigrationStep`).

- The browser splits text into cells (`parse-people.ts`, `toGrid`) and nothing
  else. It sends `grid`: lines of cells, an empty line kept in its place so a
  line number is the one the reader counts.
- `App\Support\EmployeeImportGrid` reads the grid. A first line is column
  names when one of its cells names the name column and none looks like a
  person's details; then the columns may be in any order. Otherwise they are
  read in `EmployeeImportRow::DEFAULT_ORDER`. Only the name is required.
- A column name that means nothing is **reported** (`ignored`), not dropped
  without a word: a "Salary (birr)" column used to vanish.
- Each line that cannot be staged is named with its number and left out:
  a rule it breaks, a department, branch or position the organisation does not
  have, an employee number, email, badge or other-system id an earlier line
  already used.
- **Dates.** Year first (`2019-07-08`, `2019/7/8`) or day first (`08/07/2019`),
  in the calendar chosen, unless the column name says (`Hire date (E.C.)`) or
  the date itself does (`2011-10-30 E.C.`). The paste box used to refuse a
  day-first date; the file import has read one since 2026-10-15, and that is
  now the rule for both. A date that does not exist in its calendar is refused.
- **A hire date may be missing** from a staged list: the day the list is added
  is used, and the screen says how many people that is, before and after.

Two columns are new:

| column | takes | does |
|---|---|---|
| `status` | `hired`, `probation`, `confirmed` ("Active" and "Permanent" are confirmed) | the status a **new** person arrives with. Confirmed sets `confirmation_date` to the day they were added: the real day is not in the list. It does not change an existing employee |
| `external_id` | the person's id in the system the list came from | kept as an identity (`source_type` `hr_system`, `identifier_type` `external_id`), so a later list finds the same person by it whatever else about them changed |

Until a list can name its source (column mapping, later in the plan), every
`external_id` shares one source, `default`. Two other systems that both number
their people from 1 would collide; one system does not.

A staged "create" makes the person from everything the line brings: Amharic
name, gender, birth date, branch, position, salary, national id, TIN, badge.
**Staging a list that sets a salary or a TIN needs `employee.updateFinancial`**,
as the file import and the add-employee form do.

The older `POST /employees/import/preview` and `/commit` are still served, for
a caller that sends a file. They read the same column names and honour both new
columns. They have no review: a person already in ETHR is counted and nothing
the row brings is kept. The screen no longer uses them.

### A workbook is opened in the browser (2026-11-11)

The employee template is an Excel workbook, and an `.xlsx` file can be chosen
wherever a CSV can. **Neither touches the server as a file.** The library that
writes dropdowns on the server needs a newer PHP than the host runs, and the
host's zip and XML extensions are unconfirmed, so the browser does the work
(ExcelJS, loaded only when a workbook is chosen or the template downloaded;
`features/onboarding/v2/xlsx-grid.ts`) and sends the same `grid` of cells a
pasted list is. What a column means is still decided by `EmployeeImportRow`.

**Reading.** The sheet named `Employees` is read, or the first visible sheet
that is not the template's own `Instructions`. Each cell becomes the text a
person sees:

| cell | becomes |
|---|---|
| text | itself, trimmed |
| number | as typed: `1001`, `12500.5` |
| number of 10^15 or more | `1.23e+15`. The spreadsheet has already rounded it (a 16-digit national id keeps fifteen digits), nothing can restore the lost ones, and written like this the server refuses it with what to do |
| date | `2019-07-08 G.C.`. A date cell is Gregorian whatever calendar the list is in, and says so, so a list of Ethiopian dates typed as text can sit beside it |
| formula | its result |
| rich text, link | its text |

An empty row keeps its place, so a line number the server reports is the row
number in Excel. The cells go into the list box as text, to be seen and
corrected like a pasted list. A sheet of more than 5,000 rows and an old `.xls`
file are refused with what to do instead.

**What a spreadsheet does to a value, and the server undoes or refuses**
(`ExcelCoercionTest`):

- a phone that lost its leading zero (`911223344`) is the same number;
- `1001.0` in an identifier column is `1001`;
- a bare five-digit number in a date column is Excel's day count (`43654` is
  8 July 2019), read as Gregorian;
- `1.23457E+15` in an employee number, phone, national id, TIN, badge or
  other-system id is refused: "format the column as text and type the whole
  number".

**The template.** `POST /employees/import/template` now also answers what a
workbook needs: `lists` (what each choice column may hold: the fixed values,
and this organisation's departments, branches and positions by name, by code
where two share a name, since a shared name is refused as ambiguous),
`text_columns`, `date_columns` and `required`. The browser builds:

- `Employees`: the header row, each date column naming the calendar chosen;
  identifier and date columns formatted as text; a dropdown on each choice
  column for 2,000 rows. A dropdown **warns and lets a typed value through**:
  the import reads more spellings than the list offers (a code for a name, M
  for male).
- `Instructions`: how to fill it in, and what each column takes, in the
  reader's language.
- `Lists`: hidden, the dropdowns' values. A value with a comma in it would
  break a list typed into the rule itself.

Dates are text on purpose. An Ethiopian date is not a date Excel can hold, and
a Gregorian one typed into a date cell is shown in whatever order the
computer's region uses. There is no example row: one left in would be imported.
If the workbook cannot be built (its library did not load), the CSV header row
is downloaded instead.

### Suggested action from the verdict

| verdict | default action |
|---|---|
| `matched` | `merge` (link identity, no new employee) |
| `matched`, and the person has left | `defer`: back, or the number was reissued? |
| `new` | `create`; `defer` when the source gave no name |
| `probable` / `ambiguous` / `archived` | `defer` (needs a human) |

`probable` includes a match that scored as certain and was contradicted: an
equal employee number beside a different national ID, or a terminal number that
is someone's employee number under a name that shares nothing with theirs. The
row's reasons say which ([IDENTITY_RESOLUTION.md](IDENTITY_RESOLUTION.md)).

`commit` honours the final action: `create` makes an employee and links its
external identity (verified); `merge` links to the resolved employee and
writes the row's changes to their record, below; `skip`/`defer` do nothing.

### A match is a reviewed update (2026-11-11)

A matched row used to be a link and nothing more: the phone, department or hire
date it carried were dropped. Owner decision 2026-11-10: **fill what ETHR has
blank, show a person every difference, never a second employee.**
`App\Services\Migration\StagedChanges` compares the row with the employee,
field by field:

| kind | meaning | at commit |
|---|---|---|
| `fill` | ETHR has nothing there | written |
| `conflict` | ETHR has something else | written only if the reviewer accepted that field |

- **Blank in ETHR** is null or empty, a salary of 0, a name ETHR made up for a
  device user with none ("Device user 17"), and a hire date ETHR set to the day
  an earlier list was committed because that list gave none. The last is known
  only while that list is kept; afterwards a different date is a conflict.
- **A blank in the row never overwrites anything.**
- **A terminal's own number is never offered as an employee number or a badge.**
  It is linked as that device's identity, which is what punches resolve through.
- **A number someone else holds** (employee number, badge) is shown as `taken`
  and never written.
- **An acceptance holds while both values are the ones the reviewer was shown.**
  The list is drawn up again at commit from the employee as they are then; an
  accepted phone is not written over a phone someone corrected meanwhile.
- **National id, TIN and salary are named and never shown**, either side.
- **An employee with a personnel action scheduled for a later date is flagged
  and left as they are.**

The edit is `EmployeeUpdater`'s, so it leaves what an edit on the form leaves:
the same pay gate, the same `employee.updated` audit record and webhook.

**Permissions.** Staging and committing still need only `employee.create`. An
edit made through a list takes what the same edit takes on the form:

| to | needs |
|---|---|
| have a row's fills written | `employee.update`, with reach over that employee |
| accept a difference (`PATCH /rows/{row}` with `accept`) | the same; 403 without |
| write or accept salary or TIN | `employee.updateFinancial` as well |
| see what ETHR holds today for a field | `employee.view`, with reach over that employee |

Without them the row is still linked, its other fields are still written, and
`can_apply_changes` on the row says so before the commit.

### A list is committed a slice at a time (2026-11-11)

One request has thirty seconds on the host and the whole list was one
transaction, so a long list could not be added at all. `commitSlice()` works
for `SLICE_SECONDS` (12), in chunks of `CHUNK` (50) rows that are each their
own transaction under the organisation's seat lock, and answers with `status`
(`committing` or `committed`), `remaining` and the totals so far. The browser
sends the same request until it is `committed`. Each chunk starts after
`commit_cursor`, so nothing is added twice, and a request that dies loses only
the chunk it was in.

- The plan's limit is checked, at every chunk, on everyone the list still has
  to add. A list that does not fit is refused before its first row, as before.
  One that stops fitting part of the way (someone else added people meanwhile)
  is refused from there, with what was added kept and the list `committing`.
- Once a list is `committing`, `PATCH /rows/{row}` answers 409: the list the
  reviewer approved is the list that is added.
- The commit has a rate limit of its own (`migration-commit`, 30 a minute per
  person), so a loop that never ends cannot spend the account's allowance.
- `totals` gains `updated`: how many existing employees had details filled in
  or changed.
- `migration.committed` is audited, and the guided setup's step marked, once,
  when the last slice finishes.

**A `create` row is asked again at commit, under the lock** (2026-11-11,
`EmployeeIntake`). The verdict a reviewer saw was reached when the list was
staged, and the roster has moved since. Their "create" stands over everything
they were shown: a likeness, a leaver, a deleted record, and a certain match
when that is what the row said at review. What they were not shown is a certain
match that appeared afterwards (an earlier row of the same list naming the same
person, or someone adding them meanwhile). That row is linked to the person's
record, unverified, and counted under `merged`; it is not made a second time.

## Endpoints (`/api/v1/onboarding/migration`)

| method | path | purpose |
|---|---|---|
| POST | `/devices/{device}` | stage everyone enrolled on a device |
| POST | `/read` | what the server makes of a list (`grid`): people ready, columns read, column names it does not know, lines it cannot stage. Keeps nothing |
| POST | `/rows` | stage a list: `grid` (cells, read as `/read` reads them) or `rows` (people keyed by field). A grid with a line that cannot be staged is refused whole, unless `skip_problems` is set |
| GET | `/batches/{batch}` | review queue with verdicts and summary |
| PATCH | `/rows/{row}` | set a row's action, the employee it is, and the differences accepted |
| POST | `/batches/{batch}/commit` | apply the actions, a slice at a time; once `committed` the answer also carries the two fields below |
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
