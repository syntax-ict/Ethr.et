# E2E specification 05: attendance, shifts, holidays, kiosks and devices

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Run in QA Audit.

**Ground rules of the attendance engine** (what "correct" means in this document):

- Times are stored in UTC and rated in the organisation's time zone, `Africa/Addis_Ababa` (UTC+3, no
  daylight saving) by default. **A punch at 09:00 EAT is 06:00 UTC.** A status calculated on the UTC
  clock was a real production defect (QA A1, fixed 2026-10-12); statuses recorded before that fix
  were deliberately not recalculated.
- **Present** = checked in at or before shift start plus the shift's grace minutes; **late** otherwise.
  **Early leave** = arrived present and checked out before shift end minus early-departure minutes.
  **Pending** = no shift matched. **Absent** = an expected workday with no check-in.
- The engine **flags, never refuses**, a suspicious punch (integrity flags, section 6). It refuses only
  rule breaks: a disabled method, a required geofence missed on mobile, a leaver, a closed payroll day.
- Every attendance write carries an idempotency key. A retried request returns 200 with
  `was_duplicate: true` and records nothing new.
- A new check-in answers 201, a check-out 200. "No employee linked to your account" is a 404.

Record times in the log as **EAT**.

---

## 1. Attendance settings

`GET/PUT /attendance/settings`, needs `attendance.manage` (HR). Every change is audited.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ATT-S-001 | P1 | R | Defaults | HR opens Settings → Attendance on a fresh `uat-` organisation | Methods: biometric, mobile, QR, kiosk, web, manual, CSV. Geofence required off; mobile photo required off; kiosk PIN required off; QR expiry 30 min; QR auto refresh on; QR single-use limit 0; mobile accuracy threshold 100 m; offline sync on; kiosk auto reset 4 s; grace 15; daily overtime cap 120 min; overtime needs approval off; confidence threshold 70; reminders on, lead 10 min; auto check-out off, after 120 min; geofence auto check-in off; photo retention 7 days |
| ATT-S-002 | P1 | W | Limits | Save QR expiry 4 and 481; accuracy 9; kiosk reset 1; photo retention 0 and 91; no method at all | Each refused (QR 5 to 480, accuracy 10 to 5,000, reset 2 to 30, retention 1 to 90, at least one method) |
| ATT-S-003 | P1 | W | Disabled method | Turn web off; EMP clicks Check In on the web | 422 "This attendance method is not enabled for your organization." (the kiosk route answers 403 instead). Turn web back on |
| ATT-S-004 | P3 | R | Old settings page | Open `/attendance/settings` | Points to the settings hub |

## 2. Web check-in and check-out

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ATT-001 | P1 | W | Check in | EMP (shift S1 08:30 to 17:30, grace 15): check in at 08:40 EAT | 201; status present; the time shown is 08:40 EAT |
| ATT-002 | P1 | W | Late | EMP2 checks in at 08:46 EAT | Status late (08:30 + 15 = 08:45). Intelligence counts one late arrival |
| ATT-003 | P1 | W | Grace boundary | Check in exactly at 08:45 EAT | Present |
| ATT-004 | P1 | W | Check out | EMP checks out at 17:35 | 200; hours worked shown |
| ATT-005 | P1 | W | Early leave | EMP2 (arrived present another day) checks out at 17:10 | Early leave (17:30 − 15 = 17:15) |
| ATT-006 | P1 | W | Check out without check-in | A person with no open record clicks Check Out | 422 "There is no open check-in to close." |
| ATT-007 | P1 | W | Double click | Click Check In twice quickly | One record; the second answer is a replay (`was_duplicate: true`) or the button is disabled |
| ATT-008 | P1 | R | Day boundary | A punch at 01:30 EAT | Dated on that EAT day, not the previous one (QA H2) |
| ATT-009 | P2 | W | No shift | An employee with no shift checks in | Status pending |
| ATT-010 | P1 | R | Not linked | A user with no employee record checks in | 404 "No employee linked to your account" |
| ATT-011 | P2 | W | Location missing flag | Geofence required on; check in on the web with location blocked | Accepted (web is never refused for location) and flagged `location_missing` |
| ATT-012 | P1 | R | My attendance | EMP: My Attendance | Own records only, with status, times, source |

