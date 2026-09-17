# Ethio Telecom Hosting — Account-Level Verification Checklist

**Date:** 2026-08-29
**Purpose:** replace inference with observation. Every item resolves to
**VERIFIED / NOT VERIFIED / UNSUPPORTED / UNKNOWN**.

---

> **Partially superseded, 2026-09-17.** Account access now exists and some rows below are
> answered. The authoritative gate register is
> [`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md) — it carries the measured
> values, the blocker register (B-1…B-6) and the manual action queue.
>
> **The two documents use different gate names for the same account**, which is a drift
> hazard worth naming: this file numbers by area (`P*` PHP, `W*` web server, `C*` cron,
> `N*` Node, `D*` DNS, `S*` SSL, database, storage) and by the old `B1`–`B5`/`H1` gate IDs;
> `GATE-0-RESULT.md` numbers `G0-A`–`G0-J`. Roughly: `P*` → G0-E, `C*`/B3 → G0-D,
> `N*`/B5 → G0-G, `W*` → G0-A and G0-B, `D*`/B1 and `S*`/B2 → G0-C, database/H1 → G0-F and
> G0-I, storage → the storage rows, and **G0-J (CPU and database benchmarks) has no
> counterpart here at all.** Work from `GATE-0-RESULT.md`; use this file for the
> finer-grained per-extension rows it carries.

## ACCOUNT ACCESS: NOT AVAILABLE

**I have no access to an Ethio Telecom Plesk account, so I cannot run any of these tests
myself.** No test domain was created, no probe was uploaded, no SQL was executed against
their MySQL.

Everything below is therefore currently **NOT VERIFIED**. Nothing in this document is a
result; it is the procedure that produces the results.

Two of Ethio Telecom's own web properties (`www.ethiotelecom.et`,
`myportal.ethiotelecom.et`) failed TLS certificate verification when fetched during the
Phase 2 audit, so even their published plan specifications could not be read first-hand
and come from search-result summaries and a third-party hosting directory.

**Nothing in this document is fabricated, and no item is marked VERIFIED.**

### What I need from you — the short list

Either:

**(a) Access** — Plesk panel login for an active Linux/MySQL account (a trial or the
cheapest tier is sufficient for every test here except storage-quota). Then I can run
the whole checklist directly.

**or (b) Answers** — these eleven, from Ethio Telecom support or the panel:

1. PHP versions selectable, and the **full loaded-extension list** for the highest one.
2. `memory_limit`, `max_execution_time`, `upload_max_filesize`, `post_max_size` — and
   whether **you** can change them.
3. Is there a **Scheduled Tasks / cron** feature? Can it "run a command" (not only
   "fetch a URL")? What is the **minimum interval**?
4. Is **SSH** available, and is `php` + `composer` on the CLI path?
5. Is the **Node.js** Plesk extension available? Which Node major versions?
6. Can a subdomain be created with the literal name **`*`** (wildcard), served by one
   document root?
7. Does the free SSL issue **wildcard** certificates (requires DNS-01)? Do you control
   the DNS zone or an API token for `ethr.et`?
8. Can the **document root** be changed (to point at `api/public`)?
9. **MySQL/MariaDB version**, and does the DB user have the **`TRIGGER`** privilege? Is
   `log_bin` on?
10. Is **outbound HTTPS** from PHP permitted (ports 443, 587, 465)?
11. Storage quota and whether files can be stored **outside** the document root.

Items 3, 6, 7, 9 are the ones that can end the migration. Answer those first.

---

## How to run the automated part

`scripts/hosting-verification/ethr-hosting-check.php` (added with this document) answers
most of the PHP, filesystem and database items in one page load. It is **read-only
except for one temp file it deletes**, writes nothing outside its own directory, and
prints a classified report.

```
1. Upload it to the account's web root.
2. Visit it in a browser (or run `php ethr-hosting-check.php` over SSH).
3. Save the output.
4. DELETE IT IMMEDIATELY — it discloses environment detail.
```

It cannot answer cron, Node.js, wildcard DNS, wildcard TLS or document-root
configuration. Those are panel questions and are listed separately below.

---

## 1. Plesk / PHP

| # | Item | Method | Status |
| --- | --- | --- | --- |
| P1 | PHP version ≥ 8.2 | Probe: `PHP_VERSION`. Panel: *PHP Settings* | **VERIFIED — 8.3.33** (panel, 2026-09-17) |
| P2 | PHP handler (FPM / CGI / module) | Panel: *PHP Settings → Run PHP as* | NOT VERIFIED |
| P3 | PHP-FPM availability | Panel: same dropdown | NOT VERIFIED |
| P4 | Composer available on CLI | ~~SSH: `composer --version`~~ — **this method is dead: SSH is Forbidden** (2026-09-17). Panel: *Dev Tools → PHP Composer* | **PARTIAL** — present as a Plesk extension; CLI availability unverifiable without a shell |
| P5 | `gd` — **mandatory** | Probe | NOT VERIFIED |
| P6 | `pdo`, `pdo_mysql` — **mandatory** | Probe | NOT VERIFIED |
| P7 | `mbstring` — **mandatory** | Probe | NOT VERIFIED |
| P8 | `openssl` — **mandatory** | Probe | NOT VERIFIED |
| P9 | `fileinfo` — **mandatory** | Probe | NOT VERIFIED |
| P10 | `intl` | Probe | NOT VERIFIED |
| P11 | `zip` — needed by dompdf | Probe | NOT VERIFIED |
| P12 | `curl` — **mandatory** | Probe | NOT VERIFIED |
| P13 | `xml`, `dom`, `simplexml` — **mandatory** | Probe | NOT VERIFIED — *this row was right and the probe was not; see the note below* |
| P14 | `bcmath` — payroll arithmetic | Probe | NOT VERIFIED |
| P15 | `tokenizer` — **mandatory** | Probe | NOT VERIFIED |
| P16 | `ctype` — **mandatory** | Probe | NOT VERIFIED |
| P17 | `iconv` — **mandatory** | Probe | NOT VERIFIED |
| P18 | `sodium` — Laravel encryption paths | Probe | NOT VERIFIED |

> **P13 was right and the probe was wrong — worth recording.** This row has listed
> `simplexml` as **mandatory** since this checklist was written. The probe script
> (`scripts/hosting-verification/ethr-hosting-check.php`), which is the artifact that
> actually *enforces* the list, carried it as **optional** until 2026-09-17. It is a hard
> requirement of `aws/aws-sdk-php` via `league/flysystem-aws-s3-v3`, and
> `composer install --no-dev` aborts without it — so a host could have passed the probe
> and then failed to install. The knowledge was in the repository; it was in the document
> nobody executes rather than the script everybody runs. Fixed in the probe; noted here
> because that asymmetry is the general hazard, not this one extension.

> `gd` is the one whose absence is silent rather than fatal: `FileStorageService`
> returns original bytes and skips thumbnails when it is missing. Uploads keep working
> while the size budget stops being enforced.

## 2. Cron — **GATE B3**

| # | Item | Method | Status |
| --- | --- | --- | --- |
| C1 | Scheduled Tasks feature present | Panel: *Scheduled Tasks* | NOT VERIFIED |
| C2 | Can run a **command** (not only fetch a URL) | Panel: task-type options | NOT VERIFIED |
| C3 | **Minimum interval** (1 min wanted, 5 tolerable) | Panel | NOT VERIFIED |
| C4 | PHP CLI binary path | SSH `which php`, or panel task output | NOT VERIFIED |
| C5 | `php artisan schedule:run` executes | Run it once the app is uploaded | NOT VERIFIED |
| C6 | `php artisan queue:work --stop-when-empty` executes | Same | NOT VERIFIED |

> If C2 is "fetch a URL only", the scheduler and queue must be driven through an
> authenticated HTTP endpoint instead. That is a real design change and must be costed,
> not assumed.

## 3. Node.js — **GATE B5**

| # | Item | Method | Status |
| --- | --- | --- | --- |
| N1 | Plesk Node.js extension present | Panel: *Node.js* | NOT VERIFIED |
| N2 | Node version (**≥ 20.9** for Next 16) | Panel dropdown / `node -v` | NOT VERIFIED |
| N3 | npm available | `npm -v` | NOT VERIFIED |
| N4 | `npm run build` completes within account limits | Try it; note peak RAM | NOT VERIFIED |
| N5 | Next.js production runtime stays resident | Deploy the standalone build | NOT VERIFIED |

> N4 is a real risk independent of N1–N3: a Next 16 production build is memory-hungry
> and shared hosts cap per-process RAM. If the panel supports Node but the build cannot
> run on the host, building locally and uploading `.next/standalone` is the workaround.

## 4. Apache / Plesk web server

| # | Item | Method | Status |
| --- | --- | --- | --- |
| W1 | `.htaccess` honoured | Probe writes and requests a test rule | NOT VERIFIED |
| W2 | `mod_rewrite` active | Probe | NOT VERIFIED |
| W3 | **Document root configurable** (→ `api/public`) | Panel: *Hosting Settings* | **PARTIAL** — it *displays* as `/`, which is relative to the webspace root and resolves to `httpdocs` (confirmed 2026-09-17 via `.well-known/acme-challenge/` inside it). Whether the field is **editable** is still unverified |
| W4 | PHP routes through `index.php` front controller | Follows W1/W2 | NOT VERIFIED |
| W5 | `.env` can be denied | `.htaccess` rule, then request `/.env` | NOT VERIFIED |
| W6 | Private files storable **outside** the docroot | Probe: write one level above | **VERIFIED PASS** — `B1-B5_GATE_REPORT.md:89` recorded this on 2026-08-29 and this row simply never caught up; re-confirmed 2026-09-17 |
| W7 | Custom response headers (CSP, HSTS) settable | `.htaccess` `Header set` | NOT VERIFIED |

> If W3 is locked, the standard workaround is `public/`'s contents at the web root with
> the application one level above — which then depends entirely on W6.

## 5. DNS — **GATE B1**

| # | Item | Method | Status |
| --- | --- | --- | --- |
| D1 | Who controls the `ethr.et` zone | Registrar / Plesk *DNS Settings* | NOT VERIFIED |
| D2 | Wildcard DNS record `*.ethr.et` can be created | Panel: *DNS Settings → Add A record, host `*`* | NOT VERIFIED |
| D3 | Wildcard **subdomain** `*` accepted, one docroot | Panel: *Subdomains → Add*, name `*` | NOT VERIFIED |
| D4 | `tenant1/2/3.ethr.et` all reach the app **without per-tenant setup** | Curl three arbitrary names | NOT VERIFIED |

> D2 and D3 are different things. A wildcard DNS record makes the name resolve; a
> wildcard vhost makes the web server answer for it. Both are required. "Unlimited
> subdomains" on the plan sheet means neither.

## 6. SSL — **GATE B2**

| # | Item | Method | Status |
| --- | --- | --- | --- |
| S1 | Let's Encrypt available | Panel: *SSL/TLS Certificates* | NOT VERIFIED |
| S2 | **Wildcard** certificate option offered | Same panel | NOT VERIFIED |
| S3 | DNS-01 validation possible (wildcard requires it) | Needs zone control — see D1 | NOT VERIFIED |
| S4 | Automatic renewal | Panel | NOT VERIFIED |
| S5 | `https://<random>.ethr.et` presents a valid cert | Browser / `openssl s_client` | NOT VERIFIED |

> S5 is the one that matters. `SESSION_SECURE_COOKIE=true` means a browser withholds the
> auth cookie over a connection it does not trust — a certificate warning is not a
> cosmetic problem, it is "no tenant can log in".

## 7. Database

| # | Item | Method | Status |
| --- | --- | --- | --- |
| DB1 | MySQL/MariaDB version (need MySQL ≥ 5.7 / MariaDB ≥ 10.2 for JSON) | `SELECT VERSION()` | NOT VERIFIED |
| DB2 | Database size limit | Panel / plan sheet | NOT VERIFIED |
| DB3 | User privileges | `SHOW GRANTS` | NOT VERIFIED |
| DB4 | **`TRIGGER` privilege — gate H1** | `CREATE TRIGGER` on a temp table | NOT VERIFIED |
| DB5 | `log_bin` / `log_bin_trust_function_creators` | `SELECT @@log_bin, @@log_bin_trust_function_creators` | NOT VERIFIED |
| DB6 | **Per-table GRANT possible** (compensating control A1) | `GRANT SELECT, INSERT ON db.audit_log TO …` | NOT VERIFIED |
| DB7 | Foreign keys / InnoDB | Create a temp FK | NOT VERIFIED |
| DB8 | JSON columns | `CREATE TABLE _t (d JSON)` | NOT VERIFIED |
| DB9 | FULLTEXT index | `ALTER TABLE … ADD FULLTEXT` | NOT VERIFIED |
| DB10 | `utf8mb4` — **required for Amharic** | `SELECT @@character_set_server` | NOT VERIFIED |
| DB11 | Stored procedures | `CREATE PROCEDURE` — *not used by ETHR; informational* | NOT VERIFIED |
| DB12 | Event scheduler | `SELECT @@event_scheduler` — *not used by ETHR; a fallback if cron fails* | NOT VERIFIED |
| DB13 | max_connections | `SELECT @@max_connections` | NOT VERIFIED |

**Run this block in phpMyAdmin and record the exact errors:**

```sql
SELECT VERSION(), @@character_set_server, @@collation_server, @@max_connections;
SELECT @@log_bin, @@log_bin_trust_function_creators, @@event_scheduler;
SHOW GRANTS;

CREATE TABLE _ethr_probe (id INT PRIMARY KEY, d JSON, t TEXT);
ALTER TABLE _ethr_probe ADD FULLTEXT KEY _ft (t);        -- DB9
CREATE TRIGGER _ethr_probe_guard BEFORE UPDATE ON _ethr_probe
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'x';   -- DB4 / gate H1
DROP TABLE _ethr_probe;
```

If the `CREATE TRIGGER` fails, **record the error number** — 1419 and 1142 mean
different things and lead to different mitigations. See
`docs/AUDIT_LOG_INTEGRITY_DECISION.md`.

## 8. Storage

| # | Item | Method | Status |
| --- | --- | --- | --- |
| ST1 | Storage quota | Panel | NOT VERIFIED |
| ST2 | `upload_max_filesize` / `post_max_size` | Probe | NOT VERIFIED |
| ST3 | Max request size (web-server level) | Upload a large test file | NOT VERIFIED |
| ST4 | Filesystem writable by PHP (`storage/`, `bootstrap/cache/`) | Probe | NOT VERIFIED |
| ST5 | **Symlink creation** (`php artisan storage:link`) | Probe | NOT VERIFIED |
| ST6 | Private files storable outside docroot | Probe | NOT VERIFIED |

> ST5 matters only if the `public` disk is used. The audit found `FileStorageService`
> uses presigned/temporary URLs rather than the symlinked public disk, so this is a
> nice-to-have, not a gate.

---

## Classification key

| Value | Meaning |
| --- | --- |
| **VERIFIED** | Observed on the actual account. Record how and when. |
| **NOT VERIFIED** | Test defined but not yet run. **Current state of every row above.** |
| **UNSUPPORTED** | Tested and confirmed absent. |
| **UNKNOWN** | No test possible / no answer obtainable. |

**NOT VERIFIED is not a soft yes.** No item here may be treated as supported until it
carries VERIFIED and evidence.
