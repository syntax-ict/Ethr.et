# ETHR — Database Schema (v2.0)

## Conventions

- **Engine:** InnoDB (all tables)
- **Charset:** `utf8mb4_unicode_ci` (Amharic + emoji support)
- **Primary keys:** `BIGINT UNSIGNED AUTO_INCREMENT` (internal, never exposed via API)
- **Public IDs:** `CHAR(26) public_id` UNIQUE (ULID, API-facing)
- **Tenant scoping:** `BIGINT UNSIGNED tenant_id` FOREIGN KEY on all scoped tables
- **Timestamps:** `created_at`, `updated_at` (UTC, TIMESTAMP)
- **Soft deletes:** `deleted_at TIMESTAMP NULL` per CLAUDE.md policy
- **Currency:** `BIGINT` minor units (cents). Column suffix: `_cents`
- **Encrypted:** Marked 🔒 — stored as `TEXT` (AES-256 ciphertext)

---

## Platform (No Tenant Scope)

### tenants
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | Internal only |
| public_id | CHAR(26) UNIQUE | ULID |
| name | VARCHAR(255) | |
| slug | VARCHAR(63) UNIQUE | Subdomain |
| type | VARCHAR(50) | government, bank, hospital, etc. |
| status | ENUM(trial, active, suspended, cancelled) | |
| trial_ends_at | TIMESTAMP NULL | |
| logo_path | VARCHAR(500) NULL | MinIO path |
| primary_color | CHAR(7) NULL | Hex |
| secondary_color | CHAR(7) NULL | Hex |
| settings | JSON NULL | Consolidated tenant settings |
| schema_version | INT UNSIGNED DEFAULT 1 | Migration tracking |
| created_at, updated_at, deleted_at | TIMESTAMP | |

**Indexes:** slug, status

### plans
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| name | VARCHAR(100) | |
| slug | VARCHAR(100) UNIQUE | |
| description | TEXT NULL | |
| base_price_cents | BIGINT | |
| included_seats | INT UNSIGNED | |
| additional_seat_price_cents | BIGINT | |
| features | JSON | Max employees, devices, storage |
| is_active | BOOLEAN | |
| sort_order | INT UNSIGNED | |

### feature_flags
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| tenant_id | BIGINT NULL FK→tenants | NULL = global |
| key | VARCHAR(100) | |
| value | BOOLEAN | |

**Unique:** (tenant_id, key)

---

## Authentication & Authorization

### users
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT FK→tenants | |
| employee_id | BIGINT NULL FK→employees | |
| email | VARCHAR(255) | |
| phone | VARCHAR(20) NULL | |
| password | VARCHAR(255) | bcrypt |
| mfa_enabled | BOOLEAN | |
| mfa_secret | TEXT NULL | 🔒 TOTP secret |
| is_active | BOOLEAN | |
| locale | VARCHAR(5) DEFAULT 'en' | |
| last_login_at | TIMESTAMP NULL | |
| lockout_until | TIMESTAMP NULL | |
| failed_login_count | INT UNSIGNED DEFAULT 0 | |
| email_verified_at | TIMESTAMP NULL | |

**Unique:** (tenant_id, email)

### permissions
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| name | VARCHAR(100) UNIQUE | e.g. employees.create |
| module | VARCHAR(50) | e.g. employees |
| action | VARCHAR(50) | e.g. create |
| description | VARCHAR(255) NULL | |

### roles
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT FK→tenants | |
| name | VARCHAR(100) | |
| slug | VARCHAR(100) | |
| is_system | BOOLEAN | System roles can't be deleted |

**Unique:** (tenant_id, slug)

### role_permissions
| Column | Type |
|---|---|
| role_id | BIGINT FK→roles |
| permission_id | BIGINT FK→permissions |

**PK:** (role_id, permission_id)

### user_roles
| Column | Type |
|---|---|
| user_id | BIGINT FK→users |
| role_id | BIGINT FK→roles |

**PK:** (user_id, role_id)

### otp_codes
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| user_id | BIGINT FK→users | |
| code_hash | VARCHAR(255) | Argon2id |
| type | ENUM(login, email_verify, password_reset) | |
| expires_at | TIMESTAMP | 5-min expiry |
| used_at | TIMESTAMP NULL | Single use |

### trusted_devices
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| user_id | BIGINT FK→users | |
| device_fingerprint | VARCHAR(255) | |
| device_name | VARCHAR(255) NULL | |
| expires_at | TIMESTAMP | 30-day expiry |

### login_histories
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| tenant_id | BIGINT FK | |
| user_id | BIGINT FK→users | |
| ip_address | VARCHAR(45) | |
| user_agent | VARCHAR(500) NULL | |
| status | ENUM(success, failed, locked, mfa_required) | |
| location | VARCHAR(255) NULL | Approximate |

