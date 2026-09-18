# ETHR — Global Development Rules (v2.0)

# AI Execution Policy (Pro/Max)

## Automatic Model Routing

Prefer the lowest-cost model capable of completing the task.

### Sonnet (Default)

Use Sonnet automatically for:

- CRUD implementation
- Controllers
- FormRequests
- Policies
- API Resources
- Models
- Migrations
- Frontend components
- React pages
- Tailwind styling
- API hooks
- Unit tests
- Feature tests
- Documentation
- Translation keys
- Refactoring under 10 files
- Bug fixes
- Formatting
- Type fixes
- Lint fixes

Target: 90% of development work.

---

### Opus (Escalate Only)

Automatically escalate to Opus when any of these are true:

- Architectural decisions
- Security review
- Multi-file refactor (>10 files)
- Tenant isolation changes
- Authentication or authorization design
- Complex debugging after two failed attempts
- Performance optimization
- Query optimization
- Database redesign
- Event-driven architecture
- Queue design
- Offline sync logic
- PWA synchronization
- Payroll calculation engine
- Attendance matching engine
- Conflict resolution logic
- Permission system changes
- Large context analysis (>30 files)
- Reviewing an entire phase
- Release readiness audit

Return to Sonnet immediately after the architecture is decided.

---

### Never use Opus for

- Formatting
- Renaming files
- Small UI changes
- CSS
- Translation
- Documentation only
- Boilerplate CRUD
- Simple tests
- Small bug fixes

These should always stay on Sonnet.

---

### Cost Optimization

Before escalating to Opus ask internally:

1. Is this mainly implementation?
2. Is reasoning actually required?
3. Can Sonnet complete this safely?

If YES to implementation, remain on Sonnet.

Only escalate when reasoning complexity exceeds implementation complexity.

---
# ========================================================
# LOCAL DEVELOPMENT POLICY (NON-NEGOTIABLE)
# ========================================================

ETHR is developed entirely within the local workspace.

The local filesystem is the ONLY source of truth.

**Superseded 2026-08-29 — Git IS in use on this project.**

This section previously read "Ignore ALL Git-related functionality" and listed
every Git command as forbidden. That was never true of the repository and had
become actively misleading:

- The project is a Git repository with a GitHub remote
  (`syntax-ict/Ethr.et`), 40+ branches, and a full commit history.
- **This same file** defines a Commit Convention (see below) with required
  `type(scope):` prefixes — a rule that cannot be followed by anything that is
  forbidden to commit.
- The owner directed branch, commit and merge work explicitly on 2026-08-29.

Keeping a "NON-NEGOTIABLE" ban next to a commit-message standard meant every
session had to pick one and guess. The ban is the half that was wrong, so it is
the half that goes.

**What survives from the original intent, because it was correct:**

- The local filesystem is the source of truth for *what the code does*. Never
  infer completeness from commit messages, branch names or documentation — read
  the code. (See the Enterprise Codebase Audit Protocol below.)
- Git is not a substitute for verification. A green commit is not a passing
  gate; run `bash scripts/gates.sh`.
- Do not block on the developer to commit, push or merge. Continue from the
  current working-tree state.

**Working rules now:**

- Commit logically separated slices; follow the Commit Convention below.
- Never commit directly to `main` — branch, then merge with `--no-ff` so a
  change set has one revert point.
- Push only when the owner asks. `origin` is a shared remote, and this
  environment has no non-interactive credentials for it.

# Task Decomposition Rules

Never attempt an entire phase in one execution.

Break work into slices no larger than:

- one feature
- one controller
- one policy
- one page
- one service

Each slice must compile independently.

Complete:

Analyze → Implement → Test → Commit

before starting another slice.

---

# Context Management

Avoid re-reading the entire repository.

At the beginning of each task:

Read:

- CLAUDE.md
- Relevant phase document
- Files directly related to the feature

Do not scan unrelated modules.

If more than 30 files are required:

Create an implementation plan first.

Then implement incrementally.

---

# Development Priority

Always work in this order:

1. Security (tenant isolation, auth, encryption)
2. Data integrity (validation, constraints, audit trails)
3. Backend (migrations, models, services, controllers)
4. API (FormRequests, Resources, Policies)
5. Tests (unit, feature, integration)
6. Frontend (types, hooks, components, pages)
7. Performance (indexes, caching, eager loading)
8. Documentation

