# Approval engine: design for owner review

**Status:** proposal, 2026-10-20. Nothing here is built. It needs the owner
decisions in [§8](#8-decisions-needed-from-the-owner) before work starts.

**Why now:** the blueprint assessment (`audit/BLUEPRINT-ASSESSMENT-2026-10-20.md`,
§3 W1–W14) rated approval workflow the weakest area, with 1 of 14 items
Verified. `ENTERPRISE_ROADMAP.md` 10.1 records configurable chains as
"Missing — owner decision pending". `PRD.md` FR-06.4 lists them as P0, while
`PERMISSIONS.md` and `INDUSTRY_TEMPLATES.md` describe single-step approval as
the design. This document is the decision those disagree about.

## 1. What exists today

| Piece | Where | Behaviour |
|---|---|---|
| Who may decide | `Services/Approval/DecidableApprovals.php` | Holders of the request's permission (`leave.approve`, `correction.approve`, `employee.update`) whose org scope reaches the employee |
| The decision | `LeaveDecisionService`, `CorrectionDecisionService`, `ProfileUpdateRequestService`, `PayrollController::approve` | One step: the first permitted approver decides |
| Self-approval | Same services | Refused for leave, corrections and profile updates; payroll since #324 (sole approver allowed, audited) |
| Inbox | `GET /approvals/pending`, `POST /approvals/batch` | Leave, corrections and profile updates. Payroll runs are not in it |
| Reminders | `SendApprovalRemindersJob` | One digest per approver after 48 h; never escalates |
| Unused | `leave_requests.current_approver_id` | Column and relation exist, nothing writes it |

The org-scope rule ("reaches the employee") is good and stays: every step
below still requires reach.

## 2. Goals

1. **Configurable chains** per request type, for example: leave goes to the
   supervisor, then the department head, then HR.
2. **Routing** by department, branch, leave type and, later, by amount (loans,
   expenses).
3. **Delegation** while an approver is away, recorded as on someone's behalf.
4. **Escalation** of overdue steps to a named fallback, not just another
   reminder to the same person.
5. **Return for correction**: a request can be sent back to its author and
   resubmitted, not only approved or rejected.
6. **One inbox and one history**: every request type, with "step 2 of 3"
   shown and a timeline of who did what, when.

## 3. Non-goals for the first version

- Parallel branches ("HR **and** Finance in any order").
- Conditions on arbitrary custom fields.
- A drag-and-drop workflow designer. Steps are a short ordered list.

## 4. Model

```
approval_workflows      tenant_id, request_type, name, priority, is_active,
                        conditions json {department_ids, branch_ids,
                        leave_type_ids, min_amount_cents, max_amount_cents}
approval_workflow_steps workflow_id, position, approver_kind, approver_ref,
                        sla_hours, can_return
approval_instances      tenant_id, approvable (morph), workflow snapshot json,
                        current_position, status, submitted_by, submitted_at
approval_actions        instance_id, position, actor_user_id, on_behalf_of_user_id,
                        action (approve|reject|return|escalate|reassign),
                        comment, created_at          (append-only, like audit_log)
approval_delegations    tenant_id, delegator_user_id, delegate_user_id,
                        starts_on, ends_on, request_types json
departments.head_employee_id                        (roadmap 10.6)
```

**Approver kinds:** `supervisor` (the requester's), `department_head`,
`permission` (anyone holding it who reaches the employee, today's rule),
`role`, `user`.

**The workflow is snapshotted** onto the instance when the request is
submitted. Editing a workflow later never changes a request already in
flight, the same principle as the payroll `calculation_log`.

**Which workflow applies:** the active workflow for the request type with
the highest priority whose conditions all match. With none configured, a
built-in **default workflow** reproduces today's single step exactly
(`permission` kind, the request's current permission), so **nothing changes
for any organisation until it configures a chain**.

## 5. Rules the engine enforces

1. **Never self-approval.** A step whose only resolved approver is the
   requester moves to the step's fallback. It is never skipped silently, and
   never decided by the requester.
2. **Fallback when no approver resolves** (no supervisor, no department head):
   the organisation's HR approvers, i.e. the `permission` kind. The action
   history records that the fallback was used.
3. **Reach is always required**, at every step, as today.
4. **Maker-checker** for payroll becomes a two-step default (process, then
   approve), keeping #324's sole-approver exception.
5. **Every action is append-only** in `approval_actions` and mirrored to
   `audit_log`.
6. **Final approval and rejection call the module's existing service**
   (`LeaveDecisionService::approve` and the others). Balances, payroll locks
   and notifications stay where they are; the engine only decides *when*.

## 6. Delegation, escalation, return

- **Delegation:** while a delegation is active, the delegate is an eligible
  approver for the delegator's steps. Actions record
  `on_behalf_of_user_id`. A delegate can never approve their own request.
- **Escalation:** an hourly sweep finds steps past `sla_hours` (default 48)
  and notifies the step's escalation target: the approver's own supervisor,
  else HR. The first version does not auto-approve or auto-advance.
- **Return for correction:** the author edits and resubmits. The instance
  restarts at step 1 with a fresh snapshot, and earlier actions stay in the
  history.

## 7. Delivery in phases

| Phase | Scope | Changes behaviour? |
|---|---|---|
| A | Engine, tables, default workflow; leave and attendance corrections routed through it; inbox shows step and history | No: the default workflow reproduces today |
| B | Workflow settings screen (steps editor per request type); department head field; profile updates and payroll on the engine | Only for organisations that configure a chain |
| C | Delegation (profile setting), escalation sweep, return for correction | Opt-in per organisation |
| D | Amount routing for loans, and expenses once that module exists | Opt-in |

Each phase is its own PR with tests: a request is decided by exactly the
configured approvers, in order; the requester can never decide; a workflow
edited mid-flight changes nothing in flight; tenant isolation for every new
table (`TenantIsolationTest`).

## 8. Decisions needed from the owner

1. **Build it?** This replaces "single-step by design" in `PERMISSIONS.md` and
   `INDUSTRY_TEMPLATES.md`, and closes roadmap 10.1.
2. **Department head:** add `departments.head_employee_id` (roadmap 10.6)?
   Chains like supervisor, then department head, then HR need it.
3. **Fallback approver:** is "HR approvers of the organisation" the right
   universal fallback when a step resolves no one?
4. **Default chain for new organisations:** keep today's single step, or start
   leave as supervisor then HR?
5. **Escalation:** 48 hours by default, escalating to the approver's
   supervisor, else HR. Acceptable?
6. **Parallel approvals:** confirm they can wait for a later version.

With yes to 1 and 2, Phase A is about one week of work and changes nothing
users see until an organisation configures a chain.

### Decided 2026-10-21

The owner delegated these ("decide for me"). Each answer is the most
conservative one this document already recommended, and each is consistent
with the blueprint decision that single-step approval stays the default
(`audit/BLUEPRINT-ASSESSMENT-2026-10-20.md` §2):

1. **Build it: yes**, in the phases of §7. Single step remains what every
   organisation has until it configures a chain, so `PERMISSIONS.md` and
   `INDUSTRY_TEMPLATES.md` now say "single step by default", not "by design".
2. **Department head: yes**, `departments.head_employee_id`, delivered with
   Phase B's settings screen, where it is first needed.
3. **Fallback: yes**, the organisation's HR approvers (the `permission` kind),
   and the history records that the fallback was used.
4. **Default chain: today's single step.** No organisation's routing changes
   without someone choosing it.
5. **Escalation: yes**, 48 hours, to the approver's supervisor, else HR. It
   notifies only; nothing is auto-approved (Phase C).
6. **Parallel approvals: later.**

### What Phase A built

- Tables `approval_workflows`, `approval_workflow_steps`, `approval_instances`,
  `approval_actions`, all tenant-owned. `approval_delegations` waits for
  Phase C.
- `ApprovalEngine` starts an instance when a leave request or attendance
  correction is created, snapshotting the workflow that applies. It resolves
  each step's approver when the step is entered (the supervisor becomes that
  user; no one, or only the requester, falls back to the permission rule), so
  the inbox can filter in SQL.
- A request with no instance — everything submitted before this shipped — is
  on the built-in default workflow, which is today's single step.
- The decision services ask the engine first: an approval that is not the
  last step records the step and leaves the request pending; the last step
  calls the existing `approve()`. Policies are unchanged, so every step still
  needs the request's permission and reach.
- The inbox shows "step 2 of 3" when a chain has more than one step, and the
  leave and correction resources carry `approval` with the step and its
  history.
- Supported approver kinds in Phase A: `permission`, `supervisor`, `role`
  (the user's system role), `user`. `department_head` arrives with its column
  in Phase B.

### What Phase B's backend built (2026-10-21)

- `departments.head_employee_id`, set through the department endpoints as
  `head_employee_public_id` and returned as `head`.
- The `department_head` approver kind: the head of the requester's department,
  through the same eligibility as every other kind, so a head asking for leave
  themselves, or one who cannot reach the employee, falls back to HR.
- `GET|POST /settings/approval-workflows` and
  `PUT|DELETE /settings/approval-workflows/{publicId}`, behind
  `settings.manage`. An update replaces the whole chain, steps included, and
  is audited with the steps before and after. At most five steps; a role must
  be a tenant role other than `employee`; a named user, department, branch or
  leave type must belong to the organisation (a foreign id gets the same
  `validation.exists` message as a missing one); leave-type conditions only on
  leave chains.
- The submission notice goes to the person the first step names, falling back
  to the supervisor as before when the step is open to a role or to the
  permission holders.

Then the settings screen (Settings > Approval chains, #365).

### Profile changes on the engine (2026-10-21)

- `profile_update` is a request type: chains for it are configured the same
  way, every step needs `employee.update` and reach, and the change is applied
  to the employee record only at the last step.
- The staging notice goes to the person the first step names, else to every
  reviewer as before. A resubmitted field supersedes the old request one row
  at a time, so the old request's approval closes; withdrawing closes it too.
- Rejecting is refused to someone who is not the current step's approver, as
  for leave and corrections; the existing self-review rules are unchanged.

### Overtime requests on the engine (2026-10-22)

`overtime` is the fourth request type: overtime asked for before it is worked
(blueprint S8). Every step needs `overtime.approve` and reach; the inbox, batch
decisions, reminders and escalation handle it like a correction. With the
organisation's `overtime_requires_approval` on, payroll flags overtime worked
past what was approved for the day, and pays it.

### Payroll stays off the engine for now (decided 2026-10-21)

Payroll was the last item of Phase B. It is deferred, because it is the
riskiest piece and adds the least:

- A run already needs a second person: the maker-checker rule from #324
  refuses approval by whoever processed it, with an audited exception for an
  organisation whose only approver did.
- A run has no single employee, so the supervisor, department-head and reach
  rules the engine applies to every step have nothing to apply to.
- A run is not pending from creation: it becomes approvable when processing
  completes, and reprocessing or voiding starts it over. The engine would need
  to start and close instances on those transitions, inside the money path.

Revisit when an organisation asks for a payroll sign-off chain longer than
maker and checker.

### Reminders and escalation follow the chain (Phase C, part, 2026-10-21)

- The daily 06:00 reminder job (`SendApprovalRemindersJob`) asks the engine
  who can decide a request's current step. On a configured chain that is the
  step's approvers, exactly as the inbox lists them, and the 48 hours run from
  when the step began, not from when the request was filed. A request on the
  built-in single step is reminded as before.
- In the same run, a step that names one person (`supervisor`,
  `department_head`, `user`) and has waited past its `sla_hours` is escalated
  once: that person's supervisor is told, or every active HR admin when they
  have none. The escalation is recorded in the request's history and the audit
  log. It only notifies; nothing is approved or moved on. Steps open to a role
  or to the permission holders are not escalated, since the reminder already
  reaches the whole group.
- It runs daily rather than hourly, so an escalation lands at most a day after
  the limit passes.
- **An escalated step opens to everyone who may approve the request**
  (decided 2026-10-22). Without this, a step naming one absent person could
  wait for ever: the escalation told their supervisor, but nobody else was
  allowed to decide it. The named approver still can; every other rule
  (permission, reach, no self-approval, no two steps by one person) still
  applies; the history's `escalate` entry records `opened_to`. This is the
  small answer to an approver being away; delegation, the larger one, waits
  because a delegate rarely has the absent approver's reach.

Still to come in Phase C: delegation while an approver is away, and returning
a request to its author for correction.

### Attendance reconciliation before payroll (2026-10-27)

Payroll staff reconciled attendance by hand: the run only flagged absences,
overtime limits and integrity findings, nothing tracked whether anyone acted
on them, and lateness was ignored. Reconciliation adds no approval type and no
table of its own. `App\Services\Payroll\AttendanceReconciliation` reads a pay
period's exceptions by payroll's own rules (the `PayrollEngine` detectors and
`payableEmployees`) and links each to the request that settles it, all
already on this engine:

| Exception | Settled by |
|---|---|
| Absent with nothing on record | A missed-punch claim (`attendance_corrections.kind = missed_punch`, approving it creates the record), or leave HR files for the employee: unpaid (deducted by the existing unpaid-leave step), excused, or a business trip (`leave_types.kind` `excused` / `duty`) |
| Late arrival or early departure | A lateness decision (`kind = lateness`): minutes to deduct, or 0 to waive. Moves no punch |
| Check-out missing, or written by auto check-out | A correction, as before |
| Overtime past what was approved | An overtime request for the day; overtime over a limit is listed for information only, because it is paid |
| Integrity flag, duplicate punches | Their own screens |

An exception's state (`open`, `pending`, `resolved`, `info`) is derived from
the request it points at and never stored, so it cannot disagree with the
requests. The engine's rules still hold: nobody approves their own request,
and nobody approves what they filed. A request HR files on someone's behalf is
recorded as a `filed_for` action, and its filer cannot approve it. A lateness
decision must also be approved by someone holding `payroll.process`.

Organisations choose how firmly this binds (`attendance_settings.reconciliation_mode`,
changed with `payroll.manageConfig`):

- `off`, the default: runs are approved exactly as before.
- `advisory`: the run page shows what is still open.
- `required`: a run is refused approval while anything in its period is open or
  pending, and once a decision was made after it was calculated (reprocess it).

Lateness is deducted only after an organisation switches it on and records its
basis (`lateness_deduction_enabled`, `lateness_deduction_basis`): Proclamation
1156/2019 Art. 59 allows deductions from wages only on a stated basis. The
deduction is basic / 22 / 480 a minute, capped at a third of the period's
basic. **Open for the accountant (O2):** whether loans and cost sharing count
toward the same one-third ceiling. Until that is answered, lateness alone is
capped and nothing else changes.
