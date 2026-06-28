# ETHR — Global Development Rules

## Identity

**ETHR** — Ethiopian Workforce Operating System
Enterprise-grade, multi-tenant, offline-first HCM SaaS for Ethiopian organizations.

---

## Stack (v1.0 — Locked)

| Layer | Technology | Notes |
|---|---|---|
| Backend | Laravel 12, PHP 8.2 | Sanctum, Horizon, Reverb |
| Database | MariaDB 10.11 | SQLite in-memory for tests |
| Cache / Queue | Redis 7+ | Horizon for queue dashboard |
| Frontend | Next.js 15, React 19, TypeScript strict | Tailwind CSS 4, shadcn/ui |
| Forms | React Hook Form + Zod | Server + client validation |
| Data Fetching | TanStack Query v5 | Optimistic updates where safe |
| File Storage | MinIO | S3-compatible, self-hosted |
| Real-time | Reverb (WebSocket) | In-app notifications, device status |
| Mobile | PWA (v1.0) | Flutter deferred to v2.0 |
| Testing | Pest (PHP), Vitest (TS), Playwright (E2E) | |
| Infrastructure | Docker, Nginx, Supervisor | Ethiopian VPS compatible |
| PDF | DomPDF | Payslips, reports |
| SMS | Interface-based | LogSms (dev), EthioTelecom (prod) |

**Rule:** No paid API dependencies. Everything self-hosted or free-tier.

---

## 10 Non-Negotiable Conventions

These are hard constraints on every slice. No exceptions.

| # | Convention | Detail |
|---|---|---|
| 1 | Tenant isolation | `tenant_id` on all scoped tables. `BelongsToTenant` trait with global scope. |
| 2 | UTC storage | Store all timestamps in UTC. Display in EAT (Africa/Addis_Ababa, UTC+3). |
| 3 | Integer currency | ETB stored as `BIGINT` minor units (cents). Never use `FLOAT` or `DECIMAL`. Format: `X,XXX.XX ETB`. |
| 4 | ULID public IDs | `BIGINT` auto-increment PK (internal). `CHAR(26)` ULID `public_id` (API-facing). Never expose numeric PK. |
| 5 | Immutable audit log | Append-only `audit_log` table for all sensitive operations. `AuditLog::record()` helper. |
| 6 | FormRequest validation | All validation in dedicated `FormRequest` classes. No inline `$request->validate()`. |
| 7 | Policy authorization | Every controller action authorized via `Policy` or `Gate`. No unprotected endpoints. |
| 8 | RFC-7807 errors | All API errors return `{ type, title, status, detail, errors? }` as `application/problem+json`. |
| 9 | i18n keys only | No hardcoded strings. Translation keys for all user-facing text. Ship `en` + `am`. Architecture supports `om`, `ti`, `so`, `sid`. |
| 10 | Idempotency keys | All write operations on attendance and field data require `Idempotency-Key` header. Replay returns `was_duplicate: true`. |

---

## Build Lifecycle

Every slice follows this cycle. Do not mark complete until all steps pass.

```
Read phase document
       |
Understand architecture + dependencies
       |
Implement backend (migration -> model -> service -> controller -> FormRequest -> Policy -> tests)
       |
Implement frontend (types -> API hooks -> components -> pages -> tests)
       |
Run formatter (pint backend, prettier frontend)
       |
Run static analysis (phpstan level 6)
       |
Run Pest tests
       |
Run Vitest tests
       |
Verify in browser (desktop + tablet + mobile + dark + light)
       |
Fix issues, re-test
       |
Update docs
       |
Commit
```

---

## Quality Gates

No slice ships without all checks passing:

- [ ] `php artisan test` — all green
- [ ] `npx vitest run` — all green
- [ ] `./vendor/bin/phpstan analyse` — level 6, zero errors
- [ ] `./vendor/bin/pint --test` — no formatting issues
- [ ] `npx prettier --check src/` — no formatting issues
- [ ] `npx tsc --noEmit` — zero type errors
- [ ] Every endpoint has a `FormRequest`
- [ ] Every endpoint has a `Policy` or `Gate`
- [ ] Sensitive operations write to `audit_log`
- [ ] All user-facing strings use i18n keys (`en` + `am`)
- [ ] Responsive: mobile (375px), tablet (768px), desktop (1280px+)
- [ ] Dark mode + light mode verified
- [ ] Error states, loading states, empty states handled
- [ ] Browser console clean (no errors, no warnings)

