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
- The engine **flags, never refuses**, a suspicious punch (integrity flags and the risk score,
  sections 6 and 6a). It refuses only rule breaks: a method disabled where the person works
  (organisation or branch rule), a required geofence missed on mobile by someone in office mode, a
  leaver, a closed payroll day, and an unregistered phone under "Only the registered phone"
  (section 15). A required geofence at a branch with no position is **recorded and flagged**
  `geofence_unconfigured`, not refused. **Sections 2 to 8 assume "Registered phone" is Off**; run them
  with it Off.
- Every attendance write carries an idempotency key. A retried request returns 200 with
  `was_duplicate: true` and records nothing new.
- A new check-in answers 201, a check-out 200. "No employee profile linked to your account." is a 404.

Record times in the log as **EAT**.

---

## 1. Attendance settings

`GET/PUT /attendance/settings`, needs `attendance.manage` (HR). Every change is audited.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ATT-S-001 | P1 | R | Defaults | HR opens Settings → Attendance on a fresh `uat-` organisation | The setup panel is at the top (section 1c). Methods: biometric, mobile, QR, kiosk, web, manual, CSV. Geofence required off; mobile photo required off; kiosk PIN required off; QR expiry 30 min; QR auto refresh on; QR single-use limit 0; mobile accuracy threshold 100 m; offline sync on; kiosk auto reset 4 s; daily overtime cap 120 min; overtime needs approval off; confidence threshold 70; reminders on, lead 10 min; auto check-out off, after 120 min; geofence auto check-in off; photo retention 7 days; Cheating protection: risk review threshold 40, live face check off, adaptive selfies off, registered phone off, office networks empty; lateness deductions off; reconciliation off. **No organisation grace setting** (grace is per shift). "Work sites" and "Rules by branch" cards present |
| ATT-S-002 | P1 | W | Limits | Save QR expiry 4 and 481; accuracy 9; kiosk reset 1; photo retention 0 and 91; no method at all; 21 office networks; office network `10.0.0.0/7` | Each refused (QR 5 to 480, accuracy 10 to 5,000, reset 2 to 30, retention 1 to 90, at least one method, at most 20 office networks, a range no wider than /8) |
| ATT-S-003 | P1 | W | Disabled method | Turn web off; EMP clicks Check In on the web | 422 "This attendance method is not enabled for your organization." (the kiosk route answers 403 instead). Turn web back on |
| ATT-S-004 | P3 | R | Old settings page | Open `/attendance/settings` | Points to the settings hub |
| ATT-S-005 | P2 | W | New settings limits | Save `risk_review_threshold` 9 and 101; send `grace_period_minutes: 30` | Threshold refused (10 to 100). Grace is ignored and not echoed: grace is a shift property only |

## 1a. Rules by branch, and where each employee punches

`GET /attendance/settings/branches`, `PUT /attendance/settings/branches/{branch}`, both
`attendance.manage` (HR), audited `attendance.branch_rules_updated`. A branch can override only:
enabled methods, geofence required, selfie with phone check-in, kiosk PIN, weakest GPS accepted,
automatic check-out and its minutes, automatic check-in on arrival, the risk review threshold, and the
registered-phone rule and office networks (section 15). Photo retention, confidence and overtime stay
organisation-wide. `PUT /employees/{id}/attendance-mode` takes `office`, `field` or `remote`
(`attendance.manage`, audited `employee.attendance_mode_changed`). UI: "Rules by branch" under
Settings → Attendance; "Where they punch" on the employee's Attendance tab. For this section move EMP2
to B2, and back afterwards.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| BRR-001 | P1 | R | List | HR: Settings → Attendance → Rules by branch; `GET /attendance/settings/branches` | Every active branch: "Same as organisation" or ":count changed"; a branch with no coordinates shows "Not on the map". The API returns `organisation`, `overridable`, and per branch `overrides` (null = inherited) and `effective` |
| BRR-002 | P1 | W | Selfie at one branch | Organisation selfie off. B2 → "Change rules" → "Selfie with phone check-in" On. EMP2 (B2) and EMP (B1) check in on the phone without a photo (API) | EMP2: 422 "Take a selfie to check in. Your organisation requires a photo when you check in on your phone." EMP: 201. EMP2's `my-shift` shows `policy.photo_required: true` |
| BRR-003 | P1 | W | Method off at one branch | B2 methods without web and mobile. EMP2 clicks Check In on the web and opens `/attendance/mobile`; EMP the same at B1 | EMP2 web: 422 "This attendance method is not enabled for your organization."; the phone page: "Phone check-in is turned off where you work. Use the device, kiosk or web check-in your organisation provides." EMP unaffected (201) |
| BRR-004 | P2 | W | Only real differences are stored | Save B2 with geofence equal to the organisation's value; then change the organisation's value | `overrides` all null, card "Same as organisation"; B2's `effective` follows the organisation. A branch saved with no differences has its override row removed |
| BRR-005 | P2 | W | Limits and rights | Overrides: accuracy 9; auto check-out minutes 14; risk threshold 9; `enabled_methods: []`. EMP calls `GET /attendance/settings/branches` | Each refused (accuracy 10 to 5,000; minutes 15 to 720; threshold 10 to 100; at least one method). EMP: 403 |
| BRR-006 | P1 | W | Kiosk PIN follows the kiosk's branch | Organisation PIN off; B1 "PIN at the kiosk" On. At QA Lobby (B1) EMP2 (B2) punches without a PIN; then on a kiosk registered to B2 | QA Lobby: 422 (PIN required); its activation answer has `settings.pin_required: true`. The B2 kiosk needs no PIN. **The rule is the kiosk's branch, not the employee's** |
| BRR-007 | P2 | W | Auto check-out at one branch | Organisation auto check-out off; B1 On, 120. EMP (B1) and EMP2 (B2) check in and never check out | Only EMP's record is closed (as AUTO-001); EMP2's stays open |
| BRR-008 | P1 | W | Set where someone punches | HR: EMP → Attendance → "Where they punch" → Field, Remote, "At their branch"; then `attendance_mode: "home"` (API). SUP and EMP try | "Saved" each time; audit with `from` and `to`. Invalid value: 422. SUP and EMP: no card, API 403 |
| BRR-009 | P1 | W | Remote is not held to the geofence | Geofence required On. EMP set to Remote checks in on the phone 1 km from B1 | 201, no `outside_geofence`, coordinates kept. `my-shift`: `policy.attendance_mode: "remote"`, `geofence_required: false`, `places: []`, `auto_check_in_allowed: false` |
| BRR-010 | P1 | W | Field outside every place | Geofence required On. EMP as Field checks in 1 km from B1 and every site; then back to "At their branch" and repeat | Field: 201, never refused, flagged `outside_geofence` (medium for mobile) with `distance_meters`, `radius_meters`, `place` (nearest). Office: 422 "You are outside the allowed check-in area. Please move closer to your branch location." |

