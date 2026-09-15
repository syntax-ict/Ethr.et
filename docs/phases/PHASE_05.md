# Phase 5 — Portals & Notifications (v2.0)

> **Design record — not a progress tracker.**
> The `- [ ]` checkboxes below are the original up-front specification and were
> never maintained against the code. They under-report reality badly: several
> phases read as 0% complete while the features they describe are live and
> covered by tests. **Do not use them to judge what is done.**
>
> The live, code-grounded status is [`ENTERPRISE_ROADMAP.md`](../ENTERPRISE_ROADMAP.md),
> and the measured state of the codebase is [`audit/BASELINE.md`](../audit/BASELINE.md).
> Per the project rule, the source of truth is the code — verify against it, not
> against this file.
>
> This header previously cited `ETHR_AUDIT.md` and `ETHR_AUDIT_2026-08-14.md`.
> Neither is in the tree and, as far as git history shows, neither ever was; the
> roadmap link was also written as a sibling path and resolved nowhere from this
> directory. Corrected in Phase 1 — a header whose only job is to route readers
> to current truth was routing them to nothing.
>
> Keep this document for its design intent: scope, data model, and acceptance
> criteria, which remain accurate and useful.

## Prerequisites
- Phase 3 complete (attendance, corrections)
- Phase 4A complete (leave)
- Phase 4B complete (payroll) — payslip viewing added after 4B ships

## Objective
Build the employee self-service portal (mobile-first), manager portal with unified approval center, and the notification system across all channels.

**NOTE:** Employee self-service (S24) should ship immediately after Phase 4A (leave). Payslip viewing is added as a follow-up after Phase 4B ships. This delivers employee value earlier.

---

## Progress / Gap Closure Log

### 2026-08-02 — S24 profile-update approval workflow ✅

**Gap (data loss, user-visible).** `ProfileController::update()` split the payload into
"allowed" and "sensitive" fields, applied the first set, and for the second returned
`pending_approval: { status: 'pending_approval', … }` with the message *"Changes to
sensitive fields require HR approval."* — then **discarded the values**. There was no
`profile_update_requests` table, no model, no reviewer endpoint, and nothing in the
approvals centre. An employee who submitted a new bank account was told it was queued;
it was written to an audit line and dropped. The spec's `ProfileUpdateRequestController`,
`ProfileUpdateRequestedNotification` and `ProfileUpdateApprovedNotification` did not exist.

**Closed:**
- `profile_update_requests` table — one row per field, `old_value`/`new_value` **encrypted
  at rest** (the gated set is exactly the sensitive set: bank account, TIN).
- `ProfileUpdateRequest` model + `ProfileUpdateStatus` enum + factory + policy
  (`employee.update` — approving a gated change *is* the employee edit).
- `ProfileUpdateRequestService` — stage / approve / reject. Re-submitting a field
  supersedes the earlier pending row rather than queuing a second one; a value equal to
  what is already on record queues nothing. Approval routes each field to its real
  destination (`employees` or `employee_bank_details`).
- Gated set widened from `name`/`bank_name`/`bank_account_number` to include `name_am`
  and `tin`, matching the S24 spec. Both were previously droppable by `validated()`.
- Endpoints: `GET /profile-update-requests`, `POST /profile-update-requests/{id}/review`.
  `GET /profile` now returns `pending_updates` so the employee can see their own queue.
- Wired into the approvals centre (`type: profile_update`), including batch review —
  gated per item on `employee.update`, so a supervisor cannot approve a bank-account
  change by bundling it with leave.
- **Account numbers are masked** (last four only) for reviewers holding `employee.update`
  without `employee.viewFinancial`, in both the resource and the approvals summary.
- Frontend: pending-changes panel on `/profile`, delta shown in the approvals card,
  cache invalidation across profile / approvals / employees.