---

## API Conventions

```
Base:        /api/v1/
Auth:        Authorization: Bearer {sanctum_token}
Tenant:      Subdomain resolution ({tenant}.ethr.et)
Pagination:  ?page=1&per_page=25
Filtering:   ?filter[field]=value
Sorting:     ?sort=-created_at
Includes:    ?include=department,branch
Search:      ?search=query
```

- Response envelope: `JsonResource::withoutWrapping()` — flat JSON, no `{ "data": ... }` wrapper
- Paginated responses use Laravel's default `{ data, links, meta }` structure
- Error format: RFC-7807 `{ type, title, status, detail, errors? }`
- Rate limiting: 60 req/min (trial), 300 req/min (paid)
- CORS: configured per-tenant subdomain
- Versioning: URL path (`/api/v1/`), backward compatible within major version

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

**Frontend (Vitest):**
- React Testing Library for component tests
- MSW for API mocking
- Test user-visible behavior, not implementation

**E2E (Playwright — Phase 9):**
- Critical path flows: signup -> onboarding -> attendance -> payroll
- Run against Docker staging environment

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
    /Traits              BelongsToTenant, HasPublicId, etc.
  /database
    /migrations
    /seeders
  /routes
    api.php
  /tests
    /Feature
    /Unit
  /config
  /lang
    /en
    /am

/src                    Next.js 15 frontend
  /app                  App router pages
  /components
    /ui                 shadcn/ui primitives
    /shared             Reusable business components
    /layouts            Layout components
  /features             Feature-scoped modules
    /{feature}
      /components
      /hooks
      /api.ts           TanStack Query hooks
      /types.ts
  /api
    /client.ts          Axios instance + interceptors
    /types              Shared TypeScript types
  /lib
    /i18n               Internationalization
    /calendar           Ethiopian calendar utilities
    /utils
  /styles               Tailwind config, global CSS

/docker                 Docker Compose + service configs
/docs                   Generated API documentation
/scripts                Build, deploy, seed scripts
/infrastructure         Nginx, Supervisor, SSL configs
```

---

## File Naming

| Context | Convention | Example |
|---|---|---|
| PHP class | PascalCase | `EmployeeController.php` |
| PHP test | PascalCase + Test suffix | `EmployeeControllerTest.php` |
| Migration | snake_case with timestamp | `2024_01_01_000001_create_employees_table.php` |
| TS component | PascalCase | `EmployeeCard.tsx` |
| TS hook | camelCase with `use` prefix | `useEmployees.ts` |
| TS type file | camelCase | `employee.ts` |
| TS API hook file | `api.ts` per feature | `features/employees/api.ts` |
| Translation key | dot-notation | `employee.profile.title` |

---

## Commit Convention

```
type(scope): short description

- feat(attendance): add biometric device registration
- fix(payroll): correct overtime calculation for night shifts
- refactor(auth): extract token refresh into middleware
- test(leave): add approval workflow integration tests
- docs(api): update attendance endpoint documentation
```

---

## Security Checklist (Per Slice)

- [ ] No SQL injection (Eloquent only, no raw queries without bindings)
- [ ] No XSS (React auto-escapes; never use `dangerouslySetInnerHTML`)
- [ ] No mass assignment (explicit `$fillable` on every model)
- [ ] CSRF protection on all state-changing requests
- [ ] Tenant data never leaks across tenants
- [ ] File uploads validated (type, size, content)
- [ ] Sensitive data encrypted at rest where required
- [ ] Passwords hashed with bcrypt (Laravel default)
- [ ] API tokens scoped and expirable

---

## Performance Targets

| Metric | Target |
|---|---|
| API response (p95) | < 200ms |
| Dashboard page load | < 1.5s |
| Employee list (1000 rows) | < 500ms |
| Payroll calculation (500 employees) | < 30s |
| Attendance sync (batch 100) | < 5s |
| Time to First Contentful Paint | < 1.5s |

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

# For every new session
Read CLAUDE.md first.

Then read PRD.md, ARCHITECTURE.md, and all relevant documents in /et.

Analyze the current codebase.

Continue from the last completed phase.

Do not rewrite completed modules unless necessary.

Run tests and verify changes in the browser before marking the task complete.