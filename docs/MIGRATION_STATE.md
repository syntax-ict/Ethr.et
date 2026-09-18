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
The account IP (`213.55.96.154`) and username (`etrhet`) are already known — see
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

TARGET:  etrhet @ line6.ethiotelecom.et / 213.55.96.154 (Linux Bronze, Plesk)

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
| **Status** | Closed. PR #19, branch `claude/ethr-migration-continue-0xhhnt`. |
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
correctly blocked on the same four facts it always was. See NEXT ACTION.

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

Held, not fixed: `ROLLBACK_RUNBOOK.md` is not frozen and Scenario A's premise is provably
wrong, so it *could* be corrected now. It is not, because the correct replacement text
depends on answer 1 — "roll back to a working VPS" and "there is no rollback target" are
different documents, and writing one before knowing which would mean writing it twice.

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

The mechanism is the audit-log triggers. `SHOW CREATE TRIGGER` returns
``CREATE DEFINER=`root`@`localhost` …`` verbatim — confirmed in that document, not
assumed — and that is what `mysqldump` and the Plesk panel export write into a dump file.
Restoring it as a non-`SUPER` user **stops dead at the trigger statement**, because naming
a definer other than yourself needs `SUPER`. `DatabaseDumper` emits no `DEFINER` clause
precisely to avoid this, which is why `ethr:backup` exists as a path that does not have
the property.

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
| **U-1** | **None of this session's corrections are on `main`.** PR #19 and #20 are green and unmerged, so `main` still carries: §0's file list telling you to copy `robots.txt` into the document root, no step 4a at all, an 18-extension probe list **missing `simplexml`**, Node "≥ 20.9", a canary without `shadow.txt`, and no manual action queue. An operator working from `main` today gets the defective procedure. | Merging is the owner's decision, not mine | Merge #19, then #20 |
| **U-2** | **`DEPLOYMENT.md` steps 3 and 4 describe a procedure nobody can perform here** — `rsync` over SSH, `artisan` over SSH — on an account where SSH is Forbidden | Freeze discipline. The exception test is *branch-independent **and** blocks Gate 0*; this is branch-independent but does not block Gate 0. Rewriting before the SSH question resolves means writing it twice | SSH granted → steps stand as written. SSH refused → rewrite around Git + Composer + a task runner |
| **U-3** | **B-4 — nothing can run `artisan`.** `key:generate`, `migrate`, `db:seed`, `ethr:create-admin` have no runner. The Git route delivers code and Composer delivers `vendor/`; neither executes anything | External capability, not a repository defect | Manual queue #1 (command-type Scheduled Task) or #2 (SSH) |
| **U-4** | **B-3 — `httpdocs/ethr.et/` is an unidentified Plesk object** with its document root inside `httpdocs/` | Identification needs the panel. Deleting a vhost is not deleting a folder, so it is not being touched on a guess | Manual queue #5 |
| **U-5** | **The host carries a Git deployment at `716ab93`, 47 commits behind `origin/main`** | Not reconciled, and reconciling it before Gate 0 would deploy an unverified configuration | Gate 0 completing, then a deliberate first deployment |
| **U-6** | **`httpdocs/public/` and `httpdocs/et/` were removed without the disposability confirmation `B1-B5_GATE_REPORT.md` required** | Irreversible. `et/` was recorded empty; `public/` was never inspected | Nothing — recorded as a permanent gap rather than quietly dropped |

**U-1 is the one with a deadline.** Every other entry waits on evidence or on a decision
with no cost to delay. U-1 degrades: the longer the corrections sit on branches, the more
likely someone runs Gate 0 from `main` and gets the four-check canary, the missing
`simplexml` row, and the instruction to copy `robots.txt` into the document root.

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

## MANUAL ACTION QUEUE — the only things that still need a human in Plesk

Everything resolvable from the repository has been done. These eight remain, in dependency
order. Each says where to click, what to bring back, whether to change anything, and which
gate it unlocks. **Do not do 8 before 5.**

