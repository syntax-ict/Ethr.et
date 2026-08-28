# Domain & Multi-Tenant Upgrade Plan

**Source:** `/docs/audits/DOMAIN_TLS_MULTITENANT_AUDIT.md` (2026-08-15)
**Status:** DM-A, DM-B and part of DM-C implemented on 2026-08-15. DM-D, DM-E and DM-F
remain planned only.

## Progress

| Phase | State | Notes |
|---|---|---|
| DM-A — tenant selection | **DONE** | `X-Tenant` refused outside local/testing; SPA no longer sends it where a root domain is configured; `device.*` channel now checks tenant ownership |
| DM-B — production config | **DONE** | `APP_DOMAIN`, Sanctum apex, CORS, `SESSION_DOMAIN`, `SUPER_ADMIN_PASSWORD`, `REVERB_HOST` on prod api/worker/scheduler. **B3 (trusted proxies) was implemented, measured, and reverted** — see audit A-3 |
| DM-C — hostname tests | **PARTIAL** | `tests/Feature/Security/HostnameTenancyTest.php` covers B-1, B-2, A-3, A-5, reserved subdomains, no-credentials-in-URL. Queue/MinIO/Reverb isolation tests still missing |
| DM-D — host-scoped admin | **DONE** | D1–D5, D7, D8 implemented; D9 closed 2026-08-21. **D6 deliberately not done** — reasoning below |
| DM-E — bootstrap/operability | **DONE** | E1–E4, E6 implemented. **E5 deliberately skipped** — reasoning below |
| DM-F — deployment verification | planned | needs a real environment |

**Found during remediation, not during the audit:** the apex domain `ethr.et` was being parsed
as a tenant named `ethr`, so every API request to the public domain answered 404. Fixed via
`APP_DOMAIN` (audit finding A-5, CRITICAL). It surfaced because a DM-C test failed for the
wrong reason — which is the argument for writing DM-C early rather than last.

This plan covers **only** gaps the audit actually found in the code. It does not restate work
already completed in `PHASE_00`–`PHASE_09`, and it does not propose refactoring anything that
passed the audit.

## Naming

The existing scheme is `PHASE_NN.md`, numbered sequentially, and the most recent live plan is
`ENTERPRISE_ROADMAP.md`. To avoid colliding with either, these phases are lettered **DM-A**
through **DM-F** ("Domain/Multi-tenant"). They can be renumbered into the `PHASE_NN` sequence
if you would rather keep one continuous track.

Six phases, not the ten suggested — several of the proposed splits (Redis/Queue/Reverb/MinIO,
Next.js routing, Laravel resolution) turned out to be one or two findings each rather than a
phase's worth of work, and TLS/DNS needed no code changes at all.

---

## Phase DM-A — Close the tenant-selection hole

**Why first:** contains both CRITICAL findings, and everything else is lower value while the
hostname is not authoritative. Small, surgical, high risk-reduction.

| # | Task | Finding | Files |
|---|---|---|---|
| A1 | Restrict `X-Tenant` to `local`/`testing` (or platform API keys) | B-1 | `app/Http/Middleware/ResolveTenant.php` |
| A2 | Stop sending `X-Tenant` from the SPA in production builds | B-1/H-2 | `src/src/api/client.ts` |
| A3 | Add tenant ownership check to the `device.*` channel | M-2 | `routes/channels.php` |
| A4 | Confirm `EnsureUserBelongsToTenant` covers every authenticated route | B-2 | `routes/api.php` |

**Exit criteria:** `X-Tenant: <other>` returns 403 in a production-like environment; the device
channel rejects a foreign-tenant subscription; full suite still green.

**Risk:** A1/A2 must ship together — the SPA depends on `X-Tenant` whenever the host has no
subdomain, so restricting the backend alone breaks local development and any apex-host usage.
Sequence carefully and verify `localhost` development still works.

---

## Phase DM-B — Production configuration correctness

**Why second:** these are deploy-blockers that no test would catch, and they are cheap.

