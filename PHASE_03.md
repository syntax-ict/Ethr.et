# Phase 3 — Attendance Platform (Flagship)

## Prerequisites
- Phase 2 complete (employees, org structure, branches with geofence data)

## Objective
Build the attendance platform — ETHR's core differentiator. Unified attendance engine supporting multiple sources (biometric, mobile, offline, QR, web, manual), shift management, intelligence, and correction workflows.

---

## S12 — Attendance Engine Core

### Backend
- [ ] `attendance_records` table migration (see DATABASE.md)
- [ ] `AttendanceRecord` model with relationships (employee, branch, shift, device)
- [ ] `AttendanceEngine` service:
  - `record(AttendanceInput $input): AttendanceRecord` — unified entry point
  - Idempotency check (via `idempotency_key`)
  - Source validation
  - Shift matching (delegate to ShiftMatcher in S13)
  - Confidence scoring (via `ConfidenceScorer`)
  - Duplicate detection (same employee, same minute, different source -> merge or flag)
  - Dispatches `AttendanceRecorded` event
- [ ] `ConfidenceScorer` service: calculates score based on source + GPS + geofence + selfie (see ARCHITECTURE.md)
- [ ] `AttendanceController`:
  - `POST /api/v1/attendance/check-in` — employee checks in (web source)
  - `POST /api/v1/attendance/check-out` — employee checks out
  - `GET /api/v1/attendance/my` — current user's attendance (date range, paginated)
  - `GET /api/v1/attendance/team` — manager's team attendance
  - `GET /api/v1/attendance` — HR: all attendance (filterable by employee, department, branch, date range, status, source)
  - `GET /api/v1/attendance/today` — today's real-time attendance summary (present, absent, late count)
  - `GET /api/v1/attendance/{id}` — single record detail
- [ ] Attendance status calculation: compare check-in time vs shift start -> present/late/absent/early_leave
- [ ] Worked minutes + overtime minutes calculation
- [ ] `AttendancePolicy`: employee sees own; supervisor sees team; hr_admin sees all
- [ ] FormRequests for check-in/check-out
- [ ] Audit logging for manual entries

### Frontend
- [ ] Attendance overview page:
  - Today's summary cards (total, present, absent, late, on leave)
  - Real-time attendance list (employee, check-in, check-out, status, source, confidence)
  - Date picker to view historical days
  - Filters: department, branch, status, source
  - Export button (CSV)
- [ ] My attendance page (employee view):
  - Monthly calendar view (color-coded: green=present, red=absent, yellow=late, blue=leave)
  - List view (date, check-in, check-out, worked hours, status, source)
  - Monthly summary (total days, present, absent, late, OT hours)
- [ ] Attendance timeline component: visual timeline per employee per day
- [ ] Check-in/check-out button (web attendance, for employee self-service)
- [ ] Confidence score badge (color-coded: green >90, yellow 70-90, red <70)
- [ ] Source indicator icons (biometric, mobile, QR, web, manual icons)

### Tests
- [ ] Check-in + check-out flow (Pest)
- [ ] Idempotency (duplicate check-in returns existing) (Pest)
- [ ] Confidence scoring by source (Pest)
- [ ] Status calculation (on time, late, early leave) (Pest)
- [ ] Worked minutes + overtime calculation (Pest)
- [ ] Tenant isolation (Pest)
- [ ] Authorization matrix (Pest)
- [ ] Attendance list + filters (Vitest)
- [ ] Calendar view rendering (Vitest)
- [ ] Confidence badge display (Vitest)

### Exit Criteria
- Attendance records can be created from web source
- Confidence scoring works per source
- Status (present/late/absent) calculated from shift comparison
- Real-time dashboard shows today's attendance
- Employee can view own attendance history

---

## S13 — Shift Engine

