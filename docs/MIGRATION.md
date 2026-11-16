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

### A new person's whole profile (2026-11-16)

A staged list can also bring a new person in with the rest of their profile.
These columns are written for a person the list **creates**. A row matched to
someone already in ETHR fills what that person lacks, by the rules in
*Completing people who are already here* below.

| column | group | takes | written through |
|---|---|---|---|
| `grade` | job | the grade's name, as in ETHR (a grade has no code) | `employees.grade_id` |
| `cost_center` | job | the cost centre's name or code | `employees.cost_center_id` |
| `probation_end_date` | job | a date, both calendars. Not before the hire date | `employees.probation_end_date` |
| `contract_type` | job | `probation`, `fixed_term`, `permanent`, `casual`, `consultancy` ("Fixed term" is read) | `EmployeeContractService::create()`, starting on the hire date, with no salary |
| `contract_end_date` | job | a date, after the hire date. Needed for every type but `permanent` | the same contract |
| `bank_name`, `bank_branch`, `bank_account` | pay | the bank, its branch, the account number | `EmployeeRelatedRecords::addBankDetail()`, as the primary account |
| `emergency_name`, `emergency_relationship`, `emergency_phone` | contact | who, how related, their phone | `EmployeeRelatedRecords::addEmergencyContact()` |

- **The rules are the screens' own.** The bank and emergency rules are read
  from `StoreBankDetailRequest` and `StoreEmergencyContactRequest`, and the
  contract type and its end date from `StoreEmployeeContractRequest`, under the
  import's column names (`EmployeeImportProfile`). A group left half filled is
  refused with the missing column named: a bank needs its name and its account
  number, an emergency contact its name, relationship and phone, a contract end
  date its type.
- **A contract never sets pay.** It carries no salary, so the `salary` column
  stays the only source of an employee's pay.
- **Pay permission.** A list that sets `bank_name`, `bank_branch` or
  `bank_account` needs `employee.updateFinancial` to be staged, as a salary or
  a TIN does. A bank account is masked on the review screen.
- **Same transaction.** The contract, bank account and emergency contact are
  written in the transaction of the chunk of up to 50 people that created the
  person (`WorkforceMigrationService::commitChunk`), so a related record that
  fails takes the whole chunk with it and nobody is left half made. A list is
  checked against every one of these rules before it is staged, so a failure at
  commit is not an expected one.
- **Where they are read.** `GET /employees/export` carries `grade`,
  `cost_center`, `probation_end_date`, the active contract's type and end date
  and the first emergency contact, for someone who may open an employee's
  record. It never carries the bank columns: bank details are read with the pay
  permission, which the export does not ask for.

The older `POST /employees/import/preview` and `/commit` are still served, for
a caller that sends a file. They read the same column names and honour both new
columns. They store none of the profile columns above: a preview of a file with
a value in one of them says so in its file-level `errors` (line 0) and still
returns the rows, and the Import screen is where those columns are used. They have no review: a person already in ETHR is counted and nothing
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

### Another system's column names (2026-11-12)

A list exported from another HR or payroll system names its columns its own
way: `Emp Nm`, `Pers Ref`, `DOJ`. ETHR reads none of those by itself, and used
to take such a first line for a person. Now the reader says which column is
which, once, and it is kept per source (`import_mappings`, `ImportMapping`;
`ImportMappingTest`).

**Matching.** `/read` and `/rows` take `mapping`, a list of `{header, column}`:
a column name as the file writes it, and the column of
`EmployeeImportRow::MAPPABLE` it is, or `null` for one that is left out.

- With a mapping, the first line is column names **because the reader said
  so**, whatever it looks like. A name the mapping does not mention is still
  read as ETHR reads it.
- A column name is compared as `EmployeeImportRow::headerKey()` writes it, so
  `EMP NM` and `Emp. Nm` are the same column.
- A column left out is not reported as "not recognised". That message is for
  a name nobody has answered for.
- A matched date column still takes its calendar from its own name:
  `DOJ (E.C.)` is Ethiopian.
- If none of the columns is the name, nothing is read and the answer says so
  once (`needs_name`), not once per line. Staging is refused with a `mapping`
  error.