**Verified:** 23 Pest cases in `ProfileUpdateRequestTest` (staging, supersede, no-op,
approve→apply for both destinations, reject, double-review 422, employee cannot review,
cross-tenant isolation, masking under a custom role that grants review without financial
visibility, notifications both directions, approvals-centre integration). Frontend:
`profile-pending-updates.test.tsx`. Then driven end-to-end in a browser against the running
stack: staged → HR queue → approve → `employees.name` and `employee_bank_details` updated.

**Two bugs the green suite did not catch, found by running it:**
- **`GET /profile` 500'd once a request existed** — the resource reads
  `employee`/`requester`/`reviewer` and they were not eager-loaded, so
  `preventLazyLoading` (on outside production) threw. The suite passed because Laravel
  exempts freshly-created models from the violation: the staging response was fine, only a
  *subsequent* read failed. Fixed + a test that forces `preventLazyLoading(true)`.
- **A Reverb outage turned a successful write into a 500** — the row committed, then the
  inline notification threw `BroadcastException`, so the employee was told their change
  failed when it had in fact been queued. Notification delivery is now best-effort and
  logged. Same class as the `REVERB_HOST` bug in the 2026-07-31 session.

### 2026-08-02 — S26 notification preferences actually enforced ✅

**Gap (dead control).** `GET/PUT /notifications/preferences` persisted a per-user ×
per-type × per-channel matrix, and Settings rendered the toggles — but all 16
notifications hard-coded their own `via()`, and **no code path anywhere read a stored
preference row**. Turning off "email me about payslips" changed a database row and
nothing else.

**Closed:** `RespectsNotificationPreferences` trait maps the API vocabulary
(`in_app`/`email`/`sms`) onto Laravel channels (`database`/`broadcast`/`mail`/`sms`) and
filters `via()`. Applied to the 11 notifications that have a preference type. Deliberately
**not** applied to `AccountActivationNotification`, `PasswordResetLinkNotification`,
`SystemAlertNotification`, `DeviceOfflineNotification` or `TrialExpiringNotification` —
transactional and security mail must not be suppressible. `in_app` remains non-optional
(it is the record of what happened, and the controller already refuses to store it as
false). Added `profile_update` to `NOTIFICATION_TYPES`.

**Bug found by running it (the suite was green):** `NotificationPreference` is
`BelongsToTenant`, and a queued notification is delivered by a worker with no
`CurrentTenant` bound — under the global scope the lookup returned **zero rows**, which is
indistinguishable from "user has no preferences", so every opt-out was silently ignored on
exactly the delivery path most notifications take. The lookup now runs
`withoutGlobalScopes()` keyed on `user_id`, which is strictly narrower than `tenant_id` (a
user belongs to one tenant) and so cannot reach another tenant's rows.

**Verified:** 7 Pest cases in `NotificationPreferenceEnforcementTest`, including a
round-trip from the endpoint through to actual `via()` output and a case that clears tenant
context to reproduce the queue-worker path.

### 2026-08-02 — S26 48-hour approval reminders now fire ✅

**Gap (dead code).** `ApprovalReminderNotification` shipped in Phase 5 with a mail
template, a translation key and a preference toggle — and **no caller**. Nothing in the
codebase ever constructed it, so the specified "approver after 48h" reminder could not
happen.

**Closed:** `SendApprovalRemindersJob`, scheduled daily at 06:00 UTC (09:00 EAT, the start
of the Ethiopian working day). Collects pending leave, corrections and profile updates
older than 48h; leave/corrections route to the employee's supervisor, profile updates to
`employee.update` holders. **One notification per approver**, not one per stale item.

**Verified:** 6 Pest cases in `ApprovalReminderTest`, including the schedule registration.

### 2026-08-02 — S26 SMS adapter built ✅

**Gap (undeliverable channel).** S26 refers to "`SmsSender` interface (existing)" and the
CLAUDE.md stack table lists an interface-based SMS layer with LogSms/EthioTelecom drivers.
**Neither existed** — the only "sms" string in `app/` was the channel name in the
preferences controller. Users could opt into a channel with no delivery path at all.

