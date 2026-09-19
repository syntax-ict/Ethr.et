# Ethio Telecom Shared Hosting — Capability Matrix (Phase 2)

**Date:** 2026-08-29
**Method:** public product pages and the Ethio Telecom hosting portal. **No account
was available to this audit**, so nothing here was tested against a live environment.

> **Partially superseded 2026-09-17/18.** An account is now available and some rows are
> measured: **B4 → VERIFIED 8.3.33**, **H7 → UNSUPPORTED (SSH Forbidden)**, wildcard DNS
> re-confirmed, document root confirmed as `httpdocs`. The live register — with the
> measured values, blockers **B-1…B-6** and the manual action queue — is
> `deployment/GATE-0-RESULT.md` and `MIGRATION_STATE.md`. Rows below that still read
> UNKNOWN are still unknown; this file was **not** bulk-updated, because a matrix edited
> from memory is worse than one that is honestly stale.

Every row is classified as **VERIFIED**, **UNKNOWN** or **UNSUPPORTED**.
**UNKNOWN is never treated as supported.** The Go/No-Go decision in
`docs/SHARED_HOSTING_MIGRATION_PLAN.md` depends on resolving the UNKNOWN rows marked
**BLOCKING**.

Two of the sources returned `unable to verify the first certificate` when fetched
(`www.ethiotelecom.et`, `myportal.ethiotelecom.et`). Product-tier figures below come
from search-result summaries and a third-party hosting directory, and should be
re-confirmed against the portal before purchase.

---

## 1. Product tiers (VERIFIED from public listings — re-confirm on the portal)

Ethio Telecom sells **Linux Web Hosting (with MySQL DB)**, **Windows Web Hosting (with
MS SQL Server DB)**, **VPS** and **Dedicated Hosting**. ETHR requires the **Linux +
MySQL** line; the Windows/MSSQL line is not viable (Laravel targets MySQL/MariaDB here,
and `config/database.php` has no `sqlsrv` connection).

| Tier | Storage | Bandwidth | Databases | Email accts | Subdomains | SSL |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 5 GB | 50 GB | 1 | 5 | listed | Free |
| 2 | 20 GB | 250 GB | 3 | 10 | listed | Free |
| 3 | 50 GB | Unlimited | 5 | 25 | listed | Free |
| 4 | 100 GB | Unlimited | 10 | 90 | Unlimited | Free |

Control panel: **Plesk** (not cPanel). Price band roughly **452–1,009 ETB**.
Server location: Ethiopia.

**Database count is not a constraint for ETHR.** §A of the audit verified single-database
shared-schema tenancy: one database serves every tenant. Even tier 1 is schema-sufficient.

**Storage is a real constraint.** ETHR stores employee photos, attendance selfies,
documents and per-tenant backups (`BackupTenantJob`). If storage stays on the hosting
account rather than external S3, tier 3 or 4 is the realistic floor.

### VPS tiers (VERIFIED — relevant to the hybrid option)

| Plan | RAM | Storage | Bandwidth | Root | Price |
| --- | --- | --- | --- | --- | --- |
| VPS Gold | 4 GB | 50 GB | Unlimited | Yes | ETB 10,379 / yr |
| VPS Platinum | 8 GB | 100 GB | Unlimited | Yes | ETB 15,845 / yr |

CPU core counts are not published. Both include root access and are Linux.

---

## 2. Capability matrix

### 2.1 BLOCKING unknowns — these decide Go/No-Go

