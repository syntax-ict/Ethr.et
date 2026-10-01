# Impersonation — how a platform operator acts as a tenant admin

**Written 2026-10-01 from the code** (audit item D14). Every claim cites the file and line it
was read from. Nothing here was run against a live host; the last section lists what reading
the code turned up that the tests do not cover.

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
     server log or a `Referer`. On the tenant host the claim page posts it to
     `POST /api/v1/auth/session/claim` (`src/src/app/(auth)/impersonate/claim/claim-session.tsx:37-50`),
     which consumes it atomically, checks it was issued for *this* host's tenant, sets the
     cookie, and audits `impersonation_handoff_claimed` (`SessionClaimController.php`).
     Unknown, expired, reused and wrong-tenant nonces all get the same `422`.
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

`POST /api/v1/admin/exit-impersonation`, called *as the impersonated user*
(`AdminTenantController.php:300-347`):

- Only a token carrying the `impersonation` ability may call it; anything else gets
  `409 Not Impersonating` (`:308-315`).
- It is exempt from the platform-MFA requirement, because the caller is the tenant admin
  (`RequirePlatformMfa.php`, `EXEMPT_PATHS`).
- It audits `admin.tenant.impersonation_ended`, **deletes the impersonation token**, then
  recovers the operator from the token name and re-checks they are still a super admin before
  minting them a fresh session. If they are not — demoted, deleted — it clears the cookie and
  restores nothing (`:317-345`, `:357-373`).

The banner shown during impersonation calls this, then sends the browser to `/admin`, or to
`/login` when nothing was restored (`src/src/components/shared/impersonation-banner.tsx:22-49`).

If nobody exits, the token simply expires at 30 minutes.

## Findings from reading the code — not run, not covered by a test

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

Until those are checked on a real hostname setup, treat production impersonation as
**unverified**: the server half of the handoff is tested, the browser half is not wired, and
the way out may not be reachable. The 30-minute expiry still bounds every session.
