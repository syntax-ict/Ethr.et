# Phase 5 — Portals & Notifications

## Prerequisites
- Phase 3 complete (attendance, corrections)
- Phase 4 complete (leave, payroll)

## Objective
Build the employee self-service portal, manager portal with unified approval center, and the notification system across all channels.

---

## S23 — Employee Self-Service Portal

### Backend
- [ ] Employee dashboard endpoint: `GET /api/v1/dashboard/employee`
  - Today's attendance status (checked in/out, worked hours)
  - Leave balance summary (top 3 types)
  - Pending approvals count
  - Recent notifications count
  - Latest payslip summary (month, net amount)
  - Upcoming holidays (next 3)
- [ ] Profile self-update: `PUT /api/v1/profile`
  - Allowed fields: phone, emergency contacts, address, photo
  - Changes that affect payroll (bank details, name) require HR approval
  - Creates `ProfileUpdateRequest` (similar to correction workflow)
- [ ] Attendance self-service:
  - `GET /api/v1/attendance/my` (already exists from Phase 3)
  - `POST /api/v1/attendance/corrections` (already exists)
- [ ] Leave self-service:
  - `GET /api/v1/leave/my` (already exists from Phase 4)
  - `POST /api/v1/leave/request` (already exists)
  - `GET /api/v1/leave/balance` (already exists)
- [ ] Payslip self-service:
  - `GET /api/v1/payroll/payslips/my` (already exists from Phase 4)
- [ ] Announcement viewing:
  - `GET /api/v1/announcements` — published announcements for this tenant (paginated)
  - `GET /api/v1/announcements/{id}` — single announcement
- [ ] Organization directory:
  - `GET /api/v1/directory` — searchable employee list (name, photo, department, position, phone, email)
  - Limited fields visible (no salary, no bank details)
  - Cacheable for offline access

### Frontend
- [ ] Employee dashboard page:
  - Welcome header with employee name and photo
  - Quick action buttons: Check In, Apply Leave, View Payslip
  - Status cards: attendance today, leave balance, pending approvals
  - Recent activity feed
  - Announcements panel
- [ ] My Profile page:
  - View all personal information
  - Edit button for allowed fields
  - Edit creates pending update request (if requires approval)
  - Document viewer (own documents)
- [ ] My Attendance page (from Phase 3, ensure complete):
  - Calendar + list views
  - "Request Correction" button on each record
  - Monthly summary
- [ ] My Leave page (from Phase 4, ensure complete):
  - Balance cards
  - "Apply for Leave" button
  - Request history
- [ ] My Payslips page (from Phase 4, ensure complete):
  - Monthly list
  - View + download
- [ ] Announcements page:
  - Announcement cards (title, excerpt, date, priority badge)
  - Click to read full announcement
  - Priority-based ordering (urgent first)
- [ ] Organization directory:
  - Searchable card/list grid
  - Employee cards: photo, name, department, position, phone (click to call), email
  - Department filter
  - Cached for offline viewing (PWA)
- [ ] All pages mobile-optimized (primary use case for employees)

### Tests
- [ ] Employee dashboard data assembly (Pest)
- [ ] Profile self-update (allowed vs restricted fields) (Pest)
- [ ] Directory listing (no sensitive data exposed) (Pest)
- [ ] Announcement listing (Pest)
- [ ] Employee dashboard UI (Vitest)
- [ ] Directory search (Vitest)

### Exit Criteria
- Employee has a unified dashboard for all self-service needs
- Profile updates work with approval for sensitive fields
- Directory is searchable and cacheable
- Mobile-first design verified

---

## S24 — Manager Portal & Approval Center

