# Impersonation — how a platform operator acts as a tenant admin

**Written 2026-10-01 from the code** (audit item D14). Every claim cites the file and line it
was read from. Nothing here was run against a live host.

**Updated 2026-10-02 (audit N19):** the hostname-mode flow the last section found broken is
now wired end to end and covered by tests; *Delivers the session* and *Ending it* describe it
as it now works, and the last section records what was found and what is still only provable
on the real host.

Impersonation is the **only sanctioned way** for a platform super admin to see a tenant's data
as that tenant sees it. With `APP_DOMAIN` set, a super admin's own session is refused on tenant
hosts for exactly that reason — impersonation is MFA-gated, time-boxed and audited, and roaming
tenant hosts directly would bypass all three
(`api/app/Http/Middleware/EnsureUserBelongsToTenant.php:61-75`).

---

## Who can, and what it demands

| Gate | Where |
|---|---|
| `admin.manage` — super admins | `AdminTenantController.php:194` |
| Called on the platform host only: with `APP_DOMAIN` set, the `/admin` API 404s wherever a tenant is resolved | `api/routes/api.php:665-672`, `EnsurePlatformContext.php` |
| **MFA enabled on the operator's own account** — else `409 MFA Required` | `AdminTenantController.php:198-205` |
| **A fresh 6-digit TOTP code in the request**, every time — else `422`, and the failure is audited | `ImpersonateTenantRequest` (`code`: required, size 6); `AdminTenantController.php:207-218` |

The MFA check is per impersonation, not per login: holding an MFA-verified session is not
enough, the code is asked for again.

## What it does

`POST /api/v1/admin/tenants/{publicId}/impersonate` with `{"code": "123456"}`
(`api/routes/api.php:672`):

1. Finds the tenant by `public_id`, then **a** user of that tenant with role `tenant_admin`
   (`AdminTenantController.php:220-227`). There is no ordering: with several tenant admins,
   which one is unspecified. A tenant with none answers `404 No Admin User`.
2. Mints a Sanctum token **as that tenant admin**, with abilities `['*', 'impersonation']`, named
   `impersonation:<operator's user id>`, expiring in **30 minutes**
   (`:34`, `:238-243`; `ImpersonationToken.php`). The name is how the operator's identity
   travels with a token that belongs to someone else.
3. Writes `admin.tenant.impersonated` to the audit log with the impersonated user, the operator
   and the expiry (`:245-249`).
4. Delivers the session:
   - **Hostname mode** (`APP_DOMAIN` set — production): the session cookie is host-only, so it
     cannot be set from `admin.ethr.et` for `{tenant}.ethr.et`. The server stores the token
     under a single-use nonce for **30 seconds**, audits `impersonation_handoff_issued`, and
     returns `handoff_url` = `https://{tenant}.{domain}/impersonate/claim#nonce=…`
     (`:255-273`; `SessionHandoff.php`). The nonce is in the fragment, so it never reaches a
     server log or a `Referer`.
     **The console follows it** by full navigation (`window.location.assign`) and writes
     nothing to its own origin's storage (`src/src/features/admin/api.ts:186-192`).
     On the tenant host the claim page drops the nonce from the address bar, fetches
     `/sanctum/csrf-cookie` (this is the browser's first request to that host, so it holds no
     `XSRF-TOKEN`, and the claim is a stateful POST — `src/src/features/auth/sign-in.ts:86-89`),
     then posts the nonce to `POST /api/v1/auth/session/claim`, which consumes it atomically,
     checks it was issued for *this* host's tenant, sets the cookie, and audits
     `impersonation_handoff_claimed` (`SessionClaimController.php`). Unknown, expired, reused
     and wrong-tenant nonces all get the same `422`. On success the page sets
     `localStorage.impersonating = "true"` **on the tenant origin** — the banner's flag — and
     replaces itself with `/dashboard` by full navigation
     (`src/src/app/(auth)/impersonate/claim/claim-session.tsx:48-68`).
   - **Single-host development** (no `APP_DOMAIN`): the cookie is set directly and the token is
     also returned for API clients (`:278-288`).