## 3. Mobile check-in (GPS, selfie, automatic)

Use a real phone at B1's address and one at least 1 km away.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| MOB-001 | P1 | W | Inside the fence | At B1, `/attendance/mobile` → check in | 201; geofence verified; confidence 90 (88 with coordinates only, 95 with a photo) |
| MOB-002 | P1 | W | Outside, geofence required | Geofence required on; check in 1 km away | 422 "You are outside the allowed check-in area…" |
| MOB-003 | P1 | W | No location, geofence required | Location permission denied | 422 "GPS location is required…" |
| MOB-004 | P1 | W | Outside, geofence not required | Geofence required off; check in 1 km away | Accepted and flagged `outside_geofence` |
| MOB-005 | P1 | W | Photo consent first | Mobile photo required on; EMP has never consented; check in | The photo notice (version `2026-10-17.2`) is shown; without consent: 422 "Agree to the attendance photo notice…"; after agreeing, the selfie is taken and saved |
| MOB-006 | P1 | W | Photo required | Photo required on; send no photo (API) | 422 "Take a selfie…" |
| MOB-007 | P2 | W | Photo size | Send a selfie over 1.5 MB decoded (API) | Refused; through the interface the photo is shrunk first and saves |
| MOB-008 | P2 | W | Consent withdrawn | EMP withdraws consent in Profile; checks in with a photo | Refused until consent is given again. Consent to an older version no longer counts |
| MOB-009 | P2 | W | Low accuracy | Report accuracy 300 m (threshold 100) | Accepted and flagged `low_accuracy` |
| MOB-010 | P2 | W | Automatic check-in | Turn on the organisation's geofence auto check-in, and EMP's own auto check-in; arrive at B1 within an hour before shift start | Checked in automatically. With either switch off, or outside the fence: 422. The phone tries only from 60 minutes before start until start plus grace, and only with accuracy at most max(threshold, 50 m) |
| MOB-011 | P2 | R | My shift | `GET /attendance/my-shift` | Date, time zone, working day or not, holiday, leave, shift window, status (not checked in, checked in, checked out, auto checked out), reminder settings |
| MOB-012 | P3 | R | Reminders | Reminders on, lead 10 min | The phone shows a reminder 10 min before shift start (reminders are on the phone, driven by `/my-shift`; there is no server-side reminder sender) |

## 4. QR attendance

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| QR-001 | P1 | W | Generate | HR: Attendance → QR, branch B1, expiry 30 | A QR code with an expiry; auto refresh as configured. Unknown branch: 404; expiry outside 5 to 480 refused |
| QR-002 | P1 | W | Scan | EMP: Attendance → Scan, scan the code | Check-in 201 at B1; scanning again for check-out 200 |
| QR-003 | P1 | W | Same code twice | EMP scans the same code for check-in a second time | 422 "You have already used this QR code…" (an automatic retry with the same key is not counted) |
| QR-004 | P1 | W | Expired | Generate with expiry 5, wait 6 minutes, scan | 422 "QR code is invalid or has expired." |
| QR-005 | P1 | R | Other organisation | Scan a QR code generated in QA Isolation | 422 "QR code is invalid or has expired." |
| QR-006 | P2 | W | Use limit | Single-use limit 2; three people scan | Third: "…used the maximum number of times" |
| QR-007 | P2 | W | Other branch | EMP (branch B1) scans B2's code | Accepted and flagged `branch_mismatch` |
| QR-008 | P2 | R | Tampered | Change one character of the token (API) | Same refusal as QR-004 |

