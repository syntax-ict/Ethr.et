# ETHR — Code vs Documentation

**Phase 0 forensic audit, read-only. Measured 2026-09-25 against `6d9fb23`.**

Rule applied: **executable evidence outranks prose.** Where a document asserts something
the code contradicts, the code wins and the document is listed for correction. Where a
document asserts something about the *host* rather than the code, neither wins — that is a
measurement nobody has taken, and it is recorded as such rather than resolved.

> **Phase 2 reconciliation applied 2026-09-25.** Rows marked **✅ CLOSED** have been corrected
> in the documents named. The corrections are *inline banners*, not rewrites: every superseded
> claim is struck or quoted alongside what replaced it, so the audit trail survives. Nothing
> under `api/` or `src/` was touched — Phase 2's write scope was existing documentation only.
>
> **Deliberately still open:** the `157 → 159` docblock in
> `api/tests/Feature/Security/tenant-scope-bypasses.php`. It is correct to fix and it sits in
> `api/`, outside Phase 2's scope. **It was not reached for.** Carried to whichever phase may
> touch test files.

The Action column says what a later phase should do for anything not yet closed.

---

## A. Contradictions where code wins

| Topic | Code Evidence | Documentation Claim | Status | Action |
|---|---|---|---|---|
| **Horizon** | Absent from `api/composer.json` and `api/composer.lock`; **no `api/config/horizon.php`**. Removed in `cdf85d1`. `infrastructure/supervisor.conf`, `docker-compose.yml:75`, `docker-compose.prod.yml:2` and `docker-compose.lowmem.yml:2` each carry a header recording that they invoked a command the application does not define | `docs/ARCHITECTURE.md:22` *"(Horizon/Supervisor)"*, `:1109` *"Queue Architecture (Horizon)"*, `:1236`–`1238` three Horizon workers, **`:1254` cites `api/config/horizon.php`** — a file that does not exist. `docs/DEPLOYMENT.md:183`–`185` prescribes `horizon --environment=production-*` | **OBSOLETE — ✅ CLOSED 2026-09-25** | Done. `ARCHITECTURE.md` gained a staleness banner and its diagram, service list, queue table and health-check row were corrected; `DEPLOYMENT.md`'s service table, sizing section, deploy description and three diagnostic snippets now describe `queue:work` and `ethr:queue:check`. The `/horizon` dashboard "open item" was **moot, not deferred**, and says so |
| **Queue names** | **Four**, and the list is authoritative: `QueueHealth::QUEUES = ['default', 'attendance', 'notifications', 'exports']`. `supervisor.conf`, `docker-compose.yml` and `CronRunController::queue` all read from or match it. `grep onQueue` finds only `attendance`, `default`, `notifications`; `exports` arrives via `Schedule::job(..., 'exports')` | `docs/ARCHITECTURE.md:1111`–`1119` lists **seven**: adds `payroll`, `devices`, `sync`. A worker started against that list drains three queues that receive nothing and, if it replaced the real list, would miss none — but the table is the kind of thing an operator copies | **OBSOLETE — ✅ CLOSED 2026-09-25** | Done. `ARCHITECTURE.md`'s table now lists the four, names `QueueHealth::QUEUES` as the source of truth, and states that `payroll`, `devices` and `sync` do not exist — nothing dispatches to them and no worker drains them |
| **Job count** | **16** classes in `api/app/Jobs` | `BASELINE.md` §7 *"**15 job classes**"* | **HISTORICAL — ✅ CLOSED 2026-09-25** | Done. §7 now reads 16, notes all are `ShouldQueue`, and flags that the timeout table below it still lists 15 and was **not** re-derived — an honest partial beats a silently-patched total |
| **Scheduled entry count** | **14** `Schedule::` calls in `routes/console.php` (6 `call`, 6 `job`, 2 `command`) | `BASELINE.md` §8 *"**12 entries**"*. `CronRunController`'s own docblock says *"Eleven of the fourteen entries"* — **the two in-repo sources disagree with each other** | **CONTRADICTORY — ✅ CLOSED 2026-09-25** | Done. §8 now reads 14, with the breakdown (6 `call`, 6 `job`, 2 `command`) and the two that were missing — `ethr:backup` and **the minute heartbeat**, which is what lets `ethr:queue:check` tell *quiet* from *dead*. `CronRunController` was already right and is untouched |
| **`SystemHealthService` storage disk** | `api/app/Services/Admin/SystemHealthService.php:95` reads `Storage::disk(config('filesystems.default'))` | `BASELINE.md` §9 *"`SystemHealthService.php:66` **still hardcodes `Storage::disk('minio')`** — missed by `80cac67` … the admin health page will show storage permanently red on any non-MinIO deployment"* | **OBSOLETE — ✅ CLOSED 2026-09-25** | Done. The §9 bullet is **struck, not deleted** — it was correct when written and the consequence it named was real — with the fix and the current line (`:95`) recorded beside it |
| **Redis as a production dependency** | No `predis/predis` in `composer.lock`; no `ext-redis` platform requirement; **no `Redis::` or Redis facade call in `app/`, `routes/` or `bootstrap/`**. Application comments already state it was removed for this target — `PlatformSetting.php:66`, `DashboardCacheVersion.php:15`, `SiteContentController.php:32` | `docker-compose.yml:35`–`37` selects `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`; `api/.env.example:44`–`46` ships the same | **CONTRADICTORY, and both are true of their own scope** | Neither is wrong: the *application* needs no Redis, the *Docker stack* selects it and supplies `ext-redis` via `pecl install redis` in `docker/php/Dockerfile:25`. The trap is `api/.env.example` — a shared-hosting deployment that copies it selects three drivers the host cannot provide. Point deployment docs at `api/.env.shared-hosting.example` |
| **`BROADCAST_CONNECTION` default** | `api/config/broadcasting.php:40` defaults to **`null`** since 2026-09-25, and `BroadcastConnectionConfigTest` pins the unset case | `api/.env.example:52` ships `BROADCAST_CONNECTION=reverb` | **CONTRADICTORY — deliberate** | Leave. The example file describes the Docker dev stack, which does run Reverb; the config default exists for the no-`.env` path. Worth one line in `.env.example` saying so |
| **`FILESYSTEM_DISK`** | `config/filesystems.php:16` defaults to `local`; `FileStorageService.php:64` resolves `config('filesystems.default')` — *"Storage backend is now a pure config switch"* | `api/.env.example:63` ships `FILESYSTEM_DISK=minio` | **CONTRADICTORY — deliberate**, same shape as the row above | Leave; same one-line note |