The operator's own token is left alive (`:280-281`).

## While impersonating

**Time box.** The token expires 30 minutes after it was minted, and the cookie carries the same
lifetime (`:238`, `:260`, `:282`). There is no extension: start again, with a new MFA code.

**Audit.** Every `AuditLog::record()` made during the session gets `impersonated_by` = the
operator's user id, read from the token name (`api/app/Models/AuditLog.php:73-78`). The rows
are filed under the tenant, so the tenant's own audit view shows them.

**Blocked** (`api/app/Http/Middleware/BlockImpersonatedActions.php:19-45`), answered
`403 Action Restricted`:

- **every `DELETE`**, whatever the route;
- changing the password (`PasswordResetController@change`);
- MFA setup, enable and disable (`MfaSetupController@setup/enable/disable`);
- issuing an API key (`ApiKeyController@store`);
- changing the plan (`BillingController@changePlan`).

Everything else the impersonated tenant admin could do, the operator can do — including
approving payroll and leave. The protection there is the audit tag, not a block. The middleware
sits on the whole authenticated group (`api/routes/api.php:220`).

The check reads the token's abilities list directly, not `tokenCan()`: an ordinary token holds
`'*'`, which `tokenCan('impersonation')` would match (`BlockImpersonatedActions.php:32-35`).

## Ending it

`POST /api/v1/auth/impersonation/exit`, called *as the impersonated user*, on the host the
impersonated session lives on (`api/routes/api.php:245`; `AdminTenantController.php:314-378`).
*(Moved 2026-10-02, audit N19 — it was `/admin/exit-impersonation`, behind
`EnsurePlatformContext`, which 404s on every tenant host, so in hostname mode there was no way
out.)* It sits in the ordinary authenticated group, so `auth:sanctum`,
`EnsureUserBelongsToTenant` and `BlockImpersonatedActions` all apply, and the `/admin`
middleware does not:

- Only a token carrying the `impersonation` ability may call it, read from the abilities list
  rather than `tokenCan()`. Anything else gets **`403 Not Impersonating`** and nothing happens —
  no token is touched and nothing is audited (`:321-331`). *(It answered `409` while it lived
  under `/admin`.)*
- It needs no platform MFA: it is outside the `/admin` group, so `RequirePlatformMfa` never sees
  it, and the path exemption that file used to carry is gone.
- It recovers the operator from the token name and re-checks they are still a super admin
  (`resolveImpersonator()`, `:389-407`), audits `admin.tenant.impersonation_ended` with the host,
  and **deletes the impersonation token**. Then:
  - **Hostname mode:** it clears the tenant host's cookie and mints **nothing** — the operator's
    own session is on the platform host, which impersonation never touched, and a super-admin
    cookie on a tenant host would be refused by `EnsureUserBelongsToTenant` anyway. It answers
    `return_url` = `https://admin.{APP_DOMAIN}/admin` (`SessionHandoff::platformConsoleUrl()`),
    or `null` when the re-check failed (`:343-352`).
  - **Single host:** unchanged — a fresh session for the operator, `tenant` naming their tenant,
    `return_url: null`; when the re-check fails, the cookie is cleared and nothing is restored
    (`:354-378`).

The banner shown during impersonation calls this, clears its flags, then sends the browser to
`return_url` when the server named one (http/https only), else `/admin`, else `/login` when
nothing was restored (`src/src/components/shared/impersonation-banner.tsx:25-59`).

If nobody exits, the token simply expires at 30 minutes. The tenant origin's `impersonating`
flag then outlives it until the banner's exit button is pressed (which answers 401/403 and
lands on `/login`, clearing it) — harmless, but a banner can show over an expired session.

## Findings from reading the code — fixed 2026-10-02 (audit N19)

*Kept as written on 2026-10-01; the line references below are to the code as it was then.
Each finding was confirmed by reading before the fix, and a fourth turned up on the way.*

