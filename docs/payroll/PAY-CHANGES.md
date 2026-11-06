# Deliberate pay changes

Every change to the payroll engine that is meant to move a figure on a
payslip is recorded here, with who it affects and the owner decision behind
it. A change to pay that is not in this register is a regression.

How it is kept honest: `api/tests/Feature/Payroll/PayrollCharacterisationTest.php`
pins what the engine paid before the payroll upgrade (2026-10-27) in the
cases the upgrade touches, C1 to C6. Those figures were obtained by running
the engine, so they record behaviour, not correctness. Each case names the
upgrade allowed to change it. When that upgrade lands, the case is flipped in
the same commit and the change is entered below.

The golden payslips G1 to G7 in `PayrollGoldenPayslipsTest` are written out
by hand from the law and do not move at all.

| Date | Change | Calculation log | Who is affected | Owner decision | Case |
|---|---|---|---|---|---|
| 2026-10-27 | Every amount is an exact fraction of cents, rounded once, half away from zero (`App\Support\Cents`), instead of a float passed to `round()` | 1.4 | Nobody measured: G1 to G7 and C1 to C6 are unchanged to the cent. A float could land a hair either side of a half, so a rare payslip may move by one cent | Payroll upgrade plan, upgrade 1 | none |
| 2026-11-03 | Overtime is paid at the contract's hourly rate: monthly basic over `payroll.working_days_per_month` × `payroll.hours_per_day`, before proration, unpaid leave and lateness. It was taken from the basic after those, so an hour of overtime was worth less in a short month | 1.6 | Anyone with overtime who joined or left during the period, took unpaid leave, or had lateness deducted: their overtime rises by the share those took off basic. Everyone else is unchanged | Payroll upgrade plan, upgrade 5 (decision 3) | C1, C2 moved; golden G8 added |
| 2026-11-04 | A fixed allowance is paid for the days of the period the person was employed, as basic is: a hire on the 25th of a 31-day month gets 7/31 of it. It was paid in full. Percentage allowances already followed prorated basic; unpaid leave reduces neither | 1.5 (no new field: each allowance line's `amount_cents` is the prorated amount) | Anyone who joined or left during a period while a fixed allowance applied: that allowance falls to their share of the period. Everyone else is unchanged | Owner decision 2026-11-04 (A-Z review D-F3) | none moved; new test in `PayrollAllowanceTest` |
| 2026-11-04 | Unpaid leave that runs past the period edge counts the person's own scheduled working days (rotation, then shift, then the organisation's week) instead of the organisation's week | unchanged | People whose schedule differs from the organisation's week, with unpaid leave crossing the period start or end: a Saturday shift is now deducted for Saturdays, a four-day shift no longer for its rest day. Leave inside the period was already deducted by the request's own day count, which now uses the same rule when filed | A-Z review E-06, decided 2026-11-04 | none: G1 to G8 and C1 to C6 have no such leave |
| 2026-11-06 | Sick leave follows Labour Proclamation Art. 85: within twelve months of the first day of sickness, the first month is paid in full, the next two at half the daily rate and the last three not at all; a request past six months is refused. A month is `payroll.working_days_per_month` of the person's working days, so a month of sick leave costs exactly a month's basic. It was one paid bucket of 30 days | 1.7 (new step `sick_leave`) | Anyone with more than a month of sick leave within twelve months: their pay falls by half the daily rate per half-pay day and the full rate per unpaid day. Sick leave of a month or less is unchanged, as is everyone else | A-Z review E-08, decided 2026-11-04; the counting rules (working days, the period anchored on the first sick day) want the accountant's confirmation | none: G1 to G8 and C1 to C6 have no sick leave; `SickLeaveArt85Test` added |

## Also in calculation log 1.4

- `rounding: integer_half_up` at the top of the log.
- The income-tax step's `rule` carries `brackets`, the whole ladder applied.
  Retro pay (upgrade 12) re-taxes a past month from its own log, and a
  tenant's ladder could be deleted until upgrade 4 kept its history.

## Also in calculation log 1.5 (2026-10-27)

- `statutory_rules`: every statutory parameter applied, the organisation type it was
  resolved for, and for each its value, source (`platform`, `platform_type`, `tenant`,
  `tenant_setting`), legal reference, whether confirmed, and the row it came from.
- No figure moved: the parameters were seeded with the values the constants held, and
  G1 to G7 and C1 to C6 are unchanged.

## Also in calculation log 1.7 (2026-11-06)

- A `sick_leave` step after `unpaid_leave`: `half_pay_days`, `unpaid_days`, `daily_rate_cents`, `deduction_cents`
  and `basic_after_sick_leave_cents`. Art. 85 leave types are left out of `unpaid_leave`, so no day is deducted
  twice whatever the type's paid flag.

## Also in calculation log 1.6 (2026-11-03)

- The overtime step carries `hourly_rate_base_cents`, the monthly basic its hourly rate was
  taken from, so a payslip's overtime can be re-derived from its own log.
