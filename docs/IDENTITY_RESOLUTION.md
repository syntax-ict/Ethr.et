# Identity Resolution

How ETHR maps external identifiers (device users, import rows, directory records)
to the one internal employee they belong to, so imports and device syncs never
create a duplicate person. ETHR is the master identity.

This is the first part of the *one employee identity* plan (2026-11-10): one
employee record, several attendance sources, one attendance engine. What the plan
still has to build is listed under [Known follow-ups](#known-follow-ups).

## Data model

`employee_external_identities` (tenant-scoped, `BelongsToTenant`):

| column | purpose |
|---|---|
| `source_type` | where it came from — `zkteco`, `hikvision`, `csv`, … |
| `source_ref` | which instance — e.g. `device:5` (non-null, default `''`) |
| `identifier_type` | kind of id — `device_user_id`, `card_number`, `national_id`, … |
| `identifier_value` | the value |
| `confidence` | confidence at link time |
| `verified_at` | set when an admin confirms the mapping |

Unique on `(tenant_id, source_type, source_ref, identifier_type, identifier_value)`
— the **same** device user id on two different devices is two distinct mappings,
which is what makes multi-device sync safe.

## Resolver — `App\Services\Identity\IdentityResolver`

`resolve(int $tenantId, IdentitySignals $signals): IdentityMatch`

1. If the signals carry external-identity coordinates and an exact mapping
   already exists, that mapping is authoritative (no scoring).
2. Otherwise candidates are scored by weighted signals:

   | signal | weight | notes |
   |---|---|---|
   | national_id (blind index) | 0.95 | see below |
   | employee_code (exact, ci) | 0.90 | unique per tenant |
   | badge_number (exact, ci) | 0.85 | |
   | email (exact, ci) | 0.85 | |
   | phone | 0.70 | every form of one Ethiopian number: `0911…`, `+251911…`, `251911…`, `911…` |
   | name similarity | ≤0.20 | additive; never enough alone; the Amharic name counts too |

3. Verdict by top score and gap to the runner-up, among **current** employees:
   - `matched` ≥ 0.85 and clear of the runner-up
   - `probable` ≥ 0.60
   - `ambiguous` — two credible candidates within 0.15 of each other
   - `new` — nothing credible
4. A `matched` verdict is then checked for a **contradiction**. With one it is
   `probable`: shown to a person, never acted on.
5. Only when nobody current is credible are archived (soft-deleted) employees
   looked at. One that is a certain match, uncontradicted, is `archived`.

A single strong identifier reaches `matched`; a name resemblance alone stays
below `probable`, so bulk import never silently merges distinct new hires.

### Contradictions

Weights only add. On their own they cannot tell "the same person" from "shares
one identifier with them", and from the moment a match is used to *update* an
employee rather than only skip a row, that difference is someone's record.

| conflict | when |
|---|---|
| `national_id_differs` | both have a national ID and they are not the same |
| `employee_code_differs` | the source **states** an employee number and the employee has another, and the national ID is not what matched (staff get renumbered; a person does not get a second national ID) |
| `name_differs` | the match rests on a device number alone (a badge, or a terminal's number tried as an employee number) and the two names share no word |

`IdentityMatch::$conflicts` lists them, and they are appended to the top
candidate's `reasons`, so a screen that lists reasons shows them.

**A terminal's number is a guess at an employee number, not a statement of one.**
Many organisations enrol people under their employee number, so the number is
tried as one — but nobody said it is. `IdentitySignals::forDeviceUser()` builds
those signals (`codeIsGuess`), and it is the single definition used by a pushed
punch, a pulled punch, a held punch being assigned and the enrolled-users list.
For a guess, a different employee number is not a contradiction; a name with
nothing in common is.

`name_differs` is not a similarity score. A terminal holds "ABEBE", "Abebe K."
or "Abebe 2" for Abebe Kebede Tesfaye; each scores low and is plainly him. What
marks another person is that no word overlaps at all ("Guest card"). A name in
Amharic against a record with only a Latin name cannot be compared and is not
called different.

**A punch carries a number and no name, so none of this changes whose punch it
is.** `tests/Feature/Device/DevicePunchResolutionUnchangedTest.php` pins that;
ten of its eleven cases passed before the change and all pass after.

### Archived and former employees

- **Archived** (soft-deleted). The resolver used not to see them, so re-importing
  one produced a second record — or, when the employee number was the same, the
  unique index's "already taken". They are now reported as `archived`. They never
  outrank or tie with a current employee: the current ones are judged first,
  exactly as before.
- **Former** (resigned, terminated, retired). Still `matched` — a leaver's punch
  has to reach the engine to be refused — with `IdentityMatch::isFormerEmployee()`
  true and `former_employee` among the reasons.

### `link()`

Persists (or refreshes) a mapping. A mapping that already names **someone else**
is returned as it stands. It used to be re-pointed silently. It now moves only:

- with `reassign: true` — a person said whose number it is (`UnmatchedPunches::assign`,
  committing a reviewed migration). Audited as `identity.reassigned`, with who
  held it before;
- when nobody confirmed it and its holder is no longer a current record, so an
  automatic link follows the match, as it always has.

It never throws for a holder it will not displace, because a device calls it on
every punch. Two punches from one new number arriving together both try to
insert; the loser re-reads the winner's row.

## Where it is used

- **`DevicePunchRecorder`** (webhooks, ZKTeco ADMS) and **`PullDeviceEventsJob`**
  — resolve each event's number; record attendance only on an actionable match,
  auto-link the mapping (unverified), and hold everything else in
  `unmatched_device_punches` for a person to assign.
- **`DeviceEnrollmentController`** — the enrolled-users list, each with a verdict.
- **`EmployeeImporter`** — skips a row that is the same person (`matched`).
  A row that contradicts the person it looks like is refused
  (`employee.import_needs_review`), and so is a row for an archived employee
  (`employee.import_archived`): this import has no review step, so it neither
  skips nor adds what it cannot be sure of.
- **`WorkforceMigrationService`** — see [MIGRATION.md](MIGRATION.md). A match
  with a former employee, and an `archived` verdict, are both deferred to a
  person.

Every caller passes every signal it has. The file import used to leave out the
badge number and the migration the national ID, so the same row could be a match
through one and new through the other.

## National ID matching (blind index)

`employees.national_id` is stored **encrypted** (PII), so it can't be queried
directly. Matching uses a **blind index**: `Employee::hashNationalId()` produces a
deterministic HMAC-SHA256 of the value with all separators stripped and
upper-cased, persisted to `national_id_hash` (kept in sync by the model's
`saving` hook, guarded by `isDirty`). The resolver looks up and scores on that
hash — the strongest signal (weight 0.95) — without ever decrypting. Covered by
`NationalIdMatchingTest`.

## Tests

- `IdentityResolutionTest` — scoring, mappings, the pull and the import.
- `IdentityResolverContradictionTest` — phone forms, the three contradictions,
  archived and former verdicts, the guarded `link()`, and what the import and the
  migration now pass.
- `Device/DevicePunchResolutionUnchangedTest` — whose punch a number is.
- `NationalIdMatchingTest`, `MigrationWorkspaceTest`, `Device/UnmatchedPunchTest`.

## Known follow-ups

From the plan, in order; none is built yet:

- **One gateway.** Six code paths create an employee and five of them have their
  own duplicate rule (manual add, file import, migration, recruitment, SCIM, SSO).
  `EmployeeIntake` becomes the only one, pinned by an inventory test.
- **An archived match has no "restore".** The verdict exists; the action does not.
- **A match is still not an update.** The import skips the row and a migration
  merge links an id. Filling blanks and reviewing conflicts comes with the staged
  import.
- **No database uniqueness** on badge number or national ID. Only after a
  duplicates report shows production is clean.
- `username` login identifier (Slice 7 deferral) still needs a column.
- A phone stored with a character the generated column does not strip
  (`091/234-5678`) still never matches (BASELINE §15e).