Never implement frontend before backend contracts exist.

Never implement a feature without writing its tests in the same slice.

---

# Response Policy

Do not explain every generated file.

Output only:

- files changed
- summary
- remaining tasks
- blockers

Keep explanations under 200 words unless asked.

Minimize token usage.

---

# Large Refactor Rules

When touching more than 20 files:

Phase 1: Analyze, identify dependencies, produce plan.

Phase 2: Implement.

Phase 3: Run tests.

Never mix planning and implementation in the same large task.

---

## Identity

**ETHR** — Ethiopian Workforce Operating System
Enterprise-grade, multi-tenant, offline-first HCM SaaS for Ethiopian organizations.

---

## Stack (v1.0 — Locked)

> **This table describes the VPS-era v1.0 stack, and four of its rows are no
> longer what ships.** It is kept because it is the locked v1.0 specification,
> not because it is a current inventory. What actually changed:
>
> | Row | Status |
> |---|---|
> | Cache / Queue — Redis 7+ | **Not used in production.** `SHARED_HOSTING_AUDIT.md` §B removed Redis for the shared-hosting target; the queue runs on the `database` driver. Coupling is configuration-only — `audit/BASELINE.md` §6 found no application code calling Redis. |
> | Horizon | **Removed entirely**, not merely undeployed — `cdf85d1`. It is absent from `composer.json` and `composer.lock`, and the lockfile carries zero hard `ext-pcntl`/`ext-posix` requires (re-verified 2026-09-18). The old reason given here — that it *would* abort `composer install --no-dev` — described a dependency that no longer exists; `BASELINE.md` §15 row 6 had recorded the resolution while §3a still read as live. |
> | Real-time — Reverb | **Not deployed.** `BROADCAST_CONNECTION=log` in production. |
> | File Storage — MinIO | **Not deployed** for the shared-hosting target; the `local` disk serves documents through signed `temporaryUrl()` routes. |
>
> `Infrastructure` likewise still says "Ethiopian VPS"; the production target is
> Ethio Telecom shared hosting under Plesk. The VPS assets are kept only until
> that cutover is verified — see `deployment/GATE-0-RESULT.md`.

| Layer | Technology | Notes |
|---|---|---|
| Backend | Laravel 12, PHP 8.2 | Sanctum, Horizon, Reverb |
| Database | MariaDB 10.11 | SQLite in-memory for tests |
| Cache / Queue | Redis 7+ | Horizon for queue dashboard |
| Frontend | Next.js 16, React 19, TypeScript strict | Tailwind CSS 4, shadcn/ui |
| Forms | React Hook Form + Zod | Server + client validation |
| Data Fetching | TanStack Query v5 | Optimistic updates per policy |
| Tables | TanStack Table v8 | Enterprise DataTable foundation |
| File Storage | MinIO | S3-compatible, self-hosted |
| Real-time | Reverb (WebSocket) | In-app notifications, device status |
| Mobile | PWA (v1.0) | Flutter deferred to v2.0 |
| Testing | Pest (PHP), Vitest (TS), Playwright (E2E) | Contract tests via OpenAPI types |
| Infrastructure | Docker, Nginx, Supervisor | Ethiopian VPS compatible |
| PDF | DomPDF | Payslips, reports |
| SMS | Interface-based | LogSms (dev), EthioTelecom (prod) |

**Rule:** No paid API dependencies. Everything self-hosted or free-tier.

---

## 15 Non-Negotiable Conventions

These are hard constraints on every slice. No exceptions.