## 1b. Work sites and the map

`GET/POST/PUT/DELETE /attendance/sites` and `GET /attendance/map-config`, all `attendance.manage`. A
work site is a named circle, 10 to 10,000 m (150 m by default), where people may punch besides their
branch: open to everyone at the site's branch, plus the employees and departments assigned to it. A
punch is inside when it falls within the branch or any open site; reported GPS accuracy is allowed up
to 50 m of slack. Audited `attendance.site_created`, `attendance.site_updated`,
`attendance.site_deleted`.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PLACE-001 | P1 | W | Create a site | HR: Settings → Attendance → Work sites → "Add a site": "QA-Site North", paste `9.0300, 38.7400` into "Paste a map link or coordinates", radius 200, no branch; add EMP2 under "People who work here" and D1 under "Departments that work here". Then no name; radius 9 and 10,001 | 201, "Site saved", listed with "200 m". No name or position: "Give the site a name and put it on the map." Radius outside 10 to 10,000 refused |
| PLACE-002 | P1 | W | Punch at a site, geofence required | Geofence required On. At QA-Site North (over 1 km from B1): EMP2 (assigned), EMP (in D1), EMP3 (D2, not assigned) check in on the phone, office mode | EMP2, EMP: 201, verified, no flag. EMP3: 422 "You are outside the allowed check-in area. Please move closer to your branch location." |
| PLACE-003 | P2 | W | Accuracy slack is capped | Geofence required On; B1 radius 200 m. API: 240 m from B1's centre with `accuracy: 40`; 260 m with `accuracy: 80` | 240/40 inside (201). 260/80: 422 (only 50 m of the 80 is allowed) |
| PLACE-004 | P2 | W | Switch off and remove | Untick "People may punch here", then on again; then "Remove site" | While off, EMP2's PLACE-002 punch is refused. Remove: 204, "Site removed"; soft-deleted, earlier visits and punches still name it |
| PLACE-005 | P1 | R | Other organisation's ids | Create a site with a QA Isolation employee in `employee_public_ids` and a QA Isolation branch in `branch_public_id`; EMP calls `GET /attendance/sites` | 422 on each field with the generic "does not exist" message, same as an unknown id. EMP: 403 |
| PLACE-006 | P2 | R | Map provider, nothing to Google | With the network panel open, use the site dialog and Organisation → Branches: "Search an address", "Use my current location"; paste a Google Maps `/@9.01,38.76,17z` link and an OpenStreetMap `#map=17/9.01/38.76` link; paste text with no coordinates | `GET /attendance/map-config`: `google_maps_key: null` unless the console holds a key. OpenStreetMap tiles; search goes to nominatim.openstreetmap.org; no request to Google. Both links place the pin. No coordinates: "No location found. Paste a Google Maps link (open short links first) or latitude, longitude." With a console key, Google Maps is offered beside OpenStreetMap |

## 1c. Attendance setup status

`GET /attendance/setup-status` (`attendance.manage`) returns `ready` (false when any check is a
blocker) and `checks[]`, each with `id`, `severity` (`blocker`, `warning`, `info`), `count` and
`fix_route`. The panel heads Settings → Attendance, and appears compact (titles only, hidden when
empty) on the Attendance hub for people who manage attendance.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SETUP-001 | P1 | R | Healthy organisation and rights | HR: Settings → Attendance in set-up QA Audit; the Attendance hub. SUP and EMP call the API | "Attendance is set up. Nothing needs attention.", `ready: true`; no panel on the hub. SUP, EMP: 403, no panel |
| SETUP-002 | P1 | W | Blocker: required geofence at an unplaced branch | Geofence required On at B2 only while B2 has no coordinates; open the panel; EMP2 (B2) checks in on the phone with location | "Location required at 1 branch(es) not on the map", blocker, links to `/organization?tab=branches`; `ready: false`. EMP2's punch is **recorded** (201), flagged `geofence_unconfigured`, shown in Integrity as "Branch not on the map" |
| SETUP-003 | P2 | W | Warnings | Produce: a punch on a rotation rest day (no shift); a Field employee with no active work site; B1 PIN required with a kiosk at B1 and someone without a PIN | ":count punch(es) this week with no shift", ":count field staff and no work sites", ":count people without a kiosk PIN", each with its count and a link to the fix |
| SETUP-004 | P3 | R | Info | Make the leave settings' working week differ from the default shift's days | "Working week and default shift disagree", info, links to `/settings/leave-types` |
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
| ATT-010 | P1 | R | Not linked | A user with no employee record checks in | 404 "No employee profile linked to your account." (the `/attendance/device*` endpoints answer the same) |
| ATT-011 | P2 | W | Location missing flag | Geofence required on; check in on the web with location blocked | Accepted (web is never refused for location) and flagged `location_missing` |
| ATT-012 | P1 | R | My attendance | EMP: My Attendance | Own records only, with status, times, source |

## 3. Mobile check-in (GPS, selfie, automatic)