---

## B. Documents whose status is stale rather than wrong

| Topic | Code Evidence | Documentation Claim | Status | Action |
|---|---|---|---|---|
| **Migration phase** | `CronRunController` landed `b61cb05` **2026-09-22**; `BACKUP_KEEP` lowered `03cefb8` **2026-09-23**; the canary was corrected `1edaad4` **2026-09-25**. Shared-hosting work has been landing continuously | `docs/MIGRATION_STATE.md` → *"CURRENT PHASE: **Hosting migration: PAUSED at hosting verification, since 2026-08-29** … Migration *code* changes … remain **FROZEN**"*, dated 2026-08-31 | **CONTRADICTORY — ✅ CLOSED 2026-09-25** | Done. A supersession banner now sits **above** the *CURRENT PHASE* block, with the three dated commits that disprove "frozen" and a pointer to Phase 1's result. The 2026-08-31 text is kept verbatim beneath it, labelled as the historical record |
| **Four facts needed to resume** | The probe `scripts/hosting-verification/ethr-hosting-check.php` has a **web-execution route**, so it does not need the SSH session the table assumes; and G0-D has since been answered (FAIL) | `MIGRATION_STATE.md` *"Facts 1–3 come from a single SSH session"*; *"Fact 4 is the only one that is panel-only"* | **HISTORICAL** | The facts are still the right facts; the *How* column is out of date |
| **Node.js gate** | `.nvmrc` = 24 for build; `docker/frontend/Dockerfile:1` runs `node:22-alpine` | `GATE-0-RESULT.md` G0-G was *"Node.js (build only)"* until **corrected 2026-09-22** — the panel offers a startable application | **CURRENT** — already corrected in place | None. Recorded here because the corrected row explicitly warns against reading it as *"22 fails ETHR's Node requirement"* |
| **Tenant-scope bypass count** | `tests/Feature/Security/tenant-scope-bypasses.php` is pinned per file by `TenantScopeBypassInventoryTest` | Root `CLAUDE.md` records **159 real calls across 57 files**, and records at length that a `preg_match_all` count over raw text overcounted by a constant +5 for four days | **CURRENT** | None. The inventory file is the authority; do not re-derive the figure with a regex |

