# Migration Changelog

Chronological record of the Ethio Telecom shared-hosting migration effort,
2026-08-29 through 2026-08-31. Four `--no-ff` merges to `main`, each independently
revertable (`git revert -m 1 <sha>`).

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

Four facts, all requiring the Plesk account, none answerable from this repository:
B3 (cron), B4 (PHP/extensions/GD), B5 (Node.js), H1 (`CREATE TRIGGER` privilege). See
`docs/MIGRATION_STATE.md` → NEXT ACTION for exactly how to get each. Plus two
confirmations only the owner can supply: a tax adviser/ERCA sign-off on the 1395/2025
schedule before the first live payroll run, and real SMTP credentials.