### Backend
- [ ] Manager dashboard endpoint: `GET /api/v1/dashboard/manager`
  - Team attendance today (present, absent, late counts)
  - Pending approvals count (leave + corrections + profile updates)
  - Team overtime this week
  - Team leave calendar summary (who's off this week)
  - Team size and composition
- [ ] Unified approval center: `GET /api/v1/approvals/pending`
  - Returns all pending items across modules:
    - Leave requests pending manager's approval
    - Attendance corrections pending manager's approval
    - Profile update requests pending manager's approval
  - Each item: type, employee, summary, submitted_at, priority
  - Sortable by date, type, priority
- [ ] Batch approval: `POST /api/v1/approvals/batch`
  - Input: array of { type, id, action: approve|reject, reason? }
  - Process each, return results
- [ ] Team monitoring:
  - `GET /api/v1/team/attendance/today` — real-time team attendance
  - `GET /api/v1/team/attendance/summary?period=weekly|monthly` — team attendance trends
  - `GET /api/v1/team/overtime?period=monthly` — overtime summary
  - `GET /api/v1/team/leave/calendar?month=2026-07` — team leave calendar
- [ ] Manager alerts:
  - Attendance anomalies for team members
  - Pending approvals older than 48h (escalation)
  - Overtime threshold breaches
- [ ] Delegation: managers can delegate approval authority during absence

### Frontend
- [ ] Manager dashboard page:
  - Team attendance card (real-time: present/absent/late/on-leave counts)
  - Pending approvals card with badge count
  - Team overtime card
  - Quick action buttons: View Team, Approve Requests
  - Alerts section (anomalies, overdue approvals)
- [ ] Unified approval center page:
  - Tab view: All | Leave | Corrections | Profile Updates
  - List of pending items with type icon, employee photo/name, summary, time ago
  - Quick approve/reject inline buttons
  - Click for full detail view
  - Batch select + approve/reject
  - Empty state: "All caught up!" message
- [ ] Team attendance page:
  - Today's view: employee list with check-in/out status, real-time
  - Historical: date picker, attendance grid
  - Filter by status
- [ ] Team leave calendar page:
  - Monthly calendar with team members as rows
  - Leave blocks color-coded by type
  - Click date to see who's off
  - Minimum staffing indicator
- [ ] Overtime monitoring:
  - Monthly summary table: employee, OT hours, OT amount
  - Threshold warning (highlight employees exceeding cap)
  - Department total
- [ ] All pages responsive (tablet-optimized for managers)

### Tests
- [ ] Manager dashboard data (Pest)
- [ ] Approval center aggregation (Pest)
- [ ] Batch approval (Pest)
- [ ] Team attendance scoping (only direct/indirect reports) (Pest)
- [ ] Delegation (Pest)
- [ ] Approval center UI (Vitest)
- [ ] Team calendar (Vitest)

### Exit Criteria
- Managers have unified view of team status
- Approval center aggregates all pending items
- Batch approval works
- Team attendance and leave views functional
- Overtime monitoring with threshold alerts

---

## S25 — Notification System

### Backend
- [ ] Notification infrastructure:
  - Laravel notifications with database + mail + broadcast channels
  - `notifications` table (Laravel default)
  - Notification preferences per user: `GET/PUT /api/v1/notifications/preferences`
  - User can disable specific notification types per channel
- [ ] `NotificationController`:
  - `GET /api/v1/notifications` — paginated, filterable by read/unread
  - `PUT /api/v1/notifications/{id}/read` — mark as read
  - `PUT /api/v1/notifications/read-all` — mark all as read
  - `GET /api/v1/notifications/unread-count` — badge count
- [ ] Notification types (implement all):
  - `LeaveRequestedNotification` -> approver (in-app + email)
  - `LeaveApprovedNotification` -> employee (in-app + email)
  - `LeaveRejectedNotification` -> employee (in-app + email)
  - `AttendanceCorrectionRequestedNotification` -> approver (in-app)
  - `AttendanceCorrectionApprovedNotification` -> employee (in-app)
  - `AttendanceAnomalyNotification` -> supervisor (in-app)
  - `MissingPunchNotification` -> employee (in-app + email)
  - `PayrollProcessedNotification` -> all employees (in-app)
  - `PayslipAvailableNotification` -> employee (in-app + email)
  - `AnnouncementNotification` -> target audience (in-app)
  - `ApprovalReminderNotification` -> approver after 48h (in-app + email)
  - `DeviceOfflineNotification` -> admin (in-app + email)
  - `TrialExpiringNotification` -> tenant admin (in-app + email)
- [ ] Email templates: clean, branded, bilingual (EN/AM based on user preference)
- [ ] SMS adapter activation (for critical: OTP, payroll ready):
  - `SmsSender` interface (already exists)
  - Production adapter: EthioTelecom or generic HTTP gateway
  - Rate limiting on SMS (max 5/day per user)
- [ ] Reverb (WebSocket) broadcast:
  - Set up Reverb server in Docker Compose
  - Private channels: `user.{id}` for personal notifications
  - Tenant channel: `tenant.{id}` for announcements
  - Broadcast all in-app notifications via Reverb for real-time delivery
- [ ] `AnnouncementController`:
  - `POST /api/v1/announcements` — create (tenant_admin/hr_admin)
  - `PUT /api/v1/announcements/{id}` — edit
  - `DELETE /api/v1/announcements/{id}` — delete
  - Target: all, specific department, specific branch, specific role
  - Dispatches `AnnouncementNotification` to target audience

### Frontend
- [ ] Notification bell in header:
  - Unread count badge (real-time via Reverb)
  - Click opens dropdown: recent notifications (last 10)
  - "View All" link to notifications page
  - Click notification -> navigate to related item
  - Mark as read on click
- [ ] Notifications page:
  - Full list (infinite scroll or paginated)
  - Filter: All / Unread
  - Group by date
  - Each notification: icon (type), title, description, time ago, read/unread indicator
  - "Mark all as read" button
- [ ] Real-time: Reverb WebSocket connection
  - Connect on auth
  - Listen to `user.{id}` channel
  - New notification: update bell badge, show toast
  - Reconnect on disconnect
- [ ] Notification preferences page:
  - Table: notification type rows, channel columns (in-app, email, SMS)
  - Toggle each on/off
  - In-app always on (cannot disable)
- [ ] Announcement management (admin):
  - Create: title, body (rich text), priority, target audience, schedule
  - List: title, date, target, status (draft/published)
  - Publish button
- [ ] Toast notifications for real-time events (shadcn/ui Sonner)

### Tests
- [ ] Notification creation and delivery (Pest)
- [ ] Mark as read / read all (Pest)
- [ ] Unread count (Pest)
- [ ] Email sending (Pest, mock mailer)
- [ ] Notification preferences (Pest)
- [ ] Reverb broadcast (Pest, mock broadcaster)
- [ ] Announcement CRUD + targeting (Pest)
- [ ] Notification bell component (Vitest)
- [ ] Notification list (Vitest)
- [ ] Preferences table (Vitest)

### Exit Criteria
- All notification types implemented across in-app + email channels
- Real-time delivery via Reverb WebSocket
- Notification bell with badge count
- User preferences for notification channels
- Announcements publishable to targeted audience
- Email templates branded and bilingual
- SMS adapter ready for production

---

## Phase 5 Exit Criteria

- [ ] Employee self-service portal complete (dashboard, profile, attendance, leave, payslips, directory)
- [ ] Manager portal with unified approval center
- [ ] Batch approval functional
- [ ] Team attendance + leave monitoring
- [ ] Notification system across all channels (in-app, email, SMS, WebSocket)
- [ ] Real-time notifications via Reverb
- [ ] Notification preferences per user
- [ ] Announcements with targeted delivery
- [ ] All Pest + Vitest tests passing
- [ ] Browser verified: employee and manager flows end-to-end
