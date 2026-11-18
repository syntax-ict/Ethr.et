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

There is one `attendance_records` row per work day, and a second one only for a second session (below). Each punch that touches the row is appended to `metadata.punches[]` as `{kind, source, at, outcome}`, the last 30 kept. The outcome is `created`, `merged`, `deduped`, `flagged`, `rejoined` (a return from a break), `applied` (a check-out) or `duplicate`. `AttendanceRecordResource` exposes the list as `punches`.

## A break inside the day

A person who works two sessions (in 08:50, out 12:05, in 13:55, out 17:00) has one record. A live check-in **reopens** the person's latest closed, non-voided record of the same work day (`AttendanceEngine::rejoinDay()`) when all of these hold:

- the check-out is earlier than the check-in, and the gap is at most `AttendanceRules::MAX_BREAK_MINUTES` (180);
- the check-in is before the record's shift end (a record with no shift: the gap alone decides);
- the day is not in an approved or paid payroll run (`PayrollPeriodLock`);
- the source is live: a manual or imported entry never reopens anything.

The old check-out becomes `metadata.breaks[]` `{type: out}` and the return `{type: in}`, each with the key of its punch; `check_out` is cleared; an `early_leave` caused by the morning's check-out goes back to `present` (`late` stays `late`); the last check-out rates early leave as for any day. The answer is outcome `resumed` ("Welcome back"), the audit entry is `attendance.day_rejoined`, and `attendance.checked_in` is sent. The shift's fixed `break_minutes` are still subtracted once in worked time; the measured gap is stored, not paid. A terminal that resends the morning's check-out finds its key in the break and is answered as a duplicate; it does not close the reopened day.

Anything outside those bounds (an evening return, a gap over three hours) is a second session as below.

## Duplicates

| Case | Result |
|---|---|
| Same idempotency key (a retry, an offline resend) | The original record is returned. |
| Check-in from any method within 5 minutes of another (`AttendanceRules::MERGE_WINDOW_MINUTES`) | `ConflictResolver` merges them by source priority. Under 1 minute from the same source, it dedupes. |
| Check-in after a check-out on the same day, within 180 minutes and before the shift's end | The day's record is reopened with a break noted (see above). No second record, no conflict. |
| Check-in after the shift's end, or more than 180 minutes after the check-out, or from a manual or imported entry | A second record, flagged as an `AttendanceConflict` for HR. |
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