| # | Convention | Detail |
|---|---|---|
| 1 | Tenant isolation | `tenant_id` on all scoped tables. `BelongsToTenant` trait with a **fail-closed** global scope (no tenant context applies `whereRaw('0 = 1')`, so absence yields no rows rather than all rows). `TenantIsolationTest` validates every model — run by `./scripts/gates.sh`, not by CI, which does not exist yet. Every `withoutGlobalScope` bypass must re-apply a tenant predicate; ~147 sites do, nothing enforces it. |
| 2 | UTC storage | Store all timestamps in UTC. Display in EAT (Africa/Addis_Ababa, UTC+3). Ethiopia does not observe DST — the +3 offset is constant. |
| 3 | Integer currency | ETB stored as `BIGINT` minor units (cents). Never use `FLOAT` or `DECIMAL`. Format: `X,XXX.XX ETB`. Use `formatETB(cents)` helper everywhere. |
| 4 | ULID public IDs | `BIGINT` auto-increment PK (internal). `CHAR(26)` ULID `public_id` (API-facing). Never expose numeric PK in any API response. |
| 5 | Immutable audit log | Append-only `audit_log` table for all sensitive operations. `AuditLog::record()` helper. Never update or delete audit records. |
| 6 | FormRequest validation | All validation in dedicated `FormRequest` classes. No inline `$request->validate()`. |
| 7 | Policy authorization | Every controller action authorized via `Policy` or `Gate`. No unprotected endpoints. Use `$user->hasPermission()` not `$user->role ===`. |
| 8 | RFC-7807 errors | All API errors return `{ type, title, status, detail, errors? }` as `application/problem+json`. |
| 9 | i18n keys only | No hardcoded strings. Translation keys for all user-facing text. Ship `en` + `am`. Architecture supports `om`, `ti`, `so`, `sid`. |
| 10 | Idempotency keys | All write operations on attendance, payroll, and field data require `Idempotency-Key` header. Replay returns `was_duplicate: true`. |
| 11 | Payroll audit trail | Every `PayrollEntry` must store a `calculation_log` JSON with the full pipeline trace: inputs, intermediate values, applied rules, and outputs at each step. Immutable after approval. |
| 12 | Semantic color tokens | Never use Tailwind color values directly. Always reference semantic CSS variables (`--color-surface-primary`, `--color-text-secondary`). |
| 13 | QueryBoundary pattern | Every data-fetching component must handle all four states: loading (skeleton), empty (CTA), error (retry), success (data). Use `<QueryBoundary>` wrapper. |
| 14 | Soft delete policy | Follow the entity-level soft delete policy in this document. Never hard-delete employees, attendance, payroll, or audit logs. |
| 15 | File content verification | After upload, verify file magic bytes match declared content-type. Strip EXIF from images. |

---

## Soft Delete Policy

| Entity | Strategy | Reason |
|---|---|---|
| Employees | Soft delete | Legal requirement to retain records |
| Attendance Records | Never delete | Audit requirement |
| Payroll Entries | Never delete | Financial audit requirement |
| Payroll Runs | Never delete (can void) | Financial audit requirement |
| Leave Requests | Soft delete | Historical reference |
| Departments | Soft delete | Historical employee assignments |
| Branches | Soft delete | Historical attendance records |
| Positions / Grades | Soft delete | Historical employee assignments |
| Documents | Soft delete | May be referenced in legal/payroll |
| Shifts | Soft delete | Historical attendance matching |
| Devices | Soft delete | Sync log references |
| Notifications | Hard delete after 90 days | Storage management |
| Audit Logs | Never delete | Compliance requirement |
| Webhook Deliveries | Hard delete after 30 days | Storage management |
| Import staging data | Hard delete after 7 days | Temporary data |

Enforce via a custom PHPStan rule that checks all models against this table.

---

## Optimistic UI Policy

| Action | Optimistic? | Reason |
|---|---|---|
| Mark notification as read | Yes | No downstream effects |
| Toggle sidebar state | Yes | UI-only |
| Edit employee profile fields | Yes (with rollback) | Low-risk, high-frequency |
| Change department/branch | Yes (with rollback) | Org change, reversible |
| Check in attendance | Yes (with rollback) | User expects instant feedback |
| Approve leave request | No | Has payroll/balance implications |
| Run payroll | No | Irreversible financial operation |
| Delete any entity | No | Destructive action |
| Transition employee status | No | Has cascading effects |
| Process bulk import | No | Complex, needs server validation |

For optimistic actions: show success immediately, display "Undo" toast with 5-second window, rollback on server error.

---

## Rate Limiting Policy

| Endpoint | Limit | Reason |
|---|---|---|
| `POST /auth/login` | 5/min per IP | Brute force prevention |
| `POST /auth/otp/request` | 3/min per phone | SMS cost control |
| `POST /payroll/process` | 2/hour per tenant | Expensive operation |
| `POST /employees/import/*` | 5/hour per tenant | Resource intensive |
| `GET /dashboard/*` | 30/min per user | Heavy queries |
| `POST /webhooks/*/test` | 10/hour per tenant | Abuse prevention |
| All write endpoints (trial) | 60/min per tenant | Trial tier |
| All write endpoints (paid) | 300/min per tenant | Paid tier |
| All read endpoints (trial) | 120/min per tenant | Trial tier |
| All read endpoints (paid) | 600/min per tenant | Paid tier |

