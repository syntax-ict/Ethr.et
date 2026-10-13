# QA — attendance engine and devices on production, 2026-10-12

A live pass over the attendance engine and the Devices page on production
(`v2026.10.12.6`), driven from the app's isolated browser pane, signed in by the owner as
the tenant admin of the throwaway organisation **QA Audit** (`qa-audit.ethr.et`). Every
write went to QA Audit only, behind a guard that refused to send anything unless the
signed-in tenant was `qa-audit`. No real employee, tenant or payroll was touched.

Method: UI for the flows a person uses (conflicts, devices, kiosks), and same-origin
requests from the signed-in page for the matrix cases, each read back from the API. The
plan, suspicions and safety rules are in the session plan; the suspicions came from two
read-only passes over `api/app/Services/Attendance/*`, the attendance and device
controllers, `routes/console.php` and `src/src/app/(dashboard)/attendance/*`.

What this report does not contain: emails, IP addresses, tokens, PINs.

Severity: **S1** wrong results for every tenant / data integrity · **S2** a broken feature
or a control that is missing · **S3** wrong behaviour with a workaround · **S4** polish.

## Summary

| # | Sev | Finding | Status |
|---|---|---|---|
| A1 | S1 | **Late / early-leave / overtime are classified on the UTC clock**, not Addis Ababa time — every punch's status is wrong by 3 hours | Confirmed live |
| A2 | S2 | **Offline sync rejects every record** once an organisation has attendance settings | Confirmed live |
| A3 | S2 | **A mock "simulator" device is offered — as the default — in production**; its pull fabricates punches for real employees, including check-outs in the future | Confirmed live |
| A4 | S2 | **An admin can approve the correction they filed** — no second person on a change that feeds payroll | Confirmed live |
| A5 | S3 | Approving a correction changes the times but **not the status** | Confirmed live |
| A6 | S3 | A **replayed check-out answers 422**, not "duplicate" | Confirmed live |
| A7 | S3 | An organisation's **first admin has no employee record and cannot be linked to one** — the owner cannot use self-service attendance | Confirmed live |
| A8 | S3 | **Detail URLs ending in `/` return 404** (devices, employees) | Confirmed live |
| A9 | S4 | Manual entry writes **two** `attendance.manual_entry` audit rows | Confirmed live |
| A10 | S4 | The page **wrote to the OS clipboard** on two ordinary clicks (a dropdown, a Cancel) | Observed by the browser, not yet traced |
| A11 | S4 | Device registration: the mock adapter rejects an empty `connection_config`; the form has **no auto-sync switch** | Confirmed live |
| H2, H4, H7, H8, H10 | — | Suspected from code, **not verified live** — see *Not covered* | Open |

### Where each finding went

| # | Resolution |
|---|---|
| A1–A4 | Fixed in #214 (merged 2026-10-12, `f87f080c`). A1 also closes **H2**: a punch is dated by the tenant's day |
| A5, A6, A7, A8, A9, A11 | Fixed in #215 |
| A10 | **Not a product defect.** Nothing in the app writes the clipboard outside its explicit Copy buttons (kiosk token, webhook URL, API key, SCIM, webhook secret, settings). The writes the browser reported came from the automated test browser |
| A11, first half | **No change.** The Register form sends a marker config for the simulator, and since #214 the simulator exists only in development |
| Past records | **Not recalculated.** Statuses already stored were rated on the UTC clock. Production holds trial and test organisations only, so no migration job was written; if a customer's history ever needs it, it is a separate, audited job |

## Findings

### A1 · S1 · Status is computed on the UTC clock

**Setup.** Shift **QA-EAT 17:10–23:30**, grace 10 minutes, assigned to QA-0001 and
QA-0002. Tenant timezone `Africa/Addis_Ababa`.

**Steps.** At **18:09–18:10 Addis time** (15:09–15:10 UTC): a live punch for QA-0001
through the engine (`POST /attendance/kiosk`, admin on behalf), and a manual entry for
QA-0002 at `18:09` Addis wall time (`POST /attendance/manual`).

**Expected.** Both **late** — 59 minutes after the start, 49 after the grace.

**Actual.** Both stored `status: present`. The **instants are right** (`check_in`
15:10:51Z and 15:09:00Z = 18:10 and 18:09 Addis); the **classification is wrong**: the
engine compares the UTC clock time (15:10) with the shift's wall-clock start (17:10) and
reads the punch as two hours early. Manual entry converts the time correctly for storage
(`Support/TenantTime`) and is still classified the same way.