### api_keys
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT FK | |
| name | VARCHAR(100) | |
| token_id | BIGINT FK→personal_access_tokens | |
| abilities | JSON | Scoped permissions |
| rate_limit | INT UNSIGNED DEFAULT 60 | req/min |
| expires_at | TIMESTAMP NULL | |
| last_used_at | TIMESTAMP NULL | |
| is_active | BOOLEAN | |

### api_key_logs
| Column | Type |
|---|---|
| id | BIGINT PK |
| api_key_id | BIGINT FK→api_keys |
| method | VARCHAR(10) |
| path | VARCHAR(500) |
| status_code | SMALLINT |
| response_time_ms | INT UNSIGNED |
| created_at | TIMESTAMP |

**Retention:** 30 days, then purged.

---

## Audit

### audit_log
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| tenant_id | BIGINT FK | |
| user_id | BIGINT NULL | |
| impersonated_by | BIGINT NULL | Super admin ID if impersonating |
| action | VARCHAR(100) | e.g. employee.created |
| entity_type | VARCHAR(100) NULL | Model class |
| entity_id | BIGINT NULL | |
| entity_public_id | CHAR(26) NULL | |
| before | JSON NULL | State before change |
| after | JSON NULL | State after change |
| metadata | JSON NULL | |
| ip_address | VARCHAR(45) NULL | |

**APPEND ONLY: no UPDATE or DELETE allowed.**

**Indexes:** (tenant_id, created_at), (tenant_id, entity_type, entity_id), (tenant_id, action)

---

## Billing

### subscriptions
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT UNIQUE FK | One per tenant |
| plan_id | BIGINT FK→plans | |
| status | ENUM(trial, active, past_due, suspended, cancelled) | |
| current_period_start | DATE | |
| current_period_end | DATE | |
| seat_count | INT UNSIGNED | |
| monthly_amount_cents | BIGINT | |

### invoices
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT FK | |
| subscription_id | BIGINT FK | |
| invoice_number | VARCHAR(50) | |
| status | ENUM(draft, sent, paid, overdue, cancelled) | |
| subtotal_cents | BIGINT | |
| total_cents | BIGINT | |
| due_date | DATE | |
| paid_at | TIMESTAMP NULL | |
| payment_reference | VARCHAR(255) NULL | |
| line_items | JSON | |
| pdf_path | VARCHAR(500) NULL | |

---

## Organization

### branches
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT FK | |
| name, name_am | VARCHAR(255) | |
| code | VARCHAR(20) UNIQUE(tenant) | |
| is_headquarters | BOOLEAN | One per tenant |
| region, city, subcity, woreda, kebele | VARCHAR(100) | Ethiopian address |
| phone, email | VARCHAR | |
| latitude | DECIMAL(10,7) NULL | Geofence |
| longitude | DECIMAL(10,7) NULL | Geofence |
| radius_meters | INT DEFAULT 200 | Geofence |
| is_active | BOOLEAN | |
| deleted_at | TIMESTAMP NULL | Soft delete |

### departments
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT FK | |
| parent_id | BIGINT NULL FK→departments | Hierarchy (max 5 levels) |
| branch_id | BIGINT NULL FK→branches | |
| head_employee_id | BIGINT NULL FK→employees | |
| name, name_am | VARCHAR(255) | |
| code | VARCHAR(20) UNIQUE(tenant) | |
| level | TINYINT DEFAULT 0 | Depth |
| sort_order | INT | |
| is_active, deleted_at | | Soft delete |

### teams
| Column | Type |
|---|---|
| id | BIGINT PK |
| public_id | CHAR(26) UNIQUE |
| tenant_id | BIGINT FK |
| department_id | BIGINT FK→departments |
| name, name_am | VARCHAR(255) |
| lead_employee_id | BIGINT NULL FK→employees |
| is_active, deleted_at | Soft delete |

### positions
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT FK | |
| name, name_am | VARCHAR(255) | |
| code | VARCHAR(20) UNIQUE(tenant) | |
| min_salary_cents | BIGINT NULL | |
| max_salary_cents | BIGINT NULL | |
| is_active, deleted_at | | Soft delete |

### grades
| Column | Type |
|---|---|
| id | BIGINT PK |
| public_id | CHAR(26) UNIQUE |
| tenant_id | BIGINT FK |
| name, name_am | VARCHAR(100) |
| level | INT UNIQUE(tenant) |
| is_active, deleted_at | Soft delete |

### cost_centers
| Column | Type |
|---|---|
| id | BIGINT PK |
| public_id | CHAR(26) UNIQUE |
| tenant_id | BIGINT FK |
| name, name_am | VARCHAR(255) |
| code | VARCHAR(20) UNIQUE(tenant) |
| is_active, deleted_at | Soft delete |

---

## Employees