All throttled responses must include `Retry-After` header.

---

## Visual Identity (ETHR Design System)

### Colors

```
Primary:          #0F4C75   Deep Teal Blue — authority, trust
Primary Light:    #3282B8   Interactive blue — buttons, links
Primary Dark:     #0A2E4A   Sidebar, headers
Accent:           #E8A838   Ethiopian Gold — highlights, badges
Success:          #059669   Green
Warning:          #D97706   Amber
Destructive:      #DC2626   Red
Neutral 50:       #F8FAFC   Page background
Neutral 100:      #F1F5F9   Card background
Neutral 200:      #E2E8F0   Borders
Neutral 500:      #64748B   Secondary text
Neutral 900:      #0F172A   Primary text
```

### Typography

```
Display/Body:     Inter (400, 500, 600, 700)
Amharic:          Noto Sans Ethiopic (400, 500, 600, 700)
Monospace:        JetBrains Mono (employee codes, amounts, timestamps)
```

### Spacing Scale (4px base)

4, 8, 12, 16, 20, 24, 32, 40, 48, 64, 80, 96

### Border Radius

4px (inputs), 6px (cards), 8px (modals), 12px (panels), 9999px (pills/badges)

### Elevation

```
Level 0: none                                    Flat cards in content area
Level 1: 0 1px 3px rgba(0,0,0,0.08)             Dropdowns, popovers
Level 2: 0 4px 12px rgba(0,0,0,0.10)            Modals, dialogs
Level 3: 0 8px 24px rgba(0,0,0,0.12)            Command palette, toast stack
```

### Signature Element

Ethiopian geometric textile motif (tilf/tibeb pattern) as a subtle decorative border at the sidebar footer and as section dividers on the marketing site. This single cultural element makes the product unmistakably Ethiopian.

---

## Semantic Color Tokens (CSS Variables)

```css
:root {
  --color-surface-primary: #FFFFFF;
  --color-surface-secondary: #F8FAFC;
  --color-surface-elevated: #FFFFFF;
  --color-surface-sidebar: #0A2E4A;
  --color-text-primary: #0F172A;
  --color-text-secondary: #64748B;
  --color-text-inverse: #FFFFFF;
  --color-text-on-sidebar: #E2E8F0;
  --color-border-default: #E2E8F0;
  --color-border-strong: #CBD5E1;
  --color-interactive-primary: #0F4C75;
  --color-interactive-hover: #3282B8;
  --color-interactive-focus: #0F4C75;
  --color-accent: #E8A838;
  --color-status-success: #059669;
  --color-status-warning: #D97706;
  --color-status-error: #DC2626;
  --color-status-info: #0284C7;
}

[data-theme="dark"] {
  --color-surface-primary: #0F172A;
  --color-surface-secondary: #1E293B;
  --color-surface-elevated: #1E293B;
  --color-surface-sidebar: #020617;
  --color-text-primary: #F1F5F9;
  --color-text-secondary: #94A3B8;
  --color-text-inverse: #0F172A;
  --color-text-on-sidebar: #CBD5E1;
  --color-border-default: #334155;
  --color-border-strong: #475569;
  --color-interactive-primary: #3282B8;
  --color-interactive-hover: #60A5FA;
  --color-interactive-focus: #3282B8;
  --color-accent: #FBBF24;
  --color-status-success: #34D399;
  --color-status-warning: #FBBF24;
  --color-status-error: #F87171;
  --color-status-info: #38BDF8;
}
```

Never use raw Tailwind colors (e.g., `text-blue-600`). Always map to semantic tokens.

---

## Sidebar Navigation Structure (Role-Based)

```
OVERVIEW
  Dashboard

PEOPLE
  Employees
  Organization
  Directory

OPERATIONS
  Attendance
  Shifts & Schedules
  Devices
  Leave

FINANCE
  Payroll
  Reports

ADMIN (tenant_admin, hr_admin only)
  Settings
  Audit Log
  Billing

Bottom:
  [Ethiopian tibeb pattern border]
  Command Palette (⌘K)
  User menu
  Language switcher
  Dark mode toggle
```

