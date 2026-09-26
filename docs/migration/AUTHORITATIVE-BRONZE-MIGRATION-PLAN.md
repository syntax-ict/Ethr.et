# ETHR — Authoritative Bronze Migration Plan

**Derived from the Phase 0 forensic audit and updated with Phase 1 host verification and
Phase 2 documentation reconciliation — all 2026-09-25, against `6d9fb23` on
`migration/bronze-plesk`.**

> **What has been executed:** Stage 4 (documentation reconciliation) in full, the canary
> half of Stage 1, and — 2026-09-26 — the entity-route half of unknown 3b.
>
> **No dependency, migration or Laravel configuration has been changed in any phase.** That
> sentence used to begin *"No application code, …"* and it no longer holds: four frontend
> routes were refactored on 2026-09-26 to close unknown 3b. The change is deliberately of a
> kind that does not commit the migration — it is correct under `output: "standalone"` as
> well as `"export"`, and an arbitrary id still returns 200 and still server-renders with the
> real param on the deployment that exists today. ~~**`next.config.ts` is untouched; `output`
> is still `"standalone"`.**~~ — true of that pass only; the third paragraph below changed
> it, and `standalone` is now the **default** rather than the only value. See
> [`../audit/BASELINE.md`](../audit/BASELINE.md) §19.
>
> **Verified 2026-09-26, separately from the implementation, and the verification did not
> agree with it.** Two regex escapes had been lost writing the new rules into `.htaccess`;
> they are fixed, and the rules were then replayed *out of the shipped file* against a real
> export listing — 12 of 12 cases. Authorization and tenant isolation were re-checked rather
> than assumed: **0 files changed under `api/`**, no `AuthGuard`/`RoleGate` line in the diff,
> and a foreign `public_id` still 404s at route-model binding under `BelongsToTenant`'s
> global scope. [`../audit/BASELINE.md`](../audit/BASELINE.md) §19i–§19j.
>
> **The rest of the frontend conversion followed on 2026-09-26** — `output`,
> `middleware.ts`, the `(auth)/layout.tsx` `headers()` read, and the `/admin` boundary that
> had no tested replacement. `output` is now a **switch** on `ETHR_TARGET`, not a flip:
> `standalone` remains the default because an export emits no `server.js` and that is what
> the rollback path runs. Three defects were found in the process, **none of them in the item
> being worked on** — the `.htaccess` `/admin` deny was ordered after a catch-all and
> measured 200 instead of 403, its CSP blocked every inline script so nothing hydrated, and
> `useHost()` read the browser during the first render. All three are fixed and measured.
> [`../audit/BASELINE.md`](../audit/BASELINE.md) §20.
>
> Everything else in this plan is unexecuted.
>
> **The headline after Phase 1: there is no confirmed technical incompatibility between ETHR
> and Bronze.** What remains is one commercial precondition, one panel question, and a set of
> measurements that one probe run would close.

Target: **Ethio Telecom Linux Bronze shared hosting under Plesk** — 5 GB storage, 50 GB
bandwidth, 1 MySQL database, 5 email accounts, 5 subdomains, 1 website, SSL. No root, no
sudo, no systemd, no Supervisor, no Docker, no shell.

**Objective, stated so it cannot be misread:** *preserve ETHR business functionality while
replacing infrastructure that genuinely cannot operate on Bronze.* Not *delete everything
VPS-related.*

> ### Placeholders — HARD RULE 2
>
> Per [`../deployment/SHARED-HOSTING-CONTRACT.md`](../deployment/SHARED-HOSTING-CONTRACT.md)
> **HARD RULE 2**, this document is a **procedure**, so every account-specific value is a
> placeholder: `<APP_DOMAIN>`, `<PANEL_HOST>`, `<PANEL_URL>`, `<ACCOUNT_IP>`, `<ACCOUNT_USER>`,
> `<DOCROOT>`, `<APP_ROOT>`, `<DB_NAME>`, `<DB_USER>`. Fill them from the panel. **They are not
> recorded here, and no step in this plan should be followed by pasting a value out of it.**
>
> **The Phase 1 readings in §5 and §7 keep their literals, deliberately.** The same rule
> divides by what a sentence is *for*: a measurement without its actual values is not evidence,
> and templatising one does not anonymise it — it falsifies it. Where a row cites a
> certificate, an address or a redirect target, that is something to **check**, not something
> to fill in.

---

## 0. The finding that should change how this migration is planned

**Most of this migration is already built.** The audit went looking for what would have to be
constructed and found it largely constructed:

| Already in the repository | Evidence |
|---|---|
| A database-backed queue, cache, session and lock stack | `config/{queue,cache,session}.php` all default to `database`; `cache_locks` live-verified on real MariaDB |
| An HTTP-driven `schedule:run` + `queue:work` runner | `CronRunController`, `VerifyCronToken`, `config/cron.php`, routes at `api.php:130`–`146` — landed `b61cb05`, 2026-09-22 |
| A storage backend that is a pure config switch | `FileStorageService.php:64` resolves `config('filesystems.default')` |
| A host-neutral backup and restore path | `ethr:backup` / `ethr:restore`, rehearsed on MariaDB in CI since 2026-09-23 |
| A fully annotated Bronze environment file | `api/.env.shared-hosting.example`, 13.8 KB, every Docker service name already replaced |
| Apache rules, document-root assembly, cron caller, smoke check, rollback | `docs/deployment/shared-hosting/` — 9 files including a 39.9 KB procedure |
| Redis removed from application code | No `predis`, no `ext-redis`, no `Redis::` call in `app/`; three files carry comments stating it was removed *for this target* |
| Horizon removed from the project | `cdf85d1`; absent from `composer.json`, `composer.lock`, and there is no `config/horizon.php` |
| **`.htaccess` mechanisms confirmed working on the host** *(added after Phase 1)* | `mod_rewrite`, `mod_headers`, `<FilesMatch>` deny, `Authorization` passthrough, and `.htaccess` reaching static assets — five gates, measured 2026-09-25 |
| **A wildcard `*.ethr.et` certificate** *(added after Phase 1)* | Let's Encrypt, valid 2026-09-16 → 2026-12-15, already served for the apex |

**The remaining work is dominated by unmeasured host capabilities and two decisions, not by
code.** Planning this as a build effort would repeat work and risk regressing tested
behaviour. See the sequence in §6.

**Phase 1 strengthened this finding rather than qualifying it.** Every architectural worry
Phase 0 carried either dissolved or shrank: `/api` needs no proxy directive; the realtime cost
is one toast's latency; the wildcard certificate already exists; and the `.htaccess` the
deployment depends on is confirmed honoured. **The two things that could still stop a
deployment are a missing `TRIGGER` grant and a missing timer — one grant request and one
scheduling decision, neither of which is a code change.**

---

## 1. KEEP — unchanged, and correct for Bronze

| Item | Why it needs no change |
|---|---|
| **Single-database, `tenant_id`-scoped multi-tenancy** | Bronze provides one database and ETHR needs one. A database-per-tenant design would have made this migration impossible |
| **`BelongsToTenant` fail-closed global scope** | `whereRaw('0 = 1')` with no tenant context. Host-independent |
| **`CurrentTenant` as a `scoped` binding** | Flushed between queued jobs. **Do not regress to `singleton`** — see [`../audit/TENANT-ISOLATION-AUDIT.md`](../audit/TENANT-ISOLATION-AUDIT.md) §3.3 |
| **`ResolveTenant` / `EnsureUserBelongsToTenant` / `EnsurePlatformContext` / `RequirePlatformMfa`** | The isolation stack. Unaffected by hosting |
| **Laravel 12.64 on PHP ^8.2** | Host reads 8.3.33 |
| **`QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` = `database`** | The shipped config defaults already |
| **No `Cache::tags()`, version-key invalidation instead** | `DashboardCacheVersion`; three explicit comments. This is what makes the `database` store viable |
| **All 16 `ShouldQueue` jobs and the 14 `Schedule::` entries** | The asynchronous half of the product. The *runner* changes; the work does not |
| **`CronRunController` and `VerifyCronToken`** | Built for this target. 404s when `CRON_TOKEN` is unset; throttle-before-token order is load-bearing |
| **`ethr:backup` / `ethr:restore`** | The host-neutral path |
| **`FileStorageService`** tenant-prefixed paths | The only place the prefix is applied. Anything writing storage outside it loses isolation |
| **Audit-log triggers** | **Do not soften this migration to get past a missing grant.** `docs/AUDIT_LOG_INTEGRITY_DECISION.md` explains why failure is fatal: the model's `update()`/`delete()` overrides are instance methods that every mass path bypasses |
| **`docker/frontend/Dockerfile`** | Per `VPS-DECOMMISSION.md` §2, it is **the only declaration of the frontend runtime version** and the Plesk Node branch cites it. Its filename is misleading; it is a new-target asset |
| **`docker-compose.yml`, `docker-compose.test.yml`, `docker/php/{Dockerfile,php.ini,www.conf}`** | Local development and the containers `gates.sh` execs into in five places |
| **`scripts/gates.sh` and CI** | CI calls `gates.sh` rather than restating it, so the two cannot drift |
| **Sanctum cookie authentication as currently configured** | Host-only cookie, unencrypted by necessity, opaque token verified by hashed lookup. **Only revisit under the §2 cross-origin branch** |

---

## 2. MODIFY — keep the mechanism, change its configuration or its driver

