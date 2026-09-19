# ETHR Shared-Hosting Migration — State

Working file for the migration. Updated at the end of each phase.

---

## CURRENT PHASE

**Hosting migration: PAUSED at hosting verification, since 2026-08-29, at the owner's
request.** Unchanged as of this update (2026-08-31) — no new hosting-side facts have
come in. Migration *code* changes (the deployment-target-specific ones — driver swaps,
`.htaccess`, static export) remain **FROZEN**. Nothing is half-applied; the pause is
safe.

**A second, unrelated thread of work happened in parallel and is fully merged to
`main`** — see "PARALLEL WORK MERGED TO MAIN" below. None of it depended on the hosting
gates; all of it is correct regardless of where ETHR ends up deployed.

### KNOWN BLOCKERS — recorded 2026-09-18, none resolvable from this repository

An autonomous pass on 2026-09-18 was asked to carry the migration to completion,
verify shared hosting, and remove the VPS assets. It could do none of those three,
and the reasons are the same two facts each time. Recording them here so the next
pass does not re-derive them:

| # | blocker | why it cannot be cleared from here | what it blocks |
|---|---|---|---|
| **KB-1** | **Gate 0 has never been run against the account.** `deployment/GATE-0-RESULT.md`: *"Status: NOT RUN"*, *"Run by: owner (requires the Plesk account — this cannot be automated from the repository)"* | The four facts need the Plesk panel or an SSH session to `213.55.96.154`. No code path reaches them | Hosting verification; every downstream deployment decision |
| **KB-2** | **The VPS/Docker production assets cannot be removed while KB-1 stands.** Root `CLAUDE.md` keeps them *"only until that cutover is verified"* | Deleting the working deployment path before the replacement is measured leaves the product with neither | VPS decommissioning |
| **KB-3** | **Manual action 0a — *"is anything still serving on the VPS?"* — cannot be probed from an agent or CI sandbox.** Added 2026-09-18 after an attempt returned a false negative | This environment's egress gateway routes by Host header and SNI and **discards the destination IP**, so an IP-addressed probe never reaches the host it names. Proven by dialling the VPS IP with `Host: example.com` and receiving example.com from Cloudflare | **B-6**, the top-ranked safety finding. Needs a human on an ordinary network — one `curl`, no Plesk, no shell |

**KB-3, added 2026-09-18 — manual action 0a cannot be run from any agent environment, and
an attempt produced a confident false negative.** 0a had been deferred by every prior pass
on the grounds that no environment could reach the host. That was re-tested, appeared to
succeed, and the result — *"the VPS serves nothing"* — was written into this file and into
`ROLLBACK_RUNBOOK.md` before it was checked properly. **It was false.** This environment's
egress gateway routes by Host header and SNI and discards the destination IP, so the probe
never addressed the VPS; the "controls" chosen to validate it shared the same flaw and
confirmed nothing. Both documents are corrected, and **B-6 below** carries the proof, the
tell that should have been caught first (two "different" hosts returning byte-identical
`etag`s), and why the controls failed. The reusable part: *a control only controls for what
it varies*. What survives is the set of findings measured by **name** rather than by IP —
the apex serving a `404` and the wildcard serving `200` — because a name is what a browser
resolves too.

The lesson still generalises, just not the way it was first written: *"this environment
cannot reach it"* deserved re-testing, and re-testing it was right. What was wrong was
believing the retest on controls that could not have detected the failure.

**What was done instead of stopping**: `deployment/VPS_DECOMMISSION.md` now carries the
full inventory — what gets deleted, what only looks like it should (`docker-compose.yml`
is the *only* supported local dev path and is not a VPS asset), which successor replaces
each item, and the order. When KB-1 clears, the removal is a procedure to follow rather
than an investigation to repeat.

It also corrected one claim that would have made the removal look unsafe: §14 of
`audit/BASELINE.md` said there was no non-Docker backup or restore path. There is —
`ethr:backup` and `ethr:restore` are pure-PHP Artisan commands written for Plesk
Scheduled Tasks. The `.sh` files are wrappers, not the capability.

### To resume the hosting migration, you need exactly four facts

Everything else is decided (see DECISIONS TAKEN ON DELEGATION below). Nothing further
can be settled from this repository — all four require the Plesk account.

| # | Fact | How |
| --- | --- | --- |
| 1 | PHP version + extensions + GD (**B4**) | Upload `scripts/hosting-verification/ethr-hosting-check.php` to `~/` — **home, not `httpdocs`**, so it is never web-accessible — then `php ~/ethr-hosting-check.php` |
| 2 | `CREATE TRIGGER` privilege (**H1**) | Same run, adding `--db-host=localhost --db-name=… --db-user=… --db-pass=…` |
| 3 | Node.js present + version (**B5**) | `node -v` over SSH, or Plesk → *Node.js* |
| 4 | Cron (**B3**) | Plesk → *Scheduled Tasks*: present? "Run a command" or URL-fetch only? Minimum interval? |

Fact 4 is the only one that is panel-only. Facts 1–3 come from a single SSH session.
The account IP (`213.55.96.154`) and username (`ethret`) are already known — see
`docs/B1-B5_GATE_REPORT.md`.

**Decision rule on resumption:** B4 fail → blocked, no fallback. H1 fail → request the
`TRIGGER` grant from Ethio Telecom before touching code (the migration now aborts
loudly rather than degrading silently either way — see D8). B5 present (Node ≥ 20.9) →
keep the Next.js standalone build unchanged; B5 absent → static export. B3 fail →
scheduler and queue must move behind an authenticated HTTP endpoint (a real design
change, to be costed, not assumed).

### ~~`main` is at `2eb2e42`, 13 commits ahead of `origin/main`, **not pushed**~~

> **Resolved 2026-09-15. The push blocker no longer exists.**
>
> `git push -u origin docs/phase-0-baseline` succeeded from a non-interactive
> session, exit 0, publishing 32 commits — the 19 that had accumulated on `main`
> plus 13 from Phases 0–2. Credentials resolve now; whatever made Git Credential
> Manager prompt in August does not any more.
>
> This entry is struck through rather than deleted because it was shaping
> decisions for two and a half weeks, including the sequencing of this session's
> work. A blocker recorded as permanent and never re-tested is its own kind of
> drift — worth re-checking a "confirmed blocked" claim before planning around it.
>
> `origin/main` is deliberately untouched: `docs/CLAUDE.md` says never commit
> directly to `main`, so the work sits on a branch awaiting a `--no-ff` merge.

```
2eb2e42  Merge: remove dead trial-expiry middleware, correct PHASE_01
2826b0d  Merge: plan-tier feature gating
12f53de  Merge: shared-hosting audit, config hardening, and Proclamation 1395/2025
07ff61e  (origin/main)
```

Three `--no-ff` merges, each with its own single revert point.

### Full gate suite: ALL 9 PASS, current as of the last merge (2026-08-31)

`./scripts/gates.sh`, after applying the 5 pending dev-DB migrations Larastan needs
to read the schema:

```
✓ Pint (format)        ✓ i18n (keys + en/am)     ✓ TypeScript (tsc)
✓ PHPStan (level 6)    ✓ Prettier (format)       ✓ Vitest (435 tests / 70 files)
✓ Pest (backend)       ✓ ESLint (frontend)       ✓ API types (contract)
```

PHPStan level 6 was the outstanding gap flagged in the Phase A review; it is now
closed. Backend suite green at **1652 passed / 4904 assertions**, collection check
139/139 classes.

### Ethiopian income tax — corrected 2026-08-29

`TaxCalculator`, `TaxBracketSeeder` and any seeded database carried the
**Proclamation No. 979/2016** ladder. That was superseded by **No. 1395/2025 on
7 July 2025** — thirteen months earlier — so every payslip since had over-deducted
employment income tax, worst at the bottom of the scale (a 2,000 ETB earner was charged
157.50 ETB/month, ~8% of gross, on income that is now exempt).

New schedule, monthly ETB: `0–2,000` 0% · `2,001–4,000` 15% · `4,001–7,000` 20% ·
`7,001–10,000` 25% · `10,001–14,000` 30% · `>14,000` 35%. Pension unchanged at 7%/11%
and still does not reduce taxable income.

The quick-form deductions (300 / 500 / 850 / 1,350 / 2,050) were derived from the
statutory cumulative form and cross-checked against an independent source; the method
was validated by re-deriving 979/2016's published deductions.

**A latent bug was fixed at the same time, because the amendment activates it.**
`effectiveBrackets()` filtered on `now()` rather than the payroll period. Harmless while
one ladder existed; with two, voiding and reprocessing a June-2025 period would retax it
at July-2025 rates and produce a different payslip from the one actually paid.
`calculate()` now takes an as-of date and `PayrollEngine` passes `$periodStart`. Old
bands are closed with `effective_to`, never deleted.

**Still outstanding, and both need a human:**

1. **Independent confirmation before the first live payroll.** Sourced from law-firm and
   payroll-provider summaries corroborating each other, not the Negarit Gazeta text.
2. **Tenants with their own bracket overrides were deliberately not migrated** and remain
   on 979/2016. The migration counts them and warns on STDERR. Update via
   `PUT /api/v1/payroll/tax-brackets`. If payroll already ran on the old ladder,
   over-deducted tax must be reconciled with the affected employees.

**Green gates are not production readiness.** They prove no regression, not that the
product is ready — see `docs/DEPLOYMENT.md`'s pre-deployment checklist for the gaps
that no gate can catch (ERCA tax-bracket confirmation, live SMS handshake, real SMTP
credentials, plan-tier feature gating, Google Workspace SSO, biometric hardware).

```
OWNER DECISION (2026-08-29):  NO VPS. Options A, C and D withdrawn.
                              Target = existing Ethio Telecom Linux Bronze,
                              upgrading tier if required.

PHASE A:      IMPLEMENTED BUT NOT YET APPROVED FOR PRODUCTION
              (working tree only — not committed)

TARGET:  ethret @ lin6.ethiotelecom.et / 213.55.96.154 (Linux Bronze, Plesk)

B1a wildcard DNS          VERIFIED PASS   (6 unconfigured names all resolve)
B1b wildcard vhost        PARTIAL         (owner reports `*` accepted — testimony,
                                           not recorded output; vhost NOT created.
                                           deployment/GATE-0-RESULT.md governs; see
                                           its G0-C section)
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

> **Legacy IDs — read this before the letter-number labels above.** That block is the
> 2026-08-29 status, kept as history. It uses the **retired** `B3/B4/B5/H1` scheme, and
> three of those labels now **collide** with live blockers of the same name that mean
> something else entirely. `B5` above is *Node.js*; **B-5** below is *no database import
> route*. `B3` above is *cron*; **B-3** below is *an unidentified Plesk vhost skeleton*.
> Per `HOSTING_VERIFICATION_CHECKLIST.md:17`, the retired labels map to gates, not to the
> blocker register:
>
> | Retired | Means | Now |
> |---|---|---|
> | `B3` | cron / Scheduled Tasks | **G0-D** |
> | `B4` | PHP version, extensions, GD | **G0-E** |
> | `B5` | Node.js availability | **G0-G** |
> | `H1` | `CREATE TRIGGER` privilege | **G0-F** |
>
> The live blockers are **B-1 … B-6**, always written with the hyphen, in the register
> further down. When a document says "the four remaining facts", it is speaking the 2026-08-29
> language and predates every blocker found since.

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

**Hosting migration:**

- **Phase 0** — Read-only discovery. No file modified.
- **Phase 1** — Architecture audit → `docs/SHARED_HOSTING_AUDIT.md`
- **Phase 2** — Capability matrix → `docs/ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`
- **Phase 3** — Options and recommendation → `docs/SHARED_HOSTING_MIGRATION_PLAN.md`
- **Phase A** — Config changes: implemented, tested, **committed and merged** (`12f53de`).
- **Gate review** — →
  `docs/PHASE_A_CHANGE_REVIEW.md`,
  `docs/AUDIT_LOG_INTEGRITY_DECISION.md`,
  `docs/HOSTING_VERIFICATION_CHECKLIST.md`,
  `docs/B1-B5_GATE_REPORT.md`,
  `docs/TCO_COMPARISON.md`
- **Verification tooling** — `scripts/hosting-verification/ethr-hosting-check.php`,
  self-tested against the dev stack.
- **External DNS/TLS/host probing** — done without needing account access; see the
  "Externally verified" block above.

**Parallel work (unrelated to hosting, all merged):** see the new section below.

---

## IN PROGRESS

### Reconciled 2026-09-17 — the deployment package had a missing step, now closed

`DEPLOYMENT.md` §0 drew the document root; **no step in the runbook assembled it**. Every
rule G0-B measures is inert until `~/httpdocs/` exists, so the gate rested on a step that
did not. Written as **step 4a**, plus an enforceable control and one new canary check.

| | |
| --- | --- |
| **Status** | **Merged 2026-09-18 as `e7e0199`.** PR #19, branch `claude/ethr-migration-continue-0xhhnt`. |
| **Gates moved** | **None.** G0-A and every G0-B row remain `NOT VERIFIED` and require the Plesk account. `G0-B.5` is new and equally unverified. |
| **Freeze** | One narrow exception taken and recorded at `deployment/GATE-0-RESULT.md` → *After the run* item 5. The freeze otherwise stands. |
| **Detail** | `MIGRATION_CHANGELOG.md` → 2026-09-17. Four defects, what each would have done, and what was checked and found *not* to be a defect. |
| **Enforcement** | `api/tests/Feature/DocumentRootInventoryTest.php` pins `api/public/` against a manifest, so a file cannot arrive in the document-root decision set unnoticed. It found one of the four defects on its first run. |

**PR #17 (tenant public pages) untouched.** Open, unmerged, separate workstream, and it
changes no file this run touched — its routing table lives in a new
`docs/TENANT_PUBLIC_PAGES.md`. Its five paths are **tenant-host** rules routing to
Laravel; two share a name with rows in step 4a's **platform-host** table, so that table
now names its vhost on its face. When PR #17 merges, step 4a needs a pointer to it, not a
copy of its rows — one table authoritative per vhost.

---

**The rest of the deployment package is built and merged** —
`docs/deployment/shared-hosting/` (runbook, env reference, `.htaccess`, checklists) plus
`docs/DATABASE_MIGRATION_PLAN.md`, `docs/ROLLBACK_RUNBOOK.md`,
`docs/PRODUCTION_CHECKLIST.md`, `docs/MIGRATION_CHANGELOG.md`. Built ahead of the
remaining B3/B5 answers deliberately, as explicit branches rather than waiting — see
each file's own "branch on B3/B5" sections for exactly what changes once those answers
land. Hosting migration *execution* (upload, migrate, cutover) has not started and is
correctly blocked on the same four facts it always was. See NEXT ACTION. *(Written
2026-08-31. Those "four facts" are the retired `B3/B4/B5/H1` — now `G0-D`, `G0-E`, `G0-G`,
`G0-F` — and they are no longer all that blocks execution: blockers **B-1 … B-6** were
found on 2026-09-17/18 and are the current list. See the legacy-ID table above.)*

---

## PARALLEL WORK MERGED TO MAIN (2026-08-30 – 2026-08-31)

Independent of the hosting migration — none of it required a hosting-gate answer, and
all of it stands regardless of where ETHR ends up deployed. Three `--no-ff` merges.

### 1. Payroll compliance fix — `12f53de` (part of the Phase A merge)

The platform income-tax ladder was Proclamation 979/2016, **superseded 7 July 2025**.
Every payslip since then over-deducted tax, worst at the bottom (a 2,000 ETB earner lost
~8% of gross on income now exempt). Corrected to the six-band 1395/2025 schedule,
effective-dated so historical periods still reproduce what was actually withheld.
Migration `2026_08_29_000001_apply_income_tax_proclamation_1395_2025` applied and
**verified against real MariaDB** (both ladders present, old one closed at
`2025-07-06`, new one open from `2025-07-07`) — not just the SQLite test suite.

Two defects in my own first draft, both caught before merge: `forgetCachedBrackets()`
unset a cache key that no longer existed after the key format changed to
`{tenantId}@{date}`; and a test asserted strict monotonicity across a continuous band
boundary, which is wrong by construction.

**Needs a human:** get the schedule confirmed by a tax adviser or ERCA before the first
live payroll run — it was sourced from corroborating legal/payroll-provider summaries,
not the Negarit Gazeta text. Tenants with their own bracket override are **not**
migrated (deliberately — overriding the ladder is a per-tenant choice) and remain on
979/2016; the migration counts and warns about them on STDERR. If payroll already ran
on the old ladder, the over-deduction needs reconciling with the affected employees.

### 2. Plan-tier feature gating — `2826b0d`

`Plan.features` existed on every plan and was enforced nowhere — tier pricing bought
nothing. Now enforced by `PlanFeatureService` + `RequiresPlanFeature` middleware on 5
write-side routes (payroll process/approve/void/reprocess, reports generate/export,
saved/scheduled report definitions, webhook create/update, API-key issuance) plus the
one deliberate read-side exception (`GET /audit-logs`, since there the read *is* the
capability). Fail-open on every ambiguity (trial tenants, no subscription, NULL feature
list) for the same reason `PlanLimitService` already does — a billing gate wrong in the
closed direction locks out paying customers.

**Live-verified against the real stack**, not just Pest: found that `php artisan
serve`'s child process silently drops every Docker-injected env var (a real, if
narrow, local-dev gotcha — worked around by running PHP's built-in server directly).
With that fixed, ran the full gate matrix on the demo tenant with a real Sanctum login,
flipping its subscription between Starter and Enterprise:

| Route | Starter | Enterprise |
| --- | --- | --- |
| `POST /payroll/process` | 403 (gate) | 422 (validation — gate passed) |
| `GET /attendance` (Starter feature) | 200, real data | — |
| `POST /reports/generate` | 403 | 422 |
| `POST /reports/save` | 403 | 422 |
| `POST /api-keys` | 403 | 422 |
| `POST /webhooks` | 403 | 422 |
| `GET /audit-logs` | 403, gate's own message | 200, real data |

One false alarm caught and correctly diagnosed rather than reported as a bug:
`audit-logs` returned 403 on *both* plans on the first pass — traced to a pre-existing,
unrelated `Gate::authorize('settings.manage')` permission check in the controller that
`hr_admin` doesn't hold; re-ran with `tenant_admin` to isolate the actual gate. Also
found and fixed: `Plan` had no `@property` block, so PHPStan (correctly) reported the
gate would never consult the feature list at all — fixed at the root (added the
annotation, matching `TaxBracket`/`AuditLog`'s convention) rather than suppressed,
which also narrowed the generated frontend contract from `unknown[]` to `string[]`.
All test/demo state fully reverted after verification (subscriptions deleted); the ~61
audit-log rows from test logins were **not** deletable — the append-only trigger
refused the DELETE live, which is correct behaviour and further confirms that control
works on this database.

### 3. Dead code removed — `2eb2e42`

Investigating whether the new plan gate handled suspended/cancelled tenants (it
doesn't check subscription status, matching `PlanLimitService`'s existing pattern)
surfaced that access control for that case already exists elsewhere —
`ResolveTenant::isActive()` blocks suspended/cancelled/trial-expired tenants with 403
on every request, before any gate runs. What was real: `EnforceTrialExpiration`, a
middleware meant to give trial expiry a dedicated 402 + upgrade CTA, was registered
nowhere (confirmed by repo-wide grep — only its own file and a `PHASE_01.md` checkbox
referenced it). Removed; the doc corrected to describe what actually enforces trial
expiry. The dedicated CTA UX was **not built** — that's a billing/conversion product
decision, not a bug fix, same category as Google Workspace SSO below.

Followed by a bounded audit for siblings: every one of the 15 middleware classes and
15 job classes is referenced somewhere. This was the only dead artifact of its kind.

### 4. Documentation corrected against the code (throughout)

Four stale entries, drifting in both directions — understating shipped work
(`DEPLOYMENT.md`'s and `ENTERPRISE_ROADMAP.md`'s cost-sharing rows both said "missing";
it's a 346-line page with real API hooks, backed by 38 backend test cases) and
overstating currency (the tax-bracket row asked the reader to "confirm 979/2016 is
still operative" — it hadn't been for 13 months). Also removed the contradictory
`CLAUDE.md` section that banned all Git usage next to a commit-message convention that
required it.

### Deliberately not built — flagged, not invented

- **Google Workspace SSO.** `SsoProviderInterface` is SAML-shaped; Google needs
  OAuth2/OIDC — a new contract, not an adapter. Building it speculatively risks the
  wrong abstraction.
- **Trial-expiry 402 + upgrade CTA.** Real enforcement exists (generic 403); the
  dedicated UX is a billing/conversion decision — what does "upgrade" link to, is
  there self-serve checkout — not something to invent without the owner.

---

## "The VPS is stored on Git" — what that does and does not cover (2026-09-17)

Reported by the owner, and corroborated: the GitHub repository's own description reads
*"Cloned from VPS."* Checked what is actually in Git, because the distinction decides
whether B-5 collapses or B-6 gets worse.

**Git holds the code and the directory skeleton. It holds no data and no keys.**

| | In Git? | Evidence |
| --- | --- | --- |
| Application code, config, migrations | **Yes** | 1,599 tracked files |
| Laravel runtime directory tree | **Yes** | 13 tracked `.gitignore` placeholders under `api/storage` and `api/bootstrap` |
| Database dump, SQLite file | **No** | No `.sql`/`.sqlite`/`.dump` tracked anywhere; `api/database/database.sqlite` gitignored |
| Uploaded files — employee documents, photos | **No** | `storage/app/private` holds only its placeholder |
| **`.env`, and therefore `APP_KEY`** | **No** | `api/.env` gitignored, correctly |

### The consequence, and it is a data one rather than a rollback one

This **strengthens** the code half of rollback: redeploying from Git does not need the VPS
to be serving, so B-6's "the rollback target has no open ports" matters less than it
first appeared — *for code*.

It does not touch the data half, and there the picture is worse than B-6 described:

- `deployment/BACKUP-RESTORE.md:119` — `--off-host` is **deliberately absent** from the
  scheduled backup line, and the command *"warns on every run that the backup exists only
  on the host it protects."*
- `:185` — *"Configure the off-host disk and confirm `--off-host` lands an archive"* is an
  **unchecked box**.
- `Employee.php:93-94` — `tin` and `national_id` use the `encrypted` cast. **A database
  backup without its `APP_KEY` cannot decrypt them.**

So if the VPS ever held real tenant data, then its database backup lives **only on the
VPS**, and the `APP_KEY` needed to read the encrypted columns lives **only in the VPS's
`.env`** — neither in Git, neither off-host. Decommissioning or losing that machine loses
both, and the encrypted PII becomes unrecoverable even if a dump later turns up.

### This sharpens queue item 0b rather than answering it

"Stored on Git" answers *"is the code safe?"* — yes. It does not answer *"does the VPS
hold a database?"*, which is what 0b asks and what decides whether B-5 collapses into B-4.

**If the answer to 0b is yes, then before the VPS is touched again:** take a dump and
preserve its `APP_KEY` off the machine. That is cheap now and impossible afterwards.
**If no**, this section is moot, B-5 collapses, and the migration is a fresh deployment —
which is the hypothesis the repository's own evidence favours (`ethr.et` never served live
traffic; the VPS is recorded as dormant).

---

## B-6 — the DNS cutover already happened, and the rollback target may not serve

**Reviewed 2026-09-17. This is the most serious finding of that review, and it is not a
gate — it is a safety property that has quietly stopped holding.**

### The runbook's stated current state is false

`docs/ROLLBACK_RUNBOOK.md:8`, Scenario A — *"before DNS cutover (**the current state, as of
this writing**)"*:

> *"Nothing to roll back. `ethr.et` still points at the VPS (dormant — no live traffic)."*

**It does not.** Measured twice on 2026-09-17 from this workstation:

```
213.55.96.154   ethr.et
213.55.96.154   www.ethr.et
213.55.96.154   zzq7x.ethr.et      <- wildcard, never configured
```

`213.55.96.154` is the **Plesk host**. The DNS half of the cutover has already been
performed — and nothing in this repository records who did it, when, or under what plan.
`MIGRATION_STATE.md:177` still says the opposite (`91.99.81.71`, Hetzner), because that
observation is dated 2026-08-29.

**Scenario A therefore does not apply.** We are not "before DNS cutover". We are after it,
with an unverified deployment target and no record of the transition.

### Scenario B tells you to roll back to a host with no open ports

Scenario B's instruction is *"Repoint the A/AAAA records … back to the VPS IP."* The
repository's own measurement of that IP, `MIGRATION_STATE.md:177`:

> *"`91.99.81.71` = Hetzner, Falkenstein DE, where ports **80/443/8080/22/21 are all
> closed**. **Nothing is serving the domain today**."*

If that is still true, **executing Scenario B produces a total outage, not a
restoration.** A rollback plan whose target serves nothing is worse than an admitted
absence of one, because it will be trusted in the exact moment there is no time to check
it.

**Stated precisely, because the two halves have different evidence:** the DNS change is
**measured by me today**. The port state is the **repository's own 2026-08-29
measurement**, which I could not re-verify — this session's proxy refuses outbound to both
hosts. The VPS may well have been brought back up. That is exactly why it needs checking
rather than assuming, in either direction.

### Why this outranks the Gate 0 work

Every Gate 0 blocker is a question about whether the migration *can* proceed. This is a
question about whether it can be *undone*. `deploy-checklist.md` is run "before step 8
(DNS cutover)" — and step 8 appears to have already happened, out of order, with the
pre-cutover checklist unrun.

### Actions — neither needs the Plesk panel

1. **Is the VPS actually serving?** `curl -sI http://91.99.81.71/` and check ports 80/443.
   Serving → Scenario B is viable and only the runbook's Scenario A text is wrong. Not
   serving → **there is no rollback target**, and that must be fixed or accepted
   explicitly before anything is deployed to Plesk.
