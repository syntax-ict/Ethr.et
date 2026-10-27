# Statutory parameters

Every statutory value the payroll engine applies is a dated row, never a
constant in code. This page lists them, what each one does, the value seeded
for each organisation type, and whether that value is confirmed.

Since 2026-10-27 (migration `2026_10_27_000002_create_organization_types_and_statutory_parameters`).
Before that the engine fell back to constants for the working-day divisor,
the hours in a day, the Art. 68 overtime floor, the Art. 67 limits, the night
window, the list of pension schemes, the 7/11 pension rates and both income
tax ladders.

## How a value is found

`App\Services\Payroll\StatutoryRules::for($tenant, $date)` resolves each code
in this order:

1. the platform row for the organisation's type, if there is one in force;
2. otherwise the platform row for every type (`organization_type` NULL);
3. then, only when that row says `tenant_overridable`, the organisation's own
   row, or else its equivalent setting from before this table existed
   (`settings.pension_scheme`, `settings.fiscal_year_start_month`,
   `settings.pagumen_proration_strategy`).

The tax ladder (`tax_brackets`) and the pension rules (`pension_rules`) follow
the same order: the organisation's own, then its type's on the platform, then
every type's. A ladder is always taken whole.

**There is no default.** A code with no row in force refuses the run, naming
the code: *"No statutory rule is in force for payroll.hours_per_day on this
date."* The refusal happens before any entry is written.

Every payroll entry's `calculation_log` (version 1.5 onwards) carries the
whole resolved set under `statutory_rules`: the organisation type, and for
each code its value, its source (`platform`, `platform_type`, `tenant`,
`tenant_setting`), its legal reference, whether it is confirmed, and the row
it came from.

## Organisation types

A global catalogue (`organization_types`), edited from the platform console.
An organisation chooses its type at registration or in Settings; every
organisation that existed before 2026-10-27 was given the type its settings
suggested and asked to confirm it (`organization_type_needs_review`).

A guessed type moves no pay. Before 2026-10-27 an organisation with no
setting was paid on the private pension scheme with a Meskerem fiscal year,
whatever it was, so the migration wrote those two values into the settings
of every existing organisation that had not chosen its own. Without that, an
organisation guessed to be `government` would have moved to the public scheme
and a Hamle fiscal year on deploy. Each can still be changed in payroll
settings; organisations registered after the migration get their type's
defaults.

| Code | Meaning |
|---|---|
| `private` | Companies, banks, private schools and clinics, other private employers |
| `public_enterprise` | Enterprises owned by the federal or a regional government |
| `government` | Federal and regional ministries, bureaus, agencies, public universities |
| `ngo` | Charities, societies and other civil society organisations |
| `international_organization` | International and regional organisations, diplomatic missions |
| `defence_police` | Defence forces and police |

## Parameters

`confirmed` is true only where the value is the text of the law. An ETHR
convention or reading is false until the accountant sign-off settles it
(`ACCOUNTANT-SIGN-OFF.md`).

| Code | Value | Types | Overridable | Source | Confirmed |
|---|---|---|---|---|---|
| `payroll.working_days_per_month` | 22 | all | no | ETHR convention | no |
| `payroll.hours_per_day` | 8 | all | no | Labour Proclamation 1156/2019, Art. 61(1) | yes |
| `overtime.minimum_rates` | normal 1.5, night 1.75, rest day 2.0, holiday 2.5, holiday night 2.5 | all | no (an organisation raises its own rates on the overtime card) | 1156/2019, Art. 68(1) | yes |
| `overtime.night_window` | 22:00 to 06:00 | all | no | 1156/2019, Art. 68(1)(b) | yes |
| `overtime.legal_daily_minutes` | 240 | all | no | 1156/2019, Art. 67 | no |
| `overtime.legal_weekly_minutes` | 720 | all | no | 1156/2019, Art. 67 | no |
| `overtime.limited_types` | normal, night | all | no | ETHR reading of Art. 67 | no |
| `pension.schemes` | private, public, public_military | all | no | 1268/2022; 1267/2022 | yes |
| `pension.default_scheme` | private | all | yes | 1268/2022 | yes |
| `pension.default_scheme` | public | `public_enterprise` | yes | 1267/2022 | no (research O1) |
| `pension.default_scheme` | public | `government` | yes | 1267/2022, Art. 10 | yes |
| `pension.default_scheme` | public_military | `defence_police` | yes | 1267/2022, Art. 11 | yes |
| `fiscal_year.start_month` | 1 (Meskerem) | all | yes | ETHR default | no |
| `fiscal_year.start_month` | 11 (Hamle) | `government` | yes | Federal fiscal year | no |
| `pagume.proration` | full_month | all | yes | ETHR default | no |

The 979/2016 income tax ladder (8 July 2016 to 6 July 2025) and the
1395/2025 ladder (from 7 July 2025) are platform rows in `tax_brackets`,
written by `TaxBracketSeeder` and by the migrations; the 979/2016 one is
inserted by the 2026-10-27 migration where a database had only run
migrations. The pension rates are the `pension_rules` rows seeded on
2026-10-14.

## Changing a value

A change in the law is a new row from its effective date, never an edit:
the previous row is closed the day before. Reprocessing an earlier period
keeps reading the row that was in force then. Until the platform console
gains its editors (payroll upgrade 9), a new platform row is written by a
migration, as the tax ladder's 1395/2025 amendment was.

## Open questions

Tracked in [`STATUTORY-RULES-RESEARCH.md`](STATUTORY-RULES-RESEARCH.md):
which rules differ for civil servants, public enterprises, charities and
international organisations beyond the pension scheme and fiscal year
(research O1, O2); the overtime divisor; the Art. 67 limits.
