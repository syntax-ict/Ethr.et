# Identity Resolution

How ETHR maps external identifiers (device users, import rows, directory records)
to the one internal employee they belong to, so imports and device syncs never
create a duplicate person. ETHR is the master identity.

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
   | employee_code (exact, ci) | 0.90 | unique per tenant |
   | badge_number (exact, ci) | 0.85 | |
   | email (exact, ci) | 0.85 | |
   | phone (digits, format-insensitive) | 0.70 | not country-code canonicalized |
   | name similarity | ≤0.20 | additive; never enough alone |

3. Verdict by top score and gap to the runner-up:
   - `matched` ≥ 0.85 and clear of the runner-up
   - `probable` ≥ 0.60
   - `ambiguous` — two credible candidates within 0.15 of each other
   - `new` — nothing credible

A single strong identifier reaches `matched`; a name resemblance alone stays
below `probable`, so bulk import never silently merges distinct new hires.

`link()` persists (or refreshes) a mapping; ambiguous identifiers resolve to no
employee rather than guessing.

## Where it is used

- **`PullDeviceEventsJob`** — resolves each event's badge; records attendance
  only on an actionable match, auto-links the mapping (unverified), and leaves
  ambiguous/unknown badges for review.
- **`EmployeeImporter`** — skips a row that strongly matches an existing employee
  (reported as `matched`) instead of creating a duplicate.
- **`WorkforceMigrationService`** — see [MIGRATION.md](MIGRATION.md).

## National ID matching (blind index)

`employees.national_id` is stored **encrypted** (PII), so it can't be queried
directly. Matching uses a **blind index**: `Employee::hashNationalId()` produces a
deterministic HMAC-SHA256 of the value with all separators stripped and
upper-cased, persisted to `national_id_hash` (kept in sync by the model's
`saving` hook, guarded by `isDirty`). The resolver looks up and scores on that
hash — the strongest signal (weight 0.95) — without ever decrypting. Covered by
`NationalIdMatchingTest`.

## Known follow-ups

- Phone matching is digit-for-digit, not telephony canonicalization (`+251` vs
  leading `0` are not reconciled).
- `username` login identifier (Slice 7 deferral) still needs a column.
