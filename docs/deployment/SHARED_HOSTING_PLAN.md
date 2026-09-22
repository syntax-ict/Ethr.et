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

**Recorded, not decided.** If RLS is wanted as *defence in depth* on top of the global
scope, that is a legitimate architectural goal — but it is a **new requirement**, not an
existing dependency, and it should be costed as one. This document therefore analyses both
and says which it recommends.

---

## 1. Gap table

Legend — **OK** · **WORKAROUND** (possible, defined, costs something) · **BLOCKER**
(no known route today) · **ASK** (unknown; only the host can answer).

| # | Requirement | Docker prod provides | Gold shared hosting provides | Status |
|---|---|---|---|---|
| 1 | **PHP ≥ 8.2** | `php:8.2-fpm-alpine` | **8.3.33**, panel-read 2026-09-17 | **OK** |
| 2 | **`ext-pdo_mysql`** (declared requirement) | built in image | MySQL plan; extension presence unprobed | **ASK** |
| 3 | **`ext-pdo_pgsql`** | *not installed* — image builds `pdo_mysql` only | unknown; not in `composer.json`, not in the probe's mandatory list | **ASK** — and moot unless PostgreSQL is chosen |
| 4 | **Other extensions** (`gd`, `intl`, `zip`, `bcmath`, `mbstring`, …) | built in image | G0-E rows `NOT VERIFIED` — the probe cannot run (see #9) | **ASK** |
| 5 | **`ext-redis` / a Redis server** | `redis` + `redis-cache` services | **No Redis.** Shared hosting runs no long-running daemon | **WORKAROUND** — `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`. Already the documented shared-hosting env |
| 6 | **Outbound TCP 5432 + TLS** to an external PostgreSQL | N/A (DB is in-network) | **Unknown.** Zero mentions of `5432` in `GATE-0-RESULT.md` or `HOSTING_VERIFICATION_CHECKLIST.md` | **ASK** — wholly uncovered today |
| 7 | **Next.js runtime** | `frontend` service, `output: "standalone"`, `node server.js` on `node:22-alpine` | Plesk Node.js **present and startable** (22.23.2, startup file, app mode, app URL) but **not enabled**; G0-G `PARTIAL` | **ASK** |
| 8 | **`/` → Next, `/api/*` → Laravel routing** | nginx in-compose | Neither directive textarea appears on Apache & nginx Settings (read twice); G0-A `NOT VERIFIED`, strong evidence of FAIL | **BLOCKER** |
| 9 | **Queue worker** (`queue:work`) | `worker-realtime` + `worker-exports` | **No Scheduled Tasks section exists** on the subscription. G0-D **FAIL** | **BLOCKER** |
| 10 | **Scheduler** (`schedule:run`) | `scheduler` service | same absence — same root cause as #9 | **BLOCKER** |
| 11 | **Any `artisan` at all** (`key:generate`, `migrate`, `db:seed`) | `docker exec` | SSH **Forbidden**; Plesk Git *additional deployment actions* run on deploy | **WORKAROUND** — covers one-off install; cannot drive anything recurring |
| 12 | **Storage symlink + writable dirs** | named volume `api_storage` | `storage:link` needs a command run; writability unprobed | **ASK** — and gated by #11 |
| 13 | **Object storage** | `minio` service | none | **WORKAROUND** — local disk, or an external S3 (adds #6's egress question) |
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
so this is the right *shape* and still gated on ask 1. And the static export is **not free**
— `SHARED_HOSTING_AUDIT.md` measured 2026-09-18 that the export does not currently build
(`app/manifest.ts` needs `force-static`; four `[id]` routes are `"use client"`, which Next
forbids combining with `generateStaticParams`, and their ids are tenant data so they need
client-side routing).

### Alternatives

- **Gold + Plesk Node.js app + plan MySQL** — keeps SSR and avoids the static-export work, but depends on G0-A routing (#8) and on enabling Node.js while `Document Root: /ethr` is still unexplained.
- **Option A — stay on the VPS** — everything already works there, including the queue workers fixed on 2026-09-22; it is what the pre-registered No-Go already selected, and costs the migration's entire rationale.

---

## 4. What this document does not do

No gate status changes. No architecture is adopted — this is analysis, and
`SHARED_HOSTING_MIGRATION_PLAN.md` §4's **No-Go → Option A** stands until G0-D moves.
`SHARED-HOSTING-CONTRACT.md` is untouched.
