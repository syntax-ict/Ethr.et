# Deploying ETHR to Ethio Telecom Linux **Gold** (Plesk) — gap analysis

> **The tier in that title is ASSUMED, and it is not this document's to settle.** *Gold* is
> the **brief's** premise (§0), not a reading of this account. The account is reported
> **Bronze** — owner-supplied on 2026-08-29 (`../B1-B5_GATE_REPORT.md`, *Target account*),
> repeated in the never-sent support request and in
> [`shared-hosting/DEPLOYMENT.md`](shared-hosting/DEPLOYMENT.md)`:3`. That is **testimony,
> not panel output**, which this repository grades PARTIAL wherever else it appears.
> **Nobody has read the tier from the panel.** It is open question **Q9** (§5.8) and it stays
> open here — deciding it needs the Plesk panel and the owner, not a document edit.
>
> Consequently every per-tier figure below — databases, subdomains, mailboxes, storage,
> bandwidth — is **PUBLISHED** plan data applied to an **ASSUMED** tier. §5.4 works through
> which of them would change on Bronze; the short answer it reaches is *one operational
> setting and one sharpened question*. The title is left unrenamed because this document is
> cited by name and date elsewhere.

**Read-only analysis, 2026-09-22; §5 added 2026-09-23. Nothing is deployed and no gate
status is changed by this document.** Authoritative gate state stays in
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
| The plan ships MySQL | Gold includes **5 MySQL databases** — **PUBLISHED**, on an **ASSUMED** tier (Bronze publishes 1; Q9). ETHR needs one either way, so nothing here turns on it. PostgreSQL would be an external paid service plus egress |

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
| 4 | **Other extensions** (`gd`, `intl`, `zip`, `bcmath`, `mbstring`, …) | built in image | G0-E rows `NOT VERIFIED` — the probe has not been run. *(Corrected 2026-09-23: this said the probe "cannot run". It has a web-execution mode — see `MIGRATION_STATE.md` NEXT ACTION item 2.)* | **ASK** |
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
| 18 | **Subdomain quota vs tenant count** | unlimited | Gold publishes **15**, Bronze **5** — **PUBLISHED** figures on an **ASSUMED** tier (Q9); whether a wildcard counts as one is an **OPEN QUESTION** (Q3), and that counting rule, not the tier, is what decides whether the number binds at all | **ASK** |
| 19 | **Mail** | SMTP config | G0-H `NOT VERIFIED` — outbound 587/465 unprobed; the mailbox count is **PUBLISHED** — 25 on Gold, 5 on Bronze — on an **ASSUMED** tier (Q9) | **ASK** |
| 20 | **Biometric device callbacks** reaching the API | nginx → api | inbound HTTPS to a named host is ordinary web traffic; depends on #8 and #16 | **ASK** — no device has ever reached this host |
| 21 | **Backups** | `ethr:backup` + volumes | `ethr:backup`/`ethr:restore` are **plain PHP CLI by design** | **BLOCKER** — same root as #9/#10. §5.1 splits this row: the backup half has a route under denial, the restore half does not |
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

> **The letters A, B and C below are local to this section.** They answer *which
> frontend, given shared hosting*. In
> [`../SHARED_HOSTING_MIGRATION_PLAN.md`](../SHARED_HOSTING_MIGRATION_PLAN.md) §4 the same
> letters answer a different question — **Option A** there is *stay on the VPS* and
> **Option B** is *everything on shared hosting*. Neither set is renamed, because both are
> cited by date elsewhere. §5's naming warning has the full picture; where a reader needs
> them, name them in full.

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
(6–11), of which 2.0 are line items gated on `.htaccess` being honoured.** That 2.0 is the
exposure if the gate merely makes work conditional. It is **not** the exposure if the gate
fails: a **G0-B.1 failure voids the whole ≈8-day estimate**, because `.htaccess` carries the
SPA rewrite without which entity routes have no serving mechanism at all — there is nothing
left to re-cost. §3A carries the measured
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

> **Read the Gated column as a void, not a discount.** The three gated rows total **2.0
> days**, and that is what they cost if `.htaccess` merely makes the work conditional. It is
> not what a **G0-B.1 failure** costs. `.htaccess` is the mechanism by which entity routes
> are served *at all*, so on a B.1 failure this whole table is **VOID** — the ≈8 days is
> discarded, not revised down to ≈6, and Option A stops being bounded. That consequence is
> pre-registered in
> [`../../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md`](../../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md)
> (the *B.1 FAIL* row), and it is the reading to act on.

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
| **Total** | **≈ 8** | ~1.6 measured-grounded, ~6.5 estimated | **2.0 days gated on G0-B.1/B.2 — and all 8 void if B.1 fails** |

