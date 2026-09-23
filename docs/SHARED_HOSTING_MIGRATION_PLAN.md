# ETHR — Shared-Hosting Migration Plan (Phase 3)

**Date:** 2026-08-29
**Status (2026-08-29, as written):** DECISION PENDING. No implementation has started.

> **Superseded 2026-09-18 — both halves of that line are now false, and it is the first
> thing a reader sees.**
>
> - **The decision was taken.** Option B (everything on Ethio Telecom shared hosting) was
>   chosen on 2026-08-29 under owner delegation. See `MIGRATION_STATE.md` → *Decisions
>   taken on delegation*, D1–D10, including the withdrawal of Options A, C and D.
> - **Implementation started and substantial parts are merged.** Phase A shipped in
>   `12f53de` (five hardcoded infrastructure references made configuration-driven); the
>   deployment package exists; `DEPLOYMENT.md` step 4a assembles the document root; a
>   `DocumentRootInventoryTest` enforces which files may enter it.
>
> What has **not** started is *execution on the host* — upload, migrate, cutover — which
> is blocked on Gate 0 and on blockers **B-1 to B-6** (`MIGRATION_STATE.md`). The
> conditional Go in §4 below still stands, and two new gates have joined its five.
>
> **Corrected 2026-09-23: "its five gates are still unresolved" is no longer true.** Two
> are answered, and `deployment/GATE-0-RESULT.md` is where the measurements live:
>
> - **B3 (cron) is answered — G0-D = FAIL.** No Scheduled Tasks section exists on the
>   subscription dashboard. What follows from that is **not** what the §4 table
>   originally said. **Resolved 2026-09-23:** the reason that table gave — *"leave accrual,
>   invoicing, anomaly scanning, cleanup and every queued email stop"* — is false, MEASURED.
>   `POST /api/v1/cron/schedule` and `POST /api/v1/cron/queue` drive the scheduler and the
>   queue over HTTP. The row is corrected; **the No-Go decision it recorded is unchanged**
>   and is the owner's to re-take. See the B3 row.
> - **B4 (PHP ≥ 8.2) is answered — PHP 8.3.33**, panel-read 2026-09-17. It passes.
>
> B1, B2 and B5 remain unresolved, as do the two newer gates. Read §4 with
> `deployment/GATE-0-RESULT.md` beside it, and treat that file as authoritative for any
> gate status — this document reasons about consequences, it does not record measurements.
**Inputs:** `docs/SHARED_HOSTING_AUDIT.md`,
`docs/ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`

---

## 1. The decision this document exists to make

ETHR currently runs as 13 Docker services on a VPS. The request is to move it to
Ethio Telecom **shared** hosting. The audit found that the application itself is far
more portable than its deployment suggests — but portability is not the constraint.
The constraint is five hosting capabilities nobody has confirmed yet.

The options below are ranked by how much of ETHR survives, not by how little work
they are.

---

## 2. Options

### OPTION A — Stay on the VPS (do nothing)

**Architecture:** unchanged.

| | |
| --- | --- |
| Functionality preserved | 100% |
| Work | none |
| Risk | none |
| Cost | current VPS |

**When this wins:** if capability B1 (wildcard subdomain) or B3 (cron) comes back
negative. In that case shared hosting cannot host a multi-tenant SaaS with scheduled
payroll and leave processing, and no amount of adaptation changes it.

This option is listed first deliberately. It is the correct answer if the blocking
questions resolve badly, and "we already have a working deployment" is a real asset.

---

### OPTION B — Shared hosting, minimal change

Everything on the Ethio Telecom Linux + MySQL plan. Requires **B1–B4** to all pass.

