# Statutory schedules

Added 2026-10-14 (gap 4, Wave 2 #8). Each payroll run exports the two monthly schedules
an Ethiopian employer files:

| Schedule | Filed with | Per employee | Totals |
|---|---|---|---|
| **Employment income tax** | Ministry of Revenues, at the employer's tax centre | TIN, name, code, start date, basic salary, overtime, allowances, taxable income, tax withheld | Equal to the run's `tax_total_cents` |
| **Pension contributions** | POESSA (private employers) or PSSSA (public bodies) | TIN, name, code, basic salary, employee 7 %, employer 11 %, total 18 % | Equal to the run register's pension columns |

Download them from the payroll run page: **Export → Statutory schedules**, as CSV or PDF.
The PDF is A4 landscape, with lines for *prepared by*, *checked by* and *authorised
signature and stamp*.

## Where the figures come from

Every figure comes from the run register (`RunRegisterService`). The register reads
the stored entries and their immutable calculation logs (convention #11) and never
recomputes them, so a schedule shows what was actually withheld.
`StatutorySchedulesTest` asserts the totals against the golden payslips
([`ACCOUNTANT-SIGN-OFF.md`](ACCOUNTANT-SIGN-OFF.md)).

- An employee with no pension on the run (exempted by the engine) is left off the
  pension schedule.
- A run that is not approved prints **NOT APPROVED — draft figures** on the schedule.

## The header

The header comes from **Settings → Payroll → Statutory filing**: employer TIN (10 digits),
tax centre, and pension scheme (private → POESSA, public → PSSSA). These are stored in
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
face: *"Layout to be confirmed with … before filing; the figures are the payroll's
own."* Closing this is owner action O3: send the drafted letters, then adjust the
columns to the forms they return. The figures will not change, only their arrangement.

Not covered yet:

- Direct e-filing to the MoR e-tax portal or the pension agencies' systems. Neither
  offers an integration API we know of.
- Withholding tax on non-employment payments.
- The annual reconciliation.