Items show notification count badges where applicable (Approvals, Attendance anomalies).

Mobile: bottom tab bar (Home, Attendance, Leave, Payslips, More) — not sidebar.

---

## Form Pattern Library

| Pattern | When to Use | Example |
|---|---|---|
| Inline form | 1-5 fields, single concern | Edit branch name |
| Dialog form | 3-8 fields, create/edit | Add emergency contact |
| Page form (tabbed) | 8+ fields, multiple concerns | Employee create/edit |
| Wizard form | Sequential steps, dependent data | Onboarding setup |
| Inline edit | Single field on a list row | Quick department reassignment |
| Drawer form | 5-12 fields, context-preserving | Leave request (keep calendar visible) |

Rules for all forms:

- Submit/cancel buttons: always bottom-right, primary action on the right.
- Validation: inline under fields, server error summary at top.
- Auto-save for page forms and wizards: every 30 seconds, draft state.
- Unsaved changes warning on navigation.
- Keyboard shortcuts: Enter to submit dialogs, Escape to cancel.

---

## Ethiopian Calendar Rules

- Pagumen (13th month, 5-6 days): payroll proration configurable per tenant (`full_month` or `daily_rate`).
- Fiscal year start: configurable per tenant (Hamle 1 for government, Meskerem 1 for private).
- Ethiopian New Year: Meskerem 1 = September 11 (or 12 in leap years).
- Ethiopia does not observe DST. UTC+3 is constant year-round.
- All date pickers: support dual calendar display (Gregorian + Ethiopian) when tenant enables it.
- Leave day counting: must handle leave spanning Pagumen correctly.
- Amharic line-height: use 1.6-1.8 (vs 1.5 for Latin text).

---
# Build Lifecycle

Every task must follow this lifecycle.

Do not skip any step.

Do not mark a task complete until every validation passes.

```
Read CLAUDE.md
       |
Read relevant phase document(s)
       |
Analyze current code
       |
Verify existing implementation
       |
Determine completed vs missing work
       |
Understand architecture + dependencies
       |
Implement ONE feature slice
       |
Backend
(migration → model → service → controller → FormRequest → Policy → tests)
       |
Frontend
(types → API hooks → components → pages → tests)
       |
Run formatter
(Pint + Prettier)
       |
Run static analysis
(PHPStan + TypeScript)
       |
Run Pest tests
       |
Run Vitest tests
       |
Run TenantIsolationTest
(if applicable)
       |
Verify Browser
Desktop
Tablet
Mobile
Dark
Light
       |
Fix issues
       |
Re-run validation
       |
Update documentation
       |
Output:
Files Changed
Summary
Remaining Tasks
Blockers
       |
Continue to next highest-priority unfinished feature
```
## Completion Report 

After every completed task output ONLY:

Files changed

Summary

Validation performed

Remaining tasks

Known blockers

Do not output Git commands.

Do not generate commit messages.