### employees
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT FK | |
| user_id | BIGINT NULL FK→users | |
| first_name, last_name | VARCHAR(100) | |
| first_name_am, last_name_am | VARCHAR(100) NULL | Amharic |
| email, phone | VARCHAR | |
| date_of_birth | DATE NULL | |
| gender | ENUM(male, female) NULL | |
| nationality | VARCHAR(100) DEFAULT 'Ethiopian' | |
| marital_status | ENUM | |
| religion | VARCHAR(50) NULL | |
| photo_path | VARCHAR(500) NULL | |
| region, city, subcity, woreda, kebele | VARCHAR(100) | Ethiopian address |
| employee_code | VARCHAR(20) UNIQUE(tenant) | |
| status | ENUM(draft, probation, active, suspended, transferred, departed) | |
| hire_date | DATE | |
| probation_end_date, confirmation_date, termination_date | DATE NULL | |
| departure_reason | ENUM NULL | |
| department_id, branch_id, position_id, grade_id, team_id, cost_center_id | BIGINT NULL FK | |
| supervisor_id | BIGINT NULL FK→employees | Self-ref |
| basic_salary_cents | BIGINT DEFAULT 0 | |
| bank_name, bank_branch | VARCHAR(100) NULL | |
| bank_account_number | TEXT NULL | 🔒 AES-256 |
| tin | TEXT NULL | 🔒 AES-256 |
| search_text | TEXT NULL | Concatenated searchable fields |
| deleted_at | TIMESTAMP NULL | Soft delete |

**FULLTEXT INDEX:** (first_name, last_name, first_name_am, last_name_am, employee_code, email)

### employee_transitions
| Column | Type | Notes |
|---|---|---|
| employee_id | BIGINT FK | |
| from_status, to_status | VARCHAR(20) | |
| reason | TEXT | |
| effective_date | DATE | |
| metadata | JSON NULL | |
| transitioned_by | BIGINT FK→users | |

**IMMUTABLE: no UPDATE or DELETE.**

### employee_emergency_contacts, employee_education, employee_documents, profile_update_requests
Standard CRUD tables with tenant_id, employee_id FK. See full column details in ARCHITECTURE.md.

---

## Attendance

### shifts
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| tenant_id | BIGINT FK | |
| name, name_am | VARCHAR(100) | |
| start_time, end_time | TIME | |
| break_minutes | INT DEFAULT 60 | |
| grace_period_minutes | INT DEFAULT 15 | |
| is_overnight | BOOLEAN | |
| is_active | BOOLEAN DEFAULT TRUE | **Factories must set TRUE** |
| is_default | BOOLEAN DEFAULT FALSE | |
| working_days | JSON DEFAULT '[1,2,3,4,5,6]' | 0=Sun |
| deleted_at | TIMESTAMP NULL | Soft delete |

### shift_assignments
Polymorphic: assigns shift to Employee, Department, or Branch with date range.

### attendance_records
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id, employee_id | BIGINT FK | |
| date | DATE | |
| check_in, check_out | TIMESTAMP NULL | UTC |
| worked_minutes, overtime_minutes | INT UNSIGNED | |
| status | ENUM(present, late, early_departure, absent, half_day, holiday, leave, pending) | |
| source | ENUM(biometric, mobile, qr, kiosk, web, manual, csv, api, offline) | |
| confidence_score | TINYINT 0-100 | |
| shift_id, device_id | BIGINT NULL FK | |
| gps_latitude, gps_longitude | DECIMAL(10,7) NULL | |
| geofence_matched | BOOLEAN NULL | |
| selfie_path | VARCHAR(500) NULL | EXIF stripped |
| idempotency_key | VARCHAR(255) UNIQUE NULL | |
| offline_token | VARCHAR(500) NULL | HMAC |

**NEVER DELETE attendance records.**

**Indexes:** (tenant_id, employee_id, date), (tenant_id, date), idempotency_key

### attendance_corrections
4-step approval chain: pending_supervisor → pending_dept_head → pending_hr → approved/rejected. Includes original and corrected timestamps, reason, attachment, approval chain JSON.

### attendance_conflicts
Formal conflict resolution tracking: record_a_id, record_b_id, conflict_type, resolution status.

### attendance_settings
Per-tenant method configuration: method name, is_enabled, config JSON. Unique (tenant_id, method).

### devices
Biometric device management: type (hikvision/zkteco/suprema), connection details (IP, port, credentials 🔒), status (online/offline/error), last sync. Soft delete.

### device_sync_logs
Sync history per device: type (pull/push/webhook), records received/processed/failed, timing.

### kiosk_sessions
Token-authenticated kiosk sessions: branch, token_hash, PIN required flag, expiry.

---

## Leave