2. **Who repointed DNS, and when?** It changes the risk model: if `ethr.et` now resolves to
   a Plesk host serving a placeholder, the domain is publicly live against an unverified
   deployment.

~~Held, not fixed: `ROLLBACK_RUNBOOK.md` is not frozen and Scenario A's premise is provably
wrong, so it *could* be corrected now. It is not, because the correct replacement text
depends on answer 1 — "roll back to a working VPS" and "there is no rollback target" are
different documents, and writing one before knowing which would mean writing it twice.~~

**FIXED 2026-09-18, and the hold was reasoning from the wrong premise.** What depends on
0a is *which rollback procedure is viable*. What does **not** depend on it is that
Scenario A's stated premise is false **today** — `ethr.et` resolves to `213.55.96.154`,
measured twice, whatever the VPS is doing. So there was a correction available that does
not need writing twice, and the hold was costing more than it saved:

- The document instructs the reader to *"read down to the one that matches reality"*, and
  Scenario A **announced itself as reality**. Following the instruction as written stops
  at A and concludes there is nothing to roll back.
- The frozen `shared-hosting/rollback.md` delegates to this file as "the real plan". That
  one cannot be edited under the freeze — so this file is the only place the chain can be
  corrected at all, which makes it the highest-leverage safety fix available.

Corrected without pre-supposing 0a's answer: a banner stating the measured DNS fact and
that the current state is Scenario B or C; Scenario A struck through but retained as
history and as the still-correct pre-cutover procedure; Scenario B's repoint instruction
gated on 0a, saying plainly that repointing at a host which serves nothing converts one
outage into two and that TTL then caches the bad answer; and Scenario B's
`SELECT MAX(updated_at)` check flagged as needing a SQL route that B-4 has not
established.

The *decision* between "roll back to a working VPS" and "there is no rollback target"
still belongs to 0a and to the owner. What the runbook no longer does is assert one of
them.

### 0a ATTEMPTED 2026-09-18 — still unanswered, and now known to be unanswerable from any agent environment

**An earlier version of this section claimed 0a was answered and that the VPS serves
nothing. That was wrong, and it was wrong in the dangerous direction — it would have
justified abandoning the rollback target. It is corrected here rather than deleted,
because the way it went wrong is the reusable part.**

The probes and what they appeared to show:

| Probe | Appeared to show |
|---|---|
| `curl http://91.99.81.71/` | `503`, Envoy's `upstream connect error … remote connection failure` |
| `curl https://91.99.81.71/` | `Connection reset by peer` |
| `curl http://1.1.1.1/`, `http://213.55.96.154/` — "controls" | `301`, `200` — taken as proof the vantage point reached arbitrary IPs |

That looked like a clean negative with working controls. **It was not a measurement of the
VPS at all.** This environment's egress gateway routes by **Host header and SNI, and
ignores the destination IP**. Two checks establish it beyond argument:

1. **The TLS certificate presented for *both* IPs** is `subject=CN = *.ethr.et`,
   `issuer=O = Anthropic, CN = Egress Gateway SDS Issuing CA (production)`, issued minutes
   before the request. Not the real site's certificate — the gateway's.
2. **Dialling `91.99.81.71:80` with `Host: example.com` returns example.com**, `server:
   cloudflare`, `cf-ray` and all. The IP is discarded.

Which also explains the tell that should have been caught first: `91.99.81.71` and
`213.55.96.154` returned **byte-identical** 404s — same `content-length: 808`, same
`etag: "328-657701a10331b"`, same `last-modified`. Two independent hosts do not agree to
that precision. They were the same backend, reached by name, twice.

**So the "controls" were the error.** They were chosen to prove the *path* worked, and they
did — by the same name-based routing that made the target unreachable. A control only
controls for what it varies, and these varied nothing that mattered. The genuine control
would have been the Host-header test above, which was run only after a contradiction
forced it.

**0a therefore stands unanswered, exactly as before**, and the earlier passes' *"this
environment cannot reach either host"* was substantively right. What is added is *why*, so
the next pass does not spend the attempt again: **no agent environment behind this gateway
can probe an IP as an IP.** 0a needs a human on an ordinary network, and the `curl -sI
http://91.99.81.71/` in the queue below has to be run by one.

**Nothing about the VPS is established here — not that it serves, not that it does not.**
The repository's 2026-08-29 measurement remains the only evidence, uncorroborated.

### What *is* established: the name-based findings, which do not depend on the IP

These were measured by **name**, which is what a browser resolves too, so the gateway's
routing does not invalidate them:

| Host | Serves |
|---|---|
| `http://ethr.et/` → `https://www.ethr.et/` | **`404 Not Found`** — Plesk's error page (`/error_docs/styles.css`) |
| `https://ethr.et/`, `https://www.ethr.et/` | same `404` |
| `http://zzq7x.ethr.et/` — a never-configured wildcard name | **`200`**, Plesk's *"Web Server's Default Page"* |
| `getent hosts ethr.et` | `213.55.96.154`, agreeing with the 2026-09-17 reading |

The domain is publicly live against an unverified deployment, and the apex does not serve
a placeholder — it serves a **server error**. Anyone visiting `ethr.et` today gets
"Server Error / 404 Not Found".

**The wildcard result is the one specific to this product, and it is recorded nowhere
else.** ETHR is multi-tenant on subdomains. Every tenant subdomain currently resolves and
answers **`200 OK`** with Plesk's default page — so any smoke test, uptime monitor or
cutover check that asserts *"the tenant subdomain returns 200"* passes today against
nothing at all. A check for that has to assert on content, not on status.

**Still unanswered, and not derivable from here:** who repointed DNS, and when. That needs
the registrar or DNS provider's audit log, not a probe.

---

## B-5 REVISED 2026-09-18 — the remedy already exists, and the plan names the wrong tool

`deployment/BACKUP-RESTORE.md` was reviewed and it changes B-5 materially, in the
favourable direction. **`ethr:restore` was built for exactly this constraint.**

That file, line 34, on `ethr:backup` and `ethr:restore`: *"Both run as plain PHP CLI,
which is **the entire design constraint**: they must work from **Plesk → Scheduled Tasks**
with no shell and no `mysqldump`."*

Verified in the codebase, not taken from the prose: `BackupCommand.php`,
`RestoreCommand.php`, `BackupRehearsalCommand.php` (`ethr:backup`, `ethr:restore`,
`ethr:backup:rehearse`), plus `tests/Feature/BackupRestoreRehearsalTest.php`, which the
doc describes as performing a real populate → back up → **drop every table** → restore →
assert schema, rows, triggers and documents came back.

**So B-5 is not "no import route exists".** It is "`DATABASE_MIGRATION_PLAN.md` names
`mysql -h localhost … < dump.sql`, which needs a shell, when a shell-free path is already
built." B-5 therefore **collapses into B-4**: both need one thing, a way to run a PHP CLI
command, which is manual action 1.

#### Correction 2026-09-18 — "and tested" was too strong, and the gap is on the production driver

This section first read *"already built **and tested**."* Checked rather than left
standing, and the word does not survive on the driver that matters.

`BackupRestoreRehearsalTest` — the destructive round-trip the claim rests on — **skips
itself on MySQL.** Its own guard, and the reasoning is sound:

```php
if (DB::connection()->getDriverName() !== 'sqlite') {
    test()->markTestSkipped(
        'Destructive restore rehearsal is SQLite-only; use `artisan ethr:backup:rehearse` on MySQL.'
    );
}
```

The stated reason is correct — these tests DROP EVERY TABLE, which `RefreshDatabase` undoes
on SQLite but not on MySQL, where DDL implicitly commits and the database stays destroyed
for every test after it. So the skip is right. The problem is what replaces it.

**`ethr:backup:rehearse`, the named MySQL equivalent, is invoked nowhere.** Not by
`scripts/gates.sh`, not by either CI workflow, not by any test — grepped 2026-09-18, the
only hits are its own definition and the two lines above pointing at it. So:

| Driver | Job | Destructive backup→restore rehearsal |
|---|---|---|
| SQLite | `Backend` | runs |
| MariaDB | `Backend suite on MySQL` | **skipped, and nothing runs in its place** |

Two consequences worth stating separately:

1. **The backup path has never been exercised on the production driver.** Production is
   MariaDB. `DatabaseDumper::quoteFlat()` carries its own note about SQLite and MySQL
   quoting newlines differently — a divergence that already bit this project once — so
   "passes on SQLite" is precisely the evidence that divergence defeats.
2. **The SQLite rehearsal cannot cover B-6's hazard even in principle.** It asserts
   triggers come back by reading `sqlite_master WHERE type='trigger'`. The B-6 mechanism
   is ``CREATE DEFINER=`root`@`localhost` ``, and SQLite triggers have no `DEFINER`
   concept at all. The one assertion that looks like it covers the risk is on the one
   driver where the risk cannot exist.

**Not fixed here, deliberately.** The fix is a CI change, and this environment cannot
validate one: `composer install` cannot authenticate to github.com here, so there is no
`api/vendor`, so no `artisan` command can be run at all. Pushing an unvalidated workflow
edit is the specific thing `CLAUDE.md`'s CI section warns about — five structural defects,
every one invisible from the working tree.

The proposed patch, for whoever has a working backend:

- The command refuses unless the database name contains one of `test`, `rehears`,
  `scratch`, `staging`, `sandbox` (`BackupRehearsalCommand::DISPOSABLE`). CI's database is
  `ethr_suite_mysql`, which matches none — so this needs a **second** database, e.g.
  `ethr_rehearsal`, not `--force` on the suite's own.
- Creating it needs the service's root credentials (`MARIADB_ROOT_PASSWORD: root` is
  already set in `gates.yml`) and a grant to `ethr`; then `migrate` against it and run
  `php artisan ethr:backup:rehearse` with `DB_DATABASE` overridden for that step only.
- Verify the runner actually has a `mysql` client before relying on one.

Until that runs, the honest statement is: **built, tested on SQLite, unexercised on
MariaDB** — and item 3 of `BACKUP-RESTORE.md`'s checklist, running the rehearsal on the
host itself, is still the thing that converts a backup procedure into a verified backup.

### And Plesk's own backup is not the recovery path

`BACKUP-RESTORE.md:73`, which matters for B-6 as much as B-5: **"a Plesk-generated
database backup of ETHR may be unrestorable on ETHR's own host."**

The mechanism is the audit-log triggers. Restoring a dump that names a definer **other
than the account doing the restore** stops dead at the trigger statement with
`1227 Access denied … SUPER`, measured against MariaDB 10.4.32 on 2026-09-15. That is what
`mysqldump` and the Plesk panel export write into a dump file. `DatabaseDumper` emits no
`DEFINER` clause precisely to avoid this, which is why `ethr:backup` exists as a path that
does not have the property.

**Correction 2026-09-18 — this section said the definer is `root@localhost`, universally.**
It quoted `BACKUP-RESTORE.md`'s reading as "confirmed, not assumed". The 1227 mechanism is
confirmed; the *specific definer* is not general, and the difference decides which
operation the hazard bites. The migration emits `CREATE TRIGGER` with **no `DEFINER`
clause** (lines 53 and 95), so the engine assigns whoever ran `migrate` — `DB_USERNAME`,
which is `ethr` under Docker and a Plesk-issued per-database user on the target
(`ENVIRONMENT.md:98`), never `root`. So:

- **Importing the VPS dump — B-5, the migration step itself — bites for certain.** That
  dump carries the VPS's definer, which by construction is not the Plesk user restoring
  it. Use `ethr:restore`, or strip the `DEFINER` clauses.
- **A steady-state Plesk backup of the shared host is conditional.** Its triggers will
  name the Plesk database user, and that same user restores them, which is permitted. It
  fails only where the accounts differ — including differing **only in the host part**
  (`ethr@localhost` vs `ethr@%`), which gives the identical 1227.

The instruction does not change: verify a restore before trusting the panel's backup. What
changes is that the certainty belongs to the import step, and the steady-state case is
worth testing rather than written off. Scope note added at the source in
`deployment/BACKUP-RESTORE.md`.

**Do not treat the panel's backup as the recovery path** until someone has restored one on
the host and watched both triggers come back.

### What this does to the priority