Do not reference repositories.
```

---

## Quality Gates

No slice ships without all checks passing.

**Run them with `bash scripts/gates.sh`, not by hand.** It runs all nine
(Pint, PHPStan, Pest, i18n, Prettier, ESLint, tsc, Vitest, API-contract), keeps
going after a failure so one run reports all the damage, and applies the
bind-mount workarounds below automatically. The itemised list that follows is
the *rationale* for each gate — the script is the enforcement. Anything listed
here but not wired into the script is not a gate: Prettier and ESLint sat in
this list unenforced until 2026-08-24, which is how a release audit, rather
than a gate, is what caught `prettier --check` failing on 36 files.

Performance budgets are separate and opt-in: `bash scripts/gates.sh performance`.

- [ ] `bash scripts/pest-isolated.sh` — all green. **Not `php artisan test` or a
      bare `vendor/bin/pest`**: over the Windows bind mount PHP's recursive
      directory scan silently collects a fraction of the suite (measured
      2026-08-21: 22 of 132 test classes) and still exits 0 with a green
      summary. `scripts/gates.sh` now fails on that undercount instead of
      reporting success; this script runs the suite where collection is whole.
- [ ] `npx vitest run` — all green
- [ ] `bash scripts/phpstan-isolated.sh` — level 6, zero errors. Not
      `vendor/bin/phpstan` directly: over the bind mount Larastan sees half the
      migrations, and the missing tables become ~990 phantom "undefined
      property" errors that drowned this gate for months.
- [ ] `./vendor/bin/pint --test` — no formatting issues
- [ ] `npx prettier --check src/` — no formatting issues
- [ ] `npx tsc --noEmit` — zero type errors
- [ ] Every endpoint has a `FormRequest`
- [ ] Every endpoint has a `Policy` using `hasPermission()`
- [ ] Sensitive operations write to `audit_log`
- [ ] Payroll operations store `calculation_log`
- [ ] All user-facing strings use i18n keys (`en` + `am`)
- [ ] Responsive: mobile (375px), tablet (768px), desktop (1280px+)
- [ ] Dark mode + light mode verified (semantic tokens only)
- [ ] Error states, loading states, empty states handled via `QueryBoundary`
- [ ] Browser console clean (no errors, no warnings)
- [ ] File uploads verify magic bytes
- [ ] New models have `BelongsToTenant` (unless in global model list)
- [ ] `TenantIsolationTest` passes for any new model
- [ ] API response types match generated OpenAPI TypeScript types

---

## API Conventions

```
Base:        /api/v1/
Auth:        Authorization: Bearer {sanctum_token}
Tenant:      Subdomain resolution ({tenant}.ethr.et)
Pagination:  ?page=1&per_page=25 (max per_page=100)
Filtering:   ?filter[field]=value
Sorting:     ?sort=-created_at
Includes:    ?include=department,branch
Search:      ?search=query
Idempotency: Idempotency-Key: {uuid} (on all write endpoints)
```

Response shapes:

Single resource — flat JSON (no wrapper):
```json
{ "public_id": "01HXYZ...", "name": "Abebe Kebede", ... }
```

Paginated collection — `{ data, meta, links }`:
```json
{
  "data": [...],
  "meta": { "current_page": 1, "last_page": 5, "per_page": 25, "total": 123 },
  "links": { "next": "...", "prev": null }
}
```

Error — RFC-7807:
```json
{ "type": "validation_error", "title": "Validation Failed", "status": 422, "detail": "...", "errors": {...} }
```

TypeScript types for all responses generated from OpenAPI spec via `openapi-typescript`.

---

## Testing Rules

**Backend (Pest):**
- SQLite `:memory:` for speed
- `RefreshDatabase` trait on all test classes
- Full URL for host-based tests: `$this->getJson('http://acme.ethr.et/api/v1/...')`
- `$this->app['auth']->forgetGuards()` between requests that change auth state
- Assert `assertJsonMissingPath('id')` — never leak numeric PKs
- No test-only migration files in `database/migrations/`
- One test file per feature/controller
- Seed 1000 employees for performance benchmark tests
- Include Ethiopian edge cases: Pagumen proration, tax bracket boundaries, Ramadan shifts

**Frontend (Vitest):**
- React Testing Library for component tests
- MSW for API mocking using generated OpenAPI types (contract testing)
- Test user-visible behavior, not implementation
- Include Amharic text in snapshot tests to catch truncation bugs
- Test all four QueryBoundary states (loading, empty, error, success)

**E2E (Playwright — Phase 9):**
- Critical path flows: signup → onboarding → attendance → payroll
- Run against Docker staging environment
- Test at 375px and 1280px viewport widths

**Tenant Isolation (run by `./scripts/gates.sh` — there is no CI yet):**
- `TenantIsolationTest` dynamically discovers all Eloquent models
- Asserts every tenant-scoped model has `tenant_id` column
- Asserts cross-tenant queries return empty results
- Asserts no model with `tenant_id` is missing `BelongsToTenant` trait

---

## Repository Structure

```
/api                    Laravel 12 backend
  /app
    /Models
    /Http/Controllers
    /Http/Requests       FormRequest classes
    /Http/Resources      API Resources (JsonResource)
    /Policies
    /Services
    /Events
    /Listeners
    /Jobs
    /Enums
    /Traits              BelongsToTenant, HasPublicId, HasPermissions, etc.
    /Rules               Custom validation rules (PHPStan, TenantIsolation)
  /database
    /migrations
    /seeders
    /factories
  /routes
    api.php
  /tests
    /Feature
    /Unit
    /Security            TenantIsolationTest, PermissionTest
    /Performance         ResponseTimeTest
  /config
  /lang
    /en                  Full coverage (15+ files)
    /am                  Full coverage (15+ files)