Use a real phone at B1's address and one at least 1 km away.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| MOB-001 | P1 | W | Inside the fence | At B1, `/attendance/mobile` → check in | 201; geofence verified; confidence 90 (88 with coordinates only, 95 with a photo) |
| MOB-002 | P1 | W | Outside, geofence required | Geofence required on; EMP in office mode with no work site open to EMP; check in 1 km from B1 | 422 "You are outside the allowed check-in area. Please move closer to your branch location." Inside any site open to EMP counts as inside (PLACE-002, PLACE-003) |
| MOB-003 | P1 | W | No location, geofence required | Location permission denied | 422 "GPS location is required…" |
| MOB-004 | P1 | W | Outside, geofence not required | Geofence required off; check in 1 km away | Accepted and flagged `outside_geofence` |
| MOB-005 | P1 | W | Photo consent first | Mobile photo required on; EMP has never consented; check in | The photo notice (version `2026-10-17.2`) is shown; without consent: 422 "Agree to the attendance photo notice…"; after agreeing, the selfie is taken and saved |
| MOB-006 | P1 | W | Photo required | Photo required on; send no photo (API) | 422 "Take a selfie…" |
| MOB-007 | P2 | W | Photo size | Send a selfie over 1.5 MB decoded (API) | Refused; through the interface the photo is shrunk first and saves |
| MOB-008 | P2 | W | Consent withdrawn | EMP withdraws consent in Profile; checks in with a photo | Refused until consent is given again. Consent to an older version no longer counts |
| MOB-009 | P2 | W | Low accuracy | Report accuracy 300 m (threshold 100) | Accepted and flagged `low_accuracy` |
| MOB-010 | P2 | W | Automatic check-in | Turn on the organisation's (or B1's) geofence auto check-in and EMP's own; arrive at B1 or one of EMP's work sites within an hour before shift start | Checked in automatically. Either switch off, or a Remote employee: 422 "Automatic check-in is not turned on for you." Outside every place: 422 "Automatic check-in needs you to be inside your branch area." The phone tries only from 60 minutes before start until start plus grace, only with accuracy at most max(threshold, 50 m), and never while a selfie is required. Under "Only the registered phone" it never succeeds (PHN-019) |
| MOB-011 | P2 | R | My shift | `GET /attendance/my-shift` | Date, time zone, working day or not, holiday, leave, shift window, status (not checked in, checked in, checked out, auto checked out), reminder settings. `policy` holds `mobile_enabled`, `web_enabled`, `qr_enabled`, `attendance_mode` with branch rules applied, `photo_required` (branch rule and adaptive selfies), `liveness_required`, and `places` (branch plus open work sites; empty for Remote). `auto_check_in_allowed` false when there are no places |
| MOB-012 | P2 | X | Reminders | Reminders on, lead 10 min; EMP subscribed to "Even when ETHR is closed" (PUSH-002); close ETHR | Reminders arrive with ETHR open (in the app) and closed (web push from `attendance:push-reminders`, every 5 minutes through the cron caller): "Your shift starts at :time." 10 min before; "You have not checked in yet. Your shift started at :time." when grace runs out; "Your shift ended at :time. Remember to check out." at the end while checked in. Each at most once per person per day; none on leave, holidays or rest days |

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
| KIO-003 | P1 | W | Punch by code | Enter EMP's employee code with the kiosk on "Automatic" → "Record"; then choose Check in or Check out explicitly for EMP2 | Recorded; the greeting follows the server's `punch` ("Welcome, :name" or "Goodbye, :name"); the screen resets after 4 s and returns to Automatic. An explicit choice is honoured |
| KIO-004 | P1 | W | PIN required | Kiosk PIN required for QA Lobby's branch (organisation setting or B1 rule); HR sets EMP's PIN `1357` (4 to 6 digits); punch without, with a wrong, with the right PIN | Without: 422. Wrong: 401 "Invalid employee PIN." Right: recorded. An employee with no PIN: 422. The rule is the kiosk's branch, not the employee's (BRR-006) |
| KIO-005 | P1 | W | PIN lock | Wrong PIN 5 times for EMP | 429 with `Retry-After`; EMP locked at this kiosk for 15 minutes; audited `kiosk.pin_locked`. Other employees unaffected |
| KIO-006 | P2 | W | Unknown code | Enter a code that does not exist | 404 |
| KIO-007 | P1 | W | Exit | Exit with a wrong admin PIN, then the right one | "Incorrect admin PIN."; then the kiosk closes (204, audited `kiosk.exited`). More than 5 exit attempts a minute: 429 |
| KIO-008 | P1 | W | Deactivate and regenerate | Deactivate the session; the tablet tries to punch. Activate, regenerate the token; the tablet tries the old token | 401 "Invalid or inactive kiosk session." in both cases |
| KIO-009 | P1 | W | Offline kiosk | Disconnect the tablet's network; punch two employees on Automatic; reconnect | Queued on the tablet, then synced, each created or duplicate; the time is the tablet's punch time, not the sync time. An Automatic punch is decided on sync, and one older than an open record's check-in is a check-in, never a check-out (PUNCH-006) |
| KIO-010 | P2 | R | Kiosk on another organisation | Present QA Audit's kiosk token on QA Isolation's address | 401 |
| KIO-011 | P2 | W | Kiosk disabled | Turn the kiosk method off for QA Lobby's branch; punch. Then on there but off at B2, and EMP2 punches at QA Lobby | First: 403. Second: 422 "This attendance method is not enabled for your organization." (the engine checks the employee's branch) |
| KIO-012 | P2 | W | Staff kiosk entry | HR (signed in) records a kiosk punch for EMP via `POST /attendance/kiosk`; SUP tries the same | HR allowed; SUP 403 |

## 5a. The punch each screen offers

The web Attendance page and the QR scanner read `GET /attendance/my-shift`, as the phone screen
does. The shared kiosk sends `type: "auto"` unless the person picks one; the server closes the open
record or opens a new one, and answers `punch: "check_in" | "check_out"` (201 for a new check-in,
200 for a check-out or a replay).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PUNCH-001 | P1 | W | Web page after a phone check-in | EMP checks in on the phone; opens Attendance on a computer; checks out there; reloads | Only "Check Out" is offered, as the primary button. 200, the same record closed, no second record, no conflict. Then only "Check in again" |
| PUNCH-002 | P2 | R | No answer means both | A user with no employee record (or `my-shift` failing) opens Attendance | "Check In" and "Check Out" both shown |
| PUNCH-003 | P1 | W | QR starts on the right punch | EMP checked in on the phone opens Attendance → Scan | The selector starts on Check out; scanning closes the day (200); changing the selector still overrides it |
| PUNCH-004 | P1 | W | Kiosk Automatic | QA Lobby: EMP (no record today) types the code → "Record". Later EMP2, checked in on the phone at 08:02, types the code at 17:05 | "Automatic" selected, hint "Type your code. The kiosk checks you in when you arrive and out when you leave." EMP: 201, `punch: "check_in"`, "Welcome, :name". EMP2: 200, `punch: "check_out"`, "Goodbye, :name", one record only. After the reset, back on Automatic |
| PUNCH-005 | P1 | W | Typed twice, and retried | EMP types the code again within 5 minutes of checking in; resend a check-out with the same idempotency key (API) | Second code: 200, `was_duplicate: true`, `punch: "check_in"`, record not closed. Retry: 200, `was_duplicate: true`, `punch: "check_out"` |
| PUNCH-006 | P2 | W | Offline Automatic backlog | Kiosk offline: EMP punches Automatic at 08:00; EMP checks in on the phone at 08:30; reconnect the kiosk | The 08:00 punch syncs as a **check-in** and never closes the 08:30 day. The two merge if within 5 minutes; otherwise a conflict, as CON-001 |
## 6. Integrity flags and review

Flags never block a punch. They queue it for review in Attendance → Integrity (needs
`attendance.viewConflicts`; deciding needs `attendance.resolveConflicts`).

