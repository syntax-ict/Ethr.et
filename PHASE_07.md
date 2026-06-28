# Phase 7 — Integration & API Platform

## Prerequisites
- Phase 3-6 complete (all business modules operational)

## Objective
Build the external-facing API platform with documentation, webhooks, and integration adapters for third-party systems.

---

## S29 — REST API Documentation & Developer Portal

### Backend
- [ ] OpenAPI 3.0 specification:
  - Generate from Laravel routes (using `dedoc/scramble` or manual spec)
  - All endpoints documented: path, method, parameters, request body, response schema, auth
  - Response examples for success and error cases
  - Authentication section (Sanctum token flow)
  - Rate limiting documentation
  - Pagination, filtering, sorting conventions
- [ ] API documentation endpoint: `GET /api/docs` — serves Swagger UI or Redoc
- [ ] API versioning validation: ensure all routes under `/api/v1/`
- [ ] API key management for external integrations:
  - `POST /api/v1/api-keys` — generate API key (tenant_admin only)
  - `GET /api/v1/api-keys` — list active keys
  - `DELETE /api/v1/api-keys/{id}` — revoke key
  - API keys are scoped Sanctum tokens with configurable abilities
  - Rate limiting per API key (separate from user rate limits)
- [ ] Request/response logging for API keys (for debugging)
- [ ] CORS configuration documentation

### Frontend
- [ ] Developer/API section in settings:
  - API keys management page
  - Generate key dialog: name, permissions (read/write per module), expiry
  - Key list: name, created, last used, status, actions (revoke)
  - Key displayed once on creation (copy to clipboard)
- [ ] Link to API documentation (Swagger UI or Redoc in new tab)

