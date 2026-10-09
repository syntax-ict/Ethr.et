# ETHR — Tenant Isolation Audit

**Phase 0 forensic audit, read-only. Measured 2026-09-25 against `6d9fb23`.**

This documents the **real implementation** of tenant isolation as read from source, and the
concrete risks that follow. It is deliberately not generic multi-tenancy advice.

Two prior passes already exist and are deeper on their own subject: `BASELINE.md` §11b–§11j
(the `withoutGlobalScope` bypass audit, four measured passes) and the root `CLAUDE.md`
section 4. **This audit does not re-derive their figures.** It adds what neither covers in
one place: the isolation mechanism end to end — resolution, enforcement, the non-database
surfaces (cache, files, broadcast channels, sessions), and where Bronze changes the picture.

---

## 1. The model

**Single database, single schema, `tenant_id` column, global query scope.** Not
database-per-tenant, not schema-per-tenant.

This is the fact that makes Bronze possible at all — one MySQL database is the entire
allowance, and ETHR needs exactly one.

---

## 2. Establishment — how a tenant is identified

`api/app/Http/Middleware/ResolveTenant.php`, registered in `bootstrap/app.php` as the
**fourth** prepended API middleware, after `SecurityHeaders`, `AuthenticateFromCookie` and
`SetLocale`.

**The load-bearing design decision, stated in the class docblock:** *"Tenant identity is
resolved BEFORE authentication, from explicit URL/header signals only — never from the
authenticated user's `tenant_id`. This keeps authentication and tenant identity as separate,
independently-verified concerns (preventing cross-tenant data access via leaked tokens)."*

Resolution order:

| # | Selector | Honoured where | Note |
|---|---|---|---|
| 1 | **Subdomain** — `acme.ethr.et` | **Production — the only selector honoured there** | `resolveFromSubdomain()` |
| 2 | `X-Tenant` header | **local/testing only; refused in production** | A bare `localhost` has no subdomain to read |
| 3 | Tenant slug in the form body | login / register / password routes only | Selects *which* tenant to authenticate against; grants nothing |

**One host is exempt from all three.** `isPlatformHost()` makes `admin.<root>` resolve no
tenant by any selector. The docblock records why this had to become structural rather than
remain a reserved-name check: `admin` being reserved stopped the *subdomain* resolving, but
resolution **fell through to the form-field selector**, so
`POST admin.ethr.et/api/v1/auth/login` with `tenant: habru` in the body authenticated a
Habru user on the platform hostname. No data crossed a boundary — the cookie is host-only
and the console still demands `super_admin` — but tenant authentication was reachable on the
platform host, which is exactly the separation the split exists to make structural.

`www` is deliberately **not** treated the same way, because it is an apex alias in most
deployments and refusing the form fallback there would break login for anyone arriving by
the conventional name.

---

## 3. Enforcement — three independent layers

### 3.1 The global scope — fail-closed

`api/app/Traits/BelongsToTenant.php`:

```php
if ($current->resolved()) {
    $builder->where($builder->getModel()->getTable().'.tenant_id', $current->id());
} else {
    $builder->whereRaw('0 = 1');
}
```

**Absence of tenant context yields *no* rows, not *all* rows.** This is the property the
entire product rests on, and it is the right default: a forgotten middleware, a queued job
with no HTTP context, or an artisan command produces an empty result rather than a
cross-tenant read.

The trait also sets `tenant_id` on `creating` when a tenant is resolved and the model has
none — so writes inherit the boundary as well as reads.

### 3.2 Membership check — the second half of the contract

`api/app/Http/Middleware/EnsureUserBelongsToTenant.php` runs **after** `auth:sanctum` and
checks that the authenticated user's `tenant_id` agrees with the resolved tenant.

Its docblock records the defect it was written to close, and it is worth quoting because it
shows why layer 3.1 alone is insufficient: *"Any authenticated user could read another
tenant's data simply by aiming the same valid token at that tenant's subdomain … the global
scope faithfully scoped every query to the **host's** tenant, and no layer asked whether the
caller belonged to it."*

Exemptions, each narrow and reasoned:

- **Super admins** are deliberately tenant-less and must reach across tenants for the
  platform console and to start an impersonation.
- **An impersonation token is issued *as the target user***, so it carries that user's
  `tenant_id` and is checked here like any other token.
- In single-host development the exemption stays broad, read through `TenancyDomain` because
  `APP_DOMAIN=` yields an empty string and reading that as "configured" would refuse super
  admins on every host.

### 3.3 Container binding — `scoped`, not `singleton`

