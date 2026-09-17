# ETHR Documentation

Index for 37 top-level documents plus `phases/`, `audit/` and `deployment/`. There was no index before Phase 1, and `README.md` mapped eight of them.

**Start here:** root [`CLAUDE.md`](../CLAUDE.md) for the environment traps · [`CLAUDE.md`](CLAUDE.md) for the conventions · [`audit/BASELINE.md`](audit/BASELINE.md) for what is measured versus merely documented.

---

## Read this first

Several documents in this tree describe things that are not true of the code. That is not carelessness so much as drift — the project moved faster than its prose. The two places to check before trusting any claim:

| | |
|---|---|
| [`audit/BASELINE.md`](audit/BASELINE.md) | The measured state, every claim tagged `[verified]` / `NOT VERIFIED` / `NOT MEASURED`. §12b lists the known documentation contradictions. |
| [`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md) | Every hosting capability, all still `NOT VERIFIED`. Nothing has touched the real host. |

Three standing caveats:

- **CI is configured but has never run.** Phase 1 corrected four documents that asserted a pipeline which did not exist; Phase 2 built one. It still has not executed — nothing has been pushed — so it is untested configuration. The pre-push hook (`git config core.hooksPath .githooks`) is the part that works today.
- **`phases/` is not maintained.** The checkboxes badly under-report what is built. Specifications, not progress trackers.
- **"Migration" means three different things here.** See the naming note at the bottom.

---

## Rules and conventions

| Document | What it is |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | **Authoritative.** 15 Non-Negotiable Conventions, design system, API and testing rules, model routing policy |
| [`AGENTS.md`](AGENTS.md) | A pointer to the above. Was a fork; drifted 288 lines; retired in Phase 1 |
| [`../CONTRIBUTING.md`](../CONTRIBUTING.md) | How to run, test and commit — including the traps |

## Product and architecture

| Document | What it is |
|---|---|
| [`PRODUCT_VISION.md`](PRODUCT_VISION.md) · [`PRD.md`](PRD.md) | Why ETHR exists, and what it must do |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | System design and data flow (58 KB, the largest reference) |
| [`DATABASE.md`](DATABASE.md) | Schema |
| [`PERMISSIONS.md`](PERMISSIONS.md) | Roles, permissions, the RBAC model |
| [`FRONTEND.md`](FRONTEND.md) · [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) | Frontend structure, visual language, semantic tokens |
| [`LOCALIZATION.md`](LOCALIZATION.md) | i18n, Amharic, the Ethiopian calendar |

## Features

| Document | What it is |
|---|---|
| [`ONBOARDING_V2.md`](ONBOARDING_V2.md) | Tenant onboarding and the guided setup wizard |
| [`INDUSTRY_TEMPLATES.md`](INDUSTRY_TEMPLATES.md) | Per-sector organization templates |
| [`DEVICE_INTEGRATION.md`](DEVICE_INTEGRATION.md) | Biometric and attendance devices |
| [`IDENTITY_RESOLUTION.md`](IDENTITY_RESOLUTION.md) | Matching people across sources |
| [`MIGRATION.md`](MIGRATION.md) | **Workforce** data migration — the product feature, not hosting |

## Status

| Document | What it is |
|---|---|
| [`ENTERPRISE_ROADMAP.md`](ENTERPRISE_ROADMAP.md) | **Live status** — done, partial, missing, grounded in code (97 KB). Cites several audit documents that no longer exist; see the note below |
| [`PHASE_A_CHANGE_REVIEW.md`](PHASE_A_CHANGE_REVIEW.md) | Point-in-time review |
| [`B1-B5_GATE_REPORT.md`](B1-B5_GATE_REPORT.md) | External probing of the hosting target — DNS, ports, TLS |
| [`phases/`](phases/) | `PHASE_00`–`PHASE_09` design records. **Checkboxes not maintained** |

## Security

| Document | What it is |
|---|---|
| [`../SECURITY.md`](../SECURITY.md) | **Vulnerability reporting policy** — how to report, and what we treat as serious |
| [`SECURITY.md`](SECURITY.md) | Internal security *posture* — controls, OWASP mapping |
| [`security-audit.md`](security-audit.md) | Audit evidence, dated 2026-07-30 |
| [`AUDIT_LOG_INTEGRITY_DECISION.md`](AUDIT_LOG_INTEGRITY_DECISION.md) | Why `audit_log` is append-only at the database level, and the cost of that |

## Deployment and hosting

The target is Ethio Telecom Linux shared hosting under **Plesk** (owner decision 2026-08-29, "NO VPS").

| Document | What it is |
|---|---|
| [`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md) | **Start here.** Hosting verification — all rows `NOT VERIFIED` |
| [`deployment/shared-hosting/`](deployment/shared-hosting/) | The deployment package: runbook, env reference, `.htaccess`, checklists. **Content frozen until Gate 0 reports** |
| [`HOSTING_VERIFICATION_CHECKLIST.md`](HOSTING_VERIFICATION_CHECKLIST.md) | ~60 capability rows, every one `NOT VERIFIED` |
| [`ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`](ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md) | Plan tiers and platform constraints |
| [`SHARED_HOSTING_AUDIT.md`](SHARED_HOSTING_AUDIT.md) · [`SHARED_HOSTING_MIGRATION_PLAN.md`](SHARED_HOSTING_MIGRATION_PLAN.md) | What must change, and in what order |
| [`MIGRATION_STATE.md`](MIGRATION_STATE.md) · [`MIGRATION_CHANGELOG.md`](MIGRATION_CHANGELOG.md) | **Hosting** migration state and history |
| [`DATABASE_MIGRATION_PLAN.md`](DATABASE_MIGRATION_PLAN.md) | Moving the **schema and data** to the target MySQL/MariaDB |
| [`TCO_COMPARISON.md`](TCO_COMPARISON.md) | Cost comparison — opens by questioning the migration's own premise |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Reference manual: *why* each piece is shaped as it is |
| [`VPS_DEPLOYMENT.md`](VPS_DEPLOYMENT.md) | **Deprecated, deliberately kept** — the rollback path until cutover is verified |
| [`PRODUCTION_CHECKLIST.md`](PRODUCTION_CHECKLIST.md) · [`ROLLBACK_RUNBOOK.md`](ROLLBACK_RUNBOOK.md) | Go-live and rollback |
| [`LOCAL_SETUP.md`](LOCAL_SETUP.md) | Development environment |