### Backend
- [ ] `shifts` + `shift_assignments` tables migration (see DATABASE.md)
- [ ] `Shift` model with relationships
- [ ] `ShiftMatcher` service:
  - Given an employee and check-in time, find the matching shift
  - Logic: check employee assignment -> department assignment -> branch assignment -> default
  - Night shift handling (cross-midnight: end_time < start_time)
  - Grace period application
- [ ] `ShiftController`:
  - CRUD for shifts
  - `POST /api/v1/shifts/assign` — assign shift to employee/department/branch with date range
  - `GET /api/v1/shifts/schedule` — view schedule (who is on which shift, by date range)
  - `GET /api/v1/shifts/roster` — weekly/monthly roster view
- [ ] Shift rotation: weekly, bi-weekly, monthly rotation patterns
- [ ] Default shift seeder (8:30-17:30, standard Ethiopian work hours)
- [ ] `ShiftPolicy`: tenant_admin + hr_admin can manage

### Frontend
- [ ] Shift management page:
  - Shift list (name, time range, break, grace, type badge)
  - Create/edit dialog (name, start time, end time, break minutes, grace minutes, night shift toggle, color picker)
  - Delete with confirmation (check for active assignments)
- [ ] Shift assignment:
  - Assignment form: select shift, select target (employee/department/branch), date range
  - Visual roster: week/month grid with shifts color-coded
  - Drag-and-drop shift assignment on roster (optional enhancement)
- [ ] Schedule view:
  - Weekly/monthly view
  - Filter by department/branch
  - Employee rows, day columns, shift cells (color-coded)

### Tests
- [ ] Shift CRUD (Pest)
- [ ] Shift matching priority (employee > department > branch) (Pest)
- [ ] Night shift cross-midnight handling (Pest)
- [ ] Rotation pattern generation (Pest)
- [ ] Assignment with date range (Pest)
- [ ] Shift schedule endpoint (Pest)
- [ ] Shift form (Vitest)
- [ ] Roster view (Vitest)

### Exit Criteria
- Shifts configurable with all parameters
- Assignment to employee/department/branch
- Attendance engine matches correct shift
- Night shifts handled correctly
- Roster view shows schedule

---

## S14 — Web, Manual & CSV Attendance

### Backend
- [ ] Web attendance:
  - `POST /api/v1/attendance/web/check-in` — authenticated user, capture IP
  - `POST /api/v1/attendance/web/check-out` — authenticated user
  - Source: `web`, confidence: 75
- [ ] Manual attendance:
  - `POST /api/v1/attendance/manual` — HR enters for employee
  - Required: employee_id, check_in, check_out, reason
  - Source: `manual`, confidence: 60
  - Audit logged (who entered, when)
- [ ] CSV attendance import:
  - `POST /api/v1/attendance/import/preview` — validate CSV
  - `POST /api/v1/attendance/import/commit` — create records (idempotent)
  - CSV format: employee_code, date, check_in_time, check_out_time
  - Source: `csv`, confidence: 50
- [ ] Kiosk attendance:
  - `POST /api/v1/attendance/kiosk` — employee identifies via code/QR
  - Source: `kiosk`, confidence: 80

### Frontend
- [ ] Web check-in/out widget:
  - Large check-in button on employee dashboard
  - Shows current status (checked in since X, or not checked in)
  - Check-out button after check-in
  - Today's record summary below
- [ ] Manual attendance entry (HR view):
  - Employee selector (searchable dropdown)
  - Date, check-in time, check-out time inputs
  - Reason (required text field)
  - Submit -> confirmation
- [ ] CSV import page:
  - Upload CSV
  - Preview with validation
  - Commit with progress
- [ ] Kiosk mode page:
  - Fullscreen mode
  - Employee code input (numpad) or QR scanner
  - Large clock display
  - Check-in/out with employee photo confirmation

### Tests
- [ ] Web check-in/out (Pest)
- [ ] Manual entry by HR (Pest)
- [ ] Manual entry denied for non-HR (Pest)
- [ ] CSV import with valid/invalid rows (Pest)
- [ ] Kiosk attendance (Pest)
- [ ] Check-in widget (Vitest)
- [ ] Manual entry form (Vitest)

