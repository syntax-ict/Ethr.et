# Deploying ETHR to Ethio Telecom Linux **Gold** (Plesk) — gap analysis

**Read-only analysis, 2026-09-22. Nothing is deployed and no gate status is changed by
this document.** Authoritative gate state stays in
[`GATE-0-RESULT.md`](GATE-0-RESULT.md); the deployment contract stays in
[`SHARED-HOSTING-CONTRACT.md`](SHARED-HOSTING-CONTRACT.md).

---

## 0. The brief's premise does not hold, and it changes the answer

This analysis was requested as: *Laravel on the Gold plan + an external managed
PostgreSQL, because **tenant isolation depends on RLS, so no MySQL port***.

**Measured against the code, ETHR does not use PostgreSQL row-level security at all.**

| Claim | What the repository shows |
|---|---|
| Isolation depends on RLS | **False.** `api/app/Traits/BelongsToTenant.php:17-23` adds an **Eloquent global scope**: `where tenant_id = ?`, falling back to `whereRaw('0 = 1')` when no tenant is resolved. Application-layer and database-agnostic |
| RLS is present somewhere | **Zero** occurrences of `ROW LEVEL SECURITY`, `CREATE POLICY`, `FORCE ROW LEVEL`, `current_setting(` or `set_config(` anywhere under `api/` |

The fail-closed design the root `CLAUDE.md` describes — *"absence of context yields no rows,
not all rows"* — is that `whereRaw('0 = 1')`, not a database policy. It behaves identically
on MySQL, MariaDB, PostgreSQL or SQLite.

**So "no MySQL port" is not required by tenant isolation.** And moving to PostgreSQL is not
free:

| Cost | Evidence |
|---|---|
| The audit-log integrity triggers are **MySQL-only syntax** | `2026_07_22_000001_restrict_audit_log_to_insert_only.php:53-54` uses `SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT`. PostgreSQL needs a PL/pgSQL function plus trigger — a rewrite, not a config change |
| Three migrations carry MySQL-specific SQL | measured across `api/database/migrations/` |
| The platform requirement is MySQL | `api/composer.json` requires **`ext-pdo_mysql`**; it does **not** require `ext-pdo_pgsql` |
| PostgreSQL is **never exercised** | zero references to `pgsql`/`postgres` in `phpunit*.xml`, any `.github/workflows/*.yml`, or `scripts/gates.sh`. `gates.sh mysql` runs MariaDB. A `pgsql` block exists in `config/database.php`, but a configured driver is not a tested one |
| The plan ships MySQL | Gold includes **5 MySQL databases**; PostgreSQL would be an external paid service plus egress |

If RLS is wanted as *defence in depth* on top of the global scope, that remains a
legitimate architectural goal — but it is a **new requirement**, not an existing
dependency, and it must be costed as one.

### DECISION DB-1 — MySQL/MariaDB on the Plesk plan. No external PostgreSQL.

**Decided 2026-09-22 by the owner. This section is no longer "recorded, not decided".**

The decision rests on a repository-wide audit run specifically to test the brief's premise,
searching for every mechanism RLS would need:

| Searched for | Scope | Found |
|---|---|---|
| `CREATE POLICY` · `ALTER POLICY` · `DROP POLICY` | `api/`, then whole repo | **0** |
| `ENABLE ROW LEVEL SECURITY` · `FORCE ROW LEVEL SECURITY` | `api/`, then whole repo | **0** |
| `pg_policies` · `pg_policy` · `rowsecurity` | `api/` | **0** |
| `set_config(` · `SET LOCAL` · `current_setting(` | `api/`, then whole repo | **0** |
| an `app.current_tenant`-style session variable | `api/` | **0** |
| the **`ethr_app` role** and its `NOBYPASSRLS` grant | whole repo | **0** — the role does not exist in this repository; nothing grants it and no code path depends on it |
| any test asserting isolation **below** the query builder | `tests/Feature/Security/` (12 files) + `TenantIsolationTest` | **0** — no `DB::select`, `DB::statement`, `DB::connection`, `getPdo` or second connection anywhere in them |
| `.sql` files that could carry DDL out of band | `api/`, excluding `vendor/` | **0** — all 62 migrations are PHP |

**Isolation is `api/app/Traits/BelongsToTenant.php:17-23` and nothing else.** All three
environment templates already select MySQL: `DB_CONNECTION=mariadb` in
`.env.example:30`, `.env.production.example:42` and `.env.shared-hosting.example:51`.

Two things that look like PostgreSQL, and are not:

