# ETHR Shared-Hosting Migration — State

Working file for the migration. Updated at the end of each phase.

---

## CURRENT PHASE

**PAUSED at hosting verification, 2026-08-29, at the owner's request.**
Migration code changes remain **FROZEN**. Nothing is half-applied; the pause is safe.

### To resume, you need exactly four facts

Everything else is decided (see DECISIONS TAKEN ON DELEGATION below). Nothing further
can be settled from this repository — all four require the Plesk account.

| # | Fact | How |
| --- | --- | --- |
| 1 | PHP version + extensions + GD (**B4**) | Upload `scripts/hosting-verification/ethr-hosting-check.php` to `~/` — **home, not `httpdocs`**, so it is never web-accessible — then `php ~/ethr-hosting-check.php` |
| 2 | `CREATE TRIGGER` privilege (**H1**) | Same run, adding `--db-host=localhost --db-name=… --db-user=… --db-pass=…` |
| 3 | Node.js present + version (**B5**) | `node -v` over SSH, or Plesk → *Node.js* |
| 4 | Cron (**B3**) | Plesk → *Scheduled Tasks*: present? "Run a command" or URL-fetch only? Minimum interval? |

Fact 4 is the only one that is panel-only. Facts 1–3 come from a single SSH session.

**Decision rule on resumption:** B4 fail → blocked, no fallback. H1 fail → request the
`TRIGGER` grant from Ethio Telecom before touching code. B5 present (Node ≥ 20.9) → keep
the Next.js standalone build unchanged; B5 absent → static export. B3 fail → scheduler
and queue must move behind an authenticated HTTP endpoint (a real design change, to be
costed, not assumed).

### Working tree at the time of pause

Uncommitted. HEAD is `07ff61e`. 6 modified files, 1 new test, 10 new docs, 1 new script.
The full inventory is under FILES CHANGED below, and complete reversal is one command.
Backend suite was green at **1652 passed / 4904 assertions / 0 failures**.

```
OWNER DECISION (2026-08-29):  NO VPS. Options A, C and D withdrawn.
                              Target = existing Ethio Telecom Linux Bronze,
                              upgrading tier if required.

PHASE A:      IMPLEMENTED BUT NOT YET APPROVED FOR PRODUCTION
              (working tree only — not committed)

TARGET:  etrhet @ line6.ethiotelecom.et / 213.55.96.154 (Linux Bronze, Plesk)

B1a wildcard DNS          VERIFIED PASS   (6 unconfigured names all resolve)
B1b wildcard vhost        VERIFIED PASS   (owner: `*` accepted; not yet created)
B2  wildcard TLS          PARTIAL         (LE already working per-hostname on this
                                           account; wildcard needs DNS-01, zone is
                                           not in Plesk. HTTP-01 fallback PROVEN)
B3  cron                  UNKNOWN         (Plesk panel question)
B4  PHP >= 8.2 + GD       UNKNOWN         (probe script answers this)
B5  Node.js runtime       UNKNOWN         (Plesk panel question)
H1  CREATE TRIGGER        UNKNOWN         (probe script answers this)

AUDIT LOG:    RESOLVED — fail-fast restored + diagnosis. Tests pass.

DECISION:     GO on Option B (Ethio Telecom Bronze), staying on Bronze
              for now. Taken 2026-08-29 on owner's delegation.

NEXT ACTION:  Three lookups only — B3 (cron), B5 (Node.js), and the probe
              script for B4 + H1. Everything else is decided.
```

## DECISIONS TAKEN ON DELEGATION (2026-08-29)

Owner said "decide for me". These are settled; they are not open questions.