| Flag | Severity | How to produce it |
|---|---|---|
| `low_confidence` | low | Check-in confidence below the threshold (raise the threshold to 96 and do a web check-in) |
| `outside_geofence` | high for offline and web, medium otherwise | Outside the branch and every work site open to the person (MOB-004, BRR-010). Never raised for Remote staff. Details name the nearest place |
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
| `geofence_unconfigured` | low | Geofence required, a self-service punch with a location, and no place to check against: no branch, or a branch not on the map (SETUP-002) |
| `high_risk` | medium below 70, high at 70 and above | Risk score at or above the review threshold (section 6a) |
| `buddy_pattern` | high | Daily pattern scan (RISK-009) |
| `grace_edge` | low | Daily pattern scan (RISK-009) |

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| INT-001 | P1 | W | Flags appear | Produce at least five of the flags above | Each listed once per record and type, open first, sorted by severity; the sidebar shows the count to review |
| INT-002 | P1 | W | Accept | HR accepts a flag with a note | Closed; the record stands |
| INT-003 | P1 | W | Reject | HR rejects a flag without a note; then with a note | Note required. Rejecting voids the record, closes its other open flags, notifies the employee ("punch rejected") and is audited |
| INT-004 | P1 | R | No self-review | HR reviews a flag on HR's own punch | 403 "You cannot review a flag on your own attendance." |
| INT-005 | P2 | W | Closed flag | Review an already reviewed flag | 422 |
| INT-006 | P2 | R | Summary | `GET /attendance/integrity/summary` | Counts agree with the list |

## 6a. Risk score, liveness and pattern scans

Every engine punch gets a `risk_score` from 0 to 100 and `risk_reasons`, with fixed weights
(`AttendanceRiskScorer::WEIGHTS`); check-in and check-out are scored as one day. At or above the
review threshold (organisation `risk_review_threshold`, default 40, 10 to 100, overridable per branch)
the day gets one `high_risk` flag, medium below 70 and high at 70 and above, only when the punch itself
added a reason. **A score never refuses a punch.** At 70 and above, active TA and HR users are told in
the app (and by push): ":name punched with a risk score of :score. Review it before payroll." Settings
are under Settings → Attendance → "Cheating protection". Liveness is "Live face check before selfies"
(`liveness_check`, off by default); it runs only when a selfie is due (`my-shift`
`policy.liveness_required`).

| Reason | Points | Reason | Points |
|---|---|---|---|
| `outside_geofence` | 20 | `shared_device` (install punched for someone else in 14 days) | 35 |
| `location_missing` | 10 | `ip_burst` (another person on the same IP within 2 min, IP not a learned office network) | 20 |
| `suspect_location` | 35 | `stale_fix` (fix older than 120 s) | 15 |
| `low_accuracy` | 5 | `automation` (`webdriver: true`) | 40 |
| `impossible_travel` | 40 | `missing_fix_details` | 15 |
| `branch_mismatch` | 15 | `clock_skew` (client clock over 300 s off) | 15 |
| `unverified_offline` | 30 | `photo_replayed` / `photo_of_someone_else` | 40 / 50 |
| `clock_suspect` | 30 | `unusual_place` (over 3 km from median, 10+ history) | 15 |
| `late_sync` | 5 | `unusual_time` (over 3 deviations, 10+ history) | 10 |
| `photo_missing` | 15 | `device_switch` (checked out on another install) | 10 |
| `device_clock_ahead` | 10 | `liveness_failed` / `no_face` | 25 / 20 |
| `low_confidence` | 10 | `device_*` (registered phone) | section 15 |

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| RISK-001 | P1 | W | Clean punch | EMP checks in on the phone at B1 normally | `risk_score` 0, `risk_reasons` null, no `high_risk` flag |
| RISK-002 | P1 | W | One phone, two people | EMP checks in on a phone and signs out; EMP2 signs in on the same phone and browser and checks in within 2 minutes | Accepted (201). Reasons include `shared_device` (35), plus `ip_burst` (20) when the network is not a learned office one: 55, a medium `high_risk` flag. With only 35, no flag at the default threshold |
| RISK-003 | P1 | W | Threshold, organisation and branch | Repeat RISK-002 with the organisation threshold 100, then B1's branch threshold 30; save 9 and 101 | At 100: no flag. B1 at 30: flagged for B1 staff only. 9 and 101 refused |
| RISK-004 | P1 | W | Score of 70 tells reviewers | EMP phone check-in by API inside B1 with `webdriver: true`, `client_time` 10 minutes behind, `fix_age_ms: 300000` | Score at least 70 (40 + 15 + 15), `high_risk` high, punch 201. Each active TA and HR user gets one in-app notice: "… punched with a risk score of 70. Review it before payroll." A second punch that day does not notify again |
| RISK-005 | P1 | R | Review shows why | HR opens the RISK-004 flag in Attendance → Integrity | "Risk score :score"; "Several signals together reached your review threshold of :threshold. The reasons are listed below."; labelled reasons ("Browser driven by automation", "Location was minutes old", "Phone clock far from the server's"); ":count findings for this person in 90 days"; "See the selfie" (with a photo; "Viewing this selfie is recorded in the audit log."); "Show on map". Accept and reject as INT-002, INT-003 |
| RISK-006 | P1 | W | Payroll lists open serious findings | Leave the RISK-004 flag open; FIN processes a QA run covering that day; HR decides the flag; FIN reloads | "Punches waiting for review", ":count employee(s) have :flags serious finding(s) in this period still waiting for review." Pay identical either way. Banner gone once decided |
| RISK-007 | P2 | W | Reused selfie | Photo required. EMP checks in with a selfie; the next day sends the identical image (API); EMP2 sends the same image | EMP: `photo_replayed` (40), flagged. EMP2: `photo_of_someone_else` (50), flagged. Neither refused |
| RISK-008 | P2 | W | Adaptive selfies | Photo required off, "Adaptive selfies" On. `my-shift` for a new employee; one with records older than 90 days and no findings; one with a rejected flag in the last 90 days | New: `photo_required: true` every day. Clean established: true about one day in five, and a phone punch without a photo is refused only on those days. With a finding: true every day |
| RISK-009 | P2 | W | Daily pattern scan | EMP and EMP2 check in on the same install within 60 s of each other on 3 days within 14; wait for `attendance:pattern-scan` (daily 16:00 UTC, 19:00 EAT, through the cron caller) | `buddy_pattern`, high: "One phone, two people", "Punched in on the same phone as a colleague, within a minute, on :days days in two weeks." The next run updates it rather than repeating. `grace_edge` (low, "Always on the grace edge") when 8 of the last 10 arrivals fell in the 3 minutes before late |
| LIVE-001 | P1 | W | Liveness passes | "Live face check before selfies" On, selfie required. EMP checks in on the phone | Before the photo: "Blink once" or "Turn your head to one side" (random); doing it takes the photo. 201, `liveness: "passed"`, no points |
| LIVE-002 | P1 | W | Liveness fails, punch kept | Hold a printed photo up, or turn away 7 s; two faces in frame; lower the threshold to 20 and repeat | "We could not confirm a live face. Face the camera in good light and try again." The photo is still taken and the punch recorded (201): `liveness_failed` (25), or `no_face` (20) when no face was seen. At threshold 20, a `high_risk` flag |
| LIVE-003 | P2 | R | On the device, only when due | Network panel open: punch with liveness on, then off; send `liveness: "maybe"` (API) | The model is fetched from storage.googleapis.com only when the check is on and a selfie is due; only the selfie and the outcome word are sent. `"maybe"`: 422. `"unavailable"` adds no points. Off or no selfie due: no prompt |
## 7. Offline mobile attendance

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| OFF-001 | P1 | W | Grant | EMP opens the mobile page online | The app holds an offline grant valid 72 h (renewed when under 24 h remain). With a registered phone and the rule on, `GET /attendance/offline-grant` also returns `device_nonce`, `credential_id` and `rp_id`. One grant covers at most 20 offline punches; after that a punch counts as not from the registered phone |
| OFF-002 | P1 | W | Punch offline | Airplane mode; check in; wait 10 min; reconnect | "Waiting to sync" indicator; then synced. The record's time is the offline time; result `created` |
| OFF-003 | P1 | W | Replay | Force the app to sync the same record again (or re-send via API) | Result `duplicate`; no second record |
| OFF-004 | P1 | W | Bad grant | Registered phone Off or "Check and review". Sync a record with a missing or altered grant (API) | Recorded and flagged `unverified_offline`, never refused. Under "Only the registered phone" the same record is a per-record `error`, `code` `device-proof-invalid` (or `device-not-registered` for a person with no phone) |
| OFF-005 | P1 | W | Too old or in the future | Sync a record dated 8 days ago, and one 10 minutes ahead (API) | Per-record error "…captured more than 7 days ago…"; the other records in the batch still process |
| OFF-006 | P1 | R | Someone else's record | EMP syncs a record for EMP2 (API) | Per-record error "You can only sync your own attendance." |
| OFF-007 | P2 | W | Batch size | Sync 101 records | Refused (1 to 100) |
| OFF-008 | P2 | W | Offline disabled | Offline sync off; sync | Method not allowed (422) |
| OFF-009 | P2 | W | Photo without consent offline | Offline punch with a photo, no consent | Punch kept, photo dropped |