`api/app/Providers/TenantServiceProvider.php:36` binds `CurrentTenant` as **`scoped`**.

This is not cosmetic. It was a `singleton`, which survives for the life of a worker process,
and **eight queued jobs and a queued listener call `set()` while nothing in `app/` ever calls
`forget()`** — so the next job inherited the previous job's tenant instead of failing closed.
`scoped` is flushed between jobs by the framework (`QueueServiceProvider` hands the Worker a
`$resetScope` callback; `Worker::daemon()` calls it before reserving each job).

**This one is Bronze-relevant in a way the VPS was not.** On shared hosting the queue runs
through `CronRunController::queue` → `queue:work --stop-when-empty --max-time=50`, which is
still a `Worker::daemon()` loop within one PHP process draining several jobs, so the same
reset applies and the same hazard would apply if the binding regressed. **If you write a job
that needs tenant context, set it yourself — do not assume it is absent, and do not assume it
is right.**

---

## 4. The bypass inventory — what it proves and what it does not

`api/tests/Feature/Security/tenant-scope-bypasses.php` pins every
`withoutGlobalScope`/`withoutGlobalScopes` call site **per file**.

**Measured directly from that file, 2026-09-25: 159 call sites across 57 files.**
`TenantScopeBypassInventoryTest` fails when any per-file count moves.

> **A stale figure inside the authoritative file.** Its own docblock (line 11) still reads
> *"these **157** bypasses"* while the data below it sums to 159. The data is right; the
> prose is two behind. Worth fixing precisely because this file is where people go to
> settle the number — and because the root `CLAUDE.md` already records, at length, a
> four-day +5 overcount that came from counting the same thing a second way.

**What the gate buys:** adding a bypass becomes a deliberate act, which is exactly what was
missing when the one shipped defect went in. **What it does not buy:** an audit of the ones
that already exist. A count cannot audit anything.

Four passes have been run over the existing sites (`BASELINE.md` §11c, §11d, §11i, §11j).
The current shape, as they left it:

- **113 of 157 prove their own safety; 44 do not** — the 2026-09-23 reading, left as
  measured. The two listeners added 2026-09-25 (`NotifyDeviceSyncFailed`,
  `NotifyPayrollRunFailed`) both state `tenant_id` derived from a tenant-owned row, so they
  enter the self-proving class and make it **115 of 159 with the 44 unchanged**.
- The remaining 44 are gates, pre-authentication secrets, global models and sweeps, where a
  predicate is impossible or contrary to the feature.
- **Nothing found that can reach another tenant's rows** in either of the two strict passes.

**The one defect that did ship**, kept here because it calibrates how much the rest is worth:
`EmployeeImporter::commit()` de-duplicated on a caller-supplied `import_key` **across every
tenant** — a cross-tenant existence oracle and silent data loss in one. Pinned now by
`tests/Feature/Security/TenantImportIsolationTest.php`.

**The lesson the audits converged on, and the one to carry into any Bronze work:** three
queued jobs had the tenant id in the payload and wrote
`if ($this->tenantId !== null) { $query->where(...) }`. A predicate conditional on a nullable
payload field is stated by the *dispatcher*, not by the query. **Having a predicate is
necessary, not sufficient; having one conditionally is neither.**
`QueuedJobTenantPredicateTest` now reflects over those constructors and fails if the argument
returns to optional *or* to `?int`.

**Raw SQL carries no scope at all.** `whereRaw`, `selectRaw` and `DB::raw` bypass the global
scope silently — say `tenant_id` yourself.

---

## 5. Non-database surfaces

The scope covers Eloquent. These five surfaces are outside it, and each was checked
individually.

### 5.1 Broadcast channels — **outside the HTTP middleware stack entirely**

`api/routes/channels.php` authorises three channels. Broadcast authorisation runs outside
the middleware stack, so `CurrentTenant` is **not resolved** and the global scope would match
nothing — the tenant comparison therefore has to be explicit, and is:

| Channel | Check |
|---|---|
| `user.{publicId}` | `$user->public_id === $publicId` |
| `tenant.{tenantId}` | `(string) $user->tenant_id === $tenantId` |
| `device.{devicePublicId}` | `device.view` permission **and** `Device::withoutGlobalScopes()->where('public_id', …)->where('tenant_id', $user->tenant_id)->exists()` |

The device channel's own comment records that **the ownership check is not redundant with
the permission check**: `devices.view` says a user may look at devices, not *whose*. Without
the tenant clause, any holder of that permission in any tenant could subscribe to another
tenant's device by `public_id` — *"a leak that travels over the WebSocket and so bypasses
every HTTP-layer control, including `EnsureUserBelongsToTenant`."*