It concentrates everything on the same question. Manual action 1 — *can this account run a
PHP CLI command on a schedule?* — now decides **deployment** (B-4), **database import**
(B-5), **backup and restore** (this section), and **observability**
(`health-check.md`'s SQL checks). One panel page, four blockers.

---

## B-5 — the database has no import route either, and one question may delete it

`docs/DATABASE_MIGRATION_PLAN.md` was reviewed on 2026-09-17 and had not been checked
against SSH being Forbidden. **Both of its paths are blocked, by the same missing
capability class as B-4 but by different mechanisms:**

| Path | The blocking step | Why it is blocked |
| --- | --- | --- |
| **Existing-data migration** | `mysql -h localhost -u… "$DB" < ethr_pre_migration_*.sql` (§ *Schema transfer*) | Runs the import **on the shared host**. Needs a shell there. The `mysqldump` half is fine — that runs on the VPS, which has SSH |
| **Fresh deployment** | `php artisan migrate` + `ProductionSeeder` | This is **B-4**. Same blocker, already recorded |

So there is currently no route to get a schema into the shared-hosting database at all,
by either path. Recorded as **B-5**, distinct from B-4 because the remedy differs: B-4
needs something that runs `artisan`; B-5 needs either that *or* a database import UI.

### The question that might delete B-5 entirely

**Which path applies has never been decided**, and the repository's own evidence points
hard at one of them. `MIGRATION_STATE.md` records `ethr.et` as having had **no live
traffic** — *"nothing is serving the domain today, so there is no live-traffic cutover
risk… the VPS is dormant, not serving."* The plan's own opening says: *"This assumes there
is existing data to migrate — if the shared-hosting deployment is instead a fresh start
with `ProductionSeeder` and no prior tenants, skip straight to 'Fresh deployment'."*

If the VPS holds no real tenant data, then:

- the `mysqldump` → `mysql <` half of the plan **does not apply at all**, and B-5 collapses
  into B-4;
- the storage-transfer section likewise;
- and the whole database migration becomes `artisan migrate` + `ProductionSeeder`, which
  is one blocker rather than two.

**This is answerable without Plesk.** It is a question about the VPS, not the shared host,
and it is the only outstanding item on the critical path that does not require the panel —
which is why it is queued ahead of the panel work below.

Held, not fixed: `DATABASE_MIGRATION_PLAN.md` is **not** in the frozen directory, so it
could be rewritten now. It is not being rewritten, for the same reason as U-2 — if the
path turns out to be "fresh deployment", half the document becomes irrelevant rather than
wrong, and rewriting it before that answer means writing it twice.

---

## UNFIXED — held deliberately, 2026-09-17

Known defects and unresolved states that are **not** being repaired right now, each with
why. Distinct from the blocked gates: those are *unmeasured*. These are *known wrong or
known unresolved and consciously left*. Listed so none of them becomes a surprise.

| # | Unfixed | Why it is held | What changes it |
| --- | --- | --- | --- |
| ~~**U-1**~~ | ~~**None of this session's corrections are on `main`.**~~ **RESOLVED 2026-09-18.** Both PRs merged in the order this row prescribed: **#19 → `e7e0199`**, **#20 → `7fb91cc`**. `main` now carries step 4a, the probe list including `simplexml` — **20 extensions as merged; 18 since 2026-09-18**, when `bcmath` was measured as never required and `zip` restated as `phar` OR `zip` — the five-check canary, the manual action queue, and the blocker register. The defective procedure is no longer what an operator gets from `main`. | — | Done |
| **U-2** | **`DEPLOYMENT.md` steps 3 and 4 describe a procedure nobody can perform here** — `rsync` over SSH, `artisan` over SSH — on an account where SSH is Forbidden | Freeze discipline. The exception test is *branch-independent **and** blocks Gate 0*; this is branch-independent but does not block Gate 0. Rewriting before the SSH question resolves means writing it twice | SSH granted → steps stand as written. SSH refused → rewrite around Git + Composer + a task runner |
| **U-3** | **B-4 — nothing can run `artisan`.** `key:generate`, `migrate`, `db:seed`, `ethr:create-admin` have no runner. The Git route delivers code and Composer delivers `vendor/`; neither executes anything | External capability, not a repository defect | **Manual queue #1 is closed** — answered 2026-09-18, there is no Scheduled Tasks section, so a command-type task is not on the table *(this row said it was until 2026-09-19)*. What is left: **queue #2 (SSH)**, ask 2 of the support request; a **command-type Plesk Git deployment action**, which executes shell as the subscription user but only on deploy, so it covers the four one-off commands and not the scheduler; or a **SQL console** if *Databases* offers phpMyAdmin — the still-open half of queue #1 — which imports a schema dump but runs no `artisan` |
| **U-4** | **B-3 — `httpdocs/ethr.et/` is an unidentified Plesk object** with its document root inside `httpdocs/` | Identification needs the panel. Deleting a vhost is not deleting a folder, so it is not being touched on a guess | Manual queue #5 |
| **U-5** | **The host carries a Git deployment at `716ab93`, 47 commits behind `origin/main`** | Not reconciled, and reconciling it before Gate 0 would deploy an unverified configuration | Gate 0 completing, then a deliberate first deployment |
| **U-6** | **`httpdocs/public/` and `httpdocs/et/` were removed without the disposability confirmation `B1-B5_GATE_REPORT.md` required** | Irreversible. `et/` was recorded empty; `public/` was never inspected | Nothing — recorded as a permanent gap rather than quietly dropped |

~~**U-1 is the one with a deadline.** Every other entry waits on evidence or on a decision
with no cost to delay. U-1 degrades: the longer the corrections sit on branches, the more
likely someone runs Gate 0 from `main` and gets the four-check canary, the missing
`simplexml` row, and the instruction to copy `robots.txt` into the document root.~~

**That deadline was met on 2026-09-18.** `main` is `7fb91cc`. Nothing in this register
still degrades with time: **U-2** through **U-6** all wait on evidence, on an external
capability, or on a decision that costs nothing to defer — and **U-6** is a permanent
record rather than a task. The remaining register is genuinely blocked, not merely
un-started.

Two consequences worth stating, because "merged" is easy to over-read:

- **No gate moved.** G0-A through G0-J are still `NOT VERIFIED`. What merged is a
  *corrected procedure*, not a verified one. The Plesk account is still what Gate 0 needs.
- **The host's Git deployment is now further behind, not closer.** `U-5` records it at
  `716ab93`; `main` has moved twice since that was written. Reconciling it still waits on
  Gate 0 — deploying now would push an unverified configuration onto the target.

### Production env template — completeness checked 2026-09-17

`api/config/*.php` reads **232** env vars; `.env.production.example` declares **62**. That
gap sounds alarming and mostly is not: 128 of the undeclared ones carry defaults in
`config/`, and of the 46 with no default, all but one belong to drivers this target does
not use — AWS/S3, SQS, DynamoDB, Memcached, Pusher, Postmark, Resend, Slack, Papertrail.
A `null` for those is correct.

Two were checked properly rather than assumed:

- **`SENTRY_DSN` — false alarm.** `config/sentry.php:13` reads
  `env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN'))`, and the template declares the primary.
  No mismatch. Recorded because the naming looks like a bug and is not.
- **`CONTACT_INBOX` — a real template gap, now closed.** `config/mail.php:125` reads it
  with no default; it was absent from the template. The *code* is fine and deliberately so
  — `ContactController` persists the lead either way and logs
  `"Lead saved; no contact inbox configured"` at info level, with a comment saying this is
  not an error. But a production deployment built from the template would silently collect
  leads nobody is notified about, with a log line as the only signal. Added as a commented
  entry with that reasoning, so leaving it blank is a decision rather than an omission.

No other deployment-relevant variable is missing.

### Checked and clean — recorded so it is not re-checked

`api/.env.production.example` carries **no real secret**. Every `*_KEY`, `*_PASSWORD`,
`*_SECRET` and `*_TOKEN` value is an instruction-style placeholder or empty (verified by
pattern, values never printed). A first-pass entropy heuristic flagged nine of them; that
was a false positive and is recorded as such rather than left as a scare.

---

## GIT DEPLOYMENT ROUTE — verified from the repository, 2026-09-17

`DEPLOYMENT.md` step 3 uploads with `rsync` over SSH and step 4 runs `artisan` over SSH.
**Neither is available on this account** (SSH Forbidden, measured). Plesk's **Git** and
**Composer** extensions are present, so that is the route. What follows is what the
*repository* guarantees about it — checked, not assumed. The Plesk-side behaviour is
flagged as unknown rather than described.

### The layout maps cleanly — no repackaging needed

Plesk Git's deployment path is relative to the **webspace root**, so a path of `ethr`
deploys to `~/ethr/`. The repository root holds `api/ docker/ docs/ infrastructure/
scripts/ src/`, which makes the result `~/ethr/api/` — **exactly what §0 specifies**, a
sibling of `httpdocs` and outside the document root. Nothing has to be restructured.

### Three things checked, because Git deploys differ from rsync in ways that break Laravel

| Risk | Result | Evidence |
| --- | --- | --- |
| **Git does not carry empty directories**, and Laravel needs `storage/framework/{cache,sessions,views}`, `storage/logs` and `bootstrap/cache` to exist or it fails at runtime | **SAFE** | Every one carries a tracked `.gitignore` — 13 of them under `api/storage` and `api/bootstrap`. The tree is recreated by the clone. This is the classic Git-vs-rsync deployment break and it does not apply here |
| Deploy payload size | **SAFE** | 1,599 tracked files, largest is 700 KB (`generated.ts`). No binaries, no bundled dependencies |
| Secrets reaching the host through Git | **SAFE** | `vendor/`, `node_modules/`, `.env`, `api/.env`, `api/.env.production`, `storage/*.key` and `public/storage` are all gitignored. Nothing carrying a credential is tracked |

### Ordering that must not be got wrong

`.env` **before** Composer, not after. `composer install` runs `package:discover`,
`config/broadcasting.php` defaults to `reverb` when `BROADCAST_CONNECTION` is unset, and
`routes/channels.php` calls `Broadcast::channel()` at load time — so Composer exits 1 with
a null Pusher key if no `.env` exists yet. This is the same defect that was CI cause 2.
`DEPLOYMENT.md` step 1 already warns about it; under the Git route the hazard is larger,
because the Plesk Composer extension is a button that can be pressed at any moment.

So: **Git deploy → create `~/ethr/api/.env` → then Composer.**

### What this route does and does not solve

**Solves:** getting code onto the host (B-1's `rsync` half) and installing dependencies.

**Does not solve B-4.** `key:generate`, `migrate`, `db:seed` and `ethr:create-admin` still
have no runner. Git puts the files there; Composer fills `vendor/`; nothing executes
`artisan`. That remains blocked on SSH or a command-type Scheduled Task — manual action
queue items 2 and 1.

### Unknown on the Plesk side — not asserted here

Which branch the extension tracks and whether it can be changed; whether the deployment
path is editable after creation; whether "additional deployment actions" exist on this
plan (they normally run shell commands, which would be a route for `artisan` — but shell
is Forbidden, so assume not until seen). The extension currently reports `716ab93`, **47
commits behind `origin/main`**, which is itself a sign it is configured but not tracking
anything current.

---

## The frozen package, reviewed read-only 2026-09-18 — one consequence that outlives deployment

`ENVIRONMENT.md` and `health-check.md` are inside the frozen directory, so they were read
and not edited. Both are broadly sound. Two observations, recorded here because this file
is not fenced:

**`ENVIRONMENT.md:170` — the package's one operational instruction is gated on G0-D.**
It is `* * * * * cd ~/ethr/api && php artisan schedule:run`, a **command-type** Scheduled
Task. If G0-D returns "Fetch a URL only", that single line is inert and the scheduler,
the queue, invoicing, leave accrual and payslip notifications have no delivery mechanism.
Nothing else in the file assumes a shell, which is the right shape — it just means the
whole asynchronous half of the product rests on one unread panel page.

*Read 2026-09-18, and the answer is neither branch above: there is no Scheduled Tasks
section at all. **G0-D = FAIL**, so that single line is inert today, not conditionally.
See* G0-D ANSWERED 2026-09-18 *below.*

**`health-check.md` — post-deployment monitoring has no verified access route, and B-5
does not end at deployment.** Its two primary ongoing checks are SQL:

- `SELECT COUNT(*) FROM failed_jobs` — there is no Horizon UI here, so this *is* the
  failed-job surface
- `SELECT MAX(created_at) FROM jobs` — the doc's own reasoning is that *"cron silently
  not firing looks identical to nothing to do"*, so this is the only way to tell them apart

With SSH Forbidden, running either needs a database UI. **phpMyAdmin was not in the Dev
Tools list the owner reported** — it is usually under a separate *Databases* section
rather than Dev Tools, so its absence from that list is not evidence either way, and it
has not been checked.

The third row degrades correctly on its own: disk usage offers *Plesk → Statistics* before
`du -sh … over SSH`, so it survives.

**Why this matters beyond the gates.** B-1 and B-5 have been framed as deployment
blockers. This is the same gap in the operations phase: if there is no way to run SQL, then
after a successful deployment there is still no way to see that queued jobs are failing or
that cron has stopped — the two failure modes this product has already been bitten by
(`ScanAttendanceAnomaliesJob` failed on every scheduled run and was found by opening the
health endpoint for an unrelated reason). **Add "is there a database UI?" to the Scheduled
Tasks panel visit** — same page-load, and it decides whether this deployment is
observable, not merely whether it is possible.

---

## THE PROBE'S CLI CAVEAT COVERED ONE ROW OF FOUR — 2026-09-18

Same lens as the canary finding, turned on the other instrument. The probe's `Limits`
section grades four rows that feed **G0-E**, and it already carried a warning that a CLI
run cannot see the web SAPI's value. **The warning named only `max_execution_time`.**

Measured here on PHP 8.4, CLI:

| Row | CLI value | Probe verdict | Reality |
|---|---|---|---|
| **L1** `memory_limit` | `-1` | **OK** | **False PASS** — unlimited under CLI, says nothing about the web limit |
| **L2** `max_execution_time` | `0` | OK, **warned** | Guarded, correctly |
| **L3** `upload_max_filesize` | `2M` | **FAIL** | **False FAIL** — php.ini default, not the web value |
| **L4** `post_max_size` | `8M` | **FAIL** | **False FAIL** — same |

**Three of four rows were unreliable and only one said so**, and they mislead in *both*
directions. A false PASS on `memory_limit` hides a host that cannot run payroll. A false
FAIL on the upload pair sends the operator to support for a limit that may already be
correct.

### Fixed

The warning now covers all four, names the CLI defaults that cause each direction of error,
and says which feature depends on which pair — payroll on the first two, employee document
upload on the last two.

### Honest scope

**The practical impact today is small, and it is worth saying so rather than inflating it.**
This only bites a **CLI** run, which is Route A, which needs SSH — and SSH is Forbidden.
Route C, the only live route, runs under a real web SAPI where the four values *are* the
production values and no caveat is needed. The probe's gating was correct for both real
routes.

It is worth fixing anyway for one reason: **ask 2 of the support request is SSH.** If it is
granted, Route A becomes live the same day, and this is precisely the run that would then
mis-grade three of G0-E's rows.

### A note on the `php -S` validation

`GATE-0-RESULT.md` records Route C as *"validated by running it, 2026-09-17"* under PHP's
built-in server. That SAPI reports as **`cli-server`**, not `cli`, so the CLI warning was
suppressed while the ini values were still CLI-ish — `memory_limit` `-1`, upload pair at
`2M`/`8M`. **That validation proved the transport, not the limits.** It was never claimed to
prove the limits; recorded so nobody later reads it as having done so.

---

## THE CANARY HAD A FALSE NEGATIVE — found by reproducing Plesk's topology

With nginx and Apache both installed locally, the actual Plesk arrangement was
reproduced — **nginx proxying to Apache** — rather than Apache alone. That is the
configuration G0-B.5 is actually about, and it exposed a defect in the instrument.

### Three topologies, measured

| | `shadow.txt` | `shadow.js` |
|---|---|---|
| **A** — static serving OFF (pure proxy) | REWRITE won | REWRITE won |
| **B** — static block **includes** `.txt` | FILE won | FILE won |
| **C** — static block **excludes** `.txt` | **REWRITE won** | **FILE won** |

**Case C is the defect.** Static serving is ON and `.js`/`.css` *are* being shadowed — but
the canary, testing only `shadow.txt`, reports *"the rewrite won"*. **G0-B.5 would have been
graded as though `.htaccess` applies to static assets, when it does not.**

### Why it matters more than a mis-graded row

Plesk's generated static block **always** covers js/css/images; whether it covers `.txt`
varies by version and template. And the deployment ships **`.js`, `.css`, `.woff2` — never
`.txt`**. So the one extension the canary tested was the one least likely to be
representative.

The damage is not the row itself. When static shadowing is in force, `.htaccess` never runs
for those files, so **the seven security headers and `Cache-Control: immutable` silently do
not reach them.** That is **G0-B.2's real failure mode hiding behind G0-B.5's answer** — and
both gates would have read green.

### Fixed

`shadow.js` added as a second bait with the rewrite rule to match; `canary.php` now prints
both URLs, explains why `.js` is the one that counts, states that the two **can legitimately
disagree**, and spells out the G0-B.2 consequence. README and the deploy list updated —
five files, not four.

**Re-measured across all three topologies after the fix: A both-rewrite, B both-file, C
disagrees exactly as designed.** The canary now catches case C.

*(Second method note in one session: the first run of this comparison used a shell `case`
pattern anchored at the start of the string, and `shadow.js` begins `// `, so it reported
"REWRITE won" for a file that had plainly been served from disk. The harness was wrong, not
the canary. Both times the tell was the same — a result that contradicted a simpler,
already-established measurement.)*

### The standing of this

The canary was **sound as an instrument** and **wrong as an experiment**: every mechanism
fired, and the sample it drew was unrepresentative. That is a harder class of defect than a
broken rule, because everything looks like it is working.

No gate moved. G0-B.5 remains `NOT VERIFIED` — what changed is that answering it will now
produce the right answer.

---

## `nginx-directives.conf` WAS EXECUTED — 2026-09-18. It holds.

The third deployment artifact, and its own header said so: *"STATUS: NOT VERIFIED against
any live host. Every line below is a translation of a directive in this directory's
`.htaccess`, not a measurement."* One measured thing was claimed — 17 sample URIs matched
under PCRE on 2026-09-15 — but that tested the **regexes**, not nginx.

nginx 1.24.0 installed locally; the repository file **included verbatim** into a server
block; the same document root shape served. File unchanged.

### Syntax

```
nginx -t  →  syntax is ok, test is successful
```

First time the file has been parsed by nginx at all.

### Every deny and allow claim, now measured under nginx rather than under a regex

| Must be denied | | Must NOT be denied | |
|---|---|---|---|
| `/.env` | **403** | `/.well-known/acme-challenge/token` | **200** |
| `/.git/config` | **403** | `/manifest.json` | **200** |
| `/composer.json`, `/composer.lock` | **403** | `/assets/app.min.js` | **200** |
| `/package.json`, `/package-lock.json` | **403** | `/_next/static/chunks/main.js` | **200** |
| `/artisan`, `/phpunit.xml` | **403** | `/storagebin/x` | **200** |
| `/storage/…`, `/bootstrap/cache/…` | **403** | `/` | **200** |

**16 of 16 as claimed.** The two that matter most are the negatives: ACME passes, so
certificate renewal survives, and `/storagebin/x` passes, so the `storage` rule is a
directory match and not a prefix match.

### The `add_header` inheritance trap — tested, and it holds

`GATE-0-RESULT.md` G0-B.2 warns: *"nginx `add_header` does not inherit into a location that
has one of its own, so one passing URL proves nothing about the others."* So one URL was
not trusted:

| Path | Headers |
|---|---|
| `/` | **7/7** |
| `/assets/app.min.js` | **7/7** |
| `/_next/static/chunks/main.js` | **7/7** |
| `/manifest.json` | **7/7** |
| `/.well-known/acme-challenge/token` | **7/7** |
| `/.env` (a **403**) | **7/7** |

All seven reach every path type, including the denied response — the `always` flag working
as intended.

**It holds for a reason, and the reason is fragile.** Parsed properly (comments stripped,
brace depth tracked): **no active `add_header` sits inside any `location` block** — all
seven are at server level. The file *documents* the trap and ships a commented repeat-block
for static assets, which is good practice. **The moment anyone uncomments that block, or
Plesk's generated config adds an `add_header` to a static-file location, the server-level
seven vanish from those responses silently.** That is the failure this file exists to
prevent, and it can be reintroduced by an edit that looks like an improvement.

*(Method note: the first check for this used `awk` and latched onto the word "location"
inside a comment, reporting a dozen false positives. Re-done with comment stripping and
brace tracking. A pattern that matches prose is not a pattern that matches code.)*

### `client_max_body_size 32m` — enforced

| Upload | Result |
|---|---|
| 8 MB | 405 (method not allowed on a static path — **not** 413, so under the limit) |
| 40 MB | **413** |

The limit is real. It is the directive that otherwise fails silently: uploads simply stop
working at a size nobody documented.

### What this does not establish

The file is **correct as written**. Whether *this host* will accept it is **G0-A**, still
`NOT VERIFIED` and strong-evidence FAIL — there was no directive textarea on the settings
page. A correct file you cannot paste anywhere is still not a control. Its header's own
instruction stands: do not treat a paste as a control until G0-B.2 and G0-B.3 pass.

---

## THE CANARY WAS EXECUTED TOO — 2026-09-18, and it is sound

The canary is the instrument that will **answer** G0-B.1–B.5. If it mis-reports, those
rows get graded wrong and nobody finds out. It had never been run either. Served from the
same local Apache; package copied, repository untouched.

**All five mechanisms fire correctly:**

| # | Mechanism | Result |
|---|---|---|
| 1 | `mod_rewrite` — `/REWRITE_OK` → `canary.php` | **reached it** (200, canary content, not a 404) |
| 2 | `mod_headers` | **`X-Ethr-Canary: headers-ok`**, plus the two security headers |
| 3 | Deny rule — `secret.txt.probe` | **403** |
| 4 | `Authorization` forwarding | **works** — `Bearer CANARYTOK` |
| 5 | Static shadowing — `/shadow.txt` | **canary.php answered, not the file on disk** |

### Row 5 is the one worth having, because it establishes the control

G0-B.5 asks whether Plesk's nginx serves static files **before Apache sees the request**.
The canary answers it by putting `shadow.txt` on disk *and* rewriting that exact path, so
whichever layer wins is visible in the response — its own README: *"Whichever one answers
tells you which layer resolved."*

**Nobody had measured what Apache alone does.** Now it is measured: with Apache serving and
no nginx in front, **the rewrite wins**. So the reading is unambiguous when the real result
arrives:

| The canary returns | Meaning |
|---|---|
| `canary.php`'s output | Apache resolved it — rewrites reach the request |
| `shadow.txt`'s own text | **nginx shadowed it** — static is served before Apache, and `DEPLOYMENT.md` step 4a's premise holds |

Without that control, a result of "canary.php answered" could have been read either as
*Apache won* or as *the bait never worked*. It can no longer be confused.

### What this does and does not establish

**Does:** the canary package is correct and will report faithfully. Deploying it is worth
doing; its answers can be trusted.

**Does not:** move G0-B.1–B.5 a millimetre. Those are properties of **that host**, and the
only thing measured here is that the instrument works. A working thermometer is not a
temperature.

Same PHP limit as the `.htaccess` run: `canary.php` was served as source, so its *printed
report* is unverified — only the four Apache-layer mechanisms it depends on were exercised.
The fifth, its own PHP output formatting, was verified separately on 2026-09-18 when the
`$scriptDir` defect was fixed by running it.

---

## THE DEPLOYMENT `.htaccess` WAS EXECUTED — 2026-09-18

`docs/deployment/shared-hosting/.htaccess` is the routing brain of the whole deployment:
deny rules, header policy, API routing, ACME passthrough. **It had never been run.** The
canary (G0-B.1–B.4) tests whether the host honours `.htaccess` *at all*; nothing tested
whether *this file* is correct.

Apache 2.4.58 was installed locally, a document root assembled per the runbook, and the
real file served. **Repository file untouched throughout — the served copy was a copy.**

### What passed, measured

| Check | Result |
|---|---|
| Deny rules — `.env`, `.env.production`, `composer.json/lock`, `package.json`, `package-lock.json`, `artisan`, `phpunit.xml` | **403 on all seven** |
| `storage/`, `bootstrap/cache/` | **403 both** |
| ACME passthrough `/.well-known/acme-challenge/…` | **200** — certificate renewal survives the rules |
| Static asset | **200** |
| API routing `/api/v1/ping`, `/sanctum/csrf-cookie` | **200** — both reach the front controller |
| Seven security headers | **all present**, verified on the wire |
| `Cache-Control: public, immutable` on assets | present |
| **`Authorization` forwarding** | **works** — `X-Probe-Auth: Bearer TESTTOKEN`, `(null)` in the control |
| **`X-XSRF-Token` forwarding** | **works** — same shape |

The two forwarding rules were observed through env-var echoes **set in the vhost, not in
the file under test**, so the artifact was measured rather than modified. This is the
mechanism behind **G0-B.4**; the file's half of it is correct. (Whether the *host* honours
`.htaccess` at all remains G0-B.1–B.4 and still needs the canary.)

### The gap: there is no SPA fallback

| Request | Result |
|---|---|
| `/employees/` (a prerendered page exists) | 200 |
| `/employees/123` | **404** |
| `/dashboard` | **404** |

The rewrite routes `^/(api\|sanctum)` to `index.php` and nothing else. **Every client-side
route that was not prerendered 404s** — on a shared link, and on a browser refresh of any
detail page.

This is independent of the Next.js finding and compounds it: even if the
`generateStaticParams` problem were solved, these URLs would still 404 at the Apache layer
until a fallback exists.

### The fix, tested and NOT applied

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]
```

Placed after the API rule. Measured in the served copy:

| | |
|---|---|
| `/employees/123`, `/dashboard` | **200**, SPA shell |
| `/employees/` | **200, still the real prerendered page** — `!-f`/`!-d` lets real files win |
| API, deny rules, ACME, static assets | **no regression** |

**Deliberately not applied**, for three reasons: the file is in the frozen directory;
Branch B is **NOT APPROVED**; and the rule presumes an `index.html` exists, which is true
under Branch B and false under Branch A. Applying it now would bake in the branch decision
this workstream has not made. It is recorded as a proposal with its evidence, ready for the
pass that selects an architecture.

### One limit on this measurement

`index.php` was served as source — there is no PHP handler in this Apache, and
`libapache2-mod-php` could not be installed because the PPA is refused by the egress policy
(403), which per that policy was reported rather than worked around. So **routing to the
front controller is verified; PHP execution behind it is not.** That was never this file's
job.

---

## STATIC EXPORT MEASURED, NOT ESTIMATED — 2026-09-18

The repository builds for a target this account cannot host, and the documented cost of
fixing that is wrong. Both measured by running builds here; no host involved.

### `next.config.ts` still says `output: "standalone"`

That is the **Node server** build — Branch A. Shared hosting runs no Node server, so as it
stands **the frontend builds for a target the account cannot serve.** Branch B (static
export) is the shared-hosting path, and it had never been attempted.

**Baseline build: PASS.** `npm run build` on Node 22, exit 0, 90 routes prerendered,
11 dynamic (`routes-manifest.json`), 1 server-rendered (`/register`).

### Attempting `output: "export"` — three findings, in the order the build produced them

| # | Blocker | In the documented estimate? |
|---|---|---|
| 1 | `/manifest.webmanifest` needs `export const dynamic = "force-static"` | **No — missing entirely** |
| 2 | The four `[id]` routes are missing `generateStaticParams()` | Yes |
| 3 | **All four are `"use client"`, and Next rejects `generateStaticParams` on a client component** | **No — and it invalidates the estimate** |

Finding 3 verbatim from the build:

```
Error: Next.js can't recognize the exported `generateStaticParams` field in route.
App pages cannot use both "use client" and export function "generateStaticParams()".
  × 4
