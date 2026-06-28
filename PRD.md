# ETHR v1.0 — Product Requirements Document

## Product Position

> **ETHR — Ethiopian Workforce Operating System**
>
> Enterprise-grade, multi-tenant, offline-first Human Capital Management SaaS
> designed specifically for Ethiopian organizations.

**v1.0 Goal:** Replace Excel, BioTime, Hikvision standalone software, and manual HR processes
with a secure, offline-capable, multi-tenant platform that unifies attendance, HR, leave, payroll,
and executive reporting.

**Release type:** Minimum Lovable Enterprise Product (MLEP) — complete, reliable, enterprise-ready.

---

## Target Users

| Role | Needs |
|---|---|
| **Super Admin** | Platform operations, tenant management, revenue |
| **Tenant Admin** | Organization setup, system configuration, user management |
| **HR Admin** | Employee lifecycle, documents, reports |
| **Finance Admin** | Payroll processing, tax, pension, bank exports |
| **Department Head** | Team oversight, approvals, reports |
| **Supervisor** | Direct reports, attendance monitoring, approvals |
| **Employee** | Self-service: attendance, leave, payslips, profile |

---

## Target Organizations

| Type | Size | Examples |
|---|---|---|
| Government offices | 50-5000 | Ministries, regional bureaus, woredas |
| Banks & financial | 100-10000 | Private banks, MFIs, insurance |
| Hospitals & health | 50-3000 | Public/private hospitals, clinics |
| Manufacturing | 100-5000 | Factories, industrial parks |
| NGOs | 20-1000 | International and local NGOs |
| Hotels & hospitality | 50-500 | Hotel chains, resorts |
| Education | 50-2000 | Universities, schools |
| Private companies | 10-1000 | Tech, retail, services |
| Construction | 50-2000 | Contractors, real estate |
| Agriculture | 50-5000 | Commercial farms, agri-business |

---

## 1. Platform Foundation

### 1.1 Multi-Tenant SaaS
- Complete tenant isolation (data, files, encryption keys, backups)
- Automatic tenant provisioning on signup
- Custom subdomains (`company.ethr.et`)
- Custom domain support (CNAME)
- Tenant branding (logo, colors, theme)
- Tenant lifecycle: trial (6 months) -> active -> suspended -> cancelled
- Tenant backup and restore
- Tenant-scoped audit logs

### 1.2 Authentication & Security
- Email/password login
- Phone/OTP login (Ethiopian phone format)
- Multi-factor authentication (TOTP)
- Password policy (configurable strength)
- Session management (active sessions list, revoke)
- Device management (trusted devices)
- API tokens (Sanctum, scoped, expirable)
- Login history with IP, device, location
- IP restriction (optional, per-tenant)
- SSO-ready architecture (defer implementation to v2.0)

### 1.3 Role-Based Access Control
- Roles: super_admin, tenant_admin, hr_admin, finance_admin, dept_admin, supervisor, employee
- Fine-grained permissions (configurable per role)
- Permission groups by module
- Custom role creation by tenant admin
- Role hierarchy for approval chains

### 1.4 Organization Structure
- Headquarters
- Branches (multi-location)
- Departments (hierarchical)
- Teams
- Positions / Job titles
- Grades / Levels
- Cost centers
- Reporting hierarchy (org chart)
- Approval hierarchy (configurable chains)

---

## 2. Public Website & Marketing

### 2.1 Marketing Pages
- Professional landing page
- Product overview with feature highlights
- Industry-specific pages (government, banking, hospital, manufacturing)
- Pricing page (tiered plans)
- Feature comparison table
- Interactive product tour
- FAQ section
- Contact sales form
- Book a demo scheduler
- Status page (system health)
- Security & compliance page

### 2.2 Self-Service Sales
- Free trial signup (6 months, no credit card)
- Instant tenant creation (< 30 seconds)
- Email verification
- Guided onboarding (setup wizard)
- Subscription upgrade flow
- Plan comparison