| # | Capability | Status | Why it blocks | How to verify |
| --- | --- | --- | --- | --- |
| B1 | **Wildcard subdomain** `*.ethr.et` served by one vhost | **UNKNOWN** | `ResolveTenant` resolves tenants **only** from the subdomain in production. Without a catch-all, every new tenant needs a manual Plesk subdomain — self-service signup (`RegisterTenantRequest` → `ProvisionTenant`) silently produces tenants nobody can reach. | Ask support: "Can I configure a wildcard subdomain `*.mydomain.et` pointing at one document root?" In Plesk this is *Add Subdomain* with the name `*` — supported by Plesk itself, but frequently disabled on shared plans. |
| B2 | **Wildcard TLS certificate** `*.ethr.et` | **UNKNOWN** | "Free SSL" on shared plans is normally per-hostname Let's Encrypt. Wildcard issuance needs **DNS-01**, which needs API access to the DNS zone. Without it, tenant hosts serve a certificate error — fatal, since `SESSION_SECURE_COOKIE=true` means auth cookies are not sent over a distrusted connection. | Ask: "Does the free SSL support wildcard certificates via DNS-01, and do I control the DNS zone or an API token for it?" |
| B3 | **Cron jobs** | **UNKNOWN** | 14 scheduled entries and all 16 queued job classes depend on it. Without cron: no leave accrual, no carry-forward, no invoicing, no overdue handling, no anomaly scan, no missing-punch scan, no scheduled reports, no digests, no approval reminders, no data cleanup, **and no queued email is ever sent**. | Plesk has a *Scheduled Tasks* panel. Ask for the **minimum interval** — some plans cap at 5, 15 or 30 minutes. 1-minute is wanted; 5 is tolerable. |
| B4 | **PHP version ≥ 8.2** | **VERIFIED — 8.3.33** (panel, 2026-09-17) | Laravel 12 hard-requires it. Below 8.2 the application does not boot. | Plesk *PHP Settings* lists selectable versions. |
| B5 | **Node.js runtime** (Plesk Node.js extension) | **UNKNOWN** | Decides Option B1 (keep SSR) vs Option B2 (static export). Not fatal either way — but it changes the amount of frontend work from ~zero to a scoped refactor. | Plesk *Node.js* panel. Ask for the available Node major version — Next 16 needs **Node 20.9+**. |

### 2.2 High-impact unknowns

