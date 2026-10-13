# Payroll — accountant sign-off pack

**Status: prepared for review, not yet signed.** ETHR's payroll follows the rules below in code
and is tested against the worked examples to the cent, but no accountant has yet checked it
against real payslips. That review is plan owner action O2. This document is what the
reviewer reads, checks and signs.

What ETHR does **not** claim: that the rules below are the law in force. The tax schedule
was taken from secondary sources, and a confirmation letter to the Ministry of Revenues is
drafted but not sent (`docs/external/ERCA_INCOME_TAX_QUERY.md`). The reviewer's signature is
what turns "implemented as understood" into "checked".

## 1. The rules as implemented

**Monthly employment income tax — Proclamation 1395/2025, from 7 July 2025**
(`api/app/Services/Payroll/TaxCalculator.php`, `CURRENT_BRACKETS`):

| Taxable income (ETB / month) | Rate | Quick deduction (ETB) |
|---|---|---|
| 0 – 2,000 | 0% | 0 |
| 2,000.01 – 4,000 | 15% | 300 |
| 4,000.01 – 7,000 | 20% | 500 |
| 7,000.01 – 10,000 | 25% | 850 |
| 10,000.01 – 14,000 | 30% | 1,350 |
| over 14,000 | 35% | 2,050 |

Tax = taxable × rate − quick deduction, rounded to the cent. A period before 7 July 2025 is
taxed on the Proclamation 979/2016 ladder (`SUPERSEDED_BRACKETS`), so re-running an old
period reproduces what was withheld then. A tenant can store its own effective-dated ladder
(Payroll → Tax brackets); it takes precedence.

**Pension** (`PensionCalculator`): employee 7% and employer 11% of **basic salary after
proration and unpaid leave**. Allowances and overtime are not pensionable.

**Order of operations for one employee** (`PayrollEngine`; every step is stored in the
entry's `calculation_log` and shown on the run page, calculator icon on each row):

1. Basic salary, prorated for a mid-period hire or termination.
2. Pagume: `full_month` (the 5–6 days paid as part of the month) or `daily_rate`.
3. Unpaid leave: monthly basic ÷ 22 working days × unpaid days, deducted from basic.
4. Overtime: hourly rate = basic ÷ 22 ÷ 8, times the rate for the kind of hour (minimums
   1.5 normal, 1.75 night, 2.0 rest day, 2.5 public holiday — a tenant may set higher).
5. Allowances: fixed amounts or a percentage of basic; each marked taxable or not.
6. Gross = basic + overtime + allowances.
7. Taxable income = gross − non-taxable allowances. Income tax on that.
8. Pension on basic (step 3's figure).
9. Loan repayments and cost sharing.
10. Net = gross − income tax − employee pension − loans − cost sharing.

## 2. Worked examples

October 2026, no proration, no Pagume day in the period. These are the figures
`api/tests/Feature/Payroll/PayrollGoldenPayslipsTest.php` asserts the engine produces —
written by hand, not obtained from the engine.

| | Basic | Allowances | Gross | Taxable | Income tax | Pension 7% | Net | Employer 11% |
|---|---|---|---|---|---|---|---|---|
| G1 | 1,800.00 | — | 1,800.00 | 1,800.00 | 0.00 | 126.00 | 1,674.00 | 198.00 |
| G2 | 3,500.00 | — | 3,500.00 | 3,500.00 | 225.00 | 245.00 | 3,030.00 | 385.00 |
| G3 | 6,000.00 | — | 6,000.00 | 6,000.00 | 700.00 | 420.00 | 4,880.00 | 660.00 |
| G4 | 9,000.00 | — | 9,000.00 | 9,000.00 | 1,400.00 | 630.00 | 6,970.00 | 990.00 |
| G5 | 12,000.00 | — | 12,000.00 | 12,000.00 | 2,250.00 | 840.00 | 8,910.00 | 1,320.00 |
| G6 | 25,000.00 | — | 25,000.00 | 25,000.00 | 6,700.00 | 1,750.00 | 16,550.00 | 2,750.00 |
| G7 | 8,000.00¹ | 1,400.00² | 9,400.00 | 8,800.00 | 1,350.00 | 560.00 | 7,490.00 | 880.00 |

¹ Contract basic 8,800; two days of unpaid leave at 8,800 ÷ 22 = 400 a day.
² Transport 600 (not taxable) + position allowance 10% of 8,000 = 800 (taxable).

Each tax figure, by the cumulative method: G4 = 15% × 2,000 + 20% × 3,000 + 25% × 2,000 =
300 + 600 + 500 = 1,400. G6 = 300 + 600 + 750 + 1,200 + 35% × 11,000 = 6,700.

## 3. Evidence for a real run

For a processed run, the run page offers:

- **Payroll register** (CSV): per employee basic, overtime, allowances, gross, taxable,
  income tax, both pension shares, loan, cost sharing, net — and a totals row. Its totals
  equal the run's totals and the accounting journal's lines (tested).
- **Calculation**, per employee: the stored steps, from basic to net.
- **Journal** (accounting export): salary expense, employer pension expense, tax payable,
  pension payables, net salary payable.

## 4. Checklist to sign

| # | Check | Reviewer | Date | Result |
|---|---|---|---|---|
| 1 | The tax ladder in §1 is the schedule in force for the period reviewed | | | |
| 2 | Pension rates and base (basic salary only) are correct for this organisation's sector | | | |
| 3 | The worked examples in §2 are correct | | | |
| 4 | For one real run, three payslips recomputed by hand match ETHR to the cent | | | |
| 5 | The register's totals agree with the journal and with the bank file's total | | | |
| 6 | Allowance tax treatment matches the organisation's policy and the exemption limits | | | |
| 7 | Overtime rates, the 22-day month and the 8-hour day suit this organisation | | | |
| 8 | Pagume treatment (`full_month` or `daily_rate`) is the one the organisation uses | | | |

Signed: ____________________ (name, qualification) Date: ____________

## 5. Not covered yet

- Statutory remittance schedules (monthly employment tax declaration, pension contribution
  schedule) — plan Wave 2 #8.
- Bank-specific transfer formats — need each bank's file specification (owner action O10).
- Government salary scales — civil-service payroll rules are set by the civil service
  commission and are not loaded (see the organisation template review notes).