---

## C. Claims that are about the host, where neither source can win

These are listed so that a later phase does not mistake them for documentation defects.
Every one is already tracked in [`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md).

| Topic | Code position | Documentation position | Status | Action |
|---|---|---|---|---|
| `CREATE TRIGGER` | `2026_07_22_000001` aborts `migrate` if denied — *"A deployment that cannot guarantee an immutable audit log must stop and say so"* | G0-F **NOT VERIFIED**; `GATE-0-RESULT.md:1158` argues a favourable prior and calls it *"an argument about priors, not a measurement"* | **UNKNOWN** | Run probe `DB4` |
| Reverse proxy for `/api/` | `client.ts:14` `baseURL: "/api/v1"` relative; `next.config.ts` rewrites | G0-A: *"neither directive textarea present on the settings page (2026-09-17) — strong evidence of FAIL, unconfirmed"* | **UNKNOWN** | Decide between the Node rewrite and the cross-origin rebuild |
| Cron availability | `CronRunController` exists *because* of this | G0-D: *"No Scheduled Tasks / Task Scheduler / Cron Jobs section exists"* — **FAIL, strong evidence, one confirmation short** | **UNKNOWN (leaning FAIL)** | Choose the external timer; `cron-caller.md` covers the mechanics |
| Wildcard subdomain vhost | `ResolveTenant` treats the subdomain as *"the only selector honoured in production"* | G0-C **PARTIAL**: DNS PASS; the panel accepting `*` is *"per owner report, not a measurement"*; wildcard TLS blocked | **UNKNOWN** | Highest product impact of the unknowns — see the Risk Register |
| `.htaccess` honoured | `docs/deployment/shared-hosting/.htaccess` carries the deny rules, `Authorization` and `X-XSRF-Token` passthroughs and the CSP | G0-B.1–B.5 **NOT VERIFIED**; the canary is uploaded and *"no fetch has been performed"* | **UNKNOWN** | Fetch the canary |
| PHP extensions, `memory_limit`, SMTP, quotas, DB version | — | 18 mandatory extensions, all `NOT VERIFIED`; `docs/HOSTING_VERIFICATION_CHECKLIST.md` states its own position: *"NOT VERIFIED is not a soft yes"* | **UNKNOWN** | One probe run answers most of them |

---

## D. Where the documentation is ahead of where you would expect

Recorded because an audit that only lists defects misrepresents the repository.

- **`api/.env.shared-hosting.example`** already replaces every Docker service name, annotates
  each `REDIS_*` line *"LEAVE EMPTY. Redis is not used here"*, and carries the arithmetic
  behind `BACKUP_KEEP=2` inline.
- **`docs/deployment/shared-hosting/DEPLOYMENT.md`** §4a documents the document-root assembly
  down to *"Repoint `index.php` — **three** lines, not two"* and the `public_path()`
  consequence, and §6a states the disk-full failure mode correctly as data corruption rather
  than an outage.
- **`GATE-0-RESULT.md`** distinguishes panel-read from probe-measured throughout, downgrades
  its own rows when evidence weakens (*"the document root row moves from CONFIRMED to
  AMBIGUOUS"*), and has a section titled *"What these readings do not settle"*.
- **`CronRunController`** documents why its routes are excluded from the OpenAPI contract, and
  that the middleware order is load-bearing because a token check before the throttle would
  give an attacker unlimited guesses.

---

## Related

- [`REPOSITORY-INVENTORY.md`](REPOSITORY-INVENTORY.md)
- [`MIGRATION-DOCUMENT-INVENTORY.md`](MIGRATION-DOCUMENT-INVENTORY.md)
- [`BASELINE.md`](BASELINE.md)
- [`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md)