```
                          Browser
                             │  https://{ethr.et | admin.ethr.et | *.ethr.et}
                             ▼
              ┌──────────────────────────────┐
              │  Ethio Telecom shared host   │
              │  Plesk · Apache/nginx · PHP  │
              │  docroot → api/public        │
              │                              │
              │  .htaccess:                  │
              │    /api, /sanctum  → index.php (Laravel)
              │    everything else → SPA
              └───────┬──────────────┬───────┘
                      │              │
                 MySQL (1 DB)   storage/app  (or external S3)
                      ▲
                      │  cron (every minute)
              ┌───────┴───────────────────────┐
              │ php artisan schedule:run      │  ← 14 scheduled entries
              │ php artisan queue:work        │  ← 15 job classes
              │     --stop-when-empty         │
              │     --max-time=55             │
              └───────────────────────────────┘

  Removed: Redis · Horizon · Reverb · MinIO · read replica · Docker · nginx conf
  Degraded: live WebSocket toasts → existing 30 s poll
            Horizon dashboard → `failed_jobs` table + logs
```

Two sub-variants, decided by capability **B5**:

#### B1 — Node.js runtime available

Deploy the Next.js `standalone` build under the Plesk Node.js extension. `.htaccess`
proxies non-`/api` traffic to the Node process.

- **Frontend code change: none.** `middleware.ts` and the `headers()` auth layout keep
  working exactly as today.
- Risk: Plesk Node.js apps are Passenger-managed and can be restarted aggressively;
  Next 16 needs Node 20.9+.

#### B2 — No Node.js runtime → static export

Switch to `output: "export"` and serve the built HTML/JS as static files. Justified by
the audit: 107 of 126 page files are already `"use client"`, there are **zero** API
routes and **zero** server actions.

Required changes, all scoped and named:

| Change | File | Reason |
| --- | --- | --- |
| `output: "export"`, drop `rewrites()`, drop `headers()` (CSP moves to `.htaccess`) | `src/next.config.ts` | Static export supports neither. |
| Delete `middleware.ts`; reimplement the host rules in `.htaccess` | `src/src/middleware.ts` | Not executed under static export. Its own comment already names nginx as the authoritative control. |
| Read host client-side instead of via `headers()` | `src/src/app/(auth)/layout.tsx` | Accepts a first-paint flash on the tenant login page in exchange for removing the last SSR dependency. |
| `generateStaticParams` returning `[]` + client-side param read | `employees/[id]`, `payroll/[id]`, `devices/[id]`, `admin/tenants/[id]` | Static export requires known params or a shell page. |

**Cost of B2:** the SPA becomes fully client-rendered. Marketing pages lose SSR (SEO
impact on `/`, `/features`, `/pricing`, `/faq`, `/contact` — worth stating explicitly to
the business). No authenticated feature is affected: those pages were already client
components fetching over `/api/v1`.

#### Backend changes required in Option B (both variants)

Environment only, except where a file is named:

```diff
- CACHE_STORE=redis          + CACHE_STORE=database
- QUEUE_CONNECTION=redis     + QUEUE_CONNECTION=database
- SESSION_DRIVER=redis       + SESSION_DRIVER=database
- BROADCAST_CONNECTION=reverb + BROADCAST_CONNECTION=log
- FILESYSTEM_DISK=minio      + FILESYSTEM_DISK=local   (or an external S3 endpoint)
- DB_READ_HOST=...           + (unset)
- REDIS_* / REVERB_* / MINIO_*  + (unset or repointed)
```

Code changes — **four sites, three files**, each justified by the audit:

| File | Change | Why |
| --- | --- | --- |
| `api/app/Services/FileStorageService.php:44` | `$this->disk = config('filesystems.default')` | Currently hardcodes `'minio'`, so `FILESYSTEM_DISK` is ignored on every upload and download path. |
| `api/app/Http/Controllers/Api/V1/HealthController.php:29,30,43` | Use the configured cache store and disk | Otherwise `/api/v1/health` reports permanently unhealthy. |
| `api/app/Console/Commands/HealthCheckCommand.php:20` | Same | Otherwise `health:check` always fails. |

Plus one migration guard, conditional on capability **H1**:

