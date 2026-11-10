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

`App\Services\Payroll\StatutoryRules::for($tenant, $date)` is the one settings
resolver: payroll, leave and the settings pages all read through it. It first
takes the organisation's **profile on that date**
(`App\Services\Tenancy\ProfileContext`): the organisation type and sector of
the `tenant_profile_assignments` row in force, and the type's parent. Then,
for each code, among the platform rows in force (latest first) the most
specific wins:

1. a row for the organisation's **sector** (sector rows are a layer of their
   own; none is seeded, because no sector-specific rule is sourced);
2. a row for its **organisation type**;
3. a row for its type's **parent** (a type with no rows of its own is paid as
   its parent is);
4. the **baseline**, the row for every type (`organization_type` NULL).

Then, unless that row is `locked`, the organisation's **own** row in force
replaces it.

How binding each value is (`enforcement`):

| Enforcement | Meaning |
|---|---|
| `locked` | The law's value. An organisation has no row of its own. Where the law sets a floor an organisation may exceed through its own mechanism (the overtime rates card, a leave type's days), `floor_direction` is `min`; where it sets a ceiling an organisation may stay under (the Art. 59 deduction cap), `max`. |
| `default` | The organisation may replace it with its own dated row. |
| `advisory` | As `default`, presented as guidance. |

`tenant_overridable` is kept as the projection `enforcement != locked`, set on
every save and pinned by a test, because the column predates `enforcement`.

The income tax ladder (`tax_brackets`) and the pension rules (`pension_rules`)
are found in the same order through the same profile: the organisation's own,
then its type's on the platform, then its parent type's, then every type's. A
ladder is always taken whole. Both are the law's exactly: since 2026-11-09 an
organisation can no longer save its own ladder or rule (the API answers 422,
citing the law). One it saved before is still honoured for its dates, flagged as
a blocker on the readiness checklist (`statutory_law_followed`) and ended from
Settings → Payroll → Statutory rules (`POST /payroll/statutory-rules/use-law`,
`payroll.manageConfig`, `App\Services\Payroll\OwnStatutoryRates`). Ending closes
each of its rows yesterday, never deletes one, so a period already paid still
reproduces; it is audited `statutory_parameter.own_rates_ended`.

**There is no default.** A code with no row in force refuses the run, naming
the code: *"No statutory rule is in force for payroll.hours_per_day on this
date."* The refusal happens before any entry is written.

Every payroll entry's `calculation_log` (version 1.5 onwards) carries the
whole resolved set under `statutory_rules`: the organisation type, and for
each code its value, its source, its legal reference, whether it is
confirmed, and the row it came from. From 1.8 it also carries the sector, and
for each code its `layer` (`baseline`, `parent_type`, `organization_type`,
`sector`, `tenant`), its `enforcement` and the published batch it came from
(`version`).

### Published batches

Each platform row carries the `profile_versions` batch it was published in:
`2026.11.1` for every row in place on 2026-11-09, `2026.11.2` for the leave,
sick-pay, deduction-cap and pension-coverage rows. A change in the law is a
new batch of new dated rows; it never touches an organisation's own rows, and
no organisation pins a batch: the law in force on a payroll date is the law
applied.

### Seeing and resetting them

Settings → Payroll → Statutory rules (`GET /payroll/statutory-rules`,
`payroll.viewConfig`) lists every code in force with its layer, enforcement,
legal reference, batch and the default it would have without the organisation's
own value. A `default` code is changed where it always was (`PUT /settings`) and
reset here (`DELETE /payroll/statutory-rules/{code}`, `payroll.manageConfig`),
which deletes the organisation's own row. Both are audited where the row is
written, whichever path wrote it (`StatutorySettingsBridge`):
`statutory_parameter.overridden` with the value before and after, and
`statutory_parameter.reset` with the value removed.
A `locked` code is listed, never editable.

### Settings keys from before the table

Four values an organisation could set as `tenants.settings` keys before they
were rows (`pension_scheme`, `fiscal_year_start_month`,
`pagumen_proration_strategy`, `working_days_per_month`) are its own
statutory_parameters rows since 2026-11-09. The resolver no longer reads the
keys; `StatutorySettingsBridge` writes a saved key through to the row (dated
from the platform's first row for the code, because a key applied to every
period), and removing the key removes the row. The `tenant_setting` source is
retired.