**Three line items totalling 2 days are gated on G0-B.1/B.2**, which the canary answers with
no shell, no cron and no support ticket — and which is already item 4 of
`MIGRATION_STATE.md`'s next-action list. **Run it before committing to A**: it converts the
widest part of this estimate into a measurement, and it is the one check that can show A to
be unbounded before the work starts rather than during it.

> **Arithmetic corrected 2026-09-23.** This paragraph, the Total row, §3A's comparison
> table, §3A.4 and the next-action table all said **3 days** gated. The table's own gated
> rows are 0.5 + 0.5 + 1.0 = **2.0**, and the nine rows total **8.1**. Recorded rather than
> silently fixed, because the figure was quoted in five places and a reader who remembers
> "3 days" should know which number moved and why. *"Three of its nine line items"* was
> always right — three rows are gated; only the day count was wrong.
>
> **And "five places" was itself short — corrected later the same day.** The sweep that
> produced this note matched the literal string *"3 days gated"*, which missed three
> variants that say the same thing in other words: §5.1's blocker row (*"3 of them gated"*),
> §5.1's closing cost line (*"3 days are void"*) and §5.5 step 4 (*"3 days conditional"*),
> plus one in `../MIGRATION_STATE.md`. **Eight places, not five.** All eight now read 2.0.
> The lesson is the ordinary one for this corpus: a figure is quoted in more wordings than
> the one you remember, so sweep for the *claim*, not for the sentence.

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
- **Cost.** *Estimated.* The shared-hosting plan is already paid, whichever tier it is
  (**ASSUMED**; Q9). A platform tier with wildcard
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
| Engineering cost | **≈8 days, 6–11** *(E)*, of which **2.0 days gated on G0-B.1/B.2** — and **all 8 void, not reduced, if B.1 fails** *(M — the mechanism; see above)* | ~0 | base URL + config *(M)*; tenancy change **uncosted** |
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
   nine line items — **2.0 of the 8 days** — are gated on **G0-B.1/B.2**, and that 2.0 is
   the *conditional* cost, not the *failure* cost: **if B.1 comes back negative the whole
   ≈8-day estimate is void**, not reduced. `.htaccess` is not
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

**This applies to §5 below as well**, which was added later and under a different premise.

---

## 5. Plan B — deploy assuming no host concessions

**Added 2026-09-23, at the owner's direction, after no reply from Ethio Telecom.**

**No gate status changes here either.** §4's disclaimer covers this section: **G0-D stays
FAIL**, G0-A stays `NOT VERIFIED`, G0-C and G0-G stay `PARTIAL`. What changes is not any
gate's value but what follows from it — and the central finding below is precisely that
**G0-D = FAIL is still true and is no longer fatal.**

### 5.0 The assumption set, and one naming warning

Working assumption, set by the owner: **all four asks in
[`ETHIO-TELECOM-SUPPORT-REQUEST.md`](ETHIO-TELECOM-SUPPORT-REQUEST.md) are DENIED.** No
Scheduled Tasks, no plan upgrade, no `TRIGGER` grant, no SSH. Anything not in the plan's
published or default feature set is unavailable.

This is an **assumed** premise, not a measured one — the ticket was never sent. Everything
downstream of it inherits that. Individual facts are marked:

| Mark | Meaning |
|---|---|
| **MEASURED** | read from this repository's code, or from the account by the owner, on a dated occasion |
| **PUBLISHED** | from Ethio Telecom's own plan pages, as transcribed in the support request |
| **ASSUMED** | neither — reasoning, or a third party's documented behaviour taken on trust |

> **A boundary inside MEASURED, because the tier landed on it.** *"Read from the account by
> the owner"* means the owner says **what page they read**. Every row in §5.2's MEASURED
> table names one — Hosting Settings, Dev Tools, Apache & nginx Settings, the subscription
> dashboard. A fact the owner supplied without naming what was read is **ASSUMED**, however
> dated and however plausible: `GATE-0-RESULT.md`'s own rule is that **testimony is not
> output**, and `../B1-B5_GATE_REPORT.md` applies it to downgrade B1b on exactly that
> ground. **The plan tier is the instance** — supplied 2026-08-29 under *Target account*,
> no panel page named, never re-read. See the note under this document's title, and Q9.