| File | Change | Why |
| --- | --- | --- |
| `database/migrations/2026_07_22_000001_restrict_audit_log_to_insert_only.php` | Wrap `CREATE TRIGGER` in try/catch that logs a loud warning, matching the pattern the same file **already uses** for the PostgreSQL branch | Without `SUPER` on a binlog-enabled managed MySQL this raises error 1419 and aborts the entire `migrate` run. The append-only guarantee then rests on application-level enforcement, and **that trade-off must be recorded** — it is a security control, and downgrading it is a decision for the owner, not for the deployment. |

**Nothing else changes.** No business logic, no schema, no API contract, no domain
model, no tenancy model, no payroll calculation, no Ethiopian calendar, no localisation.

| | |
| --- | --- |
| Functionality preserved | ~95% |
| Lost | Horizon dashboard, live WebSocket push (falls back to existing poll), sub-minute queue latency, read replica; under B2 also marketing-page SSR |
| At risk | audit-log DB triggers (H1); payroll/import runtime under `max_execution_time` (H2) |
| Work | Small backend, moderate deployment, plus an **architectural frontend deployment change** if B2 — re-costed 2026-09-18 (`7aed9d2`); the earlier "scoped frontend refactor" is withdrawn |
| Risk | **High until B1–B5 are answered** |
| Cost | one shared plan (tier 3–4 for storage headroom) |

---

### OPTION C — Hybrid: shared hosting + a small VPS for what shared hosting cannot do

Shared hosting serves the web tier; one Ethio Telecom VPS Gold (4 GB, **ETB 10,379/yr —
ASSUMED**) carries the components that need a long-running process.

> **That price is ASSUMED, not measured or published.** Its only source in this repository
> is a third-party directory (whtop) carrying **data dated 2020**, recorded as
> *"unconfirmed"* in [`TCO_COMPARISON.md`](TCO_COMPARISON.md) at every one of its four
> appearances there, and never checked against the Ethio Telecom portal. It is quoted
> unqualified twice in this section; both are now marked. **Confirm it on the portal before
> any decision rests on it** — `TCO_COMPARISON.md` opens by saying the same thing about
> every third-party figure it carries.

```
        Browser ──────────────► Shared host  (Plesk, PHP, docroot → api/public)
           │                          │  full Laravel API + SPA
           │                          ▼
           │                    MySQL on the shared plan
           │                          ▲
           │                          │  same DB, same code, cron-free
           └──── wss://realtime ──► VPS Gold
                                      ├── Reverb        (WebSockets)
                                      ├── Horizon       (Redis queues, real workers)
                                      ├── Redis
                                      ├── scheduler     (schedule:work)
                                      └── MinIO         (object storage)
```

**This option is listed because the brief asks for it, and it must be assessed
honestly rather than adopted by default.**

The hybrid only helps if the shared host can reach the VPS's MySQL/Redis over the
public internet, and vice versa. That means:

- Exposing MySQL or Redis across the internet between two providers (or a VPN between
  them, which shared hosting cannot terminate).
- Every DB query from the web tier crossing a network hop it does not cross today.
- Two `.env` files, two deploy paths, two backup regimes for one application.

And critically: **once a VPS is in the picture, Option A is strictly better.** The VPS
in this hybrid is doing the hard part — Redis, workers, WebSockets, storage — while the
shared host contributes only PHP execution that the same VPS could do for free. The
hybrid costs more than the VPS alone, is harder to operate, and is slower.

| | |
| --- | --- |
| Functionality preserved | ~100% |
| Work | Highest of the three |
| Risk | Highest — cross-provider network dependency on the hot path |
| Cost | shared plan **plus** ETB 10,379/yr VPS — the VPS figure **ASSUMED** (see above) |

**Recommendation: reject Option C** unless the requirement is specifically "the domain
and web tier must be hosted at Ethio Telecom" for a reason other than cost. If that
requirement exists, say so and this becomes the right answer.