## 5. Kiosk terminal

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| KIO-001 | P1 | W | Register | HR: Attendance → Kiosks → add "QA Lobby", branch B1, admin PIN `4826` | Created; the 64-character token is shown once only |
| KIO-002 | P1 | W | Activate a tablet | Open `/kiosk` on a tablet and enter the token | Kiosk screen for QA Lobby |
| KIO-003 | P1 | W | Punch by code | Enter EMP's employee code, check in | Recorded; the screen resets after 4 s |
| KIO-004 | P1 | W | PIN required | Kiosk PIN required on; HR sets EMP's PIN to `1357` (4 to 6 digits); punch without, with wrong, with right PIN | Without: 422. Wrong: 401 "Invalid employee PIN." Right: recorded. An employee with no PIN: 422 |
| KIO-005 | P1 | W | PIN lock | Wrong PIN 5 times for EMP | 429 with `Retry-After`; EMP locked at this kiosk for 15 minutes; audited `kiosk.pin_locked`. Other employees unaffected |
| KIO-006 | P2 | W | Unknown code | Enter a code that does not exist | 404 |
| KIO-007 | P1 | W | Exit | Exit with a wrong admin PIN, then the right one | "Incorrect admin PIN."; then the kiosk closes (204, audited `kiosk.exited`). More than 5 exit attempts a minute: 429 |
| KIO-008 | P1 | W | Deactivate and regenerate | Deactivate the session; the tablet tries to punch. Activate, regenerate the token; the tablet tries the old token | 401 "Invalid or inactive kiosk session." in both cases |
| KIO-009 | P1 | W | Offline kiosk | Disconnect the tablet's network; punch two employees; reconnect | Punches queued on the tablet, then synced; each result created or duplicate. Punch time is the time on the tablet, not the sync time |
| KIO-010 | P2 | R | Kiosk on another organisation | Present QA Audit's kiosk token on QA Isolation's address | 401 |
| KIO-011 | P2 | W | Kiosk disabled | Turn the kiosk method off; punch | 403 |
| KIO-012 | P2 | W | Staff kiosk entry | HR (signed in) records a kiosk punch for EMP via `POST /attendance/kiosk`; SUP tries the same | HR allowed; SUP 403 |

## 6. Integrity flags and review

Flags never block a punch. They queue it for review in Attendance → Integrity (needs
`attendance.viewConflicts`; deciding needs `attendance.resolveConflicts`).

| Flag | Severity | How to produce it |
|---|---|---|
| `low_confidence` | low | Check-in confidence below the threshold (raise the threshold to 96 and do a web check-in) |
| `outside_geofence` | high for offline and web, medium otherwise | MOB-004 |
| `location_missing` | medium | ATT-011 |
| `suspect_location` | high | Accuracy reported as 0, or identical coordinates (7 decimals) on another day within the last 30 records (API) |
| `low_accuracy` | low | MOB-009 |
| `impossible_travel` | high | Two punches more than 2 km apart implying over 120 km/h within 12 h (API with coordinates) |
| `branch_mismatch` | medium | QR-007 |
| `unverified_offline` | high | Offline sync with a missing or invalid grant (OFF-004) |
| `clock_suspect` | high | Offline punch time outside the grant window |
| `late_sync` | low | Offline punch synced 24 h or more after it was taken |
| `photo_missing` | medium | Offline punch without a photo while photos are required |
| `device_clock_ahead` | medium | Device reports a time more than 120 s ahead |

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| INT-001 | P1 | W | Flags appear | Produce at least five of the flags above | Each listed once per record and type, open first, sorted by severity; the sidebar shows the count to review |
| INT-002 | P1 | W | Accept | HR accepts a flag with a note | Closed; the record stands |
| INT-003 | P1 | W | Reject | HR rejects a flag without a note; then with a note | Note required. Rejecting voids the record, closes its other open flags, notifies the employee ("punch rejected") and is audited |
| INT-004 | P1 | R | No self-review | HR reviews a flag on HR's own punch | 403 "You cannot review a flag on your own attendance." |
| INT-005 | P2 | W | Closed flag | Review an already reviewed flag | 422 |
| INT-006 | P2 | R | Summary | `GET /attendance/integrity/summary` | Counts agree with the list |

## 7. Offline mobile attendance

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| OFF-001 | P1 | W | Grant | EMP opens the mobile page online | The app holds an offline grant valid 72 h (renewed when under 24 h remain) |
| OFF-002 | P1 | W | Punch offline | Airplane mode; check in; wait 10 min; reconnect | "Waiting to sync" indicator; then synced. The record's time is the offline time; result `created` |
| OFF-003 | P1 | W | Replay | Force the app to sync the same record again (or re-send via API) | Result `duplicate`; no second record |
| OFF-004 | P1 | W | Bad grant | Sync a record with a missing or altered grant (API) | Recorded and flagged `unverified_offline`, never refused |
| OFF-005 | P1 | W | Too old or in the future | Sync a record dated 8 days ago, and one 10 minutes ahead (API) | Per-record error "…captured more than 7 days ago…"; the other records in the batch still process |
| OFF-006 | P1 | R | Someone else's record | EMP syncs a record for EMP2 (API) | Per-record error "You can only sync your own attendance." |
| OFF-007 | P2 | W | Batch size | Sync 101 records | Refused (1 to 100) |
| OFF-008 | P2 | W | Offline disabled | Offline sync off; sync | Method not allowed (422) |
| OFF-009 | P2 | W | Photo without consent offline | Offline punch with a photo, no consent | Punch kept, photo dropped |

