# QA pass: one canonical address, verified custom domains, the `custom_domain` add-on

**2026-10-08, before go-live, on the owner's instruction.** The change under test is
[#168](https://github.com/syntax-ict/Ethr.et/pull/168) (`44b9c56c`, release `f22f7f7f`):
[`OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md`](../decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md),
*Amendment 2026-10-08*. Production is not live and is not touched.

## Harness

The **local-production rehearsal** (`scripts/local-production/up.sh`, a dedicated Apache on
`:8081` serving the real static export over the real `api/public`, PHP as CGI, MariaDB), rebuilt
from the merge. It is the production shape: the frontend calls `/api/v1` **same-origin**
(`src/src/api/client.ts`), so the API's request host is whatever host served the page. That is
what makes a custom domain testable at all, and it is also the fact the whole feature rests on.

Chromium resolves every `*.localhost` name to loopback, so with `APP_DOMAIN=ethr.localhost`:

| Role | Host |
|---|---|
| Apex (shared host, fallback tier) | `ethr.localhost:8081`, alias `www.ethr.localhost:8081` |
| Platform console | `admin.ethr.localhost:8081` (`ADMIN_HOST` at build time) |
| Subdomain tier | `acme.ethr.localhost:8081` |
| Enterprise custom domain | `hr.acme.localhost:8081` (not under `APP_DOMAIN`) |
| A host the deployment does not own | `evil.localhost:8081` |

**What the harness cannot do, stated up front:** it has no TLS and no DNS of its own. So the
*positive* Verify (TXT token and CNAME found) cannot be driven end-to-end here; it is pinned by
`AdminTenantDomainTest` with a fake resolver, and the browser exercises the negative path
against real DNS. A domain is made verified for the downstream cases by setting
`custom_domain_verified_at` directly in the rehearsal database, which is the exact write the
Verify endpoint makes.

Browser: the desktop app's built-in Chromium. API-level cases run as `fetch()` from a page on
the relevant host, so they carry that host's cookies and CSRF token, the same path the UI takes.

## Severity scale

**S1** data crosses a tenant boundary or an address is taken over · **S2** a documented flow
does not work · **S3** wrong but recoverable · **S4** cosmetic or documentation.

## Matrix

Result column: `PASS` · `FAIL(n)` → finding *n* below · `N/A` with the reason · `UNIT` = not
reachable in this harness, pinned by the named automated test.

### A. The entry URL `/{slug}`

| # | Case | Type | Expect | Result |
|---|---|---|---|---|
| A1 | Fallback-tier organisation | positive | 302 → `/login?org={slug}` | PASS |
| A2 | Organisation with a **verified** custom domain | positive | 302 → `{scheme}://hr.acme.localhost:8081/login` | PASS after F3 |
| A3 | Subdomain tier (`TENANCY_SUBDOMAINS=true`) | positive | 302 → `http://acme.ethr.localhost:8081/login` | PASS |
| A4 | Organisation with a **pending** custom domain | negative | 302 → `/login?org=`, the pending domain never used | PASS |
| A5 | Suspended organisation | boundary | same 302 as active (state not revealed) | PASS |
| A6 | Unknown slug | negative | real 404, static page, no `Set-Cookie` | PASS |
| A7 | Reserved names `dashboard`, `login`, `admin`, `en` | negative | 404 | PASS, expectation corrected (see log) |
| A8 | Upper-case `/Acme` | boundary | 404 (route pattern is lower-case) | PASS |
| A9 | Trailing slash `/acme/` | boundary | same as A1 | PASS |
| A10 | 63-character slug / 64-character slug | boundary | route matches / 404 | PASS / 404 (indistinguishable; pattern pinned by `SharedHostingHtaccessTest`) |
| A11 | `/..%2f.env`, `/acme%00` | security | 4xx, never 500, never a file | PASS |
| A12 | `POST /acme` | negative | 405 or 404, no side effect | PASS (405 / 302) |

### B. Sign-in and the canonical hand-off

| # | Case | Type | Expect | Result |
|---|---|---|---|---|
| B1 | Fallback-tier org signs in on the apex | positive | 200, dashboard loads with `X-Tenant` | PASS |
| B2 | Verified-domain org signs in on the apex | positive | 409 `canonical-address`, `canonical_url` = custom domain `/login`; the page follows it | PASS, API and the page following it |
| B3 | Same, wrong password | boundary | still 409 (hand-off precedes credentials); nothing authenticated | PASS |
| B4 | Sign-in **on** the custom domain | positive/e2e | 200; `/auth/me` 200 on the next request; dashboard shows that org's data | PASS; FAIL(2) for the page |
| B5 | Sign-in on `www.ethr.localhost` for a verified-domain org | positive | 409 as B2 | PASS |
| B6 | Sign-in on `acme.ethr.localhost` while a verified custom domain exists | positive | 409 → custom domain | PASS |
| B7 | Sign-in on `evil.localhost` (not owned) | security | no hand-off; no tenant resolved afterwards (fail-closed) | PASS (see log) |
| B8 | Super admin on the apex | positive | 200, no hand-off (no tenant) | PASS |
| B9 | Pending-domain org on the apex | negative | 200, no hand-off | PASS |
| B10 | `canonical_url` is never `javascript:`/relative | security | only `http(s)://` is followed (`login-canonical-address.test.tsx`) | UNIT |

### C. Tenant resolution by host

| # | Case | Type | Expect | Result |
|---|---|---|---|---|
| C1 | `tenant-context` on the verified custom domain | positive | names the org | PASS |
| C2 | Same on a pending domain | negative | `tenant: null`; `/employees` → 401 or empty, never another tenant | PASS |
| C3 | `HR.ACME.localhost:8081` | boundary | resolves (case, port ignored) | PASS |
| C4 | `X-Tenant: other` on the custom domain | security | ignored, host wins | PASS |
| C5 | Other org's session/token used on the custom domain | security | 403 `tenant-mismatch` | PASS |
| C6 | Assign → (verify) → first request | integration | resolves at once past the 5-minute cached miss | PASS |
| C7 | Clear the domain → next request | integration | stops resolving at once | PASS |
| C8 | Session cookie set on `hr.acme.localhost` is absent on `ethr.localhost` | security | host-only cookie | PASS |
| C9 | `acme.ethr.localhost` still resolves `acme` | regression | unchanged | PASS |
| C10 | `admin.ethr.localhost` + `X-Tenant` | security | never resolves a tenant | PASS |

### D. The console: assign, verify, clear

| # | Case | Type | Expect | Result |
|---|---|---|---|---|
| D1 | Tenant with no domain, plan without the add-on | positive | "None"; dialog shows the plan note; Save disabled | PASS |
| D2 | Assign `HTTPS://HR.Acme.localhost/` on an Enterprise tenant | positive/e2e | stored `hr.acme.localhost`, `pending`; DNS card shows TXT name, token value, CNAME → `ethr.localhost`; DB token 32 hex, `verified_at` NULL; audit `domain_changed` | PASS; audit row FAIL(1) |
| D3 | Verify, no DNS records (real DNS) | negative/e2e | 422; both `txt` and `cname` messages listed; DB unchanged | PASS |
| D4 | Domain made verified | positive | header prints the domain, Verified badge, Verify card gone | PASS |
| D5 | Re-assign a different domain | positive | token rotates, back to pending | PASS |
| D6 | Remove | positive | `null`; "None"; DB token NULL; old host stops resolving (C7) | PASS |
| D7 | Assign on a plan without the add-on, straight to the API | authorization | 422 `custom_domain_not_in_plan`; DB unchanged | PASS |
| D8 | Assign a domain another org holds, `HTTPS://HR.ACME.LOCALHOST` | negative | 422 unique | PASS |
| D9 | `ethr.localhost`, `admin.ethr.localhost`, `acme.ethr.localhost` | negative | 422 reserved | PASS |
| D10 | `acme`, `hr acme.localhost`, `-x.acme.localhost`, a 254-char name, a 64-char label | boundary | 422; nothing stored | PASS |
| D11 | Empty body | negative | 422 required; existing domain kept | PASS |
| D12 | Tenant admin → admin endpoints; unauthenticated; super admin without MFA (write) | authorization | 403 / 401 / 403 `mfa-required`; reads still 200 | PASS |
| D13 | POST without CSRF token (cookie session) | security | 419 | PASS |
| D14 | Verify with no domain | negative | 422 `custom_domain_none` | PASS |
| D15 | Verify on an already verified domain with no DNS | boundary | 200, still verified (never revokes) | PASS |
| D16 | Unknown `publicId` | negative | 404 | PASS |
| D17 | Tenant list | positive | pending domain not printed as the address; status present | PASS |
| D18 | Platform audit view | integration | `domain_changed`, `domain_verified` rows attributed to the tenant | FAIL(1) → PASS after the fix |
| D19 | `<script>` or `'` in the domain | security | 422 by the hostname rule; nothing rendered unescaped | PASS |
| D20 | Re-save the same domain | boundary | verification kept | PASS |

### E. The `custom_domain` plan feature

| # | Case | Type | Expect | Result |
|---|---|---|---|---|
| E1 | Plan editor lists *Custom domain (add-on)*; enable on Professional | positive/e2e | PUT plan → DB `features` gains it → a Professional tenant's detail says `custom_domain_allowed: true` | PASS |
| E2 | Trial tenant on a plan **with** the add-on | boundary | allowed (the plan names it) | PASS |
| E3 | Trial tenant on a plan **without** it; tenant with no subscription | negative | refused | PASS |
| E4 | `/auth/me` `plan_features` | regression | never lists `custom_domain`; Starter lists its three; Enterprise `null` | PASS |
| E5 | `/en/pricing` | regression | Enterprise shows its written lines; no raw `custom_domain` key anywhere | PASS |
| E6 | Migration on MariaDB over an already-seeded catalogue | integration | `enterprise` gained `custom_domain`; other plans untouched | PASS |

### F. Links ETHR hands out

| # | Case | Type | Expect | Result |
|---|---|---|---|---|
| F1 | Invite/activation link, verified-domain org | positive | custom-domain origin | PASS |
| F2 | Same org, domain pending | negative | `ethr.localhost:8081/{slug}` | PASS |
| F3 | Password-reset link | regression | shared host with `tenant` in the query | PASS |

### G. Operations

| # | Case | Type | Expect | Result |
|---|---|---|---|---|
| G1 | `custom_domain_token` in any response (detail, list, `/auth/me`, `tenant-context`, audit payloads) | security | never present | PASS |
| G2 | `tenancy:recheck-custom-domains` with a verified domain and no DNS | integration | audit `platform.tenant.domain_check_failed` with the checks; log warning; domain still verified; visible in the platform audit view | PASS; ownership FAIL(1) → PASS after the fix |
| G3 | `schedule:list` | integration | Monday 03:00, no overlap | PASS |
| G4 | Both migrations on MariaDB | integration | ran in `up.sh`; columns present | PASS |
| G5 | `verify.sh` | regression | 36/36 | PASS (49/49) |

## Findings

Three defects, all fixed in `499812c3` with a test each that fails on the code before it.
None crosses a tenant boundary; none is S1.

### 1 · Console actions on a tenant were audited with no owner — S3, `api/app/Models/AuditLog.php`

- **Seen:** after D2 the `admin.tenant.domain_changed` row had `tenant_id = NULL`. The
  tenant's Recent activity on the console never showed it, and the organisation's own audit
  log never would either.
- **Root cause:** `AuditLog::record()` derives an owner, when no tenant is resolved, from
  `$auditable->getAttribute('tenant_id')`. A `Tenant` has no such column. The console acts
  from the platform host, where nothing is resolved, so every `admin.tenant.*` row (status,
  trial, domain; and the weekly `platform.tenant.domain_check_failed`) was written unowned.
  Pre-existing; the domain actions inherited it.
- **Reproduce:** sign in to the console, assign a domain, `SELECT tenant_id FROM audit_log
  WHERE action = 'admin.tenant.domain_changed'` → NULL.
- **Fix:** a `Tenant` is its own owner (`getKey()`). `AuditLogTenantAttributionTest` gains
  *attributes a console action on a tenant to that tenant*. **Consequence stated plainly:**
  organisations now see platform actions on them (suspension, trial extension, a domain
  change) in their own audit log. That is the transparency the audit log exists for, and the
  payloads carry only the organisation's own data.
- **After:** G2's re-check row was written with `tenant_id = 4` (Acme), and Acme's Recent
  activity showed *domain changed* and *domain check failed*.

### 2 · The sign-in page on a custom domain did not know whose domain it was — S3, `src/src/lib/auth/use-auth-host-context.ts`

- **Seen (B4):** on `hr.acme.localhost:8081/login`, after the hand-off from the apex, the
  page read "Sign in to ETHR", showed an empty organisation field, and sent no context
  request. Signing in with the field blank worked, because the host resolves the tenant; the
  person was still asked which organisation they belong to, on that organisation's own
  domain.
- **Root cause:** `hostContext()` classifies a host from `NEXT_PUBLIC_ROOT_DOMAIN`, which a
  production build does not set (M3). Every host is `unknown` there, and the hook only asked
  `/auth/tenant-context` for a host it had classified as a subdomain.
- **Fix:** on an `unknown` host the hook asks the server, **without `X-Tenant`**, so only the
  host can answer. A custom domain or subdomain names its organisation; the apex answers
  `null` and keeps the field. Through `apiClient` the apex would have named whichever
  organisation the browser last used and locked the field to it, which is why the request
  bypasses the interceptor. `auth-host-context-custom-domain.test.tsx`, 2 cases, both failing
  on the old hook.
- **Verified end to end after the rebuild:** see the log's last entry.

### 3 · The Enterprise redirect hard-coded `https://` — S3, `api/app/Support/FrontendUrl.php`

- **Seen:** the harness itself. `canonicalOrigin()` built `https://hr.acme.localhost/login`
  for a custom domain while the subdomain tier took scheme and port from `app.frontend_url`,
  so the one tier the owner's decision adds was the one tier the rehearsal could not drive.
- **Fix:** both tiers use the frontend URL's scheme and port. Production is unchanged,
  because `FRONTEND_URL` there is `https://www.ethr.et`. `CanonicalTenantAddressTest` gains
  *gives a custom domain the scheme and port the deployment serves*, failing before.

### Notes, not defects

- **An `.env` older than the template lacks `TENANCY_SUBDOMAINS`.** The rehearsal reused its
  2026-10-06 `.env`; the key was added on 2026-10-07; `config('tenancy.subdomains')` read
  `false`, which is the safe direction. The same holds for a host `.env` created before the
  key. Worth knowing when M3 passes: adding the key is a deliberate step, not a default.
- **B7:** a sign-in on a host ETHR does not own (`evil.localhost`) still mints a token, because
  the login endpoints honour the organisation in the form body on any host (by design since
  2026-10-06). That token resolves no tenant on that host afterwards: `/employees` answered
  `{"data": []}` there, and the same token on the apex worked. Nothing crosses.
- **A7 as written was wrong, the code was right:** a reserved name that *is* a page
  (`/dashboard`, `/login`, `/en`) is served as that page; a reserved name that is not one
  (`/platform`, `/api`, `/mail`, `/status`) is a 404. `/admin` on the apex is 403, the
  `.htaccess` admin-host boundary.
- **The platform audit list** (`/admin/audit`) has no tenant column; it names the organisation
  through `auditable_public_id` and the payload. Not changed.
- **Console strings** (*Custom domain*, *Pending verification*, *Verify*) are English on the
  Amharic console, like every existing admin key. The rehearsal prints `ethr.et/acme` as the
  address because its build has no `NEXT_PUBLIC_SITE_URL`; production's does.
- **Not advertised:** `/en/pricing` renders Enterprise's written lines; the two `custom_domain`
  strings in its HTML are the i18n bundle, not rendered text (E5).

## Execution log

2026-10-08, local. Rehearsal built from `44b9c56c` (release tree 12 922 files), MariaDB
10.4.32, PHP 8.2 as CGI under Apache 2.4.58, Chromium 1280×720. Both migrations ran over the
pre-existing rehearsal database; Enterprise gained `custom_domain` (E6); `audit_log` kept both
triggers (G4). `verify.sh` 49/49 under the stock env (G5). Then `APP_DOMAIN=ethr.localhost`,
`FrontendUrl.php` patched in for finding 3 (the release predates the fix), MFA enrolled on the
rehearsal admin, `acme` and `habru` registered through `POST /auth/register` and moved to
Enterprise and Starter.

| Block | How | Outcome |
|---|---|---|
| A, C, B (API) | curl against Apache, per host | all as expected; the pending domain never resolved, built no link and was never redirected to |
| B2 page | the apex login page with Acme's credentials | landed on `http://hr.acme.localhost:8081/login` (finding 3 applied) |
| B4 page | sign-in on the custom domain, field blank | dashboard; `/auth/me` names Acme; cookie host-only (C8: 401 on the apex). Finding 2 |
| D | console with MFA; dialog for D2/D3/D1, same-origin `fetch()` for the rest | D2 stored normalised, pending, token 32-hex, both records shown; D3 422 named both records; finding 1 on the audit row |
| E1 | plan editor, tick on Professional, save | `PUT /admin/plans/…` 200; `/api/v1/plans` shows it; Professional reverted afterwards |
| Standard tier | `TENANCY_SUBDOMAINS=true` appended, config recached, then restored | A3 → `habru.ethr.localhost`; Acme's custom domain still wins; 409s and the subdomain sign-in as designed |
| G2 | `tenancy:recheck-custom-domains` in the rehearsal | 1 domain failed, owned audit row after finding 1, warning logged, still verified |

**Harness mistakes, recorded so they are not read as findings:** one curl battery sent an
empty `Bearer` header (a `/tmp` path PHP on Windows could not read) and got Apache 400s; the
console session idle-expired once, turning a batch into 401s; and one `cd` did not take, so a
re-check ran against the dev app instead and truncated its log. Each was rerun.

**After the rebuild from `499812c3`,** no patching this time: `verify.sh` 49/49;
`/acme` → `http://hr.acme.localhost:8081/login` and the apex sign-in 409 with that
`canonical_url` (findings 3); on `hr.acme.localhost:8081/login` the page reads *Sign in to
Acme Ltd*, shows no organisation field, and the only context request was
`GET hr.acme.localhost:8081/api/v1/auth/tenant-context → 200` (finding 2); the apex login page
still shows the field with the generic heading.

## What this pass does not prove

- The positive Verify against real DNS, and `dns_get_record()` on the Ethio Telecom host.
- TLS on a custom domain, and Plesk serving one: host work, per organisation.
- The cutover gates in [`CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md); this
  pass is evidence about the application, not about the host.