---

## 3. Comparison

| | **A — VPS** | **B — Shared** | **C — Hybrid** |
| --- | --- | --- | --- |
| Product features preserved | 100% | 100% | 100% |
| Realtime push | Native | Degrades to 30 s poll — **MEASURED** (`refetchInterval: 30000` in `src/src/features/notifications/api.ts:30`, and the same on the devices and admin dashboards) | Native |
| Queue latency | Sub-second | ~~1 min (cron)~~ **ASSUMED, and overtaken — see below** | Sub-second |
| Queue observability | Horizon UI | `failed_jobs` + logs | Horizon UI |
| Audit-log DB triggers | Yes | **At risk (H1)** | Yes |
| Wildcard tenant subdomains | Yes | **At risk (B1/B2)** | At risk |
| Payroll execution headroom | 1800 s | **At risk (H2)** | 1800 s |
| Marketing-page SSR | Yes | B1 yes / **B2 no** | Yes |
| Application code changed | 0 files | **3 files, 4 sites** (+4 frontend files if B2) | 3 files |
| Business logic changed | None | **None** | None |
| Schema changed | None | **None** | None |
| API contract changed | None | **None** | None |
| Operational complexity | Medium | Low | **High** |
| Cost | VPS | Lowest | Highest |

> **The B column's queue latency was never measured, and the mechanism it assumed is
> gone.** *"1 min (cron)"* took Plesk's minimum cron interval on trust — no such reading
> exists in this repository. It is moot either way: **G0-D is FAIL**, there is no Scheduled
> Tasks section, and the queue is driven instead by an **external caller** hitting
> `POST /api/v1/cron/queue`. That caller is unchosen (question **Q6**), and every candidate
> cadence in
> [`deployment/SHARED_HOSTING_PLAN.md`](deployment/SHARED_HOSTING_PLAN.md) §5.3a is marked
> **ASSUMED** there — GitHub Actions, the closest to free, documents a **5-minute** minimum
> and delivers best-effort. So B's real queue latency is **unknown and bounded below by the
> caller**, not by cron. No figure is substituted here, because none has been measured.

---

## 4. Recommendation

**Conditional Go for Option B, gated on the five blocking capabilities.**

The audit's central finding is that ETHR is far better positioned for this migration
than its 13-service deployment implies. Redis, Horizon, Reverb and MinIO are all
*configuration*, not *architecture*: there is not a single `Redis::` call in
`app/`, the broadcast leg is already guarded by a config check in twelve notifications,
notifications already poll, and tenancy is a single database with a global scope.
The entire hardcoded-infrastructure surface is four lines in three files.

That is the argument for attempting Option B rather than declaring shared hosting
unsuitable.

> **Naming warning — "Option A" means something else in the sibling document.** In this
> file **Option A** is *stay on the VPS* and **Option B** is *everything on Ethio Telecom
> shared hosting*. In
> [`deployment/SHARED_HOSTING_PLAN.md`](deployment/SHARED_HOSTING_PLAN.md) §3A, **A** is
> *static export*, **B** is *Node on Plesk* and **C** is *split hosting* — a different
> question entirely (which frontend, given shared hosting) with the same letters. That
> document carries the same warning at its §5. Neither set is renamed, because both are
> cited by date elsewhere; where a reader needs them, name them in full.

But the decision cannot be made yet, because the failure modes are not in the code:

| Gate | If it fails |
| --- | --- |
| **B1** wildcard subdomain | **No-Go.** Self-service tenant signup produces unreachable tenants. Path-based tenancy is a product redesign, explicitly out of scope. → Option A |
| **B2** wildcard TLS | **No-Go.** `SESSION_SECURE_COOKIE=true` means auth cookies are withheld over an untrusted connection; tenants cannot log in. → Option A |
| **B3** cron | ~~**No-Go.** Leave accrual, invoicing, anomaly scanning, cleanup and every queued email stop. → Option A~~ **Resolved 2026-09-23 — the stated reason is false, MEASURED.** Those do not stop. `POST /api/v1/cron/schedule` and `POST /api/v1/cron/queue` exist (`api/routes/api.php:134-143`, behind `throttle:cron` then `VerifyCronToken`, built `b61cb05`), and `ethr:backup` rides the scheduler. **G0-D stays FAIL** — no gate moves here; what moves is the consequence this row draws from it. Since the rule's premise is gone, the No-Go stands only until someone **re-takes it deliberately**, which is an owner decision and not a documentation edit. Two caveats, neither a route back to the old reason: the endpoints need an **external caller**, unchosen (`deployment/SHARED_HOSTING_PLAN.md` §5.3a, question **Q6**), and nothing on this account has ever executed them |
| **B4** PHP ≥ 8.2 | **No-Go.** Laravel 12 does not boot. → Option A |
| **B5** Node.js runtime | **Not fatal.** Fails → Option B2 (static export), a scoped, costed frontend refactor. |
| **H1** `CREATE TRIGGER` | **Not fatal, but a security downgrade that needs sign-off.** |
| **H2** `max_execution_time` | **Not fatal.** Mitigation: move payroll processing fully behind the queue so the HTTP request only enqueues. |