| # | Action | Plesk location | Bring back | Change anything? | Unlocks |
| --- | --- | --- | --- | --- | --- |
| **0a** | **Is the VPS still serving?** — *no Plesk needed* | n/a — `curl -sI http://91.99.81.71/`, check 80/443 | Whether anything answers | No | **B-6.** Decides whether a rollback target exists at all. **Highest priority in this table** |
| **0b** | **Does the VPS hold real tenant data?** If yes, dump it **and preserve its `APP_KEY`** off the machine before touching it — `tin` and `national_id` are `encrypted` casts, and off-host backup was never configured — *no Plesk needed* | n/a — this is a question about the VPS | Yes/no. If no: the deployment is a fresh start | No | Collapses **B-5** into B-4 and makes half of `DATABASE_MIGRATION_PLAN.md` not apply. **Do this first — it is free and it may remove work** |
| **1** | **Scheduled Tasks capability** — **and, on the same visit, whether a database UI (phpMyAdmin) exists** | Websites & Domains → *Scheduled Tasks*; then look for a *Databases* section | Task types offered ("Run a command" / "Fetch a URL" / "Run a PHP script"), minimum interval, full path to the PHP binary. Plus: is there any web UI that can run SQL? | No | **G0-D** — decides whether the migration is performable at all without SSH (B-1/B-4). The database-UI half decides **B-5** *and* whether the deployment is observable afterwards: `health-check.md`'s two primary checks are both SQL |
| **2** | **SSH availability** | Hosting Settings → *SSH access* | Whether the field is changeable by you or greyed out; the value you set | Set `/bin/bash` **if the field allows it** | Clears **B-1 and B-4**; makes probe Route A and `artisan` available. Setting it is not proof it works — verify separately |
| **3** | **Custom-directive capability** | Websites & Domains → *Apache & nginx Settings*, **bottom of page** | Whether any *"Additional directives for HTTP/HTTPS"* or *"Additional nginx directives"* textarea exists | No | **G0-A**. Absent → FAIL, which now costs a scoped frontend change, not weeks |
| **4** | **Static-file handling** | Same page, nginx section | Exact current value of *"Serve static files directly by nginx"*, verbatim or "empty" | No | **G0-B.5**, and it conditions how **G0-B.2** must be read |
| **5** | **Identify the unexplained object** | Websites & Domains | What object exists named `ethr.et` besides domain id 2536, and its document root | **No — identify only.** Deleting a vhost is not deleting a folder | **B-3**; unblocks action 8 |
| **6** | **Capability probe, Route C** | File Manager → upload to `httpdocs/<random>.php` | The full output. Open it with **no query string**; **delete the file in the same sitting** | Upload then delete | **G0-E**, **G0-H**, storage rows, **G0-J** CPU half. Read the truncation table in `deployment/GATE-0-RESULT.md` Step 1 first |
| **7** | **Canary, five checks** | File Manager → `httpdocs/ethr-canary/` | Output of all five checks, each `curl` with `--resolve www.ethr.et:443:213.55.96.154`; plus the `favicon.ico` header comparison for G0-B.2's static-asset scope | Upload then delete the directory | **G0-B.1–B.5**. Take the four files from branch `claude/gate0-procedure-corrections` — `shadow.txt` is **not on `main`**, and without it you run four checks, not five |
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
a URL only", this migration has no documented way to be performed. G0-D is now the
highest-value remaining panel read.

### One capability found, worth keeping

**Plesk Git's deployment path is relative to the webspace root, not to `httpdocs`.** So
setting it to `ethr` deploys to `~/ethr/` — exactly the layout `DEPLOYMENT.md` §0
specifies, outside the document root. That matters now that SSH is forbidden and step 3's
`rsync` is unavailable: Git + Composer extensions are the remaining deployment route, and
they can reach the correct target natively. Recorded here rather than in the runbook
because `docs/deployment/shared-hosting/*` stays frozen and this is not step 4a.

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
| R3 | No cron → 11 scheduled entries and all queued work stop | **Critical** | Unverified — still the top open item |
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

Nothing further is available to do without external input. Two independent things are
needed from the owner, unrelated to each other. Neither requires the other.

### 1. ~~Push to GitHub~~ — done 2026-09-15

`docs/phase-0-baseline` is pushed (32 commits, exit 0). The August finding that
this was "not a retry-worthy failure" no longer holds; it was retried and it
worked. What remains is a reviewed `--no-ff` merge into `main`, which is a
decision rather than a blocker.

### 2. Resume the hosting migration

Account: `etrhet` @ `213.55.96.154` (Plesk). All quick, ~10 minutes total.

**Already answered — do not re-ask the owner:** **B1b** — the owner confirmed Plesk
accepts the literal name `*` for *Add Subdomain*. Do not put that question again.
**But the gate is not closed:** the vhost has never been created and no panel output was
recorded, so `deployment/GATE-0-RESULT.md` holds G0-C at **PARTIAL** under its own
"only from output" rule. The action is to *create* it in this session and record the
result — not to re-ask whether it can be.

**From the panel:**

1. **B3** — *Scheduled Tasks*: present? "Run a command" offered, or URL-fetch only?
   Minimum interval?
2. **B5** — *Node.js*: extension present? Which Node major version (need ≥ 20.9)?
3. **Bronze's actual quotas** — storage, bandwidth, databases, subdomains (only
   matters for tier sizing — see D2, staying on Bronze for now regardless).

**By uploading one file, from the home directory, never `httpdocs`:**

4. Run `scripts/hosting-verification/ethr-hosting-check.php`, save the output, **delete
   it from the server immediately after**. Answers B4 (PHP + extensions + limits) and
   H1 (`CREATE TRIGGER`) together in one pass.

That's the whole remaining list. The audit-log question (item 7 in an earlier version
of this file) is **no longer outstanding** — resolved by D8: the migration aborts with
a diagnosis if `CREATE TRIGGER` is denied, rather than degrading silently. If H1 turns
out negative, the response is a support request to Ethio Telecom for the `TRIGGER`
grant, not a code change.