**Downstream.** `GET /attendance/intelligence?date=2026-10-12` → **`late_arrivals.count: 0`**.
Every report, dashboard, payroll deduction or discipline rule that reads `status` is
3 hours off in Ethiopia: lateness appears only after ~3 h, normal check-outs read as early
leave, overtime almost never. Code: `AttendanceEngine` uses `Carbon::now()`;
`ShiftMatcher.php:175-186`; `tests/Unit/EthiopianTimezoneTest.php:38-61` already pins part
of this as "likely-unintended".

**Fix.** Classify in the tenant's zone: convert the punch instant to tenant wall time
(`Support/TenantTime`) before comparing with `start_time`/`end_time`, and for early
departure and overtime the same; the record's `date` from the tenant's day (H2). A test
that fails at 18:10 Addis today.

### A2 · S2 · Offline sync rejects every record

**Steps.** `POST /attendance/sync` with one `check_out` for QA-0002 (admin may sync for
others).

**Actual.** 200 with `status: error`, *"This attendance method is not enabled for your
organization."* QA Audit's settings at the time: `offline_sync_enabled: true`,
`enabled_methods` without `offline_mobile`. The settings row exists from the first visit
to the settings page, and `UpdateAttendanceSettingRequest` does not accept
`offline_mobile`, so no organisation can turn it on. Every punch a phone stored offline is
discarded at sync — silently to the user unless they read the per-record result.