| # | Decision | Basis |
| --- | --- | --- |
| **1** | **Architecture: Option B — everything on Ethio Telecom shared hosting.** | VPS withdrawn by owner. B1 fully verified PASS, which was the only gate without a workaround. Nothing found so far blocks it. |
| **2** | **Stay on Bronze. Do not upgrade yet.** | Wildcard makes the 5-subdomain cap irrelevant — `*` is one entry. One database is sufficient (single-DB tenancy, verified). Bronze's real limits are 5 GB storage / 50 GB bandwidth, and those should be sized against real data, not guessed. Upgrading is reversible and instant; over-buying now is just waste. |
| **3** | **Audit log: fail-fast, with a diagnosis.** Implemented. | The trigger is the only real enforcement (model guards are instance-methods only). If H1 fails, ask Ethio Telecom to grant `TRIGGER` — a support request, not a code change. |
| **4** | **TLS: pursue wildcard, ship on per-tenant HTTP-01.** | A working LE cert already exists on this account, which *proves* HTTP-01. Wildcard needs the zone in Plesk or a DNS API token — worth requesting from Ethio Telecom, but it is an optimisation, not a blocker. |
| **5** | **Canonical host is `www.ethr.et`.** | Plesk already 301s apex → www; `www` is already reserved in `Tenant::RESERVED_SUBDOMAINS`. Set `APP_URL` and `CORS_ALLOWED_ORIGINS` accordingly. |
| **6** | **Drivers: `database` for queue/cache/session, `log` for broadcast, `local` for files.** | Zero `Redis::` calls; broadcast already guarded by config check; `temporaryUrl` works on the `local` disk via `serve => true`. |
| **7** | **`DB_HOST=localhost`.** | Port 3306 is refused from the internet — correct, and it settles the question. |
| **8** | **Next.js: decided by B5, no default assumed.** Node ≥ 20.9 → keep the standalone build unchanged. No Node → static export per the completed route audit. | Not a judgement call; it is a branch on one fact. |
| **9** | **Approve the six driver-agnostic Phase A changes.** | Behavioural no-ops on the current config; 1652/1652 tests pass. |
| **10** | **Reject Option C (hybrid) permanently.** | Moot — owner excluded VPS entirely. |

### Externally verified 2026-08-29 (no credentials used)

**The domain zone**

- **Wildcard DNS works.** `zzq7x`, `tenant1`, `habru-test-9f3`, `a1b2c3d4e5`, `admin`,
  `api` — all `.ethr.et`, none configured — resolve.
- Zone is on `ns2.telecom.net.et` (Ethio Telecom), **not** Plesk — blocks *automatic*
  wildcard TLS. No CAA record, so issuance itself is unrestricted.
- `ethr.et` **currently points at `91.99.81.71` = Hetzner, Falkenstein DE**, where ports
  80/443/8080/22/21 are all closed. **Nothing is serving the domain today**, so there is
  no live-traffic cutover risk. DNS must be repointed to `213.55.96.154`.

**The Ethio Telecom host `213.55.96.154`**

- Ports: 80, 443, **22 (SSH)**, 8443 (Plesk) OPEN. 21 closed. **3306 refused** — MySQL is
  not internet-exposed, so `DB_HOST=localhost`.
- Stack: nginx + `X-Powered-By: PleskLin`.
- **The `ethr.et` vhost already exists**: `www.ethr.et` → 200 (Plesk placeholder),
  `ethr.et` → 301 → `www`. `zzq7x.ethr.et` → the *server default* page, confirming the
  wildcard vhost is not yet created.
- **A valid Let's Encrypt certificate is already installed** — `CN=ethr.et`,
  SAN `ethr.et, www.ethr.et`, valid 2026-08-07 → 2026-11-05. Per-hostname, not wildcard.
  This *proves* the HTTP-01 per-tenant fallback works on this account.
- **Apex → www redirect is compatible.** `www` is already in
  `Tenant::RESERVED_SUBDOMAINS` and `resolveFromSubdomain()` returns `null` for reserved
  names, so `www.ethr.et` behaves as the apex. Requires only that
  `CORS_ALLOWED_ORIGINS`/`APP_URL` name `www.ethr.et` as canonical.

---

## COMPLETED

- **Phase 0** — Read-only discovery. No file modified.
- **Phase 1** — Architecture audit → `docs/SHARED_HOSTING_AUDIT.md`
- **Phase 2** — Capability matrix → `docs/ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`
- **Phase 3** — Options and recommendation → `docs/SHARED_HOSTING_MIGRATION_PLAN.md`
- **Phase A** — Config changes implemented and tested. **Not committed.**
- **Gate review** — →
  `docs/PHASE_A_CHANGE_REVIEW.md`,
  `docs/AUDIT_LOG_INTEGRITY_DECISION.md`,
  `docs/HOSTING_VERIFICATION_CHECKLIST.md`,
  `docs/B1-B5_GATE_REPORT.md`,
  `docs/TCO_COMPARISON.md`