---

## 3. Organization Templates (NEW)

Pre-built configurations for common Ethiopian organization types:

| Template | Pre-configured |
|---|---|
| Government Office | Departments (HR, Finance, Admin, Legal, IT), positions, grade structure, leave policies, shift patterns |
| Private Bank | Branch structure, departments (Operations, Credit, IT, HR), banking-specific positions, shift patterns |
| Hospital | Departments (Medical, Nursing, Admin, Lab, Pharmacy), shift rotations (day/night/on-call), overtime rules |
| Manufacturing | Production departments, shift patterns (3-shift), overtime rules, attendance strictness |
| NGO | Project-based structure, flexible leave, expatriate allowances |
| Hotel | Front office/housekeeping/kitchen departments, rotating shifts, tip handling |
| University | Faculty/admin split, academic calendar awareness, research staff handling |
| General Company | Standard departments, 8-5 schedule, basic leave policies |

Each template provides:
- Department structure
- Default positions and grades
- Shift definitions
- Leave type configuration
- Payroll rule defaults
- Attendance policies
- Approval workflows

Tenant admin can customize after applying a template.

---

## 4. Setup Wizard (NEW)

First-run onboarding wizard for new tenants:

```
Step 1: Organization Profile
  - Name, type, size, industry
  - Logo upload
  - Select organization template

Step 2: Organization Structure
  - Review/edit departments from template
  - Add branches (if multi-location)
  - Configure reporting hierarchy

Step 3: Work Schedule
  - Define shifts (or accept template defaults)
  - Set working days
  - Configure attendance rules

Step 4: Leave Policies
  - Review/edit leave types from template
  - Set accrual rules
  - Configure approval chain

Step 5: Payroll Configuration
  - Basic salary structure
  - Allowance types
  - Tax configuration (Ethiopian defaults)
  - Pension settings

Step 6: Employee Import
  - CSV upload with preview
  - Template download
  - Field mapping
  - Validation + commit

Step 7: Review & Launch
  - Summary of all settings
  - Invite team members
  - Launch dashboard
```

Progress saved at each step. Can be resumed later. Skip-ahead allowed for experienced admins.

---

## 5. Localization

### 5.1 Languages (v1.0)
- English (primary)
- Amharic (አማርኛ)
- Architecture supports: Afaan Oromoo, Tigrinya (ትግርኛ), Soomaali, Sidaamu Afoo

### 5.2 Ethiopian Localization
- Ethiopian Calendar (ዓ.ም.) — automatic conversion
- Gregorian Calendar — always available
- Dual calendar display (admin toggle per tenant)
- Ethiopian public holidays — auto-detected from Ethiopian calendar
- Configurable regional holidays
- Ethiopian Birr (ETB) formatting
- Ethiopian phone format (+251-XX-XXX-XXXX)
- Ethiopian address format
- Ethiopian payroll terminology

---

## 6. Employee Management

### 6.1 Employee Profile
- Personal information (name, DOB, gender, nationality, marital status, religion)
- Employment information (hire date, employee code, department, position, grade, status)
- Organization assignment (branch, department, team, reporting to)
- Documents (contracts, certificates, ID copies)
- Photos (profile picture)
- Emergency contacts
- Education history
- Work experience
- Skills & certifications
- Bank details (for payroll)
- Tax information (TIN)
- History timeline (all changes)

### 6.2 Employee Lifecycle (State Machine)
```
Hired -> Probation -> Confirmed -> [Active]
                                      |
                        +-------------+-------------+
                        |             |             |
                   Transfer      Promotion     Suspension
                        |             |             |
                        +-------------+-------------+
                                      |
                        +-------------+-------------+
                        |             |             |
                   Resignation   Retirement   Termination
                        |             |             |
                        +-------> [Exited] <-------+
                                      |
                                   Rehire -> Hired
```

