# Phase 2 — Organization & Employee Platform (v2.0)

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
- Phase 0 complete (foundation, permissions, design system)
- Phase 1 complete (tenancy, onboarding, templates)

## Objective
Full organization structure management, employee CRUD with complete profiles, employee lifecycle state machine, document management, bulk operations, and full-text search.

---

## S10 — Organization Structure

### Backend
- [ ] Migrations: `branches`, `departments`, `teams`, `positions`, `grades`, `cost_centers` (see DATABASE.md)
- [ ] All models with `BelongsToTenant`, `HasPublicId`, `SoftDeletes`, proper `$fillable`, `$hidden`, `$casts`
- [ ] `BranchController`: CRUD + list with filtering
  - Geofence fields (latitude, longitude, radius_meters) for attendance geofencing
  - `is_headquarters` flag (exactly one per tenant — enforce via model event)
  - Ethiopian address fields (region, city, subcity, woreda, kebele)
- [ ] `DepartmentController`: CRUD + hierarchical listing
  - `parent_id` for nested departments (max 5 levels)
  - `head_employee_id` assignment
  - Tree structure endpoint: `GET /api/v1/organization/tree` (cached 30 min)
  - Prevents circular parent references
- [ ] `TeamController`: CRUD under departments
- [ ] `PositionController`: CRUD + salary range (min/max in integer cents)
- [ ] `GradeController`: CRUD + level ordering
- [ ] `CostCenterController`: CRUD
- [ ] Org chart endpoint: `GET /api/v1/organization/chart` (hierarchical JSON, cached 30 min)
- [ ] FormRequest for each resource
- [ ] Policy for each resource using `hasPermission('organization.manage_*')`
- [ ] Audit logging for create/update/delete on all org entities
- [ ] Cache invalidation on org structure changes (`tenant:{id}:org:structure`)

### Frontend
- [ ] Organization settings page (tabbed: Branches | Departments | Teams | Positions | Grades | Cost Centers)
- [ ] Branch management:
  - DataTable with search, sort, filter
  - Create/edit dialog (name, code, city, address via `AddressForm`, phone, email)
  - Map pin for geofence (latitude/longitude input, radius slider in meters)
  - HQ badge indicator
- [ ] Department management:
  - Tree view (expandable, showing hierarchy with indentation)
  - Create/edit dialog (name, code, parent department dropdown, branch, head employee search)
  - Drag-and-drop reordering within same level
  - Department count badge showing employee count
- [ ] Team management: DataTable + create/edit dialog under department
- [ ] Position management: DataTable + create/edit with salary range (`CurrencyInput`)
- [ ] Grade management: DataTable + create/edit with level ordering
- [ ] Cost center management: DataTable + create/edit
- [ ] Org chart visualization: hierarchical diagram (departments → teams → positions)
  - Collapsible nodes
  - Employee count per node
  - Click node to navigate to detail
- [ ] All forms bilingual, all states handled via `QueryBoundary`

### Tests
- [ ] Branch CRUD + validation (Pest)
- [ ] Branch geofence data stored correctly (Pest)
- [ ] Department hierarchy — create parent, child, verify tree endpoint (Pest)
- [ ] Department circular reference prevention (Pest)
- [ ] Tenant isolation for all org resources (Pest)
- [ ] Authorization — employee role denied, hr_admin allowed (Pest)
- [ ] Org tree endpoint returns correct structure (Pest)
- [ ] Soft delete preserves historical references (Pest)
- [ ] Cache invalidation on org changes (Pest)
- [ ] Branch form + geofence input (Vitest)
- [ ] Department tree view expands/collapses (Vitest)
- [ ] All QueryBoundary states rendered (Vitest)

### Exit Criteria
- Full CRUD for all org structure entities
- Department hierarchy works (parent-child, max 5 levels)
- Org chart renders correctly
- Geofence data stored for branches (used in Phase 3)
- Soft delete enforced on all org entities
- Org structure cached and invalidated correctly

---

## S11 — Employee Management & Lifecycle

### Backend
- [ ] `employees` table migration (full schema per DATABASE.md)
  - FULLTEXT index on `(first_name, last_name, first_name_am, last_name_am, employee_code, email)` for search
  - `search_text` computed column concatenating all searchable fields (for Amharic tokenization fallback)