- **Verification tooling** — `scripts/hosting-verification/ethr-hosting-check.php`,
  self-tested against the dev stack.

---

## IN PROGRESS

Nothing. **Implementation is frozen** pending gate resolution.

---

## BLOCKED

Everything downstream of the gates. The blocking dependency is **access to an Ethio
Telecom Plesk account**, not further analysis.

| ID | Question | Effect if negative |
| --- | --- | --- |
| B1 | Wildcard subdomain `*.ethr.et` on one vhost? | **No-Go** → Option A/D |
| B2 | Wildcard TLS certificate? | **No-Go** → Option A/D |
| B3 | Cron, minimum interval, command-type tasks? | **No-Go** → Option A/D |
| B4 | PHP ≥ 8.2 with GD and all mandatory extensions? | **No-Go** → Option A/D |
| B5 | Node.js ≥ 20.9? | Not fatal → selects Option B1 vs B2 |
| H1 | DB user may `CREATE TRIGGER`? | Security gate — see decision doc |
| $$ | Shared-plan price **and billing period**; current VPS cost | May invalidate the migration's entire rationale |

---

## DECISIONS

| # | Decision | Rationale |
| --- | --- | --- |
| D1 | Single-database tenancy confirmed | No `CREATE DATABASE`, no connection switching; `BelongsToTenant` global scope, 37 migrations |
| D2 | Redis removable with **zero** application-code change | No `Redis::` call anywhere in `app/` |
| D3 | Reverb removable without silently disabling anything | 12 notifications guard on `config('broadcasting.default')`; sole frontend consumer catches failure; notifications already poll at 30 s |
| D4 | Horizon is a Redis-queue runner, not a feature | 15 ordinary queued job classes; nothing calls `Horizon::` |
| D5 | Hardcoded infra surface is **5** sites in 3 files | Was reported as 4; `checkReadReplica()`'s hardcoded `mariadb` found during implementation |
| D6 | Static export viable but **not free** — Option B2 only if B5 fails | Route audit complete: 0 API routes, 0 server actions, but `middleware.ts` + `(auth)/layout.tsx` both break, and marketing SSR is lost |
| D7 | **Reject Option C (hybrid)** | Once a VPS carries Redis/workers/WebSockets/storage, it costs the most and is the least reliable |
| **D8** | **REVERSED, then RESOLVED: audit-log guard is fail-fast again** | The model-level guards are instance methods only; every mass-operation path bypasses them, and `AuditLogImmutabilityTest` already asserts DB-level enforcement. Now aborts with a diagnosis rather than a bare SQLSTATE |
| **D9** | **Option D (Ethio Telecom VPS) added to the comparison** | Satisfies "host with Ethio Telecom" at 100% functionality, **zero** code change and **zero** unverified gates. Was under-weighted originally |
| **D10** | No cost recommendation is possible yet | Public pricing is self-contradictory on billing period; current VPS cost unknown |

---

## RISKS

| # | Risk | Severity | Status |
| --- | --- | --- | --- |
| R1 | No wildcard subdomain → self-service signup creates unreachable tenants | **Critical** | Unverified. Compounded: lower tiers cap subdomains at 5/10 |
| R2 | No wildcard TLS → `SESSION_SECURE_COOKIE=true` withholds the auth cookie | **Critical** | Unverified |
| R3 | No cron → 11 scheduled entries and all queued work stop | **Critical** | Unverified |
| R4 | `CREATE TRIGGER` denied → audit log not immutable | **Critical** | Unverified. Phase A's mitigation is itself now assessed as a downgrade |
| R5 | `max_execution_time` too low for payroll and imports | High | Unverified |
| R11 | **Shared hosting may cost more than the VPS** | **High** | Public data contradicts itself; unresolved |
| R12 | **Subdomain caps (5/10) on lower tiers** are structurally incompatible with SaaS tenancy | **High** | From 2020 directory data; needs confirmation |
| R6 | Document root not editable → cannot point at `api/public` | Medium | Unverified |
| R7 | `ext-gd` absent → image compression and thumbnails degrade **silently** | Medium | Unverified; probe covers it |
| R8 | Outbound HTTPS blocked → SMTP, webhooks, SMS, Sentry, device polling fail | Medium | Unverified; probe covers it |
| R9 | Storage quota exhausted by photos, documents and tenant backups | Medium | Depends on tier |
| R10 | Existing deploy/backup tooling all assumes Docker + SSH | Medium | Would need rebuilding under Option B |