## Organisation types

A global catalogue (`organization_types`), written by migrations: there is
no editor in the platform console yet. An organisation chooses its type at
registration or in Settings, and `App\Services\Tenancy\OrganisationProfile`
records it as a dated assignment. Before any payroll is approved a change
corrects the history. After, it is never retroactive: `PUT
/settings/organization` needs an `effective_from` that is the first day of an
organisation month (never Pagume) after the last approved period and not before
today (`OrganisationProfile::earliestChange()`, which `GET /settings` reports as
`organization_type_earliest_change`). The change waits as a future assignment
(`organization_type_pending`); a newer one replaces it. Choosing the type in
force today again changes nothing (any `effective_from` sent with it is
ignored), so saving other details never disturbs a scheduled change; it is
withdrawn only by `cancel_pending_organization_type`, audited
`organization_profile.change_cancelled`. Every
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

Since 2026-11-09 a type may have a **parent**: it owns no rows until a
sourced rule differs, and resolves through its parent's meanwhile (one level
deep). Amharic names of the inheriting types are drafts awaiting native
review (`docs/i18n/AMHARIC-REVIEW.md`); their coverage questions are
in `ACCOUNTANT-SIGN-OFF.md`.

| Group | Code | Parent | Meaning |
|---|---|---|---|
| private | `private` | — | Other private employers |
| private | `sole_proprietor` | `private` | A business owned and run by one person (Commercial Code 1243/2021) |
| private | `private_limited_company` | `private` | PLC, including a one-person PLC |
| private | `share_company` | `private` | Share company (S.C.) |
| private | `partnership` | `private` | General or limited partnership, joint venture |
| private | `cooperative` | `private` | Cooperative society or union (Proclamation 985/2016) |
| private | `foreign_company_branch` | `private` | Ethiopian branch of a business registered abroad |
| non_profit | `ngo` | — | Charities, societies and other civil society organisations |
| non_profit | `foreign_cso` | `ngo` | Foreign or international NGO (Proclamation 1113/2019) |
| non_profit | `religious_organization` | `ngo` | Religious institution, outside 1113/2019 |
| public | `public_enterprise` | — | Enterprises owned by the federal or a regional government |
| public | `government` | — | Federal ministries, bureaus, agencies, public universities |
| public | `regional_government` | `government` | Regional state or city administration offices |
| public | `defence_police` | — | Defence forces and police |
| international | `international_organization` | — | International and regional organisations |
| international | `diplomatic_mission` | `international_organization` | Embassies, consulates and other missions |

## Parameters

`confirmed` is true only where the value is the text of the law. An ETHR
convention or reading is false until the accountant sign-off settles it
(`ACCOUNTANT-SIGN-OFF.md`).

Overridable "yes" is `enforcement = default`; "no" is `locked`. The Art. 67
overtime limits stay `locked`: they only flag a run, but they are the law's.

| Code | Value | Types | Overridable | Source | Confirmed |
|---|---|---|---|---|---|
| `payroll.working_days_per_month` | 22 | each type has its own row, so each is approved or changed alone | yes, on Payroll rules → Payroll schedule (20 to 31) | ETHR convention | no |
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
| `leave.annual.base_days` | 16 | all | no (locked, `min`: a leave type may grant more) | 1156/2019, Art. 77(1) | no |
| `leave.annual.seniority` | +1 day every 2 years | all | no (locked, `min`) | 1156/2019, Art. 77(1) | no |
| `leave.maternity.days` | 120 (calendar days) | all | no (locked, `min`) | 1156/2019, Art. 88 | no |
| `leave.paternity.days` | 3 | all | no (locked, `min`) | 1156/2019 (article to cite) | no |
| `leave.sick.tiers` | 1 month full, 2 half, 3 unpaid | all | no | 1156/2019, Art. 85 | no |
| `leave.sick.window_months` | 12 | all | no | 1156/2019, Art. 85 | no |
| `leave.sick.half_pay_ratio` | 1/2 | all | no | 1156/2019, Art. 85 | no |
| `payroll.deduction_cap_ratio` | 1/3 of the month's wage | all | no (locked, `max`) | 1156/2019, Art. 59 | no |
| `pension.applies_to` | every contract type | all | no | 1268/2022, Art. 2; research P11 | no |

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
