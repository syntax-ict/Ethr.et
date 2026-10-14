# Statutory schedules

Added 2026-10-14 (gap 4, Wave 2 #8). Each payroll run exports the two monthly schedules
an Ethiopian employer files:

| Schedule | Filed with | Per employee | Totals |
|---|---|---|---|
| **Employment income tax** | Ministry of Revenues, at the employer's tax centre | TIN, name, code, start date, basic salary, overtime, allowances, taxable income, tax withheld | Equal to the run's `tax_total_cents` |
| **Pension contributions** | POESSA (private employers) or PSSSA (public bodies) | TIN, name, code, pensionable salary, employee and employer contributions and their total, each headed with the rate the run applied | Equal to the run register's pension columns |

Download them from the payroll run page: **Export → Statutory schedules**, as CSV or PDF.
The PDF is A4 landscape, with lines for *prepared by*, *checked by* and *authorised
signature and stamp*.

## Where the figures come from

Every figure comes from the run register (`RunRegisterService`). The register reads
the stored entries and their immutable calculation logs (convention #11) and never
recomputes them, so a schedule shows what was actually withheld.
`StatutorySchedulesTest` asserts the totals against the golden payslips
([`ACCOUNTANT-SIGN-OFF.md`](ACCOUNTANT-SIGN-OFF.md)).

- **Who is on the pension schedule follows the rule data**: an employee the run's
  pension rule exempted (`exempt` in the entry's calculation log — a non-national, or
  under a rule's minimum service) is left off. A run calculated before exemptions were
  logged falls back to "no pension was taken".
- **Rates in the column headers are the rates the run applied**, read from the logs —
  never a fixed "7 %" or "11 %". The register CSV, the payslip and the calculation view
  do the same. An entry from before rates were logged shows no rate rather than a
  guessed one.
- A run that is not approved prints **NOT APPROVED — draft figures** on the schedule.

## Rules, layouts and the basis line (2026-10-14)

Nothing statutory is written in code any more
([`STATUTORY-RULES-RESEARCH.md`](STATUTORY-RULES-RESEARCH.md)):

- **Pension rates** are dated rows in `pension_rules`, per scheme (`private`, `public`,
  `public_military`), with the law each comes from. The platform rows are seeded from the
  research. An organisation can add its own in **Settings → Payroll → Pension rules**
  (`payroll.manageConfig`; the legal source is required). An organisation's rule replaces
  the platform's for its scheme from its date. 7 % / 11 % remains only as the fallback
  for a database with no rule, as the tax ladder's hard-coded fallback does.
- **Tax ladders** carry their legal reference (979/2016, 1395/2025). Their values and
  dates are unchanged.
- **The rule each entry used is copied into its calculation log** (rates, ceiling,
  base, exemptions, legal reference, effective date, platform/organisation/fallback),
  so an approved run keeps saying what it applied after the rule changes (convention #11).
- **Agency names, titles and column layouts** are dated rows in `statutory_layouts`. A
  new form version is added from the platform console API (`POST /admin/statutory-layouts`,
  `admin.manage`) and applies from its date — no deploy. A label may use
  `{employee_rate}`, `{employer_rate}` and `{total_rate}`. There is no console screen for
  this yet; the API is the interface.
- **Every schedule states the rules it was built on**, in CSV and PDF, for example:
  *"Pension: Proclamation No. 1268/2022, Art. 10, effective 18/03/2022; Tax: Income Tax
  (Amendment) Proclamation No. 1395/2025, effective 07/07/2025"*. The pension scheme and
  agency are the ones the run applied, not today's setting.

## Nationality decides coverage — as a fixed choice (2026-10-14)

Pension covers Ethiopian nationals (research P8). That used to be read from the free-text
`nationality`, so a typo ("Ethiopean", "Ethiopian national") exempted an Ethiopian silently,
and the employer then owes the contributions plus 5 % a month (P12). Now:

- **`nationality_status`** — `ethiopian`, `ethiopian_origin` (a foreign national of
  Ethiopian origin, Proclamation 270/2002) or `foreign` — is the only thing
  `PensionCalculator` reads. `nationality` stays for the country name. `ethiopian_origin`
  is exempt under its own reason, `ethiopian_origin_unconfirmed`, until P8 is settled.
- **Existing rows** were mapped once (`NationalityBackfill`): blank or a known Ethiopian
  spelling → `ethiopian`; anything else → `foreign` **and flagged** (`nationality_needs_review`).
  Employees → *Check nationality* lists them until someone saves their status. The local demo
  data (152 employees, all "Ethiopian") flagged none.
- **Forms, API and import** take the status. The import accepts it as people write it
  ("Ethiopian origin", "FOREIGN"), takes a blank as `ethiopian`, and refuses anything else.
- **Before approval** the run page lists every pension-exempt employee with the reason,
  linked to the profile, and approval needs the count confirmed; the API refuses
  `PUT …/approve` unless `acknowledged_pension_exemptions` equals it.

Not covered: a per-employee pension scheme (military and police at 33 % inside a mixed
public body). The scheme is still the organisation's.

## The header

The header comes from **Settings → Payroll → Statutory filing**: employer TIN (10 digits),
tax centre, and pension scheme (private → POESSA, public → PSSSA civil service,
public — military and police → PSSSA military and police fund). These are stored in
the organisation settings as `employer_tin`, `tax_center` and `pension_scheme`.
Without an employer TIN the schedule still downloads, with **NOT SET** in its place,
and the run page warns before the download.

## TINs

Employee TINs are encrypted at rest and appear **only in the downloads**:

- `GET /payroll/runs/{run}/statutory/{income-tax|pension}` returns the header, totals,
  employee count and the names of employees with no TIN. It is the check before filing,
  and the run page shows those names as a warning.
- `GET …/download?format=csv|pdf` returns the file with every TIN. Each download writes
  a `payroll.statutory_export` audit row.

Both need `payroll.viewAll`, the same as the register and the bank file. A TIN that
cannot be decrypted (a database restored without its `APP_KEY`) is reported as missing,
and the rest of the schedule still downloads.

## What is not settled

**The column layout is ETHR's, not yet the authorities'.** Neither MoR nor the pension
agencies publish a machine-readable format we could find. Every schedule says so on its
face: *"Layout prepared by ETHR; to be confirmed with the agency before filing."* Closing this is owner action O3: send the drafted letters, then adjust the
columns to the forms they return. The figures will not change, only their arrangement.

Not covered yet:

- Direct e-filing to the MoR e-tax portal or the pension agencies' systems. Neither
  offers an integration API we know of.
- Withholding tax on non-employment payments.
- The annual reconciliation.