/src                    Next.js 16 frontend (the real source is one level down, in src/src)
  /app                  App router pages
    /(auth)              Auth layout pages (login, register, verify)
    /(dashboard)         Dashboard layout pages (all authenticated views)
    /(marketing)         Marketing layout pages (landing, pricing)
    /(onboarding)        Onboarding layout pages (setup wizard)
    /kiosk               Kiosk mode (standalone layout)
    /offline             Offline fallback page
  /components
    /ui                 shadcn/ui primitives
    /shared             Reusable business components
    /layouts            Layout components (Dashboard, Auth, Marketing, Onboarding)
    /patterns           QueryBoundary, FormPatterns, DataTable
  /features             Feature-scoped modules
    /{feature}
      /components
      /hooks
      /api.ts           TanStack Query hooks
      /types.ts
  /api
    /client.ts          Axios instance + interceptors
    /types              Generated OpenAPI TypeScript types
  /lib
    /i18n               Internationalization
    /calendar           Ethiopian calendar utilities
    /offline            IndexedDB queue, service worker registration
    /hooks              Shared hooks (useOfflineStatus, useDebounce, etc.)
    /utils
  /styles               Tailwind config, semantic tokens, global CSS
  /public
    /sw.js              Service worker
    /manifest.json      PWA manifest

/docker                 Docker Compose + service configs
/docs                   Generated API documentation
/scripts                Build, deploy, seed, backup scripts
/infrastructure         Nginx, Supervisor, SSL configs
```

---

## File Naming

| Context | Convention | Example |
|---|---|---|
| PHP class | PascalCase | `EmployeeController.php` |
| PHP test | PascalCase + Test suffix | `EmployeeControllerTest.php` |
| PHP security test | PascalCase + Test suffix | `TenantIsolationTest.php` |
| Migration | snake_case with timestamp | `2024_01_01_000001_create_employees_table.php` |
| TS component | PascalCase | `EmployeeCard.tsx` |
| TS hook | camelCase with `use` prefix | `useEmployees.ts` |
| TS type file | camelCase | `employee.ts` |
| TS API hook file | `api.ts` per feature | `features/employees/api.ts` |
| TS pattern component | PascalCase | `QueryBoundary.tsx` |
| CSS variable | kebab-case with prefix | `--color-surface-primary` |
| Translation key | dot-notation | `employee.profile.title` |

---

## Commit Convention

```
type(scope): short description

