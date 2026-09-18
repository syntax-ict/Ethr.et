# Migration Changelog

Chronological record of the Ethio Telecom shared-hosting migration effort,
2026-08-29 onward. Four `--no-ff` merges to `main` covering 2026-08-29 – 2026-08-31,
each independently revertable (`git revert -m 1 <sha>`), plus the entries below.

---

## 2026-09-17 — The document root had no step that built it

**Reconciliation, not new scope.** `docs/deployment/shared-hosting/DEPLOYMENT.md` §0
drew `~/httpdocs/` and named its contents, but no step in the runbook ever assembled
it: steps 3–4 put the application in `~/ethr/api/`, step 5 deploys the frontend, and
nothing in between copied `index.php` or `.htaccess` into the document root. Every rule
G0-B measures is inert until that file is in place, so the gate rested on a step that
did not exist — and running Gate 0 without it would have measured a deployment nobody
could perform.

Written as **step 4a**, from §0's own specification. **Four** defects in that file list
surfaced, the last of them found by the control written to prevent the first three:

1. **"2 lines repointed" is three.** `api/public/index.php:9` reads
   `storage/framework/maintenance.php` and was missing from the list. Unrepointed it
   resolves to `~/storage/…`, which never exists, so `php artisan down` succeeds on the
   CLI and changes nothing about what is served — maintenance mode inert exactly when it
   is relied on.
2. **`robots.txt` was listed as copied from `api/public/`, and must not be.** On the VPS
   that file serves the API vhost (`infrastructure/nginx.conf` roots three server blocks
   at `api/public`) and reads `User-agent: * / Disallow:`. This deployment merges the API
   and the public site into one document root, so copying it serves allow-all at
   `/robots.txt`, silently replacing the frontend's generated file — losing the
   `Disallow` list and the `Sitemap:` pointer, with the site fully functional and nothing
   logged. `favicon.ico` is the same mechanism at cosmetic severity.
3. **Under B5 = no it is order-dependent**: the exported `out/` and `api/public/` land in
   the same directory and neither step said which wins.
4. **`api/public/.htaccess` was missed entirely** — by the original §0 list *and* by the
   first correction of it, because `ls` does not show dotfiles. It is the most dangerous
   of the four to copy: Laravel's stock rules end in a catch-all (`!-d`, `!-f`,
   `RewriteRule ^ index.php`) that sends every unmatched path to Laravel, which under
   B5 = no swallows `/pricing`, `/dashboard` and every other client-routed path. The
   deployment ships a purpose-built replacement whose front-controller rule is restricted
   to `^/(api|sanctum)` for exactly this reason. Loud rather than silent, but the two
   files share a name, which is the whole hazard.

**The rule is now enforced rather than written down.**
`api/tests/Feature/DocumentRootInventoryTest.php` enumerates `api/public/` against
`document-root-inventory.php`, which records for every file whether it belongs in the
shared document root and why, and fails naming any file with no decision. Same shape as
`Security/TenantScopeBypassInventoryTest`, and for the stated reason: `CLAUDE.md` holds
that a documented control nobody runs is worse than an admitted gap. Defect 4 above is
what that test caught on its first run, against the corrected prose — which is the
argument for it, made by the thing itself. It does not audit the existing decisions; a
manifest cannot. It makes adding a fourth file deliberate.

**One assertion downgraded from fact to gate.** Step 4a had claimed a real file in the
document root always wins over the rewrite, citing both the `.htaccess` `!-f` guard *and*
"Plesk serves static files from disk before any handler". The first is readable in the
repository; the second was never measured, in a document whose own rule is that inference
is not evidence. It is now **G0-B.5**, and the canary answers it in the same session as
the other four (`shadow.txt` exists on disk *and* is rewritten, so the response names
which layer resolved first). No failing answer — both outcomes leave step 4a correct —
but "which layer answered" is the first question anyone asks when a document root
misbehaves.

