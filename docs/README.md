# ETHR Documentation

Index for 34 top-level documents plus `phases/`, `audit/`, `migration/`, `deployment/`, `operations/` and `archive/`. *(It said 39 until 2026-10-01, when five superseded migration documents moved to [`archive/migration/`](archive/migration/README.md).)* There was no index before Phase 1, and `README.md` mapped eight of them.

**Start here:** root [`CLAUDE.md`](../CLAUDE.md) for the environment traps · [`CLAUDE.md`](CLAUDE.md) for the conventions · [`audit/BASELINE.md`](audit/BASELINE.md) for what is measured versus merely documented.

---

## Read this first

Several documents in this tree describe things that are not true of the code. That is not carelessness so much as drift — the project moved faster than its prose. The two places to check before trusting any claim:

| | |
|---|---|
| [`audit/BASELINE.md`](audit/BASELINE.md) | The measured state, every claim tagged `[verified]` / `NOT VERIFIED` / `NOT MEASURED`. §12b lists the known documentation contradictions. |
| [`audit/CODE-VS-DOCUMENTATION.md`](audit/CODE-VS-DOCUMENTATION.md) | **Where prose and code disagree, and which way.** Each row cites the executable evidence; closed rows say what was corrected. |
| [`deployment/LOCAL-PRODUCTION-SETUP.md`](deployment/LOCAL-PRODUCTION-SETUP.md) | **Run ETHR locally in its production shape — three commands.** Real Apache + MariaDB, the rendered `.htaccess`, the assembled document root. Runs clean; closes no host gate, and says so. |
| [`deployment/CUTOVER-CHECKLIST.md`](deployment/CUTOVER-CHECKLIST.md) | **Can ETHR go live? The single register that decides.** `CUTOVER READY = NO` as of 2026-09-27. Five explicit states per gate, and it states in terms that a green CI run moves none of them. |
| [`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md) | Every hosting capability, graded panel-read vs probe-measured. **No longer all `NOT VERIFIED`** — see the row below. **Read the *Gate status reconciliation* section at the end for current status**; the tables above it are dated and were deliberately left unmodified. |
| [`audit/BRONZE-BLOCKER-RESOLUTION.md`](audit/BRONZE-BLOCKER-RESOLUTION.md) | **Phase 1, 2026-09-25 — the first evidence taken against the real host.** Five capabilities moved to `VERIFIED`; eight discrepancies against existing documents recorded without rewriting them. |

Three standing caveats:

- **CI runs, and its history is the point.** *(Corrected 2026-09-25 — this said "CI is configured but has never run … nothing has been pushed".)* The Actions tab was read on 2026-09-16 and **every run had failed — 50 of them, since the first push**, for five structural reasons, none of them code. **Run #66 (`1cf9083`, 2026-09-16) is the first fully green run.** The root [`CLAUDE.md`](../CLAUDE.md) carries the five causes and the lesson: *read the Actions tab rather than reasoning about it.* The pre-push hook (`git config core.hooksPath .githooks`) runs `gates.sh quick` locally.
- **`phases/` is not maintained.** The checkboxes badly under-report what is built. Specifications, not progress trackers.
- **"Migration" means three different things here.** See the naming note at the bottom.

---

## Rules and conventions

| Document | What it is |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | **Authoritative.** 15 Non-Negotiable Conventions, design system, API and testing rules, model routing policy |
| [`AGENTS.md`](AGENTS.md) | A pointer to the above. Was a fork; drifted 288 lines; retired in Phase 1 |
| [`../CONTRIBUTING.md`](../CONTRIBUTING.md) | How to run, test and commit — including the traps |
| [`API-CONTRACT.md`](API-CONTRACT.md) | How `src/src/api/generated.ts` is generated from the Laravel routes (Scramble → OpenAPI → openapi-typescript), the drift gate, and why a comment above an array key ends up in the public contract |

## Product and architecture

| Document | What it is |
|---|---|
| [`PRODUCT_VISION.md`](PRODUCT_VISION.md) · [`PRD.md`](PRD.md) | Why ETHR exists, and what it must do |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | System design and data flow (58 KB, the largest reference) |
| [`DATABASE.md`](DATABASE.md) | Schema |
| [`PERMISSIONS.md`](PERMISSIONS.md) | Roles, permissions, the RBAC model |
| [`FRONTEND.md`](FRONTEND.md) · [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) | Frontend structure, visual language, semantic tokens |
| [`LANDING_PAGE_PRODUCTION_PLAN.md`](LANDING_PAGE_PRODUCTION_PLAN.md) | The public site (`/`, features, pricing, FAQ, contact) — what is not production ready and the phased plan to fix it. Read from source, nothing measured |
| [`PLATFORM_MANAGED_CONTENT_PLAN.md`](PLATFORM_MANAGED_CONTENT_PLAN.md) | **Supersedes the content half of the above.** What the platform admin owns — plan prices and limits, contact details, logo, tagline — and how it moves out of hardcoded JSX into the database |
| [`LOCALIZATION.md`](LOCALIZATION.md) | i18n, Amharic, the Ethiopian calendar |

## Features

| Document | What it is |
|---|---|
| [`ONBOARDING_V2.md`](ONBOARDING_V2.md) | Tenant onboarding and the guided setup wizard |
| [`INDUSTRY_TEMPLATES.md`](INDUSTRY_TEMPLATES.md) | Per-sector organization templates |
| [`DEVICE_INTEGRATION.md`](DEVICE_INTEGRATION.md) | Biometric and attendance devices |
| [`IDENTITY_RESOLUTION.md`](IDENTITY_RESOLUTION.md) | Matching people across sources |
| [`MIGRATION.md`](MIGRATION.md) | **Workforce** data migration — the product feature, not hosting |
| [`WEBHOOKS.md`](WEBHOOKS.md) | **For tenant developers.** The nine events actually sent, the payload, verifying `X-ETHR-Signature`, retries and auto-disable, and why the URL must be public |

## Status

| Document | What it is |
|---|---|
| [`ENTERPRISE_ROADMAP.md`](ENTERPRISE_ROADMAP.md) | **Live status** — done, partial, missing, grounded in code (97 KB). Cites several audit documents that no longer exist; see the note below |
| [`external/ERCA_INCOME_TAX_QUERY.md`](external/ERCA_INCOME_TAX_QUERY.md) | **Drafted, unsent.** Asks the **Ministry of Revenues** (not "ERCA" — that body is gone) to confirm the Proclamation 1395/2025 employment-income ladder band by band, because `DEPLOYMENT.md` records it was taken from legal-firm summaries rather than the Negarit Gazeta, and to say how an over-withholding must be corrected. Also records that **both** "open items" in roadmap 4.3 are stale |
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
| [`deployment/PLESK-HOSTING-GUIDE.md`](deployment/PLESK-HOSTING-GUIDE.md) | **Start here if you have the panel open.** The owner's end-to-end guide: what to configure, in what order, and the one step that has no route |
| [`deployment/VPS-DECOMMISSION.md`](deployment/VPS-DECOMMISSION.md) | When the VPS assets come out, and the five that must **not** — two are load-bearing for the Plesk target |
| [`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md) | Hosting verification — the authoritative capability register. *(Corrected 2026-09-25: this said "all rows `NOT VERIFIED`". Phase 1 verified five and re-graded PHP from panel-read to measured; the register itself is unmodified, with the new readings in [`audit/BRONZE-BLOCKER-RESOLUTION.md`](audit/BRONZE-BLOCKER-RESOLUTION.md).)* |
| [`deployment/PLESK-SETUP.md`](deployment/PLESK-SETUP.md) | **What the repository handles vs what you click in Plesk.** Extensions, env file, database, Git path |
| [`deployment/SHARED-HOSTING-CONTRACT.md`](deployment/SHARED-HOSTING-CONTRACT.md) | **The deployment contract.** PRIMARY: Plesk UI, Git deployment, Laravel integration, Scheduled Tasks, database. OPTIONAL: SSH. NEVER REQUIRED: Docker, systemd, Supervisor, root, VPS-only services. Records which PRIMARY elements are observed on the account and which are not |
| [`deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`](deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md) | **Ready to send.** One ticket, **four** asks, ranked by criticality 2026-09-22 — cron, what the higher plans provide, `TRIGGER`, then SSH. Ask 1 is the only one that can move G0-D; ask 2 may resolve G0-A, G0-D and G0-G at once; ask 3 unblocks G0-F; ask 4 is a convenience the deployment contract forbids depending on |
| [`external/ETHIO_TELECOM_SMS_REQUEST.md`](external/ETHIO_TELECOM_SMS_REQUEST.md) | **Drafted, unsent — a DIFFERENT Ethio Telecom desk from the row above.** Applies to the SMS/VAS (A2P) business unit for bulk-SMS service, sender ID `ETHR` and API credentials. Deliberately separate from the hosting ticket: that one goes to hosting support, and folding an SMS ask into it would route it to people who cannot answer and dilute the cron ask. Unblocks roadmap 7.2 |
| [`deployment/shared-hosting/`](deployment/shared-hosting/) | The deployment package: runbook, env reference, `.htaccess`, cron caller, checklists. **Its `.htaccess` mechanisms were verified working on the host 2026-09-25** — `mod_rewrite`, `mod_headers`, `<FilesMatch>` deny, `Authorization` passthrough, and `.htaccess` reaching static assets. Two baits remain unfetched because the **canary deployed on the host is the 2026-09-18 revision**, predating the two added in `1edaad4` |
| [`HOSTING_VERIFICATION_CHECKLIST.md`](HOSTING_VERIFICATION_CHECKLIST.md) | ~60 capability rows. **Most are `NOT VERIFIED`, and the reason is sharper than "nobody looked": nobody has run the probe.** *(Corrected 2026-09-23: this said the probe "has no route to run" because SSH is Forbidden and G0-D is FAIL. It has a web-execution mode — 403 by default, unlocked by `ETHR_PROBE_WEB_TOKEN` in the uploaded copy — so the route exists and the containment question in `MIGRATION_STATE.md` NEXT ACTION item 2 is what gates using it.)* The rows a panel read can answer are answered (`W3`, `W6`, `N1`–`N3`) |
| [`ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md`](ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md) | Plan tiers and platform constraints |
| [`MIGRATION_STATE.md`](MIGRATION_STATE.md) | **Hosting** migration state — a dated log (200 KB). Stays here because scripts cite it by path; superseded in substance by the canonical set in [`archive/migration/README.md`](archive/migration/README.md) |
| [`DATABASE_MIGRATION_PLAN.md`](DATABASE_MIGRATION_PLAN.md) | Moving the **schema and data** to the target MySQL/MariaDB |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | **Historical — the VPS/Docker record.** Only *Draining the queue before an upgrade*, the tax schedule and the env-var reasoning are still authoritative; the live procedure is `deployment/shared-hosting/DEPLOYMENT.md` |
| ~~`VPS_DEPLOYMENT.md`~~ | **Removed 2026-09-26** with the rest of the VPS stack, at the owner's direction and before a verified cutover. There is no rollback path now; the way back is `git revert`. See [`deployment/VPS-DECOMMISSION.md`](deployment/VPS-DECOMMISSION.md) |
| [`deployment/CUTOVER-CHECKLIST.md`](deployment/CUTOVER-CHECKLIST.md) | **Can we go live? The one register that decides.** Every gate is `PASS` / `FAIL` / `HOST ACTION REQUIRED` / `OWNER DECISION` / `BLOCKED` |
| [`deployment/shared-hosting/rollback.md`](deployment/shared-hosting/rollback.md) | **The current rollback procedure** — redeploy an earlier commit for code, `ethr:restore` for data. There is no VPS to fall back to |
| [`deployment/BACKUP-RESTORE.md`](deployment/BACKUP-RESTORE.md) · [`deployment/M6-RESTORE-REHEARSAL.md`](deployment/M6-RESTORE-REHEARSAL.md) | `ethr:backup` / `ethr:restore`, and the nine-step rehearsal on the real host (**not yet performed**) |
| [`deployment/shared-hosting/ENVIRONMENT.md`](deployment/shared-hosting/ENVIRONMENT.md) · [`cron-caller.md`](deployment/shared-hosting/cron-caller.md) · [`deploy-checklist.md`](deployment/shared-hosting/deploy-checklist.md) · [`health-check.md`](deployment/shared-hosting/health-check.md) | Env reference, the GitHub Actions scheduler, the pre-cutover pass, and the health endpoint |
| [`deployment/G0-F-CREATE-TRIGGER.md`](deployment/G0-F-CREATE-TRIGGER.md) · [`deployment/SHARED_HOSTING_PLAN.md`](deployment/SHARED_HOSTING_PLAN.md) · [`PANEL-SESSION-RUNBOOK.md`](PANEL-SESSION-RUNBOOK.md) | The `CREATE TRIGGER` host probe, the original (Gold-tier, disputed) plan, and the single-session panel runbook |
| [`decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md`](decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md) | **Decided 2026-09-27: static export served by Apache**, no Plesk Node application |
| [`PRODUCTION_CHECKLIST.md`](PRODUCTION_CHECKLIST.md) · [`ROLLBACK_RUNBOOK.md`](ROLLBACK_RUNBOOK.md) | **Historical.** The original 24-item brief and the VPS-era rollback plan; both superseded by the two rows above |
| [`LOCAL_SETUP.md`](LOCAL_SETUP.md) | Development environment |