Every transition:
- Requires authorization (role-based)
- Captures reason and effective date
- Creates audit trail entry
- Triggers notification to relevant parties
- Updates payroll status

### 6.3 Import Methods
- CSV upload with field mapping and preview
- Template download (pre-formatted CSV)
- Individual manual entry
- Bulk edit (select + batch update)

---

## 7. Attendance Platform (FLAGSHIP)

This is the core differentiator and largest competitive advantage.

### 7.1 Attendance Sources (Priority Order)
1. **Biometric devices** — Hikvision, ZKTeco (v1.0); Suprema, others via adapter (v2.0)
2. **Mobile attendance** — GPS + geofence + optional selfie
3. **Offline mobile attendance** — local encrypted storage, auto-sync
4. **QR attendance** — generated per-shift, location-bound
5. **Web attendance** — browser-based clock in/out
6. **Tablet/shared kiosk** — shared device mode with employee identification
7. **Manual entry** — HR enters on behalf of employee
8. **CSV import** — bulk historical import
9. **REST API** — third-party system integration

### 7.2 Attendance Engine
- Unified attendance record regardless of source
- Confidence scoring per record (100% biometric online -> 60% manual entry)
- Configurable approval threshold below confidence score
- Duplicate detection and merge (cross-source)
- Missing punch detection
- Attendance timeline view per employee
- Real-time attendance dashboard

### 7.3 Shift Engine
- Shift definitions (start, end, break, grace period)
- Shift types: fixed, rotating, flexible, split
- Rotation patterns (weekly, bi-weekly, monthly)
- Shift assignment (individual, department, branch)
- Auto-detection of shift from punch time
- Night shift handling (cross-midnight)
- Holiday shift rules

### 7.4 Biometric Integration
- Device registration and configuration
- Device health monitoring (online/offline/error)
- Event pull (scheduled polling)
- Event push (real-time webhook from device)
- Employee-device mapping (fingerprint/face enrollment tracking)
- Adapter pattern: `DeviceAdapter` interface per manufacturer
- v1.0 adapters: Hikvision (ISAPI), ZKTeco (Push/Pull)

### 7.5 Offline Attendance Architecture
```
Employee Phone
     |
Internet available?
  |          |
 YES        NO
  |          |
Online    Offline Mode
Sync         |
          Local encrypted DB (IndexedDB)
             |
          Attendance record:
            - Employee ID, Tenant ID, Branch ID
            - Check-in/out timestamps
            - GPS coordinates
            - Geofence validation result
            - Device ID + app signature
            - Offline token (signed)
            - Optional selfie (compressed)
            - Battery / WiFi SSID / BLE beacon (optional)
            - Offline hash (integrity)
             |
          Queued locally
             |
          Internet returns
             |
          Auto-sync (background, batch upload)
             |
          Server: conflict detection + duplicate merge
             |
          Attendance engine processes
             |
          Audit log preserves everything
```

### 7.6 Attendance Intelligence
- Late arrival detection (vs shift start + grace)
- Early departure detection
- Overtime calculation (daily, weekly, monthly caps)
- Missing punch detection + alert
- Pattern analysis (habitual lateness, absenteeism trends)
- Anomaly detection (impossible locations, duplicate devices)
- Fraud indicators (jailbroken device, GPS spoofing signals)

### 7.7 Attendance Correction Workflow
```
Employee submits correction request (reason + optional attachment)
  -> Immediate Supervisor (approve/reject/escalate)
    -> Department Head (approve/reject)
      -> HR (final approve/reject)
        -> Audit log (immutable record)
          -> Payroll recalculation (if in open period)
```

- Original record preserved (never overwritten)
- Correction creates new record linked to original
- Payroll impact preview before approval
- Configurable chain (skip levels if not applicable)
- Notification at each step

---

## 8. Leave Management