```

### Why that matters

`SHARED_HOSTING_AUDIT.md` §E and D6 cost Branch B as, among other items,
*"`generateStaticParams` on four dynamic routes"* — four one-line additions. **That is not
implementable as written.** `app/(dashboard)/{admin/tenants,devices,employees,payroll}/[id]/page.tsx`
all open with `"use client"`, so the compiler rejects the addition outright.

The real shape is a **split per route**: a server component that exports
`generateStaticParams`, with the existing client component moved into a child. Four file
splits with prop-threading, not four one-line edits.

And the harder half is unchanged by any of that: **those IDs are tenant data.** Nothing can
enumerate every employee, device, payroll run and tenant at build time, so
`generateStaticParams` can only return `[]` — which builds, and then 404s every real
`/employees/123`. Making those routes work under export means client-side routing that
reads the id from the URL at runtime, which is a different and larger change again.

### Static-export feasibility is not application runtime feasibility

The audit conflated these, and the distinction decides whether Branch B is viable at all:

| | |
|---|---|
| **Static-export feasibility** | Can `next build` emit files? **Currently no**, and fixable — a metadata directive, four server/client splits, `generateStaticParams` returning `[]` |
| **Application runtime feasibility** | Will the exported app *work*? **No, and the fixes above do not change that.** `[]` pre-renders nothing, so every real `/employees/123` returns 404 |

**A passing export build would not mean a working application.** Same shape as the
green-test-run trap in the root `CLAUDE.md`: the signal that looks like success arrives
before the thing works.

### Branch B is NOT APPROVED for implementation

Until a deployment architecture is selected. It is gated on G0-A and G0-G, both
`NOT VERIFIED`, and `SHARED_HOSTING_MIGRATION_PLAN.md` §4's pre-registered rule has
returned No-Go on B3.

**The "four small route changes" framing is withdrawn everywhere it appeared.** On measured
evidence Branch B is an **architectural frontend deployment change**: a rendering-strategy
switch, four component splits, and a routing rearchitecture for entity pages. Corrected at
source in `SHARED_HOSTING_AUDIT.md` §E and in every document that had compressed it —
`GATE-0-RESULT.md` (×3), `TCO_COMPARISON.md`, `SHARED_HOSTING_MIGRATION_PLAN.md`,
`B1-B5_GATE_REPORT.md`, `shared-hosting/DEPLOYMENT.md` step 4, and this file's queue row 3.

### What was NOT done, deliberately

The experiment was reverted in full — `git checkout -- src/`, working tree clean,
`next.config.ts` back to `standalone`. **No Branch B work was implemented.** It is gated on
G0-A and G0-G, both `NOT VERIFIED`, and on the Option A/B decision that has just returned
No-Go. Implementing it now would be the speculative spend this file has just recommended
against.

What is delivered is the measurement: if Branch B is ever revisited, the cost line needs
rewriting first, and the new blocker (`manifest.ts`) needs adding.

### Repository-side compatibility — where it actually stands

| Layer | State |
|---|---|
| **Backend** — PHP version, extensions, env template, paths, config defaults, Horizon, health checks | **Compatible.** Verified by CI and by reading the code |
| **Frontend** — Branch A (`standalone`, Node server) | Builds, **but the account cannot run it** |
| **Frontend** — Branch B (static export) | **Does not build** — three blockers, one of which invalidates the costing. **And fixing all three would not make it work:** the `[id]` routes resolve tenant data, so `generateStaticParams` can only return `[]` and every real `/employees/123` 404s. Build feasibility and runtime feasibility are separate questions; see the section above |

The backend half of "shared-hosting compatible by default" is done. **The frontend half is
not, and now has a measured gap rather than an assumed one.**

---

## THE PRE-REGISTERED DECISION RULE HAS FIRED — 2026-09-18

Recorded because the owner asked for a decision, and one was already committed to in
writing before any evidence existed. This is not a new judgement; it is reading the rule
that `SHARED_HOSTING_MIGRATION_PLAN.md` §4 set down and applying the measurement.

### The rule

| Gate | The plan's pre-registered consequence |
|---|---|
| **B3** cron | **"No-Go.** Leave accrual, invoicing, anomaly scanning, cleanup and every queued email stop. **→ Option A"** |

`B3` is `G0-D` (the disambiguation table at line 162 of this file). **G0-D is FAIL** — there
is no Scheduled Tasks section on this subscription, read from the dashboard on 2026-09-18.

**So the rule's own answer is Option A: stay on the VPS.** The value of pre-registering a
decision is that it cannot be renegotiated once the answer is inconvenient, and this one is
inconvenient.

### Two things stop that from being final

1. **G0-D is FAIL *as provisioned*, not *impossible*.** Scheduled Tasks is a service-plan
   permission, so a support grant or a tier change flips it. That request is written and
   unsent: [`deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`](deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md).
   **It is the only thing that can reverse the rule.**
2. **The decision belongs to the owner, not to this file.** What is recorded here is that
   the criterion they set has been met, and what follows from it.

### A second gate is pointing the same way

**B2** (wildcard TLS) carries the same *"No-Go → Option A"* consequence, and G0-C's TLS row
is `PARTIAL`: per-hostname certificates are **proven**, wildcard is **blocked** because the
zone sits on `ns1`/`ns2.telecom.net.et` and Plesk cannot perform DNS-01. That is not fatal
the way B3 is — tenants can be certificated one at a time — but it converts self-service
tenant signup into manual operator work per tenant, permanently.

Two of the four fatal gates now point No-Go. None points Go.

### Option C is not the escape hatch

The plan already rejected it, and the reasoning holds better now than when it was written:

> *"Once a VPS is in the picture, Option A is strictly better. The VPS in this hybrid is
> doing the hard part — Redis, workers, WebSockets, storage — while the shared host
> contributes only PHP execution that the same VPS could do for free. The hybrid costs more
> than the VPS alone, is harder to operate, and is slower."*

Its stated exception is narrow: *"unless the requirement is specifically 'the domain and
web tier must be hosted at Ethio Telecom' for a reason other than cost."* **If such a
requirement exists — regulatory, contractual, data residency — it has never been recorded,
and it would change this answer.** That is the one question worth putting to the owner
rather than deciding for them.

### The tier question, added to the ticket 2026-09-18

Before Option A is taken as settled, one thing has never been asked. `GATE-0-RESULT.md`
records that *"PHP versions / Node / cron granularity / proxy directives"* often **differ
by tier**, and that **G0-A, G0-D and G0-G may all have different answers on a higher
plan** — which is precisely the set blocking this migration:

| Gate | State | Cost if it stays |
|---|---|---|
| **G0-D** cron | **FAIL** | The application cannot be installed at all |
| **G0-A** custom directives | strong-evidence FAIL | Forces Branch B — the architectural frontend change |
| **G0-G** Node runtime | **never read** | If it passes, Branch A works and the frontend needs **nothing** |

**All three are service-plan permissions, not server capabilities. The account's tier has
never been confirmed.** So the decision tree everyone has been reasoning over has a branch
nobody checked: a higher plan could flip all three at once.

Added as **ask 4** to the support request. It asks the provider to *sell* something rather
than grant a favour, which is usually the easier conversation, and it can resolve three
gates in one reply.

**G0-G is also free to read from the panel right now**, and it carries the most information
per unit of effort of anything outstanding: it is the difference between "the frontend
needs an architectural rework" and "the frontend needs nothing".

### What follows, in order

1. **Send the support request.** One ticket, four asks *(three when this was written; a
   fourth — what the higher tiers include — was added 2026-09-18)*. It is the only route to
   G0-D now that Route D went with the Git repository, and it costs nothing to try.
2. **Set a deadline on it.** No useful answer within a reasonable window means refused;
   refused means the rule stands and the target is Option A.
3. **Spend nothing further on Option B.** The repository is already Plesk-compatible and
   that work does not rot — it is correct whenever this is revisited. But the static-export
   refactor (B5 / Branch B) and anything else conditional on Option B should wait for an
   answer to B3.
4. **Answer B-6 now, and treat it as the top risk rather than a footnote.** Under Option A
   the VPS is not a rollback target — **it is production** — and nobody has confirmed it is
   still serving or what data it holds. `tin` and `national_id` are `encrypted` casts and
   off-host backup was never configured, so its `APP_KEY` may be the only thing that can
   read its own data. Manual actions **0a** and **0b** need no Plesk and no permission —
   but **0a needs an ordinary network**, not an agent sandbox, per **KB-3**.

**No gate moved.** Gate 0 remains 1 verified, 1 failed, 28 outstanding. Recording that a
decision rule has fired is not evidence about the host.

---

## MANUAL ACTION QUEUE — the only things that still need a human in Plesk

Everything resolvable from the repository has been done. These **nine** remain, in
dependency order (the count said "eight" while listing nine — row 1, the Scheduled Tasks
read, was removed when **G0-D** was answered FAIL on 2026-09-18, and the total was never
adjusted). Each says where to click, what to bring back, whether to change anything, and which
gate it unlocks. **Do not do 8 before 5.**

| # | Action | Plesk location | Bring back | Change anything? | Unlocks |
| --- | --- | --- | --- | --- | --- |
| **0a** | **Is the VPS still serving?** — *no Plesk needed, but it does need an ordinary network: **not** from an agent or CI sandbox (**KB-3**)* | n/a — `curl -sI http://91.99.81.71/`, check 80/443 | Whether anything answers | No | **B-6.** Decides whether a rollback target exists at all. **Highest priority in this table** |
| **0b** | **Does the VPS hold real tenant data?** If yes, dump it **and preserve its `APP_KEY`** off the machine before touching it — `tin` and `national_id` are `encrypted` casts, and off-host backup was never configured — *no Plesk needed* | n/a — this is a question about the VPS | Yes/no. If no: the deployment is a fresh start | No | Collapses **B-5** into B-4 and makes half of `DATABASE_MIGRATION_PLAN.md` not apply. **Do this first — it is free and it may remove work** |
| ~~**1**~~ | ~~**Scheduled Tasks capability**~~ **ANSWERED 2026-09-18 — the section does not exist.** See *G0-D answered* below. The database-UI half of this item is **still open**: a *Databases* section IS present on the dashboard, but whether it offers a SQL console (phpMyAdmin) has not been read. | Websites & Domains → *Databases* → look for phpMyAdmin / a query console | Task types offered ("Run a command" / "Fetch a URL" / "Run a PHP script"), minimum interval, full path to the PHP binary. Plus: is there any web UI that can run SQL? | No | **G0-D** — decides whether the migration is performable at all without SSH (B-1/B-4). The database-UI half decides **B-5** *and* whether the deployment is observable afterwards: `health-check.md`'s two primary checks are both SQL |
| **2** | **SSH availability** | Hosting Settings → *SSH access* | Whether the field is changeable by you or greyed out; the value you set | Set `/bin/bash` **if the field allows it** | Clears **B-1 and B-4**; makes probe Route A and `artisan` available. Setting it is not proof it works — verify separately |
| **3** | **Custom-directive capability** | Websites & Domains → *Apache & nginx Settings*, **bottom of page** | Whether any *"Additional directives for HTTP/HTTPS"* or *"Additional nginx directives"* textarea exists | No | **G0-A**. Absent → FAIL, which costs an **architectural frontend deployment change** (measured `7aed9d2`), not the "scoped" one this row used to claim |
| **4** | **Static-file handling** | Same page, nginx section | Exact current value of *"Serve static files directly by nginx"*, verbatim or "empty" | No | **G0-B.5**, and it conditions how **G0-B.2** must be read |
| **5** | **Identify the unexplained object** | Websites & Domains | What object exists named `ethr.et` besides domain id 2536, and its document root | **No — identify only.** Deleting a vhost is not deleting a folder | **B-3**; unblocks action 8 |
| **6** | **Capability probe, Route C** | File Manager → upload to `httpdocs/<random>.php`, **then set `ETHR_PROBE_WEB_TOKEN` in that copy to a second random value** — as shipped every web request returns 403 | The full output. Open it as `?token=<that value>` and pass **no other query parameter**; **delete the file in the same sitting** | Upload, edit the token, then delete | **G0-E**, **G0-H**, storage rows, **G0-J** CPU half. Read the truncation table in `deployment/GATE-0-RESULT.md` Step 1 first |
| **7** | **Canary — five gates, SIX fetches** | File Manager → `httpdocs/ethr-canary/` | Output of all six fetches — **G0-B.5 needs two, `shadow.txt` AND `shadow.js`** — each `curl` with `--resolve www.ethr.et:443:213.55.96.154`; plus the `favicon.ico` header comparison for G0-B.2's static-asset scope | Upload then delete the directory | **G0-B.1–B.5**. Upload **five** files from `main` — `.htaccess`, `canary.php`, `secret.txt.probe`, `shadow.txt`, **`shadow.js`**. `README.md` stays in the repository; it explains the baits and there is no reason to publish that. **`shadow.js` was added 2026-09-18 and is the bait that counts**: with a static block that excludes `.txt`, `shadow.txt` alone reports "the rewrite won" while every asset the deployment ships is being shadowed — a false PASS on G0-B.5 that also hides G0-B.2. **The two can legitimately disagree; record both.** ~~Take the four files from branch `claude/gate0-procedure-corrections`~~ *(corrected 2026-09-18: `shadow.txt` is on `main` since `7fb91cc`; that branch is merged and this row would have sent you to a stale copy).* Miss `shadow.txt` and you run five checks, not six. **Miss `shadow.js` and you run six checks and get the wrong answer** — which is worse |
| **8** | **Wildcard subdomain** | Websites & Domains → *Add Subdomain*, name it `*` | What the panel does when you save | Yes — create it | **G0-C**. **Only after 5**: adding a subdomain while an unexplained `ethr.et` object exists would compound B-3 |