## 8. Manual entry, import, corrections, overtime, conflicts

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| MAN-001 | P1 | W | Manual day | HR: Attendance → manual entry for EMP3, yesterday 08:35 to 17:30, reason | Recorded; rated present; exactly one `attendance.manual_entry` audit row |
| MAN-002 | P1 | W | Rules | Date tomorrow; check-out before check-in; reason over 500 characters | Refused. **A night shift crossing midnight cannot be entered manually** (check-out must be later the same date); record as a limitation if the business needs it |
| MAN-003 | P2 | W | Merge | Manual entry within 5 minutes of an existing punch | Merged; only a later check-out is added |
| MAN-004 | P1 | W | Closed payroll day | Manual entry on a day inside an approved payroll run | 422 `payroll-period-closed`, "This day is in an approved payroll run, so its attendance cannot be changed. Void the run first to reopen it." |
| AIMP-001 | P1 | W | Import | Template (`employee_code,date,check_in_time,check_out_time`), preview 5 rows with one bad date, one bad time, one unknown code | Per-row: "Invalid date", "Invalid check_in_time (expected HH:MM)", "Employee 'X' not found" |
| AIMP-002 | P1 | W | Commit and replay | Commit the valid rows; commit again with the same key | 201 `{created, skipped, errors}`; replay skips all. **Imported rows are stored present with no shift**, so no late or early rating: verify and note |
| AIMP-003 | P2 | R | Legacy file | Upload a BioTime or Hikvision CSV to legacy parse | Returns the parsed records and errors; writes nothing |
| COR-001 | P1 | W | Request | EMP: Corrections → request on yesterday's record, proposed times, reason | Pending; SUP notified |
| COR-002 | P1 | W | Approve | SUP approves | Record shows proposed times, keeps the originals, status recalculated (a late arrival corrected to 08:30 becomes present); `attendance.corrected` webhook fires (if one is subscribed); EMP notified |
| COR-003 | P1 | W | Reject | SUP rejects without, then with, a reason | Reason required (up to 1,000); EMP notified |
| COR-004 | P1 | R | Self-approval | HR files a correction for HR's own record and approves it; HR files one for EMP and approves it | "You cannot approve a correction to your own attendance."; "You filed this correction, so someone else must approve it." Rejecting one's own is allowed |
| COR-005 | P2 | W | Not pending | Approve an already decided correction | 422 "…no longer pending." |
| COR-006 | P2 | R | Payroll impact | HR (with `employee.viewFinancial`) opens payroll impact | Hours, difference in minutes, estimated cost at salary ÷ 176 h, whether it falls in an open period. SUP without financial rights: refused |
| COR-007 | P1 | W | Closed day | Correction on a day in an approved run | 422 payroll period closed |
| OT-001 | P1 | W | Request overtime | EMP: Overtime requests → tomorrow, 120 min, reason | Pending; SUP notified |
| OT-002 | P1 | W | Rules | Date yesterday; 10 min; 241 min; reason of 2 characters; a second request for the same day | Refused (today or later, 15 to 240 min, reason 3 to 1,000, "Overtime is already requested for this day…") |
| OT-003 | P1 | W | Decide | SUP approves one, rejects one with a reason | Recorded; EMP notified |
| OT-004 | P1 | R | Self-approval | SUP requests overtime and approves it | 403 |
| OT-005 | P2 | W | Cancel | EMP cancels a pending request; then a decided one | First cancelled; second 422 |
| CON-001 | P1 | W | Conflict | Record a web check-in and, 10 minutes later, a kiosk check-in for the same person and day | A conflict (`multi_source_far`) in Attendance → Conflicts |
| CON-002 | P1 | W | Close sources merge | Two punches 3 minutes apart from different sources | Merged: earliest check-in and latest check-out kept, the other voided, no conflict |
| CON-003 | P1 | W | Resolve | Resolve CON-001 with keep A; another with dismissed | Keep A voids B; dismissed changes no record. Already resolved: 422. Resolving one's own: 403. Keep on a closed payroll day: refused |

