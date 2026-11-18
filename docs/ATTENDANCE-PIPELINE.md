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

## Presence checks: seeing the middle of the day

Punches prove the two ends of the day. A presence check looks at the middle of it: up to one, two or three times a shift the server sends "Confirm you are at work" to a checked-in office employee's phone, at a moment the employee cannot predict, and the employee answers from where they stand. The code is `App\Services\Attendance\PresenceChecks`, the answer is `POST /api/v1/attendance/presence/confirm` (`AttendancePresenceController`), and the phone side is a card on `/attendance/mobile`.

**The setting.** `attendance_settings.presence_checks_per_shift` is 0 (off, the default) to 3, overridable per branch like the other rules in `AttendancePolicy::OVERRIDABLE`. The answer window is a constant, `AttendanceRules::PRESENCE_RESPONSE_MINUTES = 15`. There is no feature switch of its own: `Feature::WebPush` stops every push, presence checks included, for the platform or one organisation.

**Who is checked.** Someone who is checked in on an office-mode working day with a shift, who has at least one place to be measured against (`AttendancePlaces::for()`: the branch's circle or an assigned work site), who has no open break, and who has a push subscription on an active account. Remote and field staff, people on leave or a holiday, and people with no place are not asked. Nobody is marked for a message they could not receive: a check no push service accepted is deleted, not counted as missed. `AttendanceHealth` reports `presence_unreachable`, the people the checks cannot reach.

**How times are derived and never stored.** For each record `PresenceChecks::times()` takes `hash_hmac` of the record id and the slot under the app key: one hash picks how many checks the day carries (1 up to the setting), the others pick a minute for each inside the usable window. That window starts 30 minutes after the later of check-in and shift start and ends 30 minutes plus the 15-minute answer window before the shift ends, and leaves out the middle of the shift (half the shift's `break_minutes` plus 15 minutes either side of the midpoint), because shifts carry no lunch time. A shift with less than an hour usable gets none. The same record always has the same times, so a test can compute them; the phone cannot. Nothing is written until a check is sent.

**The five-minute pass.** `PushPunchRemindersCommand` already visits every push subscriber with their shift state. Per user it asks `PresenceChecks::due()`, which returns a slot whose time has been reached, with no unanswered check still open and no row for that slot, and with time left to answer before the shift ends. `send()` creates the row in `attendance_presence_checks` and pushes through `WebPushSender` (tag `presence-check`, time to live equal to the window, `requireInteraction`). The deadline runs from the real send, so a late tick still gives the full 15 minutes. Per organisation the same pass turns expired `sent` rows into `missed` with one `UPDATE` and, once a day, deletes rows older than 90 days. The scheduler is woken by an external caller and can run late: checks go out late, never early.

**The answer.** The phone sends its position, the punch signals and, where device binding is on, the passkey proof, through the same place-and-phone step as a field visit (`PlaceProof`, shared with `AttendanceVisitController`). It answers the caller's own latest check, so no id travels and there is no cross-organisation parameter; with nothing to answer it is 404. The check's `status` is:

| Status | Meaning |
|---|---|
| `sent` | Waiting for an answer. |
| `confirmed` | Answered in time from inside a workplace. |
| `outside` | Answered in time from outside every workplace. Filed at once. |
| `unverified` | Answered in time from a phone the organisation does not allow (`device_binding=flag`). Under `enforce` the answer is refused with the same problem response a punch gets, and nothing is stored. |
| `missed` | Not answered in 15 minutes. An answer after that is still stored, with its time and place, `answered_at` after `expires_at` and `late: true`, and the status stays `missed`. |

Time decides, by timestamps. Two answers racing have one winner (a conditional `UPDATE`), and a repeat of the same `idempotency_key` returns the first answer.

**Two findings, both in the integrity queue.**

- An `outside` answer files the existing type `outside_geofence` with `punch = 'presence'`, severity high, and details `{asked_at, answered_at, distance_meters, nearest}`.
- `AttendancePatternScan` files `presence_pattern` (`punch = 'pattern'`) when at least two of the person's checks in the last 14 days were missed or unverified and they are at least half of those sent. It is medium, and high from four. Details are `{missed, unverified, total, days}`. One miss files nothing: a flat battery or a tunnel is not a finding.

**What rejecting does and does not void.** `AttendanceIntegrityController::review` voids the day's record only when the flag's `punch` is one of `AttendanceIntegrityFlag::VOIDING_PUNCHES` (`check_in`, `check_out`, `day`), the flags that say a punch cannot be trusted. For `presence` and `pattern` flags the decision and note are recorded and audited, nothing is voided, and no "punch rejected" message is sent. The screen words the decision "Excused" and "Not excused" for those. Nothing is added to the risk score; an open high finding or a rejected one makes `AttendancePolicy::selfieRequired()` ask for a selfie every time, as it already did.

**A deliberate change.** Before presence checks, rejecting any flag voided the day's record, including a `pattern` flag, which is attached to the person's most recent day. Rejecting `buddy_pattern` or `grace_edge` therefore voided whichever day the pattern happened to be attached to, a day that proved nothing. That no longer happens: those two are findings about a pattern, and rejecting one records the decision and leaves every day standing. Voiding a day needs a flag about that day's punch.

**Limits.** Checks reach only people with a smartphone who allowed notifications (on iPhone, with the app added to the Home Screen). A check cannot be answered offline, which is why one miss is not a finding. The middle band is a guess at lunch. Staff who punch only on a terminal are not covered.