- [ ] `Employee` model:
  - `BelongsToTenant`, `HasPublicId`, `SoftDeletes`
  - All relationships (department, branch, position, grade, team, cost_center, supervisor, user, documents, transitions)
  - Encrypted fields: `bank_account_number`, `tin` (AES-256 via Laravel Crypt)
  - `$hidden = ['id', 'bank_account_number', 'tin']` — never leak in API
- [ ] `EmployeeController`:
  - `index` — paginated, filterable (status, department, branch, position, grade), searchable (FULLTEXT MATCH AGAINST), sortable
  - `store` — create with all profile fields, audit logged
  - `show` — with eager-loaded relationships (select only needed columns, no N+1)
  - `update` — partial update, audit logged, encrypted fields handled
  - `destroy` — soft delete (requires `employees.delete` permission)
  - Performance: employee list at 1000 rows < 500ms, search < 100ms
- [ ] `EmployeeTransitionController`:
  - `POST /api/v1/employees/{id}/transition` — { to_status, reason, effective_date, metadata }
  - Validates transition via `EmployeeStatus::canTransitionTo()` state machine
  - Creates `employee_transitions` record (immutable)
  - Dispatches `EmployeeTransitioned` event
  - Updates employee status + relevant dates (confirmation_date, termination_date, etc.)
  - Audit logged
- [ ] `EmployeeStatus` enum with state machine:
  - draft → probation → active
  - active → suspended → active
  - active → transferred → active
  - any active state → departed (resign/retire/terminate)
  - departed → probation (rehire — creates new employment period)
  - `canTransitionTo(EmployeeStatus $target): bool`
  - `availableTransitions(): array`
- [ ] `employee_transitions` table: employee_id, from_status, to_status, reason, effective_date, metadata (JSON), transitioned_by
- [ ] `employee_emergency_contacts` CRUD nested under employee
- [ ] `employee_bank_details` CRUD nested under employee (encrypted account_number, encrypted TIN)
- [ ] `employee_education` CRUD nested under employee
- [ ] Employee search: `GET /api/v1/employees?search=query`
  - Uses FULLTEXT index with `MATCH ... AGAINST` in natural language mode
  - Fallback: `LIKE 'query%'` on employee_code and first_name for auto-complete
  - Search works for both Latin and Amharic text
- [ ] Employee stats: `GET /api/v1/employees/stats` (count by status, department, branch — cached 5 min)
- [ ] Self-update: `PUT /api/v1/profile`
  - Allowed fields (immediate): phone, address, emergency contacts, photo
  - Approval-required fields: bank details, name, TIN → creates `ProfileUpdateRequest`
  - Uses `self.edit_basic` permission
- [ ] FormRequests for all operations (validation includes Amharic name fields)
- [ ] `EmployeePolicy` using `hasPermission()`:
  - view: `employees.view` OR `self.view` (own) OR `team.view` (supervisor chain)
  - create: `employees.create`
  - update: `employees.edit` OR `self.edit_basic` (own, limited fields)
  - delete: `employees.delete`
  - transition: `employees.transition`
- [ ] Audit logging for all mutations

### Frontend
- [ ] Employee list page:
  - Enterprise DataTable with columns: photo (avatar), name, code (monospace), department, position, status (StatusBadge), actions
  - Full-text search bar (debounced, searches name/code/email in both languages)
  - Filters: status (multi-select), department (multi-select with hierarchy), branch, position, grade
  - Sort by any column
  - Row expansion: show quick details (hire date, supervisor, contact info)
  - Bulk actions on selected rows: export, update department, update branch
  - "Add Employee" button (if permission)
  - Stats cards above table (StatCard: total, active, on probation, departed)
  - Saved views (column config + filters persisted)
  - Virtual scrolling enabled for 1000+ rows
- [ ] Employee create/edit form (page form, tabbed):
  - Personal tab: first_name, last_name, first_name_am, last_name_am, email, phone (`PhoneInput`), DOB (`DualCalendarPicker`), gender, nationality, marital status, religion, photo upload (`FileUpload`)
  - Employment tab: employee code (auto-generated or manual, monospace display), hire date (`DualCalendarPicker`), probation end date, status (read-only `StatusBadge`)
  - Organization tab: department, branch, position, grade, team, cost center, supervisor (all searchable dropdowns with QueryBoundary)
  - Financial tab: salary (`CurrencyInput` in ETB), bank details (bank name, branch, account — masked display), TIN
  - All tabs validate independently via Zod
  - Auto-save draft every 30 seconds
  - Unsaved changes warning on navigation
