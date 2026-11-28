# Attendance rules page: review and upgrade plan

Status: review and plan, 2026-11-28. **Not built.** Written on Fable 5.2 as the planning step of the owner's model rule ([`../MODEL-ROUTING.md`](../MODEL-ROUTING.md)); implementation belongs to Sonnet sessions, PR by PR, in the order below.

Page: `/settings/attendance` (Settings, then Attendance rules). Code read at `origin/main` `2e001351bc`.

## How this was checked

- **The live page**, on QA Audit (`qa-audit.ethr.et`), signed in as an organisation admin, in Chrome with no write guard. Nothing was clicked that saves. One `GET /api/v1/attendance/settings` was sent from the page to read the stored values.
- **The code**: the page (`src/src/app/(dashboard)/settings/attendance/page.tsx`) and its four cards, the controller, the request, the model, `AttendancePolicy`, `AttendanceHealth`, the engine's method and geofence checks, and each consumer of each setting.
- **Not checked**: saving, the phone layout, the Amharic and Afaan Oromoo text, and the audit log screen. No test was run. Where a finding says "read, not run", it comes from the code alone.

## What the page does well

- Every anti-cheating control says what it does and that a score sends a punch to review and never refuses it. Only the registered phone rule can refuse, and the page says so.
- Lateness deductions are off by default, need a written basis (Proclamation 1156/2019 Art. 59) and belong to whoever holds `payroll.manageConfig`, not to HR.
- The setup panel at the top lists what stops attendance working, most serious first, each with a link to where it is fixed.
- Every setting on the page has a reader in the backend. None is stored and ignored. (The 2026-10-12 QA found several that were; that is closed.)
- Number fields carry the server's limits, an empty field blocks the save with a message, and leaving with unsaved changes is warned (#865).

## Findings

Severity is the house scale (S1 stops work or leaks data, S4 is cosmetic).

| # | Sev | Finding | Evidence |
|---|---|---|---|
| F1 | S2 | **The audit entry cannot say what changed.** The page sends all 30 fields on every save, and the entry stores the request as sent, with no earlier values. "Who switched off the geofence, and when" needs two entries compared by hand. Rules by branch has the same gap. | `AttendanceSettingController.php:61` records `$request->validated()`; `BranchAttendanceController.php:68` records the new overrides only. Other settings already store both sides: `SettingsController.php:226` |
| F2 | S2 | **Two admins overwrite each other without notice.** The page holds the settings it loaded and sends every field back. A tab opened in the morning reverts a colleague's later change. When the stale tab belongs to HR and the field a colleague changed is a payroll one, the whole save answers 403, because the server sees HR changing a payroll field. | `page.tsx:209` sends the whole form; `page.tsx:151` seeds it once and never again; `AttendanceSettingController.php:45-51` |
| F3 | S3 | **The written basis can be cleared while deductions stay on.** The check runs only when `lateness_deduction_enabled` is in the request. A request carrying only an empty basis passes. The page sends both fields today, so this is reachable through the API only. It becomes reachable from the page the moment the page sends only what changed (D1), so it is fixed first. | `UpdateAttendanceSettingRequest.php:66` returns early when the switch is not sent |
| F4 | S2 | **With Manual entry off, a missed-punch claim cannot be approved.** Approving a claim enters the record through the engine as a `manual` punch, and the engine refuses a method that is off. Employees can still file such claims. Read, not run: the first step of PR 3 is a test that shows it. | `CorrectionDecisionService.php:214` calls `EnteredAttendance::enter()`, whose source defaults to `manual` (`EnteredAttendance.php:38`); `AttendanceEngine.php:199` |
| F5 | S3 | **Method switches act at once and say nothing.** Turning Biometric off holds every terminal punch as refused. They can be replayed from Devices, but the page neither asks nor shows that two terminals are registered. Turning Phone, Computer, QR or Kiosk off refuses those punches from the next request. | `ProcessDeviceInboundPunchesJob.php:213-215`; replay exists at `POST /devices/quarantine/replay` |
| F6 | S3 | **Settings that cancel each other are accepted without a note.** QA Audit has selfies required and automatic check-in both on. Automatic check-in then never fires, and employees are still offered the opt-in. The same holds for the live face check when no selfie is ever asked, and for adaptive selfies when a selfie is required of everyone. | `punch-assistant.ts:201` (`photo_required` stops it); `MyShiftService.php:149` still answers `auto_check_in_allowed: true`; `AttendancePolicy.php:127` |
| F7 | S3 | **One Save button, at the top of a very long page.** It scrolls away. Silent terminals has its own Save, Rules by branch saves in its dialog, and Work sites saves per site. A person who edits a rule halfway down has to know to scroll back up. | `page.tsx:303-317` |
| F8 | S3 | **Rules by branch offers less than the server accepts.** The API takes a branch's own methods, its automatic check-out wait and its office networks. The dialog offers none of the three, and the admin guide said it did (corrected with this document). | `UpdateBranchAttendanceRequest.php:26-39` against `branch-rules-card.tsx:45-51` |
| F9 | S4 | **The page's description promises grace periods.** Grace is set on each shift. | `route-meta.ts:316`, key `route.settings.attendance.desc` in `en`, `am`, `om` |
| F10 | S4 | **"Kiosk check-in is on with no kiosk" shows only when a PIN is required.** The condition reads as a leftover and no test pins it. | `AttendanceHealth.php:175` |
| F11 | S4 | **Wording.** The English text of Silent terminals says "the tenant and HR admins" (the Amharic says organisation admins), and its switch says "Tell me" although every admin is told. | `device-silence-setting.tsx:128`, `:164` |
| F12 | S4 | **Layout at 1280 px.** The method cards wrap to two or three words a line, and the "Reconcile attendance before payroll" row squeezes its description into a column three words wide. The cards of a method that is off look the same as the rest. | `page.tsx:343` (`xl:grid-cols-4` inside the settings column); `page.tsx:534` |