> **Naming warning, because three different things are now called "B".** In §3A of this
> document **B** is *Node on Plesk*. In
> [`../SHARED_HOSTING_MIGRATION_PLAN.md`](../SHARED_HOSTING_MIGRATION_PLAN.md) §4 **Option
> B** is *everything on shared hosting* and **Option A** is *stay on the VPS*. This section
> is **"Plan B"** in the owner's sense — *the fallback if nobody answers* — and it is none
> of those three. Where it needs them it names them in full.

### 5.1 Every requirement that was waiting on an ask, under denial

Three outcomes: **RESOLVED ANOTHER WAY** (a route exists that needs nothing from the host),
**DEGRADED** (it works, worse), **HARD-BLOCKED** (no route today).

| Row | Was | Under denial | Route, or why not |
|---|---|---|---|
| **#9** queue worker | BLOCKER (G0-D) | **RESOLVED — degraded** | `POST /api/v1/cron/queue` **MEASURED** (`routes/api.php:142`, built `b61cb05`). Needs an external caller; see §5.3a |
| **#10** scheduler | BLOCKER (G0-D) | **RESOLVED — degraded** | `POST /api/v1/cron/schedule` **MEASURED** (`routes/api.php:141`) |
| **#21** backups | BLOCKER (G0-D) | **RESOLVED — degraded. Taking them, not restoring them** | Not a separate route: `ethr:backup` is a `Schedule::command` entry **MEASURED** (`routes/console.php:107`), so the scheduler endpoint drives it. **`ethr:restore` has no such route** — it is not scheduled, and `CronRunController` runs only `schedule:run` and `queue:work` **MEASURED** (`:48,66`). The one route is a Plesk deployment action, which is §5.3b's single point of failure and fires on every deploy. Degraded further by Bronze's disk quota — §5.4 — and with no off-host disk a host loss has no restore path at all. [`BACKUP-RESTORE.md`](BACKUP-RESTORE.md) → *On the Ethio Telecom target* |
| **#11** `artisan` at all (`key:generate`, `migrate`, `db:seed`, `ethr:create-admin`) | WORKAROUND | **UNCHANGED — and now the single point of failure** | Plesk Git *additional deployment actions*. **MEASURED as present** (owner, 2026-09-18) and **never executed**. Under denial there is no second route |
| **#12** `storage:link`, writable dirs | ASK | **Inherits #11** | Same mechanism, same untested assumption |
| **#22 / G0-F** `CREATE TRIGGER` | ASK | **HARD-BLOCKED — and it is an owner decision, not an engineering one** | `migrate` aborts at `2026_07_22_000001` by design **MEASURED**. [`AUDIT_LOG_INTEGRITY_DECISION.md`](../AUDIT_LOG_INTEGRITY_DECISION.md) pre-registered this exact case: on refusal the choice returns to the owner as accepted risk, and the mechanism is then an `AUDIT_LOG_REQUIRE_DB_IMMUTABILITY` flag **that is deliberately not built**. See §5.7 |
| **#8 / G0-A** path routing | BLOCKER | **CONFIRMED — and routed around** | Denial converts "strong evidence of FAIL" into the working assumption. Consequence: §3A's **B (Node on Plesk)** is foreclosed, and **A (static export)** is the only frontend path. Costed at ≈8 days, 2.0 of them gated on G0-B.1/B.2 — and all 8 void if B.1 fails |
| **#7 / G0-G** Node runtime | ASK | **MOOT** | The panel offers a startable Node app **MEASURED** (2026-09-22) — but with #8 denied there is nothing to split `/api/*` from `/`, so the runtime being present buys nothing. G0-G stays `PARTIAL`; it simply stops mattering |
| **#18** subdomain quota | ASK | **UNKNOWN — and now potentially product-limiting** | Nobody has established whether a wildcard counts as one. See question Q3 |
| **#17 / G0-C** wildcard TLS | BLOCKER | **UNCHANGED — it was never one of the four asks** | A DNS question, not a hosting one. One workaround exists and is not a host concession — §5.3d |
| **#2 #4** extensions · **G0-E** limits | ASK | **RESOLVED** | The probe supports **web execution** deliberately **MEASURED** (`ethr-hosting-check.php:35-38`, `:77-83`) — §5.3c |
| **G0-H** SMTP · **G0-I** database · **G0-J** performance | NOT VERIFIED | **RESOLVED** | Same probe, same route |
| **G0-B.1–B.5** `.htaccess` | NOT VERIFIED | **UNCHANGED — and now the critical path** | The canary needs no shell, no cron and no ticket, and static export is now the only frontend option. §5.3c |
| **#5** Redis · **#13** object storage · **#14** Reverb | WORKAROUND | **UNCHANGED** | `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION=database`, local disk, `BROADCAST_CONNECTION=log`. All already prescribed |
| **#15** SSH / Git deploy | WORKAROUND | **UNCHANGED** | [`SHARED-HOSTING-CONTRACT.md`](SHARED-HOSTING-CONTRACT.md) already makes SSH OPTIONAL; ask 4's denial costs nothing it had not already priced |
| **#19** mail | ASK | **Split** | Outbound 587/465 → probe. Mailbox send cap → a panel read |
| **#20** device callbacks | ASK | **Inherits #8 and #16** | Ordinary inbound HTTPS to a named host once those hold |
| **#1** PHP · **#3** · **#6** · **#16** | OK / RESOLVED | **UNCHANGED** | |