### Exit Criteria
- Employees can check in/out via web interface
- HR can enter manual attendance
- CSV bulk import works with validation
- Kiosk mode functional

---

## S15 — Biometric Device Framework + Hikvision

### Backend
- [ ] `devices` table migration (see DATABASE.md)
- [ ] `Device` model with `BelongsToTenant`
- [ ] `DeviceAdapter` interface (see ARCHITECTURE.md):
  - `connect()`, `pullEvents()`, `pushEventUrl()`, `getStatus()`, `getDeviceInfo()`
- [ ] `DeviceManager` service: registry of adapters by type
- [ ] `HikvisionAdapter`:
  - ISAPI HTTP protocol implementation
  - `pullEvents()` — GET `/ISAPI/AccessControl/AcsEvent/...` with pagination
  - Parse attendance events -> map employee (by card/fingerprint ID)
  - Error handling for network issues
- [ ] `DeviceController`:
  - CRUD for devices (register, configure, remove)
  - `GET /api/v1/devices/{id}/status` — query device health
  - `POST /api/v1/devices/{id}/pull` — manually trigger event pull
  - `POST /api/v1/devices/webhook/hikvision` — receive push events (ISAPI callback)
- [ ] Scheduled event pull: `PullDeviceEventsJob` runs every 5 minutes (per device)
- [ ] Device health monitoring: check heartbeat, mark offline if no response
- [ ] Map device events to employees (via badge number / fingerprint ID stored on employee record)
- [ ] Feed events into `AttendanceEngine` (source: `biometric`, confidence: 100 online / 95 offline)
- [ ] `DevicePolicy`: tenant_admin only

### Frontend
- [ ] Device management page:
  - Device list: name, type (Hikvision/ZKTeco), branch, IP, status badge (online/offline/error), last sync
  - Add device dialog: name, type selector, IP address, port, credentials, branch assignment
  - Device detail: connection status, last events, sync history
  - "Pull Events" button (manual trigger)
  - "Test Connection" button
- [ ] Device status indicators: real-time via Reverb (green online, red offline, yellow error)

### Tests
- [ ] Device CRUD (Pest)
- [ ] Hikvision adapter: mock ISAPI responses, verify event parsing (Pest)
- [ ] Event pull creates attendance records (Pest)
- [ ] Webhook endpoint receives push events (Pest)
- [ ] Device status check (Pest)
- [ ] Employee mapping (badge number -> employee) (Pest)
- [ ] Device management UI (Vitest)

### Exit Criteria
- Hikvision devices can be registered and configured
- Events pull successfully and create attendance records
- Push webhook receives and processes events
- Device health monitored (online/offline status)
- Events map to correct employees

---

## S16 — ZKTeco Adapter + Device Dashboard

### Backend
- [ ] `ZktecoAdapter` implementing `DeviceAdapter`:
  - Push protocol: device pushes to `POST /api/v1/devices/webhook/zkteco`
  - Pull protocol: polling device HTTP API for logs
  - Parse attendance events (punch type: check-in / check-out)
  - Map employee (by badge/finger ID)
- [ ] Device dashboard endpoint: `GET /api/v1/devices/dashboard`
  - Total devices, online count, offline count, error count
  - Events processed today
  - Last sync times
- [ ] Device alerts: dispatch `DeviceOffline` event after 10 minutes without heartbeat
- [ ] Device event log: store raw events for debugging

### Frontend
- [ ] ZKTeco device configuration in add device dialog
- [ ] Device dashboard:
  - Summary cards (total, online, offline, error)
  - Device grid/list with status indicators
  - Real-time updates via Reverb
  - Click device -> detail view with event log
- [ ] Alert banner when devices go offline