| Item | Change | Evidence |
|---|---|---|
| **Environment file** | Deploy from `api/.env.shared-hosting.example`, **never** `api/.env.example` — the latter ships `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `FILESYSTEM_DISK=minio`, `BROADCAST_CONNECTION=reverb`, none of which this host can provide | `api/.env.example:44`–`63` vs `api/.env.shared-hosting.example` |
| **`FILESYSTEM_DISK`** | `minio` → `local` (already the config default and already set in the shared-hosting example) | `config/filesystems.php:16` |
| **`BROADCAST_CONNECTION`** | → `null`. **Never `log`** — it writes a line per broadcast against a fixed quota, and filling that quota fails every write path including the database's | `HostingRequirementsConsistencyTest` |
| **Backup retention** | `BACKUP_KEEP=2`, already set. At peak every document exists `keep + 2` = 4 times because `prune()` runs after `create()` | `config/backup.php:41`; `shared-hosting/DEPLOYMENT.md` §6a |
| **Web server rules** | nginx vhost → `docs/deployment/shared-hosting/.htaccess`. Already written; **G0-B.1–B.5 unverified**, canary uploaded, no fetch performed |
| **Document root** | Assemble per `shared-hosting/DEPLOYMENT.md` §4a — three files in `<DOCROOT>`, `index.php` repointed on three lines, with the `public_path()` consequence already documented. `<APP_ROOT>` stays **above** `<DOCROOT>`, which is what keeps `.env` and `storage/` unreachable over HTTP by construction |
| **Node build vs run** | **Under static export there is no Node runtime on the host at all** — `next build` runs off-host and only the emitted `out/` directory ships. The two-version question (build 24, run 22) exists **only on the Node branch**, which contract rule 2 forecloses. **Do not relax `.nvmrc` to fit the host either way** — the host never runs the gates | G0-G, corrected 2026-09-22; branch per `SHARED-HOSTING-CONTRACT.md` rule 2 |
| **`ARCHITECTURE.md` and `DEPLOYMENT.md`** | Remove the Horizon topology and the seven-queue table; the real list is `QueueHealth::QUEUES` (four) | [`../audit/CODE-VS-DOCUMENTATION.md`](../audit/CODE-VS-DOCUMENTATION.md) §A |
| **`BASELINE.md` §7, §8, §9** | 15 jobs → 16; 12 scheduled entries → 14; strike the `SystemHealthService` MinIO bullet — that defect is fixed (`:95` reads `config('filesystems.default')`) | same |
| **`MIGRATION_STATE.md`** | Move the supersession notice above the *CURRENT PHASE* block, which still says the migration is PAUSED and code FROZEN while shared-hosting code landed 2026-09-22/23/25 | same |
| **`tenant-scope-bypasses.php` docblock** | 157 → 159, to match the data in the same file | [`../audit/TENANT-ISOLATION-AUDIT.md`](../audit/TENANT-ISOLATION-AUDIT.md) §4 |

---

## 3. REPLACE — the mechanism cannot run on Bronze; the feature survives

| Feature | Current mechanism | Bronze replacement | Status |
|---|---|---|---|
| **Queue execution** | `supervisor.conf` `[program:ethr-queue]`, a `--max-time=3600` daemon | `POST /api/v1/cron/queue` → `queue:work --stop-when-empty --max-time=50`, under a `Cache::lock`, 409 on overlap | **Built.** Needs a timer to call it |
| **Scheduler** | `supervisor.conf` `[program:ethr-scheduler]`, a `while true; sleep 60` loop | `POST /api/v1/cron/schedule` → `schedule:run` | **Built.** Needs a timer to call it |
| **The timer itself** | `cron` / Supervisor | Plesk Scheduled Task if one exists; otherwise any external URL-fetch on a timer | **DECISION REQUIRED.** G0-D = FAIL, strong evidence: no Scheduled Tasks section on the subscription. `shared-hosting/cron-caller.md` has the mechanics |
| **Process supervision** | Supervisor | Plesk's Node.js application manager for the frontend; nothing for PHP, which is request-scoped | Partly built |
| **Web server** | nginx | Plesk Apache + `.htaccess` | **Built, unverified** |
| **TLS** | certbot webroot | Plesk-issued certificates; `.htaccess` already carries an ACME passthrough | **Built** |
| **Backup/restore transport** | `docker compose exec mariadb mysqldump` | `ethr:backup` / `ethr:restore` | **Built.** Never rehearsed on the Ethio Telecom host |
| **Object storage** | MinIO container | `local` disk; `s3` remains available for off-host retention | **Built, off-host not configured** |
| **Realtime transport** | Reverb daemon | **None.** `BROADCAST_CONNECTION=null`; the 5 broadcast events go quiet | **Accepted degradation** — see §3a |
| **`/api` same-origin routing** | nginx `fastcgi_pass` | Next.js rewrites in `next.config.ts`, served by the Plesk Node app | **Built, contingent** — see §5 |

### 3a. What is actually lost when Reverb is absent — and what is not

> **Measured in Phase 1, 2026-09-25, and it is smaller than this section first claimed.**
> There are **3** `ShouldBroadcast` events, not 5 — `grep -rl` had matched the string inside
> comments. All three publish to `tenant.{id}`, and **nothing in the frontend subscribes to
> `tenant.*` or `device.*`.** The sole Echo subscription is `user.{userId}`, in
> `reverb-provider.tsx`. See [`../audit/BRONZE-BLOCKER-RESOLUTION.md`](../audit/BRONZE-BLOCKER-RESOLUTION.md) §9.

Lost, stated as measured rather than assumed: **the notification toast arrives on the next
30-second poll instead of instantly.** That is the whole of it. The three broadcasting events
(`AttendanceRecorded`, `DeviceOffline`, `EmployeeTransitioned`) reach no user interface on
any target today, Reverb running or not.

**Not lost: the underlying business feature.** Every broadcast accompanies a database write
already exposed by the REST API; `refetchInterval` polling already ships on notifications,
devices, admin, team and payroll surfaces; and `reverb-provider.tsx` wraps its subscription in
a `try/catch` commented *"Reverb unavailable … fall back to polling"*, so **polling is the
shipped default when Reverb is absent, not a contingency to build.** The three
failure-notification listeners (`NotifyDeviceOffline`, `NotifyDeviceSyncFailed`,
`NotifyPayrollRunFailed`) are **queued listeners writing database notifications, not
broadcasts** — so *"someone is told when a device or a payroll run fails"* works with
`BROADCAST_CONNECTION=null`.

**Latency changes; capability does not.** No feature should be deleted on account of Reverb
being unavailable.

---

## 4. REMOVE — only what is proven unnecessary

**This section defers entirely to
[`../deployment/VPS-DECOMMISSION.md`](../deployment/VPS-DECOMMISSION.md), which is a more
careful analysis than this plan should duplicate.** Three points from it that this audit
independently confirms:

1. **Nothing is removable yet.** That document's status is **NOT TRIGGERED**, and
   `docs/VPS_DEPLOYMENT.md` is the rollback path — *"Out last, not first."* The owner
   directed removal on 2026-09-25; the trigger is a **verified cutover**, which has not
   happened.
2. **The load-bearing set is real and easy to get wrong.** `docker/frontend/Dockerfile`,
   `docker-compose.yml`, `docker-compose.test.yml` and `docker/php/{Dockerfile,php.ini,www.conf}`
   were swept up by the phrase *"VPS files"* because of where they live. The distinction that
   matters is **production-VPS-only** versus **development, CI, or new-target**.
3. **Its own §3 list is annotated as incomplete**, measured 2026-09-25, after a removal
   attempt stopped at the deletion step. *"Every one of these findings would have been
   discovered mid-removal, which is the worst moment to discover them."*

**What this audit adds:** `docker-compose.prod.yml` is cited by 13 documents,
`docker-compose.lowmem.yml` by 4, `docker-compose.hostnames.yml` by 2. **Removal is a
documentation change of the same size as the deletion**, and `./scripts/gates.sh docs`
checks markdown link integrity — so a partial removal turns a gate red.

**Removable with no further evidence needed:** nothing. **Removable once cutover is
verified:** the §3 list in `VPS-DECOMMISSION.md`, with its citations updated in the same
change.

---

## 5. UNKNOWN / REQUIRES EXTERNAL VERIFICATION

Every row is a host capability, not a code question. All are tracked in
[`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md).

