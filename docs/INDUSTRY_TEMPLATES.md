# Industry Templates & Smart Configuration

A tenant's starting configuration is composed from three independent choices, so
"hospital + 24/7 rotation + civil-service rules" and "hospital + office hours +
private-sector rules" need no template each:

| choice | catalogue | decides |
|---|---|---|
| **Industry** (archetype) — what the organisation is | `App\Services\Onboarding\IndustryCatalog` (35) | departments, positions, grades, sector leave types (study, sabbatical, injury, R&R), sector allowances, employee-number prefix |
| **Workforce profile** — how its people work | `WorkforceProfileCatalog` (10) | shifts, shift rotations, the working week, attendance settings |
| **Policy profile** — which employment law its rules start from | `PolicyProfileCatalog` (3) | statutory leave types, fiscal-year start, common allowance definitions, review notes |

Each industry names its usual profile and policy; the admin can change either in
the review step. Adding an industry is a row in `IndustryCatalog`, not a new
hand-authored template (ONBOARDING_V2.md D2–D3). Catalogue version:
`IndustryCatalog::VERSION` (`2026.10.1`), recorded on the tenant when applied.

## The 8 base templates

Seeded by `OrganizationTemplateSeeder` into `organization_templates`:
`government`, `bank`, `hospital`, `manufacturing`, `ngo`, `hotel`, `university`,
`general`. Each `template_data` carries `departments`, `positions`, `grades`,
`shifts`, `leave_types` and `settings`.

Since catalogue 2026.10.1 a plan takes its **shifts** from the workforce profile
and its **statutory leave types** from the policy profile. The templates' own
`shifts` and `leave_types` still serve the public `GET /templates` listing and
plans saved before then. Two corrections made with that change:

- The hospital and hotel templates wrote Sunday as `0`. Shifts use ISO days
  (1 = Monday … 7 = Sunday), so those "every day" shifts never worked a Sunday.
  The seeder now writes `7`, the provisioner reads a `0` as `7`, and migration
  `2026_10_14_000001_fix_sunday_in_template_shift_working_days` repairs shifts
  already created.
- Re-seeding gave every template a new `public_id` on each deploy. It is now set
  once, on insert.

## The 35 industries — `IndustryCatalog`

Grouped, each `→ base` (default workforce profile · policy):