### Tests
- [ ] ZKTeco adapter: mock responses, verify parsing (Pest)
- [ ] ZKTeco push webhook (Pest)
- [ ] Device dashboard stats (Pest)
- [ ] Offline alert dispatch (Pest)
- [ ] Device dashboard UI (Vitest)

### Exit Criteria
- ZKTeco devices work alongside Hikvision
- Device dashboard shows real-time status
- Offline alerts fire for unresponsive devices

---

## S17 — Mobile & Offline Attendance

### Backend
- [ ] Mobile check-in: `POST /api/v1/attendance/mobile/check-in`
  - Input: GPS coordinates, geofence validation, optional selfie (Base64 or presigned upload)
  - Validates: geofence match against assigned branch
  - Source: `mobile`, confidence: calculated (90 with GPS, 95 with selfie, 88 without geofence)
- [ ] Mobile check-out: `POST /api/v1/attendance/mobile/check-out`
- [ ] QR attendance: `POST /api/v1/attendance/qr`
  - QR code generation: `GET /api/v1/attendance/qr/generate` (per shift, per branch, time-limited)
  - QR code validation: verify signature, expiry, branch
  - Source: `qr`, confidence: 85
- [ ] Offline batch sync: `POST /api/v1/attendance/sync`
  - Input: array of offline attendance records
  - Each record: employee_id, check_in/out, GPS, device_id, offline_token (HMAC), offline_hash
  - Validate HMAC signature per record
  - Run each through AttendanceEngine (idempotent)
  - Return: array of results (created, duplicate, conflict, error per record)
  - Source: `offline_mobile`, confidence: 88
- [ ] `ConflictResolver` service:
  - Same employee + same minute + different source -> merge (keep higher confidence)
  - Same employee + overlapping times -> flag for HR review
  - Offline arrives after payroll close -> flag for retroactive adjustment
- [ ] Geofence validation helper: calculate distance from coordinates to branch coordinates, compare to radius

### Frontend
- [ ] Mobile attendance page (responsive, designed for phone):
  - Large check-in/out button
  - GPS status indicator
  - Geofence status indicator (inside/outside)
  - Camera integration for selfie (optional)
  - Current status display
  - Today's attendance summary
- [ ] QR attendance scanner:
  - Camera-based QR code reader
  - Auto-submit on scan
  - Success/error feedback
- [ ] QR code generator (HR/admin):
  - Select branch + shift
  - Generate QR code (displayed or printable)
  - Expiry time setting
- [ ] Offline support (PWA):
  - Service worker registration
  - IndexedDB storage for attendance queue
  - Offline check-in/out: write to IndexedDB
  - Online indicator banner
  - Background sync: when online, push queued records to `/attendance/sync`
  - Sync status: pending count, last sync time
  - Conflict resolution UI: show conflicts for HR review
- [ ] Attendance sync status page:
  - Queued records count
  - Last sync timestamp
  - Sync errors (with retry button)

### Tests
- [ ] Mobile check-in with GPS + geofence (Pest)
- [ ] Geofence validation (inside/outside radius) (Pest)
- [ ] QR generation + validation (Pest)
- [ ] QR expiry enforcement (Pest)
- [ ] Offline batch sync (Pest)
- [ ] HMAC validation on offline records (Pest)
- [ ] Conflict resolution: merge + flag (Pest)
- [ ] Idempotency on sync retry (Pest)
- [ ] Mobile check-in button (Vitest)
- [ ] QR scanner component (Vitest)
- [ ] Offline queue indicator (Vitest)

### Exit Criteria
- Mobile attendance works with GPS + geofence + selfie
- QR codes generated per shift, expire correctly
- Offline check-in stored locally, syncs when online
- Conflict resolution handles cross-source duplicates
- PWA service worker caches for offline use

---

## S18 — Attendance Intelligence & Correction Workflow

