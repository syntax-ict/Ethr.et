# Console API keys are accepted on the tenant API, as their creator, within their abilities

> **DECIDED BY THE AGENT, 2026-10-09, under a delegation** ("decide for me", after
> [`../audit/QA-FULL-SWEEP-2026-10-08.md`](../audit/QA-FULL-SWEEP-2026-10-08.md) finding 3).
> Not an owner decision. The owner can reverse it by removing `AuthenticateApiKey` from the
> `api` group in `bootstrap/app.php` and `EnforceApiKeyAbilities` from the tenant route group;
> nothing else depends on them.

## What was wrong

Settings → API keys, behind the `api_access` plan feature, minted keys with abilities (`read`,
`write`, `employees`, `attendance`, `leave`, `payroll`, `reports`) that **nothing accepted**. The
only reader of `ApiKey` was `ScimAuth`, which requires a `scim` ability the console cannot
grant. A console key used alone answered 401 on every tenant endpoint. The feature was sold on
the pricing page ("API access, webhooks and the audit log") and did not exist.

## What was decided

Three things, each with the alternative it was chosen over.

1. **A key acts as the user who created it.** `AuthenticateApiKey` runs in the `api` group after
   `ResolveTenant`, looks the key up by the hash of the presented secret, and sets the creator
   on the `web` guard, which Sanctum's guard reads first. Every existing middleware and policy
   then sees an ordinary authenticated user: the creator's role, permissions and tenant apply,
   and an inactive creator or a suspended tenant refuses the key. *Over* a dedicated "integration
   principal", which would have needed its own role model and a second authorization path;
   and *over* re-minting keys as Sanctum personal access tokens, which would have changed the
   stored format every existing key and `ScimAuth` depend on.
2. **The key's tenant is the resolved tenant when the host named none**; when the host or
   `X-Tenant` named another, `EnsureUserBelongsToTenant` answers 403 `tenant-mismatch` exactly
   as for a session. A key can never reach a second organisation. *Over* letting the key
   override the resolved tenant, which would have made the header meaningless for keys only.
3. **Abilities narrow, never widen.** `EnforceApiKeyAbilities`, in the tenant route group after
   `auth:sanctum`, acts only on requests that authenticated by key. `read` = any GET on the
   tenant API; `write` = any other method; a module ability = every method under
   `/api/v1/<module>/` for the five modules. Everything else needs `read` or `write`. A SCIM-only
   key is refused everywhere outside SCIM. The 403 names the abilities that would have allowed
   the call. *Over* treating any ability as full access, which is what "stored but unread"
   amounted to.

What the key refuses says nothing about why: unknown, expired, revoked, creator inactive and
tenant suspended all answer the same 401, so a leaked key reveals no state.

## What it costs

- Two new `withoutGlobalScopes` sites (161/59): the key lookup is pre-authentication and keyed on
  the secret, like `ScimAuth`; the creator lookup states `tenant_id` from the key.
- `api-global` rate limiting counts a key's calls against its creator's budget (300/min), since
  the creator is the user. A busy integration shares a budget with the person who made the key.
- A key inherits every constraint on its creator, including `RequireTenantMfaEnrolment` when a
  tenant enforces enrolment: an un-enrolled creator's keys stop working. That is a feature of
  acting as a person, not a side effect; document it where keys are issued if it bites.

## Verification

`ApiKeyAuthenticationTest`, 13 cases. With the two middleware unwired, **9 fail and 4 pass** — the four are the refusal cases and the untouched session, which the old code satisfied by refusing every key; the nine are what did not exist: authentication as
the creator on the key's tenant; unknown, expired, revoked, inactive-creator and
suspended-tenant refusals; the cross-tenant 403; `last_used_at`; a module key inside and outside
its module; a read key reading everything and writing nothing; write and module writes; the
403's wording; a SCIM-only key refused; an employee's key refused what the employee may not do;
and a session untouched. `TenantScopeBypassInventoryTest` pins the two new sites.