## Decisions and audit

| Document | What it is |
|---|---|
| [`decisions/DECISIONS.md`](decisions/DECISIONS.md) | Decision log — what was chosen, why, and what would reverse it |
| [`audit/BASELINE.md`](audit/BASELINE.md) | Phase 0 forensic baseline |
| [`audit/MARKETING_PAGE_UPGRADING_PLAN.md`](audit/MARKETING_PAGE_UPGRADING_PLAN.md) | The public marketing site audited against launch. Phased plan MP-A…MP-F; §2.1-2.4 are launch blockers |
| [`operations/QUEUE-MONITORING.md`](operations/QUEUE-MONITORING.md) | Detecting silent queue death on a host with no supervisor |

## End-user guides

[`user-guide.md`](user-guide.md) · [`admin-guide.md`](admin-guide.md) · [`manager-guide.md`](manager-guide.md)

---

## Two naming problems, stated rather than fixed

**"Migration" means three unrelated things** in adjacent filenames:

| File | Meaning |
|---|---|
| `MIGRATION.md` | Moving *workforce data into ETHR* — a product feature |
| `MIGRATION_STATE.md`, `MIGRATION_CHANGELOG.md`, `SHARED_HOSTING_MIGRATION_PLAN.md` | Moving *ETHR onto new hosting* |
| `DATABASE_MIGRATION_PLAN.md` | Moving *the schema* — and Laravel migrations generally |

Renaming them would be tidier. It would also invalidate roughly 150 backticked prose references across 37 files, which is the kind of change that looks harmless and silently leaves half the tree pointing at the wrong thing. Recorded here instead; see `decisions/DECISIONS.md` D-002.

**Casing is inconsistent** — the three end-user guides are lowercase, everything else is `SCREAMING_SNAKE`. Same reasoning.

## Documents cited but never present

`ENTERPRISE_ROADMAP.md` and all ten phase documents cite audits that are not in this tree and, as far as git history shows, never were: `ETHR_AUDIT.md`, `ETHR_AUDIT_2026-08-14.md`, `ETHR_AUDIT_2026-08-03.md`, `FINAL_PRODUCTION_READINESS_REPORT.md`, and a `docs/audits/` directory referenced twice.

Phase 1 fixed the ones that were *navigation*: the root `README.md`'s live link, and the header repeated across all ten phase documents, whose only job was to route readers to current status and which pointed at two files that do not exist and one whose relative path resolved nowhere. Those now point at [`ENTERPRISE_ROADMAP.md`](ENTERPRISE_ROADMAP.md) and [`audit/BASELINE.md`](audit/BASELINE.md).

What remains are citations inside *narrative* — `ENTERPRISE_ROADMAP.md`'s evidence column, recording what the author had read at the time. Editing those would falsify the record rather than correct it, so they stay. **If you follow an evidence citation in the roadmap and it names one of those files, it does not exist.** `audit/BASELINE.md` is the current equivalent.

`scripts/docs-link-check.js` now enforces the difference: every relative *link* must resolve. It does not see backticked prose references, which is the gap described in D-002.