- `config/database.php:97-112` is Laravel 12's **unmodified stock `pgsql` scaffold**, down
  to `'search_path' => 'public'` and `DB_SSLMODE` defaulting to `prefer`. Every Laravel
  install ships it.
- `2026_07_22_000001_restrict_audit_log_to_insert_only.php:103-109` branches on
  `$driver === 'pgsql'` to issue `REVOKE UPDATE, DELETE ON audit_log`. That is a
  **table-privilege** revoke — which verbs a role may issue — and is orthogonal to RLS,
  which governs which rows a statement sees.

**One limitation, stated rather than glossed:** this audit covers the *repository*. No live
database was reachable from the environment it ran in, so it cannot exclude policies applied
out of band to some existing instance. `DB_CONNECTION=mariadb` in all three templates makes
that unlikely, and no instance is in service yet.

**What DB-1 changes below:** rows **#3** and **#6** of the gap table resolve. **It closes no
BLOCKER** — see the note under the blocker count.

---

## 1. Gap table

Legend — **OK** · **WORKAROUND** (possible, defined, costs something) · **BLOCKER**
(no known route today) · **ASK** (unknown; only the host can answer).

| # | Requirement | Docker prod provides | Gold shared hosting provides | Status |
|---|---|---|---|---|
| 1 | **PHP ≥ 8.2** | `php:8.2-fpm-alpine` | **8.3.33**, panel-read 2026-09-17 | **OK** |
| 2 | **`ext-pdo_mysql`** (declared requirement) | built in image | MySQL plan; extension presence unprobed | **ASK** |
| 3 | ~~**`ext-pdo_pgsql`**~~ | *not installed* — image builds `pdo_mysql` only | not needed | **RESOLVED by DECISION DB-1** — PostgreSQL is not being used, so this extension is not required. Was **ASK** |
| 4 | **Other extensions** (`gd`, `intl`, `zip`, `bcmath`, `mbstring`, …) | built in image | G0-E rows `NOT VERIFIED` — the probe cannot run (see #9) | **ASK** |
| 5 | **`ext-redis` / a Redis server** | `redis` + `redis-cache` services | **No Redis.** Shared hosting runs no long-running daemon | **WORKAROUND** — `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`. Already the documented shared-hosting env |
| 6 | ~~**Outbound TCP 5432 + TLS** to an external PostgreSQL~~ | N/A (DB is in-network) | N/A — MySQL is on the plan at `DB_HOST=localhost`, so there is no network hop | **RESOLVED by DECISION DB-1** — the requirement is withdrawn, not answered. Was **ASK**, and the only wholly-uncovered one. *If a remote database is ever adopted this row returns:* `smoke-check.sh` §4 asserts TLS on any non-loopback `DB_HOST` and refuses to pass without it |
| 7 | **Next.js runtime** | `frontend` service, `output: "standalone"`, `node server.js` on `node:22-alpine` | Plesk Node.js **present and startable** (22.23.2, startup file, app mode, app URL) but **not enabled**; G0-G `PARTIAL` | **ASK** |
| 8 | **`/` → Next, `/api/*` → Laravel routing** | nginx in-compose | Neither directive textarea appears on Apache & nginx Settings (read twice); G0-A `NOT VERIFIED`, strong evidence of FAIL | **BLOCKER** |
| 9 | **Queue worker** (`queue:work`) | `worker-realtime` + `worker-exports` | **No Scheduled Tasks section exists** on the subscription. G0-D **FAIL** | **BLOCKER** |
| 10 | **Scheduler** (`schedule:run`) | `scheduler` service | same absence — same root cause as #9 | **BLOCKER** |
| 11 | **Any `artisan` at all** (`key:generate`, `migrate`, `db:seed`) | `docker exec` | SSH **Forbidden**; Plesk Git *additional deployment actions* run on deploy | **WORKAROUND** — covers one-off install; cannot drive anything recurring |
| 12 | **Storage symlink + writable dirs** | named volume `api_storage` | `storage:link` needs a command run; writability unprobed | **ASK** — and gated by #11 |
| 13 | **Object storage** | `minio` service | none | **WORKAROUND** — local disk (prescribed: signed `temporaryUrl()` routes). An external S3 remains possible but would reintroduce the outbound-egress question that #6 just retired, and it is **unprobed either way** |
| 14 | **WebSockets** (Reverb) | `reverb` service | no long-running daemon; contract lists Reverb **NEVER REQUIRED** | **WORKAROUND** — `BROADCAST_CONNECTION=log`, already prescribed |
| 15 | **SSH / Git deploy** | N/A | SSH **Forbidden**; Plesk **Git** and **Composer** extensions present | **WORKAROUND** — contract makes SSH OPTIONAL |
| 16 | **SSL on `ethr.et`, `api.`, `app.`** | own certs | per-hostname Let's Encrypt **PROVEN** on this account | **OK** for named subdomains |
| 17 | **Wildcard SSL `*.ethr.et`** (tenant subdomains) | own certs | wildcard **blocked** — needs DNS-01; G0-C `PARTIAL` | **BLOCKER** for subdomain tenancy |
| 18 | **Subdomain quota vs tenant count** | unlimited | Gold publishes **15**; whether a wildcard counts as one is an **OPEN QUESTION** | **ASK** |
| 19 | **Mail** | SMTP config | G0-H `NOT VERIFIED` — outbound 587/465 unprobed; plan includes 25 mailboxes | **ASK** |
| 20 | **Biometric device callbacks** reaching the API | nginx → api | inbound HTTPS to a named host is ordinary web traffic; depends on #8 and #16 | **ASK** — no device has ever reached this host |
| 21 | **Backups** | `ethr:backup` + volumes | `ethr:backup`/`ethr:restore` are **plain PHP CLI by design** | **BLOCKER** — same root as #9/#10 |
| 22 | **`CREATE TRIGGER`** (audit-log integrity) | granted locally | G0-F `NOT VERIFIED`; managed MySQL often denies it (error 1419) | **ASK** |

### Blocker count

**Four distinct blockers**, from **three** root causes:

| Root cause | Manifests as |
|---|---|
| **G0-D — no Scheduled Tasks** | #9 queue worker · #10 scheduler · #21 backups |
| **G0-A — no custom directives** | #8 path routing |
| **G0-C — no wildcard TLS** | #17 subdomain tenancy |

Counting by symptom it is four (#8, #9, #10, #17, #21 → five entries, three of which share
G0-D). **Counting by what must actually be solved: three.**

> **#9/#10/#21 are one problem, and it is the pre-registered one.**
> `SHARED_HOSTING_MIGRATION_PLAN.md` §4's decision rule already fired on G0-D and returned
> **No-Go → Option A**. Nothing in this analysis reverses that; a PostgreSQL backend does
> not give the host a cron runner.

#### What DECISION DB-1 did and did not close

**Closed: two ASK rows — #3 (`ext-pdo_pgsql`) and #6 (outbound 5432 + TLS).**

**Closed: zero BLOCKERs.** The blocker count is **unchanged at four symptoms from three
root causes**. This is worth stating plainly because a resolved-rows count reads like
progress and here it is not: neither #3 nor #6 was ever a blocker, and the three root
causes — G0-D, G0-A, G0-C — are all properties of the *host*, which a database choice
cannot touch.

What DB-1 buys is the removal of *uncertainty*, not of obstruction: #6 was the only
requirement in this table that nothing in `GATE-0-RESULT.md` or
`HOSTING_VERIFICATION_CHECKLIST.md` covered at all, so retiring it means every remaining
open row is at least tracked somewhere.

Counted off the table rather than estimated:

| | Before DB-1 | After DB-1 |
|---|---|---|
| **BLOCKER** rows | 5 (#8, #9, #10, #17, #21) | **5 — unchanged** |
| Distinct blockers by symptom | 4 | **4 — unchanged** |
| Root causes behind them | 3 (G0-D, G0-A, G0-C) | **3 — unchanged** |
| **ASK** rows | 10 | **8** |
| **RESOLVED** rows | 0 | **2** (#3, #6) |

*(OK 2 · WORKAROUND 5 · 22 rows total, both before and after — no row was deleted, so the
table still accounts for every requirement Docker production provides.)*

---

## 2. What the existing ticket covers — and what it does not

[`ETHIO-TELECOM-SUPPORT-REQUEST.md`](ETHIO-TELECOM-SUPPORT-REQUEST.md) asks four things:
**1** Scheduled Tasks (cron) · **2** what the higher plans provide · **3** `TRIGGER` ·
**4** SSH.

| Gap | Covered? | By which ask |
|---|---|---|
| #9 #10 #21 queue / scheduler / backups | ✅ | ask 1 — directly, and it asks for **two** cron lines |
| #22 `CREATE TRIGGER` | ✅ | ask 3 |
| #11 running `artisan` | ✅ | asks 1 and 4 |
| #8 custom nginx/Apache directives | ✅ | ask 2 — *"the ability to add custom Apache or nginx directives"* |
| #7 Node.js runtime on a higher plan | ✅ | ask 2 — *"a Node.js runtime"* |
| #18 subdomain counting | ✅ | ask 2 — the wildcard-counting bullet |
| #2 #3 #4 PHP extensions incl. `pdo_pgsql` | ❌ | **not asked** |
| #6 **outbound TCP 5432 + TLS** | ❌ | **not asked — and not mentioned anywhere in the repository** |
| #17 wildcard TLS / DNS-01 | ❌ | **not asked** |
| #19 outbound SMTP 587/465 | ❌ | **not asked** |
| #12 writable dirs / `storage:link` | ❌ | **not asked** |
| #13 external S3 egress | ❌ | **not asked** |

**Six uncovered host questions.** The most consequential is **#6**: the requested
architecture rests entirely on the account being able to open an outbound TLS connection to
port 5432, and nobody has ever asked. Shared hosts commonly restrict outbound ports to
80/443.

> **Not proposed here:** adding these to the existing ticket. Its ask count is pinned by
> `HostingRequirementsConsistencyTest` and mirrored in its title and `docs/README.md`, and
> ask 1 is the one blocker everything waits on. Widening it risks the answer that matters.
> A **second** ticket, once ask 1 returns, is the cheaper shape.

---

## 3. Recommendation

### Recommended — Laravel + **MySQL on the Gold plan**, Next.js as a **static export**, on one vhost

| Layer | Where |
|---|---|
| Frontend | **Static export** (`output: "export"`), served from the same document root |
| API | Laravel on Gold, `api/public` as the front controller |
| Database | **MySQL on the plan** (Gold includes 5) |

**Why.**

1. **It removes a blocker instead of adding one.** Same-origin static files plus
   `.htaccess` routing `^/(api|sanctum)` need **no custom nginx directives**, which is
   exactly what G0-A (#8) cannot currently provide. A Node app would *depend* on the
   blocker.
2. **It does not invent a requirement.** Tenant isolation is a global scope, not RLS
   (§0). PostgreSQL buys a feature the application does not use, at the cost of rewriting
   MySQL-only audit triggers and running on a database no gate has ever exercised.
3. **It avoids #6 entirely.** No outbound 5432, no TLS-to-external-DB question, no egress
   billing — and no dependency on an unasked, plausibly-denied capability.
4. **It keeps the data in the plan you are paying for.**

**It does not fix G0-D.** Queue, scheduler and backups (#9/#10/#21) still have no runner,
so this is the right *shape* and still gated on ask 1.

**And the static export is not free — it was built on 2026-09-22 and costs ≈8 days
(6–11), of which 3 are gated on `.htaccess` being honoured.** §3A carries the measured
breakdown: seven distinct build failures, the actual mechanism entity routes need, and two
controls that disappear silently. Read it before committing to this recommendation —
**A is bounded only if G0-B.1/B.2 come back positive**, and the canary answers that today.

---

## 3A. The frontend: three options, one criteria set

The database is settled (DECISION DB-1). The frontend is not. Three options are live, and
they are compared here on identical criteria.

**Measured vs estimated is marked throughout.** Everything under *"what the repo shows"* was
read from the code on 2026-09-22. Money and latency are **estimates** — this repository
holds no pricing data and no latency measurement of any kind, and none was invented.

### The options

| | Frontend | API | Tenant hostnames |
|---|---|---|---|
| **A — static export** | `output: "export"`, same document root as `index.php` | Laravel on Gold | `{tenant}.ethr.et` on Plesk |
| **B — Node on Plesk** | Plesk Node.js app (22.23.2), `output: "standalone"` | Laravel on Gold | `{tenant}.ethr.et` on Plesk |
| **C — split hosting** | Next.js on Vercel or equivalent | Laravel on Gold at `api.ethr.et` | frontend on the platform; **API hostnames are the open question** |

### What Option C would change in the repository

| Concern | What the repo shows today | What C needs |
|---|---|---|
| **API base URL** | `src/api/client.ts:14` — `baseURL: "/api/v1"`, **relative**. There is **no `NEXT_PUBLIC_API_URL`** among the ten `NEXT_PUBLIC_*` vars in use | Introduce an absolute, env-driven base URL. Small and mechanical |
| **CORS** | `config/cors.php` already has `'supports_credentials' => true`, origins from `CORS_ALLOWED_ORIGINS`, and `X-Tenant` in `allowed_headers` | **Configuration only.** Set the origin list. No code change |
| **Auth transport** | `client.ts:19` — `withCredentials: true` and **no `Authorization` header**. Auth is an httpOnly cookie (`SESSION_HTTP_ONLY` default true, `SESSION_SAME_SITE` default `lax`, Secure in production) carrying an opaque Sanctum token, read by `AuthenticateFromCookie` | **Nothing, if the frontend is served under `ethr.et`.** See below |
| **Sanctum** | `bootstrap/app.php:50` — `statefulApi()` enabled; `SANCTUM_STATEFUL_DOMAINS` is env-driven | Add the frontend host to the env list. Configuration only |
| **Tenant resolution** | `ResolveTenant.php:108-125` — **`X-Tenant` is refused outside `local`/`testing`.** *"In production the hostname is the only tenant selector"* | **This is the problem. See below** |

#### The good news: cross-origin auth is *not* the hard part

The brief anticipates *"cross-origin auth"* as Option C's new risk. Measured, it is cheaper
than it looks — **provided the frontend is served on a hostname under `ethr.et`** (a custom
domain pointed at the platform, e.g. `app.ethr.et` or `{tenant}.ethr.et`):

- `app.ethr.et` and `api.ethr.et` share the registrable domain `ethr.et`, so they are
  **same-site**. `SameSite=Lax` cookies are sent on those XHRs. No downgrade to
  `SameSite=None` is required, and the CSRF surface that would open does not open.
- `SESSION_DOMAIN` is already env-driven (`config/session.php:159`); setting `.ethr.et`
  scopes the cookie across both hosts.
- They are still different **origins**, so CORS applies — but `supports_credentials` is
  already `true` and the origin list is already env-driven.

If instead the frontend is left on a platform-owned hostname (`*.vercel.app`), all of that
inverts: cross-**site**, so `SameSite=None; Secure` becomes mandatory, and the cookie
becomes a CSRF target — or the app moves to `Authorization: Bearer`, which puts the token
in JavaScript-reachable storage and trades CSRF for XSS. **Neither is necessary. Use a
custom domain.**

#### The real obstacle: tenancy is bound to the Host header

`ResolveTenant` resolves the tenant from `$request->getHost()` and **refuses** every
alternative in production. Its docblock gives the reason:

> *Honouring it in production would mean the tenant is chosen by the client rather than by
> the host — which is precisely the property that made a leaked or borrowed token able to
> read another tenant's data before `EnsureUserBelongsToTenant` existed. Two independent
> controls are better than one.*

`bootstrap/app.php` independently forbids the proxy workaround:

> *Never `X_FORWARDED_HOST`: the Host header is the tenant selector, and trusting a
> client-supplied override would reintroduce tenant spoofing.*

Both of Option C's shapes run into this:

| Shape | What the browser sees | What the API receives | Result |
|---|---|---|---|
| **C1** — frontend calls `https://api.ethr.et` directly | cross-origin, same-site; cookies fine | `Host: api.ethr.et` | **No tenant resolves.** `BelongsToTenant` fails closed → every query returns zero rows |
| **C2** — Next `rewrites()` proxies `/api/*` to the API (the mechanism already at `next.config.ts:88`) | same-origin; no CORS at all | `Host: api.ethr.et` — a proxy rewrites Host, and `X-Forwarded-Host` is explicitly untrusted | **Same failure**, plus it collides with a documented security decision |

So Option C is not "CORS plus a base URL". It requires **one of three** changes, each
larger than the frontend work it replaces:

1. **Per-tenant API hostnames** (`{tenant}.ethr.et` answering the API) — which needs
   wildcard TLS on Plesk. **That is blocker #17, returning.** Option C does *not* escape it.
2. **Honour a tenant header in production** — reversing a control the code argues for
   explicitly, and giving up the second of two independent controls.
3. **Move tenancy off the hostname** (path- or token-scoped) — a genuine architectural
   change touching `ResolveTenant`, `EnsurePlatformContext`, the subdomain rules,
   `SubdomainCheckController`, and every place `RESERVED_SUBDOMAINS` is consulted.

**Not costed here**, because costing it needs a design decision first. Flagged as the
gating question for C.

### Option A's cost, measured rather than asserted

**Everything in this subsection was produced by building it on 2026-09-22.** The project
was copied to a scratchpad, `node_modules` hard-linked, `output: "export"` set **there**,
and built repeatedly — fixing one failure to reveal the next. The repository was not
modified. Where this contradicts what §3 and the bullets below previously said, **the
measurement wins**; the earlier text was written from this repository's prior assertions,
not from a build.

#### Build failures, in the order they occur

| # | File | Error (verbatim) | Fix | Kind |
|---|---|---|---|---|
| 1 | `src/app/manifest.ts` | `export const dynamic = "force-static"/export const revalidate not configured on route "/manifest.webmanifest"` | add `export const dynamic = "force-static"` | mechanical, 1 line |
| 2 | `src/app/robots.ts` | same, route `/robots.txt` | same | mechanical, 1 line |
| 3 | `src/app/sitemap.ts` | same, route `/sitemap.xml` | same | mechanical, 1 line |
| 4 | 4 × `[id]/page.tsx` | `Page "/admin/tenants/[id]" is missing "generateStaticParams()"` | see below | design |
| 5 | same | `App pages cannot use both "use client" and export function "generateStaticParams()"` | server/client split, forwarding `params` | mechanical shape, per route |
| 6 | same | `returned an empty array from "generateStaticParams()". With "output: export", at least one route must be generated` | placeholder param | design |
| 7 | `src/app/(auth)/layout.tsx:35` | `Route /login with dynamic = "error" couldn't be rendered statically because it used headers()` | move the host read client-side | design, with a known regression |

**Correction — this document previously named only `manifest.ts`.** `robots.ts` and
`sitemap.ts` fail identically. `sitemap.ts` was confirmed **independently**, by reverting
its fix and watching the build fail again on `/sitemap.xml`.

**#7 is not free.** That file's own docblock records why it reads `headers()`: the
client-side host read it replaced caused **React hydration error #418 on every tenant login
page**, because server and client disagreed about the heading and whether to show the
organisation field. Reverting it reintroduces that bug unless the layout defers render
until mounted (a visible flash) or the markup is made host-independent.

#### Two consequences with no error and no build failure

**`headers()` in `next.config.ts:33-77` is silently dropped.** The build prints
`⚠ Specified "headers" will not automatically work with "output: export"` and continues.
**CSP, HSTS, X-Frame-Options and Permissions-Policy stop being served.** Nothing fails; the
site simply loses them. Restoring them means `.htaccess` — which is **G0-B.2**, unverified.

**`middleware.ts` does not block the build — correcting this document's earlier claim that
A "requires deleting it".** The export builds with the file present; Next 16 emits only a
deprecation warning. What actually happens is worse than a build error, because it is
silent: a static host cannot execute middleware, so its two behaviours simply stop. It
enforces the **host-based `/admin` boundary** and **locale redirects**.

Measured: `out/` contains **6 `admin*.html` files** as ordinary static files in the same
document root, so under one vhost **every tenant hostname serves the admin console shell**.
The middleware's docblock names its backstop — *"Nginx refuses `/admin` on non-platform
hosts too… That is the authoritative control"* — and **on this plan that backstop does not
exist**, because G0-A (#8) reports no custom directives. Neither control would be in force.

Data is not exposed: `Gate::authorize('admin.manage')` and `<RoleGate minRole="super_admin">`
both still refuse. This is a **defence-in-depth regression**, not a breach — but it returns
the boundary to exactly the *"permission check rather than structural"* state the middleware
was written to fix. `.htaccess` could restore it, which is **G0-B.1**, also unverified.

#### How a static export actually serves entity routes

Affected: `/employees/[id]`, `/devices/[id]`, `/payroll/[id]`, `/admin/tenants/[id]`.

This document previously said they "404 because the ids are tenant data". That is the
symptom. The mechanism, measured, is:

1. `generateStaticParams` **cannot return `[]`** — Next rejects it outright (failure #6). It
   must return at least one **placeholder**, e.g. `[{ id: "_" }]`.
2. The export then emits **exactly one file per route**. Measured: `out/employees/_.html`
   and nothing else.
3. The web server must rewrite `/employees/*` → `/employees/_.html` — a classic SPA
   fallback, in `.htaccess`.
4. **Each page must read the real id from the URL after hydration**, not from `params`.

**Step 4 is mandatory, and step 3 alone is not enough.** `out/` was served with exactly that
rewrite and driven with a real browser. Loading `/employees/01HTESTID` rendered the page,
`location.pathname` was `/employees/01HTESTID` — and the hydrated application requested:

```
/api/v1/employees/_
```

**The placeholder, not the id.** All four pages do `const { id } = use(params)`
(`employees/[id]/page.tsx:45`, `devices:133`, `payroll:56`, `admin/tenants:74`), and under
export `params` is frozen to the build-time value. So the rewrite alone ships pages that
always fetch the wrong record. Each page needs its id source changed to a
`usePathname()`-derived read.

**So Option A is bounded — but only if `.htaccess` is honoured.** The mechanism above is
real and standard. Step 3 *is* `.htaccess`. If G0-B.1 comes back negative there is no
rewrite, and with no rewrite there is no mechanism at all for entity routes — at which point
**A stops being bounded**, alongside losing the security headers (G0-B.2) and the `/admin`
boundary. That is the single contingency this estimate rests on.

**Not measured, and it matters:** whether *in-app* client navigation passes the real id.
Next's client router parses the URL, so it plausibly does. If so the failure mode is *works
when you click through, breaks on refresh and on every shared link* — which changes how
likely the defect is to reach production, but not the fix.

#### Estimate

**≈ 8 days, realistically 6–11.** Marked **M** measured · **E** estimated.

| Work | Days | Basis | Gated on |
|---|---|---|---|
| `force-static` × 3 | 0.1 | **M** — build-verified | — |
| Server/client split × 4 + `params` forwarding | 1.0 | **M** shape, **E** polish on 500–800-line components | — |
| Placeholder params + `.htaccess` rewrites × 4 | 0.5 | **E** — mechanism proven **M**, `.htaccess` unwritten | **G0-B.1** |
| Change id source `params` → URL in 4 pages + tests | 1.5 | **E** — the measured `employees/_` defect | — |
| `(auth)/layout.tsx` host read + fix hydration #418 | 1.5 | **E** — regression documented, not reproduced | — |
| Restore CSP/HSTS/X-Frame-Options via `.htaccess` | 0.5 | **E** | **G0-B.2** |
| Restore the `/admin` host boundary | 1.0 | **E** | **G0-B.1** |
| Locale redirects lost with middleware | 0.5 | **E** — `out/en/*` and `out/am/*` exist **M**; unprefixed forwarding does not | — |
| Full-suite regression + manual pass | 1.5 | **E** | — |
| **Total** | **≈ 8** | ~1.6 measured-grounded, ~6.5 estimated | **3.0 days gated on G0-B.1/B.2** |

**Three line items totalling 3 days are gated on G0-B.1/B.2**, which the canary answers with
no shell, no cron and no support ticket — and which is already item 4 of
`MIGRATION_STATE.md`'s next-action list. **Run it before committing to A**: it converts the
widest part of this estimate into a measurement, and it is the one check that can show A to
be unbounded before the work starts rather than during it.

### What Option C removes

Real, and worth weighing against the above:

- **The static-export build failures above** — all seven. C keeps SSR, so none apply.
- **The entity-route mechanism.** C serves `/employees/123` directly. No placeholder, no
  SPA rewrite, no per-page id rewiring, and no dependency on `.htaccess` for any of it.
- **The silent losses.** C keeps `next.config.ts`'s `headers()` (CSP/HSTS) and keeps
  `middleware.ts` (the `/admin` host boundary, locale redirects) working as written —
  neither of which A can retain without `.htaccess`.
- **Node-on-shared-hosting risk.** B depends on enabling Plesk Node.js while
  `Document Root: /ethr` is still unexplained (B-3), and on the version being 22.23.2
  against an `.nvmrc` pin of 24. C moves the runtime to a platform built for it.
- **Wildcard TLS for the frontend.** Platforms of this kind issue wildcard certificates as
  a standard feature — which is blocker #17 solved *for the frontend*. It is **not** solved
  for the API, which is the point made above.

### What Option C adds

- **A second vendor**, a second bill, a second status page, and a second place a deploy can
  fail. The migration's stated direction is *fewer* moving parts.
- **A foreign dependency for a product serving Ethiopian organisations** — data residency
  and procurement may matter here in ways this document cannot assess.
- **Latency.** *Estimated, not measured.* The frontend would be served from a global CDN
  edge; the nearest point of presence to Addis Ababa is plausibly Europe or the Gulf, so
  first-byte for HTML/SSR would likely be worse than a local host, while static assets
  would likely be better. **Every API call still crosses to `ethr.et` regardless**, so the
  data path is unchanged. This repository contains no latency measurement of any kind and
  none was invented; the offline-first design (IndexedDB queue, service worker) blunts but
  does not remove the concern.
- **Cost.** *Estimated.* The Gold plan is already paid. A platform tier with wildcard
  custom domains is an additional recurring subscription in the low tens of USD per month,
  plus bandwidth. **Confirm current pricing before relying on any figure** — none is
  recorded in this repository, including in `TCO_COMPARISON.md`.

### Comparison

Same criteria, all three. **M** = measured from the repo · **E** = estimated.

| Criterion | A — static export | B — Node on Plesk | C — split hosting |
|---|---|---|---|
| Depends on **G0-A** (#8, no directives) | **No** — `.htaccess` suffices *(M)* | **Yes** *(M)* | No *(M)* |
| Depends on **G0-B.1/B.2** (`.htaccess` honoured) | **Yes — and it is load-bearing.** Entity routes, CSP/HSTS and the `/admin` boundary all rest on it *(M)* | No *(M)* | No *(M)* |
| Depends on **G0-C** (#17, wildcard TLS) | Yes *(M)* | Yes *(M)* | **Yes, for the API** *(M)* |
| Depends on **G0-D** (#9/#10/#21) | Yes *(M)* | Yes *(M)* | Yes *(M)* — unchanged by any frontend choice |
| Frontend build works today | **No** — 7 distinct failures *(M)* | **Yes** *(M)* | **Yes** *(M)* |
| Entity routes work | No — need placeholder + SPA rewrite + URL-derived id *(M)* | Yes *(M)* | Yes *(M)* |
| Repo changes needed | `force-static` ×3, split 4 routes, placeholder params, rewire 4 id reads, client-side host read in `(auth)/layout.tsx` *(M)*. **Not** deleting `middleware.ts` — it builds fine and goes inert *(M)* | **None** *(M)* | base URL + CORS/Sanctum config, **plus a tenancy decision** *(M)* |
| Engineering cost | **≈8 days, 6–11** *(E)*, of which **3 days gated on G0-B.1/B.2** | ~0 | base URL + config *(M)*; tenancy change **uncosted** |
| Vendors | 1 | 1 | **2** |
| Extra recurring cost | none | none | low tens of USD/month *(E)* |
| SSR retained | No *(M)* | Yes *(M)* | Yes *(M)* |
| Loses CSP/HSTS headers | **Yes, silently** — `next.config.ts:33-77` dropped with a warning *(M)*; recoverable only via `.htaccess` | No *(M)* | No *(M)* |
| Loses the `/admin` host boundary | **Yes, silently** — middleware inert, and its documented nginx backstop is what G0-A says is absent *(M)* | No *(M)* | No *(M)* |
| New security surface | **defence-in-depth regression** (above); data still protected by `admin.manage` + `RoleGate` *(M)* | none | cross-origin auth — **manageable on a custom domain** *(M)*; tenancy change is the real risk *(M)* |
| Blocked on an unanswered host question | ask 1 **+ the canary (G0-B.1/B.2)** | ask 1 **+ Node while B-3 open** | ask 1 |

### Recommendation

**Keep A as the recommended target — but run the canary before committing to it. A is
bounded only if `.htaccess` is honoured.** Hold C as the contingency.

Reasoning, in the order it decides:

1. **No frontend option closes the blocker that actually matters.** G0-D gates all three
   identically. Choosing between them does not make the product deployable; answering ask 1
   does.
2. **C does not escape #17.** It looked like it might — a platform gives wildcard TLS for
   free — but the API still needs per-tenant hostnames, so the wildcard problem moves
   rather than disappears. That is the single finding that keeps C from being the answer.
3. **C's true cost is a tenancy change, not a CORS change**, and it is uncosted. A's cost
   is now **measured at ≈8 days, realistically 6–11**. A known cost beats an unknown one —
   *provided the condition in 4 holds*.
4. **A's boundedness is conditional, and this is the sentence to act on.** Three of its
   nine line items — **3 of the 8 days** — are gated on **G0-B.1/B.2**. `.htaccess` is not
   a convenience for A; it is the mechanism. It carries the SPA rewrite without which
   entity routes have **no way to be served at all**, the CSP/HSTS headers that static
   export drops silently, and the `/admin` host boundary that middleware can no longer
   enforce. **If `.htaccess` is not honoured, A is not bounded either** — and the estimate
   above should be treated as void rather than merely optimistic.
5. **B is the weakest.** It depends on G0-A, which has the strongest evidence of FAIL of
   any open gate, *and* on enabling Node while B-3 is unexplained.
6. **C becomes the right answer if** the canary shows `.htaccess` is ignored, *or* if G0-A
   is confirmed FAIL and someone decides tenancy can move off the hostname. The first is a
   measurement that can be taken today; the second is a decision nobody has made.

> **The next action this section implies is not a choice between A and C.** It is the
> canary — five files, six fetches, no shell, no cron, no support ticket, already item 4 of
> `MIGRATION_STATE.md`'s next-action list. It answers G0-B.1 through B.5, and it is the one
> check that can show A to be unbounded *before* the eight days are spent rather than
> during them.

**Unchanged by any of this:** the pre-registered **No-Go → Option A (stay on the VPS)**
still stands, because it fired on G0-D.

---

## 4. What this document does not do

No gate status changes. No architecture is adopted — this is analysis, and
`SHARED_HOSTING_MIGRATION_PLAN.md` §4's **No-Go → Option A** stands until G0-D moves.
`SHARED-HOSTING-CONTRACT.md` is untouched.