## Decisions and audit

| Document | What it is |
|---|---|
| [`decisions/DECISIONS.md`](decisions/DECISIONS.md) | Decision log — what was chosen, why, and what would reverse it |
| [`audit/BASELINE.md`](audit/BASELINE.md) | The forensic baseline — §1–§18 of measured state. The deepest reference in this tree |
| [`operations/QUEUE-MONITORING.md`](operations/QUEUE-MONITORING.md) | Detecting silent queue death on a host with no supervisor |
| [`operations/ON-CALL.md`](operations/ON-CALL.md) | **What to do when** something breaks in production — symptom, cause, action, on a host with no shell |
| [`operations/COMMANDS-AND-SCHEDULE.md`](operations/COMMANDS-AND-SCHEDULE.md) | Every `ethr:*` Artisan command, the full schedule, and first checks when something looks wrong |
| [`operations/IMPERSONATION.md`](operations/IMPERSONATION.md) | How a platform super admin acts as a tenant admin: MFA re-check, 30-minute token, cross-host handoff, audit tagging, what is blocked. **Ends with three findings on the production (hostname) path that no test covers** |

### The Bronze/Plesk migration audit — Phases 0–2, 2026-09-25

Seven documents produced by a read-only forensic audit and one host-verification pass. They
**defer to `BASELINE.md` and `GATE-0-RESULT.md` rather than restating them** — a second copy of
a measured figure is how figures drift.