### Backend
- [ ] `AttendanceIntelligence` service:
  - `detectLate(AttendanceRecord $record)` — compare vs shift start + grace
  - `detectEarlyLeave(AttendanceRecord $record)` — compare vs shift end
  - `calculateOvertime(AttendanceRecord $record)` — beyond shift end, respecting daily/weekly caps
  - `detectMissingPunch(Employee $employee, Carbon $date)` — check-in without check-out (or vice versa)
  - `detectAnomalies(AttendanceRecord $record)` — impossible travel, suspicious patterns
  - Run as listener on `AttendanceRecorded` event
- [ ] Missing punch alerts: daily job scans for incomplete records, notifies employee + supervisor
- [ ] Overtime summary: `GET /api/v1/attendance/overtime?period=monthly` (per employee, per department)
- [ ] `attendance_corrections` table migration
- [ ] `AttendanceCorrectionController`:
  - `POST /api/v1/attendance/corrections` — submit request (reason, corrected values, attachment)
  - `GET /api/v1/attendance/corrections` — list (filterable by status)
  - `GET /api/v1/attendance/corrections/pending` — pending for current approver
  - `PUT /api/v1/attendance/corrections/{id}/approve` — approve and advance to next approver
  - `PUT /api/v1/attendance/corrections/{id}/reject` — reject with reason
- [ ] Approval chain: employee -> supervisor -> dept head -> HR
  - Configurable chain (skip missing levels)
  - Track all approvers in `approved_by` JSON array
  - Final approval: apply correction to attendance record, mark `is_corrected = true`
  - Original values preserved, correction linked
- [ ] Payroll impact preview: before final approval, show how correction affects payroll
- [ ] Notification at each step of approval chain
- [ ] Audit logging for every correction action

### Frontend
- [ ] Attendance intelligence dashboard (HR view):
  - Late arrivals today (list with employee, shift start, actual time, minutes late)
  - Early departures today
  - Missing punches (employees who checked in but not out)
  - Overtime summary (top overtime employees this month)
  - Anomaly flags (if any)
- [ ] Correction request form:
  - Select attendance record (from "My Attendance" list)
  - "Request Correction" button
  - Form: what to correct (check-in time, check-out time, add missing), reason, attachment upload
  - Submit -> notification to approver
- [ ] Correction approval page (supervisor/HR view):
  - Pending corrections list
  - Detail view: original vs corrected values, reason, attachment, payroll impact
  - Approve / Reject buttons
  - Rejection requires reason
  - Approval chain progress indicator
- [ ] Correction history on attendance detail page

### Tests
- [ ] Late/early/overtime detection (Pest)
- [ ] Missing punch detection (Pest)
- [ ] Correction submission (Pest)
- [ ] Approval chain flow (submit -> approve -> approve -> applied) (Pest)
- [ ] Rejection flow (Pest)
- [ ] Payroll impact calculation (Pest)
- [ ] Notification dispatch at each step (Pest)
- [ ] Intelligence dashboard (Vitest)
- [ ] Correction form (Vitest)
- [ ] Approval UI (Vitest)

### Exit Criteria
- Late/early/OT/missing punch auto-detected
- Correction workflow works through approval chain
- Payroll impact previewed before approval
- Original records never modified (corrections linked)
- Notifications sent at each approval step
- Full audit trail

---

## Phase 3 Exit Criteria

- [ ] Unified attendance engine handles all sources (web, manual, CSV, biometric, mobile, offline, QR)
- [ ] Shift engine matches employees to shifts correctly
- [ ] Hikvision + ZKTeco devices integrated
- [ ] Offline attendance stores locally and syncs with conflict resolution
- [ ] Mobile attendance with GPS + geofence + selfie
- [ ] QR attendance with time-limited codes
- [ ] Intelligence detects late/early/OT/missing/anomalies
- [ ] Correction workflow with configurable approval chain
- [ ] Device dashboard with real-time status
- [ ] Confidence scoring applied to all records
- [ ] All Pest + Vitest tests passing
- [ ] Browser verified: desktop, tablet, mobile, dark mode