It also records that the permission string was **`devices.view` (plural), which matched no
permission in `PermissionSeeder`**, so the channel silently denied everyone except super
admins and device status never reached a dashboard — and that this is why the missing tenant
check underneath was not exploitable. **Two defects, each concealing the other.** This is the
single most instructive finding in the isolation surface.

**Bronze note:** with `BROADCAST_CONNECTION=null` these channels are never subscribed to, so
the surface is inert on the target. That is a reason to keep the checks correct, not to relax
them — the VPS still runs Reverb and is the rollback path.

### 5.2 File storage — tenant-prefixed paths

`api/app/Services/FileStorageService.php`: every stored object is written to
`{$tenantPrefix}/{$directory}/{$filename}`, where the prefix comes from the injected
`CurrentTenant` and the directory passes through `safeDirectory()`. Filenames are ULIDs, so
paths are not guessable across tenants. Downloads use `temporaryUrl()`, which works on the
`local` disk because `config/filesystems.php` sets `serve => true`.

**Bronze note:** `FILESYSTEM_DISK=local` puts every tenant's documents on one filesystem
under one account. The prefix is the only separation, and it is applied in one place — which
is good design and a single point of failure. Anything that writes to storage without going
through `FileStorageService` loses the prefix.

### 5.3 Cache keys

| Key | Tenant-safe? | Why |
|---|---|---|
| `dashboard_cache_version:{tenantId}` | **Yes** | Explicitly keyed by tenant id; the dashboard's date-ranged keys embed the version, which is how invalidation works **without `Cache::tags()`** |
| `tenant:{subdomain}` | **Yes** | The subdomain *is* the tenant identity |
| `custom_role_permissions:{customRoleId}` | **Yes** | Custom roles are tenant-owned rows, so the id is tenant-derived |
| `role_permissions:{role}` | **Yes — global by design** | Keyed on the `UserRole` enum. Built-in role→permission mapping is platform-wide reference data, identical for every tenant |
| `cron:{task}` lock | **N/A** | Deployment-wide by intent — one `queue:work` at a time per host |
| `ethr:scheduler:heartbeat` | **N/A** | Deployment-wide by intent |

**No unkeyed per-tenant cache entry was found.** `Cache::tags()` is used **nowhere**, and
three files carry comments explaining that this is deliberate because the `database` store
does not support tags. That decision is what makes the Bronze cache driver viable.

### 5.4 Sessions and authentication

`SESSION_DRIVER=database`; the Sanctum session cookie is **host-only**, so a cookie set on
`acme.ethr.et` is not sent to `other.ethr.et`. `bootstrap/app.php` excludes that one cookie
from encryption — safe rather than weakening, because the value is an opaque Sanctum token
verified by hashed lookup server-side, so a forged value simply fails to authenticate. It
keeps `httpOnly`, `SameSite=Lax` and, in production, `Secure`.

**Bronze note, and it is a real one:** if G0-A forces the frontend cross-origin, this cookie
must become `SameSite=None; Secure` and `SANCTUM_STATEFUL_DOMAINS` must widen.
`GATE-0-RESULT.md:961` already prices that as *"one to two weeks, and it needs an
auth-security review"*. **That review is a tenant-isolation review** — the host-only cookie
is currently doing isolation work that a widened cookie policy would relax.

### 5.5 Trusted proxies — deliberately not configured

`bootstrap/app.php` carries a long comment explaining that trusting proxies was added during
the 2026-08-15 domain audit and reverted the same day, because measurement showed it made
things strictly worse: a request carrying `X-Forwarded-For: 203.0.113.77` was recorded
verbatim in `login_histories` once proxies were trusted.

**The tenancy-critical line:** *"Never `X_FORWARDED_HOST`: the Host header is the tenant
selector, and trusting a client-supplied override would reintroduce tenant spoofing."*

**Bronze note:** the Plesk deployment does put a web server in front of PHP, and if the
frontend ends up proxying `/api` through the Node process then there *is* an HTTP proxy in
the path. The comment already says to revisit — and says to trust
`X_FORWARDED_FOR`/`PORT`/`PROTO` only. **Anything that makes the Host header
client-controllable is a tenant-spoofing vulnerability in this design.**

---

## 6. Defence in depth above the scope