---

## FILES CHANGED

**Application code (working tree, NOT committed):**

```
 api/app/Console/Commands/HealthCheckCommand.php            |  6 +-
 api/app/Http/Controllers/Api/V1/HealthController.php       | 26 ++++--
 api/app/Services/FileStorageService.php                    | 20 ++++-
 api/database/migrations/2026_07_22_000001_…                | 77 ++++++++++++--   <- REVERSAL RECOMMENDED
 api/phpunit.xml                                            | 14 ++++
 api/tests/bootstrap.php                                    |  1 +
 api/tests/Feature/InfrastructureAgnosticTest.php           | new
```

**Documentation and tooling added:** the six `docs/*.md` files listed under COMPLETED,
plus `scripts/hosting-verification/ethr-hosting-check.php`.

Complete reversal: `git checkout -- api/ && rm api/tests/Feature/InfrastructureAgnosticTest.php`

---

## TEST RESULTS

### Backend — baseline, before any Phase A edit

```
Tests:    1647 passed (4896 assertions)
Duration: 325.31s      Exit: 0
```

### Backend — after Phase A

```
Tests:    1652 passed (4904 assertions)      (= baseline + 5 new tests, +8 assertions)
Duration: 274.89s      Exit: 0
Collection check: 139/139 test classes
```

**Zero regressions.** Targeted re-run of the two most relevant files:

```
PASS  Tests\Feature\InfrastructureAgnosticTest      5 passed
PASS  Tests\Feature\AuditLogImmutabilityTest        4 passed
```

`AuditLogImmutabilityTest` passing is significant for decision D8: it confirms the
trigger path still works wherever the privilege exists (SQLite here; MariaDB dev has
`log_bin=0`).

Both runs used `scripts/pest-isolated.sh`. Running `pest` directly in the container
collects a fraction of the suite over the Windows bind mount and still exits 0.

### Static analysis

`php -l` and Laravel Pint: **pass** on all 7 changed files.
**PHPStan (level 6) not yet run** — required before any commit.

### Not run

Frontend suites and production build — Phase A touches no frontend file.

### Probe self-test

`scripts/hosting-verification/ethr-hosting-check.php` run against the dev stack:
correctly reported PHP 8.2.33, all 18 mandatory extensions present, MariaDB
10.11.18, `CREATE TRIGGER` **permitted** (`log_bin=0`), and per-table `GRANT`
**denied** (`1142`) — the last confirming that compensating control A1 is unavailable
even to our own dev database user.

---

## NEXT ACTION

VPS options are withdrawn, so the cost comparison in `docs/TCO_COMPARISON.md` is now
informational only — tier selection, not architecture selection.

**From the Plesk panel (all quick):**

1. **The Bronze account's IP address** — *Hosting Settings*. Needed to repoint DNS and to
   let external verification continue.
2. **B1b** — *Subdomains → Add Subdomain*: is the literal name `*` accepted? This is the
   single most important remaining question; everything about tenant routing depends on
   it.
3. **B3** — *Scheduled Tasks*: present? "Run a command" offered, or URL-fetch only?
   Minimum interval?
4. **B5** — *Node.js*: extension present? Which Node major version (need ≥ 20.9)?
5. **Bronze's actual quotas** — storage, bandwidth, databases, subdomains.

**By uploading one file:**

6. Run `scripts/hosting-verification/ethr-hosting-check.php`, save the output, **delete
   it from the server**. Answers B4 (PHP + extensions + limits) and H1 (`CREATE
   TRIGGER`) together.

**Decision still outstanding:**

7. The audit-log question — recommended: revert the Phase A `up()` guard to fatal
   (`docs/AUDIT_LOG_INTEGRITY_DECISION.md`). Note this now interacts with H1: with no
   VPS fallback, a hard failure here means the audit log's database-level immutability
   cannot be guaranteed on this platform, and that becomes an accepted-risk decision
   rather than a choose-another-platform one.
