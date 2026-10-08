# ETHR — Ethiopian Workforce Operating System

Multi-tenant, offline-first HCM (human capital management) SaaS built for
Ethiopian organizations: government bureaus, universities and TVET colleges,
hospitals, manufacturing, hotels, and private enterprise.

ETHR covers the full employee lifecycle — organization structure, employee
records and personnel actions, biometric and mobile attendance, leave, payroll
with Ethiopian statutory tax and pension, reporting, and self-service portals —
with the constraints Ethiopian deployments actually impose:

- **Ethiopian calendar throughout**, including Pagumen (the 13th month) in leave
  counting and payroll proration, and a configurable fiscal year (Hamle 1 for
  government, Meskerem 1 for private).
- **Offline-first attendance.** Sites lose connectivity; check-ins queue in
  IndexedDB and sync when the network returns.
- **Amharic as a first-class language**, not an afterthought — `en` and `am` ship
  at full parity, with `om`/`ti`/`so`/`sid` supported architecturally.
- **Runs on Ethiopian shared hosting.** No cloud-provider-specific services, no
  paid API dependencies, no long-running processes; everything is self-hosted or
  free-tier. (It was "self-hostable on an Ethiopian VPS" until the VPS assets
  were removed on 2026-09-26.)

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 · PHP 8.2+ (Sanctum) |
| Frontend | Next.js 16 · React 19 · TypeScript strict · Tailwind 4 · shadcn/ui — shipped as a static export |
| Database | MariaDB / MySQL (SQLite in-memory for tests) |
| Cache / queue / session | The `database` drivers |
| Storage | Local disk, served through signed `temporaryUrl()` routes |
| Real-time | Reverb in code; **off in production** (`BROADCAST_CONNECTION=null`) — shared hosting has no WebSocket server |
| Mobile | PWA |
| Testing | Pest · Vitest · Playwright |
| Hosting | Ethio Telecom Plesk **shared hosting**: Apache + PHP-FPM, no shell, no Docker, no long-running processes; the scheduler and queue are driven over HTTP by GitHub Actions (`.github/workflows/cron.yml`) |

Measured 2026-09-30 by file count: **105 controller files, 70 models, 67 migrations**
under `/api/v1`, covered by **189 backend test classes** (2012 tests passed on
2026-09-28), **91 Vitest files** and **13 Playwright specs** — the last of which no CI
workflow runs. Reproduce the figures rather than trusting these; they drift.

Reproduce the backend figure rather than trusting this line — it is only true
on the day it was written:

```bash
cd api && php -d memory_limit=-1 vendor/bin/pest --compact
```

Check the collected class count against
`find api/tests/Unit api/tests/Feature -name '*Test.php'` before believing any
green run; see the root [`CLAUDE.md`](CLAUDE.md) for why (and why not all of
`api/tests`).

## Repository layout

```
api/              Laravel backend
src/              Next.js frontend (note: the app root is src/, so build
                  output lives at src/.next/)
scripts/          quality gates, the shared-hosting deploy, the local
                  production rehearsal, hosting verification
docs/             Architecture, PRD, phase design records, audits, roadmap
```

## Getting started

Local development runs **the same shape as production**: XAMPP's Apache,
PHP and MariaDB, no Docker. (The Docker stack was removed on 2026-09-30.)
Follow [`docs/LOCAL_SETUP.md`](docs/LOCAL_SETUP.md). In short:

```bash
scripts/local-production/up.sh        # build, assemble, migrate, start on :8081
scripts/local-production/verify.sh    # 36 checks, must end "0 failed"
```

Then open http://localhost:8081. `scripts/local-production/down.sh` stops it.

For day-to-day frontend work you can also run `php artisan serve` in `api/` and
`npm run dev` in `src/` against the same XAMPP MariaDB. Queued jobs only run
when a worker drains them — `php artisan queue:work --stop-when-empty` locally,
the GitHub Actions cron caller in production.

Seeded demo tenant: `demo`, users `admin@` / `hr@` / `emp@demo.ethr.et` and
`superadmin@ethr.et`, all with password `password`.

## Quality gates

No change ships without all of these passing:

```bash
./scripts/gates.sh            # everything — the way CI runs it
./scripts/gates.sh quick      # everything except the test suites
```

`gates.sh` runs Pint, PHPStan (level 6), Pest, i18n, Prettier, ESLint, tsc,
Vitest, the docs links, the static export and the OpenAPI contract, on native
PHP and Node. On Windows, XAMPP's PHP (`C:\xampp\php`) is enough.

Two gotchas worth knowing up front:

- **Check the collected test count, not just the colour.** PHP's recursive
  directory scan was once lossy (over a Docker Desktop bind mount, now removed):
  `pest` collected 21 of 132 test classes and exited 0 green. `gates.sh`
  compares the collected class count against `find` and fails loudly on an
  undercount — keep the checkout on a plain local disk.
- `scripts/api-types-check.sh` needs a running, fully migrated database, because
  the OpenAPI export types responses from `information_schema`.

## Documentation

Start with [`docs/CLAUDE.md`](docs/CLAUDE.md) — it holds the 15 non-negotiable
conventions (tenant isolation, UTC storage, integer currency, ULID public IDs,
immutable audit log, RFC-7807 errors, and the rest) that every change is held to.

| Document | Purpose |
|---|---|
| [`ENTERPRISE_ROADMAP.md`](docs/ENTERPRISE_ROADMAP.md) | **Live status.** What is done, partial, or missing, grounded in the code |
| [`ARCHITECTURE.md`](docs/ARCHITECTURE.md) | System design and data flow |
| [`DATABASE.md`](docs/DATABASE.md) · [`PERMISSIONS.md`](docs/PERMISSIONS.md) | Schema and the permission model |
| [`PLESK-GO-LIVE.md`](docs/deployment/PLESK-GO-LIVE.md) · [`SECURITY.md`](docs/SECURITY.md) | Production deployment (Plesk Git + the Laravel Toolkit) and security posture |
| [`LOCALIZATION.md`](docs/LOCALIZATION.md) · [`DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md) | i18n and the design system |
| [`audit/BASELINE.md`](docs/audit/BASELINE.md) | **Measured state of the codebase** — what is verified vs merely documented |
| [`deployment/GATE-0-RESULT.md`](docs/deployment/GATE-0-RESULT.md) | Plesk hosting verification — five capabilities `VERIFIED`, the rest outstanding (see its reconciliation section) |
| `PHASE_00.md` – `PHASE_09.md` | Original design records — **their checkboxes are not maintained** |

The phase documents are specifications, not progress trackers; their checkboxes
badly under-report what is built. For status, read the roadmap or the code.

## Licence

Proprietary. All rights reserved.
