# Attendance rules: the register

Every rule the attendance engine applies when it turns punches into a day, in one place, each with an id. This is the specification the accuracy tests are written against ([production testing/12-ATTENDANCE-ACCURACY.md](<production testing/12-ATTENDANCE-ACCURACY.md>)). How a punch travels through the code is in [ATTENDANCE-PIPELINE.md](ATTENDANCE-PIPELINE.md); this file says what the answer must be.

**When a rule changes, change it here first**, then the code, then the golden case that cites its id. Other documents link to a rule id and do not restate its numbers.

## How to read a row

| State | Meaning |
|---|---|
| Decided | A document states it and the code was read to agree. The oracle implements it and a disagreement is a defect. |
| Implemented | The code does it and no document states it. The oracle copies it so that the tests can run, and the accuracy report counts these rules separately: agreement proves the behaviour is stable, not that it was chosen. |
| Undecided | Nobody has said what the answer should be. The oracle does not guess: cases that depend on it are reported as "rule undecided", not as passes or failures. |

Sources: **05** is [production testing/05-E2E-ATTENDANCE-SHIFTS-DEVICES.md](<production testing/05-E2E-ATTENDANCE-SHIFTS-DEVICES.md>), **07** is [production testing/07-E2E-PAYROLL.md](<production testing/07-E2E-PAYROLL.md>), **PIPE** is [ATTENDANCE-PIPELINE.md](ATTENDANCE-PIPELINE.md), **STAT** is [payroll/STATUTORY-PARAMETERS.md](payroll/STATUTORY-PARAMETERS.md), **BASE** is [audit/BASELINE.md](audit/BASELINE.md). A path under `api/` is the code that was read.

All times below are wall-clock times in the organisation's zone unless a row says UTC.

## 1. Clock and dates

| Id | Rule | Source | State |
|---|---|---|---|
| AR-CLOCK-01 | Punch times are stored in UTC and rated in the organisation's zone, `Africa/Addis_Ababa` unless the organisation set another. 09:00 in Addis is 06:00 UTC. | 05 ground rules | Decided |
| AR-CLOCK-02 | A record's date is the work date in the organisation's zone, never the UTC date. A punch at 01:30 is dated that local day. | 05 ATT-008 | Decided |
| AR-CLOCK-03 | The Ethiopian clock and calendar change what a reader sees and types, never what is stored or rated. 08:40 reads 2:40 and 17:35 reads 11:35. | 05 ATT-014 | Decided |
| AR-CLOCK-04 | Every count of minutes is a whole number, cut down, never rounded: 16 minutes 59 seconds is 16. The difference between two moments is cut down, not each moment first: 08:30:40 to 17:30:20 is 8 hours 59 minutes 40 seconds, so 539 minutes. No punch time is rounded to a quarter hour or any other step. | `api/app/Models/AttendanceRecord.php` `workedMinutes()` | Implemented |
| AR-CLOCK-05 | A terminal's time with no zone offset is read in the terminal's own zone, or the organisation's when the terminal has none. A punch more than 10 minutes ahead of the server is refused as `clock_ahead`. | BASE 25y | Decided |
| AR-CLOCK-06 | Whether a window is measured to the second or in whole minutes. Every window in this register is written in minutes (grace, early departure, the 5 minute merge and repeat, the 180 minute break, the automatic check-out minutes) or hours (14, 6); terminals report seconds. Out 10:00:00 and back 13:00:30 is a break of 180 minutes cut down, or of more than 180 to the second. AR-STATUS-07 is this question at the grace boundary. | none | Undecided |
| AR-CLOCK-07 | How seconds are cut when one span is split into kinds of overtime: once for the span, the remainder going to one kind, or once per kind. 17:45:30 to 22:00:40 holds 254 minutes 30 seconds of day time and 40 seconds of night: 255 normal and 0 night by the first reading, 254 and 0 by the second. | none | Undecided |

## 2. Which shift rates the day

