# Workforce metric definitions

What each figure on the executive dashboard and the analytics pages counts,
written from `api/app/Services/Analytics/ExecutiveDashboardService.php`. A
figure that disagrees with this page is a defect in one of the two.

Added 2026-10-20 (blueprint assessment RP18). Before then no metric had a
written definition, and three of them, headcount, the headcount trend and
turnover, each counted people differently.

## Employed on a day

The one definition the headcount figures share. Someone is employed on a day
when:

- they were hired on or before it (or have no hire date), and
- they have not left: no termination date, or a termination date on or after
  that day.

Suspended staff are still employed. A leaver whose termination date was never
recorded is not counted, because when they left is unknown.

## Headcount

| Figure | Counts |
|---|---|
| **Active** | Employees whose status is hired, probation or confirmed today. Excludes suspended staff |
| **Total** | Employees employed today (above). Includes suspended staff, excludes everyone who has left |
| **By department** | Active employees per department |
| **Headcount trend** | For each of the last twelve months, employees employed on the month's last day |

## Turnover

| Figure | Counts |
|---|---|
| **Exits** | Employees resigned, terminated or retired whose termination date falls in the selected period |
| **Rate** | Exits ÷ the average of the headcount employed on the period's first day and on its last day, as a percentage |

A leaver is counted in the period they left, not when their record was last
edited.

## Hires

**Workforce growth** counts employees by the month of their hire date, for the
last six months.

## Attendance

| Figure | Counts |
|---|---|
| **Today** | Employees with at least one attendance record today ÷ active employees |
| **Period** | Attendance records in the period ÷ (active employees × weekdays in the period) |

Voided records, the losing copies of merged duplicate punches, are never
counted.

**Known limit:** the period rate divides by weekdays (Monday to Friday), not
by each employee's own working days, shifts or the organisation's holidays.
For a six-day or shift-based workforce it overstates attendance.

## Leave utilisation

All leave balances for the current year: days used ÷ days entitled, as a
percentage. Entitlement includes days accrued so far this year, so early in
the year the rate reads high.

## Exports

Report and payroll register CSVs start with a UTF-8 byte-order mark, so Excel
opens them as UTF-8 and Amharic text reads correctly. Bank transfer files,
accounting journals and statutory schedules do not, because the systems that
import them may refuse the mark.