> **Updated 2026-09-25 after Phase 1 host verification**
> ([`../audit/BRONZE-BLOCKER-RESOLUTION.md`](../audit/BRONZE-BLOCKER-RESOLUTION.md)). **Twelve
> unknowns became five and a half.** Rows 3, 4 and 5 changed most, and two of them changed in
> the favourable direction — which matters, because a plan that only ever adds risk stops being
> read. Closed rows are struck and kept.

| # | Question | Current reading | How it is answered | Cost if the answer is bad |
|---|---|---|---|---|
| 1 | **What drives `schedule:run`?** | **G0-D FAIL, unchanged** — no Scheduled Tasks section (owner-read 2026-09-18); Dev Tools has PHP, Git, Composer, **no Terminal**. Not observable over HTTP, so Phase 1 could neither confirm nor contradict it | Panel confirmation, then choose an external URL-fetch timer | **The entire asynchronous half of the product is inert, silently.** No payroll completion, no billing, no backups, no leave accrual. **Now the top technical blocker** |
| 2 | **`CREATE TRIGGER` granted?** | **NOT VERIFIED, unchanged.** Favourable prior — `TRIGGER` is part of `ALL PRIVILEGES` on a schema, needs no `SUPER`, Plesk's default is `ALL`; `GATE-0-RESULT.md:1158` calls that *"an argument about priors, not a measurement"*. **The probe could not be placed** — no shell, no FTP, no panel — and **no substitute was improvised** | Probe `DB4`, whose test is safe and reversible: scratch table → trigger → `DROP TRIGGER` → `DROP TABLE` | `migrate` **aborts by design**. Remedy is a grant request, **not a code change** |
| 3 | ~~**Can the Node app serve the `/api` rewrite, or does a proxy directive exist?**~~ **ANSWERED, and it is no longer a branch decision** | **`/api` reaching Laravel: ANSWERED.** `mod_rewrite` honoured (G0-B.1 PASS), PHP-FPM answers `.php` in the document root, `Authorization` reaches PHP (G0-B.4 PASS). **No reverse-proxy directive is required.** Under static export — the path rule 2 mandates — **nothing contends for `/`**, so the who-owns-`/` question does not arise | No action while rule 2 stands | **None.** The cross-origin rebuild was only ever reachable via the Node branch, which rule 2 forecloses |
| 3b | ~~**Does static export's step 4 actually work?**~~ **CLOSED 2026-09-26** | **It did not, and the step was wrong in three ways rather than one.** The two known since 2026-09-18 (`7aed9d2`) were confirmed: all four routes are `"use client"` so Next rejects `generateStaticParams`, and `[]` 404s every real id. The third had not been named — **the generic BRANCH B rewrite would have 404'd every page on the site**, entity routes or not, because it rewrote to `$1/index.html` and this exporter writes `features.html`. All three are closed and **independently verified 2026-09-26** — see [`../audit/BASELINE.md`](../audit/BASELINE.md) §19, with the verification in §19i and the closure statement in §19j | Done. Four server-component wrappers, one sentinel shell per route, the id read from `usePathname()`, and a rewritten BRANCH B rule set | **None for the four routes.** What remains of the static-export path is steps 1–3 — the `output` switch, `middleware.ts`, and the `(auth)/layout.tsx` `headers()` read — and `middleware.ts`'s `/admin` boundary is its own row in §7 |
| 4 | **Wildcard subdomain as one vhost, with TLS?** | **Four answers, and they disagree.** DNS **VERIFIED**; a valid `*.ethr.et` **certificate already exists** (LE, 2026-09-16 → 2026-12-15 — this *contradicts* the register's *"wildcard blocked, needs DNS-01"*); **no vhost configured**, so every tenant hostname serves the **Plesk panel login**, `admin.ethr.et` included; **quota still unknown**. **Owner states wildcard activates on subscription payment** — testimony, not panel output | Pay, then re-measure: status **and** served certificate (§5 of the blocker doc) | **Commercial precondition, not a technical blocker.** No ETHR change implied, and no fallback tenant-selector strategy needs designing. The *"about three tenants"* arithmetic is a **conditional** that only applies if wildcards are unavailable **and** each tenant costs a slot — it should not be quoted as a finding |
| 5 | **`.htaccess` honoured?** | **Mostly ANSWERED — five of seven.** `mod_rewrite` ✅, `mod_headers` ✅, `<FilesMatch>` deny ✅ 403, `Authorization` ✅, static-asset shadowing ✅ **REWRITE WINS** (the favourable answer — headers and `Cache-Control: immutable` do reach `.js`/`.css`). **Two unanswered:** `RewriteRule … [F,L]` deny and CSP survival, because the canary on the host is the **2026-09-18 revision** and the two baits that test them joined in `1edaad4` | Re-upload the current canary, two fetches | **`[F,L]` deny is the one that blocks every branch** — it is the mechanism `shared-hosting/.htaccess` uses to protect `api/.env`, `.git/` and `composer.json`. A 200 there means `APP_KEY` and the database password are web-readable **while the application works normally** |
| 6 | **18 PHP extensions, `memory_limit` ≥ 256M, `max_execution_time` ≥ 120s** | **Version ANSWERED — PHP 8.3.33**, measured from a live `X-Powered-By` header, upgrading the prior panel reading. Extensions and limits NOT VERIFIED | One probe run | A missing extension is a hard stop. Note `.htaccess`'s `php_value max_execution_time 120` applies under `mod_php` only, **not FastCGI — and the host runs `fpm-fcgi`**, so that line is inert here |
| 7 | **Outbound SMTP 587/465, and the mailbox send cap** | NOT VERIFIED | Probe + panel | Password reset, approval reminders, digests and scheduled reports die silently. Nothing in the code respects a send cap, and the fan-out jobs are the exposure |
| 8 | **MySQL version ≥ 10.2.7 / 5.7.9, server charset `utf8mb4`** | NOT VERIFIED | Probe `DB1`, `DB10` | The VIRTUAL generated column on `devices` needs 5.7.6+ / 10.2.1+ |
| 9 | **Disk and database quotas, actual free space** | NOT VERIFIED | Panel | **A full quota presents as data corruption, not as an outage** — writes fail while reads succeed, logs fail first so the evidence goes missing, and `/api/v1/health` still returns 200 |
| 10 | **`symlink()` available** for `storage:link` | Probe `ST5` NOT VERIFIED | Probe | Mitigable: switching `FILESYSTEM_DISK` off `local` removes the need |
| 11 | **Payroll execution time under the host's limits** | No measurement on this host. The project's own budget is *"500 employees < 30s"* **on dedicated hardware** | Measure after deployment | `ProcessPayrollJob` runs inside the 50-second `queue:work` window driven over HTTP. See Risk 4 |
| 12 | **50 GB bandwidth against real usage** | Nothing measures or caps it. One-year immutable asset caching in `.htaccess` is the only mitigation | Panel statistics | Overage terms unknown |

---

## 6. Recommended migration sequence

Ordered so that each step's outcome changes what the next step is, and so that **nothing
irreversible happens before the cutover is verified**. Consistent with the
`SHARED-HOSTING-CONTRACT.md` hard rule: the deliverables are repository-side; the panel
readings are evidence, and the manual items belong in `MIGRATION_STATE.md`'s queue rather
than at the end of every report.

> **Progress, 2026-09-25.** **Stage 4 is DONE** and **Stage 1 is half done**. The two
> architectural forks in Stage 2 have both moved, one of them decisively. Revised status is
> marked per stage; the ordering itself did not need changing, which is the useful part — the
> sequence survived contact with the host.
>
> **Added 2026-09-26.** Unknown **3b is closed** — the four `[id]` routes export, and the
> BRANCH B rule set that serves them was measured rather than reasoned about. It is recorded
> here rather than as a new stage because **it did not move the sequence**: it was a defect on
> the mandated path, not a step in it, and the stage-1 probe and the stage-3 timer are still
> the two things everything waits on. What is left of the static-export task is steps 1–3 of
> [`../deployment/shared-hosting/DEPLOYMENT.md`](../deployment/shared-hosting/DEPLOYMENT.md) §5,
> and the `/admin` boundary row in §7 below.

**Stage 1 — Measure, change nothing. ◐ HALF DONE.** The **canary half is executed** (five gates
verified). The **probe half is not**: `ethr-hosting-check.php` must be placed on the host, and
the Phase 1 session had no shell, FTP or panel. It still answers unknowns **2, 6, 7, 8, 10 and
most of 9 in one run** — the single highest-leverage action available, and the only route to
`CREATE TRIGGER`.

**Stage 1b — the cheapest outstanding action in this plan. ▲ NEW, DO FIRST.** Re-upload the
current canary directory and fetch two URLs. It closes `[F,L]` deny and CSP survival. **`[F,L]`
deny is the only unanswered result that blocks deployment on every branch**, and it is one
upload and two fetches. It is new because Phase 1 discovered the host is running the
**2026-09-18** canary, so the two baits added precisely to close a known false-pass were never
there to fetch.

**Stage 2 — Settle the two architectural decisions. ◐ BOTH MOVED:**

- **Unknown 3 (`/api` routing) — the expensive half dissolved.** Serving `/api` needs **no
  proxy directive**: `mod_rewrite`, PHP-FPM and `Authorization` passthrough are all measured
  working, so `.htaccess` routes `/api` and `/sanctum` to `index.php` unaided.

  > **Correction, 2026-09-25 — this bullet previously concluded "the Node branch is not
  > foreclosed." That was wrong, and the error is worth naming because it is a pattern this
  > repository already documents.** `SHARED-HOSTING-CONTRACT.md` forecloses Node on **two
  > independent grounds**: rule 3 (*"a field that is absent is … a dependency to remove"*,
  > citing G0-A) **and rule 2 — *"A Plesk extension is not a default. The Node.js extension is
  > the case in point."*** Phase 1 retired the *routing force* of rule 3. **It did not touch
  > rule 2 at all**, and G0-G's panel reading — that the extension is present and offered —
  > confirms it is an extension, which is precisely what rule 2 excludes.
  >
  > Killing one of two independent reasons and writing as if both were gone is the same
  > mistake the root `CLAUDE.md` records for the 156/161 bypass count: *"They were different
  > quantities that happened to coincide."* **Static export remains the path**, by the owner's
  > standing rule and not by a routing argument.

  What remains of unknown 3 is therefore **narrower than a branch decision**: if the Node app
  is never enabled, no process contends for `/` and `.htaccess` serves everything. The
  who-owns-`/` question only arises if rule 2 is lifted.
- **Unknown 4 (wildcard) — reclassified from technical to commercial.** The owner states it
  activates on subscription payment. It remains a **product** conversation if that turns out to
  be wrong, but it is no longer an architecture question, and **no fallback tenant-selector
  strategy should be designed on spec.**

**Stage 3 — Settle the timer (unknown 1). ● UNCHANGED, and now the top technical blocker.**
The runner exists; only its trigger is open. If no Plesk Scheduled Task exists, choose the
external URL-fetch mechanism from `shared-hosting/cron-caller.md`. **Until this is settled
nothing asynchronous works, and the failure is silent** — which is why `QueueHealth::beat()`
and `ethr:queue:check` exist and why they should be checked first after cutover.

**Stage 4 — Documentation reconciliation. ✅ DONE 2026-09-25.** `ARCHITECTURE.md` (staleness
banner, queue table 7 → 4, service list, diagram, health-check row), `DEPLOYMENT.md` (service
table, worker sizing, deploy description, three diagnostic snippets), `VPS_DEPLOYMENT.md` (a
verification command asserting six Horizon supervisors), `BASELINE.md` §7/§8/§9,
`MIGRATION_STATE.md` (supersession banner + manual queue), `README.md` (index gap, two stale
caveats, a third naming collision). Corrections are inline banners, not rewrites — **every
superseded claim is struck or quoted beside what replaced it.** Carried forward deliberately:
the `157 → 159` docblock in `api/tests/`, outside that pass's write scope.

**Stage 5 — Deploy, following `shared-hosting/DEPLOYMENT.md` unchanged.** It already branches
on the stage 1–3 answers. Do not write a second procedure.

**Stage 6 — Verify.** `scripts/shared-hosting/smoke-check.sh`, then the queue heartbeat, then
a **restore rehearsal on the host** — the backup path has been rehearsed in CI on MariaDB
since 2026-09-23 and **never on this host**, and `BASELINE.md` §15f/§15g record that this path
carried a defect while every test of it was green *and the first written account of the defect
was itself wrong in two places*.

**Stage 7 — Only now, VPS decommission**, per `VPS-DECOMMISSION.md`, with the 19 document
citations updated in the same change.

---

## 7. Bronze Risk Register

| Risk | Evidence | Severity | Required Decision |
|---|---|---|---|
| **No timer exists on the host to drive `schedule:run`, so all asynchronous work is inert and silent** | G0-D **FAIL, strong evidence** — no Scheduled Tasks / Task Scheduler / Cron Jobs section on the subscription, owner-read 2026-09-18; Dev Tools has no Terminal. All 16 `app/Jobs` classes are `ShouldQueue`; 12 of 14 `Schedule::` entries exist only to enqueue | **CRITICAL** | Which external URL-fetch service drives `POST /api/v1/cron/{schedule,queue}`, and who owns its uptime |
| **`CREATE TRIGGER` may be denied, and `migrate` aborts by design** | `2026_07_22_000001` rethrows on MySQL/MariaDB; `docs/AUDIT_LOG_INTEGRITY_DECISION.md`; G0-F NOT VERIFIED; `GATE-0-RESULT.md:1158` argues a favourable prior and declines to treat it as a measurement | **CRITICAL** | Run probe `DB4`. If denied: request the grant. **Do not soften the migration** — the trigger is the only control that survives a mass update |
| **`[F,L]` deny rules may be ignored, leaving `api/.env` web-readable while the app works normally** | Phase 1: `secret.env.probe` → **404, not a pass** — the bait is absent because the host runs the 2026-09-18 canary. `shared-hosting/.htaccess` uses `RewriteRule … [F,L]`, **not** the `<FilesMatch>` form that did pass. `RUN-SHEET.md` §4a: *"A 200 on this bait blocks deployment on every branch"* | **HIGH — and the cheapest to close** | Re-upload the current canary, fetch one URL. Exposure if it fails is `APP_KEY` and the database password |
| ~~**Static export's entity-route step does not work as written**~~ **— closed 2026-09-26** | The 2026-09-18 measurement held and a third defect was found beside it: the generic BRANCH B rewrite matched nothing this exporter writes, so it would have 404'd `/features` and `/pricing` too. Closed with server-component wrappers, one sentinel shell per route, and `useRouteId` reading the id from `usePathname()` — because on such a shell `useParams()` returns the **sentinel**, which is the trap. Verified against the shipped files 2026-09-26, which **found and fixed two lost regex escapes in the new rules** — [`../audit/BASELINE.md`](../audit/BASELINE.md) §19i | **CLOSED** | None. **Do not re-derive "the four routes cannot be exported"** — they can, and the test that pins it is `src/src/test/static-export-route-id.test.tsx` |
| ~~**Same-origin `/api` may be impossible**~~ **— retired entirely** | Phase 1 measured `mod_rewrite` ✅, PHP-FPM answering `.php` ✅, `Authorization` → PHP ✅ — **serving `/api` needs no proxy directive**. The surviving half (Node claiming `/`) only existed on the Node branch, which contract rule 2 forecloses | **CLOSED** | None. **Do not re-derive "G0-A forces static export"** — the routing premise is retired; *rule 2* is what mandates static export |
| **Payroll runs inside a 50-second HTTP-driven queue window** | Budget is *"500 employees < 30s"* on dedicated hardware — `BASELINE.md` §13a records the documented best case *"already sits at the limit"*. `CRON_QUEUE_MAX_SECONDS=50` bounds the worker under the HTTP timeout | **HIGH** | Measure on the host. If a run exceeds the window it is re-reserved after `retry_after` (1200s), and `ProcessPayrollJob` has `tries = 1` deliberately — so a crossed boundary is a **failed run**, not a duplicated one. Confirm that is the intended behaviour at the observed sizes |
| **No wildcard vhost — every tenant hostname currently serves the Plesk panel login** | Phase 1: `admin.ethr.et` and a random label both **303 → `/login.php`** under the `lin6.ethiotelecom.et` certificate. `ResolveTenant`: the subdomain is *"the only selector honoured in production"*. **Owner: activates on subscription payment** | **MEDIUM** (was HIGH) — commercial, not technical | Pay, then re-measure **status and certificate** (§5 of the blocker doc). **No code change implied.** Do not design a fallback selector on spec |
| **A wildcard certificate already exists while the register says it is blocked** | `SAN: DNS:*.ethr.et`, LE, valid 2026-09-16 → 2026-12-15, served for the apex. `GATE-0-RESULT.md` G0-C still reads *"wildcard blocked, needs DNS-01"* | **LOW — but it is a stale gate** | Split G0-C's TLS row into **issuance** (VERIFIED) and **binding** (absent). Recorded, register deliberately unmodified |
| **A full 5 GB quota presents as data corruption, not as an outage** | `shared-hosting/DEPLOYMENT.md` §6a; `BACKUP_KEEP=2` gives 4 copies of the document store at peak; off-host retention wired but **not configured**; `/api/v1/health` is not a disk check | **HIGH** | Configure `--off-host` with a real S3-compatible disk, or accept local-only retention and monitor free space in the panel from week one |
| ~~**`.htaccess` behaviour entirely unverified**~~ **— five of seven now verified** | Phase 1, eight fetches: `mod_rewrite` ✅, `mod_headers` ✅, `<FilesMatch>` deny ✅, `Authorization` ✅, static-asset shadowing ✅ **REWRITE WINS**. The two open ones are their own row above | **LOW** (was HIGH) | None for the five. The register's *"no fetch has been performed"* no longer holds |
| **Backup/restore never rehearsed on this host** | Rehearsed in CI on MariaDB since 2026-09-23. `BASELINE.md` §15f/§15g: the path carried a defect that made a dump replayable only on a permissive engine, every test was green, and the first account of the defect was itself wrong twice | **HIGH** | Rehearse a full restore on the host before cutover, not after |
| **SMTP unverified and no send-rate awareness in code** | G0-H NOT VERIFIED; `NotifyAnnouncementAudienceJob` and `SendApprovalRemindersJob` fan out | **MEDIUM** | Probe 587/465, read the mailbox cap, decide whether fan-out needs throttling |
| **44 of 159 tenant-scope bypasses are safe because of something outside the query** | `BASELINE.md` §11i/§11j; nothing found that can reach another tenant's rows in two strict passes | **MEDIUM** | No action for this migration. Do not add a 160th without reading `TenantScopeBypassInventoryTest`'s failure message |
| ~~**A static-export fallback would remove `middleware.ts`**, and with it the structural `/admin` host boundary~~ **— reframed and largely closed 2026-09-26** | Two premises were wrong. `middleware.ts` is **not removed** — Next 16.3.5 exports with it present and disables it, so it still serves the VPS path and development. And the `/admin` rule it provided is **routing, not authorization**: the boundary that protects the data is `EnsurePlatformContext` (404 whenever a tenant resolves), `Gate::authorize('admin.manage')` and `RequirePlatformMfa`, all server-side and all untouched. The routing control is replaced by `.htaccess` — which **did not work as ordered**, measuring 200 on a tenant host until the deny was moved above the fallbacks. [`../audit/BASELINE.md`](../audit/BASELINE.md) §20c, §20e | **LOW**, and host-gated | None repository-side. What remains is **M1**: if Apache ignores `[F,L]`, `/admin`'s HTML is served from every tenant host and the three API controls are the whole boundary. That is defence-in-depth lost, not data exposed — but confirm it before cutover |
| ~~**Documentation prescribes Horizon, `config/horizon.php` and seven queues that do not exist**~~ | Was `ARCHITECTURE.md:22`, `:1109`, `:1236`–`1238`, `:1254`; `DEPLOYMENT.md:183`–`185`. **It was worse than first reported** — ~14 locations in `DEPLOYMENT.md` alone, including the deploy procedure and three diagnostic snippets an operator would paste during an incident | **CLOSED 2026-09-25** | Done in stage 4. Corrections were rebuilt from `docker-compose.prod.yml` and `deploy.sh` rather than adjusted, to avoid replacing one wrong claim with another |
| **A deployment that copies `api/.env.example` selects three drivers the host cannot provide** | `.env.example:44`–`46`, `:52`, `:63` | **MEDIUM** | Make `api/.env.shared-hosting.example` the only path named in Bronze documentation |
| **`FILESYSTEM_DISK=local` puts every tenant's documents under one account**, separated only by `FileStorageService`'s prefix | `FileStorageService::upload()` | **MEDIUM** | Accept, and treat any new storage writer that bypasses the service as an isolation defect |
| **Bandwidth (50 GB) is unmeasured and uncapped** | Only mitigation is one-year immutable asset caching in `.htaccess` | **LOW** | Read panel statistics after week one |
| **VPS removal executed before cutover is verified** | `VPS-DECOMMISSION.md` status **NOT TRIGGERED**; `VPS_DEPLOYMENT.md` is the rollback path; its own §3 list is annotated as incomplete after a removal attempt stopped at the deletion step | **LOW while untriggered, CRITICAL if triggered early** | Removal happens in stage 7, never before |

---

## 8. What this plan does not do

- **Does not propose removing any business feature.** Reverb's transport is unavailable; the
  notifications, device status and attendance data it carried all remain reachable over REST.
- **Does not propose a database change.** One MySQL database is what ETHR already needs.
- **Does not propose a framework, PHP or dependency upgrade.**
- **Does not re-score a single G0 gate**, re-derive the bypass count, or restate
  `BASELINE.md`. Phase 1 took new host measurements and **`GATE-0-RESULT.md` was still left
  unmodified** — the readings live in
  [`../audit/BRONZE-BLOCKER-RESOLUTION.md`](../audit/BRONZE-BLOCKER-RESOLUTION.md), with the
  eight discrepancies listed rather than applied. Folding them in is the register owner's call.
- **Does not write a second deployment procedure.** `shared-hosting/DEPLOYMENT.md` exists and
  already branches on the open unknowns.
- **Does not route work through the Plesk panel.** Per `SHARED-HOSTING-CONTRACT.md`'s hard
  rule, the host-side items are named **once**, in `MIGRATION_STATE.md`'s manual queue
  (**M1–M5**), rather than repeated as instructions at the end of every report.

---

## Related

- [`../audit/REPOSITORY-INVENTORY.md`](../audit/REPOSITORY-INVENTORY.md)
- [`../audit/CODE-VS-DOCUMENTATION.md`](../audit/CODE-VS-DOCUMENTATION.md)
- [`../audit/VPS-SHARED-HOSTING-MATRIX.md`](../audit/VPS-SHARED-HOSTING-MATRIX.md)
- [`../audit/BRONZE-COMPATIBILITY-MATRIX.md`](../audit/BRONZE-COMPATIBILITY-MATRIX.md)
- [`../audit/MIGRATION-DOCUMENT-INVENTORY.md`](../audit/MIGRATION-DOCUMENT-INVENTORY.md)
- [`../audit/TENANT-ISOLATION-AUDIT.md`](../audit/TENANT-ISOLATION-AUDIT.md)
- [`../deployment/SHARED-HOSTING-CONTRACT.md`](../deployment/SHARED-HOSTING-CONTRACT.md) — outranks this plan
- [`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md)
- [`../deployment/shared-hosting/DEPLOYMENT.md`](../deployment/shared-hosting/DEPLOYMENT.md)
- [`../deployment/VPS-DECOMMISSION.md`](../deployment/VPS-DECOMMISSION.md)