### Tests
- [ ] OpenAPI spec validates (automated validation tool) (Pest)
- [ ] API key generation + authentication (Pest)
- [ ] API key scoping (read-only key can't write) (Pest)
- [ ] API key revocation (Pest)
- [ ] Rate limiting per API key (Pest)
- [ ] API keys management UI (Vitest)

### Exit Criteria
- Full OpenAPI 3.0 spec generated
- Interactive documentation (Swagger UI or Redoc) accessible
- API keys manageable with scoped permissions
- Rate limiting per key

---

## S30 — Webhooks

### Backend
- [ ] `webhooks` table:
  - tenant_id, url, secret (auto-generated), events (JSON array), is_active, last_triggered_at, failure_count
- [ ] `webhook_deliveries` table:
  - webhook_id, event, payload (JSON), response_status, response_body, attempt, delivered_at
- [ ] `WebhookController`:
  - `POST /api/v1/webhooks` — register webhook (URL, events to subscribe)
  - `GET /api/v1/webhooks` — list registered webhooks
  - `PUT /api/v1/webhooks/{id}` — update (URL, events, active/inactive)
  - `DELETE /api/v1/webhooks/{id}` — delete
  - `POST /api/v1/webhooks/{id}/test` — send test event
  - `GET /api/v1/webhooks/{id}/deliveries` — delivery log
- [ ] Webhook events:
  - `employee.created`, `employee.updated`, `employee.transitioned`
  - `attendance.recorded`, `attendance.corrected`
  - `leave.requested`, `leave.approved`, `leave.rejected`
  - `payroll.processed`, `payroll.approved`
  - `device.online`, `device.offline`
- [ ] Webhook delivery:
  - HMAC-SHA256 signature in `X-ETHR-Signature` header
  - Payload: `{ event, timestamp, tenant_id, data }`
  - Retry with exponential backoff: 1 min, 5 min, 30 min, 2 hours, 24 hours (5 attempts)
  - Disable webhook after 10 consecutive failures (notify admin)
- [ ] `DispatchWebhookJob`: queued on `default` queue
- [ ] `WebhookPolicy`: tenant_admin only

### Frontend
- [ ] Webhook management page:
  - Webhook list: URL, events count, status (active/inactive/failing), last triggered
  - Create webhook dialog:
    - URL input with validation
    - Event selector (checkbox grid grouped by module)
    - Secret display (generated, copy to clipboard)
  - Webhook detail:
    - Configuration (URL, events, secret)
    - Delivery log (timestamp, event, status code, response time)
    - "Send Test" button
    - Enable/disable toggle
  - Failure indicator: badge showing consecutive failures

### Tests
- [ ] Webhook CRUD (Pest)
- [ ] HMAC signature verification (Pest)
- [ ] Webhook delivery on event (mock HTTP) (Pest)
- [ ] Retry on failure (Pest)
- [ ] Auto-disable after 10 failures (Pest)
- [ ] Test event delivery (Pest)
- [ ] Webhook management UI (Vitest)

### Exit Criteria
- Webhooks configurable per event type
- HMAC-SHA256 signed payloads
- Exponential backoff retry
- Delivery log with status
- Auto-disable on repeated failure
- Test event functionality

---

## S31 — Import/Export & Accounting Integration

### Backend
- [ ] Universal export service:
  - `ExportService`: generates CSV/Excel from any data source with column selection
  - Employee export (with selected fields)
  - Attendance export (date range, filters)
  - Leave export (date range, filters)
  - Payroll export (run-based)
  - All exports as background jobs for large datasets
  - Notification when export ready (download link via signed URL)
- [ ] Universal import framework:
  - `ImportService`: CSV parsing with field mapping, validation, preview, commit
  - Employee import (existing, enhanced)
  - Attendance import (existing)
  - Holiday import
  - Organizational structure import (departments, positions from CSV)
  - Import templates downloadable per entity type
- [ ] Accounting integration framework:
  - `AccountingExportService`:
    - Payroll journal entries (debit salary expense, credit various accounts)
    - Configurable chart of accounts mapping per tenant
    - Export formats: CSV (generic), Excel
    - Future: adapter pattern for specific accounting software (QuickBooks, Peachtree, etc.)
  - `GET /api/v1/accounting/chart-of-accounts` — get account mappings
  - `PUT /api/v1/accounting/chart-of-accounts` — configure mappings
  - `GET /api/v1/accounting/journal/{payroll_run_id}` — get journal entries
  - `GET /api/v1/accounting/export/{payroll_run_id}` — export journal
- [ ] Data migration tools:
  - BioTime attendance export format parser
  - Hikvision standalone software export parser
  - Generic CSV parser with auto-detection of date/time formats

### Frontend
- [ ] Export functionality (on each list page):
  - "Export" button -> format selector (CSV, Excel)
  - Column selector (checkboxes for which fields)
  - Date range filter (where applicable)
  - Download or receive notification when ready
- [ ] Import page (unified `/settings/import`):
  - Import type selector (employees, attendance, holidays, departments)
  - Template download per type
  - Upload CSV
  - Field mapping UI (auto-map + manual override)
  - Preview with validation
  - Commit with progress
- [ ] Accounting settings page:
  - Chart of accounts mapping: payroll categories mapped to account codes
  - Default accounts for: salary expense, tax payable, pension payable, bank
  - Export journal for selected payroll run

### Tests
- [ ] Export generates correct CSV/Excel (Pest)
- [ ] Large export as background job (Pest)
- [ ] Import with field mapping (Pest)
- [ ] BioTime format parsing (Pest)
- [ ] Accounting journal entry generation (Pest)
- [ ] Export UI (Vitest)
- [ ] Import wizard (Vitest)

### Exit Criteria
- Export from any module to CSV/Excel
- Import framework supports multiple entity types
- BioTime format importable
- Accounting journal entries generated from payroll
- Chart of accounts configurable

---

## Phase 7 Exit Criteria

- [ ] OpenAPI documentation generated and browsable
- [ ] API keys manageable with scoped permissions
- [ ] Webhooks with HMAC signing and retry
- [ ] Universal export (CSV/Excel) from all modules
- [ ] Universal import with field mapping
- [ ] Accounting integration with journal entries
- [ ] Legacy format parsers (BioTime)
- [ ] All Pest + Vitest tests passing