**Closed:** `App\Contracts\SmsSender` + `LogSmsSender` (dev) + `EthioTelecomSmsSender`
(HTTP gateway) + `SmsChannel` registered as a Laravel notification channel, enforcing the
specified **5 SMS/day per user** cap (expiring at midnight EAT, so the allowance resets at
the start of the user's day). `SmsNumber` normalizes `09…` / `+2519…` / `2519…` to E.164.
`config/sms.php` + `.env.example` entries.

**Honesty rail:** both drivers report `isAvailable()`, surfaced as `channel_availability`
on the preferences endpoint. The log driver reports **false** — a log line is not delivery
— and the UI disables the SMS column with a "Not configured" badge rather than offering a
toggle nothing acts on. The EthioTelecom driver is unavailable until credentials are set
and refuses to send rather than silently no-op'ing.

**Not verified against the live operator gateway** (no credentials in this environment).
The request shape follows the standard bulk-SMS form post; the driver is reachable only by
explicit `SMS_DRIVER=ethiotelecom`, so an unexercised integration cannot be hit by
accident. **Confirm against EthioTelecom before enabling in production.**

**Verified:** 10 Pest cases in `SmsChannelTest`; 3 Vitest cases for the UI gating.

---

## S24 — Employee Self-Service Portal

### Backend
- [ ] Employee dashboard endpoint: `GET /api/v1/dashboard/employee`
  - Today's attendance status (checked in/out, worked hours, check-in time)
  - Leave balance summary (top 3 types: annual, sick, remaining days)
  - Pending approvals count (leave, corrections, profile updates)
  - Unread notifications count
  - Latest payslip summary (month, net amount) — populated after Phase 4B
  - Upcoming holidays (next 3)
  - Cached 5 min per user
- [ ] Profile self-update: `PUT /api/v1/profile`
  - Immediate fields: phone, address (AddressForm fields), emergency contacts, photo
  - Approval-required fields: bank details, name, name_am, TIN
  - Changes to approval fields create `ProfileUpdateRequest` record
  - `profile_update_requests` table: employee_id, field_name, old_value, new_value, status (pending/approved/rejected), reviewed_by, reviewed_at
  - `ProfileUpdateRequestController`: CRUD + approve/reject (requires `employees.edit`)
  - Dispatches notification to HR on submission
  - Permission: `self.edit_basic`
- [ ] Organization directory:
  - `GET /api/v1/directory` — searchable employee list
  - Fields: name, name_am, photo, department, position, phone, email
  - Excluded: salary, bank details, TIN, personal details
  - FULLTEXT search on name, department, position
  - Paginated, sortable
  - Cacheable for offline access (30-min cache headers)
  - Permission: any authenticated user
- [ ] Announcement viewing:
  - `GET /api/v1/announcements` — published announcements for this tenant (paginated, ordered by priority then date)
  - `GET /api/v1/announcements/{id}` — single announcement detail
  - Permission: any authenticated user

### Frontend
- [ ] Employee dashboard page:
  - Welcome header: "Good morning, {first_name}" with photo and date (Ethiopian + Gregorian if enabled)
  - Quick action buttons: Check In, Apply Leave, View Payslip (large, tappable on mobile)
  - Status cards row (`StatCard`): attendance today, leave balance (annual), pending approvals
  - Recent activity feed (Timeline: last 5 events — check-ins, leave approvals, payslips)
  - Announcements panel (latest 3, priority-badge, click to expand)
  - Mobile-optimized: stacked cards, large touch targets (48px+)
- [ ] My Profile page:
  - Profile header: photo (with edit overlay), name (EN + AM), status badge, employee code
  - Information cards: personal, employment, organization, financial (read-only)
  - Edit button on allowed fields (phone, address, emergency contacts, photo)
  - Approval-required fields: show "Edit" button → drawer form with notice "Changes require HR approval"
  - Pending profile updates section (if any — showing field, old value, new value, status)
  - Documents section: own documents list with download
- [ ] My Attendance page (enhanced from Phase 3):
  - Monthly calendar view: days color-coded (present=green, late=amber, absent=red, leave=blue, holiday=gray)
  - List view toggle: DataTable with date, check-in, check-out, worked hours, status, source
  - "Request Correction" button on each record (opens correction drawer)
  - Monthly summary: total days worked, OT hours, late count
  - Ethiopian calendar dates shown when enabled
- [ ] My Leave page (enhanced from Phase 4):
  - Balance cards per type (`StatCard`)
  - "Apply for Leave" button (opens leave request drawer)
  - Request history DataTable (type, dates, days, status, actions)
- [ ] My Payslips page (added after Phase 4B):
  - Monthly list DataTable (period, net amount, download button)
  - Click to view: styled payslip layout
  - Calculation breakdown accordion
  - Download PDF button
- [ ] Announcements page:
  - Announcement cards (title, excerpt, date, priority badge: urgent=red, important=amber, normal=none)
  - Click to read full announcement in modal/drawer
  - Priority-based ordering (urgent first, then by date)
- [ ] Organization directory:
  - Search bar (debounced, searches name in both languages + department + position)
  - Card grid view: employee photo, name, department, position
  - Click card: detail drawer with phone (tap to call on mobile), email (tap to email)
  - Department filter dropdown
  - Cached for offline viewing (IndexedDB via service worker)
  - Switch to list view on desktop
- [ ] Mobile-specific patterns:
  - `MobileTabBar` active: Home, Attendance, Leave, Payslips, More
  - Pull-to-refresh on all list/card views
  - Haptic feedback on check-in/check-out button (Vibration API)
  - Bottom sheets instead of modals on mobile (< 768px)
  - Swipe actions on list items where appropriate
  - Persistent `OfflineBanner` when disconnected
- [ ] All pages responsive and bilingual
- [ ] QueryBoundary on all data sections

### Tests
- [ ] Employee dashboard data assembly — all fields populated (Pest)
- [ ] Profile self-update — immediate fields update instantly (Pest)
- [ ] Profile self-update — approval-required fields create request (Pest)
- [ ] Profile update approval flow (Pest)
- [ ] Directory listing — no sensitive data exposed (Pest)
- [ ] Directory search — FULLTEXT works (Pest)
- [ ] Announcement listing — only published, correct ordering (Pest)
- [ ] Permission: employee sees own data only (Pest)
- [ ] Employee dashboard UI — all cards render (Vitest)
- [ ] Directory search and card rendering (Vitest)
- [ ] MobileTabBar renders for employee role (Vitest)
- [ ] Profile edit — approval notice shown for restricted fields (Vitest)

### Exit Criteria
- Employee has unified dashboard for all self-service needs
- Profile updates work with approval for sensitive fields
- Directory is searchable and offline-cacheable
- Mobile-first design verified at 375px and 390px
- Bottom tab navigation functional on mobile
- All pages work offline-first where possible

---

## S25 — Manager Portal & Approval Center

### Backend
- [ ] Manager dashboard endpoint: `GET /api/v1/dashboard/manager`
  - Team attendance today: { present, absent, late, on_leave, total } counts
  - Pending approvals count (leave + corrections + profile updates)
  - Team overtime this week (total hours, total amount)
  - Team leave summary this week (who's off, return dates)
  - Team size and composition (by department if managing multiple)
  - Alerts: overdue approvals (> 48h), attendance anomalies
  - Cached 5 min
- [ ] Unified approval center: `GET /api/v1/approvals/pending`
  - Returns all pending items awaiting this user's action:
    - Leave requests pending this user's approval step
    - Attendance corrections pending this user's approval step
    - Profile update requests (if HR)
  - Each item: `{ type, id, employee_name, employee_photo, summary, submitted_at, priority, days_pending }`
  - Sortable by date, type, priority
  - Filterable by type
  - Ordered: overdue first (> 48h), then by submitted_at
- [ ] Batch approval: `POST /api/v1/approvals/batch`
  - Input: `{ items: [{ type: 'leave'|'correction'|'profile', id, action: 'approve'|'reject', reason? }] }`
  - Process each independently, return results per item
  - Audit log entry per action
  - Permission: `leave.approve` or `corrections.approve` as applicable
- [ ] Team monitoring endpoints:
  - `GET /api/v1/team/attendance/today` — real-time team attendance (employee, status, check-in time, source)
  - `GET /api/v1/team/attendance/summary?period=weekly|monthly` — trend data
  - `GET /api/v1/team/overtime?period=monthly` — overtime by employee (hours, amount)
  - `GET /api/v1/team/leave/calendar?month=2026-07` — team leave calendar
  - All scoped to user's direct/indirect reports
- [ ] Manager alerts:
  - Attendance anomalies for team (late streaks, sudden absences)
  - Pending approvals older than 48h → `ApprovalReminderNotification`
  - Overtime threshold breach (employee exceeds weekly/monthly cap)
- [ ] Delegation: `POST /api/v1/approvals/delegate`
  - Delegate approval authority to another user for a date range
  - During delegation, both original and delegate can approve
  - `approval_delegations` table: delegator_id, delegate_id, start_date, end_date, modules (JSON array)
  - Audit logged

### Frontend
- [ ] Manager dashboard page:
  - Team attendance card (real-time: present/absent/late/on-leave with `ProgressRing` for attendance rate)
  - Pending approvals card with count badge (click navigates to approval center)
  - Team overtime card (`StatCard` with trend)
  - Quick action buttons: View Team, Approve Requests
  - Alerts section: `StatusBadge` items for anomalies and overdue approvals
  - Responsive: cards stack on mobile
- [ ] Unified approval center page:
  - Tab bar: All | Leave | Corrections | Profile Updates (with counts)
  - DataTable of pending items: type icon, employee photo+name, summary, days pending, quick actions
  - Overdue items highlighted (amber background if > 48h)
  - Quick approve/reject inline buttons (with `ConfirmDialog` for reject requiring reason)
  - Click row for full detail drawer:
    - Leave: employee, type, dates, days, balance impact, team calendar context
    - Correction: before/after `ComparisonCard`, payroll impact (if available), reason
    - Profile update: field name, old value, new value
    - `ApprovalChain` component showing workflow progress
  - Batch select (checkboxes) + "Approve Selected" / "Reject Selected" buttons
  - Empty state: "All caught up! No pending approvals." with celebratory icon
- [ ] Team attendance page:
  - Today's view: DataTable with employee, check-in, check-out, status, source, worked hours
  - Real-time updates via Reverb (attendance channel)
  - Historical: date picker, attendance grid (calendar-style)
  - Filter by status
- [ ] Team leave calendar page:
  - Monthly calendar: employees as rows, days as columns
  - Leave blocks color-coded by type
  - Click date to see who's off (popover)
  - Minimum staffing indicator per day
  - Holiday columns highlighted
- [ ] Overtime monitoring:
  - Monthly summary DataTable: employee, OT hours by type, total OT amount
  - Threshold warning: highlight employees exceeding weekly/monthly cap (red row)
  - Department total row
  - ChartWrapper bar chart: OT distribution by employee
- [ ] Delegation management:
  - "Delegate Approvals" button in approval center
  - Dialog: delegate selector (search), date range, module checkboxes
  - Active delegations list with cancel action
- [ ] All pages tablet-optimized (managers primarily use tablet/desktop)
- [ ] QueryBoundary on all data sections

### Tests
- [ ] Manager dashboard data — scoped to team only (Pest)
- [ ] Approval center aggregation — all pending types combined (Pest)
- [ ] Approval center — only items awaiting this user (Pest)
- [ ] Batch approval — mixed approve/reject (Pest)
- [ ] Batch approval — partial failure handling (Pest)
- [ ] Team attendance scoping — only direct/indirect reports (Pest)
- [ ] Delegation — delegate can approve during period (Pest)
- [ ] Delegation — delegate cannot approve outside date range (Pest)
- [ ] Overdue approval alert — triggered after 48h (Pest)
- [ ] Permission checks on all team endpoints (Pest)
- [ ] Approval center UI — tabs with counts (Vitest)
- [ ] Team calendar — leave blocks rendered (Vitest)
- [ ] Batch select + approve (Vitest)
- [ ] Empty state rendering (Vitest)

### Exit Criteria
- Managers have unified view of team status
- Approval center aggregates all pending items across modules
- Batch approval works for mixed item types
- Overdue approvals highlighted and alerted
- Team attendance and leave views functional with real-time updates
- Overtime monitoring with threshold warnings
- Delegation functional with date range and audit trail

---

## S26 — Notification System

### Backend
- [ ] Notification infrastructure:
  - Laravel notifications with database + mail + broadcast channels
  - `notifications` table (Laravel default)
  - Notification preferences per user: `GET/PUT /api/v1/notifications/preferences`
  - Per-user preference: enable/disable each notification type per channel (in-app always on)
  - Default preferences seeded for each role
- [ ] `NotificationController`:
  - `GET /api/v1/notifications` — paginated, filterable (read/unread, type)
  - `PUT /api/v1/notifications/{id}/read` — mark as read
  - `PUT /api/v1/notifications/read-all` — mark all as read
  - `GET /api/v1/notifications/unread-count` — badge count
- [ ] Notification types (all implement Laravel Notification):
  - `LeaveRequestedNotification` → approver (in-app + email)
  - `LeaveApprovedNotification` → employee (in-app + email)
  - `LeaveRejectedNotification` → employee (in-app + email)
  - `AttendanceCorrectionRequestedNotification` → approver (in-app)
  - `AttendanceCorrectionApprovedNotification` → employee (in-app)
  - `AttendanceAnomalyNotification` → supervisor (in-app)
  - `MissingPunchNotification` → employee + supervisor (in-app + email)
  - `PayrollProcessedNotification` → finance team (in-app)
  - `PayslipAvailableNotification` → employee (in-app + email)
  - `AnnouncementNotification` → target audience (in-app + broadcast)
  - `ApprovalReminderNotification` → approver after 48h (in-app + email)
  - `DeviceOfflineNotification` → admin (in-app + email)
  - `TrialExpiringNotification` → tenant admin at 30/7/1 days (in-app + email)
  - `ProfileUpdateRequestedNotification` → HR (in-app)
  - `ProfileUpdateApprovedNotification` → employee (in-app)
- [ ] Email templates:
  - Clean, branded layout (ETHR logo, deep teal header, Ethiopian gold accent)
  - Bilingual (EN/AM based on user's locale preference)
  - Customizable per tenant via notification template editor
  - Template variables: `{employee_name}`, `{leave_type}`, `{date}`, `{company_name}`, `{amount}`, etc.
  - Unsubscribe link per notification type
- [ ] SMS adapter (for critical notifications):
  - `SmsSender` interface (existing)
  - Production: EthioTelecom HTTP gateway adapter
  - Rate limiting: max 5 SMS/day per user
  - SMS-eligible notifications: OTP, payroll ready
- [ ] Reverb (WebSocket) broadcast:
  - Reverb server configured in Docker Compose
  - Private channels: `user.{id}` for personal notifications
  - Tenant channel: `tenant.{id}` for announcements
  - All in-app notifications broadcast via Reverb for real-time delivery
  - Connection management: auth on connect, heartbeat, reconnect with backoff
- [ ] `AnnouncementController`:
  - `POST /api/v1/announcements` — create (requires `notifications.manage_announcements`)
  - `PUT /api/v1/announcements/{id}` — edit
  - `DELETE /api/v1/announcements/{id}` — soft delete
  - Target audience: all, specific departments, specific branches, specific roles
  - Priority: normal, important, urgent
  - Schedule: publish now or schedule for future date
  - Dispatches `AnnouncementNotification` to target audience

### Frontend
- [ ] Notification bell in header:
  - Unread count badge (real-time via Reverb — updates without refresh)
  - Click opens dropdown panel: last 10 notifications
  - Each notification: type icon, title, description, time ago ("2h ago"), read/unread dot
  - Click notification → navigate to related item (leave request, payslip, etc.)
  - Mark as read on click
  - "View All" link → notifications page
- [ ] Notifications page:
  - DataTable or infinite scroll list
  - Filter tabs: All | Unread
  - Group by date (Today, Yesterday, This Week, Older)
  - Each notification: icon, title, description, time ago, read/unread indicator
  - "Mark all as read" button
  - QueryBoundary for all states
- [ ] Real-time WebSocket integration:
  - Connect to Reverb on authentication
  - Listen to `user.{id}` and `tenant.{id}` channels
  - New notification → update bell badge count, show `UndoToast` (actually a notification toast) with title
  - Reconnect with exponential backoff on disconnect (1s, 2s, 4s, 8s, max 30s)
- [ ] Notification preferences page:
  - Table: notification type rows, channel columns (in-app, email, SMS)
  - Toggle each on/off (in-app always on, cannot disable)
  - SMS column only shown if SMS is configured for tenant
  - Save button
- [ ] Announcement management (admin):
  - Create form: title (EN + AM), body (rich text — EN + AM), priority selector, target audience (departments/branches/roles multi-select), schedule (now or future date)
  - Announcements DataTable: title, date, target, priority, status (draft/published/scheduled)
  - Publish / Schedule / Edit / Delete actions
- [ ] Toast notifications for real-time events (shadcn/ui Sonner):
  - Non-intrusive slide-in from bottom-right (desktop) or top (mobile)
  - Auto-dismiss after 5 seconds
  - Click to navigate to related item

### Tests
- [ ] Notification creation and retrieval (Pest)
- [ ] Mark as read / read all (Pest)
- [ ] Unread count accuracy (Pest)
- [ ] Email sending — mock mailer, verify template content (Pest)
- [ ] Email bilingual — correct language based on user locale (Pest)
- [ ] Notification preferences — respected when dispatching (Pest)
- [ ] Reverb broadcast — mock broadcaster, verify event (Pest)
- [ ] Announcement CRUD + targeting (Pest)
- [ ] Announcement scheduling (Pest)
- [ ] SMS rate limiting — 6th SMS rejected (Pest)
- [ ] Notification bell component — badge count (Vitest)
- [ ] Notification dropdown — renders last 10 (Vitest)
- [ ] Notification list — grouping by date (Vitest)
- [ ] Preferences table — toggles (Vitest)
- [ ] Toast notification rendering (Vitest)

### Exit Criteria
- All 15 notification types implemented across in-app + email channels
- Real-time delivery via Reverb WebSocket
- Notification bell with live badge count
- User preferences for notification channels
- Announcements publishable to targeted audience with scheduling
- Email templates branded, bilingual, customizable
- SMS adapter ready for production (rate limited)
- Toast notifications for real-time events

---

## Phase 5 Exit Criteria

- [ ] Employee self-service portal complete (dashboard, profile, attendance, leave, payslips, directory)
- [ ] Mobile-first employee experience (bottom tab bar, pull-to-refresh, bottom sheets)
- [ ] Manager portal with unified approval center
- [ ] Batch approval functional
- [ ] Team attendance + leave monitoring with real-time updates
- [ ] Approval delegation functional
- [ ] Notification system across all channels (in-app, email, SMS, WebSocket)
- [ ] Real-time notifications via Reverb
- [ ] Notification preferences per user
- [ ] Announcements with targeted delivery and scheduling
- [ ] All Pest + Vitest tests passing
- [ ] TenantIsolationTest passes
- [ ] Browser verified: employee and manager flows at 375px + 1280px