| # | Capability | Status | Impact | How to verify |
| --- | --- | --- | --- | --- |
| H1 | DB user may **`CREATE TRIGGER`** | **UNKNOWN** | `2026_07_22_000001` creates two triggers. On managed MySQL with binary logging and no `SUPER`, this fails with **error 1419** and aborts the whole `migrate` run — a first-deploy stopper. | Run `SHOW GRANTS;` and `SELECT @@log_bin, @@log_bin_trust_function_creators;` in phpMyAdmin. |
| H2 | **`max_execution_time`** | **UNKNOWN** | Payroll currently gets 120 s at nginx and 1800 s on the Horizon `payroll` queue. A 30 s cap will time out payroll runs and large imports. | Plesk *PHP Settings*, or a `phpinfo()` page. |
| H3 | **`memory_limit`** | **UNKNOWN** | Horizon supervisors declare explicit per-process memory; payroll and export jobs are the heavy paths. 128 MB is likely too low; 256–512 MB wanted. | Same. |
| H4 | **CLI PHP available to cron** | **UNKNOWN** | Cron must invoke `php artisan`. Some plans expose only a URL-fetch scheduler, in which case queue and schedule must be driven by an authenticated HTTP endpoint instead. | Plesk *Scheduled Tasks* offers "Run a command" vs "Fetch a URL". Which are offered? |
| H5 | **`upload_max_filesize` / `post_max_size`** | **UNKNOWN** | nginx currently allows 50 MB. Employee document upload and CSV import depend on this. | Plesk *PHP Settings*. |
| H6 | **Outbound HTTPS from PHP** | **UNKNOWN** | Needed for external SMTP relay, Sentry, EthioTelecom SMS, webhook delivery (`DispatchWebhookJob`) and biometric device polling. Shared hosts often block non-standard outbound ports. | `file_get_contents('https://example.com')` from a test script; check ports 587/465 too. |
| H7 | **SSH access** | ~~UNKNOWN~~ **UNSUPPORTED — measured 2026-09-17: Hosting Settings reports `Forbidden`, and Dev Tools offers no Terminal.** | This row rated it *"friction, not impossibility"* and named two workarounds. **Neither is confirmed available.** Composer-locally-plus-upload survives (Plesk Git and Composer extensions are present, and Git's deployment path is relative to the webspace root, so `ethr` targets `~/ethr/` natively). But *"migrations run via a one-shot protected route or Plesk's PHP CLI panel"* — the protected route is **unbuilt**, and the PHP CLI panel is *Scheduled Tasks*, which is **unverified**. If it offers URL-fetch only there is no route at all, so on this account the row's optimism is **not yet earned**. Tracked as blockers **B-4** (nothing runs `artisan`) and **B-5** (no SQL import route). | ~~Plesk *Web Hosting Access*~~ — answered. The open question moved to *Scheduled Tasks*. |

### 2.3 Likely-supported (still to confirm)

| Capability | Status | Note |
| --- | --- | --- |
| MySQL / MariaDB database | **VERIFIED** (offered) | Version **UNKNOWN**. Need MySQL ≥ 5.7 / MariaDB ≥ 10.2 for `json()` columns. |
| `utf8mb4` charset | **UNKNOWN** | Mandatory for Amharic. Confirm the server default and that you can set it per-database. |
| InnoDB FULLTEXT index | **UNKNOWN** | Standard since MySQL 5.6 / MariaDB 10.0. Used by employee search. |
| Foreign keys | **UNKNOWN** | Requires InnoDB, which is the default. 32 migrations use them. |
| Apache `.htaccess` with `mod_rewrite` | **UNKNOWN** | Plesk normally serves Apache behind nginx and honours `.htaccess`. `api/public/.htaccess` already ships. |
| Custom document root | **UNKNOWN** | Needed to point the domain at `api/public`. Plesk supports it under *Hosting Settings*; some resellers lock it. If locked, the standard workaround is to put `public/`'s contents at the web root and move the app one level up. |
| Free SSL (per-hostname) | **VERIFIED** (advertised) | Wildcard is the open question — B2. |
| SMTP (plan mailboxes) | **VERIFIED** (email accounts included) | Deliverability and rate limits **UNKNOWN**. |
| PHP extension `gd` | **UNKNOWN** | Usually present. Its absence degrades image handling silently. |
| PHP extension `intl`, `phar`/`zip` | **UNKNOWN** | Commonly available; must be confirmed. `bcmath` was listed here and is **not required** — `suggest`-only, zero `bc*` calls. `zip` is needed only as the fallback half of `phar` **or** `zip`, for `BackupService` *(corrected 2026-09-18)*. |

### 2.4 UNSUPPORTED — assume absent on any shared plan

| Capability | Status | Consequence |
| --- | --- | --- |
| **Docker** | **UNSUPPORTED** | Entire `docker-compose*.yml` production path is unusable. Expected. |
| **Redis** | **UNSUPPORTED** (assume) | Queue, cache, session and broadcast must move to `database` / `log`. Zero application-code change (see audit §D). |
| **Long-running daemons** (`horizon`, `queue:work --daemon`, `schedule:work`, `reverb`) | **UNSUPPORTED** | Shared hosting kills background processes. Queue and schedule move to cron; Reverb cannot run at all. |
| **WebSocket listener** | **UNSUPPORTED** | No inbound port for Reverb, and no process to hold it. Realtime must go external or degrade. |
| **MinIO** | **UNSUPPORTED** | It is a server. Storage must be the local disk or an external S3-compatible service. |
| **Custom nginx config** | **UNSUPPORTED** | `infrastructure/nginx.conf` cannot be used. Plesk exposes limited "Additional nginx directives" on some plans; assume not. |
| **Read replica** | **UNSUPPORTED** | Drop `DB_READ_HOST`. Optimisation only. |
| **Root access** | **UNSUPPORTED** | Available on VPS tiers only. |

---

## 3. What is proven possible today

Three independent facts from the audit mean shared hosting is **not** ruled out:

1. **Redis is never touched directly.** Zero `Redis::` calls in `app/`. Every use is
   through Laravel's driver abstraction, and Laravel's own defaults in
   `config/queue.php`, `config/cache.php` and `config/session.php` are already
   `database`. Removing Redis is a `.env` edit.
2. **Realtime already degrades by design.** 12 notifications guard the broadcast leg on
   `config('broadcasting.default') === 'reverb'`; the sole frontend consumer catches
   failure; notifications already poll every 30 s. `BROADCAST_CONNECTION=log` is a
   supported configuration, not a hack.
3. **Tenancy is one database.** No per-tenant provisioning, no connection switching.
   The database-count limit on every plan tier is irrelevant.

## 4. What no configuration can fix

- **No cron ⇒ no scheduled work and no queued work.** This is the hard floor.
- **No wildcard subdomain ⇒ no self-service SaaS tenants.** Path-based tenancy is a
  product change, not a deployment change, and would require modifying `ResolveTenant`,
  the Next.js middleware, `SANCTUM_STATEFUL_DOMAINS`, the session-cookie isolation model
  (host-only cookies are currently what stops one tenant's session reaching another —
  see the `SESSION_DOMAIN` note in `.env.production.example`) and every absolute URL
  generated for a tenant. It is explicitly out of scope until B1 is answered.

---

## 5. Verification script for the account owner

Run these once an account exists. Record the answers back into this document, replacing
each **UNKNOWN**.

**In Plesk:**

1. *PHP Settings* → PHP version; `memory_limit`; `max_execution_time`;
   `upload_max_filesize`; `post_max_size`.
2. *PHP Settings* → the loaded-extensions list. Confirm: `pdo_mysql`, `mbstring`,
   `openssl`, `tokenizer`, `xml`, `dom`, `ctype`, `fileinfo`, `curl`, `iconv`,
   `simplexml`, `libxml`, `gd`, and one of `phar`/`zip`. `intl` is optional.
   *(Corrected 2026-09-18: `bcmath` removed — not required.)*
3. *Scheduled Tasks* → is it present? "Run a command" or only "Fetch a URL"?
   What is the minimum interval?
4. *Node.js* → is the extension present? Which Node major versions?
5. *Web Hosting Access* → SSH access: forbidden / chrooted / full?
6. *Hosting Settings* → is the document root editable?
7. *Add Subdomain* → is the literal name `*` accepted?
8. *SSL/TLS Certificates* → does Let's Encrypt offer "Issue a wildcard certificate"?

**Upload one file, `phpcheck.php`, then delete it immediately:**

```php
<?php
echo PHP_VERSION, "\n";
echo ini_get('memory_limit'), ' ', ini_get('max_execution_time'), ' ', ini_get('upload_max_filesize'), "\n";
print_r(array_values(array_intersect(get_loaded_extensions(),
    // The 18 mandatory, then the optional. bcmath removed 2026-09-18 (not required).
    ['pdo','pdo_mysql','mbstring','openssl','tokenizer','xml','dom','ctype','json',
     'fileinfo','filter','hash','session','curl','iconv','simplexml','libxml','gd',
     // optional; one of phar/zip is needed for backup archiving
     'phar','zip','intl','redis','sodium','opcache','exif','xmlwriter'])));
var_dump(function_exists('proc_open'), function_exists('exec'));
var_dump((bool) @file_get_contents('https://api.github.com', false,
    stream_context_create(['http' => ['timeout' => 5]])));
```

**In phpMyAdmin:**

```sql
SELECT VERSION(), @@character_set_server, @@collation_server;
SELECT @@log_bin, @@log_bin_trust_function_creators;
SHOW GRANTS;
CREATE TABLE _t (id INT PRIMARY KEY);
CREATE TRIGGER _t_guard BEFORE UPDATE ON _t FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'x';
DROP TABLE _t;
```

If that `CREATE TRIGGER` fails, note the exact error number — H1 is confirmed as a
blocker and the migration plan's mitigation applies.