## 9. Shifts, assignments, rotations, roster

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SHF-001 | P1 | W | Create | HR: Shifts → S1 "QA Day" 08:30 to 17:30, grace 15, early 15, break 60, Monday to Friday | Saved. Same start and end, grace 121, break 181, working days `8` refused |
| SHF-002 | P1 | W | Night shift | S2 "QA Night" 22:00 to 06:00, crosses midnight | Saved |
| SHF-003 | P1 | W | Night shift punches | Assign S2 to EMP3; check in 21:55, check out 06:05 next morning | One record, present, closed by the morning check-out |
| SHF-004 | P1 | W | Night shift lateness after midnight | On S2, check in at 00:30 | Expected: late. The code compares with the start time on the check-in's own day, so **verify this explicitly**; report a defect if rated present or pending |
| SHF-005 | P1 | W | Default shift | Mark S1 default | Any other default is cleared |
| SHF-006 | P1 | W | Delete in use | Delete S1 while assigned | 409 `shift-in-use` |
| SHF-007 | P2 | W | Duplicate | Duplicate S1 | A copy named "… copy", not default, no assignments |
| SHF-008 | P2 | R | Usage before edit | Edit S1's start | Usage preview shows assignments, people, rotations and records, and says past statuses are not recalculated |
| SHF-009 | P1 | W | Assign | Assign S1 to D1 from today; S2 to EMP3 directly | Employee assignment beats department, department beats branch; within a level the latest start date wins |
| SHF-010 | P2 | W | End or delete assignment | End an assignment (set end date); delete a started one; delete a future one | Ended; 409 `assignment-in-effect`; deleted |
| SHF-011 | P1 | W | Rotation | 14-day rotation: days 0 to 4 S1, days 7 to 11 S2, rest otherwise; preview 28 days; assign to EMP2 | Preview correct; rest days are rest days (no fall-through to a department shift). Duplicate or out-of-cycle offsets refused; preview over 366 days refused |
| SHF-012 | P2 | R | Roster | Shifts → Roster | Colour-coded schedule; SUP sees only employee-level assignments within reach |

## 10. Auto check-out, missing punches, absences

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| AUTO-001 | P1 | W | Auto check-out | Auto check-out on, after 120 min. EMP checks in on S1 and never checks out | Between 19:30 and about 19:40 EAT (shift end + 120 min, run every 5 minutes) the record is closed **at 17:30**, marked auto checked out, reclassified, audited, and EMP notified |
| AUTO-002 | P2 | W | Not applied | A check-in made after the shift end; a check-in 3 days old | Not auto-closed |
| ABS-001 | P1 | R | Absent | EMP3 (workday, no leave, no holiday) does not check in | Team today and the dashboard show EMP3 absent. On a holiday, a rest day or approved leave: holiday, rest day, on leave, not absent |
| ABS-002 | P2 | X | Missing punch notice | The day after a missing check-out | Daily at 15:30 UTC (18:30 EAT) the employee and supervisor are notified |

## 11. Team monitoring and intelligence

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| TEAM-001 | P1 | R | Team today | SUP: Team Attendance | EMP and EMP2 only (direct reports), each present, late, absent, on leave, rest day or holiday; leavers excluded |
| TEAM-002 | P1 | R | Summary count | Compare `summary.present` with the rows | **Suspected defect:** the present count may be under-reported. Count by hand and record the difference |
| TEAM-003 | P2 | R | Weekly and monthly | `/team/attendance/summary?period=weekly` | Per day present, absent, late and rate, up to today |
| TEAM-004 | P2 | R | Date default | Between 00:00 and 03:00 EAT, open `/attendance/team` without a date | Expected today's EAT date. **Suspected defect:** it may default to the UTC date (yesterday). Verify |
| TEAM-005 | P2 | R | Team overtime | `/team/overtime` | Based on an 8 h day. **Suspected defect:** voided records may be counted; void a record and compare |
| INTL-001 | P2 | R | Intelligence | Attendance → Intelligence | Late arrivals, missing punches, anomalies (over 16 h worked, over 4 h overtime) agree with the data |