### leave_types
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| tenant_id | BIGINT FK | |
| name, name_am | VARCHAR(100) | |
| code | VARCHAR(20) UNIQUE(tenant) | |
| days_entitled | DECIMAL(5,1) | Supports half days |
| accrual_method | ENUM(none, monthly, quarterly, annual) | |
| max_carry_forward | DECIMAL(5,1) | |
| max_consecutive_days | INT NULL | |
| min_notice_days | INT DEFAULT 0 | |
| gender_restriction | ENUM(male, female) NULL | Maternity/paternity |
| requires_attachment | BOOLEAN | |
| is_paid | BOOLEAN | |
| color | CHAR(7) NULL | Calendar display |
| deleted_at | | Soft delete |

### leave_balances
Per employee per type per fiscal year: entitled, carried, used, pending, adjusted days. All DECIMAL(5,1) for half-day precision.

**Unique:** (tenant_id, employee_id, leave_type_id, fiscal_year)

### leave_requests
Leave request with approval chain: dates, calculated days, status (pending/approved/rejected/cancelled), approval chain JSON, idempotency key.

### holidays
| Column | Type | Notes |
|---|---|---|
| tenant_id | BIGINT FK | |
| name, name_am | VARCHAR(255) | |
| date | DATE | |
| type | ENUM(national, religious, regional, custom) | |
| is_recurring | BOOLEAN | Same date every year |
| branch_scope | JSON NULL | NULL = all branches |
| deleted_at | | Soft delete |

---

## Payroll

### tax_brackets
Configurable Ethiopian income tax: min_amount_cents, max_amount_cents (NULL=no limit), rate_percentage, deduction_cents.

### payroll_rules
Category-based rules: pension, overtime, allowance, deduction. Formula stored as JSON.

### employee_loans
Loan tracking: amount, monthly deduction, total paid, remaining balance, status (active/paused/completed).

### payroll_runs
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UNIQUE | |
| tenant_id | BIGINT FK | |
| period_start, period_end | DATE | |
| period_key | VARCHAR(50) UNIQUE | tenant:YYYY:MM — idempotency |
| status | ENUM(processing, draft, approved, voided) | |
| employee_count | INT | |
| total_gross_cents, total_tax_cents, total_pension_employee_cents, total_pension_employer_cents, total_deductions_cents, total_net_cents | BIGINT | All integer cents |
| processed_by, approved_by, voided_by | BIGINT FK→users | |
| voided_run_id | BIGINT NULL FK→payroll_runs | Links reprocessed to voided |
| void_reason | TEXT NULL | |

**NEVER DELETE payroll runs.**

### payroll_entries
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| payroll_run_id | BIGINT FK | |
| employee_id | BIGINT FK | |
| basic_salary_cents, overtime_cents, allowances_cents, gross_salary_cents | BIGINT | Earnings |
| income_tax_cents, pension_employee_cents, pension_employer_cents, loan_deduction_cents, other_deductions_cents, total_deductions_cents | BIGINT | Deductions |
| net_salary_cents | BIGINT | Net |
| **calculation_log** | **JSON NOT NULL** | **MANDATORY audit trail — immutable after approval** |
| payslip_pdf_path | VARCHAR(500) NULL | MinIO path |
| worked_days, leave_days | INT / DECIMAL | |
| ot_normal_minutes, ot_night_minutes, ot_holiday_minutes, ot_holiday_night_minutes | INT | |

**NEVER DELETE payroll entries.**

**Unique:** (payroll_run_id, employee_id)

---

## Notifications & Communication

### notifications
Laravel default notification table: type, notifiable, data JSON, read_at.

### notification_preferences
Per user per notification type: channel toggles (in_app always on, email, sms).

### announcements
Tenant-scoped: title/body (EN+AM), priority (normal/important/urgent), target (all/department/branch/role), status (draft/published/scheduled).

### approval_delegations
Delegation of approval authority: delegator → delegate with date range and module scope.

---

## Integration

### webhooks
URL, HMAC secret, subscribed events JSON, failure tracking, auto-disable at max_failures.

### webhook_deliveries
Delivery log: event, payload, response status/body/time, attempt number. Retained 30 days.

### organization_templates
8 industry templates: config JSON with departments, positions, shifts, leave types, payroll defaults.

### saved_reports, scheduled_reports
Report builder: saved config, scheduling (daily/weekly/monthly), recipients, format.

### accounting_mappings
Chart of accounts: category → account code/name per tenant.

### failed_syncs
Recovery table: source, payload JSON, error, attempt count, resolved flag.

---

## Table Count

| Category | Count |
|---|---|
| Platform | 3 |
| Auth & Security | 11 |
| Audit | 1 |
| Billing | 2 |
| Organization | 6 |
| Employees | 6 |
| Attendance | 9 |
| Leave | 4 |
| Payroll | 5 |
| Notifications | 4 |
| Integration | 7 |
| **Total** | **58** |