| # | Task | Finding |
|---|---|---|
| B1 | `SANCTUM_STATEFUL_DOMAINS=ethr.et,*.ethr.et` in `DEPLOYMENT.md` | D-3 |
| B2 | Document `CORS_ALLOWED_ORIGINS` + `_PATTERNS` in `DEPLOYMENT.md` | D-4 |
| B3 | Configure trusted proxies — `X-Forwarded-For`/`Proto` **only** | A-3 |
| B4 | Verify `REVERB_HOST` on api/worker/scheduler in `docker-compose.prod.yml` | §18 |
| B5 | Refuse the default super-admin password when `isProduction()` | D-6 |
| B6 | Record that `SESSION_DOMAIN` must stay empty (host-only cookies) | §14 |

**Exit criteria:** a deployment performed strictly from `DEPLOYMENT.md` produces a working
tenant login, correct client IPs in `login_histories`, and functioning broadcasts.

**Risk — read before doing B3:** trusting `X-Forwarded-Host` would introduce a tenant-spoofing
vulnerability. Today Laravel ignores it *only* because no proxy is trusted; that accidental
protection disappears the moment trusted proxies are configured. Trust the two headers named
above and no others.

---

## Phase DM-C — Automated tests for the hostname security model

**Why third:** DM-A and DM-B are unprotected against regression until this exists. Both
critical findings lived precisely where there is no coverage, and the `EnsureUserBelongsToTenant`
control still has none.