- [ ] Employee detail page:
  - Profile header: photo, name (English + Amharic), status badge, employee code (monospace), quick action buttons
  - Tabbed content: Overview | Timeline | Documents | Emergency | Education | Bank
  - Overview: all profile fields in read-only card layout, supervisor link, department breadcrumb
  - Timeline: `Timeline` component showing all transitions, status changes, profile updates chronologically
  - Documents: (implemented in S12)
  - Emergency: contact cards with relationship, phone, address
  - Education: institution, degree, field, year cards
  - Bank: masked account number (show last 4 digits), bank name, branch — edit button (if permission)
- [ ] Employee lifecycle transitions:
  - "Change Status" button on detail page (if `employees.transition` permission)
  - Drawer form: select target status (only valid transitions shown via `availableTransitions()`), reason (required), effective date (`DualCalendarPicker`)
  - `ComparisonCard` showing current vs target status
  - Confirmation dialog with transition summary
- [ ] Employee self-service profile:
  - Uses employee detail page with limited edit capability
  - Edit button only on allowed fields (phone, address, emergency contacts, photo)
  - Bank detail changes show "Requires HR approval" notice
- [ ] All components responsive and bilingual
- [ ] QueryBoundary on all data-fetching sections

### Tests
- [ ] Employee CRUD — create, read, update, soft delete (Pest)
- [ ] Employee FULLTEXT search — Latin + Amharic (Pest)
- [ ] Employee search performance — 1000 rows < 100ms (Pest)
- [ ] Employee list with filter + sort — pagination correct (Pest)
- [ ] Lifecycle transitions — all valid paths (Pest)
- [ ] Lifecycle transitions — invalid transitions rejected (Pest)
- [ ] Transition creates immutable history record (Pest)
- [ ] Self-update — allowed fields succeed, restricted fields create approval request (Pest)
- [ ] Tenant isolation — cross-tenant employee access denied (Pest)
- [ ] Authorization matrix — all permission combinations (Pest)
- [ ] Bank account encryption — stored encrypted, decrypted on access (Pest)
- [ ] TIN encryption — stored encrypted, never in API response (Pest)
- [ ] Employee numeric PK never in API response (Pest)
- [ ] Employee list + filters render correctly (Vitest)
- [ ] Employee form tabs — all fields render (Vitest)
- [ ] Lifecycle dialog — only valid transitions shown (Vitest)
- [ ] Timeline component — renders chronological events (Vitest)
- [ ] CurrencyInput — stores cents, displays ETB (Vitest)

### Exit Criteria
- Full employee CRUD with all profile fields
- FULLTEXT search functional in both languages, < 100ms
- State machine transitions working with immutable audit trail
- Encrypted sensitive fields (bank, TIN) — never exposed in API
- Self-service profile viewing with approval for sensitive changes
- Virtual scrolling handles 1000+ employees
- Permission-based authorization (not role-string checks)

---

## S12 — Employee Documents & Bulk Import

### Backend
- [ ] `employee_documents` table migration (soft deletes)
- [ ] `EmployeeDocumentController`:
  - `index` — list documents for employee (filterable by category)
  - `store` — upload via FileService (presigned URL → confirm with content verification)
  - `show` — download via signed URL (5-min expiry)
  - `destroy` — soft delete (requires `employees.edit` permission)
- [ ] Document categories enum: `contract`, `certificate`, `id_copy`, `academic`, `medical`, `other`
- [ ] Document expiry tracking (optional `expires_at` field)
- [ ] Expiring documents query: documents expiring within 30 days (for notification/dashboard)
- [ ] Bulk import enhancement:
  - Extended CSV fields: department_code, branch_code, position_code, grade_code (map to existing org structure)
  - `POST /api/v1/employees/import/template` — download CSV template with column headers + example row
  - `POST /api/v1/employees/import/preview` — parse CSV, validate each row, return preview with per-row errors
    - Validate: required fields, email uniqueness, department/branch/position existence, phone format, salary format
    - Return: `{ valid_count, error_count, rows: [{ row_number, data, errors, status: 'valid'|'error' }] }`
  - `POST /api/v1/employees/import/commit` — create employees (idempotent via `Idempotency-Key`)
    - Background job for large imports (> 100 rows)
    - Progress tracking in Redis: `import:{key}:progress`
  - `GET /api/v1/employees/import/status/{key}` — poll import progress
    - Returns: `{ status: 'processing'|'complete'|'failed', progress: 75, created: 50, skipped: 3, errors: 2 }`