- feat(attendance): add biometric device registration
- fix(payroll): correct overtime calculation for night shifts
- refactor(auth): extract token refresh into middleware
- test(leave): add approval workflow integration tests
- test(security): add tenant isolation sweep for new models
- docs(api): update attendance endpoint documentation
- perf(employees): add fulltext index for search
- style(dashboard): apply semantic color tokens
```

---

## Security Checklist (Per Slice)

- [ ] No SQL injection (Eloquent only, no raw queries without bindings)
- [ ] No XSS (React auto-escapes; never use `dangerouslySetInnerHTML`)
- [ ] No mass assignment (explicit `$fillable` on every model)
- [ ] CSRF protection on all state-changing requests
- [ ] Tenant data never leaks across tenants (TenantIsolationTest passes)
- [ ] File uploads validated (type, size, magic bytes, EXIF stripped)
- [ ] Sensitive data encrypted at rest (bank details, TIN, TOTP secrets)
- [ ] Passwords hashed with bcrypt (Laravel default)
- [ ] API tokens scoped and expirable
- [ ] Webhook URLs validated (no internal/private IPs)
- [ ] Impersonation actions logged with `impersonated_by` field
- [ ] Permissions checked via `hasPermission()`, not role string comparison

---

## Performance Targets

| Metric | Target |
|---|---|
| API response (p95) | < 200ms |
| Dashboard page load | < 1.5s |
| Employee list (1000 rows) | < 500ms |
| Employee search (fulltext) | < 100ms |
| Payroll calculation (500 employees) | < 30s |
| Attendance sync (batch 100) | < 5s |
| Time to First Contentful Paint | < 1.5s |
| Largest Contentful Paint | < 2.5s |
| First Input Delay | < 100ms |
| Cumulative Layout Shift | < 0.1 |
| Lighthouse Performance score | > 80 |
| Lighthouse Accessibility score | > 90 |

---

## Deployment Compatibility

Target environments:
- Ubuntu Server 22.04+
- Docker Engine 24+
- Docker Compose v2
- Nginx reverse proxy
- Let's Encrypt TLS
- Redis 7+
- MariaDB 10.11+
- Supervisor (process management)
- PHP-FPM 8.2
- Node.js 20 LTS

No cloud-provider-specific services. Must run on:
- Ethiopian VPS / hosting providers
- Local data centers
- Private servers
- International cloud (AWS, DO, Hetzner) as optional upgrade

---

## Global Model List (No Tenant Scope)

These models are global and must NOT have `tenant_id` or `BelongsToTenant`:

- `Tenant` — the tenant itself
- `Plan` — subscription plans (global catalog)
- `FeatureFlag` — when `tenant_id` is null (global flag)
- `SuperAdmin` — platform administrators

All other models MUST have `tenant_id` and use `BelongsToTenant`. The `TenantIsolationTest` enforces this.

---

## Queue Failure Recovery

| Queue | After All Retries Fail |
|---|---|
| `attendance` | Log to `failed_jobs`, `Queue::failing` alert (see below) |
| `payroll` | Mark payroll run as `failed` with error details, notify tenant admin |
| `devices` | Trigger `DeviceOffline` event, notify admin |
| `notifications` | Log failure, do not retry (notification is stale) |
| `exports` | Mark export as `failed`, notify requesting user |
| `sync` | **Not queued** — offline sync is synchronous, see below |
| `default` | Log to `failed_jobs`, surface in admin dashboard |

All failed jobs visible in Super Admin dashboard with retry/dismiss actions.

**Every** queue additionally goes through the `Queue::failing` hook in
`AppServiceProvider::boot()`: a `Log::error` carrying job name, queue,
connection, attempt count and exception message, plus a Sentry report when that
binding is present. It exists because a retry exhausting itself looks exactly
like nothing happening — `ScanAttendanceAnomaliesJob` failed on every scheduled
run and was found only by opening the health endpoint for an unrelated reason.

**There is no `failed_syncs` table.** This table was specified here and in
`ARCHITECTURE.md`/`DATABASE.md` but never built, and the two rows above claimed
a recovery mechanism that does not exist (audit F-5, 2026-08-23). The reason is
that offline attendance sync **is not a queue at all**: `OfflineSyncController`
processes the batch synchronously and returns a per-record
`created` / `duplicate` / `error` result plus a summary, so a record that fails
is reported to the caller in the response that asked for it, not swallowed into
a recovery table. Every record is idempotency-keyed and the client keeps its
IndexedDB queue, so the correct recovery is the client retrying — replay
returns `duplicate` rather than double-punching (convention #10).

---

# Auto Resume Protocol

If generation stops for any reason:

- rate limit
- internet disconnect
- browser refresh
- power outage
- token limit

then the NEXT user message:

Continue

means:

1. Reload entire CLAUDE.md
2. Read all completed work
3. Determine unfinished task
4. Continue exactly where stopped
5. Do not repeat completed work
6. Do not ask unnecessary questions
7. Continue until task completes or another interruption occurs.

---


# For Every New Session

Always begin by understanding the existing implementation.

Execution order:

1. Read CLAUDE.md.
2. Read PRODUCT_VISION.md.
3. Read ARCHITECTURE.md.
4. Read PRD.md.
5. Read all relevant phase documents. For onboarding / device / migration / identity / login work, also read ONBOARDING_V2.md, INDUSTRY_TEMPLATES.md, DEVICE_INTEGRATION.md, IDENTITY_RESOLUTION.md, MIGRATION.md, and FRONTEND.md.
6. Analyze the existing codebase.
7. Compare implementation with documentation.
8. Identify completed work.
9. Identify unfinished work.
10. Continue from the highest-priority unfinished task.

Never restart completed work.

Never rewrite production-ready modules unless required.

Always verify implementation from code before making decisions.

# Enterprise Codebase Audit Protocol

Before implementing any feature:

1. Inspect the current implementation.

2. Determine:

- Complete
- Partial
- Missing
- Deprecated

3. Compare against:

- Product Vision
- Architecture
- Phase Documents

4. Produce:

Current completion

Missing work

Dependencies

Technical debt

Security issues

Performance issues

UI issues

5. Continue from the highest-priority unfinished feature.

Never assume documentation equals implementation.

The source of truth is always the existing code.