All three concern **hostname mode**, which is what production runs (`APP_DOMAIN=ethr.et`,
`api/.env.shared-hosting.example:33`). The backend tests for the handoff set `app.domain`
(`tests/Feature/Security/SessionHandoffTest.php:25-26`); the impersonation tests do not, so they
exercise single-host mode only.

1. **The console never follows `handoff_url`.** `useImpersonateTenant()` reads `token` and
   `tenant` from the response, sets local storage, and navigates to `/dashboard` on the *same*
   host (`src/src/features/admin/api.ts:143-168`). Nothing in `src/src` reads `handoff_url`. In
   hostname mode the response carries no token and sets no cookie, so the operator would land on
   the admin host's dashboard as themselves.
2. **Exiting would 404 on a tenant host.** `/admin/exit-impersonation` is registered inside the
   `/admin` group behind `EnsurePlatformContext` (`api/routes/api.php:665-673`), which returns
   404 whenever a tenant is resolved and `APP_DOMAIN` is set. The impersonated session lives on
   `{tenant}.ethr.et`, where a tenant is always resolved. `RequirePlatformMfa` exempts the path;
   `EnsurePlatformContext` does not. `ImpersonationTest` calls it on a tenant host without
   `app.domain` set, where the middleware is inert.
3. **The banner would not appear on the tenant host.** It shows only when
   `localStorage.impersonating === "true"` (`impersonation-banner.tsx:9-16`), which is set on the
   origin that *started* the impersonation (`api.ts:162`). Local storage is per origin, and the
   claim page does not set it.

4. **The claim would have been refused for CSRF** *(found while fixing 1–3)*. The claim page
   is the browser's first request to the tenant host, so it holds no `XSRF-TOKEN` cookie, and
   it never fetched `/sanctum/csrf-cookie` as the login form does. The claim is a stateful POST
   (`statefulApi()`, `SANCTUM_STATEFUL_DOMAINS=ethr.et,*.ethr.et`), so Sanctum's CSRF check
   would have answered `419`. No backend test can see this — Laravel skips CSRF verification
   under unit tests — so the order is pinned in Vitest instead.

**What changed.** 1: the console follows `handoff_url` by full navigation and leaves its own
storage alone. 2: exit moved to `POST /auth/impersonation/exit`, outside `/admin`; in hostname
mode it mints nothing on the tenant host and returns `return_url`. 3: the claim page sets the
flag on the tenant origin. 4: the claim page primes CSRF first. Tests:
`tests/Feature/Security/HostnameImpersonationTest.php` walks admin host → claim → an
authenticated call → exit on the tenant host, with `app.domain` set, and checks the session is
dead afterwards and both ends are audited; `src/src/test/impersonation-handoff.test.tsx` and
`impersonation-banner.test.tsx` cover the browser half.

**Audit trail, as it now lands.** The start (`admin.tenant.impersonated`,
`admin.tenant.impersonation_handoff_issued`) is recorded on the platform host, where no tenant
resolves, so it is written by the operator against the Tenant row with `tenant_id` NULL — the
platform audit view shows it, the tenant's own view does not. The claim and the exit are
recorded on the tenant host and filed under the tenant, tagged `impersonated_by`. That split is
unchanged by N19; it is stated here because it is not obvious.

**Still only provable on the real host** — none of this has run outside the test suite:

- that `{tenant}.ethr.et/impersonate/claim` is served by the static export's rewrite rules, and
  `/sanctum/csrf-cookie` and `/api/*` reach Laravel on every tenant host;
- that the `access_token` cookie set by the claim response is accepted by the browser on the
  tenant host (it is `Secure` in production, so this needs the real certificate);
- that `admin.ethr.et/admin` is where the console is served, since `return_url` names it;
- that `SANCTUM_STATEFUL_DOMAINS` on the host really includes `*.ethr.et` — without it the
  claim answers 200 and sets **no** cookie, silently.

Until then, treat production impersonation as **built and tested, not verified on the host**.
The 30-minute expiry still bounds every session.