**What `/read` says to help.** `headers` describes the first line: each name
as written, the column it is read as, and for one that is not read,
`suggested`, a guess from the words in it (`EmployeeImportRow::suggest()`:
"Emp Nm" is probably the name). A guess is **never read as an answer**; the
matcher opens with the guesses filled in and the reader's word is what is
sent. `header_likely` says the first line was read as a person and looks like
column names (several cells, none with a digit or an address in it).

**Saved sources** (`/sources`). Matched once, the columns are saved under the
system's name and the next list names the source (`source`, its `public_id`)
and nothing else. A `mapping` sent with a `source` is read over the saved
columns for that request and changes nothing saved.

- A source's name is its own within the organisation. Names that differ only
  in case or punctuation are one source.
- An organisation keeps at most 30.
- `/read` answers `suggested_source` when a saved source knows which of the
  list's columns is the name and knows more of them than any other. This
  month's export may have a column fewer or one more, so it need not know
  them all. The screen offers it; nothing is read as a source until the
  reader says so.
- Another organisation's source is answered as one that does not exist: 422
  with the generic message on `source`, 404 on its own address.

**An id belongs to the system that issued it.** Until now every
`external_id` was linked under `hr_system` / `default`, so 1042 in the payroll
system and 1042 in the old HR system were one person. A list read as a saved
source links its ids under that source's `slug`
(`employee_external_identities.source_ref`):

- the slug is made from the name when the source is first saved and **never
  changes**, so renaming "Peachtree" to "Old payroll" keeps every id linked;
- a source forgotten and saved again under the same name finds its people;
- a list that names no source still uses `default`, so the ETHR template and
  the ids already linked behave as before.

A name with no Latin letters or digits (one written in Amharic) gets a short
fingerprint of itself as its slug, the same every time.

**On screen** (`column-matcher.tsx`, inside `MigrationStep`). "Match columns"
appears under any list that has been read. Each column shows its name, a value
from the first person and a choice. The matcher keeps the reader's own answers:
the server's reading lags a choice by one request, and answers rebuilt from it
undid the choice made just before. Choosing another file starts again.

Not done here: a source's saved calendar is applied only when the reader has
not chosen one; there is no screen that lists sources apart from the import
step; the older `POST /employees/import/commit` still links under `default`.

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

### Completing people who are already here (2026-11-16)

The live case: an organisation has people whose profiles are half filled (no
gender, position, bank account or emergency contact). HR downloads them as a
workbook, fills the blanks in Excel and brings it back, with nobody added twice
and nothing overwritten by accident. A matched row now reaches more of the
record, and a list can be staged in a mode in which nobody is created.

**More fields for a matched person.** The rule is unchanged: a blank in ETHR is
filled, a blank cell in the file changes nothing.

| fields | a blank in ETHR | a difference |
|---|---|---|
| `name_am`, `gender`, `date_of_birth`, `marital_status`, `nationality`, `nationality_status` | `fill` | `conflict`, written only if a reviewer accepts it |
| `position`, `branch`, `grade`, `cost_center`, `probation_end_date` | `fill` | `locked` |