- **`middleware.ts`** — hostname decides which application a request may reach: apex is
  public, `admin.<root>` is the platform console, `{tenant}.<root>` is the application. Its
  own docblock notes this made a *structural* boundary out of what had been a permission
  check — `habru.ethr.et/admin` previously rendered the console shell for anyone who typed
  it. It is **inert without `NEXT_PUBLIC_ROOT_DOMAIN`**, and `infrastructure/nginx.conf`
  refusing `/admin` on non-platform hosts is described as *the authoritative control*.
  **On Plesk that nginx is not deployed**, so the Next.js middleware becomes the only
  enforcement of that boundary — which is precisely the case its docblock anticipates
  (*"any deployment fronted by something other than that nginx"*). Under a **static export**
  the middleware does not run at all, and the boundary would fall back to
  `Gate::authorize('admin.manage')` alone.
- **`EnsurePlatformContext`** 404s whenever a tenant is resolved; **`RequirePlatformMfa`**
  guards the platform console.
- **`BlockImpersonatedActions`** is prepended *before* `SubstituteBindings`, because a
  blocked delete on a missing resource would otherwise leak a 404 existence oracle instead of
  a 403. `RejectUnverifiedMfaToken` is prepended for the same reason and a second one: it
  reads `$request->user()`, and run too early it *"sees no user, finds no token, and waves
  every request through — which is exactly how it behaved in the test suite while appearing
  to work when driven by hand."*
- **Relation-tenancy validation** — a `public_id` from another tenant on any of the seven
  employee relation fields now returns **422** rather than 201-with-a-silent-null, via a
  `withValidator()` hook. The message is Laravel's generic `validation.exists` line, identical
  to a genuinely nonexistent id, because a distinguishable message would confirm a `public_id`
  across tenants. Tests pin the two as equal **per field**.
- **The audit log is append-only at the database**, enforced by triggers because
  `AuditLog`'s `update()`/`delete()` overrides are instance methods that every mass path
  bypasses.

---

## 7. Risks, ranked

| # | Risk | Evidence | Bronze-specific? |
|---|---|---|---|
| 1 | **A cross-origin frontend would relax the host-only session cookie.** `SameSite=None; Secure` plus widened stateful domains removes a control currently doing isolation work | `bootstrap/app.php` cookie comments; `GATE-0-RESULT.md:961` | **Yes** — follows the G0-A decision |
| 2 | **44 of 159 bypasses are safe because of something else** — a gate, a dispatcher, a caller, a backfill. Safe today, and none of that safety is stated at the query | `BASELINE.md` §11i/§11j | No |
| 3 | **A static-export fallback removes `middleware.ts`**, and with it the structural `/admin` host boundary, on a target where the nginx that was *"the authoritative control"* is not deployed | `middleware.ts` docblock; `DEPLOYMENT.md` §5 fallback branch | **Yes** |
| 4 | **Wildcard subdomain is the only production tenant selector and is UNVERIFIED.** If tenants have to share a hostname, every layer above depends on a selector that does not exist | `ResolveTenant`; G0-C PARTIAL | **Yes** |
| 5 | **`FILESYSTEM_DISK=local` puts every tenant's documents under one account**, separated only by the prefix `FileStorageService` applies. Any writer that skips that service loses it | `FileStorageService::upload()`; `config/filesystems.php:16` | **Yes** |
| 6 | **Raw SQL carries no scope**, and nothing counts `whereRaw`/`DB::raw` the way `TenantScopeBypassInventoryTest` counts bypasses | root `CLAUDE.md` §4 | No |
| 7 | **The bypass inventory's own docblock is two behind its data** (157 vs 159), in the file people consult to settle the number | `tenant-scope-bypasses.php:11` vs the summed counts | No |
| 8 | **A `singleton` regression on `CurrentTenant` re-opens tenant bleed between queued jobs**, and the HTTP cron runner drains several jobs per process | `TenantServiceProvider.php:36`; `BASELINE.md` §11c | Partly |

---

## 8. What this audit did not do

- **Did not re-derive the 159 figure with a regex.** The root `CLAUDE.md` records a +5
  overcount that came from exactly that. The pinned file is the authority.
- **Did not individually re-read the 44 sites that do not prove their own safety.** §11i and
  §11j did that; repeating it without new evidence would produce a second figure nobody could
  reconcile.
- **Did not test anything.** This phase is read-only. Every claim above is from source.

---

## Related

- [`BASELINE.md`](BASELINE.md) §11b–§11j — the four bypass passes
- [`BRONZE-COMPATIBILITY-MATRIX.md`](BRONZE-COMPATIBILITY-MATRIX.md) §7 — the subdomain constraint
- [`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md) — G0-A and G0-C
- [`../SECURITY.md`](../SECURITY.md)
