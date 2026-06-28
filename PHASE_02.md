# Phase 2 — Organization & Employee Platform

## Prerequisites
- Phase 0 complete (foundation)
- Phase 1 complete (tenancy + onboarding)

## Objective
Full organization structure management, employee CRUD with complete profiles, employee lifecycle state machine, document management, and bulk operations.

---

## S09 — Organization Structure

### Backend
- [ ] Migrations: `branches`, `departments`, `teams`, `positions`, `grades`, `cost_centers` (see DATABASE.md)
- [ ] Models with `BelongsToTenant`, `HasPublicId`, proper `$fillable`, `$hidden`, `$casts`
- [ ] `BranchController`: CRUD + list with filtering
  - Geofence fields (latitude, longitude, radius) for attendance geofencing
  - `is_headquarters` flag (exactly one per tenant)
- [ ] `DepartmentController`: CRUD + hierarchical listing
  - `parent_id` for nested departments
  - `head_employee_id` assignment
  - Tree structure endpoint: `GET /api/v1/organization/tree`
- [ ] `TeamController`: CRUD under departments
- [ ] `PositionController`: CRUD + salary range
- [ ] `GradeController`: CRUD + level ordering
- [ ] `CostCenterController`: CRUD
- [ ] Org chart endpoint: `GET /api/v1/organization/chart` (hierarchical JSON)
- [ ] FormRequest for each resource
- [ ] Policy for each resource (tenant_admin + hr_admin can manage)
- [ ] Audit logging for create/update/delete

### Frontend
- [ ] Organization settings page (tabbed: Branches | Departments | Teams | Positions | Grades | Cost Centers)
- [ ] Branch management:
  - List view with search
  - Create/edit dialog (name, code, city, address, phone, email)
  - Map pin for geofence (latitude/longitude input, radius slider)
  - HQ badge
- [ ] Department management:
  - Tree view (expandable, showing hierarchy)
  - Create/edit dialog (name, code, parent department, branch, head)
  - Drag-and-drop reordering
- [ ] Team management: list + create/edit under department
- [ ] Position management: list + create/edit with salary range
- [ ] Grade management: list + create/edit with level
- [ ] Org chart visualization: hierarchical diagram (departments -> teams -> positions)
- [ ] All forms bilingual, all states handled (loading, empty, error)

### Tests
- [ ] Branch CRUD + validation (Pest)
- [ ] Department hierarchy (create parent, child, tree endpoint) (Pest)
- [ ] Tenant isolation for all resources (Pest)
- [ ] Authorization (employee role denied) (Pest)
- [ ] Org tree endpoint returns correct structure (Pest)
- [ ] Branch form + geofence input (Vitest)
- [ ] Department tree view (Vitest)

### Exit Criteria
- Full CRUD for all org structure entities
- Department hierarchy works (parent-child)
- Org chart renders correctly
- Geofence data stored for branches (used in Phase 3)

---

## S10 — Employee Management & Lifecycle

### Backend
- [ ] `employees` table migration (see DATABASE.md — full schema)
- [ ] `Employee` model: all relationships (department, branch, position, grade, team, cost_center, supervisor, user, documents)
- [ ] `EmployeeController`:
  - `index` — paginated, filterable (status, department, branch, position), searchable (name, email, code), sortable
  - `store` — create with all profile fields
  - `show` — with eager-loaded relationships
  - `update` — partial update, audit logged
  - `destroy` — soft delete (tenant_admin only)
- [ ] `EmployeeTransitionController`:
  - `POST /api/v1/employees/{id}/transition` — { to_status, reason, effective_date, metadata }
  - Validates transition is allowed (via `EmployeeStatus::canTransitionTo()`)
  - Creates `employee_transitions` record
  - Dispatches `EmployeeTransitioned` event
  - Updates employee status + relevant dates (confirmation_date, termination_date, etc.)
- [ ] `EmployeeStatus` enum with state machine (see BACKEND.md)
- [ ] `employee_transitions` table for lifecycle history
- [ ] `employee_emergency_contacts` CRUD nested under employee
- [ ] `employee_bank_details` CRUD nested under employee (encrypted account_number)
- [ ] `employee_education` CRUD nested under employee
- [ ] Employee search: `GET /api/v1/employees?search=query` (full-text on name, email, code)
- [ ] Employee stats: `GET /api/v1/employees/stats` (count by status, department, branch)
- [ ] Self-update: `PUT /api/v1/profile` (employee updates own limited fields, creates approval request)
- [ ] FormRequests for all operations
- [ ] `EmployeePolicy` (view: self + supervisor + hr; create/update: hr_admin+; delete: tenant_admin)
- [ ] Audit logging for all mutations

### Frontend
- [ ] Employee list page:
  - DataTable with columns: photo, name, code, department, position, status, actions
  - Search bar (debounced)
  - Filters: status, department, branch, position (multi-select dropdowns)
  - Sort by any column
  - Bulk actions: export selected
  - "Add Employee" button
  - Stats cards above table (total, active, on probation, departed)