- **Public sector → government** (all `standard_office` · `ethiopia_public`):
  federal_government, ministry, federal_agency, regional_government,
  regional_bureau, zonal_administration, city_administration,
  woreda_administration, sector_office. Each tier except the two generic ones
  has its own departments and positions (a woreda's sector offices, a zone's
  departments, a bureau's directorates, a city's mayor's office and services).
- **Education → university**: university, tvet (`ethiopia_public`), school (`ethiopia_private`)
- **Healthcare → hospital**: hospital (`continuous_24_7`), health_center (`extended_hours_two_shift`)
- **Nonprofit → ngo**: ngo
- **Financial → bank**: bank and microfinance (`six_day_week`), insurance
- **Industrial → manufacturing**: manufacturing (`rotating_three_shift`), construction (`six_day_week`), agriculture (`seasonal_agricultural`)
- **Hospitality & trade → hotel**: hotel (`continuous_24_7`), restaurant and retail (`extended_hours_two_shift`), wholesale (`six_day_week`)
- **Services & tech → general**: professional_services and technology_company (`flexible_hybrid`), logistics (`six_day_week`), transport and bpo (`extended_hours_two_shift`), telecom, security_company (`continuous_24_7`)
- **Other → general**: small_business, multi_branch, custom

An override patch may replace a list section (curated departments, positions),
add `allowances`, or deep-merge `settings`.

## The 10 workforce profiles — `WorkforceProfileCatalog`

| key | shifts | rotation |
|---|---|---|
| `standard_office` | Regular 08:30–17:30 Mon–Fri | — |
| `six_day_week` | Weekday 08:00–17:00, Saturday 08:00–12:00 | 7 days: five weekdays, Saturday, rest (anchor on a Monday) |
| `flexible_hybrid` | Flexible day 08:00–17:00, grace 120 min | — |
| `rotating_three_shift` | Day 06–14, Swing 14–22, Night 22–06, Mon–Sat | 21 days: six of each, a rest day after each |
| `continuous_24_7` | Morning 06–14, Afternoon 14–22, Night 22–06, every day | 8 days, 2-2-2-2; four crews two days apart cover every shift |
| `extended_hours_two_shift` | Opening 07–15, Closing 14–22, every day | 14 days: six opening, rest, six closing, rest |
| `part_time` | Half day 08:30–12:30 and 13:30–17:30 | — |
| `field_distributed` | Field day 08:00–17:00; photo on phone check-in, auto check-out | — |
| `seasonal_agricultural` | Field shift 06:30–14:30 Mon–Sat; auto check-out | — |
| `weekend` | Weekend 08:00–17:00 Sat–Sun | — |

Every profile is expressed only in what the shift engine already runs. A test
(`OrganizationTemplateLibraryTest`) asserts every time is valid, every day is ISO,
every rotation step names one of the profile's own shifts, and every schedule
averages **at most 48 hours a week** (Labour Proclamation 1156/2019 art. 61).

## The 3 policy profiles — `PolicyProfileCatalog`

| key | statutory leave | fiscal year | allowance definitions |
|---|---|---|---|
| `ethiopia_private` | annual, sick, maternity, paternity, marriage, bereavement, unpaid | Meskerem (1) | transport, housing, position |
| `ethiopia_public` | the same plus study | Hamle (11) | transport, housing, position |
| `custom` | annual, sick, unpaid | Meskerem (1) | none |

Labour Proclamation 1156/2019 does not govern civil servants; the Federal Civil
Servants Proclamation 1064/2017 and regional civil service laws do. So the
public profile is not presented as compliant: its leave values are ETHR's general
`LeaveTypeCatalog` defaults, and the plan says so in a review note.

## Review notes — what a template does not decide for you

`preview` returns `review: [{code, message}]` (translated, `lang/*/onboarding.php`),
and the review step will not apply until the admin ticks that they have read them.
They cover: leave values to confirm against the applicable law, public-sector
salary scales that ETHR does not load, allowances created off, grade bands that
are illustrative, tax and pension left to the payroll engine, flexible time being
a grace period, seasonal contracts, and the crews a 2-2-2-2 rotation needs.

## What applying creates

`OrganizationProvisioner` writes, create-only and soft-delete aware (an existing
natural-key match is skipped, a trashed one is restored), inside one transaction
together with the tenant's settings, onboarding progress and audit row:

| section | records | natural key | notes |
|---|---|---|---|
| branches, departments, positions, grades | as before | name / title | |
| shifts | `shifts` | name | `crosses_midnight` derived; days normalised to ISO |
| rotations | `shift_rotations` + steps | name | skipped with a warning if a step names a shift that does not exist |
| leave_types | `leave_types` | code | values from `LeaveTypeCatalog` |
| allowances | `payroll_rules` (`category = allowance`) | name | **always inactive, amount 0** — an active allowance is paid to every employee |
| holidays | `holidays` | date | this year and next |
| attendance | `attendance_settings` | per field | a field is set only while it still holds its default |
| settings | `tenants.settings` | top-level key | adds `fiscal_year_start_month`, `pagumen_proration_strategy`, `working_days` — the keys the runtime reads |

The tenant records `settings.configuration_profile` = `{industry,
workforce_profile, policy_profile, catalog_version, applied_at}`. Records carry no
per-row origin; this plus the `onboarding.configuration_applied` audit row is the
history.

**What a template never does:** set an employee's salary, assign shifts, create
contracts, run or approve payroll, issue payslips, change tax brackets or pension
rates, or change a value the tenant has already set. A newer catalogue reaches an
existing tenant only by re-applying, which adds what is missing and rewrites
nothing.

## What templates cannot configure (no module exists)

Multi-step approval workflows (approval is single-step and permission-based),
employee onboarding and offboarding checklists, performance cycles, training,
expense categories, recruitment stages (only vacancies exist), salary structures
(allowances are tenant-wide), document categories (a fixed list), split shifts and
on-call. A template proposes none of these rather than manufacturing empty
configuration.

## Managing what a template created

| record | list/view | create/edit | duplicate | deactivate | delete |
|---|---|---|---|---|---|
| shift | ✓ | ✓, with **usage preview** (`GET /shifts/{id}/usage`) | ✓ `POST /shifts/{id}/duplicate` (never default, unassigned) | `is_active` | refused (409) while assigned now or later, or in a rotation |
| rotation | ✓ | ✓ | — | `is_active` | refused (409) while assigned |
| leave type | ✓ | ✓ | ✓ `POST /leave-types/{id}/duplicate` (starts inactive) | `is_active` | refused (409) while any request or balance uses it |
| allowance | ✓ | ✓ | — | `is_active` | allowed (history is in each payroll entry's `calculation_log`) |
| department, branch, position | ✓ | ✓ | — | `is_active` | refused (409) while current employees are assigned |

Editing a shift changes how future punches are rated. Attendance already recorded
keeps its status and approved payroll is never recalculated.

## Endpoints

| method | path | purpose |
|---|---|---|
| GET | `/api/v1/templates` | the 8 base templates (public) |
| GET | `/api/v1/onboarding/industries` | industries, workforce profiles, policy profiles, catalogue version |
| POST | `/api/v1/onboarding/configuration/preview` | scored plan with review notes (read-only); accepts `workforce_profile`, `policy_profile` |
| POST | `/api/v1/onboarding/configuration/apply` | provision an edited plan; records the profiles |
| POST | `/api/v1/shifts/{shift}/duplicate` | copy a shift |
| GET | `/api/v1/shifts/{shift}/usage` | who an edit would reach |
| POST | `/api/v1/leave-types/{leaveType}/duplicate` | copy a leave type |
