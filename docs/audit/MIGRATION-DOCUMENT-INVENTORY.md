# ETHR — Migration and Deployment Document Inventory

**Phase 0 forensic audit, read-only. Classified 2026-09-25 against `6d9fb23`.**

Classification of every migration- or deployment-bearing document in the repository.
Dates in the *Last touched* column are from `git log`, not from the date printed inside the
document — several print a creation date and have been revised many times since.

**Classes:** `CURRENT` · `HISTORICAL` (accurate when written, superseded by measurement) ·
`PROPOSED` (a plan, not a record) · `OBSOLETE` (contradicted by the code) ·
`CONTRADICTORY` (disagrees with another in-repo source) · `UNKNOWN`.

**Headline finding: the repository is not short of migration documentation. It has roughly
650 KB of it, most of it careful, and the failure mode to guard against is duplication, not
absence.** Several documents already carry explicit supersession banners — that habit is
why this inventory is possible at all.

---

## 1. The authoritative set — read these first

| Document | Size | Last touched | Class | Why |
|---|---:|---|---|---|
| [`../deployment/SHARED-HOSTING-CONTRACT.md`](../deployment/SHARED-HOSTING-CONTRACT.md) | 14.7 KB | 2026-09-25 | **CURRENT — outranks everything below it** | The owner's design constraint. Carries the **HARD RULE** set 2026-09-25: *"Work happens in the repository. Hosting settings are not a path."* It also records that the rule was first written down wrong, and why the mis-stated version's consequences survive the correction |
| [`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md) | 87.0 KB | — | **CURRENT** | The host-capability evidence register. Grades panel-read separately from probe-measured, downgrades its own rows when evidence weakens, and has a *"What these readings do not settle"* section. **No other document should re-score these gates** |
| [`../deployment/shared-hosting/DEPLOYMENT.md`](../deployment/shared-hosting/DEPLOYMENT.md) | 39.9 KB | — | **CURRENT** | The executable procedure: release preparation, database creation, upload, document-root assembly (§4a), frontend (§5 with a static-export fallback branch), cron (§6 with a branch for URL-fetch-only), the disk-full failure mode (§6a), verification, DNS cutover |
| [`BASELINE.md`](BASELINE.md) | 213.8 KB | — | **CURRENT, with three stale figures** | The measured state of the codebase, §1–§18. Three rows are out of date — see [`CODE-VS-DOCUMENTATION.md`](CODE-VS-DOCUMENTATION.md) §A |
| [`../MIGRATION_STATE.md`](../MIGRATION_STATE.md) | 190.3 KB | 2026-09-25 | **CURRENT with a CONTRADICTORY header** | Actively maintained, but its *CURRENT PHASE* block still reads *"PAUSED … since 2026-08-29 … Migration code changes remain FROZEN"*, dated 2026-08-31, while shared-hosting code landed on 2026-09-22, -23 and -25. The document flags its own four-facts table as superseded; the supersession needs to move to the top |
| [`../deployment/VPS-DECOMMISSION.md`](../deployment/VPS-DECOMMISSION.md) | 13.0 KB | 2026-09-25 | **CURRENT — PROPOSED action, NOT TRIGGERED** | The removal analysis. §2 *"What must NOT be removed, and why"* is the load-bearing part and is correct: `docker/frontend/Dockerfile` is the **only declaration of the frontend runtime version** and the Plesk Node branch depends on it; `docker-compose.yml` and `docker/php/*` are what `gates.sh` execs into. §3's own list is annotated as incomplete, measured 2026-09-25 |
| [`../deployment/PLESK-HOSTING-GUIDE.md`](../deployment/PLESK-HOSTING-GUIDE.md) | 16.2 KB | 2026-09-25 | **CURRENT** | The owner-facing entry point. *"nothing here needs a shell, because this account does not have one"* |
| [`../deployment/PLESK-SETUP.md`](../deployment/PLESK-SETUP.md) | 16.1 KB | 2026-09-25 | **CURRENT** | Keeps the distinction between *the repository is unprepared* and *this is yours to configure* |
| [`../PANEL-SESSION-RUNBOOK.md`](../PANEL-SESSION-RUNBOOK.md) | 15.5 KB | 2026-09-25 | **CURRENT** | One panel session, ordered. The practical counterpart to the hard rule |

---

## 2. Supporting current documents

| Document | Last touched | Class | Note |
|---|---|---|---|
| [`../deployment/shared-hosting/README.md`](../deployment/shared-hosting/README.md) | 2026-09-24 | **CURRENT** | Index for the deployment package. States plainly that it was built 2026-08-31 ahead of the four facts, deliberately, with the dependent parts written as explicit branches |
| [`../deployment/shared-hosting/ENVIRONMENT.md`](../deployment/shared-hosting/ENVIRONMENT.md) | — | **CURRENT** | Environment-variable decisions; `api/.env.shared-hosting.example` is the executable form |
| [`../deployment/shared-hosting/cron-caller.md`](../deployment/shared-hosting/cron-caller.md), [`cron.txt`](../deployment/shared-hosting/cron.txt) | — | **CURRENT** | The mechanics for driving `CronRunController` when G0-D is FAIL. Directly load-bearing given the strong evidence that no Scheduled Tasks section exists |
| [`../deployment/shared-hosting/.htaccess`](../deployment/shared-hosting/.htaccess), [`nginx-directives.conf`](../deployment/shared-hosting/nginx-directives.conf) | — | **CURRENT** | The deployable web-server rules. Per VPS-DECOMMISSION §2 both now declare the shared-hosting headers as their own authority and cite `nginx-common.conf` only as provenance |
| [`../deployment/shared-hosting/health-check.md`](../deployment/shared-hosting/health-check.md), [`deploy-checklist.md`](../deployment/shared-hosting/deploy-checklist.md), [`rollback.md`](../deployment/shared-hosting/rollback.md) | — | **CURRENT** | Operational |
| [`../deployment/BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md) | — | **CURRENT** | Carries the `BACKUP_KEEP` arithmetic that §6a of DEPLOYMENT.md cites |
| [`../HOSTING_VERIFICATION_CHECKLIST.md`](../HOSTING_VERIFICATION_CHECKLIST.md) | 2026-09-23 | **CURRENT** | ~60 rows. States its own position: *"NOT VERIFIED is not a soft yes"* |
| [`../B1-B5_GATE_REPORT.md`](../B1-B5_GATE_REPORT.md) | 2026-09-22 | **CURRENT** | The B1–B5 measurements GATE-0 cites |
| [`../DATABASE_MIGRATION_PLAN.md`](../DATABASE_MIGRATION_PLAN.md) | 2026-09-23 | **CURRENT** | Data movement, VPS MariaDB → shared-hosting MySQL. Correctly offers a *"Fresh deployment"* branch for the no-prior-tenants case, which is the likely one |
| [`../deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`](../deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md) | 2026-09-25 | **CURRENT — drafted, NOT SENT** | Four asks, one ticket. Exists because three documents named *"one support request"* as the remedy and none contained the text |

---

## 3. Historical — accurate when written, superseded by measurement

These are the Phase 0–3 sequence from 2026-08-29, when **no account was available**. They
should be kept: they are where the reasoning is recorded, and each already carries its own
supersession banner.

| Document | Last touched | Class | Superseded by |
|---|---|---|---|
| [`../SHARED_HOSTING_AUDIT.md`](../SHARED_HOSTING_AUDIT.md) (Phase 0 + 1) | 2026-09-22 | **HISTORICAL** | This audit and `BASELINE.md`. Its own method line — *"Source of truth: the code in `api/` and `src/`, not `docs/phases/PHASE_*.md`"* — is the right principle and is the one applied here |
| [`../ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`](../ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md) (Phase 2) | 2026-09-22 | **HISTORICAL** | `GATE-0-RESULT.md`. Self-flagged *"Partially superseded 2026-09-17/18"*; built from public product pages with **no account available** |
| [`../SHARED_HOSTING_MIGRATION_PLAN.md`](../SHARED_HOSTING_MIGRATION_PLAN.md) (Phase 3) | 2026-09-23 | **HISTORICAL** | `deployment/shared-hosting/DEPLOYMENT.md`. Self-flagged: *"Superseded 2026-09-18 — both halves of that line are now false"* |
| [`../deployment/SHARED_HOSTING_PLAN.md`](../deployment/SHARED_HOSTING_PLAN.md) | 2026-09-25 | **HISTORICAL / CONTRADICTORY by title** | 77 KB gap analysis whose title says **Gold**. Its own banner: *"The tier in that title is ASSUMED… The account is reported **Bronze** — owner-supplied… That is testimony, not panel output."* Contains Plan B, which restructured the workstream on the assumption every support ask is denied — **that reasoning is worth preserving** |
| [`../PRODUCTION_CHECKLIST.md`](../PRODUCTION_CHECKLIST.md), [`../ROLLBACK_RUNBOOK.md`](../ROLLBACK_RUNBOOK.md) | 2026-09-18 | **HISTORICAL** | The `deployment/shared-hosting/` equivalents |
| [`../MIGRATION_CHANGELOG.md`](../MIGRATION_CHANGELOG.md) | 2026-09-18 | **HISTORICAL — by design** | A changelog is supposed to be historical |
| [`../phases/`](../phases/) | — | **HISTORICAL** | Explicitly demoted by `SHARED_HOSTING_AUDIT.md`'s method line |

---

## 4. VPS-target documents

| Document | Last touched | Class | Disposition |
|---|---|---|---|
| ~~`../VPS_DEPLOYMENT.md`~~ | 2026-09-24 | **REMOVED 2026-09-26** | Deleted with the VPS stack at the owner's direction. §3 said *"out last, not first"* because it was the rollback path; it went before a verified cutover, so that path no longer exists |
| [`../DEPLOYMENT.md`](../DEPLOYMENT.md) | 2026-09-25 | **CURRENT** *(was PARTLY OBSOLETE)* | Phase 2 corrected the service table, the worker-sizing section, the deploy description and three diagnostic snippets — all of which prescribed `horizon`, a package that is not installed. Its *Draining the queue before an upgrade* section was always **CURRENT and load-bearing** and is untouched — the root `CLAUDE.md` cites it as a deploy precondition |
| [`../ARCHITECTURE.md`](../ARCHITECTURE.md) | 2026-09-25 *(prior substantive revision **2026-08-28**)* | **CURRENT with a standing caution** *(was PARTLY OBSOLETE — the most stale document in the set)* | Phase 2 corrected the diagram, service list, queue table and health-check row, and added a header banner: it describes the **VPS/Docker** deployment, not the Plesk target, and where it disagrees with code the code wins. The body still predates the shared-hosting decision — the banner says so rather than pretending a one-day edit re-verified 58 KB |
| [`../TCO_COMPARISON.md`](../TCO_COMPARISON.md) | 2026-09-22 | **PROPOSED / decision input** | Cost comparison, not a record of state |

---

## 5. Application-domain documents that read as migration documents but are not

| Document | Class | Note |
|---|---|---|
| [`../MIGRATION.md`](../MIGRATION.md) | **CURRENT — unrelated** | *"Workforce Migration"* — the **product feature** for importing employee data into a tenant (`migration_workspace` tables, `WorkforceMigrationService`). **Not hosting migration.** The filename is the trap; nothing in it concerns Plesk |
| [`../DATABASE.md`](../DATABASE.md), [`../PERMISSIONS.md`](../PERMISSIONS.md), [`../FRONTEND.md`](../FRONTEND.md), [`../LOCALIZATION.md`](../LOCALIZATION.md), [`../DEVICE_INTEGRATION.md`](../DEVICE_INTEGRATION.md) | **CURRENT — domain** | Product documentation |
| [`../CLAUDE.md`](../CLAUDE.md) | **CURRENT** | The 15 Non-Negotiable Conventions, design system, API and testing rules |
| [`../AUDIT_LOG_INTEGRITY_DECISION.md`](../AUDIT_LOG_INTEGRITY_DECISION.md) | **CURRENT — load-bearing for Bronze** | Why the audit-log trigger migration is **fatal on failure** rather than degrading. Read it before anyone proposes softening that migration to get past a missing `TRIGGER` grant |

---

## 6. What this audit adds, and what it deliberately does not

**Adds** the six documents in `docs/audit/` and one in `docs/migration/` that this phase was
asked for — a cross-cutting inventory, a code-vs-documentation reconciliation, two matrices,
this inventory, a tenant-isolation audit, and one authoritative plan.

**Deliberately does not** re-score a single G0 gate, re-derive the tenant-scope bypass count
with a regex, restate `BASELINE.md`'s §1–§18, or reproduce
`deployment/shared-hosting/DEPLOYMENT.md`'s procedure. Each of those already has an owner in
this repository, and a second copy of a measured figure is how the +5 overcount in the root
`CLAUDE.md` happened.

**One structural recommendation, and it is not for this phase to execute:** the three
Phase 0–3 documents from 2026-08-29, `PRODUCTION_CHECKLIST.md` and `ROLLBACK_RUNBOOK.md`
all now have current successors. They are valuable as reasoning and confusing as
instructions. Moving them under a `docs/phases/` or `docs/historical/` prefix — not deleting
them — would make the authoritative set findable without losing anything.

---

## Related

- [`CODE-VS-DOCUMENTATION.md`](CODE-VS-DOCUMENTATION.md)
- [`REPOSITORY-INVENTORY.md`](REPOSITORY-INVENTORY.md)
- [`../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md)