### Corrections to the first report of this review

Three things said in chat on 2026-11-28 were wrong or incomplete, and are not in the table above:

- **`attendance_settings.grace_period_minutes` is not an oversight.** [`../DATABASE.md`](../DATABASE.md) already records that the column is kept and no longer rates a punch. Only the page description (F9) is wrong.
- **Early-leave tolerance exists.** It is `shifts.early_departure_minutes`, set per shift.
- **Manager alerts exist** as alert thresholds and dashboard digests (`AlertEvaluator::METRICS`, `RunDashboardDigestsJob`). They were not examined here.

## Design decisions

**D1. The page sends only what changed.** Every key in `UpdateAttendanceSettingRequest::rules()` is already `sometimes`, so the server needs no new contract. The page compares its form with what it loaded and sends the difference. Three things follow:

- two people changing different rules no longer undo each other;
- HR's save no longer carries stale payroll fields, so the accidental 403 goes;
- the request is the change, which is what the audit entry should hold.

Two people changing the *same* rule still end with the later value. That is accepted. A version check that refuses the second save was considered and left out: no settings screen in the product has one, and D2 and D9 make the earlier value and its author visible. The page also reloads the stored settings when it regains focus with no unsaved edit, so a tab left open all day shows the present values.

**D2. The audit entry holds both sides of each changed rule.** `before` and `after`, changed keys only, the shape `settings.sign_in_rules_updated` already uses. A save that changes nothing writes nothing and records nothing. Rules by branch gets the same treatment for `attendance.branch_rules_updated`.

**D3. The basis is checked against what will be stored.** The switch and the basis are each taken from the request when sent and from the stored row otherwise. Deductions on with a blank basis is refused whichever of the two the request carried. A basis of spaces counts as blank.

**D4. Settings that cancel each other get a notice, not a refusal.** A branch may set its own selfie rule, so "selfie required and automatic check-in on" can be right for the organisation and wrong only where the branch follows it. The page shows a short note under the switch that will not act, naming the rule that stops it. Nothing is refused on save.

**D5. Turning off a method that is in use asks first, at Save.** The dialog says how many punches came by that method in the last 30 days and, for Biometric and Kiosk, how many terminals or kiosks are registered, then what will happen: phone, computer, QR and kiosk punches are refused; terminal punches are held and can be replayed from Devices. The counts come from one new read, `GET /attendance/settings/method-usage`. Turning a method on never asks.

**Manual entry is decided with the F4 test.** If the test confirms F4, approving a claim must not depend on the Manual entry switch: the switch governs who may type a punch, and an approved claim is a decision already made by someone allowed to make it. The fix is in the engine's caller (the claim enters with a source the switch does not govern, or the check is skipped for an approved claim), which is attendance engine design and gets an Opus plan of its own before the code.

**D6. A save bar stays in view while there are unsaved changes.** It shows the number of changed rules, Discard and Save. The header button stays. The nine cards remain one form with one Save, because they are one record, one audit entry per save, and several rules depend on each other across cards (D4). The cards that are separate records keep their own Save and are not folded in: Silent terminals needs `settings.manage`, not `attendance.manage`, and Work sites and Rules by branch are per site and per branch.

