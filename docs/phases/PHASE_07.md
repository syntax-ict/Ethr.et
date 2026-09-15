# Phase 7 — Integration & API Platform (v2.0)

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
- All business modules complete (Phases 0-6)

## Objective
Build the external-facing API platform with OpenAPI documentation, webhook system with SSRF prevention, and import/export/accounting integration.

---

## S30 — REST API Documentation & Developer Portal

### Backend
- [ ] OpenAPI 3.0 specification:
  - Generate from Laravel routes (using `dedoc/scramble` or manual spec file)
  - All endpoints documented: path, method, parameters, request body, response schema, auth, rate limits
  - Response examples for success (200/201) and error (400/401/403/404/422/429/500) cases
  - Authentication section (Sanctum token flow + MFA)
  - Rate limiting documentation per endpoint
  - Pagination, filtering, sorting conventions
  - Idempotency-Key header documentation
  - Tenant resolution (subdomain) documentation
  - RFC-7807 error format documentation
- [ ] API documentation endpoint: `GET /api/docs` — Swagger UI or Redoc (interactive, searchable)
- [ ] API versioning: verify all routes under `/api/v1/`
- [ ] API key management for external integrations:
  - `POST /api/v1/api-keys` — generate API key (requires `settings.edit`)
  - `GET /api/v1/api-keys` — list active keys (name, created, last used, scoped abilities, status)
  - `DELETE /api/v1/api-keys/{id}` — revoke key
  - API keys are scoped Sanctum tokens with configurable abilities:
    - Read-only: can only GET endpoints
    - Read-write: can GET + POST/PUT/DELETE
    - Module-scoped: specific modules only (e.g., attendance only, employees only)
  - Rate limiting per API key: configurable (default 60/min, separate from user limits)
  - Expiry: optional expiry date
  - Key displayed once on creation (copy to clipboard, never shown again)
- [ ] Request/response logging for API keys:
  - `api_key_logs` table: api_key_id, method, path, status, response_time_ms, created_at
  - Retained 30 days, then purged
  - `GET /api/v1/api-keys/{id}/logs` — view request history
- [ ] TypeScript type generation:
  - `openapi-typescript` generates TypeScript types from OpenAPI spec
  - Generated types used in frontend TanStack Query hooks and MSW handlers
  - CI step: regenerate types → fail if uncommitted changes (contract enforcement)

### Frontend
- [ ] Developer/API section in settings:
  - API keys DataTable: name, created, last used, abilities, expiry, status, actions (revoke)
  - "Generate API Key" button → dialog:
    - Name input
    - Permissions: module checkboxes with read/write per module
    - Expiry: optional date picker
    - Rate limit: requests per minute (default 60)
  - Key displayed once on creation → copy to clipboard → dismiss warning
  - Key detail: request log DataTable (method, path, status, response time, timestamp)
  - Link to API documentation (opens Swagger UI in new tab)

### Tests
- [ ] OpenAPI spec validates against OpenAPI 3.0 schema (Pest or automated tool)
- [ ] API key generation + authentication with key (Pest)
- [ ] API key scoping — read-only key cannot POST (Pest)
- [ ] API key module scoping — attendance-only key cannot access employees (Pest)
- [ ] API key revocation — revoked key denied (Pest)
- [ ] API key rate limiting — triggers at configured threshold (Pest)
- [ ] API key expiry — expired key denied (Pest)
- [ ] Request logging — log created on API key usage (Pest)
- [ ] TypeScript types match API responses (CI: regenerate and check for changes)
- [ ] API keys management UI (Vitest)
- [ ] Key display on creation — shown once only (Vitest)

### Exit Criteria
- Full OpenAPI 3.0 spec generated and browsable via Swagger UI
- API keys manageable with module-scoped permissions
- Rate limiting per API key
- Request logging for API key usage
- TypeScript types generated from spec (contract testing in CI)

---

## S31 — Webhooks

### Backend
- [ ] `webhooks` table:
  - tenant_id, url, secret (auto-generated HMAC key), events (JSON array), is_active, last_triggered_at, failure_count, max_failures (default 10)
- [ ] `webhook_deliveries` table:
  - webhook_id, event, payload (JSON), response_status, response_body (truncated 1KB), attempt, delivered_at, response_time_ms