#### The headline

**The blocker that fired the pre-registered No-Go is the one that now has a route.**
`SHARED_HOSTING_MIGRATION_PLAN.md` §4's rule fired on **B3 — cron**, and the reason it gave
was *"Leave accrual, invoicing, anomaly scanning, cleanup and every queued email stop."*
Those do not stop any more. An external caller drives `schedule:run` and `queue:work` over
HTTP, and `ethr:backup` rides the scheduler with them.

Two blockers remain, and **neither is G0-D**:

| Remaining | Nature |
|---|---|
| **G0-C / #17** wildcard TLS | Subdomain tenancy has no certificate. A DNS problem with one unverified workaround |
| **G0-F / #22** `CREATE TRIGGER` | `migrate` will not complete. An owner decision, then ~a day of work that is deliberately unbuilt |

And one that is not a blocker but is the largest single cost: **static export, ≈8 days,
6–11**. 2.0 of those days are line items gated on `.htaccess`; but if the canary shows
`.htaccess` is ignored it is **the whole estimate that is void, not 2 days of it** — with no
rewrite there is no mechanism to serve entity routes at all, so there is nothing left to
re-cost. *(Corrected 2026-09-23: this said "3 days are void". Both halves were wrong — the
gated rows total 2.0, and the failure case voids all eight.)*

**This does not reverse the No-Go by itself.** The rule fired on a gate, and the gate has
not moved. What it says is that the rule's stated *reason* no longer holds, which is a
reason to re-take the decision rather than to consider it re-taken.

### 5.2 What is actually known about the plan defaults, and what is not

**Known — MEASURED on this account**, all by the owner reading the panel:

| Fact | Date |
|---|---|
| PHP **8.3.33** | 2026-09-17 |
| Proxy mode *"nginx proxies requests to Apache"* is **ON** — so Apache is in the path and `.htaccess` is processed at all | 2026-09-17 |
| *"Serve static files directly by nginx"* **exists**; its value is **unread** | 2026-09-17 |
| **No** *Additional directives for HTTP/HTTPS* and **no** *Additional nginx directives* fields on an otherwise complete page | 2026-09-17, read twice |
| **No** Scheduled Tasks / Task Scheduler / Cron Jobs section on an otherwise complete dashboard | 2026-09-18 |
| SSH **Forbidden**; no Terminal under Dev Tools | 2026-09-17 |
| Git, PHP Composer, Imunify present; *additional deployment actions* field present | 2026-09-17 / 18 |
| Node.js **22.23.2**, startable application (startup file, mode, URL) | 2026-09-22 |
| Per-hostname Let's Encrypt **works**; wildcard blocked (needs DNS-01) | 2026-08-29 / 09-17 |
| Home directory sits **above** `httpdocs` | 2026-08-29 |

**Known — PUBLISHED**, transcribed from Ethio Telecom's plan pages into the support request:

| Plan | Storage | Bandwidth | Databases | Subdomains | Websites |
|---|---|---|---|---|---|
| Bronze | 5 GB | 50 GB | 1 | 5 | 1 |
| Silver | 20 GB | 250 GB | 3 | 10 | 3 |
| Gold | 50 GB | Unlimited | 5 | 15 | 5 |
| Platinum | 100 GB | Unlimited | 10 | Unlimited | 10 |

**And one thing the published pages are MEASURED not to say.** The support request states it
outright: *"cron, command-line PHP, SSH, Node.js and directives are not listed for any
plan, which is why I am asking."* So *"anything not in the published feature set is
unavailable"* — the owner's framing — resolves, for those five capabilities, to
**unavailable on every tier**, not just on Bronze. That is why a plan upgrade is not a
workaround for any of them, and why §5.4 turns out to be a short section.