**Go / No-Go: BLOCKED — pending answers to B1, B2 and B5.** *(Corrected 2026-09-23: this
said "B1–B5". B3 and B4 are answered — see the banner at the top of this file. The
consequence the B3 row drew from its answer was **resolved the same day**: its stated reason
is false, MEASURED, and the row now says so. The No-Go itself is untouched — a gate fired
it, the gate has not moved, and re-taking the decision is the owner's.)*

Nothing in this repository can answer them. They require the account, or a written
answer from Ethio Telecom support. The verification procedure is in
`docs/ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md` §5.

### What it would take to re-take this decision

**Added 2026-09-23, because the rule above has a firing condition and no reversal
condition.** The No-Go fired on **B3**, its stated reason turned out to be false (see the
B3 row), and three documents now say the decision *"stands until someone re-takes it
deliberately"* — without saying what re-taking it would rest on. That asymmetry is the gap:
a rule that can only ever fire one way is not a decision procedure.

**This section does not re-take the decision and does not move a gate.** It names what the
evidence would have to show. **The thresholds are the owner's** — each row below marks who
decides, and none is filled in here.

| What | Status today | What re-taking Go would need | Whose call |
|---|---|---|---|
| **B3 cron** — the gate that fired the rule | **G0-D FAIL**, and its stated consequence is **false, MEASURED** | Nothing further on the evidence. But the endpoints have an **unchosen caller** (**Q6**) and **have never been called from anywhere**, so "resolved" is a mechanism that exists, not one that runs | Q6 is yours; the first successful tick is evidence, per [`deployment/shared-hosting/cron-caller.md`](deployment/shared-hosting/cron-caller.md) |
| **B1 / B2** wildcard subdomain and TLS | **G0-C PARTIAL** — DNS half PASS; the panel accepting `*` rests on an **owner report**, and the vhost has never been created. Wildcard TLS needs DNS-01 | Either a wildcard certificate, which needs the `ethr.et` zone administrator (**Q7**), or an explicit decision that **per-tenant HTTP-01 is acceptable instead**. The second is a product decision, not a measurement | **Yours.** `deployment/SHARED_HOSTING_PLAN.md` §5.3d holds the workaround and calls it unverified |
| **H1** `CREATE TRIGGER` | **G0-F NOT VERIFIED.** `migrate` aborts at `2026_07_22_000001` by design if it is denied | Either the grant, or the accepted-risk path **pre-registered** in [`AUDIT_LOG_INTEGRITY_DECISION.md`](AUDIT_LOG_INTEGRITY_DECISION.md) — which says the choice returns to the owner on refusal and that the mitigating flag must **not** be built pre-emptively | **Yours** (**Q8**). Steps 3 and 7 of §5.5 both stop here |
| **B5** Node.js | **G0-G PARTIAL** — present and application-execution capable at **22.23.2**, below the pinned 24 | Nothing. This was never fatal: it selects the frontend branch, not the answer | Already decided — static export |
| The frontend cost | **G0-B.1/B.2 NOT VERIFIED** | The **canary**. It is five files and six fetches, needs no shell, no cron and no ticket, and has never been run. A B.1 failure voids the ≈8-day estimate outright rather than reducing it | Nobody's decision — it is a measurement waiting to be taken |
| The tier | **ASSUMED**, never panel-read (**Q9**) | A panel read. Nothing structural turns on it | **Yours** |

**Two things this table is careful not to say.**

1. **It does not claim the remaining gates are close to answerable.** Two of the six rows
   wait on people outside this project — the DNS zone administrator (Q7) and, for the tier
   and the plan's capabilities, Ethio Telecom. A third waits on a decision nobody has been
   asked to make yet.
2. **It does not rank the rows.** The canary is the cheapest and the only one that is purely
   a measurement, which is why every next-action list in this corpus puts it first — but
   cheapest is not the same as decisive, and **B1/B2 is the row that decides whether the
   product can have more than one tenant.**

---

## 5. If approved — implementation order

Not started. Listed so the shape of the work is visible before approval.

| # | Commit group | Contents | Depends on |
| --- | --- | --- | --- |
| 1 | `migration/audit` | These three documents + `docs/MIGRATION_STATE.md` | — |
| 2 | `migration/config` | Driver-agnostic health check and `FileStorageService` disk; conditional trigger migration guard | H1 |
| 3 | `migration/deployment` | `docs/deployment/shared-hosting/` — `.htaccess`, env template, deploy checklist, health-check and rollback docs | B1–B4 |
| 4 | `migration/frontend` | Static export **only if B5 fails** | B5 |
| 5 | `migration/queue` | Cron entries for `schedule:run` and `queue:work --stop-when-empty` | B3 |
| 6 | `migration/storage` | Local-disk or external-S3 configuration; verify `temporaryUrl` signing | — |
| 7 | `migration/security` | `.htaccess` denials for `.env`, `.git`, `storage/`, `vendor/`; disable Scramble in production; verify security headers survive the nginx → `.htaccess` move | — |
| 8 | `migration/tests` | Full backend + frontend suites, then the 24 acceptance tests from the brief | all |
| 9 | `migration/docs` | `DATABASE_MIGRATION_PLAN.md`, `DEPLOYMENT_RUNBOOK.md`, `ROLLBACK_RUNBOOK.md`, `PRODUCTION_CHECKLIST.md`, `MIGRATION_CHANGELOG.md` | all |

Rollback throughout is trivial and is the reason this is worth attempting: the VPS
deployment is untouched. DNS is the switch. `scripts/rollback.sh` and
`scripts/restore.sh` already exist.

---

## 6. Estimated complexity

| Area | Complexity | Note |
| --- | --- | --- |
| Backend code | **Very low** | 4 lines across 3 files, plus one conditional migration guard. |
| Backend configuration | **Low** | One `.env`. Every driver swap is already supported. |
| Database migration | **Low–Medium** | `mysqldump` → import. One privilege risk (H1). |
| Frontend (B1, Node available) | **None** | Deploy the existing standalone build. |
| Frontend (B2, static export) | **Medium** | 4 named files, plus `.htaccess` reimplementation of the middleware host rules and the CSP. |
| Deployment tooling | **Medium** | Every existing script assumes Docker + SSH; all need shared-hosting equivalents. |
| Testing | **Medium** | Suites run unchanged; the 24 acceptance tests must run against the real host. |
| **Overall** | **Medium** — *conditional on the gates* | The application is ready. The hosting environment is unverified. |