## 7a. Field visits

`POST /attendance/visits` (`attendance.checkIn`; idempotent; follows the phone method at the person's
branch and their photo consent). `GET /attendance/visits/mine` lists one's own. `GET
/attendance/visits?date=&employee=` needs `attendance.viewAll` (HR, FIN, TA), limited to reach. **A
visit never opens or closes a record and never changes hours.** The "Visits" card appears on
`/attendance/mobile` only for Field and Remote staff while phone check-in is on; managers use
Attendance → Visits.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| VIS-001 | P1 | W | Log a visit | EMP (Field, checked in) at QA-Site North: "Log a visit", note "QA client", a photo → "Log it" | "Visit logged", 201, the site named. EMP's record and hours unchanged; no new record |
| VIS-002 | P1 | W | Offline, dated, replayed | Airplane mode: log a visit, wait 10 min, reconnect. API: `occurred_at` 8 days ago; resend with the same `idempotency_key` | "Saved on this phone. It will be sent when you are back online.", ":count waiting to be sent", then sent and dated when logged. Old: 422 "This punch was captured more than 7 days ago, or in the future, so it cannot be synced. Ask HR to enter it." Replay: 200, the same visit |
| VIS-003 | P1 | W | Rules | Mobile off at EMP's branch, log a visit (API); a photo with consent withdrawn; location denied; then EMP in office mode | "This attendance method is not enabled for your organization."; 422 on `photo`, "Agree to the attendance photo notice before taking a selfie. You can do it from the check-in screen or your profile."; "Your location is needed to log a visit. Allow location and try again." (nothing sent); office mode: no Visits card |
| VIS-004 | P1 | R | Who sees visits | HR and FIN: Attendance → Visits for VIS-001's day; SUP and DA open the menu and the API; EMP calls `/visits` and `/visits/mine` | HR, FIN: EMP's visit with "On the map". SUP, DA: no menu item, API 403. EMP: `/visits` 403, `/visits/mine` own only |
| VIS-005 | P3 | W | Date filter at night | Log a visit at 01:30 EAT; filter Visits by that EAT date | Expected on that EAT day. Suspected defect: the filter uses the UTC date, so it may show under the previous day (K-23) |
## 8. Manual entry, import, corrections, overtime, conflicts

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| MAN-001 | P1 | W | Manual day | HR: Attendance → manual entry for EMP3, yesterday 08:35 to 17:30, reason | Recorded; rated present; exactly one `attendance.manual_entry` audit row |
| MAN-002 | P1 | W | Rules | Date tomorrow; reason over 500 characters; check-out equal to check-in; night entry 21:55 to 06:05; entry 08:00 to 07:30 | Tomorrow, long reason and equal times refused. 21:55 to 06:05 accepted: the check-out is the next morning and the record is dated the night it began. 08:00 to 07:30 (23.5 h): 422 "The check out field must be a date after check in." (14 h or more is treated as a mistyped time) |
| MAN-003 | P2 | W | Merge | Manual entry within 5 minutes of an existing punch | Merged; only a later check-out is added |
| MAN-004 | P1 | W | Closed payroll day | Manual entry on a day inside an approved payroll run | 422 `payroll-period-closed`, "This day is in an approved payroll run, so its attendance cannot be changed. Void the run first to reopen it." |
| AIMP-001 | P1 | W | Import | Template (`employee_code,date,check_in_time,check_out_time`), preview 5 rows with one bad date, one bad time, one unknown code | Per-row: "Invalid date", "Invalid check_in_time (expected HH:MM)", "Employee 'X' not found" |
| AIMP-002 | P1 | W | Commit and replay | Commit the valid rows; commit again with the same key | 201 `{created, skipped, errors}`; the replay skips all. Each row is matched to that day's shift and rated like a live punch (late, early leave, present); with no shift it is **pending**. A check-out earlier on the clock than the check-in goes on the next day (CAP-001, CAP-002) |
| AIMP-003 | P2 | R | Legacy file | Upload a BioTime or Hikvision CSV to legacy parse | Returns the parsed records and errors; writes nothing |
| COR-001 | P1 | W | Request | EMP: Corrections → request on yesterday's record, proposed times, reason | Pending; SUP notified |
| COR-002 | P1 | W | Approve | SUP approves | Record shows proposed times, keeps the originals, status recalculated (a late arrival corrected to 08:30 becomes present); `attendance.corrected` webhook fires (if one is subscribed); EMP notified |
| COR-003 | P1 | W | Reject | SUP rejects without, then with, a reason | Reason required (up to 1,000); EMP notified |
| COR-004 | P1 | R | Self-approval | HR files a correction for HR's own record and approves it; HR files one for EMP and approves it | "You cannot approve a correction to your own attendance."; "You filed this correction, so someone else must approve it." Rejecting one's own is allowed |
| COR-005 | P2 | W | Not pending | Approve an already decided correction | 422 "…no longer pending." |
| COR-006 | P2 | R | Payroll impact | HR (with `employee.viewFinancial`) opens payroll impact | Hours, difference in minutes, estimated cost at salary ÷ 176 h, whether it falls in an open period. SUP without financial rights: refused |
| COR-007 | P1 | W | Closed day | Correction on a day in an approved run | 422 payroll period closed |
| COR-008 | P1 | W | "I was at work" | EMP has no record for yesterday, a workday. EMP: My Attendance | "Needs your attention", "Payroll will settle these days. If you were at work on a day marked absent, say so now.", the day "absent, nothing on record". "I was at work" opens Corrections on that day: "Nothing is on record for this day. Enter the times you worked, and once approved the day is recorded." Submitted: pending, badge "Missed punch"; the card shows "Waiting for a decision". SUP approves: a record is created and rated against the shift, and the day leaves Reconcile ([07, section 8](07-E2E-PAYROLL.md#8-attendance-reconciliation-before-payroll)) |
| COR-009 | P1 | W | Missed-punch rules | Claim today; a day that has a record; a second claim for a pending day; check-out before check-in; a day in an approved run. HR claims for EMP3 (`employee_public_id`); SUP claims for a report | "A missed punch can only be claimed for a day that has passed."; "There is already attendance on record for that day. Correct that record instead."; "A claim for that day is already waiting for a decision."; "The check-out must come after the check-in."; 422 payroll period closed. HR: accepted. SUP: 403 (no `attendance.manage`) |
| OT-001 | P1 | W | Request overtime | EMP: Overtime requests → tomorrow, 120 min, reason | Pending; SUP notified |
| OT-002 | P1 | W | Rules | Date yesterday; 10 min; 241 min; reason of 2 characters; a second request for the same day | Refused (today or later, 15 to 240 min, reason 3 to 1,000, "Overtime is already requested for this day…") |
| OT-003 | P1 | W | Decide | SUP approves one, rejects one with a reason | Recorded; EMP notified |
| OT-004 | P1 | R | Self-approval | SUP requests overtime and approves it | 403 |
| OT-005 | P2 | W | Cancel | EMP cancels a pending request; then a decided one | First cancelled; second 422 |
| CON-001 | P1 | W | Conflict | Record a web check-in and, 10 minutes later, a kiosk check-in for the same person and day | A conflict (`multi_source_far`) in Attendance → Conflicts |
| CON-002 | P1 | W | Close sources merge | Two punches 3 minutes apart from different sources | Merged: earliest check-in and latest check-out kept, the other voided, no conflict |
| CON-003 | P1 | W | Resolve | Resolve CON-001 with keep A; another with dismissed | Keep A voids B; dismissed changes no record. Already resolved: 422. Resolving one's own: 403. Keep on a closed payroll day: refused |
| CAP-001 | P1 | W | One night, three channels | EMP3 on S2 (22:00 to 06:00). Night 1: web check-in 21:55, out 06:05. Night 2: manual entry 21:55 to 06:05. Night 3: a CSV row, same date and times | Each record dated the night it began, checked out next morning, rated present, equal hours; the payroll run shows the same overtime for each (#443, every capture channel leaves payroll the same day) |
| CAP-002 | P1 | W | Imported rows are rated like punches | Import a row for EMP on an S1 weekday 08:50 to 17:30; a row for EMP2 on a rotation rest day | EMP: late (as a live punch at 08:50). EMP2: pending, not present |

## 9. Shifts, assignments, rotations, roster

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SHF-001 | P1 | W | Create | HR: Shifts → S1 "QA Day" 08:30 to 17:30, grace 15, early 15, break 60, Monday to Friday | Saved. Same start and end, grace 121, break 181, working days `8` refused |
| SHF-002 | P1 | W | Night shift | S2 "QA Night" 22:00 to 06:00, crosses midnight | Saved |
| SHF-003 | P1 | W | Night shift punches | Assign S2 to EMP3; check in 21:55, check out 06:05 next morning | One record, present, closed by the morning check-out |
| SHF-004 | P1 | W | Night shift lateness after midnight | On S2, check in at 00:30 | Late, dated **the night the shift began** (the previous date), not the check-in's own date. Present, or dated the same day: defect |
| SHF-005 | P1 | W | Default shift | Mark S1 default | Any other default is cleared |
| SHF-006 | P1 | W | Delete in use | Delete S1 while assigned (or while it is the default and another shift is active) | 409 `shift-in-use`; the detail names every reason (assignments, rotation steps, default shift) |
| SHF-007 | P2 | W | Duplicate | Duplicate S1 | A copy named "… copy", not default, no assignments |
| SHF-008 | P2 | R | Usage before edit | Edit S1's start | Usage preview shows assignments, people, rotations and records, and says past statuses are not recalculated |
| SHF-009 | P1 | W | Assign | Assign S1 to D1 from today; S2 to EMP3 directly | Employee assignment beats department, department beats branch; within a level the latest start date wins |
| SHF-010 | P2 | W | End or delete assignment | End an assignment (set end date); delete a started one; delete a future one | Ended; 409 `assignment-in-effect`; deleted |
| SHF-011 | P1 | W | Rotation | 14-day rotation: days 0 to 4 S1, days 7 to 11 S2, rest otherwise; preview 28 days; assign to EMP2 | Preview correct; rest days are rest days (no fall-through to a department shift). Duplicate or out-of-cycle offsets refused; preview over 366 days refused |
| SHF-012 | P2 | R | Roster | Shifts → Roster | Colour-coded schedule; SUP sees only employee-level assignments within reach |

### 9a. A default schedule from the first minute

Sign-up and the hourly `attendance:baseline` sweep give an organisation with no active shift its
industry's usual week, default settings, and holidays for this year and next. While any shift is
active, exactly one is the default.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| BASE-001 | P1 | W | New organisation rates its first punch | QA Isolation (registered as an office-type organisation, no wizard run): open Shifts and Holidays; its first employee checks in at 08:50 EAT | Shift "Regular" 08:30 to 17:30, Monday to Friday, break 60, grace 15, default. Holidays for this year and next. The check-in is **late**, not pending. No selfie or auto check-out switched on |
| BASE-002 | P2 | R | Six-day week | A `uat-` organisation registered as a bank: Shifts → Rotations | Default rotation "Weekdays + Saturday morning": Weekday 08:00 to 17:00 Monday to Friday, Saturday 08:00 to 12:00. A Saturday check-in at 08:20 is rated against 08:00 |
| BASE-003 | P1 | W | One default, always | QA Audit, S1 default, S2 active: untick default on S1; make S1 inactive; delete S1; then save S2 as default | Untick: 422 "Make another shift the default first. People with no shift of their own are rated against the default." Inactive: 422 "The default shift must be active." Delete: 409 `shift-in-use`, detail including "it is the default shift, used for everyone without a shift of their own (make another shift the default first)". After S2 is saved as default, S1 is not |
| BASE-004 | P2 | W | New shift copies the default | Create a shift with only a name and times (API) | Grace, early departure, break and working days copied from the default shift. Attendance settings have no grace field |
## 10. Auto check-out, missing punches, absences

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| AUTO-001 | P1 | W | Auto check-out | Auto check-out on, after 120 min. EMP checks in on S1 and never checks out | Between 19:30 and about 19:40 EAT (shift end + 120 min, run every 5 minutes) the record is closed **at 17:30**, marked auto checked out, reclassified, audited, and EMP notified |
| AUTO-002 | P2 | W | Not applied | A check-in made after the shift end; a check-in 3 days old | Not auto-closed |
| ABS-001 | P1 | R | Absent | EMP3 (workday, no leave, no holiday) does not check in | Team today and the dashboard show EMP3 absent. On a holiday, a rest day or approved leave: holiday, rest day, on leave, not absent |
| ABS-002 | P2 | X | Missing punch notice | The day after a missing check-out | Daily at 15:30 UTC (18:30 EAT) the employee and supervisor are notified |
| ABS-003 | P1 | R | Manager card counts real absences | SUP's dashboard team card on a day when EMP is on approved leave, EMP2 checked in, one member on a rest day or holiday; open it also between 00:00 and 03:00 EAT | Absent counts only workdays with no record; leave, rest days, holidays and leavers are not absent. The day is the EAT date, not UTC |

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

## 15. Registered phones (device binding)

`GET /attendance/device`, `POST /attendance/device/register-options`, `/attendance/device/register`
and `/attendance/device/challenge` need `attendance.checkIn` (everyone). `GET /attendance/devices`,
`GET /attendance/devices/unregistered`, `POST /attendance/devices/{id}/approve` and `/reject`, `DELETE
/attendance/devices/{id}` and `PUT /employees/{id}/attendance-device-exemption` need
`attendance.manage` (HR, TA). The setting is `device_binding`: `off`, `flag` or `enforce`, shown as
"Off", "Check and review" and "Only the registered phone", set for the organisation and overridable
per branch (section 1a).

Every phone used needs a screen lock: an Android phone with Chrome, or an iPhone with iCloud Keychain
on. A laptop with Windows Hello or Touch ID also counts as a "phone", and DevTools works there, which
is why the API cases use one. A refusal is 422 `application/problem+json`, `title` "Device Not
Accepted", `type` `https://ethr.et/errors/<code>` and `code`. QA Audit only. Set the rule back to Off at
the end, and delete the `ethr.et` passkeys from every test phone's password manager afterwards:
revoking a phone in ETHR does not remove its passkey. Passkeys belong to the platform domain, so they
work on `www.ethr.et/{slug}` and `{slug}.ethr.et`, not on a custom domain (K-32).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PHN-001 | P1 | R | Who manages phones | HR and TA open Attendance → Phones. SUP, DA, FIN look for the page, then call `GET /attendance/devices` and `POST /attendance/devices/{id}/approve` | HR, TA: "Phones" with cards "People with a phone" (`registered / employees`), "Waiting for approval", "Still to register"; filters "Waiting" (default), "Not registered", "Registered", "Revoked or refused"; search "Search by name or code". SUP, DA, FIN: no sidebar entry, API 403 |
| PHN-002 | P1 | W | The setting | HR: Settings → Attendance → Cheating protection → "Registered phone". API `PUT /attendance/settings`: `device_binding: "strict"`; 21 `office_networks`; `["10.0.0.0/7"]`; `["196.188.10.0/24"]` | Default "Off"; choices "Off", "Check and review", "Only the registered phone". Off: no phone card on `/attendance/mobile`, `GET /attendance/device` returns `binding: "off"`, punches as sections 2 to 7. API: "The selected device binding is invalid."; 21 refused (at most 20); `/7` refused (IPv4, /8 to /32); `/24` saved |
| PHN-003 | P2 | W | Branch rule | Organisation "Check and review"; B1 rule "Only the registered phone". EMP (B1) and a B2 employee check in on the web, neither registered. Then clear B1's rule | EMP: 422 `device-not-registered`. B2 employee: 201. Cleared: B1 inherits the organisation's rule |
| PHN-004 | P1 | W | First phone, at work | Rule on (flag or enforce). EMP at B1 on their own phone (location on, accuracy within 100 m) opens `/attendance/mobile` → **Register**, unlocks. Repeat on a computer with no Windows Hello or Touch ID | "Register this phone": "Register the phone you punch with while you are at work. Only this phone can then punch for you." → "Phone registered."; the card becomes "This is your registered phone": "Your punches are confirmed with this phone's fingerprint, face or PIN." Active at once. Phones → Registered lists it ("Android, Chrome" or "iPhone, Safari", "not used to punch yet"; "Synced passkey" when it syncs). Audit `attendance.device_registered` (`inside_geofence: true`). Nobody notified. Computer: "This browser cannot hold a passkey", no button |
| PHN-005 | P1 | W | First phone, away from work | EMP2 registers at home, or 1 km from B1, or with accuracy over 100 m, or location denied. Repeat with a Remote employee standing at B1 | "Sent for approval. You were not at work, so a manager will confirm it."; card "Waiting for approval": "This phone was registered away from work. Your manager or HR will approve it." HR and TA (not SUP) told in the app: "… registered a phone away from work. It punches once you approve it." Phones → Waiting: badge "Registered away from work". Audit `attendance.device_requested`. A Remote worker always waits, even at B1 |
| PHN-006 | P1 | W | Approve | HR approves EMP2's phone, then again through the API | "Phone approved.", status `active`. EMP2 told: "Your attendance phone was approved. Punch with it from now on." Audit "Attendance phone approved" (`replaced: 0`). Second: 409 "This phone is not waiting for approval." |
| PHN-007 | P1 | W | Enforce: no phone, or one still waiting | "Only the registered phone". EMP3 (no phone) checks in on the web, `/attendance/mobile`, QR. An employee whose only phone is waiting does the same. Then a kiosk punch (KIO-003) and a manual entry (MAN-001) for EMP3 | Each self-service punch: 422 `device-not-registered`, "This phone is not registered for your attendance. Register it from the attendance page while you are at work, or ask HR." Waiting phone: `device-pending-approval`, "Your new phone is waiting for approval. Use your registered phone until it is approved." Nothing recorded. Kiosk and manual entry recorded, never ask for a phone |
| PHN-008 | P1 | W | Enforce: the registered phone | EMP checks in and out on the registered phone, unlocking when asked; then taps Check In and dismisses the prompt | 201, then 200; Phones shows "last punched … ago". Dismissed: "Unlock your phone when it asks, to confirm it is you."; nothing sent or recorded |
| PHN-009 | P1 | W | Check and review | "Check and review"; risk threshold 35. EMP3 (no phone) checks in on the web from a network no other tester used in the last 2 minutes. Threshold back to 40; repeat with another unregistered employee | Both recorded (201). At 35: Integrity lists `high_risk`, medium, reason "Not from the employee's registered phone" (35 points). At 40 with no other reason: nothing in Integrity (K-31) |
| PHN-010 | P1 | W | Someone else's phone | On EMP's registered phone, same browser, sign in as EMP2 → Register. Then another browser app on the same handset | Same browser: 422 `device-belongs-to-another`, "This phone is registered to another employee. Punch from your own registered phone."; audit `attendance.device_refused` (`belongs_to_another`) on EMP2; nothing created. Other browser app: accepted (the check is per browser, K-36) |
| PHN-011 | P1 | W | Replace a phone | Enforce. EMP on a second phone → "Register this phone". Punch from both; HR approves; punch from both again | Before: "Your registered phone: <label>", "Last punched … ago. On a different phone now? Register this one and HR will approve it. Your old phone stops working once they do." After tapping: "Sent for approval. Your old phone works until HR approves this one." New phone: "This phone is waiting for approval"; old: "A new phone is waiting for approval. Keep using this one until then." HR, TA told "… asked to replace their attendance phone."; waiting list "Replaces <label>, last punched … Approving stops that phone." Before approval the old phone punches and the new cannot; after, the reverse; the old is "Revoked or refused", audit `replaced: 1`. A second request before approval closes the first as "Refused" |
| PHN-012 | P1 | W | Refuse and revoke, with a reason | HR rejects a waiting phone; revokes EMP's active phone "Reported lost". API: revoke again; reject with no reason; a 301-character reason. EMP registers again at B1, and another time away | "Refuse this phone" / "Revoke this phone", reason up to 300 characters required on screen. "Request rejected.", "Phone revoked." Employee told "Your attendance phone was not approved: <reason>" and "Your attendance phone was revoked: Reported lost. Register the phone you use now." Audits `attendance.device_rejected`, `attendance.device_revoked` with the reason. Second revoke: 409 "This phone is not registered now." No reason (API): accepted, "Your attendance phone was not approved." 301: 422. Again at B1: active at once; away: waiting |
| PHN-013 | P1 | W | Leaver | HR creates a disposable employee with a login in B1 who registers a phone at B1; HR records a resignation effective today. EMP3 then signs in on that phone, same browser, and registers at B1 | The phone moves to "Revoked or refused", any waiting request closed; audit `reason: left`; the leaver not notified. EMP3's registration accepted (active). Coverage no longer counts the leaver. A future-dated termination revokes only when the status changes on that date (LIFE-012) |
| PHN-014 | P2 | R | Not registered list | Rule on. Phones → "Still to register"; compare with `GET /attendance/devices` (`coverage`) and `GET /attendance/devices/unregistered` | Current staff whose phone is checked and who have no active phone: name, code, branch or "No branch", badge "Phone waiting for approval". Excluded: leavers, exempt people, branches whose rule is Off or allow no web, mobile or QR punch. "Of <n> people whose phone is checked". Empty: "Everyone checked has a phone". Rule Off everywhere: "Phones are not checked yet. Turn it on in attendance settings." Counts agree with `coverage` |
| PHN-015 | P1 | W | Exemption | HR: Not registered → "Punch without a phone" for EMP3 (default 7 days ahead) → Save; or from EMP3's profile "Punch without the registered phone" → Save, then End. API: `until` yesterday; 92 days ahead; `until` missing; SUP calls it. Under enforce EMP3 checks in on the web | "Let <name> punch without a phone": "Until this date, for a phone that cannot hold a passkey or is broken. Their punches are still sent for review." "Saved." API: "The until field must be a date after or equal to today."; 92 days refused; "The until field must be present."; SUP 403. EMP3's punch 201, scored 35 (K-31); EMP3 leaves the list. Audit `employee.attendance_device_exemption` |
| PHN-016 | P1 | W | Exempt, but a phone still registered | Enforce. EMP has an active phone; HR exempts EMP; EMP checks in on a computer browser and dismisses the phone prompt. Repeat on an office network (PHN-017) | Expected: accepted (the server allows exempt people and office networks). Suspected defect (K-30): the screen still asks for the passkey, shows "Unlock your phone when it asks, to confirm it is you." and sends nothing. Workaround: revoke the old phone |
| PHN-017 | P2 | W | Office network | HR enters the office's public address under "Office networks for computer punches". Under enforce EMP3 (no phone) checks in on the web from an office computer; on `/attendance/mobile` from the same network; on the web from another network | Office web: 201 (verdict `office_pc`, "Office computer, no phone", 5 points). Mobile on the same network: 422 `device-not-registered` (the exception covers the web only). Other network: 422. If the office punch is refused, note the address the server recorded (K-33) |
| PHN-018 | P2 | W | Offline under enforce | EMP (registered) opens `/attendance/mobile` online, airplane mode, checks in (unlocks), reconnects. EMP3 (no phone) the same. API: EMP's signed record with the latitude changed | EMP: `created`, at the offline time. EMP3: `error`, `code` `device-not-registered`, the rest of the batch processed. Changed: `error`, `device-proof-invalid`. Under "Check and review" all recorded |
| PHN-019 | P2 | W | Automatic check-in | MOB-010 for EMP (registered): enforce, then "Check and review" | Enforce: no automatic check-in (refused silently); tapping Check In works. Check and review: recorded and scored `device_unverified` |
| PHN-020 | P2 | R | Wrong address | Only if a QA organisation uses its own domain (otherwise Blocked): EMP opens `/attendance/mobile` there and checks in | "Open your organisation address": "Your registered phone works on your organisation's own ETHR address.", "Open". Punch: 422 `device-wrong-host`, "Open the ETHR address of your own organisation to punch with your registered phone.", with `canonical_url`. Check where "Open" leads; suspected the same custom domain (K-32) |
| PHN-021 | P3 | W | Challenge single use and expiry | Laptop with Windows Hello or Touch ID registered as EMP's phone. DevTools: copy a successful `/attendance/check-in` carrying `device_proof`; resend unchanged; again with a new `idempotency_key`. `POST /attendance/device/challenge`, wait 4 minutes, sign with `navigator.credentials.get`, send | Unchanged: 200 replay (`was_duplicate: true`). New key: 422 `device-proof-invalid`, "We could not confirm this is your registered phone. Try again, and unlock the phone when it asks." (spent once). After 4 minutes: the same 422 (a challenge lives 180 s). Spent and expired challenges deleted daily |
