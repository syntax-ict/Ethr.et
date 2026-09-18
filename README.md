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
- **Self-hostable on an Ethiopian VPS.** No cloud-provider-specific services and
  no paid API dependencies; everything is self-hosted or free-tier.

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 · PHP 8.2 (Sanctum, Horizon, Reverb) |
| Frontend | Next.js 16 · React 19 · TypeScript strict · Tailwind 4 · shadcn/ui |
| Database | MariaDB 10.11 (SQLite in-memory for tests) |
| Cache / queue / session | Redis 7 |
| Storage | MinIO (S3-compatible) |
| Real-time | Reverb (WebSocket) |
| Mobile | PWA |
| Testing | Pest · Vitest · Playwright |
| Infrastructure | Docker · Nginx · Supervisor |

Measured 2026-09-18: **104 controllers, 70 models, 62 migrations** under
`/api/v1`, covered by **1796 backend tests (5298 assertions)** across 166 Pest
files, plus 85 Vitest files and 13 Playwright specs. Every figure here was
counted on this commit, not carried over; the command below reproduces the
backend one.

Reproduce the backend figure rather than trusting this line — it is only true
on the day it was written:

```bash
cd api && php -d memory_limit=-1 vendor/bin/pest --compact
```

Check the collected class count against `find api/tests -name '*Test.php'`
before believing any green run; see the root [`CLAUDE.md`](CLAUDE.md) for why.

## Repository layout

```
api/              Laravel backend
src/              Next.js frontend (note: the app root is src/, so build
                  output lives at src/.next/)
docker/           Service images and configs (php, nginx, mariadb, frontend)
infrastructure/   Production nginx + supervisor configs
scripts/          deploy, backup, restore, rollback, seed, quality gates
docs/             Architecture, PRD, phase design records, audits, roadmap
```

## Getting started

Local development runs **infrastructure in Docker, the application natively**.
Follow [`docs/LOCAL_SETUP.md`](docs/LOCAL_SETUP.md) — it is maintained and
covers the parts that are easy to get wrong. In short:

```bash
docker compose up -d mariadb redis minio mailpit
```

MariaDB is published on **3307**, not 3306. The MinIO `ethr` bucket must be
created once or every upload fails. Then run four processes, all of which are
required — `php artisan serve`, `php artisan queue:work`,
`php artisan reverb:start`, and the Next dev server. Without the queue worker
jobs never run; without Reverb, broadcasts throw and a *successful* write can
return 500.

Seeded demo tenant: `demo`, users `admin@` / `hr@` / `emp@demo.ethr.et` and
`superadmin@ethr.et`, all with password `password`.

## Quality gates

No change ships without all of these passing:

```bash
bash scripts/pest-isolated.sh                       # backend suite — see below
bash scripts/phpstan-isolated.sh                    # level 6, 0 errors — see below
cd api && ./vendor/bin/pint --test
cd src && node node_modules/typescript/bin/tsc --noEmit
cd src && node node_modules/vitest/vitest.mjs run
bash scripts/api-types-check.sh                     # OpenAPI contract drift
```

`scripts/gates.sh` runs the set, and is the recommended way to run it: on a
Windows/Docker Desktop checkout there is no native `php` — it exists only in the
`et-api-1` container — so the `pint`, Pest and contract gates above fail with
`php: command not found` when run by hand. `gates.sh` (and
`scripts/api-types-check.sh`, and `npm run generate:api`) use a native `php` when
one is present and fall back to the container when it is not, so the same command
works on Linux CI and on a Windows workstation.

Three gotchas worth knowing up front:

- **Run the backend suite through `scripts/pest-isolated.sh`, not
  `vendor/bin/pest` directly.** PHP's recursive directory scan is lossy over a
  Docker Desktop Windows bind mount: `pest` collected 22 of 132 test classes,
  ran them, and exited 0 with a green summary. `gates.sh` now compares the
  collected class count against `find` and fails loudly on an undercount, so a
  direct run refuses rather than lying; the script copies `api/` into the
  container's own filesystem, where collection is complete.
- **Run PHPStan through `scripts/phpstan-isolated.sh` too.** Larastan builds its
  model schema by parsing migrations, enumerating them with the same lossy
  directory API: it sees 25 of 52 migrations over the mount, and the tables it
  misses turn into ~990 phantom "undefined property" errors. Off the mount the
  same codebase reports 0 at level 6.
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
| [`DEPLOYMENT.md`](docs/DEPLOYMENT.md) · [`SECURITY.md`](docs/SECURITY.md) | Production deployment and security posture |
| [`LOCALIZATION.md`](docs/LOCALIZATION.md) · [`DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md) | i18n and the design system |
| [`audit/BASELINE.md`](docs/audit/BASELINE.md) | **Measured state of the codebase** — what is verified vs merely documented |
| [`deployment/GATE-0-RESULT.md`](docs/deployment/GATE-0-RESULT.md) | Plesk hosting verification — every row still `NOT VERIFIED` |
| `PHASE_00.md` – `PHASE_09.md` | Original design records — **their checkboxes are not maintained** |

The phase documents are specifications, not progress trackers; their checkboxes
badly under-report what is built. For status, read the roadmap or the code.

## Licence

Proprietary. All rights reserved.
