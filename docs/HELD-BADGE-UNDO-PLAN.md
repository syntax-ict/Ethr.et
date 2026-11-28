# Undo a wrong held-badge assignment: plan

Status: plan, 2026-11-27, written on Opus (owner's model rule: Opus plans attendance and payroll design, Sonnet implements). Not built.

## The problem

A held badge is a terminal user number that matched no employee. Its punches wait in `unmatched_device_punches` until someone assigns the badge on the Unrecognised badges card (`/devices`). `UnmatchedPunches::assign()` then does two things:

1. **It links the number to the employee** through `IdentityResolver::link(..., verified: true, reassign: true)`. That moves the number even from someone it was confirmed for. The previous holder survives only in an `identity.reassigned` audit entry; the mapping row itself is overwritten.
2. **It replays every held punch** through `DevicePunchRecorder` into the attendance engine. Each punch becomes a check-in (`attendance_records.idempotency_key`), a check-out (`metadata.checkout_idempotency_key`) or a break (`metadata.breaks[].key`). The held row is marked `replayed` or `refused`, with `employee_id`, `resolved_by` and `resolved_at`.

The automatic recording (`recordMatched()`, number plus a strictly matching terminal name) does the same with no person involved.

Nothing reverses this. If the wrong person was chosen, hundreds of punches stay in their attendance and flow into payroll. The badge's real owner keeps punching into the wrong person, because the number is now linked to them. Mature HR time-clock products let an administrator reverse an assignment, with a stated effect and an audit entry. ETHR should too.

The confirmation dialog (PR #932: server preview plus acknowledged warnings before Assign) makes wrong assignments rarer. It does not make them impossible.

## What already exists that the design reuses

- **`AttendanceStatus::VOIDED`.** Every reader already skips it: timesheet, payroll inputs, dashboards, the engine's own lookups. `AttendanceIntegrityController` and `AttendanceConflictController` void with `metadata.voided_reason`.
- **`PayrollPeriodLock`.** A day the employee was paid for in an approved or released run (`PayrollRun::RELEASED_STATUSES`) is closed to human edits; voiding the run reopens it. `assertOpen()` and `closedDays()` already exist. An undo is a human edit, so it obeys this lock exactly as corrections, manual entries, conflict resolution and integrity rejection do.
- **The self rule.** A person may not decide their own hours (integrity review, corrections). The same applies to undoing an assignment to one's own employee record.
- **`AuditLog::record`** for every state change.
- **`UnmatchedPunches::lockKey()`**, the per badge lock that keeps two requests off the same badge.

## Decisions

1. **An assignment becomes a record of its own.** Add a `badge_assignments` table:
   - `tenant_id`, `public_id`, `device_id`, `badge`, `employee_id`;
   - `previous_employee_id` (nullable) and `previous_verified_at`, the mapping as it stood before the link;
   - `kind`: `manual` or `automatic`;
   - `assigned_by` (nullable for automatic) and `assigned_at`;
   - `undone_by`, `undone_at`, `undo_note`.

   Add a nullable `badge_assignment_id` on `unmatched_device_punches`, set by replay.

   Why a table rather than deriving the unit from existing rows: the previous holder lives only in an audit entry today, and an audit log is a record of what happened, not state to restore from. The replayed set also has to be exact, not "every replayed row for this badge and employee", because a badge can be assigned, undone and assigned again.
2. **The unit of undo is one assignment, and only the latest live one for a device and badge.** An earlier one cannot be undone while a later one stands: the mapping to restore would be wrong. Assignments made before this ships have no row and show no Undo. There is no backfill, because the previous holder cannot be recovered reliably.
3. **What an undo does, in one transaction under the badge lock:**
   - **Pure records are voided.** These are records whose every punch key (check-in key, check-out key, break keys) belongs to this assignment's replayed punches. They go to `VOIDED` with `voided_reason = badge_assignment_undone` and the assignment's `public_id`.
   - **Mixed records are not touched.** These hold a punch of the person's own as well as replayed ones. They are listed, and the undo goes ahead only when the administrator ticks "leave these days for a hand correction". They are named in the audit entry and in the answer, and each gets an open `AttendanceIntegrityFlag` so they show up in the existing integrity review instead of being forgotten.

     Blocking the whole undo on one mixed day would leave every wrong day in place. Voiding a mixed record would delete a real punch. The `own_attendance` warning, now acknowledged at assign time, makes this case rare and deliberate.
   - **Held punches go back to `pending`.** That is both the replayed and the refused ones of this assignment, with `employee_id`, `resolved_by`, `resolved_at`, `refusal` and `badge_assignment_id` cleared. They are back on the card to be assigned correctly.
   - **The mapping is restored.** If there was a previous holder, it is set back to them with `previous_verified_at`. Otherwise the mapping row is deleted. Leaving it in place would make the resolver match the badge to the wrong person again.
   - **No automatic re-recording to the same person.** The automatic recording (`recordsItself()` / `recordMatched()` and `sweepCandidates()`) never records a device and badge to an employee whose assignment of that badge was undone. Without this, an undo of a badge whose number equals the wrong person's `badge_number` would be redone within minutes by the sweep.
   - **One audit entry, `device.badge_assignment_undone`.** It carries: badge, device, employee, the previous holder restored or not, records voided, mixed days left, and punches returned.
4. **Refusals, each a 422 with a plain reason, changing nothing:**
   - **A closed payroll day.** Any record to void falls on a closed day (`PayrollPeriodLock::closedDays`). The answer names the run, and the way forward is to void that run first, exactly as for a correction. No pay difference is computed, because nothing in a released run moves.
   - **Already undone**, or **not the latest assignment** of that badge.
   - **The self rule:** the assignment is to the acting user's own employee record (403).
   - **Mixed days present** and not acknowledged.
5. **Permissions.** `attendance.manage`, and the employee must pass `canAccessEmployee()`. That is the same gate as Assign.

   **A support session (an operator signed in as an organisation) may undo. Owner decision 2026-11-27.** This matches the house rule for attendance corrections, which are not in `BlockImpersonatedActions::BLOCKED_ACTIONS` either. The payroll lock still applies to operators, so a paid day stays closed. The audit entry records that the undo came through a support session (the impersonation context the audit log already carries), so the organisation can see an operator did it.
6. **No time limit.** The payroll lock is the limit that matters, and it is already the house rule.
7. **The employee is told. Owner decision 2026-11-27.** When an undo voids days, the person they were removed from gets one notification through the existing notification channels: "N days of attendance from <day> to <day> were removed. They came from a terminal badge that was not yours." It is sent only when they have a user account, best-effort after the undo is saved (a mail outage must not turn a saved undo into an error), as the integrity rejection notice does. No SMS. The reason is that people see their own attendance, and days that vanish with no word start a dispute with HR.
8. **The UI lives on the same card.** A collapsed "Assigned badges" list under the held badges shows every live assignment, newest first, 20 at a time with "Show more" (owner decision 2026-11-27: no date window, because undo has no time limit and a wrong assignment is often found at a month-end pay review, after 30 days). Each entry shows: badge, device, person, when, by whom or "automatically", and how many punches. Each has an Undo button. It opens a confirmation that loads a server preview stating the effect:
   - "N days removed from <name>'s attendance (from ... to ...)";
   - "the number goes back to <previous holder>" or "is unlinked";
   - "M held punches return to this card";
   - any mixed days, with the checkbox;
   - or the refusal reason, with Confirm disabled.

   No Radix `Select` in it (see the `/devices` freeze).

## PR breakdown

All four wait for the confirm-dialog PR (#932) to merge, because PR 1 changes the same `assign()`.

1. **Record assignments (backend, migration).** `badge_assignments` table and the `unmatched_device_punches.badge_assignment_id` column. `assign()` and `recordMatched()` write a row before the link, capturing the previous mapping. Replay stamps the id. The automatic recording skips undone pairs (no undo exists yet, so this is tested with a row marked undone by hand).
   - Every query states `tenant_id`.
   - Run the migration on a scratch MariaDB first (`ethr_contract` style), not the shared local one.
   - **Deploy is migrate plus code in one step.**
2. **Undo service and API (backend).**
   - `UnmatchedPunches::undo()` and `undoPreview()`.
   - `GET /api/v1/devices/unmatched-punches/assignments`: live ones, newest first, paginated 20 a page, scoped to what the viewer may open.
   - `GET .../assignments/{assignment}/undo-preview` and `POST .../assignments/{assignment}/undo` with `accept_mixed_days`.
   - The rules above, the integrity flags for mixed days, and the audit entry.
   - Regenerate the contract, with no comments above response keys.
   - Add the new route parameter to `CrossTenantRouteSweepTest`'s fixtures (`tests/Support/ParameterizedFixtures.php`).
   - Pest:
     - pure days voided and punches back to pending;
     - the previous holder restored, or the mapping deleted;
     - the auto-record sweep does not redo it;
     - mixed days left, flagged and listed, and refused without the flag;
     - a closed payroll day refused, with nothing changed;
     - not-latest and already-undone refused;
     - the self rule;
     - a support session may undo, and its audit entry shows the operator;
     - another organisation's assignment 404s;
     - re-assigning after an undo works and gets a new row;
     - the lock (409);
     - the voided days drop out of the timesheet ledger and the payroll inputs.
3. **Card UI (frontend).** The "Assigned badges" list with Show more, the Undo confirmation with the preview, the mixed-days checkbox, the refusal states, and en, am and om text (am and om need a native read). Vitest covers the list, the preview, the checkbox gating Confirm, and the refusal message.
4. **End to end.** Extend the `e2e-attendance` gate (`src/playwright.attendance.config.ts`) with one journey:
   - a device punch for an unknown badge is held;
   - Assign is clicked through the confirmation;
   - the timesheet shows the days;
   - Undo is clicked;
   - the API reads back the records as voided and the punches as pending again.

   This is the owner's step 5 before the deploy.

## Not in scope

- Undoing assignments made before PR 1 ships.
- Moving punches straight from one person to another in one step (undo, then assign).

## Owner decisions, 2026-11-27

- Support operators may undo (decision 5).
- The employee is told when days are removed (decision 7). PR 2 sends the notice and tests that it is sent once, only to a person with an account, and that a failed send leaves the undo saved.
- The list has no date window, paged 20 at a time (decision 8).