- [ ] Webhook URL validation (SSRF Prevention — CRITICAL):
  - Before registration AND before every delivery:
    1. Parse URL — reject non-HTTPS (except localhost in dev)
    2. Resolve hostname to IP address
    3. Reject private IP ranges: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.0/8`, `169.254.0.0/16`
    4. Reject IPv6 private: `::1`, `fc00::/7`, `fe80::/10`
    5. Reject cloud metadata: `169.254.169.254`
    6. Reject internal hostnames: `localhost`, `*.ethr.et`, `*.internal`, `*.local`
    7. Re-validate on every delivery attempt (DNS can change)
  - `WebhookUrlValidator` service — shared between registration and delivery
- [ ] `WebhookController`:
  - `POST /api/v1/webhooks` — register (URL + events, validates URL, requires `settings.edit`)
  - `GET /api/v1/webhooks` — list
  - `PUT /api/v1/webhooks/{id}` — update (URL, events, active/inactive)
  - `DELETE /api/v1/webhooks/{id}`
  - `POST /api/v1/webhooks/{id}/test` — send test event (rate limited: 10/hr/tenant)
  - `GET /api/v1/webhooks/{id}/deliveries` — delivery log (paginated, last 30 days)
- [ ] Webhook events:
  - `employee.created`, `employee.updated`, `employee.transitioned`
  - `attendance.recorded`, `attendance.corrected`
  - `leave.requested`, `leave.approved`, `leave.rejected`
  - `payroll.processed`, `payroll.approved`
  - `device.online`, `device.offline`
- [ ] Webhook delivery:
  - HMAC-SHA256 signature in `X-ETHR-Signature` header: `sha256=hmac(secret, payload_json)`
  - `X-ETHR-Event` header with event name
  - `X-ETHR-Delivery-ID` header with unique delivery ID
  - Payload: `{ event, timestamp, tenant_id, data }` (JSON)
  - Timeout: 10 seconds per attempt
  - Retry with exponential backoff: 1 min, 5 min, 30 min, 2 hours, 24 hours (5 attempts)
  - Auto-disable webhook after `max_failures` consecutive failures
  - On auto-disable: `DeviceOfflineNotification` (repurpose for webhook) to tenant admin
- [ ] `DispatchWebhookJob`: queued on `default` queue, validates URL before delivery

### Frontend
- [ ] Webhook management page:
  - Webhook DataTable: URL (truncated), events count, status (`StatusBadge`: active/inactive/failing), last triggered, failure count, actions
  - "Create Webhook" button → dialog:
    - URL input with HTTPS validation
    - Event selector: checkbox grid grouped by module (Employees, Attendance, Leave, Payroll, Devices)
    - Secret display: auto-generated, copy to clipboard
  - Webhook detail page:
    - Configuration card: URL, events list, secret (masked, click to reveal), status toggle
    - Delivery log DataTable: timestamp, event, status code, response time, attempt number
    - Click delivery → detail drawer: full payload (JSON viewer), response body
    - "Send Test Event" button (rate limited indicator)
    - Failure indicator: `StatusBadge` showing consecutive failures, warning at 8+
    - "Reset Failure Count" button (if admin)
  - Enable/disable toggle on list

### Tests
- [ ] Webhook CRUD (Pest)
- [ ] URL validation — reject private IPs (Pest, test all ranges)
- [ ] URL validation — reject localhost (Pest)
- [ ] URL validation — reject cloud metadata IP (Pest)
- [ ] URL validation — accept valid HTTPS URLs (Pest)
- [ ] URL re-validation on delivery (Pest)
- [ ] HMAC signature verification (Pest: generate payload, compute expected HMAC, compare)
- [ ] Webhook delivery on event — mock HTTP (Pest)
- [ ] Retry on HTTP 500 (Pest)
- [ ] Retry exponential backoff timing (Pest)
- [ ] Auto-disable after max_failures (Pest)
- [ ] Test event delivery (Pest)
- [ ] Test event rate limiting (Pest)
- [ ] Webhook management UI (Vitest)
- [ ] Event selector checkbox grid (Vitest)
- [ ] Delivery log detail (Vitest)

### Exit Criteria
- Webhooks configurable per event type
- SSRF prevention: all private/internal IPs rejected
- HMAC-SHA256 signed payloads with verification documentation
- Exponential backoff retry (5 attempts)
- Delivery log with full payload and response
- Auto-disable on repeated failure with admin notification
- Test event functionality (rate limited)

---

## S32 — Import/Export & Accounting Integration

### Backend
- [ ] Universal export service (`ExportService`):
  - Generates CSV/Excel from any data source with column selection
  - Employee export (configurable fields, never includes encrypted data)
  - Attendance export (date range, filters)
  - Leave export (date range, filters)
  - Payroll export (run-based)
  - Large exports (> 500 rows) run as background `ExportJob`
  - `ExportJobCompleted` notification with download link (signed URL, 24hr expiry)
  - Export stored in MinIO: `tenant-{id}/exports/{filename}`
  - Auto-cleanup: exports deleted after 30 days
- [ ] Universal import framework (`ImportService`):
  - CSV parsing with configurable field mapping
  - Validation per entity type (uses existing FormRequest validators)
  - Preview with per-row errors
  - Commit with idempotency key
  - Supported entities:
    - Employees (existing, enhanced — Phase 2)
    - Attendance (existing — Phase 3)
    - Holidays (new)
    - Departments + positions (new — from CSV)
  - Import templates downloadable per entity type
- [ ] Accounting integration framework:
  - `AccountingExportService`:
    - Payroll journal entries: debit salary expense, credit tax payable, credit pension payable, credit bank
    - Configurable chart of accounts mapping per tenant
    - Export formats: CSV (generic), Excel
  - `GET /api/v1/accounting/chart-of-accounts` — get current mappings
  - `PUT /api/v1/accounting/chart-of-accounts` — configure mappings (requires `payroll.configure`)
  - `GET /api/v1/accounting/journal/{payroll_run_id}` — get journal entries (DataTable format)
  - `GET /api/v1/accounting/export/{payroll_run_id}` — export journal as CSV/Excel
  - Default chart of accounts: Ethiopian standard accounts pre-populated
- [ ] Legacy data migration parsers:
  - BioTime attendance export format parser (CSV with specific date/time format)
  - Hikvision standalone software export parser (CSV/Excel)
  - Generic CSV parser with auto-detection of date/time formats (ISO 8601, DD/MM/YYYY, MM/DD/YYYY, Ethiopian calendar)
- [ ] Permission: `reports.view` for exports, `employees.import` for imports, `payroll.configure` for accounting

### Frontend
- [ ] Export functionality (on each list page):
  - "Export" button → drawer:
    - Format selector: CSV / Excel
    - Column selector: checkboxes for which fields to include
    - Date range filter (where applicable, via `DualCalendarPicker`)
    - Preview: "Will export X records"
    - "Export" button → immediate download for < 500 rows, notification for larger
- [ ] Import page (unified `/settings/import`):
  - Import type selector: cards for each entity type (Employees, Attendance, Holidays, Departments)
  - Template download button per type
  - Upload CSV (drag-and-drop via `FileUpload`)
  - Format auto-detection for dates (show detected format, allow override)
  - Field mapping UI: auto-mapped columns + manual override dropdowns
  - Preview DataTable with row-level validation (green/red)
  - Commit with progress indicator
  - Result summary: created, skipped, errors (downloadable error CSV)
- [ ] Accounting settings page:
  - Chart of accounts mapping: DataTable with payroll categories → account codes
  - Default accounts: salary expense, tax payable, pension payable, bank, OT expense
  - "Reset to Defaults" button
  - Export journal for selected payroll run → format selector → download

### Tests
- [ ] Export generates correct CSV with selected columns (Pest)
- [ ] Export generates correct Excel (Pest)
- [ ] Large export runs as background job (Pest)
- [ ] Export never includes encrypted fields (Pest)
- [ ] Import with valid field mapping (Pest)
- [ ] Import with validation errors — per-row errors returned (Pest)
- [ ] Import idempotency (Pest)
- [ ] BioTime format parsing — dates and times correct (Pest)
- [ ] Hikvision format parsing (Pest)
- [ ] Generic CSV date format auto-detection (Pest)
- [ ] Accounting journal entries — correct debit/credit (Pest)
- [ ] Chart of accounts CRUD (Pest)
- [ ] Permission checks on all import/export endpoints (Pest)
- [ ] Export UI — column selector (Vitest)
- [ ] Import wizard — all steps (Vitest)
- [ ] Accounting settings — mapping table (Vitest)

### Exit Criteria
- Export from any module to CSV/Excel (with column selection, no encrypted data)
- Large exports run as background jobs with notification
- Import framework supports 4 entity types with field mapping
- BioTime and Hikvision legacy format parsing
- Accounting journal entries generated from payroll
- Chart of accounts configurable with Ethiopian defaults
- All exports auto-cleaned after 30 days

---

## Phase 7 Exit Criteria

- [ ] OpenAPI 3.0 documentation generated and browsable
- [ ] API keys manageable with scoped permissions and rate limiting
- [ ] TypeScript types generated from spec (contract testing in CI)
- [ ] Webhooks with HMAC signing, SSRF prevention, and retry
- [ ] Universal export (CSV/Excel) from all modules
- [ ] Universal import with field mapping for 4 entity types
- [ ] Accounting integration with journal entries
- [ ] Legacy format parsers (BioTime, Hikvision)
- [ ] All Pest + Vitest tests passing
- [ ] TenantIsolationTest passes