- [ ] Bulk update: `POST /api/v1/employees/bulk-update`
  - Input: `{ employee_ids: [...], updates: { department_id?, branch_id?, status? } }`
  - Validates all transitions/assignments
  - Creates audit log entry per employee
  - Requires `employees.edit` permission
- [ ] Employee export: `GET /api/v1/employees/export?format=csv`
  - Applies current filters (same as list endpoint)
  - Column selection via `?columns=name,code,department,position,salary`
  - Large exports (> 500 rows) run as background job with notification
  - Signed download URL (5-min expiry)
  - Never exports encrypted fields (bank account, TIN)

### Frontend
- [ ] Documents tab on employee detail page:
  - Document list (name, category badge, file size, upload date, expiry date, actions)
  - Upload button → FileUpload component with category selector
  - Category filter (dropdown)
  - Download button (opens signed URL)
  - Delete with ConfirmDialog
  - Expiry warning badges (amber for 30 days, red for expired)
  - `FilePreview` for PDF/image documents (lightbox)
  - QueryBoundary for all states
- [ ] Import page (`/employees/import`):
  - Step 1: Download template button (CSV with headers + example)
  - Step 2: Upload CSV (drag-and-drop via FileUpload)
  - Step 3: Field mapping UI (auto-detected columns + manual override dropdowns)
  - Step 4: Preview DataTable with row-level validation
    - Green rows: valid
    - Red rows: error with per-cell error tooltip
    - Summary: X valid, Y errors
    - Fix inline or re-upload
  - Step 5: Commit button with progress bar (polls status endpoint)
  - Summary on completion: X created, Y skipped (duplicates), Z errors
  - Errors downloadable as CSV for offline fixing
- [ ] Export button on employee list:
  - Opens drawer with column selector (checkboxes)
  - Format selector (CSV only for v1.0)
  - "Export" button — immediate download for < 500 rows, notification for larger
- [ ] Bulk actions on employee list:
  - Select rows via DataTable checkboxes
  - Action bar appears: "Update Department" / "Update Branch" / "Export Selected"
  - Drawer form for bulk updates
  - ConfirmDialog showing count of affected employees

### Tests
- [ ] Document upload + content verification (Pest)
- [ ] Document download via signed URL (Pest)
- [ ] Document category filtering (Pest)
- [ ] Document expiry query — upcoming expirations (Pest)
- [ ] CSV import with valid data — employees created correctly (Pest)
- [ ] CSV import with validation errors — errors reported per row (Pest)
- [ ] CSV import idempotency — same key doesn't duplicate (Pest)
- [ ] CSV import with department/position codes — mapped correctly (Pest)
- [ ] Large import runs as background job (> 100 rows) (Pest)
- [ ] Bulk update — department change on 10 employees (Pest)
- [ ] Export with filters — correct data, no encrypted fields (Pest)
- [ ] Large export runs as background job (Pest)
- [ ] Import wizard steps — navigation and validation (Vitest)
- [ ] Import preview — green/red rows rendered (Vitest)
- [ ] Document upload component — file type/size validation (Vitest)
- [ ] Bulk action bar — appears on selection (Vitest)

### Exit Criteria
- Documents upload to MinIO with content verification, download via signed URLs
- CSV import handles 1000+ rows with validation, preview, idempotency
- Large imports run as background jobs with progress tracking
- Bulk update works for department/branch changes with audit trail
- Export generates CSV with applied filters, never includes encrypted data
- File preview works for PDFs and images

---

## Phase 2 Exit Criteria

- [ ] Full org structure CRUD (branches, departments, teams, positions, grades, cost centers)
- [ ] Department hierarchy with tree view (max 5 levels)
- [ ] Full employee CRUD with all profile fields
- [ ] FULLTEXT search functional in English + Amharic (< 100ms)
- [ ] Employee lifecycle state machine with immutable audit trail
- [ ] Virtual scrolling handles 1000+ employees
- [ ] Permission-based authorization on all endpoints
- [ ] Encrypted sensitive fields (bank, TIN)
- [ ] Document management with content verification
- [ ] CSV import with validation, preview, idempotency, background jobs
- [ ] Bulk update and export
- [ ] Soft delete enforced per policy
- [ ] All Pest + Vitest tests passing
- [ ] TenantIsolationTest passes