## 12. Photos and retention

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PHO-001 | P1 | R | Who may see photos | HR, DA, TA open Attendance → Photos; SUP tries | HR, DA, TA see them; **SUP does not** (no `attendance.viewPhotos`) |
| PHO-002 | P1 | R | Viewing is audited | HR opens a photo | Image served with `no-store`; audited `photo_viewed`. A record outside HR's reach: 404 |
| PHO-003 | P1 | W | Hold | HR holds a photo with a reason (3 to 500) | Held; not deleted by retention. Releasing needs `attendance.manage` and restarts the review |
| PHO-004 | P2 | X | Retention | Retention 1 day; a photo older than 1 day | A 48 h review starts and reviewers are notified; then unheld photos are deleted (record kept), audited `attendance.photos_deleted`. Runs hourly |

## 13. Holidays

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| HOL-001 | P1 | W | Auto-detect | HR: Settings → Holidays → Auto-detect | Enkutatash, Meskel, Genna, Timkat, Adwa, Labour Day (1 May), Patriots (5 May), Derg (28 May), Siklet, Fasika, and Eid al-Fitr, Arafa, Mawlid marked estimated. Dates correct for the year (check Enkutatash 11 or 12 September, Meskel 27 or 28 September, Genna 7 January) |
| HOL-002 | P1 | W | Idempotent | Auto-detect again | Nothing duplicated (matched by date) |
| HOL-003 | P2 | W | Branch holiday | Add a holiday for B2 only | Applies to B2 staff only, in leave counting and absences |
| HOL-004 | P2 | R | Dual calendar | Holiday form date input | Ethiopian and Gregorian shown; choosing in either sets both |
| HOL-005 | P2 | R | Delete rights | HR deletes a holiday | Refused; TA can |

## 14. Biometric devices

Only with a test device the owner provides, registered to QA Audit; otherwise run DEV-001 to DEV-005
and DEV-010 and mark the rest Blocked. Never register a device using a serial a customer could own.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| DEV-001 | P1 | R | Who may register | HR tries to add a device | Refused; TA only |
| DEV-002 | P1 | W | Register | TA: Devices → add, adapter ZKTeco ADMS, serial, allowlist with the office public IP | Saved, status pending; token, allowlist and webhook address shown only to TA |
| DEV-003 | P1 | R | Simulator refused | Choose the mock adapter | 422 "The device simulator is only available on development servers…" |
| DEV-004 | P1 | W | Validation | IP and port missing for Hikvision; a private address (`192.168.1.10`); 21 allowlist entries; sync interval 0 | Refused (private hosts refused as internal) |
| DEV-005 | P1 | W | Duplicate serial | Register the same serial and adapter again | 422 "This serial number is already registered for this device type." |
| DEV-006 | P1 | W | Webhook without token | Post a punch to `/devices/webhook/zkteco` with no token from an address not on the allowlist | 401 `{"status":"unauthorized"}`; an empty allowlist admits nobody |
| DEV-007 | P1 | W | Punch from the device | Punch on the device | Recorded with source biometric; idempotent on redelivery |
| DEV-008 | P1 | W | Unknown badge | Punch with a badge no employee has | Held in Devices → unmatched badges; assign it to EMP; the held punches replay (`{replayed, refused}`) |
| DEV-009 | P2 | W | Future device time | Device clock 15 minutes ahead | Refused "The device reported a time that is unreadable or in the future…" (over 10 min); 2 to 10 min ahead accepted and flagged `device_clock_ahead` |
| DEV-010 | P2 | W | Regenerate token | Regenerate; post with the old token | 401 |
| DEV-011 | P2 | W | Commands | Turn on commands; set time; send staff; enrol EMP on devices | 202 each. With commands off: 409 'Turn on "Send commands to this device" first.' |
| DEV-012 | P2 | X | Offline device | Power the device off | Marked offline once; TA and HR get one notification (email and in-app) on the change, not one per sync |
| DEV-013 | P3 | R | Fleet dashboard | Devices → dashboard | KPIs and 24 h sync figures agree with the device list |