| Id | Rule | Source | State |
|---|---|---|---|
| AR-SHIFT-01 | The person's own assignment beats their department's, which beats their branch's. Within a level the latest start date wins. | 05 SHF-009 | Decided |
| AR-SHIFT-02 | With no assignment, the organisation's default rotation applies, then its default shift. | 05 BASE-001, BASE-002 | Decided |
| AR-SHIFT-03 | A rotation's rest day is a rest day. It does not fall through to a department or default shift. | 05 SHF-011 | Decided |
| AR-SHIFT-04 | A shift whose end is not after its start crosses midnight and ends on the next day. | 05 SHF-002 | Decided |
| AR-SHIFT-05 | A punch made before the end of yesterday's midnight-crossing shift belongs to yesterday's work date. | 05 SHF-004; PIPE "One timeline" | Decided |
| AR-SHIFT-06 | With no shift at all the record is `pending`: no late minutes, no early minutes, and no overtime on a working day. | 05 ATT-009, CAP-002 | Decided |

## 3. Status of the day

| Id | Rule | Source | State |
|---|---|---|---|
| AR-STATUS-01 | Present: checked in at or before shift start plus the shift's grace minutes. Late: any later. On 08:30 with 15 minutes of grace, 08:45 is present and 08:46 is late. | 05 ground rules, ATT-002, ATT-003 | Decided |
| AR-STATUS-02 | Grace is the shift's `grace_minutes`. The organisation column `attendance_settings.grace_period_minutes` rates nothing. | 05 BASE-004 | Decided |
| AR-STATUS-03 | Early leave: the day was present and the check-out is before shift end minus the shift's early-departure minutes. On 17:30 with 15, 17:15 is not early and 17:14 is. | 05 ground rules, ATT-005 | Decided |
| AR-STATUS-04 | A late day stays `late` when the person also leaves early. | 05 PUNCH-010; 07 LATE-004 | Decided |
| AR-STATUS-05 | A manual entry and an imported row are rated exactly as a live punch at the same times. | 05 AIMP-002, CAP-001, CAP-002 | Decided |
| AR-STATUS-06 | `absent`, `on_leave` and `holiday` are never stored on a record. They are worked out when read (section 8). | `api/app/Services/Attendance/ExpectedDays.php` | Implemented |
| AR-STATUS-07 | Whether a check-in at 08:45:30 is present or late, with grace ending at 08:45:00. | none | Undecided |
| AR-STATUS-08 | On a day off (a rest day by the person's shift or rotation, or a public holiday of their branch) a record is `present`, never `late` or `early_leave`: there are no scheduled hours to be late for. A day of approved leave is not a day off here (AR-ABS-08). | AR-MIN-06; BASELINE 25bu | Implemented |

## 4. Minutes

| Id | Rule | Source | State |
|---|---|---|---|
| AR-MIN-01 | Late minutes count from shift start, not from the end of grace, and only on a late day. 08:46 on an 08:30 shift is 16, and so is 08:46:59 (AR-CLOCK-04). | 05 ATT-002; 07 LATE-004 | Decided |
| AR-MIN-02 | Early minutes count from the check-out to shift end, only when the check-out is early by AR-STATUS-03. 17:10 against 17:30 is 20. | 05 ATT-005; 07 LATE-004 | Decided |
| AR-MIN-03 | A late day that also ends early missed late plus early minutes: 08:46 to 17:10 is 16 + 20 = 36. | 07 LATE-004 | Decided |
| AR-MIN-04 | Worked minutes are check-out minus check-in, minus the shift's fixed `break_minutes` once, never below zero. The break actually taken is stored and is not what is subtracted. 08:50 to 17:00 with a 60 minute break is 430. | 05 PUNCH-011; PIPE "A break inside the day" | Decided |
| AR-MIN-05 | A record with no check-out has worked minutes 0. A record with no shift subtracts no break. | `api/app/Models/AttendanceRecord.php` `workedMinutes()` | Implemented |
| AR-MIN-06 | Late and early minutes are zero on a day that is not a working day for the person: on the record, in payroll's reconciliation and in a lateness decision, as on the timesheet. Since 2026-11-28 also for a record stored before then, and for a holiday added after the punch. | `api/app/Services/Attendance/TimesheetLedger.php`, `AttendanceEngine::lateMinutes()`; BASELINE 25bu | Implemented |
| AR-MIN-07 | Arriving before shift start is not overtime: on a working day only time after the shift's end counts (AR-OT-01). It earns nothing else either; no document gives it a value. | 07 section 1.3 ("only time after the shift ends counts") | Decided |
| AR-MIN-08 | Whether a day with two records (a second session, AR-BREAK-02) has the shift's break subtracted once per record or once for the day. | none | Undecided |

## 5. One record a day

| Id | Rule | Source | State |
|---|---|---|---|
| AR-DAY-01 | The same idempotency key returns the original record and records nothing. A key answers only for the person whose punch used it. | 05 ground rules, ATT-013 | Decided |
| AR-DAY-02 | A second check-in while the day is open adds nothing ("already checked in"). | 05 PUNCH-008 | Decided |
| AR-DAY-03 | Two check-ins within 5 minutes from different methods are one arrival: the earliest check-in and the latest check-out are kept. Under 1 minute from the same method is a repeat: it adds no record and no time. What the person is told for the second punch (a new punch, or "already checked in") is not stated for either case, so a test asserts the record and not the answer. | PIPE "Duplicates"; 05 CON-002 | Decided |
| AR-DAY-04 | A check-out, or an Automatic punch, within 5 minutes of the day's check-out is a repeat. The first check-out stands. | 05 PUNCH-007 | Decided |
| AR-DAY-05 | A check-out with nothing open and nothing closed in the last 5 minutes is refused. | 05 ATT-006 | Decided |
| AR-DAY-06 | An Automatic punch is a check-in when nothing is open, a repeat within 5 minutes of the check-in, and a check-out otherwise. | 05 PUNCH-004, PUNCH-005 | Decided |
| AR-DAY-07 | A manual or imported entry more than 5 minutes from an existing punch is kept as a second record and flagged as a conflict. | 05 CON-001, AIMP-004 | Decided |
| AR-DAY-08 | Which two punches the 5 minutes of AR-DAY-07 are measured between: the two check-ins only (AR-DAY-03 is about arrivals), or any punch of the entry and any punch of the day. MAN-003 says "an existing punch". | none | Undecided |
| AR-DAY-09 | A check-in begins a new record on its own work date unless a record of that same work date is open (AR-DAY-02). A record still open from an earlier work date does not absorb it: the closing windows of AR-NIGHT-02 are about check-outs. In at 19:00 and never out, then in at 08:25 the next morning: two records, one per work date. | AR-DAY-02, AR-NIGHT-02 | Decided |
| AR-DAY-10 | A person who has left (resigned, terminated or retired) records nothing dated after their termination date in the organisation's zone: the punch is refused, with the reason `employee_has_left`, and makes no record. A punch dated on or before that day still counts, however late it reaches the server. A leaver with no termination date records nothing. | PIPE "One entry point" step 3; 05 ground rules ("a leaver"); the statuses and the reason code from `api/app/Services/Attendance/AttendanceEngine.php` | Decided |

## 6. A break inside the day

| Id | Rule | Source | State |
|---|---|---|---|
| AR-BREAK-01 | A live check-in after a check-out on the same work date reopens the day when the gap is at most 180 minutes and the check-in is before the shift's end. The day is then one record from the first check-in to the last check-out. | PIPE "A break inside the day"; 05 PUNCH-011 | Decided |
| AR-BREAK-02 | A return after the shift's end, or after more than 180 minutes, is a second record and a conflict. | 05 PUNCH-012 | Decided |
| AR-BREAK-03 | After a return, an `early_leave` caused by the morning check-out goes back to `present`; `late` stays `late`; the last check-out decides early leave. | PIPE "A break inside the day" | Decided |
| AR-BREAK-04 | With no shift, the 180 minute gap alone decides. | PIPE "A break inside the day" | Decided |
| AR-BREAK-05 | A day in an approved or paid payroll run is never reopened. | PIPE "A break inside the day" | Decided |

## 7. Night shifts, closing a day, automatic check-out

| Id | Rule | Source | State |
|---|---|---|---|
| AR-NIGHT-01 | A night shift's record is dated the night it began. A 00:30 check-in on a 22:00 shift is late and dated the day before. | 05 SHF-004 | Decided |
| AR-NIGHT-02 | Yesterday's open record can be closed within 14 hours of its check-in, or, for a shift that crosses midnight, until 6 hours after the shift's end. | 05 SHF-013; `api/app/Support/AttendanceRules.php` | Decided |
| AR-NIGHT-03 | An Automatic punch closes yesterday's record only until 6 hours after its shift's end. Later, it is a new arrival. | 05 SHF-013 | Decided |
| AR-NIGHT-04 | A manual or imported check-out earlier on the clock than the check-in is the next morning. A span of 14 hours or more is refused as a mistyped time. | 05 MAN-002, AIMP-002 | Decided |
| AR-AUTO-01 | With automatic check-out on, a record still open once shift end plus the setting's minutes (default 120) has passed is closed at the shift's end, not at the time the job ran. | 05 AUTO-001 | Decided |
| AR-NIGHT-05 | Exact boundaries the documents do not settle: whether exactly 14 hours after the check-in, or exactly 6 hours after the shift's end, is still inside the closing window (AR-NIGHT-02, AR-NIGHT-03); whether a check-in exactly at the shift's end is "after" it (AR-AUTO-02); and what closes yesterday's record when it has no shift, so no shift end. | none | Undecided |
| AR-AUTO-02 | A check-in made after the shift's end, or more than 2 days old, is not closed automatically. | 05 AUTO-002 | Decided |
| AR-AUTO-03 | The person's own later check-out replaces an automatic one. | BASE 25ag | Decided |

## 8. Expected days and absence

| Id | Rule | Source | State |
|---|---|---|---|
| AR-ABS-01 | Each day is classed in this order: not employed (before the hire date or after the termination date), holiday, rest day, on leave (approved leave), workday. | [BLUEPRINT-ASSESSMENT-2026-10-20.md](audit/BLUEPRINT-ASSESSMENT-2026-10-20.md); `api/app/Services/Attendance/ExpectedDays.php` | Decided |
| AR-ABS-02 | A rest day comes from the person's rotation, else their shift's working days, else the organisation's working week (Monday to Friday unless set). Saturday and Sunday are never assumed. | 05 SHF-011, ABS-003 | Decided |
| AR-ABS-03 | A holiday set for one branch applies to that branch's people only. | 05 HOL-003 | Decided |
| AR-ABS-04 | Absent: a workday that has passed (up to yesterday in the organisation's zone) with no record that is not voided and holds a punch. | 05 ABS-001, ABS-003 | Decided |
| AR-ABS-05 | An organisation with no attendance recorded in the period has no absences. | BLUEPRINT-ASSESSMENT 2026-10-24 note | Decided |
| AR-ABS-06 | Payroll lists absences and deducts nothing for them until a person settles the day. | 07 RCN-006 | Decided |
| AR-ABS-07 | Half a day of approved leave makes the whole day on leave. | none | Undecided |
| AR-ABS-08 | How a record on a day of approved leave is rated: as a working day (late, early, overtime from the shift's end) or as a day off (the whole span overtime, as AR-OT-02). | none | Undecided |

## 9. Overtime

| Id | Rule | Source | State |
|---|---|---|---|
| AR-OT-01 | On a working day, overtime is the time worked after the shift's end: from the later of the shift's end and the check-in, to the check-out. 08:40 to 19:00 on a shift ending 17:30 is 90 minutes. A record checked in at 17:45 and out at 20:00 on that shift is 135, not 150: the quarter hour before the check-in was not worked. Each record counts its own; a day with two records adds them. | 07 section 1.3 ("only time after the shift ends counts"), PAY-031 | Decided |
| AR-OT-02 | On a rest day or a public holiday, the whole span from check-in to check-out is overtime, with no break subtracted. | 07 section 1.3 | Decided |
| AR-OT-03 | The night window runs from 22:00 up to, not including, 06:00: the minute 22:00 to 22:01 is night and the minute 06:00 to 06:01 is not. It applies on every calendar day an overtime span touches. On a working day, minutes inside it are night minutes and the rest are normal; on a holiday they are holiday night minutes and the rest are holiday minutes. | STAT `overtime.night_window` | Decided |
| AR-OT-11 | Overtime on a day takes the day's class (AR-ABS-01): a holiday that falls on a rest day is paid as a holiday. | AR-ABS-01, 07 section 1.3 | Decided |
| AR-OT-12 | On a rest day every minute is rest day overtime, the night window included: there is no rest day night rate, and the rest day rate (2.0) is above the night rate (1.75). | 07 section 1.3 (the table has no rest day night row) | Implemented |
| AR-OT-04 | Multipliers are never below normal 1.5, night 1.75, rest day 2.0, holiday 2.5, holiday night 2.5. An organisation may set higher ones. | STAT `overtime.minimum_rates` | Decided |
| AR-OT-05 | Hourly rate is the contract's monthly basic, before proration and deductions, divided by working days in the month (22 unless set, 20 to 31) times 8. Pay per kind is basic times minutes times multiplier, divided by working days times 480, rounded once. Basic 8,800 gives 50.00 an hour; two normal hours pay 150.00, night 175.00, rest day 200.00, holiday 250.00. | 07 section 1.3 | Decided |
| AR-OT-06 | The limits (240 minutes a day, 720 a week, the organisation's daily cap) flag a run. Every minute is still paid, approved or not. | 07 section 1.3, PAY-029 | Decided |
| AR-OT-07 | Records of one person that overlap pay overtime once: the longest pays, the lowest id on a tie. A record ending exactly when the next begins does not overlap. | BASE 25ad | Decided |
| AR-OT-08 | A record with no shift has no overtime on a working day. | 05 CAP-002; `api/app/Models/AttendanceRecord.php` `overtimeWindow()` | Decided |
| AR-OT-09 | There is no minimum before overtime counts: a check-out 3 minutes after the shift's end pays 3 minutes, because every minute is paid (AR-OT-06). Whether to introduce a floor is an owner decision proposed in [OVERTIME-WORKING-TIME-PLAN-2026-11-28.md](audit/OVERTIME-WORKING-TIME-PLAN-2026-11-28.md), not a gap in today's rule. | 07 section 1.3, PAY-029 | Decided |
| AR-OT-13 | Which day's class applies to the part of a night shift's overtime that falls on its second calendar day: the work date's (the night it began, AR-NIGHT-01) or that calendar day's own. S2 worked 21:58 to 07:30 with a public holiday on the second day: 90 minutes of normal overtime by the first reading, 90 of holiday by the second. | none (AR-OT-03 speaks of every calendar day for the night window only) | Undecided |
| AR-OT-14 | Which record pays when overlaps form a chain (A overlaps B and B overlaps C, but A does not overlap C), and whether "longest" in AR-OT-07 means the span, the worked minutes or the overtime minutes. | none | Undecided |
| AR-OT-10 | The legal limits themselves (240 and 720) and which kinds they cover. STAT marks all three as not confirmed against the law's text. | STAT | Undecided |

## 10. Lateness deduction

| Id | Rule | Source | State |
|---|---|---|---|
| AR-LATE-01 | Nothing is deducted for lateness unless the organisation switched deductions on and a person decided the minutes for that record. | 07 LATE-001, LATE-002 | Decided |
| AR-LATE-02 | At most the minutes missed (AR-MIN-03) can be decided for a record. | 07 LATE-004 | Decided |
| AR-LATE-03 | The deduction is decided minutes times the daily rate divided by 480, rounded down, and capped so that lateness, loans and cost sharing together stay within one third of basic. 90 minutes on a daily rate of 40,000 cents is 7,500 cents. | 07 LATE-005, LATE-006 | Decided |

## Constants

From `api/app/Support/AttendanceRules.php`. A change to one of these is a change to the rules above.

| Constant | Value | Rules |
|---|---|---|
| `MAX_OPEN_HOURS` | 14 | AR-NIGHT-02, AR-NIGHT-04 |
| `CLOSE_HOURS_AFTER_SHIFT_END` | 6 | AR-NIGHT-02, AR-NIGHT-03 |
| `MERGE_WINDOW_MINUTES` | 5 | AR-DAY-03, AR-DAY-04, AR-DAY-06 |
| `MAX_BREAK_MINUTES` | 180 | AR-BREAK-01, AR-BREAK-02 |

## Waiting for a decision

These are the Undecided rows, in the order they matter to pay. Each needs the owner's ruling (the accountant's too, where money moves) before a test can call the engine right or wrong on it. Until then a test reports a field that depends on one as undecided, and only when the rule's readings would give different answers for that input: an undecided rule does not blank a field it cannot change.

1. **AR-OT-13**, holidays on a night shift's second day. It decides whether those hours are paid at 1.5 or 2.5.
2. **AR-ABS-08**, a record on a leave day: worked overtime at 1.5 from the shift's end, or the whole span.
3. **AR-STATUS-07** and **AR-CLOCK-06**, seconds at every window. Terminals report seconds, so these decide real days.
4. **AR-OT-14**, chains of overlapping records.
5. **AR-MIN-08**, the break on a second session.
6. **AR-NIGHT-05**, the exact 14 and 6 hour boundaries, and closing a day with no shift.
7. **AR-DAY-08**, which punches a manual entry's 5 minutes are measured between.
8. **AR-CLOCK-07**, seconds when a span is split into kinds of overtime: at most one minute a span.
9. **AR-ABS-07**, half-day leave.
10. **AR-OT-10**, the legal limits, which wait on reading Art. 67 of Proclamation 1156/2019 from an official copy.

Two rows were Undecided in the first edition and are not: AR-MIN-07 (early arrival) and AR-OT-09 (no overtime floor) follow from rules already Decided, which an independent review found on 2026-11-28 (BASELINE 25bq).

## Where other documents say something else

Found on 2026-11-28 while this register was written. The register is right in each case; the other text is corrected or marked.

| Document | What it says | The rule |
|---|---|---|
| `PRD.md` FR-08.3, `PRODUCT_VISION.md`, `phases/PHASE_04.md` | Overtime at 1.25, 1.5, 2.0, 2.5 | AR-OT-04 |
| `LOCALIZATION.md` | Holiday 2.0, holiday night 2.5 | AR-OT-04 |
| 05 TEAM-005 | Team overtime is worked minutes less 480 a day | AR-OT-01. The team screen's figure is an estimate and is not what payroll pays. |
| 05 COR-006 | A correction's cost is estimated at salary divided by 176 hours | AR-OT-05. 176 is right only when the month has 22 working days. |
| Employee timeline and dashboard | Show check-out minus check-in | AR-MIN-04. They do not subtract the break; the timesheet and payroll do. |
| ATTENDANCE-PIPELINE "Rules checked per punch" | A geofence refusal applies to the mobile source only | 05 ground rules: web and QR are refused too. Not a rule about time, so it has no id here. |

## What this register does not cover

Refusals and flags that decide whether a punch is accepted but not how a day is counted: methods switched off, geofence, registered phones, selfies, liveness, the risk score and presence checks. They are specified in 05 sections 1 to 7 and 15 to 16.