Tests to add (scenarios 1, 2, 4, 5, 7, 8, 10, 11, 12, 14, 15 from the audit's §20):

- apex, `admin.ethr.et`, and two tenant hosts resolve to the correct context
- `admin` is never resolved as a tenant; full reserved-subdomain list rejected
- tenant A's token against tenant B's host → 403 (B-2 regression)
- `X-Tenant` cannot override the hostname in production config (B-1 regression)
- `X-Forwarded-Host: other.ethr.et` does not change the resolved tenant (A-3 regression)
- queued job cannot write outside its tenant; MinIO path cannot be read across tenants
- Reverb `device.*` and `tenant.*` channel authorisation
- login request carries no credentials in the URL

**Exit criteria:** each test fails when its control is reverted. A test that passes with the
fix removed is not a test.

---

## Phase DM-D — Host-scoped platform admin

**Target model — confirmed by the owner 2026-08-15:**

```
admin.ethr.et            habru.ethr.et
      │                        │
      └── PlatformContext      └── TenantContext
             │                        │
             └── SuperAdmin           └── Habru tenant
```

The boundary becomes **structural** rather than a permission check. Today the separation is
enforced only by `Gate::authorize('admin.manage')` and `<RoleGate minRole="super_admin">` —
correct, but a single missing `Gate::authorize()` on one new admin controller re-opens it.
Under this model that mistake is unreachable: the route is not served on a tenant host, and no
tenant context exists on the platform host. It also makes host-level controls possible that
are impossible today — IP allow-listing the admin host, separate rate limits, separate logs.

**What already holds:** `admin` is in `RESERVED_SUBDOMAINS`
(`app/Http/Middleware/ResolveTenant.php:33`), so `admin.ethr.et` already resolves to *no*
tenant, and `BelongsToTenant` already degrades to `WHERE 0 = 1`. The "not a tenant" half is
done. The "is a platform context" half does not exist.

| # | Task | Finding |
|---|---|---|
| D1 | Split Nginx into `ethr.et`, `admin.ethr.et`, `*.ethr.et` blocks | G-1, C-3 |
| D2 | Add Next.js `middleware.ts` mapping hostname → context | H-1 |
| D3 | Reject `/admin` on tenant hosts; reject tenant routes on the admin host | C-2 |
| D4 | Introduce an explicit `PlatformContext`; assert platform routes run with `CurrentTenant` unresolved | C-2 |
| D5 | Extend reserved subdomains to the full 14 | A-2 |
| D6 | Confine super-admin **login** to the platform host | D-5, C-2 |
| D7 | Narrow the `EnsureUserBelongsToTenant` super-admin exemption | B-2 |
| D8 | Resolve the impersonation cookie handoff — **decide before starting** | see below |

### D6 — deliberately not implemented

Confining super-admin **login** to `admin.ethr.et` was planned and has been dropped, because
the cost outweighs what it buys.

What it would buy: one fewer host on which the platform account can authenticate. But the
login endpoint is already rate-limited (5/min), needs correct credentials, and impersonation —
the only route from a platform session to tenant data — separately demands MFA. And D7 already
refuses a super admin *anything* on a tenant host, so a session obtained there is inert.

What it would cost: if `admin.ethr.et` DNS or its certificate is wrong, nobody can reach the
platform console at all, and the fix requires the console you are locked out of. It would also
contradict E1, which exists so a super admin can sign in on a fresh installation before any
tenant — and therefore before the admin hostname is necessarily configured.

Locking the emergency exit to a door that may not open yet is the wrong trade. Revisit only if
platform login is later shown to be reachable in a way D7 does not already neutralise.

---

**D7 detail.** `EnsureUserBelongsToTenant` currently exempts `SUPER_ADMIN` on every host,
because it had to: the platform console is served from tenant hosts. Once the console moves to
`admin.ethr.et`, that exemption should narrow to the platform host only. A super admin arriving
at `habru.ethr.et` with a platform token should be refused rather than silently granted
whole-tenant read access. This makes the exemption bounded instead of global.

### D8 — Impersonation breaks under this model (blocker)

`SessionCookie::issue()` (`app/Services/Auth/SessionCookie.php:27-40`) passes `null` for the
cookie domain, so `access_token` is **host-only** — verified in the running stack. That is the
correct choice for tenant isolation: a cookie set on `habru.ethr.et` is never sent to
`woldia.ethr.et`.

But `AdminTenantController::impersonate()`
(`app/Http/Controllers/Api/V1/Admin/AdminTenantController.php:239`) plants that cookie on the
host serving the request and returns `['token' => …, 'tenant' => $tenant->subdomain]`. Today
every host is the same host, so it works. Under the confirmed model the cookie is planted on
`admin.ethr.et` and the browser is then sent to `habru.ethr.et`, **which will not receive it**.
Impersonation stops working the moment the hosts are split.

**DECISION: cross-host handoff (option 1). `SESSION_DOMAIN=.ethr.et` is rejected.**

Decided against the existing documentation rather than on preference. Four things in `/docs`
point the same way:

1. **`SECURITY.md:13-24` treats isolation as a per-layer invariant** — database, file storage,
   cache keys, queue jobs, API responses, and encryption each carry their own tenant boundary.
   A domain-wide cookie would be the one layer that deliberately spans tenants. It contradicts
   the stated posture directly.
2. **`SECURITY.md:48-51` already assumes a two-credential model** — a short-lived access token
   plus a separately-scoped refresh cookie on a narrower path. A single-use, expiry-bound
   exchange is the same shape the document already reaches for: present a credential, receive
   a session. Option 1 extends an existing pattern; option 2 abandons one.
3. **`SECURITY.md:228-249` shows impersonation is deliberately guardrail-heavy** — MFA on
   entry, 30-minute non-renewable, audit on every action, undismissable banner, eight classes
   of blocked action. A nonce-bound handoff is consistent with how this feature is already
   built. Widening the cookie to reach the tenant host would be the only place the feature
   chose convenience over a guardrail.
4. **`PHASE_09.md:208` requires `httpOnly`, `Secure`, `SameSite=Lax` on all cookies.** Option 2
   keeps those flags, so it would pass that checklist while still being the more dangerous
   choice — a reminder that the checklist does not cover cookie *scope*.

**The decisive point is one option 2 obscures.** `SESSION_DOMAIN` is not scoped to
impersonation. Setting it to `.ethr.et` widens the session cookie for **every user**, so an
ordinary Habru employee's cookie would be transmitted to `woldia.ethr.et` on any request their
browser is induced to make. The only thing then standing between that cookie and another
tenant's data is `EnsureUserBelongsToTenant` — a middleware added on 2026-08-15 that currently
has **no test coverage** (audit O-1). Option 2 converts a structural guarantee into a
single-middleware guarantee, in exchange for saving one redirect.

**Recommended shape:**

- `impersonate()` stops calling `$sessionCookie->issue()` on the platform host
  (`AdminTenantController.php:239`) and instead returns a single-use handoff nonce.
- Nonce: random, ≥128-bit, stored server-side against the impersonation token, TTL ~30s,
  consumed on first use, bound to the target tenant.
- The browser lands on a claim page on the tenant host, which POSTs the nonce to a tenant-host
  endpoint that mints the host-only cookie there.
- **Carry the nonce in the URL fragment, not the query string.** A query parameter would put a
  bearer-equivalent credential in server logs and `Referer` headers, violating the same
  principle the audit verified as PASS in D-2 (no credentials in URLs). The fragment is never
  sent to the server; the claim page reads it in JS and POSTs it once.
- Audit both halves — `impersonation.handoff_issued` and `impersonation.handoff_claimed` —
  so an unclaimed nonce is visible.

Cost is one redirect on a flow that already requires an MFA challenge. That is not a
meaningful regression in a path deliberately built to be slow and deliberate.

### D8 — implemented 2026-08-16

`api/app/Services/Auth/SessionHandoff.php`, `SessionClaimController`,
`POST /api/v1/auth/session/claim`, and the tenant-host landing page at
`src/app/(auth)/impersonate/claim`.

As specified above, with these properties enforced by
`api/tests/Feature/Security/SessionHandoffTest.php`:

| Property | How |
|---|---|
| Single use | `Cache::pull` reads and deletes atomically — a replay finds nothing |
| Short lived | 30s TTL; long enough for one redirect, useless if leaked |
| Bound to its tenant | tenant read from the **hostname**, never the request body |
| Not in a URL | nonce rides in the fragment; browsers never send it to a server |
| Cookie stays host-only | asserted: no `Domain` attribute, `path=/api`, httpOnly |
| Failures indistinguishable | unknown / expired / spent / wrong-tenant all return the same 422 |
| Inert in development | `SessionHandoff::required()` is false without `APP_DOMAIN` |

Two things worth carrying forward:

- **The claim must be stateful.** Queued cookies are only attached to responses Sanctum
  considers stateful, which is decided by `Origin`. `SANCTUM_STATEFUL_DOMAINS` must therefore
  list the tenant hosts (`ethr.et,*.ethr.et`) or the claim returns 200 with no cookie —
  silently landing the user logged out. The same omission that makes apex login fail (D-3).
- **The claim page reads the fragment client-side**, which is precisely why it is a page and
  not a plain redirect to `/dashboard`: a server cannot see a fragment. It also guards against
  React StrictMode's double-mount, which would otherwise spend the nonce twice and report
  failure for a handoff that had in fact succeeded.

### D9 — the platform host still accepted a tenant selector (found 2026-08-21)

`admin` being reserved stopped the *subdomain* resolving to a tenant, and `EnsurePlatformContext`
asks only whether a tenant is resolved — so `admin.ethr.et` looked correct. But `ResolveTenant`
then fell through to the next selector, and the form-field fallback is honoured on the login
endpoints. `POST admin.ethr.et/api/v1/auth/login` with `tenant: habru` in the body resolved
Habru and **authenticated a Habru user on the platform hostname** (verified: 200 with an access
token before the fix).

Not a data leak — the session cookie is host-only, `middleware.ts` bounces tenant routes to
`/admin`, and the console demands `super_admin` — but it is exactly the separation DM-D exists
to make structural rather than permission-shaped.

Fixed by `ResolveTenant::isPlatformHost()`, which returns before any selector is consulted.
Deliberately keyed to `Tenant::PLATFORM_SUBDOMAIN` alone and not to the whole reserved list:
`www` is an apex alias in most deployments, and refusing the fallback there would break login
for anyone reaching the public site by its conventional name. `platform` was added to
`RESERVED_SUBDOMAINS` at the same time — it is the other name the console is routinely called,
and reserving a name costs nothing while reclaiming one costs a migration.

Regression cover: two tests in `HostnameTenancyTest` — one that the platform host refuses the
selector, one that the apex still accepts it, so the fix cannot quietly become "the body field
is dead". The first fails (200 ≠ 422) with `isPlatformHost()` disabled.

The client-side classifier was one label out of step in the same way: `hostContext()`
special-cased `admin` but read every other reserved label as a tenant, so `www.ethr.et/login`
rendered "Sign in to www" with the organisation field hidden while `/auth/tenant-context`
answered `{tenant: null}`. It now mirrors the full reserved list. The duplicate
`lib/auth/tenant-from-host.ts` — root-domain-unaware label counting, still used by `api/client.ts`
and `tenant-host.ts` — was deleted and both call sites moved onto `host-context.ts`.

**Exit criteria:** `habru.ethr.et/admin` does not serve the admin console; `admin.ethr.et`
serves only platform administration and never carries a tenant context; impersonation still
completes end-to-end across the host boundary.

**Risk:** D2 is the first `middleware.ts` in this codebase and runs on every request. Ship it
behind a hostname allow-list first and confirm no redirect loops with the `(auth)`,
`(dashboard)` and `(marketing)` route groups. D6/D7/D8 must land together — separating super
admin from tenant hosts while impersonation still depends on a shared host locks super admins
out of the flow.

---

## Phase DM-E — Bootstrap and operability

Smaller correctness items that surfaced during the audit.

| # | Task | Finding | State |
|---|---|---|---|
| E1 | Allow super-admin login with no tenant subdomain (fresh install) | D-5 | done |
| E2 | Reconcile `/app` vs `/ws` for Reverb; fix whichever is wrong | G-2 | done |
| E3 | Remove the misleading `X-Forwarded-Host` comment in dev Nginx | G-3 | done |
| E4 | Alerting on `failed_jobs` (silent queue failures observed) | §17 | done |
| E5 | `tenantCacheKey()` helper; tenant-scope helper for jobs | K-1, L-1 | **skipped** |
| E6 | Fix the documentation inconsistencies in audit §21 | §21 | done |

### E2 — the variable was worse than the path

The path was wrong (`/ws`; nginx proxies `/app`), but the larger problem was that
`NEXT_PUBLIC_WS_URL` **is read by nothing**: `src/lib/echo.ts` builds the connection from
`NEXT_PUBLIC_REVERB_HOST/PORT/SCHEME`. The same turned out to be true of `ERROR_ALERT_EMAIL`,
documented as receiving 500-error notifications with no mail alerting anywhere in the codebase.

A documented variable that does nothing is worse than a missing one — the operator sets it and
believes the thing is configured. Both replaced with the settings that are actually read.

### E4 — what "alerting" means here

`Queue::failing` in `AppServiceProvider` logs at error level and reports to Sentry explicitly
rather than relying on log-channel forwarding. `QueueFailureAlertTest` dispatches the
framework's real `JobFailed` event rather than invoking the closure, so the test fails if the
listener is ever unregistered — registration being the part that silently disappears.

There is still no paging: alert on that log event or on Sentry. This closes the specific hole
observed (a scheduled job failing on every run with nothing announcing it), not the general
question of on-call.

### E5 — deliberately skipped

The audit suggested a `tenantCacheKey()` helper as a structural guarantee against a future key
built from a tenant-local identifier. Every key in the codebase was then re-checked: each is
either global by intent (`permissions:known_abilities`, `role_permissions:{role}`,
`tenant:{subdomain}`) or keyed by a globally-unique surrogate id
(`custom_role_permissions:{id}`, `dashboard_cache_version:{tenantId}`). There is no defect.

A helper nobody calls does not prevent the mistake it was written for, and migrating working
keys to it would invalidate caches to no end. If this is revisited, the effective form is a
test that fails when a tenant-scoped service produces a key without a tenant discriminator —
not a helper that authors must remember to reach for.

---

## Phase DM-F — Production deployment verification

Nothing here is a code change; it is the evidence the audit could not gather because this
environment has no certificates, no public DNS and no deployment.

**Partly reproducible locally since 2026-08-21.** `docker-compose.hostnames.yml` (and the
native equivalent, `APP_DOMAIN=localhost` + `NEXT_PUBLIC_ROOT_DOMAIN=localhost`) runs the
same hostname model against `*.localhost`, which browsers resolve to 127.0.0.1 without DNS.
That covers the host-shape behaviour — apex vs `admin.` vs tenant vs unknown tenant, the
admin-console split, the super-admin refusal on tenant hosts, and the impersonation handoff.
It does **not** cover anything below the application: certificates, HSTS, real DNS, client
IPs through a proxy. Those still need a deployment. Documented in `LOCAL_SETUP.md` §4.

Until 2026-08-21 none of this could be exercised outside the Pest suite — local development
was single-host only, so every host-shape claim rested on feature tests alone.

**Verified against the running stack (nginx → Laravel, `APP_DOMAIN=localhost`, 2026-08-21):**

| Request | Result |
|---|---|
| `GET /auth/tenant-context` on `localhost` | `{"tenant":null}` — apex, not a tenant named "localhost" |
| … on `demo.localhost` | `{"name":"Ethio Demo Corp","subdomain":"demo"}` |
| … on `admin.localhost` | `{"tenant":null}` — platform host |
| … on `nope.localhost` | 404 `tenant-not-found` |
| … on a suspended tenant's host | 403 `tenant-inactive` |
| … on `demo.localhost` **with `X-Tenant: nnn`** | still Demo — the hostname outranks the header |
| `POST /auth/login` on `admin.localhost` with `tenant: demo` | **422** (was 200 + access token before D9) |
| `POST /auth/login` on the apex with `tenant: demo` | 200 + access token — fallback intact |
| `GET /admin/tenants` as a tenant admin on their own host | 404 "not available on this host" |
| tenant admin's token aimed at `nnn.localhost` | 403 `tenant-mismatch` |

**Found by running it — which is the argument for making it runnable.** `middleware.ts`
cleared the port when redirecting `/admin` to the platform host, so the browser was sent to
`admin.localhost:80` with nothing listening. Correct behind nginx (the browser is on 443 and
the container's :3000 must not leak into the redirect) and wrong everywhere else. The port now
comes from the Host header — the only source that knows which situation applies — and is
assigned in both directions, because the URL `host` setter leaves an existing port in place
when the value it is given carries none. That last detail is why the original cleared it
explicitly, and why the first attempt at the fix passed the local case and broke the
production one. Covered by `src/test/middleware-hostname.test.ts`.

- `certbot renew --dry-run` succeeds unattended (the `--manual` trap)
- certificate covers `ethr.et`, `admin.ethr.et`, `{tenant}.ethr.et`; chain and expiry verified
- HTTP→HTTPS redirect and HSTS confirmed on all three host shapes
- `wss://{tenant}.ethr.et` connects and cannot subscribe cross-tenant
- client IPs appear correctly in `login_histories` (proves B3)
- consider `ssl_stapling`, currently unconfigured

---

## Sequencing

```
DM-A ──► DM-B ──► DM-C ──► DM-D ──► DM-E ──► DM-F
(critical)  (deploy)  (tests)   (arch)   (polish)  (verify)
```

DM-A and DM-B may proceed in parallel; they touch different files. DM-C should not start until
DM-A and DM-B have settled, or the tests will be written against moving targets. DM-F requires
a real environment.

## Explicitly out of scope

Not proposed, because the audit found them working: `BelongsToTenant` and the global scope,
`TenantIsolationTest`'s model sweep, MinIO path prefixing, `tenant.*`/`user.*` channel
authorisation, the wildcard TLS issuance and renewal design, HTTP→HTTPS/HSTS, and the
POST-body authentication flow.

## Open decision for the owner

Finding **D-1** (session cookie excluded from encryption) was fixed during the audit session
and is the one change carrying a genuine trade-off. It should be either ratified by a second
reviewer or replaced with the middleware-reordering alternative before production. Left as-is,
browser login works; reverted, the application cannot hold a browser session at all.