**D7. Rules by branch offers everything the server accepts.** Methods (each as on, off or same as the organisation), the automatic check-out wait and the office networks join the dialog. Until that ships, the admin guide says what the dialog offers today.

**D8. Copy and layout.** The page description drops "grace periods" and the page links to Shifts for lateness grace. "Daily overtime limit" becomes "Your own daily overtime limit", and its hint says first that the legal limits (4 hours a day, 12 a week) are checked whatever is set here: the default of 2 hours has been read as a wrong legal value, and it is the organisation's own stricter limit. Silent terminals says "the organisation's admins and HR admins" and "Tell the admins". The method grid takes fewer columns inside the settings layout, the reconciliation row is stacked like the kiosk sound row, and the card of a method that is off says so. Amharic and Afaan Oromoo text is written by the implementer and read by a native reader before release, as #857 was.

**D9. The page says who last changed the rules.** One line under the title, "Last changed by <name> on <date>", linking to the audit log filtered to this page's entries. The settings resource gains `last_changed_at` and `last_changed_by` from the newest `attendance.settings_updated` entry. This is a contract change, so the `@response` annotation moves with it.

## Features asked about, and what this plan does with each

| | Feature | Decision |
|---|---|---|
| G1 | How far back an employee may claim a missed punch or a correction | **Build (PR 6), once the owner agrees.** A new `correction_window_days`, empty by default, which is today's behaviour. It limits claims people file for themselves. HR filing for someone is not limited, and a paid day stays closed by `PayrollPeriodLock` as now |
| G2 | A notice to staff before presence checks are switched on | **Owner decision, then a small PR.** The page says "tell your staff in writing" and the product neither sends nor records that. Proposed: a button that opens an announcement draft with standard wording. It waits for the announcement audience work (communication upgrade, PR 3) and for the wording to be agreed, since it touches the DPIA |
| G3 | Exempt from attendance (directors, some drivers) | **Owner decision, not in this plan.** A fourth attendance mode would change absences, the timesheet, payroll inputs and reminders. Attendance and payroll design: an Opus plan of its own |
| G4 | Rounding punch times | **Not recommended now.** Rounding moves paid minutes. Any rule would need the accountant and a reading of the wage provisions, and nobody has asked for it |
| G5 | A weekly overtime limit below the legal 12 hours, and time off in lieu | **Wait for the accountant**, with item 5 of the 2026-11-20 subsystem review |
| G6 | Rules that start on a future date | **Not recommended.** D2 and D9 give the history, which is the need behind the request |
| G7 | Rules by department or grade | **Not recommended.** `AttendancePolicy` resolves organisation, branch, then the person's mode, three levels on purpose, so "which rule applies here" has an answer a person can predict |
| G8 | Office networks for phone punches | **Not recommended.** A phone on mobile data has no fixed address. The geofence and the registered phone do this job |
| G9 | Copy one branch's rules to another, reset to defaults | **Later, after PR 4.** Discard in the save bar (D6) covers the common case |
| G10 | Overtime: limits seen before payroll, a request that respects the week, rules for workers under 18, a fixed list of reasons, a floor for worked overtime | **A plan of its own:** [`OVERTIME-WORKING-TIME-PLAN-2026-11-28.md`](OVERTIME-WORKING-TIME-PLAN-2026-11-28.md). Raised by the owner the same day. Only the wording of the daily limit (D8) is done here |
| G11 | Overtime approval on by default for a new organisation | **Owner decision**, W4 of that plan. If yes, it is one line in `AttendanceSetting::defaults()` or the provisioner, new organisations only, and it travels with PR 5 here |

## The pull requests, in order