### 8.1 Leave Types
| Type | Default Days | Accrual | Carry-forward |
|---|---|---|---|
| Annual | 16 (grows with tenure) | Monthly | Up to 5 days |
| Sick | 6 | Available immediately | No |
| Maternity | 90 (30 pre + 60 post) | One-time | No |
| Paternity | 5 | One-time | No |
| Emergency | 3 | Available immediately | No |
| Study | Configurable | Per-request | No |
| Unpaid | Unlimited | N/A | N/A |
| Compensatory | Earned per OT | On overtime | Configurable |
| Custom | Configurable | Configurable | Configurable |

All values configurable per tenant.

### 8.2 Leave Features
- Configurable accrual rules (monthly, annual, custom)
- Carry-forward with configurable limits
- Leave balance dashboard (employee + manager view)
- Team leave calendar (who's off when)
- Holiday-aware leave calculation (skip public holidays)
- Approval workflow (configurable chain)
- Leave encashment (optional, configurable)
- Leave history with audit trail
- Leave forecasting (projected balance)
- Conflict detection (team minimum staffing)

---

## 9. Payroll (Ethiopian Rules Engine)

### 9.1 Payroll Pipeline
```
Attendance data (confirmed)
  -> Shift assignment
    -> Leave deductions
      -> Overtime calculation
        -> Allowances (position, transport, housing, custom)
          -> Bonuses
            -> Loans / advances (deduction)
              -> Ethiopian income tax calculation
                -> Pension contribution (employer + employee)
                  -> Other deductions
                    -> Net salary
                      -> Payslip generation (PDF)
                        -> Bank export file
                          -> Accounting journal entry
```

### 9.2 Rules Engine
- All formulas configurable (not hardcoded)
- Ethiopian income tax brackets (auto-updated)
- Pension: 7% employee + 11% employer (configurable)
- Overtime rates: 1.25x (normal), 1.5x (night), 2.0x (holiday), 2.5x (holiday night)
- Proration for mid-month changes
- Retroactive adjustments
- Multi-currency support architecture (ETB primary)

### 9.3 Outputs
- Employee payslip (PDF, bilingual EN/AM)
- Payroll summary report
- Department cost breakdown
- Bank transfer file (Ethiopian bank formats)
- Tax report (per-employee, aggregate)
- Pension report
- Accounting journal export

---

## 10. Employee Self-Service Portal

Employees can:
- View personal attendance records and timeline
- Submit attendance correction requests
- Apply for leave (with balance visibility)
- View leave balances and history
- View and download payslips
- Update personal details (requires approval)
- View and download documents
- Receive and read announcements
- Track pending approvals
- View organization directory (name, photo, department, position)

---

## 11. Manager Portal

Managers can:
- Approve/reject leave requests
- Approve/reject attendance corrections
- View team attendance (real-time + historical)
- View team leave calendar
- Monitor overtime usage
- Receive alerts (anomalies, pending approvals)
- View team KPIs (attendance rate, leave usage)
- Export team reports

---

## 12. Executive Dashboard

Executives see:
- Organization health score (composite KPI)
- Attendance trends (daily, weekly, monthly)
- Absenteeism rates by department/branch
- Payroll cost trends
- Overtime cost analysis
- Department comparison (performance, cost, headcount)
- Branch comparison
- Workforce growth / turnover
- Leave utilization rates
- Headcount by department, position, grade

---

## 13. Reporting Engine

- Dynamic report builder (drag-and-drop fields)
- Saved report templates
- Scheduled report delivery (daily, weekly, monthly via email)
- Export: PDF, Excel, CSV
- Dashboard widgets (charts, KPIs)
- Drill-down from summary to detail
- Date range filtering with Ethiopian calendar support
- Department/branch/employee filtering

---

## 14. Notification Center

### Channels
| Channel | Use Case |
|---|---|
| In-app (real-time) | All notifications, Reverb WebSocket |
| Email | Leave approvals, payroll ready, announcements |
| SMS (adapter) | Critical alerts, OTP |
| Push (PWA) | Attendance reminders, approval requests |

### Events
- Leave request submitted / approved / rejected
- Attendance anomaly detected
- Attendance correction requested / approved
- Payroll processed / payslip available
- Announcement published
- Document shared
- Approval pending (escalation after 48h)
- Device offline alert
- Trial expiration warning

---

## 15. Integration Hub

- REST API (full CRUD for all modules, OpenAPI documented)
- Webhooks (configurable events, HMAC-SHA256 signed, retry with exponential backoff)
- Biometric device adapters (Hikvision ISAPI, ZKTeco Push/Pull)
- CSV import/export (all modules)
- Bank export (Ethiopian bank transfer formats)
- Accounting export (journal entries, compatible with common accounting software)
- ERP integration framework (adapter pattern, v2.0 implementation)

---

## 16. Administration

### Role Hierarchy
```
Platform Super Admin
  -> Tenant Admin
    -> HQ Admin
      -> Branch Admin
        -> HR Admin / Finance Admin
          -> Department Head
            -> Supervisor
              -> Employee
```

### Tenant Admin Capabilities
- Organization settings
- User management (invite, deactivate, role assignment)
- Branch management
- Department management
- Shift configuration
- Leave policy configuration
- Payroll rule configuration
- Attendance device management
- Holiday management
- Localization settings (language, calendar, timezone)
- Notification templates
- Data import/export
- Audit log viewer

### Super Admin Capabilities
- Tenant listing and search
- Tenant lifecycle management (activate, suspend, delete)
- Subscription and billing management
- Revenue dashboard
- Feature flags (global + per-tenant)
- System health monitoring
- Platform-wide audit logs

---

## 17. Offline-First (v1.0 Scope)

v1.0 offline support is focused on attendance:

| Feature | Offline Support |
|---|---|
| Attendance check-in/out | Full offline with auto-sync |
| Employee directory lookup | Cached for offline viewing |
| Leave request draft | Draft saved locally, synced when online |
| Announcements | Cached for offline reading |
| All other features | Online required |

Offline architecture:
- PWA with service worker for static assets
- IndexedDB for attendance queue and employee cache
- Background sync API for automatic retry
- Conflict detection on server (timestamp + source priority)
- Full audit trail for offline records (device, GPS, timestamp, sync status)

---

## 18. Enterprise Quality Requirements

- Mobile-first responsive design (375px -> 1440px+)
- WCAG 2.1 AA accessibility target
- Error handling with user-friendly messages (localized)
- Retry and recovery for network failures
- Optimistic UI for common actions (leave apply, attendance)
- Loading states (skeleton screens, not spinners)
- Empty states with helpful guidance
- Background job processing (Horizon)
- Full audit logging
- Performance monitoring
- Security hardening (OWASP top 10)

---

## Features Deferred to v2.0

- Recruitment / Applicant Tracking (ATS)
- Performance Management / OKRs
- Learning Management (LMS)
- Training & Development
- Asset Management
- Visitor Management
- Contractor Management
- Fleet Management
- Procurement
- Inventory
- CRM
- Project Management
- AI predictive analytics / AI assistant
- Advanced workflow automation
- Full ERP suite
- SSO implementation (SAML, OIDC)
- Flutter mobile apps
- Gamification
- Employee engagement surveys

---

## Success Metrics (v1.0 Launch)

| Metric | Target |
|---|---|
| Tenant onboarding time | < 10 minutes (with template) |
| Attendance recording (any source) | < 3 seconds |
| Offline attendance sync | < 30 seconds after reconnect |
| Payroll processing (500 employees) | < 2 minutes |
| Page load (any page) | < 2 seconds |
| System uptime | 99.5% |
| Data loss | Zero (offline + sync guarantee) |
| Concurrent tenants | 100+ |
| Employees per tenant | Up to 10,000 |