| Document | What it is |
|---|---|
| [`migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md) | **Start here for the migration.** KEEP / MODIFY / REPLACE / REMOVE / UNKNOWN, a 7-stage sequence, and the Bronze risk register. Its headline: *most of this migration is already built* |
| [`audit/BRONZE-BLOCKER-RESOLUTION.md`](audit/BRONZE-BLOCKER-RESOLUTION.md) | **Phase 1 host verification** — the only document here containing measurements taken against the live host |
| [`audit/BRONZE-COMPATIBILITY-MATRIX.md`](audit/BRONZE-COMPATIBILITY-MATRIX.md) | Every published Bronze constraint against the implementation that has to live inside it. Rows marked **[P1]** carry live readings |
| [`audit/REPOSITORY-INVENTORY.md`](audit/REPOSITORY-INVENTORY.md) | What this repository actually is — and which parts are production runtime vs build tooling vs optional infrastructure |
| [`audit/VPS-SHARED-HOSTING-MATRIX.md`](audit/VPS-SHARED-HOSTING-MATRIX.md) | Component by component. **A filename containing "docker" or "redis" is not evidence of a production dependency** |
| [`audit/CODE-VS-DOCUMENTATION.md`](audit/CODE-VS-DOCUMENTATION.md) | The reconciliation table. Executable evidence outranks prose |
| [`audit/TENANT-ISOLATION-AUDIT.md`](audit/TENANT-ISOLATION-AUDIT.md) | The isolation mechanism end to end — including the surfaces the global scope does not cover: cache keys, file paths, broadcast channels, sessions |
| [`audit/MIGRATION-DOCUMENT-INVENTORY.md`](audit/MIGRATION-DOCUMENT-INVENTORY.md) | Which migration document to trust: CURRENT / HISTORICAL / PROPOSED / OBSOLETE / CONTRADICTORY |

## Archive

| Document | What it is |
|---|---|
| [`archive/migration/`](archive/migration/README.md) | **Superseded — do not act on.** The 2026-08-29 hosting-migration working set: `SHARED_HOSTING_AUDIT`, `SHARED_HOSTING_MIGRATION_PLAN`, `TCO_COMPARISON`, `PHASE_A_CHANGE_REVIEW`, `MIGRATION_CHANGELOG`. Moved 2026-10-01 (audit D12); its README names the five canonical documents to read instead, and the four cluster members that stayed put and why |

## End-user guides

[`user-guide.md`](user-guide.md) · [`admin-guide.md`](admin-guide.md) · [`manager-guide.md`](manager-guide.md)

---

## Three naming problems, stated rather than fixed

**"Phase N" means two unrelated sequences**, and they are both live *(added 2026-09-25)*:

| Usage | Sequence |
|---|---|
| This file's own caveats; `phases/PHASE_00.md`–`PHASE_09.md` | The **documentation and build** phases, 2026 H1 |
| `archive/migration/SHARED_HOSTING_AUDIT.md` *(Phase 0+1)*, `ETHIO_TELECOM_SHARED_HOSTING_COMPATIBILITY.md` *(Phase 2)*, `archive/migration/SHARED_HOSTING_MIGRATION_PLAN.md` *(Phase 3)* | The **2026-08-29 hosting** sequence — superseded, see `audit/MIGRATION-DOCUMENT-INVENTORY.md` |
| `audit/REPOSITORY-INVENTORY.md` *(Phase 0)*, `audit/BRONZE-BLOCKER-RESOLUTION.md` *(Phase 1)* | The **2026-09-25 Bronze** sequence — current |

So *"Phase 1 corrected four documents"* and *"Phase 1 host verification"* are different events a
month apart. **Cite the date or the document, not the number** — the number alone is ambiguous
in this tree.

**"Migration" means three unrelated things** in adjacent filenames:

| File | Meaning |
|---|---|
| `MIGRATION.md` | Moving *workforce data into ETHR* — a product feature |
| `MIGRATION_STATE.md`, `archive/migration/MIGRATION_CHANGELOG.md`, `archive/migration/SHARED_HOSTING_MIGRATION_PLAN.md` | Moving *ETHR onto new hosting* |
| `DATABASE_MIGRATION_PLAN.md` | Moving *the schema* — and Laravel migrations generally |

Renaming them would be tidier. It would also invalidate roughly 150 backticked prose references across 37 files, which is the kind of change that looks harmless and silently leaves half the tree pointing at the wrong thing. Recorded here instead; see `decisions/DECISIONS.md` D-002. *(2026-10-01: D-002's reversal condition — a link checker in CI — has held since run #66, and five superseded hosting documents were moved to `archive/migration/` with every link and backticked path updated in the same change. The renames above are still not done.)*

**Casing is inconsistent** — the three end-user guides are lowercase, everything else is `SCREAMING_SNAKE`. Same reasoning. *(The 2026-09-25 audit documents use `KEBAB-CASE`, a third convention, for the same reason: renaming to match would break the citations that already point at them.)*

## Documents cited but never present

`ENTERPRISE_ROADMAP.md` and all ten phase documents cite audits that are not in this tree and, as far as git history shows, never were: `ETHR_AUDIT.md`, `ETHR_AUDIT_2026-08-14.md`, `ETHR_AUDIT_2026-08-03.md`, `FINAL_PRODUCTION_READINESS_REPORT.md`, and a `docs/audits/` directory referenced twice.

Phase 1 fixed the ones that were *navigation*: the root `README.md`'s live link, and the header repeated across all ten phase documents, whose only job was to route readers to current status and which pointed at two files that do not exist and one whose relative path resolved nowhere. Those now point at [`ENTERPRISE_ROADMAP.md`](ENTERPRISE_ROADMAP.md) and [`audit/BASELINE.md`](audit/BASELINE.md).

What remains are citations inside *narrative* — `ENTERPRISE_ROADMAP.md`'s evidence column, recording what the author had read at the time. Editing those would falsify the record rather than correct it, so they stay. **If you follow an evidence citation in the roadmap and it names one of those files, it does not exist.** `audit/BASELINE.md` is the current equivalent.

`scripts/docs-link-check.cjs` now enforces the difference: every relative *link* must resolve. It does not see backticked prose references, which is the gap described in D-002.