**Branch A written out instead of flagged.** The G0-A contradiction (Node app in its own
document root vs. `/api/*` from `~/httpdocs`) now carries both candidate resolutions with
their costs: nginx directives splitting one origin (~1 day, no frontend change) versus
the frontend going cross-origin (~1–2 weeks plus an auth-security review, and the service
worker's API cache silently stops working). When G0-A answers, it is a lookup.

**Freeze exception, recorded not assumed.** `GATE-0-RESULT.md` says
`docs/deployment/shared-hosting/*` stays untouched until the facts exist. That rule exists
so branch-dependent content is not written twice, and does not cover a step absent under
every branch. The exception is recorded at the rule, scoped to step 4a and the §0 list it
corrects, with the rest of the package still frozen.

**Checked and found not to be a defect:** `public_path()` resolves to `~/ethr/api/public`,
which nothing serves — but its only reference is `config/filesystems.php:88`'s `links`
array, consumed solely by `storage:link`, which §4 already forbids.

**No gate moved.** G0-A and every G0-B row remain `NOT VERIFIED` and require the Plesk
account. No application code changed; the new test asserts repository shape, not runtime
behaviour, and is not evidence about Ethio Telecom's server.

---

## 2026-08-29 — Discovery, audit, and Phase A (`12f53de`)

**Read-only discovery.** Inspected the actual codebase against the deployment target —
not the `PHASE_*.md` checkboxes, per this project's own stated rule that documentation
does not equal implementation. Produced `SHARED_HOSTING_AUDIT.md`,
`ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`, `SHARED_HOSTING_MIGRATION_PLAN.md`.

**Headline finding:** the application's coupling to its VPS infrastructure is far
smaller than the 13-service `docker-compose.prod.yml` suggests. Zero `Redis::` calls
anywhere in `app/`; every notification's broadcast leg already guards on
`config('broadcasting.default')`; tenancy is a single database with a row-level scope,
not per-tenant provisioning.

**Implemented (commit `80cac67`):** five hardcoded infrastructure references made
configuration-driven — `FileStorageService`'s disk, `HealthController`'s cache/storage
checks (×3), `HealthCheckCommand`'s cache check. Behavioural no-ops on the VPS; the
prerequisite for running anywhere else.

**Reviewed and corrected (commit `f0f6888`):** the audit-log trigger migration
(`2026_07_22_000001_restrict_audit_log_to_insert_only.php`) was briefly weakened to
log-and-continue on a denied `CREATE TRIGGER`, so a shared host refusing the privilege
could still deploy. Caught before merge: the model-level guards
(`AuditLog::update()`, `NeverDelete`) are plain instance methods that every
mass-operation path bypasses, and the project's own `AuditLogImmutabilityTest` already
asserts database-level enforcement via the raw query builder. Reverted to fail-fast,
with a diagnosis (the exact SQL a DBA needs) added on top of the bare error it used to
throw. See `AUDIT_LOG_INTEGRITY_DECISION.md` for the full reasoning.

**External verification, no account access needed:** wildcard DNS on `ethr.et`
resolves for names that were never configured; a Let's Encrypt certificate is already
issued (HTTP-01, per-hostname); SSH and Plesk ports are open on `213.55.96.154`; MySQL
is not internet-exposed; the account's home directory sits above `httpdocs`, so Laravel
can live outside the document root by construction.

**Documentation corrected (commit `1685ee8`):** `DEPLOYMENT.md`'s go-live checklist
said payroll cost-sharing had "zero implementation... don't offer it." It had shipped —
model, service, controller, frontend page, 38 test cases — and the checklist had not
been updated. Fixed.

**Also in this merge (commit `13d3447`) — found while re-verifying that same checklist
against the code:** the platform income-tax ladder was Proclamation 979/2016,
superseded by Proclamation 1395/2025 on 7 July 2025 — thirteen months before this
audit. Every payslip since then over-deducted employment income tax, worst at the
bottom of the scale (a 2,000 ETB salary lost ~8% of gross to tax on income that is now
exempt). Corrected to the six-band 1395/2025 schedule, effective-dated so a
reprocessed historical period still reproduces the tax actually withheld at the time.
Verified against real MariaDB, not only the SQLite test suite.

## 2026-08-30 — Gate review, owner delegation, plan-tier billing (`2826b0d`)

A formal gate review (`PHASE_A_CHANGE_REVIEW.md`, `HOSTING_VERIFICATION_CHECKLIST.md`,
`B1-B5_GATE_REPORT.md`, `TCO_COMPARISON.md`) froze further hosting-code changes pending
external verification. The owner supplied real account credentials
(`etrhet@213.55.96.154`) and confirmed Plesk accepts a wildcard subdomain — resolving
gate B1, the one gate this migration had no workaround for.

Delegated ("decide for me"): architecture confirmed as full shared hosting, stay on the
Bronze tier rather than pre-emptively upgrading, TLS strategy set to per-tenant HTTP-01
(proven working on the account) while pursuing wildcard as an optimisation, canonical
host set to `www.ethr.et` matching Plesk's own existing redirect.

**Unrelated to hosting, merged alongside it:** `Plan.features` existed on every
subscription tier and was enforced by nothing — a Starter tenant could run payroll,
register webhooks, and read the audit log exactly like an Enterprise tenant.
`PlanFeatureService` + `RequiresPlanFeature` middleware now gate 5 write-side routes and
one deliberate read (`GET /audit-logs`, since there the read *is* the paid capability),
fail-open on every ambiguity for the same reason the existing `PlanLimitService`
already is — a billing gate wrong in the closed direction locks out paying customers.

Live-verified against the real stack, not only Pest: found and worked around a real
local-dev issue (`php artisan serve`'s child process silently drops Docker-injected
environment variables), then drove all 5 gated routes against a real Sanctum session on
the demo tenant, flipping its subscription between Starter and Enterprise, confirming
403/200 correctly on both sides — including correctly diagnosing one false alarm
(`audit-logs` returning 403 on both tiers turned out to be an unrelated pre-existing
permission check, not the new gate) rather than reporting it as a defect.

Found and fixed during that work: `Plan` had no `@property` type annotations, so
PHPStan correctly reported that the new gate's `is_array()` check could never be true —
fixed by adding the annotation (matching `TaxBracket`/`AuditLog`'s existing convention),
which also narrowed the generated frontend API contract from `unknown[]` to `string[]`.

## 2026-08-31 — Dead code, state sync, deployment package (`2eb2e42`, `92c9f03`, this merge)

Investigating whether the new plan gate handled suspended/cancelled tenants correctly
(it doesn't check subscription status — confirmed as the existing, already-shipped
`PlanLimitService` pattern, not a new gap) led to finding that access control for that
exact case already exists: `ResolveTenant::isActive()` blocks suspended, cancelled, and
trial-expired tenants on every request. What didn't exist: `EnforceTrialExpiration`, a
middleware meant to give trial expiry its own 402-with-upgrade-CTA response, was
registered nowhere — confirmed by a repo-wide search before removing it. `PHASE_01.md`
had a `[x]` claiming this was done; corrected.

`docs/MIGRATION_STATE.md`, unedited since the 2026-08-29 pause, was rewritten to match
three days of merged work rather than describing Phase A as an uncommitted working-tree
diff that had in fact been merged three times over.

**This merge:** the shared-hosting deployment package (`docs/deployment/shared-hosting/`) —
runbook, environment reference, `.htaccess`, pre-cutover checklist, health-check guide —
plus `DATABASE_MIGRATION_PLAN.md`, `ROLLBACK_RUNBOOK.md`, `PRODUCTION_CHECKLIST.md`, and
this changelog. Built ahead of the remaining B3/B5 answers deliberately: written as
explicit branches for each, rather than waiting to build the parts that don't depend on
either.

---

## What was deliberately not built

Two items, both flagged rather than invented, matching this session's general approach
to product/business decisions that aren't code fixes:

- **Google Workspace SSO.** `SsoProviderInterface` is SAML-shaped; Google needs
  OAuth2/OIDC — a new contract, not an adapter to an existing one.
- **Trial-expiry's dedicated 402 + upgrade CTA.** Real enforcement already exists
  (a generic 403, via `isActive()`); the specific UX is a billing/conversion decision —
  what does "upgrade" link to, is there self-serve checkout — not something to invent
  without the person who owns that call.
- **The authenticated-HTTP-endpoint scheduler fallback**, needed only if B3 turns out
  negative (cron offers URL-fetch tasks only, not command execution). New attack
  surface on a route that can trigger payroll-adjacent jobs; sized to the actual
  fetch-interval floor once known, not guessed at now.

## What's still open

> **Superseded 2026-09-18.** This section read: *"Four facts, all requiring the Plesk
> account … B3 (cron), B4 (PHP/extensions/GD), B5 (Node.js), H1 (`CREATE TRIGGER`
> privilege). See `docs/MIGRATION_STATE.md` → NEXT ACTION."* It is left here as the
> 2026-08-31 position, and corrected below, because it is wrong in three ways at once and
> is the section a reader checks for current state.

**1. The labels collide with live blockers that mean something else.** `B5` here is
*Node.js*; **B-5** in the current register is *no database import route*. `B3` here is
*cron*; **B-3** is *an unidentified Plesk vhost skeleton*. The retired scheme maps to
**gates**, not blockers (`HOSTING_VERIFICATION_CHECKLIST.md:17`):

| Retired | Means | Now |
|---|---|---|
| `B3` | cron / Scheduled Tasks | **G0-D** |
| `B4` | PHP version, extensions, GD | **G0-E** |
| `B5` | Node.js availability | **G0-G** |
| `H1` | `CREATE TRIGGER` privilege | **G0-F** |

**2. "Four facts" is no longer the list.** Gate 0 is **G0-A … G0-J**, and six blockers
**B-1 … B-6** were found on 2026-09-17/18 — none of which existed when this was written.
Two of the four are also partly answered: PHP is **8.3.33** (panel reading), and SSH is
**Forbidden**, which is what created B-1 and B-4.

**3. The cross-reference is stale.** `MIGRATION_STATE.md`'s live list is
**MANUAL ACTION QUEUE**, ordered `0a → 0b → 1 … 8`. A `NEXT ACTION` heading still exists
in that file but carries the 2026-08-29 position.

**Current, in one line:** everything turns on manual action **1** — *can this account run
a PHP CLI command on a schedule?* — which decides deployment (B-4), database import (B-5),
backup and restore, and observability; preceded by **0a**, *is anything still serving at
the VPS*, which decides whether a rollback target exists (B-6).

Unchanged from the original, because both are still true: two confirmations only the owner
can supply — a tax adviser/ERCA sign-off on the 1395/2025 schedule before the first live
payroll run, and real SMTP credentials.