**Highest value is action 1.** Without a shell, Scheduled Tasks is the only route to run
`artisan migrate`. Command-type or PHP-script tasks → the migration is performable.
URL-fetch only → there is no documented way to perform it on this account, and SSH stops
being a convenience and becomes a prerequisite.

**Still blocked after all eight:** **G0-F** (`CREATE TRIGGER`) and **G0-I** (server version,
charset) both need database credentials passed to the probe, which needs Route A or B —
so they wait on action 1 or 2. Route C cannot answer them by design, and that is the point
of Route C rather than a shortfall.

---

## ACCOUNT EVIDENCE — 2026-09-17 (first real observations)

Panel readings and a File Manager listing from the live Ethio Telecom account. Full
record and gate-by-gate effect: `deployment/GATE-0-RESULT.md` → *Account evidence*. **No
gate moved** — none of this is probe or canary output.

**Resolved:** SSH/shell is **FORBIDDEN** (the `/bin/false` caveat this file's own gate
report warned about is exactly what happened) · PHP **8.3.33**, satisfying `^8.2` ·
Composer and Git both present as Plesk extensions · Imunify and a WAF are active, which
may influence how G0-B.2 and G0-B.3 read.

**Document root CONFIRMED `httpdocs`.** Hosting Settings displays `/`, which read
literally would put the application in the web root; `.well-known/acme-challenge/` was
observed inside `httpdocs/`, and ACME challenges can only be served from the document
root. Plesk's `/` is relative to the webspace root. The `~/ethr` layout is intact.

**The account was not untouched**, contrary to `GATE-0-RESULT.md`'s previous claim.
`httpdocs/backend/` held a stock Laravel+Breeze scaffold — *not ETHR* — with no `.env`
and no `vendor/`; it and `dist/`, `public/`, `et/` have been removed by the owner.
`~/production.ethr.et/` is a **second vhost**. `httpdocs/` is now a pristine Plesk
default, which is a better starting point for G0-B than what preceded it.

### Blockers, recorded rather than resolved

| # | Blocker | Effect |
| --- | --- | --- |
| **B-1** | SSH **Forbidden** | The capability probe has no shell route; its fallback (Scheduled Tasks) depends on **G0-D**, unverified |
| **B-2** | No "Additional directives" fields on Apache & nginx Settings | G0-A untestable as written — strong evidence of FAIL, unconfirmed |
| **B-3** | `httpdocs/ethr.et/` — a Plesk-provisioned vhost skeleton (2026-09-17 23:48) with its document root **inside** `httpdocs/` | Purpose unknown; possible collision with the live `ethr.et` vhost; inverts the layout. Identify it in the panel before removing — deleting a vhost is not deleting a folder |
| **B-4** | No route to run `artisan` | `key:generate`, `migrate`, `db:seed`, `ethr:create-admin` have no non-shell equivalent anywhere in the package |

**B-1 is partly routed around.** `deployment/GATE-0-RESULT.md` Step 1 now carries three
probe routes. Route C — web-served under a random filename with **no database credentials**
— works on this account today and closes **G0-E, G0-H, the storage rows and the CPU half of
G0-J**. It is safe because the probe skips the database section entirely when credentials
are absent, so the access-log hazard that made web-serving a last resort does not arise.
**G0-F and G0-I stay blocked**, and G0-F is the one that aborts `migrate` by design, so it
must be answered before any deployment.

**B-1 and B-4 remain one support request** — *Hosting Settings → SSH access → `/bin/bash`* —
and together they reframe G0-D. Without a shell, Scheduled Tasks is not merely how the
scheduler runs: **it is the only route to migrate the database.** If G0-D returns "Fetch
a URL only", this migration has no documented way to be performed. ~~G0-D is now the
highest-value remaining panel read.~~

*G0-D was read on 2026-09-18 and returned worse than "Fetch a URL only" — the section is
absent — so the sentence above is the live case, not the hypothetical one. The highest-value
remaining panel read is **G0-G**; the highest-value action is sending the support request.
See NEXT ACTION.*

### One capability found, worth keeping

**Plesk Git's deployment path is relative to the webspace root, not to `httpdocs`.** So
setting it to `ethr` deploys to `~/ethr/` — exactly the layout `DEPLOYMENT.md` §0
specifies, outside the document root. That matters now that SSH is forbidden and step 3's
`rsync` is unavailable: Git + Composer extensions are the remaining deployment route, and
they can reach the correct target natively. Recorded here rather than in the runbook
because `docs/deployment/shared-hosting/*` stays frozen and this is not step 4a.

### Citation audit 2026-09-18 — every checkable claim, checked

The migration documents cite specific files and line numbers. A runbook that points at the
wrong line wastes the account session, and this sweep has already found four cases of a
document describing an artifact incorrectly. So every mechanically checkable citation was
verified against the file it names. **Eight of nine hold exactly**, which is worth
recording so nobody re-does it:

| Claim | Source | Result |
|---|---|---|
| `index.php` lines **9 / 14 / 18** are maintenance, autoloader, bootstrap | `DEPLOYMENT.md` step 4a | ✅ exact |
| `src/public/favicon.ico` present | `document-root-inventory.php` | ✅ |
| Frontend robots generator at `src/src/app/robots.ts` | `document-root-inventory.php` | ✅ |
| `api/public/robots.txt` reads `User-agent: * / Disallow:` | `document-root-inventory.php` | ✅ verbatim |
| `api/public/.htaccess` ends in `!-d`, `!-f`, `RewriteRule ^ index.php [L]` | `document-root-inventory.php` | ✅ at lines 22–24 |
| `shared-hosting/.htaccess:144` applies far-future caching to that exact extension set | `GATE-0-RESULT.md:176` | ✅ line 144 is that `FilesMatch`, extension set quoted verbatim |
| `infrastructure/nginx.conf` roots **three** server blocks at `api/public` | PR #20 / inventory | ✅ exactly 3 |
| `shared-hosting/.htaccess` restricts the front controller to `^/(api\|sanctum)`, guarded by `!-d`/`!-f`, and denies `.env`/`.git`/composer files | three documents | ✅ lines 64–67 and 32 |

#### The one that does not hold — `DEPLOYMENT.md:319`, Branch A

It reads: *"Delete the entire **"Everything else → frontend, BRANCH B"** block … (leave
BRANCH A as a comment for documentation, **per that file's own instructions**)."*

That attribution is wrong. The file's own Branch A instruction, at
`shared-hosting/.htaccess:72`, says the opposite:

> `BRANCH A — Node.js runtime available (B5 = yes). DO NOT USE this .htaccess branch;`
> `delete this whole "Everything else" block instead` and let Plesk's Node.js/Passenger
> integration own routing … Passenger inserts its own front-controller rule ahead of this
> file … a second catch-all rule here would conflict with it.

Delete the **whole section**, says the file. Delete **only BRANCH B**, says the runbook —
citing the file.

**Severity: low, and measured rather than assumed.** Lines 69–102 of that `.htaccess` were
checked for any active directive and contain **none** — the whole "Everything else"
section is commented out, and the file's only live catch-all is the `^/(api|sanctum)` rule
at 64–67. So neither instruction can leave a conflicting rule behind for Passenger, which
is the harm the file's warning exists to prevent. What it costs is an operator's time and
confidence: following the runbook, reading "per that file's own instructions", opening the
file and finding the opposite — at the one moment when the account session is running.

**Not corrected here.** `DEPLOYMENT.md` is inside the frozen directory and line 319 is the
Branch A frontend section, not step 4a, so fixing it would widen a freeze exception the
owner asked to keep narrow. Recorded for the same pass that lifts the freeze. The fix is
one clause: either drop "per that file's own instructions", or change the instruction to
delete the whole section and say why (Passenger's own catch-all).

Also confirmed while in the file, because its absence would be worse than any of the
above: `.well-known/acme-challenge/` is passed through untouched at lines 54–55, so
certificate renewal survives the deployment.

### The rest of the frozen package, reviewed read-only 2026-09-18

`ENVIRONMENT.md` and `health-check.md` were reviewed earlier. That left four files in the
package never opened in this sweep: `README.md`, `deploy-checklist.md`, `rollback.md` and
`nginx-directives.conf`. All four read, none edited — the directory stays frozen.

**`nginx-directives.conf` reviews clean, and is the best file in the package.** It opens
with `STATUS: NOT VERIFIED against any live host` and says every line is a translation of
the `.htaccess`, not a measurement. Its three `location ~` regexes *were* measured — run
against 17 sample URIs under PCRE on 2026-09-15, with the dangerous near-misses recorded
(`/.well-known/acme-challenge/token` NOT denied, `/storagebin/x` NOT denied as a prefix).
Braces balance, 23 active directives, and every `add_header` is server-level — with the
inheritance trap that makes that matter documented at its own line 77 and mirrored in
`GATE-0-RESULT.md`'s G0-B.2 row. Nothing to fix.

The other three each carry one defect. All are frozen, so all are recorded rather than
corrected.

#### 1. `rollback.md` contradicts itself inside a single sentence

Its entire instruction is:

> `Repoint ethr.et / www.ethr.et back to the VPS's IP.`
>
> That's it … since `ethr.et` had no live traffic before this migration (verified — see
> `docs/B1-B5_GATE_REPORT.md`; **the VPS is dormant, not serving**).

It tells the operator to roll back *to* the VPS, and in the parenthetical justifying that
instruction states the VPS **is not serving**. Repointing DNS at a host that answers
nothing is not a rollback; it is a second outage on top of the first.

This is B-6, which `PRODUCTION_CHECKLIST.md` row 24 was already downgraded for — but the
downgrade was recorded in the checklist, and the runbook the checklist points at still
reads as though the procedure works. **The most dangerous of the four findings**, because
it is the file someone opens while the site is visibly broken, and it is short enough to
be followed without reading twice. Manual action **0a** — *is anything still serving at
`91.99.81.71`?* — is what settles it, and this is the second reason that item is top of
the queue.

#### 2. `README.md` omits `nginx-directives.conf` from the package index

Its table lists six files; the directory holds seven. The missing one is the `.htaccess`
fallback — the file to paste if the canary comes back saying `.htaccess` is ignored, which
is the branch `GATE-0-RESULT.md` calls *"the gate most likely to come back negative."* An
operator working from the package README would not know it exists, at exactly the moment
they need it.

#### 3. `README.md` still uses the retired B3/B4/B5/H1 taxonomy

*"once `docs/MIGRATION_STATE.md`'s four remaining facts (B3, B4, B5, H1) are answered."*
Those IDs were superseded by the `B-1…B-6` register and the `G0-A…G0-J` gates.
`PRODUCTION_CHECKLIST.md` row 3 carried the identical staleness and was corrected on
2026-09-18; this is the same defect in a file the freeze protects.

#### 4. `deploy-checklist.md` — the pre-cutover gate cannot currently be executed

The file itself is good, and its Public-paths section already carries step 4a's
`robots.txt` trap. The problem is B-4, not the checklist. Of its 23 checks, **five need
`artisan` or raw SQL**, and neither has a verified route on this account:

| Check | Verifies |
|---|---|
| `php artisan migrate:status` | schema, and specifically that the audit-log trigger migration did not abort the run (G0-F / H1) |
| `SELECT @@character_set_server` = `utf8mb4` | Amharic does not silently truncate or corrupt |
| `php artisan down` → expect `503` | that `index.php` line 9 was repointed — step 4a's own verification |
| `php artisan schedule:list` | the scheduler is wired |
| `SELECT * FROM jobs` / `failed_jobs` | the queue is being picked up, and nothing fails silently on first contact with real MySQL |

Five of twenty-three undersells it: those five are precisely the checks covering the parts
that **fail silently** — schema, Amharic integrity, maintenance mode, scheduler, queue.
The other eighteen are `curl` and can be run from anywhere.

So B-4 does not only block deployment and database import. It blocks the gate that is
supposed to certify the deployment *before* DNS is pointed at it. Manual action 1 already
decided deployment, import, backup/restore and observability; it decides this too.

## Hosting Information panel — read 2026-09-18

Owner-supplied, from the account's **Hosting Information / Resource Usage** page. Four new
facts, one correction of ours, and one identifier error this repository had been repeating.

| Field | Value | Status here before |
|---|---|---|
| Domain | `ethr.et` | known |
| Username | **`ethret`** | **recorded BOTH ways — see below** |
| Server name | **`lin6.ethiotelecom.et`** | recorded as `line6.…` — wrong |
| IP | `213.55.96.154` | known, now confirmed by a second source |
| Nameservers | **`ns1.telecom.net.et`** and **`ns2.telecom.net.et`** | only `ns2` recorded — "single nameserver" |
| SSL | Valid · **2026-09-16 → 2026-12-15** · issuer **`YR1`** | a cert was seen 2026-08-29, issuer `YR2` |

### The identifier error, and why it is not cosmetic

The account username is **`ethret`**. This repository wrote it **`etrhet`** — the `hr`
transposed — in six files, while *also* writing it correctly in two places, so
`B1-B5_GATE_REPORT.md` contradicted itself between its summary table and the directory
listing thirty lines below.

Two places where the wrong spelling costs something real:

- **`AUDIT_LOG_INTEGRITY_DECISION.md`** instructs: *"ask Ethio Telecom to grant `TRIGGER`
  to the `etrhet` database user."* That is the remedy for **G0-F**, a gate whose failure
  aborts `migrate` by design. A support request naming a user that does not exist buys a
  round trip and no grant.
- **`DEPLOYMENT.md`** steps 3 and 4 put it in five `ssh` and `rsync` command lines.

Corrected in every file except the frozen one. **`DEPLOYMENT.md` still says `etrhet` in
five places** — it is inside `docs/deployment/shared-hosting/` and a username typo is
branch-independent but does **not** block Gate 0, so it fails the freeze exception test.
Those five lines are already inside the rewrite **U-2** describes (they are the `rsync`
and `ssh` steps that no longer work at all on this account), so the correction rides that
rewrite. Recorded here so it is not lost in the meantime.

`line6.ethiotelecom.et` → `lin6.ethiotelecom.et` is the same class of error and was fixed
alongside it.

### What the panel resolves, and what it does not

**It strengthens the wildcard-TLS blocker rather than weakening it.** The zone sits on
Ethio Telecom's own nameservers — **two** of them, `ns1` and `ns2`, where this file
previously said "single nameserver". Plesk does not host the zone, so DNS-01 validation is
not available from the panel, and a **wildcard certificate cannot be auto-issued**. That
was already the reading; it now rests on a second independent source rather than one
`dig`.

**It is evidence that automatic renewal works** — the one genuinely good news here. The
certificate observed on 2026-08-29 was issued by `YR2`; the panel now shows a certificate
valid from **2026-09-16**, issuer `YR1`. A different intermediate and a later start date
means the certificate **rotated on its own** in the interim. A 90-day validity window
(16 Sep → 15 Dec) is the ACME lifetime. That bears on checklist rows **S1** and **S4**.

**Stated as evidence, not as a verified gate.** The panel prints the issuer as `YR1`
without an organisation field, so "Let's Encrypt" is inferred from `YR2` having carried
`O=Let's Encrypt` on 2026-08-29 — strong, but it is inference, and this document's rule is
that inference is not evidence. **S1/S4 stay NOT VERIFIED** pending the *SSL/TLS
Certificates* panel page, which is queue item 8's neighbour.

**It answers nothing else.** No gate moves. This page reports identity and TLS; it says
nothing about Scheduled Tasks, SSH, custom directives, static-file handling, PHP
extensions or the database — which is the entire blocking set.

`lin6` is worth keeping for one practical reason: it is the string to quote in a support
request, alongside the now-correct `ethret`.

## Gate 0 continuation run — 2026-09-18, `main` at `e6ad81c`

Attempted to advance Gate 0 from the repository. **No gate moved, and none could have.**

Every G0 row's evidence source was read from `GATE-0-RESULT.md`'s own Results table:
`panel`, `canary` or `probe`. All three are host-side. **There is no G0 gate whose
evidence can be produced from a checkout**, so a repository session cannot raise the count
above 1/30 no matter how much it verifies. Recorded once here so nobody re-derives it.

What a repository session *can* do is verify the claims the next gate's outcome depends
on, before the panel window opens. Two of them were wrong.

### Two stale counts, both on the path immediately after G0-D

| Claim | Documents said | Actual | Source of truth |
|---|---|---|---|
| Scheduled entries | **11** | **14** | `api/routes/console.php` — 14 top-level `Schedule::` calls, none nested |
| Queued job classes | **15** | **16** | `api/app/Jobs/` — 16 files |

These matter *because* of where they sit. `deploy-checklist.md` makes the first one an
**acceptance criterion** — *"`php artisan schedule:list` shows all 11 entries"* — run
immediately after cron is configured, which is the step G0-D unblocks. An operator reading
14 against a checklist that says 11 either stops to investigate a non-problem, or ticks the
box at 11 and never notices the other three. Both waste the window G0-D exists to protect.

The three entries the figure missed are visible in the file and look like ordinary drift
rather than an error: `ethr:backup` (line 103), the `QueueHealth::beat()` heartbeat
(line 117), and one of the `Schedule::call` closures. The documents were written before
them and never re-counted.

Corrected in the eight editable occurrences across `SHARED_HOSTING_AUDIT.md`,
`PRODUCTION_CHECKLIST.md`, `B1-B5_GATE_REPORT.md`,
`ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`, `SHARED_HOSTING_MIGRATION_PLAN.md` and
this file.

**Three occurrences remain wrong, all inside the frozen directory** — and one of them is
the acceptance criterion itself:

| File | Line | What it says |
|---|---|---|
| `shared-hosting/deploy-checklist.md` | 78 | *"`schedule:list` shows all 11 entries"* — the acceptance criterion |
| `shared-hosting/ENVIRONMENT.md` | 174 | *"that file's 11 entries"* |
| `shared-hosting/DEPLOYMENT.md` | 362 | *"the 11 entries in …"* |