- **`locked` is a third kind.** It is shown ("Left as it is. Change it with a
  personnel action.") and never written. `applicable()` never returns it, and
  `PATCH /rows/{row}` refuses it: 422, because `accept.*` offers only
  `StagedChanges::acceptable()`. It is decided by the field and the list's
  mode, not by what is stored on the row, which the same request may be about
  to redraw.
- **The nationality status is never blank.** ETHR gives everyone `ethiopian`, so
  a different value in the file is a conflict, not a fill.
- **Relations are matched in the organisation** with `RelationMatch`, by name or
  code, and written as public ids through `EmployeeUpdater`. A name another
  organisation has is not found, and is not shown.
- **Three records are added once, never compared.** When the row brings a whole
  bank account, emergency contact or `contract_type`, and the person has none
  (a contract of any state counts), it is added through
  `EmployeeRelatedRecords::addBankDetail` (primary), `addEmergencyContact` and
  `EmployeeContractService::create` (the hire date, no salary). When the person
  has one, nothing is written and nothing is shown: comparing would reveal an
  account number or a relative's phone. The review shows a `fill` named `bank`,
  `emergency_contact` or `contract` with `current` null and `incoming` the bank
  name, the contact's name or the contract type. `bank` is pay data: it needs
  `employee.updateFinancial`, and the account number is never sent. These
  questions are asked only while the organisation of the request is the
  employee's, because the tenant scope answers "nothing" about everyone when
  none is resolved.
- **All of it is written in the chunk's transaction**, after the same guards:
  `scheduled()`, `employee.update` with reach, and the pay permission.

**`mode: complete`.** `POST /migration/rows` takes an optional `mode`,
stored in `migration_batches.options` (no migration; `commitSlice` merges what
the reviewer chooses at commit into it rather than replacing it). The batch
payload says `mode`: `add` or `complete`.

- **Nobody is created.** A row that matches nobody is staged as `skip`
  ("Not found in ETHR, left out"). `PATCH /rows/{row}` with `create` is 422, and
  `commitChunk` counts a `create` row as skipped, wherever it came from, and
  asks the plan's seat limit about nobody. A row with several candidates still
  needs the reviewer to choose one.
- **Job and pay are fill only.** A `department` and a `salary_cents` that
  differ are `locked` too, and cannot be accepted. A personal conflict can.
  `tin` and `hire_date` are not job or pay here and behave as in any list.
- **Status.** A row with `status` `probation` or `confirmed` moves a person who
  is still `hired` through `EmployeeTransitionService::transition` (today in the
  organisation's zone, no reason, the reviewer as approver), so the move has its
  history row and audit entry. It is a `fill` on `status`, and needs
  `employee.transition`, which editing a person does not imply. Any other
  difference in status is `locked`; a list never writes `status` itself.

**Download to complete.** The Employees list has a button next to Export. It
builds the employee workbook (`buildTemplate`) in the Gregorian calendar,
prefilled from `GET /employees/export` with the list's own search and filters
(`?missing=` included, and now `department`, `position` and `gender` besides
`tin`, `bank`, `emergency_contact` and `phone`). Values the export does not
give (salary, TIN, bank) are blank cells. A status the import would refuse
(someone who has left) is left blank. One staging takes 2,000 people, so a list
longer than that is not built from part of it: the reader is asked to narrow
the list. The file is `ethr-employees-to-complete.xlsx`; it is brought back on
**Import**, choosing *Complete people who are already here*. The choice is not
offered in the setup or for a device's roster.

### Problems by column, and two files (2026-11-16)

A read's `problems` are `{line, message, column, code}`. `column` is the
column as the template spells it (`hire_date`, `department`, `bank_name`), and
null for a problem about the whole list (`too_many`). `code` is derived, not a
second list: for a rule the failed rule as the validator names it, lower-cased
(`date`, `in`, `required_with`; `EmployeeImportRow::problems()`), and for the
rest a fixed code where the message is raised (`relation_not_found`,
`relation_ambiguous`, `duplicate_in_list`, `too_many`, and the profile ones
`probation_before_hire`, `contract_type_needed`, `contract_end_needed`,
`contract_end_before_start`, `contract_needs_hire_date`).
`EmployeeImportRow::messages()` keeps returning strings, so the legacy file
import is unchanged. A warning carries its `column` too (`badge_number`).

The screen groups them: one row for each column and kind, the first message,
how many lines and which (ten, then "and N more"), largest first. Every group
is shown.

**Download the lines to fix** builds `ethr-employees-to-fix.xlsx` in the
browser from the list the reader already holds: one `Employees` sheet, the
list's own header line, only the refused lines as text, and a last column
headed `Problem` ("Line 14: ..."). Nothing is sent to the server for it. The
file comes back through the same reader, which ignores a column headed
`Problem` (it is not reported as not read and is never suggested as anything),
so the corrected lines are chosen as they are. A list matched by hand loses
its matching with the file; the header line is what it was.

**Download what happened** is on the committed screen: `ethr-import-result.xlsx`,
one `Result` sheet with a row for each line (name, employee number, what
happened, note). The number is the batch row's `resolved_employee_code`, which
is null for a viewer who may not open that person's record.

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
| POST | `/read` | what the server makes of a list (`grid`): people ready, columns read, column names it does not know, lines it cannot stage. Takes `mapping` and `source`; answers `headers`, `header_likely`, `needs_name` and `suggested_source`. Keeps nothing |
| POST | `/rows` | stage a list: `grid` (cells, read as `/read` reads them) or `rows` (people keyed by field). A grid with a line that cannot be staged is refused whole, unless `skip_problems` is set. With a `source`, ids from the other system are kept against it |
| GET | `/sources` | the organisation's saved sources and their column names |
| POST | `/sources` | save a source: `name` and `mapping`, one column of which is the name |
| PUT | `/sources/{importMapping}` | rename a source or match its columns again. The slug its ids are kept against does not change |
| DELETE | `/sources/{importMapping}` | forget a source's column names. The people it brought and their ids stay |
| GET | `/batches/{batch}` | review queue with verdicts and summary |
| PATCH | `/rows/{row}` | set a row's action, the employee it is, and the differences accepted |
| POST | `/batches/{batch}/commit` | apply the actions, a slice at a time; once `committed` the answer also carries the two fields below |
| GET | `/batches/{batch}/logins` | `without_login` and `hire_dates_defaulted`, read again |

## What the whole list is brought in with (2026-11-12)

Owner decisions of 2026-11-10 (mass activation): a list is brought in with two choices
made once, on the review step, before it is added.

**What its new people arrive as.** `POST /batches/{batch}/commit` takes `status`: `hired`
(nothing sent, as before), `probation` or `confirmed`. Everyone a list brought in used to
arrive as Hired, and a Hired person cannot take leave, be suspended or resign: staff who
have worked somewhere for years were being recorded as if they had started today.
`confirmed` sets `confirmation_date` to the day they are added, as the import's own
`status` column does.

- A line that names its own status keeps it.
- The choice is read when the list **begins** to be added and kept with it
  (`migration_batches.options`). A long list is added over several requests, and one
  carried on from another tab, or by a request that says something else, goes on as it
  began (`ImportAsConfirmedTest`).
- The list's payload answers `arrival_status`, so a screen that comes back to a list
  under way shows what was chosen.
- Anything outside `EmployeeImportRow::STATUSES` is refused: nobody is brought in as a
  leaver.

**Whether they are invited straight away.** Ticked by default. When the list has been
added, the people an invitation can reach and who have not been sent one are invited by
one request to `POST /api/v1/employees/logins`, without a second button, once. The
browser makes that request, not the commit: the commit's slices stay inside the host's
thirty seconds, and nothing is sent inside any request (the invitations are queued and
paced as before). If the tab is closed first, nothing was sent and the card below still
offers the button. Unticked, the screen is as it was: HR looks at the names, then sends.

## After the commit: a way to sign in (2026-10-28)

A migration creates employees and no logins. Names read off a terminal are often wrong
("guest card", "test"), which is why inviting them is a choice made before the list is
added (above) and can be left for after HR has looked. The commit answer lists
`without_login`: each person from the batch with no login, or with one invited and not yet
activated, and the channel they would be invited by (`email`, `sms` while text invitations
are on, else `in_person`).

**Send invitations** posts every listed id to `POST /api/v1/employees/logins`
(`users.invite` and `employee.update`). `EmployeeLogins` gives each person a login as the
employee import does (the importer uses the same class), queues one batch of invitations,
and sends nothing inside the request. A person with no way to be reached gets no login
yet: HR issues an in-person code from their profile, prints a sheet of codes for all of
them, or gives them one starting password between them. Another organisation's employee
id is refused with the message an unknown id gets.

**A sheet of codes (2026-11-12).** `POST /api/v1/employees/activation-codes`
(`users.issueActivationCode`, `BulkActivationCodeTest`) issues a one-time code for each
of up to 200 employees no invitation can reach, and the screen shows them once as slips
to print and cut: name, employee code, a QR and the code to type.

- Every person is held to what a single issue holds them to
  (`ActivationCodeService::eligible`). One who is refused is listed under `refused`
  with why, and the rest are issued.
- HR confirms it is them (password, and authenticator code when they have one) and
  undertakes to check each person's identity **when the slip is handed over**. The
  codes record `document_type` as `at_handover`, and one `issued_batch` between them.
- A sheet's codes live 72 hours unless `hours` says otherwise, 168 at most. A single
  code issued face to face stays at 72: a sheet cannot be handed out in a day.
- When the organisation has no sign-in its people could use, everyone is refused with
  `identifier` and the screen links to the guided setup's Access step.
- A slip is no use to anyone else once it is with the right person, which one shared
  starting password is not. The sheet is offered first for that reason.

**An invitation nobody answered.** Someone invited by email who has not activated in
three days, and has been sent nothing since, may be given a code, on a sheet or one at a
time. Until now anyone with an email on file could never get one, even when the address
was wrong. A reminder restarts the three days.

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