**Not known, and deliberately not guessed.** These are questions, in §5.8.

### 5.3 Workarounds that need nothing from the host

#### 5.3a The scheduler and the queue — an external caller

The endpoints exist and are **MEASURED**: `POST /api/v1/cron/schedule` and
`POST /api/v1/cron/queue`, guarded by `throttle:cron` then `VerifyCronToken`, 404ing
entirely when `CRON_TOKEN` is unset. The queue route runs
`queue:work --stop-when-empty --max-time=50` over the authoritative queue list, under a
cache lock that returns **409** rather than starting a second worker.

Four callers. **Cadence and pricing claims below are ASSUMED — this repository holds no
measurement of any of them, and none was invented.**

| Caller | Cadence | Token lives | For | Against |
|---|---|---|---|---|
| **GitHub Actions `schedule:`** | 5 min documented minimum; delivery best-effort, commonly late, runs droppable under load *(ASSUMED, from GitHub's published behaviour)* | an Actions secret, in the account that already holds the repository | No new vendor, no new bill, every run logged and attributable | Private-repo minutes are billed; runner egress is GitHub's shared ranges; and "late or skipped" quietly redefines every `dailyAt` in `routes/console.php` |
| **The VPS already in hand** (`91.99.81.71`) | real `crontab`, 1 minute, reliable | a host the owner controls | Highest fidelity to what the scheduler was designed for | **It keeps the VPS.** `SHARED_HOSTING_MIGRATION_PLAN.md` §5's own argument then applies verbatim — *"once a VPS is in the picture, Option A is strictly better"* — and this is its **Option C** (shared + small VPS) under another name |
| **A third-party cron service** | 1-minute offered by some; free-tier limits **unverified** | a third party's dashboard | Nothing to run or patch | A third party can drain your queues on demand, and its outage stops all background work **silently** |
| **A laptop** | whenever it is on | locally | Named in `config/cron.php` for completeness | Not a production answer |

**What it costs to put the token outside the host — stated in full, because it is the
price of this whole section.**

- It is a **bearer credential**. Whoever holds it can run the scheduler and drain queues at
  will.
- It is **the only control**. `VerifyCronToken` (`hash_equals` over SHA-256 digests,
  constant-time) plus `Limit::perMinute(10)->by(ip)` are what guard the routes **MEASURED**.
  There is **no IP allowlist** — and under G0-A denial you cannot add one at the web server
  either, because that is exactly the directive field the panel does not offer. The token is
  not one of several controls; it is the control.
- **A leak discloses scheduler output, not just capability.** Both endpoints return
  `exit_code` and up to 2000 characters of Artisan output **MEASURED**
  (`CronRunController::tail()`). That is a consideration when choosing who holds it.
- **Blast radius is availability and timing, not tenant data**: forced drains and scheduler
  runs, plus that output. No tenant row is reachable through these routes.
- **Rotation is manual on both sides** — `CRON_TOKEN` in the Plesk environment and the
  caller's secret — with a window in between where background work is dead and nothing says
  so.
- **≥ 32 characters or the routes disable themselves** **MEASURED** (`config/cron.php:31`,
  `VerifyCronToken:49`): a short token logs an error and 404s, rather than being quietly
  accepted.

**Recommendation, and it is not the obvious one.** If the VPS is kept for cron, this stops
being a shared-hosting deployment and becomes Option C — at which point the migration's own
prior analysis says Option A is strictly better, and Plan B should be abandoned rather than
built. **So the VPS caller is the best engineering answer and the worst strategic one.** If
Plan B is to mean anything, the caller must be something the migration is not trying to
retire: GitHub Actions, at a cadence the schedule can tolerate, is the honest choice —
**provided Q6 below is answered first.**

#### 5.3b The install commands

Plesk Git *additional deployment actions* execute shell commands as the subscription user on
deploy. Under denial this carries `key:generate`, `migrate`, `db:seed`, `ethr:create-admin`
and `storage:link`, and it is **the only route**. It is **MEASURED as present** and has
**never been executed** — nothing has been entered, saved or run.

Two consequences worth naming rather than discovering:

1. **If deployment actions do not work, Plan B ends there.** Not degraded — ended. Nothing
   else on this account can run `artisan` even once.
2. **If they do work, PHP CLI exists on this account**, which is one of the things ask 2 was
   asking. Proving it costs one deploy with `php -v` in the field.

#### 5.3c Measurement — both instruments already run without a concession

**The probe can be driven over HTTP, and this was always designed in.** Its own header
**MEASURED** (`ethr-hosting-check.php:35-38`): serving it from `httpdocs` is *"a last
resort"*, a web request returns **403 and nothing else** unless `ETHR_PROBE_WEB_TOKEN` is
set in the uploaded copy, and credentials may be passed as query parameters. That answers
G0-E's extensions and limits, G0-F, G0-H, G0-I and G0-J with no shell and no cron.

Three caveats, none fatal:

- **Database credentials in a query string land in the access log** — the file says so
  itself. Put them in the uploaded copy instead.
- **Web-SAPI values differ from CLI values** for `max_execution_time` and `memory_limit`.
  Under Plan B that is an advantage, not a distortion: there is no CLI path in normal
  operation, and the cron endpoints run under the web SAPI too, so the web numbers are the
  ones that govern.
- **Containment is `NOT VERIFIED` and exposure is graded LIVE.** A copy is already under
  `httpdocs` and its URL last measured **HTTP 200**, which is not what a token-less copy
  should return. Resolve that before adding a token — it is question Q1.

**The canary answers G0-B.1–B.5** with five files and six fetches, discloses nothing, and
needs no concession. Under denial it stops being one item on a list and becomes **the
measurement Plan B rests on**, because static export is now the only frontend and three of
its eight days are void if `.htaccess` is ignored.

#### 5.3d Wildcard TLS — one workaround, and it is not a hosting ask

The zone is on `ns1`/`ns2.telecom.net.et`, so Plesk cannot serve the DNS-01 challenge.
The standard escape is **CNAME delegation**: point `_acme-challenge.ethr.et` at a zone you
do control, and answer the challenge there. It is a **one-time DNS record change**, not a
hosting concession, and it goes to whoever administers the zone — a different party from
hosting support.

**Marked ASSUMED.** This repository holds no evidence about who controls that zone or
whether they will add records. It is named because it is the only route that does not
require the hosting plan to change, not because it is known to work.

### 5.4 Gold versus Bronze — what actually differs for this deploy

The account is reported **Bronze** — **ASSUMED, not measured.** The provenance is owner
testimony supplied on 2026-08-29 (`../B1-B5_GATE_REPORT.md`, *Target account*), repeated in
the support request and in `shared-hosting/DEPLOYMENT.md`'s header. **No panel read of the
tier exists.** It is **Q9**, and this section reasons about Bronze because that is the
reported tier, not because it has been confirmed.
This document's title says *Gold*, and §1 reasons about *"Gold includes 5 databases"* and
*"Gold publishes 15"* — that was the brief's premise, and it is not this account.
**Noted rather than silently corrected**, because §1's gap table is otherwise sound: not one
of its BLOCKER rows turns on a tier figure.

| | Bronze | Gold | Matters here? |
|---|---|---|---|
| Storage | 5 GB | 50 GB | **Yes — see below** |
| Bandwidth | 50 GB | Unlimited | Not initially |
| Databases | 1 | 5 | No. ETHR needs one. Bronze leaves no room for a staging copy on the same account |
| Subdomains | 5 | 15 | **Only if a wildcard counts individually** — Q3 |
| Websites | 1 | 5 | No |

**Does Bronze change any decision? Three answers, and only one is yes.**

1. **Not the architecture. No.** Every blocker is a service-plan permission or a property of
   the host, and the published pages list cron, CLI PHP, SSH, Node and directives for **no
   tier at all**. Under the denial assumption the tier is not the variable.
2. **Yes, for backups — and this one is concrete.** `ethr:backup --keep=7` retains seven
   archives **MEASURED**, and `BACKUP-RESTORE.md` records that **no off-host disk is
   configured**, so the backup lives on the machine it protects. Seven archives against a
   **5 GB** quota is a real risk, and exhausting the quota does not merely stop backups: it
   stops every write under `storage/` — logs, uploads and the archives themselves. On
   Bronze, lower `--keep` or move backups off-host **before** the first scheduled run, not
   after. *(Whether the MySQL database shares that 5 GB is unknown — Q4.)*
3. **Possibly fatal for the product, and it is the same question either way.** If a wildcard
   counts as one subdomain, the limit is irrelevant on both tiers. If each tenant subdomain
   counts, the **PUBLISHED** caps put the product at **5 customers** on Bronze and **15** on
   Gold — and neither is a
   business. **The tier is not what decides this; the counting rule is.** Q3.

So: Bronze changes **one operational setting** and sharpens **one open question**. It does
not change which architecture to build, and upgrading to Gold would not unblock anything
that is blocked.

### 5.5 The deploy path, end to end, under full denial

Every step names the mechanism and whether anyone has ever done it here.

| # | Step | Mechanism | Status |
|---|---|---|---|
| 1 | **Run the canary** | Upload 5 files, 6 fetches | Never run. **Do this first** — 2.0 of static export's 8 days are gated on it, and a B.1 failure voids **all eight**, not two |
| 2 | **Run the probe over HTTP** | Token in the uploaded copy, credentials in the file not the URL, delete after | Never run; a copy is already exposed (Q1) |
| 3 | **Decide the `TRIGGER` question** | Owner, per `AUDIT_LOG_INTEGRITY_DECISION.md` | **Blocking. Nothing below proceeds past step 7 without it** |
| 4 | **Build the static export** | §3A option A, ≈8 days | Not started; **2.0** days conditional on step 1, and the whole estimate void if step 1 returns B.1 FAIL |
| 5 | **Deliver code** | Plesk Git, deployment path `/ethr/` **set before the first deploy** | Never configured |
| 6 | **Install dependencies** | Plesk Composer | Never run |
| 7 | **One-off install** | Deployment actions: `key:generate`, `migrate`, `db:seed`, `ethr:create-admin`, `storage:link` | Never run. **Single point of failure** (§5.3b) |
| 8 | **Assemble the document root** | `index.php` + `.htaccess` + exported `out/` | Written, never applied |
| 9 | **Configure the environment** | Plesk env vars from `api/.env.shared-hosting.example` — **not** `.env.production.example`, which selects redis/minio/reverb | Template exists |
| 10 | **Set `CRON_TOKEN`** | ≥32 chars, Plesk env | — |
| 11 | **Point an external caller at both cron endpoints** | §5.3a | Endpoints built; no caller configured |
| 12 | **Lower backup retention** | `--keep`, per §5.4 | Not done |
| 13 | **Certificates** | Per-hostname Let's Encrypt for `ethr.et`, `www`, `app` | Proven for named hosts |
| 14 | **Verify** | `deploy-checklist.md`, `/api/v1/health`, `ethr:queue:check` | — |

**What this path does *not* include, and cannot:** tenant subdomains. Steps 1–14 deliver a
working single-hostname deployment. `{tenant}.ethr.et` needs step 13 to issue a wildcard,
and it cannot.

### 5.6 What is lost versus the ideal deploy

| Lost | Why | Recoverable? |
|---|---|---|
| **Scheduler fidelity** | Cadence is the external caller's, not cron's. On GitHub Actions, `everyMinute` becomes ~5 minutes at best and late or skipped at worst | Only by changing caller |
| **"Quiet vs dead" queue observability** | The heartbeat is `Schedule::call(...)->everyMinute()` **MEASURED**, and `ethr:queue:check` reads it. A caller that fires every 5–15 minutes makes a healthy system look intermittently dead | Retune the thresholds — not yet scoped |
| **Background work surviving a third party** | The queue stops when the caller stops, silently | Monitor the heartbeat externally too |
| **The `/admin` host boundary** | Static export makes `middleware.ts` inert, and its documented nginx backstop is exactly what G0-A denies **MEASURED** | `.htaccess`, if G0-B.1 passes. Data stays protected by `admin.manage` + `RoleGate` — a defence-in-depth regression, not a breach |
| **CSP, HSTS, X-Frame-Options, Permissions-Policy** | `next.config.ts` `headers()` is dropped by `output: "export"` with a warning and no failure **MEASURED** | `.htaccess`, if G0-B.2 passes |
| **SSR on marketing pages** | Static export | No |
| **Real-time (Reverb)** | No long-running daemon; contract already says NEVER REQUIRED | No — by design |
| **A second database for staging** | Bronze publishes 1 | Separate account, or a tier change |
| **Off-host backups** | No off-host disk configured, and 5 GB shared with everything else | Configure a remote disk — needs outbound access (Q5) |

### 5.7 What remains genuinely impossible

Four things. The first two are the ones that decide whether Plan B ships at all.

1. **Subdomain tenancy.** `*.ethr.et` has no certificate and no route to one from this
   panel. `SESSION_SECURE_COOKIE=true` means tenants on an untrusted connection cannot log
   in — this is `SHARED_HOSTING_MIGRATION_PLAN.md` §4's **B2 = No-Go**, and it is untouched
   by everything above. The CNAME-delegation workaround (§5.3d) is the only route and is
   **ASSUMED**, not known. **Without it, Plan B delivers a single-tenant-hostname product.**
2. **Database-enforced audit-log immutability.** Without `TRIGGER` the migration aborts, by
   a decision already taken and recorded. Proceeding requires the owner to accept the
   downgrade explicitly *and* then build the `AUDIT_LOG_REQUIRE_DB_IMMUTABILITY` flag, which
   `AUDIT_LOG_INTEGRITY_DECISION.md` says must **not** be built pre-emptively. Until that
   decision is taken this is not an engineering task.
3. **Anything needing a resident process.** WebSockets, a supervised worker, a daemon of any
   kind. Already excluded by the contract; listed so the list is complete.
4. **Reliable sub-5-minute scheduling on a free external caller.** Some paid services offer
   one minute; GitHub Actions does not, and its schedule is best-effort.

**Not on this list:** running the scheduler, draining the queue, taking backups, installing
the application, and measuring the host. All five were blocked on an ask a day ago and all
five now have a route that needs nothing from Ethio Telecom.

### 5.8 Open questions — for the owner, not guessable from here

Deliberately not answered. Each would have had to be invented.

| # | Question | Why it matters |
|---|---|---|
| **Q1** | The probe copy already under `httpdocs` returned **HTTP 200**. What are its response headers and first body line? | A token-less copy should return **403**. 200 means either a token was set or the file is not executing as PHP. Until this is answered, exposure stays graded LIVE and step 2 of §5.5 must not proceed |
| **Q2** | Does Ethio Telecom's **published** spec sheet mention Scheduled Tasks / cron, SSH, Node.js, command-line PHP or custom directives for **any** tier — in any document, not just the plan comparison page? | The support request says it does not. If that holds, §5.4's conclusion stands and no upgrade helps. If some tier does list them, the denial assumption is wrong for that tier and this whole section reopens |
| **Q3** | **Does a wildcard subdomain count as one against the subdomain limit, or does each tenant count individually?** | The single most consequential unknown in this document. If individual, the **PUBLISHED** limits cap the product at 5 customers (Bronze) or 15 (Gold) — on a tier that is itself **ASSUMED** (Q9) — and no tier is viable |
| **Q4** | Is the MySQL database counted against the plan's **5 GB** disk quota, or separately? | Decides whether the database competes with seven backup archives and the log directory for the same 5 GB |
| **Q5** | What outbound network access does the account have — arbitrary HTTPS, SMTP 587/465, anything else? | Decides off-host backups, external S3 and outbound mail. The probe answers the SMTP ports; general outbound egress it does not |
| **Q6** | **Which external caller do you want, and where should `CRON_TOKEN` live?** | GitHub Actions (no new vendor, poor cadence), the existing VPS (perfect cadence, but it makes this Option C and re-opens Option A), or a third-party service (good cadence, a stranger holds the key). §5.3a lays out the trade; the choice is yours |
| **Q7** | Who administers the `ethr.et` zone at `ns1`/`ns2.telecom.net.et`, and will they add a `_acme-challenge` CNAME? | The only route to wildcard TLS, and the only route to subdomain tenancy |
| **Q8** | `TRIGGER` denied — do you accept the audit-log downgrade, or does ETHR not deploy on this account? | Pre-registered as yours in `AUDIT_LOG_INTEGRITY_DECISION.md`. Steps 3 and 7 of §5.5 both stop here |
| **Q9** | Is the account definitely **Bronze** — read from the panel, not repeated from what was supplied in August? | The tier is **ASSUMED** throughout this document: owner-supplied 2026-08-29, never panel-read. The title and §1 reason about *Gold* (the brief's premise); §5.4 reasons about *Bronze* (the reported tier). Nothing structural turns on it — §5.4 checks that explicitly — but §5.4's figures do, and so does the `--keep` backup-retention setting it tells you to lower **before** the first scheduled run |

### 5.9 What this section does not claim

It does not claim the denial happened — no reply is not a refusal, and the ticket was never
sent. It does not move a gate. It does not re-take
`SHARED_HOSTING_MIGRATION_PLAN.md` §4's **No-Go → Option A**, which fired on G0-D and stands
until someone re-takes it deliberately — though §5.1 gives the first substantive reason to.
And it does not claim any of these routes works: **every one of them is a mechanism that
exists in code or in the panel, and not one has ever been executed on this account.**

---