- [ ] Employee create/edit form:
  - Tabbed: Personal | Employment | Organization | Financial
  - Personal: name, name_am, email, phone, DOB, gender, nationality, marital status, religion, photo upload
  - Employment: employee code, hire date, probation end, status (read-only)
  - Organization: department, branch, position, grade, team, cost center, supervisor (searchable dropdowns)
  - Financial: salary (ETB input), bank details (bank name, branch, account), TIN
- [ ] Employee detail page:
  - Profile header: photo, name, status badge, quick actions
  - Tabbed content: Overview | Timeline | Documents | Emergency | Education | Bank
  - Overview: all profile fields in read-only card layout
  - Timeline: all transitions, status changes, updates chronologically
- [ ] Employee lifecycle transitions:
  - "Change Status" button on detail page
  - Dialog: select target status (only valid transitions shown), reason, effective date
  - Confirmation dialog showing the transition
- [ ] Emergency contacts tab: list + add/edit/delete
- [ ] Education tab: list + add/edit/delete
- [ ] Bank details tab: masked account number display, edit form
- [ ] Employee profile page (self-service): own profile with edit capability (limited fields)
- [ ] All components responsive and bilingual

### Tests
- [ ] Employee CRUD (Pest)
- [ ] Employee search + filter + sort (Pest)
- [ ] Lifecycle transitions (valid + invalid) (Pest)
- [ ] Transition creates history record (Pest)
- [ ] Self-update with limited fields (Pest)
- [ ] Tenant isolation (Pest)
- [ ] Authorization matrix (Pest)
- [ ] Bank account encryption (Pest)
- [ ] Employee list + filters (Vitest)
- [ ] Employee form tabs (Vitest)
- [ ] Lifecycle dialog (Vitest)

### Exit Criteria
- Full employee CRUD with all profile fields
- State machine transitions working with audit trail
- Search and filtering performant at 1000+ employees
- Encrypted sensitive fields (bank, TIN)
- Self-service profile viewing

---

## S11 — Employee Documents & Bulk Import

### Backend
- [ ] `employee_documents` table migration
- [ ] `EmployeeDocumentController`:
  - `index` — list documents for employee (filterable by category)
  - `store` — upload via FileService (presigned URL flow)
  - `show` — download via signed URL
  - `destroy` — soft delete
- [ ] Document categories: contract, certificate, id_copy, academic, medical, other
- [ ] Document expiry tracking (optional `expires_at` field)
- [ ] Bulk import enhancement:
  - Extended CSV fields (department code, branch code, position code — map to existing org structure)
  - `POST /api/v1/employees/import/template` — download CSV template with column headers
  - `POST /api/v1/employees/import/preview` — parse CSV, validate, return preview with errors
  - `POST /api/v1/employees/import/commit` — create employees (idempotent via import_key)
  - Import progress tracking (for large files): store status in cache, poll via `GET /api/v1/employees/import/status/{key}`
- [ ] Bulk update: `POST /api/v1/employees/bulk-update` (select employees, update department/branch/status)
- [ ] Employee export: `GET /api/v1/employees/export?format=csv` (filterable, same as list)

### Frontend
- [ ] Documents tab on employee detail page:
  - Document list (name, category, size, upload date, expiry, actions)
  - Upload button -> FileUpload component
  - Category selector on upload
  - Download button (signed URL)
  - Delete with confirmation
  - Expiry warning badges
- [ ] Import page (`/employees/import`):
  - Step 1: Download template button
  - Step 2: Upload CSV (drag-and-drop)
  - Step 3: Field mapping UI (auto-detected + manual override)
  - Step 4: Preview table with row-level validation (green = valid, red = error with message)
  - Step 5: Commit button with progress bar
  - Summary: X created, Y skipped (duplicates), Z errors
- [ ] Export button on employee list (CSV download with current filters applied)
- [ ] Bulk actions on employee list: select rows -> "Update Department" / "Update Branch" dropdown

### Tests
- [ ] Document upload + download (Pest)
- [ ] Document category filtering (Pest)
- [ ] CSV import with valid data (Pest)
- [ ] CSV import with validation errors (Pest)
- [ ] CSV import idempotency (Pest)
- [ ] Bulk update (Pest)
- [ ] Export (Pest)
- [ ] Import wizard steps (Vitest)
- [ ] Document upload component (Vitest)

### Exit Criteria
- Documents upload to MinIO, download via signed URLs
- CSV import handles 1000+ rows with validation + idempotency
- Bulk update works for department/branch changes
- Export generates CSV with applied filters

---

## Phase 2 Exit Criteria

- [ ] Full org structure CRUD (branches, departments, teams, positions, grades, cost centers)
- [ ] Department hierarchy with tree view
- [ ] Full employee CRUD with all profile fields
- [ ] Employee lifecycle state machine with audit trail
- [ ] Employee search performant at 1000+ records
- [ ] Document management via MinIO
- [ ] CSV import with validation, preview, idempotency
- [ ] Bulk update and export
- [ ] All authorization policies enforced
- [ ] All Pest + Vitest tests passing
