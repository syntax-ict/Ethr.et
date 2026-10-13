# Engineering onboarding — your first day on ETHR

Written 2026-10-14 so that ETHR does not depend on one person. Read this page top to bottom;
every section links to the document that goes deeper. Where this page and the code disagree,
the code is right — say so in a PR.

## 1. What ETHR is, in one paragraph

A multi-tenant HR, attendance and payroll product for Ethiopian organisations. One Laravel 12
API (`api/`) serves every organisation; the Next.js frontend (`src/src/`) is exported to static
files. Production is **Ethio Telecom shared hosting under Plesk**: Apache + PHP-FPM + MariaDB,
no shell, no worker process. Every organisation is reached at `ethr.et/{slug}`, its subdomain,
or its own verified domain — all served by **one shared set of pages** with the organisation's
branding as data, never a page per customer.

## 2. The map

| Path | What is there |
|---|---|
| `api/` | The whole backend: models, services, controllers, FormRequests, tests (`tests/Feature`, `tests/Unit`) |
| `src/src/` | The real frontend source (`src/` is only the Next.js project root) |
| `src/src/api/generated.ts` | The typed API contract, **generated** from the backend — never edit by hand |
| `scripts/gates.sh` | Every quality gate; CI runs the same script |
| `scripts/local-production/` | Runs the production shape locally (XAMPP Apache + MariaDB on :8081) |
| `docs/` | Index at [`README.md`](README.md); conventions in [`CLAUDE.md`](CLAUDE.md) |

## 3. Run it

1. Local setup: [`LOCAL_SETUP.md`](LOCAL_SETUP.md) and the root [`CONTRIBUTING.md`](../CONTRIBUTING.md).
2. Before any PR: `./scripts/gates.sh quick`, then the suites you touched (`backend`, `frontend`).
3. The production shape: `scripts/local-production/up.sh`, then `verify.sh` must end `0 failed`.

## 4. The rules that bite

Read [`CLAUDE.md`](CLAUDE.md)'s fifteen conventions. The ones that cost newcomers a day:

- **Tenant isolation is fail-closed.** No tenant resolved means no rows. Every
  `withoutGlobalScope` bypass is pinned per file by `TenantScopeBypassInventoryTest`; adding one
  is a deliberate act that updates the inventory and the count in both CLAUDE files.
- **One shared landing template, one shared login component, branding as data,** strict
  tenant-aware routing and authorization. Never a per-organisation page.
- **Regenerate the API contract last** (`node scripts/generate-api.mjs` in `src/`), with the
  local MariaDB running — without it the script appears to succeed and changes nothing. Comments
  directly above a key in a `rules()` array or a response array are published as API docs.
- **Money is integer cents; time is stored UTC and shown in Africa/Addis_Ababa.**
- **A green run can lie** if the suite did not collect every test class — see root
  [`CLAUDE.md`](../CLAUDE.md), *A green test run can be a lie*.

## 5. How a change reaches production

1. Branch from `main`, PR, CI green (`gates.yml`), merge.
2. `release.yml` builds the `production` branch and tags `vYYYY.MM.DD.N`
   (`scripts/release-status.sh` compares local, `main`, `production` and the live `/release.json`).
3. In Plesk → Git: **Pull**, then **Deploy** (never point Plesk at `main`).
4. Release tasks through the maintenance endpoint (`migrate`, `seed`, `optimize`, `clear`):
   [`deployment/PLESK-GO-LIVE.md`](deployment/PLESK-GO-LIVE.md).
5. Check `https://<domain>/release.json` and `/api/v1/health`.

## 6. When something is on fire

[`operations/ON-CALL.md`](operations/ON-CALL.md) — symptom → cause → action.
Who holds which credential: [`operations/CUSTODY.md`](operations/CUSTODY.md).

## 7. What is proven and what is not

- Measured state of the codebase: [`audit/BASELINE.md`](audit/BASELINE.md).
- Host gates and go-live readiness: [`deployment/CUTOVER-CHECKLIST.md`](deployment/CUTOVER-CHECKLIST.md).
- Devices: the vendor adapters follow published formats but **have not met real hardware**
  ([`DEVICE_INTEGRATION.md`](DEVICE_INTEGRATION.md)).
- Payroll: worked examples pass to the cent, **not yet signed by an accountant**
  ([`payroll/ACCOUNTANT-SIGN-OFF.md`](payroll/ACCOUNTANT-SIGN-OFF.md)).
- Amharic: complete, **machine-assisted in part** ([`i18n/AMHARIC-REVIEW.md`](i18n/AMHARIC-REVIEW.md)).