A count being stale is branch-independent but does **not** block Gate 0, so it fails the
freeze exception test, exactly as the `ethret` username correction did. It rides the same
rewrite. **Until then, read 14 wherever those three say 11.**

### Where this run stopped, and why

**G0-D — Scheduled Tasks.** It is the next gate in the MANUAL ACTION QUEUE that is
reachable at all (0a and 0b concern the VPS, which this environment also cannot reach —
the gateway denies `CONNECT` by policy, and a proxy 403 is not a host result). G0-D's
evidence source is `panel`. There is no repository substitute, and inventing one would be
the precise failure this document exists to prevent.

Stopped there. Not marked verified, not marked failed, not marked anything.

### The rest of `deploy-checklist.md`'s repo-side criteria — checked, and clean

Of the checklist's 23 acceptance criteria, **six assert something about a repository
artifact** and are therefore checkable without the host. The scheduler count above was one
of them and was wrong. The other five, and the two route checks, were verified against the
code and **all hold**:

| Criterion | Evidence |
|---|---|
| `robots.txt` carries `Disallow: /admin` **and** a `Sitemap:` line | `src/src/app/robots.ts` — `/admin` in `disallow`, `sitemap: ${SITE_URL}/sitemap.xml` |
| `/sitemap.xml` is XML, not an HTML 404 | `src/src/app/sitemap.ts` present, typed `MetadataRoute.Sitemap` |
| `GET /api/v1/ping` → 200 | `api/routes/api.php:119` |
| `GET /api/v1/health` reports real service state | `api/routes/api.php:121` → `HealthController` (the `:653` one is the *admin* route, a different thing) |
| `ProductionSeeder` ran, **not** `DatabaseSeeder` | both present; `DatabaseSeeder` does create the demo tenant the checklist warns about |
| `GET /api/docs` refused outside local | `config/scramble.php` uses `RestrictedDocsAccess`; `AppServiceProvider:259` documents it |
| `APP_ENV=production`, `APP_DEBUG=false` | `api/.env.production.example` |

So the checklist's repository half is sound; its one defect was the count. The remaining
sixteen criteria need the host and stay unverified.

*Method note, because it nearly produced a false positive:* the first search for the
`ping` route used the pattern `'ping'` and found nothing, because the route is registered
as `'/ping'` with a leading slash. Reported as missing, that would have sent someone
hunting for a route that exists. A grep that finds nothing is not evidence of absence
until the pattern has been checked against the thing it is meant to match.

## G0-D ANSWERED 2026-09-18 — FAIL. There is no Scheduled Tasks section.

**Evidence (owner-read, subscription dashboard).** The account's tool listing shows: Files,
Databases, FTP, Backup & Restore, Website Copying, Statistics, Dev Tools, PHP 8.3.33, Logs,
Git, PHP Composer, Security/SSL, Imunify, Password Protected Directories. It shows **no
Scheduled Tasks, no Task Scheduler, no Cron Jobs**. *Dev Tools* was read separately on
2026-09-17 and holds PHP, Git and Composer — no Terminal, no cron.

Graded **FAIL — strong evidence, one confirmation short**, deliberately matching how G0-A
was graded on the identical pattern: a complete-looking panel page with the one field we
need conspicuously absent. In Plesk, *Scheduled Tasks* is gated by a service-plan
permission. Its absence means **this plan does not grant it**, not that the server lacks
cron — which is why the remedy is a support request, not a code change.

### What this costs, stated plainly

G0-D was carrying four blockers. All four now fail together, and one of them is fatal
rather than degrading:

| | Without cron **and** without SSH |
|---|---|
| **Scheduler** | The **14** entries in `routes/console.php` never run: leave accrual, carry-forward, invoicing, overdue handling, attendance-anomaly and missing-punch scans, scheduled reports, dashboard digests, approval reminders, cleanup, `ethr:backup`, the queue heartbeat |
| **Queue** | The **16** job classes never process. `QUEUE_CONNECTION=database` with nothing draining it means **no queued email is ever sent** |
| **Backup / restore** | `ethr:backup` and `ethr:restore` are plain PHP CLI by design — and there is no CLI |
| **Observability** | `health-check.md`'s two primary checks are SQL, with no verified route to run SQL |
| **Deployment itself** | **`key:generate`, `migrate`, `db:seed`, `ethr:create-admin` have no runner.** This is not a degraded feature. Without it the application cannot be *initialised at all* |

The last row is the one that changes the decision. Every other consequence is "the product
runs badly". That one is "the product cannot be installed".

### The plan's own position on this

`SHARED_HOSTING_AUDIT.md` §"the two questions": *"Is there **cron**? Without it, 14
scheduled entries and all 16 queued jobs stop."* `ETHIO_TELECOM_…_COMPATIBILITY.md` B3
lists the same, ending *"**and no queued email is ever sent**."* `GATE-0-RESULT.md`'s
consequence column says the scheduler and queue *"move behind an authenticated HTTP
endpoint. **Unbuilt; must be costed.**"*

So this outcome was anticipated and costed as a risk. It has now occurred. **Option B is
not performable on this account as currently provisioned** — that is a statement about the
provisioning, not about the plan or the code.

### Three routes out, in the order they should be attempted

**1 — Check Plesk Git → *additional deployment actions*. Free, the panel is already open,
and this file flagged it on 2026-09-17 as the one unknown that could supply an `artisan`
runner.** Plesk's Git extension can run shell commands after each deployment. If that field
exists on this plan it solves the **fatal** row above — `migrate`, `key:generate`,
`db:seed` and `ethr:create-admin` all run once, at deploy time.

It does **not** solve the scheduler or the queue, because deployment actions fire on
deployment, not on a schedule. Treat it as the difference between *cannot install* and
*installs but the asynchronous half is dead*.

**2 — One support request to Ethio Telecom, three asks.** *(Four as of 2026-09-18 — see below.)* Account `ethret`, server
`lin6.ethiotelecom.et`:

- enable **Scheduled Tasks / cron** for this subscription — this is the one that matters;
- enable **SSH access** for `ethret` (clears B-1 and B-4 outright, and gives crontab);
- grant **`TRIGGER`** to the database user (**G0-F**; the audit-log migration aborts the
  entire run by design without it — `AUDIT_LOG_INTEGRITY_DECISION.md`).

*A fourth ask was added 2026-09-18 and is not in the list above because this passage records
what was decided on the day:* **what the tiers above this one include** — PHP versions, Node,
cron granularity and proxy directives often differ by tier, so one question about plans can
bear on G0-A, G0-D and G0-G together. See
[`deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`](deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md),
which is the sendable text and carries all four.

Either of the first two alone substantially unblocks the migration. None is a code change.

**3 — If both are refused, the architecture decision reopens.** `SHARED_HOSTING_MIGRATION_PLAN.md`
already costed the alternatives: **Option A** (stay on the VPS) and **Option C** (shared
hosting plus a small VPS for cron and queue only). Option C is the cheaper of those and
exists in the plan precisely for this outcome. That is an owner decision and nothing here
pre-empts it.

### G0-D continued — *Additional deployment actions* EXISTS (owner-read, 2026-09-18)

Route 1 of the three above was taken and the field is present. **Nothing has been entered,
saved or executed.** This section is analysis against the plan, not a configuration.

*Recorded honestly: the screenshot referenced by the owner did not reach this session. The
finding rests on their direct statement that the field exists, which is observation. Its
options, limits and user context have **not** been seen here.*

#### It satisfies the install requirement. It does not satisfy the asynchronous one.

That distinction is the whole of it, and the plan already draws the line — one-shot work
versus recurring work:

| Requirement | Shape | Deployment actions |
|---|---|---|
| **Install / init (B-4)** — `key:generate`, `migrate --force`, `db:seed --class=ProductionSeeder --force`, `ethr:create-admin` (`DEPLOYMENT.md` step 4) | one-shot, at deploy time | ✅ **yes** — exactly this shape |
| **Schema / import (B-5)** | one-shot | ✅ **yes**, via `migrate`, or `ethr:restore` |
| **Scheduler** — 14 entries | recurring, every minute | ❌ **no** |
| **Queue** — 16 job classes | continuous or repeated | ❌ **no** |
| **`ethr:backup`** | recurring | ❌ **no** — it would run at deploy time, which is not a backup schedule |
| **Observability** (`health-check.md`'s SQL) | ad-hoc / recurring | ⚠️ once per deployment only |

A deploy-time hook cannot be made into a timer without something external triggering
deployments, so the scheduler and queue remain unsolved. **The fatal row is fixed; the
degrading rows are not.** That moves this account from *"ETHR cannot be installed"* to
*"ETHR can be installed and its asynchronous half is dead"* — a real advance, and still
not a deployable product.

#### The larger find: this is a fourth probe route, and it beats Route C

> **SUPERSEDED 2026-09-18 — read this before acting on the section below.** Route D depended
> on the Plesk Git extension being configured, and **the repository was removed from Plesk
> later the same day**, so *there is no Route D*. The analysis is kept because it remains
> correct about what deployment actions can and cannot do, and because Route D returns the
> moment Git is reconfigured. **Route C is the only live probe route** — A is Forbidden, B
> is FAIL, D is withdrawn — which is what makes the probe's token gate load-bearing. See
> *THE PLESK GIT REPOSITORY WAS REMOVED* below.

`GATE-0-RESULT.md` documents three: **A** (SSH — blocked), **B** (Scheduled Tasks — now
**FAILED**), **C** (web-served — "the last resort"). Deployment actions are a **Route D**
the plan never considered, and on this account they are *better than C*: the probe runs
from `~/`, **never web-reachable**, which is the security property the plan insists on and
the only reason C was called a last resort.

Route A's own description says it *"answers everything: G0-E, G0-F, G0-H, G0-I, G0-J and
the storage rows."* Route D reaches the same place — **five gates**, without SSH and
without exposing the probe.

#### Two hazards to settle before anything is configured

1. **The probe exits non-zero by design.** Since `8e71045` it returns `1` on a mandatory
   gap and `2` on other failures — that fix exists precisely so a fatal result cannot read
   as success. If Plesk treats a non-zero deployment action as a failed deployment, a probe
   run may abort later actions or mark the deployment failed. **This is unverified**; the
   panel's behaviour on non-zero exit has not been seen. Output must be redirected to a
   file readable in File Manager, and the exit code neutralised if Plesk is strict.

2. **Triggering a deployment collides with U-5.** Deployment actions fire *on a
   deployment*. The host's checkout is at `716ab93`, ~50 commits behind `main`, and U-5
   says reconciling it before Gate 0 completes *"would deploy an unverified
   configuration."* So running the probe this way is not free — it advances the host.

   **And a prerequisite nobody has established: where does the Git extension currently
   deploy to?** This file already lists it as unknown. If that path is `httpdocs`, a
   deployment overwrites the live document root. **Read the deployment path before
   triggering anything.**

#### One argument worth carrying into the support request

Deployment actions execute shell commands as the subscription user. So the platform
**already runs shell for this account** — `SSH: Forbidden` is a restriction on interactive
login, not on execution. That is a concrete answer to the likely brush-off, and it applies
to the cron ask as much as the SSH one.

### The Git deployment target — read 2026-09-18, and it is pointed at the document root

Owner-read, inspection only, nothing changed:

| Field | Value |
|---|---|
| Deployment path | **`/httpdocs/`** |
| Branch | `main` |
| Mode | manual |
| Path field | **editable, and saving works** |

**This target is unsafe as configured, and it is the most dangerous single setting found in
this engagement.** Deploying `main` into `/httpdocs/` would publish the whole repository at
the web root — `api/`, `docs/`, `scripts/`, `infrastructure/`, `docker-compose.prod.yml`,
the `.env.*.example` files, and **`scripts/hosting-verification/ethr-hosting-check.php`**,
whose own header states it must not be web-reachable because it prints `disable_functions`,
database grants and the filesystem layout. It also contradicts `DEPLOYMENT.md:19`
(`~/ethr/` — *"Laravel app — NOT web-accessible"*), and it puts a first-ever, ~50-commit
cold write on top of `.well-known/acme-challenge/`, which is how the live certificate
renews.

**Deployment actions cannot guard against any of it**: Plesk runs them *after* the
repository files are deployed (documented behaviour, not measured here), so by the time an
action runs the target has already been written.

#### Why repointing to `/ethr/` makes it safe — the four things that had to hold

1. **The repo root maps onto the required layout.** It contains `api/`, `src/`, `scripts/`,
   `docs/`, so a deployment to `~/ethr/` produces `~/ethr/api/` — exactly what
   `DEPLOYMENT.md` §0 specifies.
2. **Nothing would serve it.** **Step 4a has never been performed on the host** — there is
   no repointed `index.php` in `~/httpdocs/`. Files landing in `~/ethr/` are therefore
   *dormant*: present on disk, served by nothing. U-5's objection is to deploying an
   unverified *live* configuration; this deploys an unverified *inert* one, which is a
   different risk and an acceptable one.
3. **The probe stops being web-reachable** — it lands at
   `~/ethr/scripts/hosting-verification/ethr-hosting-check.php`, outside the document root,
   which is the property that made Route D preferable to Route C in the first place.
4. **The probe needs no `vendor/`.** `api/vendor/` is gitignored and therefore absent from
   any deployment. The probe is standalone PHP and runs regardless — which is precisely why
   it can answer G0-E, G0-F, G0-H, G0-I and G0-J before `composer install` has ever run.

`httpdocs/` and `.well-known/` are untouched by a deployment to `/ethr/`.

#### Sequence agreed — three phases, each with a checkpoint

Deliberately not one step. This would be the first deployment this account has ever
performed, so the path change is proved before any command is attached to it.

- **Phase 1 — repoint and prove.** Change the path to `/ethr/`, save, deploy with **no**
  deployment action configured. Confirm `~/ethr/` is populated and `httpdocs/` is
  byte-for-byte unchanged. This also closes **U-5**: the host moves from `716ab93` to
  current `main`, in the correct location.
- **Phase 2 — run the probe once.** Only then attach a single deployment action and deploy
  again. The action must capture the exit code rather than let it decide the deployment's
  fate, because the probe exits non-zero by design (`1` mandatory gap, `2` other failures,
  since `8e71045`).
- **Phase 3 — remove the action.** A standing deployment action that runs a
  grants-and-layout probe is not something to leave configured.

~~**Nothing in phases 1–3 has been performed.**~~

### SUPERSEDED — the deployment had ALREADY run into `/httpdocs/`

**2026-09-18 14:42 host time**, before the sequence above was written. Owner's File Manager
listing shows the entire repository in the document root: `api/`, `docs/`, `scripts/`,
`src/`, `docker/`, `infrastructure/`, `.github/`, `.githooks/`, all `docker-compose.*.yml`,
`CLAUDE.md`, `README.md`, `.env.production.example`, the three PowerShell launchers. The
analysis above described a risk that had already materialised.

**Assessed rather than assumed. No credentials were exposed:**

| Checked | Result |
|---|---|
| A real `.env` deployed? | **No** — none is tracked, so none could deploy |
| `START_BACKEND.ps1` APP_KEY literal? | **No** — removed; the file now only *describes* the past mistake |
| Deployment wiped the docroot? | **No** — it merged. `.well-known/`, `cgi-bin/`, `css/`, `favicon.ico` keep their 25 Jul timestamps, so **certificate renewal is intact** |

**What was exposed is reconnaissance, and one item is materially worse than the rest:**
`httpdocs/scripts/hosting-verification/ethr-hosting-check.php` became **web-executable**.
Requested with no query string it prints `disable_functions`, the filesystem layout, PHP
limits, free space and outbound-network results — its own header says it must never be
web-reachable. (It does *not* leak database grants: that section is skipped unless
credentials are passed as arguments, and anyone able to pass them already has them.)
`canary.php` also deployed but is built to be web-reachable and discloses nothing.
`docs/` exposes the account username, host IPs and the full blocker register.

**Containment, in order:** delete `httpdocs/scripts/` first — it carries both PHP files.
Then remove every entry dated **18 Sep 14:42** and keep every entry dated **25 Jul** (that
timestamp split is exactly the deployed-versus-original boundary). Then repoint the
deployment path to `/ethr/` so a redeploy cannot recreate it.

**What this does to the record:** the host is no longer ~50 commits behind — it is at
`main`, in the wrong place. **U-5 is not resolved, it is inverted.** And this is the second
irreversible action taken on `httpdocs/` without a prior disposability check, after **U-6**.

### Containment — owner File Manager listing, 2026-09-18 ~16:00 host time

The document root has been cleaned and the repository relocated. Recorded from the listing,
which is output; the external HTTP confirmation is **still outstanding**, so containment is
**not** marked VERIFIED.

**What the listing shows resolved:**

| | Evidence |
|---|---|
| `httpdocs/` cleaned | Now holds only `.well-known`, `cgi-bin`, `css`, `ethr.et`. No `api/`, `docs/`, `scripts/`, `src/`, `docker/`, `infrastructure/`, no loose `CLAUDE.md` / `docker-compose.*.yml` / `.env.production.example` |
| **Certificate renewal intact** | `.well-known` survived the sweep — the one thing that must not have been deleted |
| Repository relocated | `~/ethr/` (16:29) now holds `.githooks`, `.github`, `api`, `docker`, `docs`, `infrastructure`, `scripts`, `src` — the layout `DEPLOYMENT.md` §0 requires |
| Deletion route | `.trash` updated 16:01, consistent with File Manager removal rather than a wipe |

A deployment to `/ethr/` therefore **did** happen, and the earlier statement that nothing had
been deployed is superseded by the listing. Recorded as evidence, not as a correction of
anyone.

#### NEW CONCERN — `~/ethr/` carries a document-root signature

`~/ethr/` also contains **`cgi-bin`, `css`, `img`, `test`**, and **none of those four is in
the repository** (checked against `origin/main`). They are the Plesk vhost skeleton — the
same four that sit inside `httpdocs/ethr.et/`, the unidentified object recorded as **B-3**.

The permissions agree:

```
httpdocs   rwx r-x ---   ethret  psaserv     <- known document root
ethr       rwx r-x ---   ethret  psaserv     <- identical
.composer  rwx r-x r-x   ethret  psacln      <- ordinary user directory
git        rwx r-x r-x   ethret  psacln      <- ordinary user directory
```

`psaserv` plus `r-x ---` is Plesk's document-root pattern; ordinary content is `psacln` and
world-readable. **So `~/ethr/` looks provisioned as a vhost root, not as a plain
directory.**

**If any domain or subdomain is rooted at `~/ethr/`, the exposure has moved rather than
ended** — `~/ethr/scripts/hosting-verification/ethr-hosting-check.php` would be reachable
again, at a different hostname. Graded **strong evidence, one confirmation short**, the same
standard applied to G0-A and G0-D.

**The confirmation is one panel page:** *Websites & Domains* — does any domain or subdomain
list its document root as `/ethr` or `ethr`? If none does, `~/ethr/` is inert and
containment is complete pending the HTTP check. If one does, that vhost must be repointed or
removed before anything else.

### Containment — external HTTP check, 2026-09-18

The owner ran the verification ladder from their own machine. Four status codes:

| Request | Status | What it establishes |
|---|---|---|
| `CLAUDE.md` | **404** | The bulk `httpdocs/` cleanup took effect |
| `favicon.ico` | **200** | **Calibration passed** — the vhost serves files from `httpdocs/` and the test method is sound. A 404 here would have meant the whole ladder was measuring the wrong thing |
| `CLAUDE.md` (repeat) | **404** | Confirms the first reading |
| `scripts/hosting-verification/ethr-hosting-check.php` | **200** | **The probe path still answers.** Not 404, not 403 |

Also resolved: the **GitHub deploy key is now read-only**, closing the item recorded above.
A host compromise no longer reaches the repository.

#### The three readings contradict each other, and that is the finding

`favicon.ico` 200 says the served root is `httpdocs/`. `CLAUDE.md` 404 says the deployed
files under that root are gone. Both cannot be true at the same time as a 200 on a path
*underneath* that same root — `httpdocs/scripts/…` — unless one of these holds:

| # | Explanation | How to tell |
|---|---|---|
| **a** | `httpdocs/scripts/` was **not actually deleted**. The File Manager listing that showed it gone was paginated, filtered, or served from a stale view | Response body is the probe's report |
| **b** | The 200 is **not the probe**. Imunify360 and similar WAFs answer with a block page under HTTP 200; so does a parent-directory index | Response body is a block page or an index |
| **c** | The two URLs **hit different vhosts** — `ethr.et` vs `www.ethr.et` vs `production.ethr.et`, which is a real second webspace on this account | Same probe path, both hostnames, compare |
| **d** | The file was **re-created** after the cleanup by a redeployment | Compare mtime in File Manager against the cleanup time |

A status code alone cannot separate these. **The response body can, in one request**, and
that is the next action rather than another round of listings.

The earlier **504** is now readable too: it was never evidence of absence. The probe makes
three outbound calls with 6-second timeouts plus CPU and disk benchmarks, so a gateway
timeout is exactly what a *successful* execution looks like from outside when the host is
slow. 504 then and 200 now are the same observation twice.

#### Grading

**Containment: NOT VERIFIED.** Partially achieved — `CLAUDE.md` is gone and the deploy key
is read-only — but the one file that actually mattered is the one still answering.

**Exposure: treated as LIVE** until the body says otherwise. This is deliberately
fail-closed and matches how the rest of this register grades: explanation (b) would make it
harmless, but (a), (c) and (d) all leave `disable_functions`, the filesystem layout, PHP
limits, free space and outbound-network results readable by anyone with the URL, and three
of four is not where the benefit of the doubt goes.

#### The fix that does not depend on which explanation is right

All four explanations share a precondition: **a copy of the probe in a document root
executes.** That is a defect in the probe, not only in the deployment, and it is now fixed
in the repository rather than only in the host's filesystem:

- `ethr-hosting-check.php` **refuses web execution by default**. A web request returns
  `403` and a 14-byte body regardless of filename. Deliberate web use — Route C — requires
  setting `ETHR_PROBE_WEB_TOKEN` in the uploaded copy and passing it as `?token=`.
- `scripts/.htaccess` denies the whole directory, as a second layer. Second, because it
  only applies if `AllowOverride` permits it — which is **G0-B.3, still NOT VERIFIED**.

Verified by running all three paths: shipped file over a web SAPI → 403/14 bytes; wrong
token → 403/14 bytes; correct token → 200/6,548 bytes, complete report; CLI unchanged and
still honouring the exit contract. `docs/deployment/GATE-0-RESULT.md` Route C carries the
amendment.

This does **not** contain the file already on the host — that copy predates the fix and
still has no token gate. It contains every copy from here on, including whatever a future
mis-pointed deployment does.

#### Still open after this listing

- **The probe response body**, which decides between (a)–(d) above. One request.
- **B-3 is unchanged** — `httpdocs/ethr.et/` is still present and still unidentified, and
  under explanation (c) it is a candidate for what answered.
- **Is any domain or subdomain rooted at `~/ethr/`?** Unanswered from the previous listing
  and still the difference between *relocated* and *moved the exposure elsewhere*.

### The agent session has no network route to the host — measured, not assumed

Recorded once, because it has been implicitly re-tested several times and it bounds
everything else in this file.

The environment these sessions run in reaches the internet through an egress proxy with an
allowlist. A request to the production vhost is refused at the tunnel, before any HTTP
request is formed:

```
$ curl -sS -o /dev/null -w '%{http_code}' https://www.ethr.et/
curl: (56) CONNECT tunnel failed, response 403
```

and the proxy's own status endpoint logs the refusal with its reason:

```json
{ "kind": "connect_rejected",
  "detail": "gateway answered 403 to CONNECT (policy denial or upstream failure)",
  "host": "www.ethr.et:443" }
```

`repo.packagist.org`, `api.github.com` and `git clone` over HTTPS all return 200 from the
same shell, so this is a **per-host policy denial, not a broken network** — the distinction
matters, because a general outage would be worth retrying and this is not.

**The consequence, stated plainly: no agent session can perform the public-exposure checks,
run the canary, or read the Plesk panel.** Every `curl` result in this document came from
the owner's machine and every panel reading from the owner's screen, and that will remain
true. It is not a limitation to be worked around — attempting to would mean routing through
a third party, which is worse than the gap it closes.

This is why the Gate 0 table is mostly `NOT VERIFIED` and will stay that way until the
owner supplies evidence: **29 of its 30 rows have an evidence source of panel, canary or
probe, all host-side.** A repository session cannot raise the count. That is a property of
where the evidence lives, not of how much work is left.

### The freeze, partially lifted — facts corrected, procedures untouched

`docs/deployment/shared-hosting/*` has been frozen since the pause. Three defects had been
recorded against it and deferred on freeze discipline rather than on correctness, each one
"riding the same rewrite" that never came. They are now fixed, because every one of them is
**true independently of any gate outcome**:

| File | Was | Now |
|---|---|---|
| `DEPLOYMENT.md` ×5 | `etrhet@213.55.96.154` | `ethret@…` — the account username, transposed |
| `DEPLOYMENT.md` header | `213.55.96.154` only | adds `lin6.ethiotelecom.et` |
| `deploy-checklist.md:78` | *"shows all 11 entries"* | **14** — and this one is an **acceptance criterion** |
| `ENVIRONMENT.md:174` | *"that file's 11 entries"* | 14 |
| `DEPLOYMENT.md:362` | *"the 11 entries in …"* | 14 |

`DEPLOYMENT.md` also gains a **status banner** recording the two measured facts that
contradict its transport: SSH is Forbidden, so every `ssh` and `rsync` line in it will not
connect; and there is no Scheduled Tasks section, so its one cron line has no runner.

**Two more factual corrections, 2026-09-19**, under the same rule and for the same reason
— the fact is true whatever the gates return:

| File | Was | Now |
|---|---|---|
| `DEPLOYMENT.md:35` | *"confirm the **four facts** in … NEXT ACTION. Two of them (B3 cron, B5 Node.js)"* | Names **G0-G** as the one that still changes which steps apply, and records that **G0-D is answered FAIL** |
| `DEPLOYMENT.md:112` | *"confirm H1 (… NEXT ACTION **#4**)"* | Names **G0-F** and the gate register row |
| `ENVIRONMENT.md:158` | *"still-open panel facts (… NEXT ACTION **#3**)"* | Names the **disk quota** row and the tier ask |

All three were **positional** references into a list, and the list was renumbered when
`NEXT ACTION` was rewritten against the measurements the same day. A reference by position
survives only until the target is edited; these now name the gate, which does not move.
That is the repair, not the renumbering.

**The procedures are deliberately NOT rewritten.** Choosing between Branch A and Branch B
rests on G0-A and G0-G, both `NOT VERIFIED`, and the replacement transport rests on G0-D,
which is `FAIL` pending a support request. Rewriting the steps now would bake a guess into
the runbook — which is the failure this freeze existed to prevent. Correcting a username
carries no such risk. The freeze stands over the procedures; it no longer stands over
demonstrable facts.

### The support request now exists as text — `deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`

Three documents named "one support request, three asks" as the remedy for B-1, B-4 and
G0-F. None of them contained the request. It is now drafted and ready to send:
[`deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`](deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md).

It carries the `ethret` spelling warning, the pre-empt for the likely brush-off on SSH
(*deployment actions already execute shell as the subscription user, so the platform runs
shell for this account; what is asked for is interactive access to the same capability*),
a decline matrix for each of the four outcomes, and an explicit note on what is **not**
asked for — wildcard TLS, which is a DNS question, and G0-A directives, which have a
documented workaround and would weaken the asks that do not.

**Not sent.** Sending it is the owner's action, and it is the highest-value one available.

### The Plesk Git repository was REMOVED — 2026-09-18, owner action

Reported by the owner, to be reconfigured later. Recorded because it changes the register
in three places, and one of them is the worst finding in this engagement.

**The `/httpdocs/` deployment hazard is neutralised.** The configuration that pointed a
`main` deployment at the live document root no longer exists. That setting had already
fired once — it published the whole repository at the web root and made the capability
probe web-executable — and it was still armed as of the last reading. It is now gone, not
merely warned about. **This is the single largest risk reduction on the host side so far,
and it came from removal rather than configuration.**

**Route D is withdrawn.** *Additional deployment actions* was recorded as a fourth probe
route and, more importantly, as the one non-shell way to run `key:generate`, `migrate`,
`db:seed` and `ethr:create-admin` — the fatal row under **G0-D**. It depended on the Git
extension being configured, so with no repository there is no Route D. G0-D's fatal
consequence is therefore **back to having no workaround at all** until either the support
request succeeds or Git is reconfigured. That is a real regression in options, and it is
worth being explicit about rather than letting the risk reduction above obscure it.

**Route C is now the only probe route again.** A (SSH) is Forbidden, B (Scheduled Tasks)
is FAIL, D has just been withdrawn — so the web-served run under a random filename is all
that remains for G0-E, G0-H, G0-I, G0-J and the storage rows. That makes the token gate
added to the probe on 2026-09-18 load-bearing rather than merely prudent: manual action 6
now requires setting `ETHR_PROBE_WEB_TOKEN` in the uploaded copy, and a run without it
returns 403 and nothing else.

**When it is reconfigured, the path is `/ethr/`.** Plesk's deployment path is relative to
the webspace root, so `/ethr/` resolves to `~/ethr/` and yields `~/ethr/api/`, the layout
`DEPLOYMENT.md` §0 requires. It must be set **before** the first deploy: Plesk runs
deployment actions *after* writing the files, so no action can guard the target.

#### One thing left unknown, deliberately

Plesk offers to keep or delete the checked-out files when a repository is removed. Which
option was taken here was not reported, so **whether `~/ethr/` still holds the repository
is unknown.** It is not inferable — both outcomes are ordinary — and it decides whether
reconfiguring is a fresh clone or a re-attach. One look at *File Manager* answers it.

The related question from the previous listing is still open and now matters less: whether
any domain or subdomain is rooted at `~/ethr/`. If the files were deleted, that exposure
path is closed by accident; if they were kept, it is unchanged.

**No gate moved.** G0-D stays **FAIL** — removing a Git repository does not create a
scheduler. Gate 0 remains 1 verified, 1 failed, 28 outstanding.

### Repository-side verification run — 2026-09-18, what was actually executed

Distinct from the Gate 0 table, which is host-side and unmoved. These are the claims a
checkout *can* settle, and they were settled by running things rather than reading them.

| Check | Result | How |
|---|---|---|
| **Tenant-scope bypass inventory** | **161 sites / 55 files — matches the pin exactly** | The test's own scan logic (`app/`, `/withoutGlobalScopes?\s*\(/`) replicated in plain PHP, no vendor needed |
| **The 5 bypasses added since the 2026-09-16 audit** | **all carry a predicate** — audited individually | ±8-line read, per `BASELINE.md` §11c's method |
| **Backend suite (Pint, PHPStan, Pest)** | **PASS** | CI run #230, job *Backend* |
| **Backend suite on MariaDB** | **PASS** | CI run #230, job *Backend suite on MySQL* — a real MariaDB service, not SQLite |
| **Frontend (i18n, Prettier, ESLint, tsc, Vitest)** | **PASS** | CI run #230 on Node 24 |
| **API contract (OpenAPI drift)** | **PASS** | CI run #230 |
| **`npm audit --omit=dev`** | **0 vulnerabilities** | run here, 2026-09-18 |
| **Tracked secrets** | **none** | no `.env` (only `*.example`), no key material, no `base64:` `APP_KEY` literal anywhere |
| **`src/.env.production` is tracked** | **correct, not a finding** | every key is `NEXT_PUBLIC_*` or `NEXT_TELEMETRY_DISABLED` — compiled into the client bundle by definition, and the file says so |

**A local Vitest run here failed 2 of 583 tests** — `employees-import.test.tsx`, the
multipart upload. That is **not a regression**: this container runs Node 22.22.2, the repo
pins **24** in `.nvmrc`, and root `CLAUDE.md` §5 already documents MSW's Node interceptor
never settling a `multipart/form-data` request on Node 20 or 22. CI on Node 24 passes the
same file. Recorded because a future session on a non-pinned Node will see it again.

**The bypass count had drifted in the documentation.** Root `CLAUDE.md` and
`BASELINE.md` §15 row 11 both said **156 across 53 files**, the figure from when the pin was
built. The pin is now **161 across 55**, moved by three commits — `716ab93` and `748dcb9`
(the queued-context tenant fixes) and `71db6da` (the platform-admin plan catalog). Both
documents corrected, with the original figure kept as the dated measurement it was.

**Five bypasses had therefore never been individually audited**, because they entered after
the only pass that read them line by line. All five were read on 2026-09-18 and all five
hold — two platform-admin surfaces behind `admin.manage` plus `EnsurePlatformContext` and
`RequirePlatformMfa`, one deriving `tenant_id` from a tenant-owned device, two in
`DispatchWebhookJob`. One inconsistency is recorded in `CLAUDE.md` rather than changed:
`handle()` states `tenant_id` explicitly where `failed()` relies on `webhook_id` alone.
Both hold; the disagreement between two halves of one class is the shape a later defect
takes.

### The backend suite could not be run in this container — cause identified

Recorded so it is not retried. `composer install` fails here regardless of flags:

```
[403] https://api.github.com/repos/phpstan/phpstan/zipball/…
Could not authenticate against github.com
```

The egress proxy blocks GitHub **archive** endpoints — `codeload.github.com` returns 403
directly — and composer reads that 403 as an authentication failure. `git clone` over
HTTPS works, and `repo.packagist.org` returns 200, so `--prefer-source` gets further but
still dies on packages that are dist-only. `npm ci` works, because `registry.npmjs.org` is
on the proxy's bypass list.

**This is why CI is the evidence for every backend row above, not a local run.** CI has
unrestricted egress and runs the same `gates.sh`.

## PLESK COMPATIBILITY PASS — 2026-09-18

Execution overlay: make the repository Plesk-shared-hosting compatible *by default*, with
account configuration left to the owner. Repository-side only; no gate moved.

### The composer manifest declared no PHP extensions at all

`api/composer.json` required `php: ^8.2` and **not one `ext-*`**, with no `config.platform`,
while Gate 0 lists 18 as mandatory. Cross-referencing `composer.lock` shows why that mostly
did not matter — and exactly where it did:

| | |
|---|---|
| Declared by a production package | 15 — `composer install` has always aborted without them |
| **Declared by nothing** | **`pdo`, `pdo_mysql`, `gd`** |

Those three are the whole risk. `pdo`/`pdo_mysql` absent means no database at all; `gd`
absent is **silent**. `FileStorageService::stripExif()` opens with
`if (! extension_loaded('gd')) { return $content; }`, so on the primary upload path EXIF
stripping returns the original bytes — employee photographs and identity documents keep GPS
coordinates, device serials and capture timestamps, served back that way, with no error and
no log line. On shared hosting that is one unchecked box in a PHP settings page.

**All three are now declared in `api/composer.json`.** `composer install` on the host aborts
rather than installing an application that fails later. `composer.lock` was updated to match
— without network access, by computing composer's `content-hash` locally and **validating the
algorithm against the known-good pre-change hash first** (`1796435c…`, reproduced exactly)
before applying the new one. `composer validate` is clean on manifest and lock. CI's
`setup-php` already installs all three, so nothing new is required there.

### Two entries in the mandatory list were wrong

| Entry | Finding |
|---|---|
| **`bcmath`** | **Not required.** Appears in `composer.lock` four times, every one under `suggest`, never `require`. Zero `bc*` calls in the application — money is integer minor units (`salary_cents`, `price_cents`). We were about to ask Ethio Telecom to enable something nothing uses |
| **`zip`** | **Mis-stated.** `BackupService` needs `phar` **or** `zip`: it tries `PharData`, falls back to `ZipArchive`, and throws a `RuntimeException` naming the remedy if neither exists. Neither is mandatory alone; the pair is. `phar` — the *first* choice — was on neither list |

Mandatory is now **18**, which incidentally reconciles `GATE-0-RESULT.md:189` ("18") with
its own results table ("20") — an internal contradiction that had been sitting in the
document.

### The production env template would not have booted here

`api/.env.production.example` is a `docker-compose.prod.yml` artifact — its own header says
so — and `DEPLOYMENT.md` step 4 says `cp .env.production.example .env`. On this account that
copy yields:

```
CACHE_STORE=redis          QUEUE_CONNECTION=redis     SESSION_DRIVER=redis
BROADCAST_CONNECTION=reverb  FILESYSTEM_DISK=minio
REDIS_HOST=redis           MINIO_ENDPOINT=http://minio:9000
```

`redis` and `minio` are **Docker service names**. There is no Redis, no MinIO and no Reverb
daemon on this account, and those hostnames resolve to nothing outside a compose network.

**`api/.env.shared-hosting.example` added.** Identical key set — verified by diffing the
sorted key lists, no variable dropped — with each conversion marked `# [shared-hosting]`
and its reason, and the VPS-specific prose replaced rather than left to contradict.

`BROADCAST_CONNECTION=null` rather than `reverb` is deliberate: with `reverb` set and nothing
listening a broadcast **throws**, so a successful write returns 500. `null` degrades the
feature instead of breaking the write.

**The VPS template is unchanged on purpose.** It is still correct for the rollback target,
and rollback is not retired.

### `docs/deployment/PLESK-SETUP.md` added

The overlay's responsibility split, per section: what is true of the code, and what the
owner clicks. It does not restate the frozen `ENVIRONMENT.md` / `DEPLOYMENT.md`.

### A defect this session introduced, caught by cross-checking rather than by CI

The first version of `api/.env.shared-hosting.example` set `CACHE_STORE=file` and
`SESSION_DRIVER=file`. Both were wrong, and neither the drift-guard test nor CI would have
caught them — they are not `redis`, so every assertion passed.

**`ENVIRONMENT.md` D-8 had already decided this, and `CACHE_STORE=database` was
LIVE-VERIFIED against real MariaDB on 2026-08-31.** The reason is specific:
`config/database.php`'s `cache_locks` table is what Laravel's database cache driver needs
for `Cache::lock()` — the primitive `->withoutOverlapping()` uses internally, at **8 call
sites in `routes/console.php`**. `file` moves those locks onto an implementation nobody
verified, for no gain. The same verification run exercised `RateLimiter` behind all ten
named limiters: 32 requests against a 30/min limit, 30 allowed, 2 blocked.

Corrected to `database` for both. The lesson is the generalisable part: **forbidding the
wrong value is not the same as pinning the right one.**

**Then the same mistake surfaced a second time, in a worse place.** A systematic diff of
the template against *every* `+` line in `ENVIRONMENT.md` — not just the driver block —
found two more, both from **D-5**:

| Key | Prescribed | Template shipped |
|---|---|---|
| `APP_URL` | `https://www.ethr.et` | `https://ethr.et` |
| `CORS_ALLOWED_ORIGINS` | `https://www.ethr.et` | `https://ethr.et` |

D-5 rests on measured evidence: **Plesk already 301-redirects the bare apex to
`www.ethr.et`** (verified, `B1-B5_GATE_REPORT.md`), and `www` is already in
`Tenant::RESERVED_SUBDOMAINS`. Both values were inherited unexamined from the VPS template.

**The CORS one is the dangerous shape.** A mismatch between `APP_URL` and
`CORS_ALLOWED_ORIGINS` is not a server error — it is the *browser* refusing every API call
while the server logs nothing and the health endpoint stays green. `APP_DOMAIN` correctly
stays `ethr.et` (tenancy root, not canonical host) and `SANCTUM_STATEFUL_DOMAINS`'s
`*.ethr.et` already covers `www`.

**So the guard was rewritten to stop restating values at all.** It now parses
`ENVIRONMENT.md`'s own diff blocks and compares every `+` line against the template, which
keeps the source of truth in one file — hardcoding the list is exactly how the first two
drifted. It asserts the parse found something first, for the same reason
`TenantScopeBypassInventoryTest` does: a pattern that silently matches nothing would make
the test pass while checking nothing.

Eight prescribed values, all matching, with `BROADCAST_CONNECTION` as the single documented
exception below. Mutation-checked: reverting `APP_URL` to the apex fails.

### One deliberate divergence from D-8, recorded rather than hidden

D-8 says `BROADCAST_CONNECTION=log`; the template says `null`. Kept, for four reasons:

- `SystemHealthService::broadcastStatus()` treats anything outside `['reverb','pusher']` as
  `disabled`, so both report identically
- `config/broadcasting.php:25` coalesces to `'null'` as its own fallback — it is the
  config's stated intent
- CI already sets `BROADCAST_CONNECTION: "null"`, so it is a tested value; `log` is not
- `log` writes a line per broadcast against a **fixed shared-hosting disk quota**, and when
  that quota fills every write path fails, including the database's

`BroadcastConnectionConfigTest` exists because `null` was once a trap — `env()` parses the
literal `"null"` into PHP null and `BroadcastManager` threw *"Broadcast connection [] is not
defined"*. That is fixed and pinned, which is why `null` is now the safer of the two.

### Application code audit — clean, and that is the finding

The overlay's §6 list was carried into `api/app`, `api/config` and `api/bootstrap`. Nothing
needed changing, which is worth recording so the sweep is not repeated:

| Checked | Result |
|---|---|
| Hardcoded VPS absolute paths (`/var`, `/opt`, `/srv`, `/home`, `/etc`) | **None.** The single hit is `MAIL_SENDMAIL_PATH`'s `/usr/sbin/sendmail` default — standard, env-overridable, and unused while `MAIL_MAILER=smtp` |
| Docker service names as **config defaults** | **None.** `config/database.php:115` and `config/broadcasting.php:35` both default to `localhost`. The Docker names existed only in the env template and the compose files |
| **Horizon** | **Already removed**, and deliberately: `SystemHealthService` records that it hard-required `ext-pcntl` and `ext-posix`, *"which shared hosting does not provide"*. `HORIZON_PREFIX` in the env template is vestigial |
| Health checks | Already config-driven — `broadcastStatus()` reads `config('broadcasting.default')`, `storageStatus()` resolves `config('filesystems.default')` rather than a hardcoded disk name |
| `QueueHealth` | Already written *for* this target: its docblock describes shared hosting with no Supervisor and one Plesk Scheduled Task, and what stops when that entry is silently disabled |

So the de-VPS work on the application itself was done in earlier passes. What was left
undone was the **manifest and the environment template** — the two files that decide whether
the application can be installed and booted at all, and both are now fixed.

One cross-check on this session's own change: `broadcastStatus()` treats anything outside
`['reverb','pusher']` as `disabled`, so the `BROADCAST_CONNECTION=null` chosen for the
shared-hosting template reports correctly. `null` is also what CI already sets, so it is a
tested value rather than a guess.

### The runbook still pointed at the wrong environment file

`DEPLOYMENT.md:133` says `cp .env.production.example .env`. Adding a correct template does
not help anyone who follows the runbook, so the status banner now names
`api/.env.shared-hosting.example` explicitly. That is a factual correction — *which file* —
not a procedural rewrite, and stays inside the freeze rule applied earlier.

### VPS artifact audit — conclusion: NOTHING REMOVED, and why

The inventory of 2026-09-17 stands and was not re-derived. Re-reading it against the
overlay's removal mandate, the answer is the same and the reason is worth stating plainly
rather than reading as evasion:

- **The VPS is the rollback target.** `shared-hosting/rollback.md` depends on it being live.
- **B-6 is unresolved** — nobody has established whether the VPS still serves, or whether it
  holds the only copy of tenant data. `tin` and `national_id` are `encrypted` casts and
  off-host backup was never configured, so its `APP_KEY` may be the only thing that can read
  them.
- **Gate 0 is 1 verified, 1 failed, 28 outstanding.** The replacement target has no verified
  capability at all.

Deleting the rollback target's deployment tooling in that state would be destructive, and the
overlay's own precondition — *"after the existing migration plan has been fully implemented
and verified as far as possible"* — is not met.

**The trigger condition is recorded so this is a checkpoint rather than an opinion.** VPS
artifacts become eligible when: B-6 answered (VPS serving state and data content known), the
application verified running on the Plesk host, and Gate 0's blocking rows closed. The one
candidate that is ready *on other grounds* is `RUN_ALL.ps1` / `START_BACKEND.ps1` /
`START_FRONTEND.ps1` — wrong rather than VPS-only — and the 2026-09-17 inventory already
called that an owner decision. It still is.

### What did not change

No gate moved. G0-A, G0-B.1–B.5, G0-C, G0-F, G0-G, G0-H, G0-I and G0-J are still
unverified, and G0-E is still a version-only panel reading. **Gate 0 stands at 1 verified,
1 failed, 28 outstanding.** Nothing is deployed to a serving path.

---

## VPS ARTIFACT INVENTORY (classification only — 2026-09-17)

**Nothing here is removed, and nothing here is scheduled for removal.** This is step 1 of
the owner's cleanup rule: *inventory and classify first; remove only confirmed VPS-only
artifacts, and only after shared-hosting migration evidence exists.* No migration gate is
verified, so **no artifact is eligible for removal yet**. The VPS remains the rollback
target (`docs/deployment/shared-hosting/rollback.md` depends on it being live).

Derived by reading which files CI and `gates.sh` actually invoke, not by pattern-matching
on the word "docker".

### Required by ETHR — must survive the migration

| Artifact | Why |
| --- | --- |
| `scripts/gates.sh` | **The trap.** It greps as Docker-related, and it is the quality gate CI itself invokes — `.github/workflows/gates.yml` and `security.yml` both call it rather than restating the gates, specifically so the two cannot drift. Removing it removes CI. |
| `scripts/api-types-check.sh` | Called by CI directly *and* by `gates.sh`'s `api_types_gate`. |
| `scripts/docs-link-check.js` | `gates.sh`'s `docs_gate`. |

### Shared-hosting compatible — Docker is a fallback path, not a requirement

| Artifact | Why |
| --- | --- |
| `scripts/pest-isolated.sh` | Native-first since the CI fix; the container is the fallback branch. Runs fine with no Docker present. |
| `scripts/phpstan-isolated.sh` | Same shape, same fix (CI cause 3). |

### Definitely VPS-only

| Artifact | Note |
| --- | --- |
| `infrastructure/nginx.conf` | **The second trap.** VPS-only as *configuration*, but it is the live evidence base for the current document-root and public-routing architecture: it roots three server blocks at `api/public`, which is *why* `api/public/robots.txt` exists and why copying it into the merged shared-hosting document root is wrong. Cited by `DEPLOYMENT.md` step 4a and by `api/tests/Feature/document-root-inventory.php`. Do not remove it without first relocating that evidence, or those two artifacts lose their justification. |
| `infrastructure/nginx-common.conf` | Security headers and rate limits; translated into the deployment `.htaccess` and `nginx-directives.conf`. |
| `infrastructure/supervisor.conf` | Process management — no equivalent on shared hosting (cron replaces it, gate G0-D). |
| `infrastructure/certbot-webroot/` | Superseded by Plesk's own Let's Encrypt integration. |
| `docker-compose.prod.yml` | The 13-service production stack. |
| `scripts/deploy.sh`, `backup.sh`, `restore.sh`, `rollback.sh`, `setup-replication.sh`, `init-storage.sh`, `prod-build-test.sh`, `api-reload.sh` | All assume Docker + SSH into containers. Risk R10 already records that this tooling needs rebuilding under Option B. |
| `docs/VPS_DEPLOYMENT.md` | Reference architecture for the source/rollback environment. |

### Uncertain — needs an owner decision, not a judgement call

| Artifact | The question |
| --- | --- |
| `docker-compose.yml`, `.override.yml`, `.lowmem.yml`, `.hostnames.yml`, `.test.yml` and `docker/*` | These are **local development and E2E**, not production. Shared hosting does not replace a local dev loop, and `CONTRIBUTING.md` / `LOCAL_SETUP.md` document them as how you run the project. Keeping them costs nothing; removing them costs every contributor. Flagged as VPS-adjacent rather than VPS-only. |
| `scripts/seed.sh`, `scripts/run-e2e.sh` | Same category — developer tooling that happens to drive containers. |
| `RUN_ALL.ps1`, `START_BACKEND.ps1`, `START_FRONTEND.ps1` | Already known-wrong: they predate the Docker setup, `RUN_ALL.ps1` prints "SQLite" while the stack is MariaDB, and none starts the queue worker or Reverb. Candidates for removal on *correctness* grounds independent of the migration — which is a different argument from "VPS-only", and should be decided as one. |

### What must be true before any of this is removed

The owner's twelve-point objective, none of which is met: ETHR running on the real Plesk
host, Laravel/PHP/DB compatibility, document roots and public entry points, `.htaccess`
behaviour, environment/config, authentication, tenant isolation intact, cron/scheduler,
storage/file handling, no regression in critical workflows, `dev.ethr.et` / `ethr.et`
routing, and HTTPS/SSL — each verified **through the actual account**. Then: re-run the
tests, update the migration documentation, and make the removal its own checkpoint.

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
| R1 | No wildcard subdomain → self-service signup creates unreachable tenants | **Critical** | **RESOLVED, PASS.** DNS wildcard confirmed externally; Plesk confirmed by owner to accept `*` as a subdomain name. |
| R2 | No wildcard TLS → `SESSION_SECURE_COOKIE=true` withholds the auth cookie | **Critical** | **Downgraded to Medium.** Wildcard itself still unverified, but a working per-hostname Let's Encrypt cert already exists on this account, proving the HTTP-01 per-tenant fallback works — so the *fatal* form of this risk (no valid TLS at all) no longer applies; what remains is whether the fallback needs per-signup automation. |
| R3 | No cron → 14 scheduled entries and all queued work stop | **Critical** | Unverified — still the top open item |
| R4 | `CREATE TRIGGER` denied → audit log not immutable | **Critical** | Still unverified live, but the code-side risk is resolved: D8 reverted the mitigation back to fail-fast-with-diagnosis, so a denial now blocks deployment loudly rather than degrading the guarantee silently. |
| R5 | `max_execution_time` too low for payroll and imports | High | Unverified |
| R11 | **Shared hosting may cost more than the VPS** | **High** | Public data contradicts itself; unresolved |
| R12 | **Subdomain caps (5/10) on lower tiers** are structurally incompatible with SaaS tenancy | ~~High~~ **Moot** | A wildcard vhost is *one* Plesk entry, not one per tenant — R1's resolution makes this cap irrelevant regardless of tier. |
| R6 | Document root not editable → cannot point at `api/public` | Medium | Unverified |
| R7 | `ext-gd` absent → image compression and thumbnails degrade **silently** | Medium | Unverified; probe covers it |
| R8 | Outbound HTTPS blocked → SMTP, webhooks, SMS, Sentry, device polling fail | Medium | Unverified; probe covers it |
| R9 | Storage quota exhausted by photos, documents and tenant backups | Medium | Depends on tier |
| R10 | Existing deploy/backup tooling all assumes Docker + SSH | Medium | Would need rebuilding under Option B |

---

## FILES CHANGED

Everything below is **committed and merged to `main`** (`2eb2e42`), not a working-tree
diff — see the commit list under CURRENT PHASE for the exact `--no-ff` merges.

**Hosting migration (Phase A, `12f53de`):** `HealthCheckCommand.php`,
`HealthController.php`, `FileStorageService.php`, the audit-log trigger migration
(fail-fast + diagnosis), `phpunit.xml`, `tests/bootstrap.php`, plus
`InfrastructureAgnosticTest.php` (new) and 6 `docs/*.md` files.

**Tax proclamation (`12f53de`):** `TaxCalculator.php`, `PayrollEngine.php`,
`TaxBracketSeeder.php`, migration `2026_08_29_000001_…`, `TaxCalculatorTest.php`
(rewritten), `PayrollRulesEngineTest.php`, `PayrollAllowanceTest.php`,
`PayrollConfigTest.php`.

**Plan-tier gating (`2826b0d`):** `PlanFeature.php` (new enum),
`PlanFeatureService.php` (new), `RequiresPlanFeature.php` (new middleware), `Plan.php`
(`@property` block added), `routes/api.php` (5 write routes + 1 read route gated),
`lang/{en,am}/billing.php`, `PlanFeatureGateTest.php` (new), `generated.ts`
(regenerated — one field narrowed from `unknown[]` to `string[]`).

**Dead-code removal (`2eb2e42`):** `EnforceTrialExpiration.php` deleted,
`PHASE_01.md` corrected.

**Docs corrected throughout:** `DEPLOYMENT.md` (×2 rows), `ENTERPRISE_ROADMAP.md`
(4.6), `CLAUDE.md` (Git-ban section).

Nothing in the working tree is uncommitted as of this update.

---

## TEST RESULTS

### Backend — baseline, before any of this session's edits

```
Tests:    1647 passed (4896 assertions)
Duration: 325.31s      Exit: 0
```

### Backend — current, after all three merges

```
Tests:    1669 passed (4957 assertions)
Duration: ~245s        Exit: 0
Collection check: 140/140 test classes
```

**Zero regressions across the whole session.** All 9 gates green on every merge point
(Pint, PHPStan level 6, Pest, i18n, Prettier, ESLint, tsc, Vitest, API-contract) —
confirmed fresh after the final dead-code removal, not carried over from an earlier run.

`AuditLogImmutabilityTest` (4 tests) passing at every checkpoint confirms the trigger
path keeps working wherever the privilege exists.

All runs used `scripts/pest-isolated.sh`. Running `pest` directly in the container
collects a fraction of the suite over the Windows bind mount and still exits 0.

### Live verification beyond Pest

Both the payroll fix and the plan gate were additionally driven against the real
stack — real MariaDB, real Redis, real Sanctum sessions — not only the SQLite test
suite. Details in "PARALLEL WORK MERGED TO MAIN" above. This followed the
[[ethr-green-tests-hide-bugs]] memory: this codebase has a track record of bugs that
pass Pest and fail on first real use.

### Probe self-test (hosting verification script, unrelated to the above)

`scripts/hosting-verification/ethr-hosting-check.php` run against the dev stack:
correctly reported PHP 8.2.33, all 18 mandatory extensions present, MariaDB
10.11.18, `CREATE TRIGGER` **permitted** (`log_bin=0`), and per-table `GRANT`
**denied** (`1142`) — the last confirming that compensating control A1 is unavailable
even to our own dev database user.

---

## NEXT ACTION

**Rewritten 2026-09-19.** This section predated G0-D being answered and predated the
probe reaching the host. It told the owner to ask questions that have since been
measured, and to upload a file by a route that does not exist. The corrections are
named rather than silently applied, because this is the section a reader jumps to and
a wrong instruction here costs more than a wrong one anywhere else in the file.

| What it said | What the measurements say |
|---|---|
| *"All quick, ~10 minutes total"* | Not quick. **G0-D FAILED** — no *Scheduled Tasks* section exists. With no cron and no shell there is **no route on this account that runs `artisan`**: no `migrate`, no `key:generate`, no scheduler, no queue worker |
| *"1. B3 — Scheduled Tasks: present?"* | **Answered — FAIL** (owner-read 2026-09-18). Do not re-ask |
| *"4. Upload the probe from the home directory and run it"* | **Do not.** Nothing on the account can run it, and it is already **on** the host — the Git deployment put it under `httpdocs/`, and its URL last measured **HTTP 200**. The open question is containment, not deployment |
| *(no mention of a support request)* | [`deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`](deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md) now exists, drafted and **unsent**. Sending it is the highest-value action available |

What still holds from the old text: **nothing further is executable without external
input.** The repository-side pass is closed — see *PLESK COMPATIBILITY PASS — 2026-09-18*
above. Every item below needs the Plesk panel or the owner's own machine.

### In priority order

1. **Read the Plesk *Node.js* page — G0-G.** The highest-value panel read, because it is
   the one that decides whether the frontend needs architectural work or none at all.
   Next.js 16's floor is Node 20.9; this repo pins **24** in `.nvmrc`. If Node is present
   at a usable version, Branch A (`output: "standalone"`) stands and the frontend needs
   no change. If it is absent, the fallback is Branch B — and Branch B is **not** "four
   small route changes". Measured at commit `7aed9d2`, it is an architectural frontend
   deployment change; `SHARED_HOSTING_AUDIT.md` §E carries the evidence.
2. **Send the support request.** Four asks: cron, SSH, the `TRIGGER` grant (G0-F), and
   what the higher tiers actually include. **B-1**, **B-4** and **G0-D** all turn on the
   answer, and three of them have no other route.
3. **Return the probe URL's response headers and its first body line.** One request
   separates explanations **(a)–(d)** above, which no further status code can. Until it
   is answered, containment stays **NOT VERIFIED** and exposure stays graded **LIVE**.
4. **Deploy the canary** — `scripts/hosting-verification/htaccess-canary/`, five files,
   six fetches. It answers **G0-B.1 through G0-B.5** with no shell, no cron and no
   support ticket, which makes it the only gate group whose route is fully open today.
5. **B-6** — `curl -sI http://91.99.81.71/`. Is the VPS still serving, and does it still
   hold data? Nothing is removed from the VPS inventory until this is answered.
6. **B-3** — identify in the panel what created `httpdocs/ethr.et/` before removing it.
   Deleting a vhost is not deleting a folder.
7. **When Plesk Git is reconfigured**, set the deployment path to `/ethr/` **before** the
   first deploy. Setting it afterwards is precisely what put the repository under the
   document root and produced item 3.

Two items from the old list survive unchanged. **B1b is answered** — the owner confirmed
Plesk accepts the literal name `*` for *Add Subdomain*; do not put that question again —
but **G0-C is not closed**, because the vhost has never been created and no panel output
was recorded, and this register grades from output. **Bronze's quotas** still matter only
for tier sizing, and D2 keeps us on Bronze regardless.

The audit-log question (item 7 in an earlier version of this file) remains resolved by
**D8**: the migration aborts with a diagnosis if `CREATE TRIGGER` is denied rather than
degrading silently. If G0-F comes back negative the response is ask 3 above, not a code
change.

### Gate 0 as it stands

Of the 30 rows in `deployment/GATE-0-RESULT.md`: **1 VERIFIED**, 1 PANEL-READ, 2 PARTIAL,
**1 FAIL**, **25 NOT VERIFIED**. That ratio is the honest summary of this workstream — the
repository is prepared and internally consistent; almost nothing about the host is known.