Each PR carries its own tests, its own cases in [`../production testing/05-E2E-ATTENDANCE-SHIFTS-DEVICES.md`](<../production testing/05-E2E-ATTENDANCE-SHIFTS-DEVICES.md>) (the pack's rule: cases are written for what is on `main`, by whoever built it), and its lines in [`../admin-guide.md`](../admin-guide.md). Sonnet implements. "Opus review" means step 3 of the model rule, with the diff, before merge.

| PR | What | Findings | Touches | Migration | Model rule |
|---|---|---|---|---|---|
| 1 | Server: the basis checked against what will be stored (D3); the audit entry with before and after, and nothing written when nothing changed (D2), for the settings and for Rules by branch | F1, F3 | `UpdateAttendanceSettingRequest`, `AttendanceSettingController`, `BranchAttendanceController`, `AttendanceSettingTest`, `AttendancePolicyTest` | No | Opus review: a pay-deduction guard and an audit record |
| 2 | Page: send only what changed, reload when clean and focused (D1); the save bar with Discard (D6) | F2, F7 | `settings/attendance/page.tsx`, `features/attendance/api.ts`, a Vitest file for the page | No | Sonnet, then a review of the diff |
| 3 | Honest switches: the F4 test first, then its fix as designed; notices for rules that cancel each other (D4); the method-usage read and the confirmation at Save (D5); the kiosk notice condition (F10) | F4, F5, F6, F10 | `AttendanceEngine` or its caller, `CorrectionDecisionService`, a new controller action and request, `AttendanceHealth`, the page, `generated.ts` | No | **Opus plan first** for the F4 fix (engine design), Opus review after |
| 4 | Rules by branch: methods, check-out wait, office networks (D7) | F8 | `branch-rules-card.tsx`, its Vitest | No | Sonnet, then a review of the diff |
| 5 | Copy, layout and "last changed by" (D8, D9) | F9, F11, F12 | `route-meta.ts`, `en`/`am`/`om`, the page, `AttendanceSettingResource` and its `@response`, `generated.ts` | No | Sonnet. The copy part alone would need no cycle; the resource change makes it a normal PR |
| 6 | The claim window (G1), only after the owner says yes | G1 | a migration, the model, request and resource, `StoreCorrectionRequest`, the page, `generated.ts` | **Yes** | Opus review: it decides which claims are refused |

**Order matters between PR 1 and PR 2.** PR 2 makes the page send partial requests, and F3 is the hole a partial request opens. PR 1 merges first. They may ship in one release, since a release carries server and page together.

**PR 3 does not wait for PR 2**, but both edit `page.tsx`. Land them one at a time and update the second branch before merging it.

### Rules each PR must keep

- `rules()` in the two requests stays free of comments above keys, and cross-field checks stay in `withValidator()`, so the generated contract does not move by accident.
- A new action reads through the tenant scope. No `withoutGlobalScope` is needed by anything here; if one appears, the inventory test will say so and the plan was wrong.
- A changed resource means `@response` and `src/src/api/generated.ts` change in the same PR.
- New text ships in `en`, `am` and `om`, with no dash, emoji or exclamation mark in the interface text.
- PR 6's migration is run on a scratch MariaDB before it is pushed, and its deploy is Deploy plus `migrate` as one step.

## Checks before a release that carries these

- `./scripts/gates.sh` for every PR, with the collection count compared against `find`.
- `./scripts/gates.sh mysql` in CI for PR 1, PR 3 and PR 6.
- `./scripts/gates.sh e2e-attendance` against a running stack before the release that carries PR 3, because it changes what the engine accepts. It needs `queue:work` on the same database.
- The settings checks in `src/e2e/ux-audit.spec.ts` (two entries for this page) and `src/e2e/amharic-layout.spec.ts`. They were not run for the 2026-11-22 settings plan, because no seeded stack was up. If that is still so, say it in the PR.
- After the deploy: `src/e2e-production/signed-in.spec.ts` (read-only, includes this page) on QA Audit with the owner's saved session, then one save on QA Audit by hand, and its entry read in the audit log.

## Owner decisions this plan waits on

1. **G1**: may an organisation limit how far back its people file claims, and is "empty means no limit" the right default?
2. **G2**: the wording of the staff notice, and whether sending it is offered or required before the switch.
3. **G3**: whether an exempt mode is wanted at all.
4. **G11**: whether overtime approval is on for a new organisation.

PRs 1 to 5 need no decision and can start.

## Documents corrected with this review

- [`../admin-guide.md`](../admin-guide.md): Branch rules no longer claims a per-branch choice of methods on the screen and lists the registered phone and presence checks, which the dialog does offer; the setup status table gains the three checks it lacked; the methods table gains Spreadsheet import and says what happens to terminal punches when Biometric is off.
- [`../DATABASE.md`](../DATABASE.md): `shifts` carries `grace_minutes` and `early_departure_minutes` (it listed `grace_period_minutes`); the status and source lists of `attendance_records` match `AttendanceStatus` and `AttendanceSource`.
- [`../production testing/05-E2E-ATTENDANCE-SHIFTS-DEVICES.md`](<../production testing/05-E2E-ATTENDANCE-SHIFTS-DEVICES.md>): section 1a says which branch overrides the dialog offers and which are API only.
- [`SETTINGS-REVIEW-2026-11-22.md`](SETTINGS-REVIEW-2026-11-22.md): a pointer from the Attendance rules row to this document. Its 8.5 was given on a pass that tested no save and did not read the audit entry.
