# Attendance pipeline: one person, one day, several methods

An employee can punch by web, QR, kiosk, biometric device, phone (online or offline) or have HR enter the day. All of it lands on one timeline per person and day.

## One entry point

Every method calls `AttendanceEngine::record()` (`api/app/Services/Attendance/AttendanceEngine.php`). That includes web and mobile controllers, QR, kiosk and kiosk sync, offline sync, device webhooks and pull, HR's manual entry and approved missed-punch claims (through `EnteredAttendance`), and the CSV import (since 2026-11-06, also through `EnteredAttendance`, source `csv`).

In order, the engine does the following:

1. Looks up the idempotency key and replays the original record.
2. Reads the person's rules (`AttendancePolicy`): the organisation's, the branch's overrides and the attendance mode.
3. Refuses a method the rules do not allow, a self-service punch from an unregistered phone under `device_binding=enforce`, and a punch after the person has left.
4. Decides whether the punch is a check-in or a check-out.

Punches for one person are taken one at a time, under the cache lock `attendance-punch:{tenant}:{employee}`. A lock that is not obtained within 8 seconds lets the punch through unserialised.

## One timeline

The day key is the shift's work date (`ShiftMatcher::forPunch()`). A night shift is dated the night it began, and `findOpenRecord()` looks back one day to close it.

There is one `attendance_records` row per session. Each punch that touches the row is appended to `metadata.punches[]` as `{kind, source, at, outcome}`, the last 30 kept. The outcome is `created`, `merged`, `deduped`, `flagged`, `applied` (a check-out) or `duplicate`. `AttendanceRecordResource` exposes the list as `punches`.

## Duplicates

| Case | Result |
|---|---|
| Same idempotency key (a retry, an offline resend) | The original record is returned. |
| Check-in from any method within 5 minutes of another (`AttendanceRules::MERGE_WINDOW_MINUTES`) | `ConflictResolver` merges them by source priority. Under 1 minute from the same source, it dedupes. |
| Check-in from another method hours later | A second record, flagged as an `AttendanceConflict` for HR. |
| Check-out, or an `auto` punch, within 5 minutes of the day's check-out | A duplicate: no new record. |
| Check-out with nothing open and nothing just closed | Refused: `NoOpenCheckIn`. |

## Rules checked per punch

- **Method allowed:** `AttendanceSetting::isMethodEnabled()`.
- **Shift, grace, late and early leave:** `ShiftMatcher` and `AttendanceEngine::reclassify()`.
- **Geofence, device binding, photo, liveness and risk:** `IntegrityInspector`, `AttendanceRiskScorer` and `ConfidenceScorer`. A geofence refusal applies to the mobile source only. Other sources are flagged.
- **Corrections:** through the approval engine (`CorrectionDecisionService`).

## Hand-off to payroll

`PayrollEngine` reads `AttendanceRecord` rows that are not voided. `AttendanceReconciliation` lists the open exceptions for a pay period: missed punches, auto-checkouts, lateness, unexplained absences, unapproved overtime, integrity flags and unresolved conflicts. Each item that comes from a record carries its `source`.

The organisation's `reconciliation_mode` is `off`, `advisory` or `required`. Under `required`, the run cannot be approved while an item is open. `PayrollPeriodLock` stops corrections, manual entry, conflict resolution and import on paid days. Machine ingestion (devices, kiosk, offline sync) is not locked.

An open integrity flag does not by itself keep a record out of payroll. The gate for that is `reconciliation_mode=required`.
