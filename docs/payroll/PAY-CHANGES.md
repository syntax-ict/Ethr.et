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