**Fix.** Treat `offline_sync_enabled` as the switch for the `offline_mobile` source (or
add `offline_mobile` to the defaults and to the request's allowed list), and backfill
existing rows.

### A3 · S2 · The mock simulator is a production feature

**Steps.** `/devices` → **Add Device**.

**Actual.**
- The empty state invites *"Add a biometric device or a mock simulator"*; the Register form's **Adapter Type defaults to "Mock (Simulator)"**, with the hint *"it will generate test attendance events from your employees when you pull records."*
- Registered "QA Mock" (auto-sync off), pressed **Pull** → the queue ran it at the next 5-minute tick (sync log `success`, found 3, processed 3).
- It **fabricated `biometric` punches** for both QA employees: QA-0001 **08:00 → 16:15 UTC**, written at 15:15 UTC — **a check-out 1 hour in the future** — and QA-0002 08:09 UTC.
- The fabricated punches then **raised two pending conflicts** against the real ones.

A tenant admin can therefore create attendance for every employee from nothing, with a
`biometric` source and confidence; it flows into reports and payroll like any other punch.

**Fix.** Refuse `adapter_type: mock` outside `local`/`testing` (request rule + hide in the
form); remove the empty-state copy; consider voiding existing mock-sourced records.

### A4 · S2 · A correction can be approved by the person who filed it

**Steps.** As the admin, file a correction on QA-0001's record (`POST /attendance/corrections`),
then `PUT /attendance/corrections/{id}/approve` with the same account.

**Actual.** **200**, `status: approved`, and the record's times changed. The existing guard
(`CorrectionDecisionService:39`) only stops approving a correction to *your own
attendance*; nothing requires the approver to differ from the requester.

**Fix.** Refuse approval when `approver_id === requested_by` (403 with a clear detail),
and pin it in a test.

### A5 · S3 · Approving a correction does not recompute status

The approved correction moved the record to 17:12–23:30 (UTC as sent — see *Notes*),
which against the shift is on time and to the end; `status` stayed **`early_leave`**.
**Fix:** re-run the status classification when a correction is applied (the same function
the engine uses, after A1's fix).

### A6 · S3 · A replayed check-out is an error

Same `Idempotency-Key` twice: check-in replay → **200 `was_duplicate: true`** (correct);
check-out replay → **422 "There is no open check-in to close."** The check-out's key is
not stored, so a phone retrying after a dropped response shows the person an error for a
punch that succeeded. **Fix:** store the check-out key and answer the replay with the
closed record and `was_duplicate: true`.

### A7 · S3 · The organisation's first admin cannot punch

`/auth/me` for QA Audit's admin: `employee_public_id: null`. Web, mobile, QR and offline
check-in all act on the signed-in user's employee and answer 404 `no_employee_linked`.
`PATCH /users/{id}` accepts no `employee_id`, and no screen links an existing user to an
employee. **Fix:** create the owner's employee record at registration, or allow linking a
user to an unlinked employee (tenant-scoped, audited).

### A8 · S3 · Detail URLs with a trailing slash 404

`curl`: `/devices/{id}` **200**, `/devices/{id}/` **404**; `/employees/{id}` 200,
`/employees/{id}/` 404. List pages work either way. A bookmark, a pasted link or a
refresh on a slash-terminated detail URL lands on "Page not found". **Fix:** let the
`.htaccess` entity-shell rule accept an optional trailing slash, pinned in
`SharedHostingHtaccessTest`.

### A9 · S4 · Manual entry is audited twice

Five manual entries → **10** `attendance.manual_entry` rows (`AttendanceEngine:135` and
`ManualAttendanceController:91`). **Fix:** record it once.

### A10 · S4 · Clipboard writes on ordinary clicks

The browser reported that the page wrote to the OS clipboard when a **Select dropdown**
was opened (Conflicts → Review → Decision) and on a **Cancel** click near the device
form. Nothing in those controls should copy. Not traced yet; worth finding the global
handler before a user pastes something unexpected.

### A11 · S4 · Device registration details

`POST /devices` with `adapter_type: mock` and `connection_config: {}` → 422
"connection config field is required", though a mock needs none. The Register form has no
auto-sync switch, so a device registered from the UI takes the server default.

## Working as designed

| Area | Result |
|---|---|
| Stored instants | Live and manual punches store the correct UTC instant for an Addis wall time |
| Duplicate handling | Same minute → `deduped`; 3 min → `merged`; 40 min → `flagged` + conflict; losers `voided` |
| Conflict review | `/attendance/conflicts` shows the pair in Addis time; *Keep record A (void B)* → PUT 200, "Conflict resolved", audited |
| Check-in replay | 200, `was_duplicate: true` |
| Check-out | Stored; `early_leave` as expected for that time |
| Correction filing & payroll impact | 201 pending; impact 0 → 6.3 h, `in_open_payroll_period: true` |
| Device: test connection (unreachable host) | 200 `offline`, 2 s timeout, no 500 |
| Device: pull (unreachable) | Sync log `offline`, "Device is not reachable" |
| Device: duplicate serial | 422 "already registered for this device type" |
| Scheduler | Both device pulls ran at the next cron-job.org tick (≤5 min) |
| Audit | Branch, employee, kiosk PIN, shift, assignment, devices, conflicts, corrections all recorded; punches correctly not audited |
| Settings | Untouched by the run (`updated_at` unchanged) |

## Not covered, and why

- **Web, mobile and QR self check-in; geofence.** Need a signed-in user linked to an employee (A7). The geofence is enforced only on the mobile path.
- **Kiosk terminal (`/kiosk`) and its PIN screens.** Activating it needs the kiosk token, and exiting needs the admin PIN — secrets the owner did not enter this run. The engine's kiosk path was exercised through the admin kiosk endpoint.
- **Device webhook (H4 — tenant resolution on the shared host).** The call carries the device token. A prepared one-line command exists; unverified.
- **H2** (punches 00:00–03:00 Addis dated the previous day), **H7** (settings stored but never read — `grace_period_minutes`, `mobile_photo_required`, `qr_single_use_limit`, …; A1 masks any live effect), **H8** (QR token reusable, not branch-bound), **H10** (`webhook_ip_allowlist` enforced but unsettable) — code findings, not driven live.
- **Missing-punch and anomaly scans** — run daily; not waited for.

## Notes

- A correction sent through the API without a timezone (`2026-10-12 17:12`) is read as UTC. The Corrections screen converts Addis time to UTC itself (`zonedWallTimeToUtcIso`), so users are unaffected; API clients should send an offset.
- A `deduped` punch answers 201, not 200 with `was_duplicate`.

## Test data left in QA Audit

| Object | State |
|---|---|
| QA Branch, shift QA-EAT and its two assignments | Kept (QA org) |
| QA Employee A (`QA-0001`, kiosk PIN set), QA Employee B (`QA-0002`) | Kept; no email address |
| Attendance records (live, manual, mock-fabricated) and one approved correction | Kept — never-delete policy; QA Audit only |
| Two conflicts from the mock punches | Pending |
| Devices "QA Mock", "QA ZK" | **Deleted** |
| Kiosk | None registered |
| Attendance settings | Unchanged |
