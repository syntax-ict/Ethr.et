# Decision Log

Decisions that shaped ETHR, each with what would reverse it. Newest first.

This log starts at Phase 0 (2026-09-15). Earlier decisions live in commit messages and in the documents they produced — `AUDIT_LOG_INTEGRITY_DECISION.md` and `MIGRATION_STATE.md`'s decision table are the substantial ones, and they are referenced rather than copied here.

A decision belongs here when someone could reasonably have chosen otherwise and will later wonder why we didn't.

---

## D-010 — Dunning stays inert until someone decides what "sent" means

**Date:** 2026-09-15 · **Phase:** billing · **Status:** open, deliberately

`BillingService::generateMonthlyInvoice()` writes `status => 'draft'`. `HandleOverdueInvoicesJob` tier 1 matches `status = 'sent'`. Nothing in `app/` transitions between them, so no invoice ever enters dunning: no reminders, no past-due subscriptions, no suspensions for non-payment (§15d).

The one-line fix is obvious and wrong. Making invoices `sent` on creation would mean "sent" = "generated" — and **nothing emails invoices either**. Dunning would then suspend customers at 60 days for not paying a bill they were never sent. That is worse than an inert chain, and it fails quietly.

**Decision:** leave it. The missing piece is a send step, not a status default, and whether one should exist is a billing-process question. Pinned by a `todo` test that fails the day someone changes it.

**Reverse it when:** the owner says whether a generated invoice counts as sent, or specifies the send step.

---

## D-009 — `retry_after` is asserted, not simulated

**Date:** 2026-09-15 · **Phase:** 3 · **Status:** implemented

Master plan §19 requires the `retry_after` > job-timeout invariant to be tested. Reproducing the actual race needs two workers, real elapsed time, and a job that sleeps past the window — slow, flaky, and it would prove Laravel's behaviour rather than this application's configuration.

**Decision:** assert the invariant by reflection over `app/Jobs/*.php`. The configuration was the part that was wrong, so the configuration is what is checked, and a new long-running job fails the suite rather than silently reintroducing the bug.

The same reasoning produced a second assertion nobody asked for: every job must *declare* a timeout. Five did not, so the invariant could not be computed even in principle.

---

## D-008 — Scheduler staleness does not make `/health` return 503

**Date:** 2026-09-15 · **Phase:** ops · **Status:** implemented, after being wrong first

`QueueHealth::snapshot()` was wired into `services.queue`, which decides the endpoint's 200-vs-503. A fresh deployment has never run `schedule:run`, so `/health` returned **503 on every new install**. Caught by the full suite — 20 failures — not by the eight tests written for the feature, which exercised `QueueHealth` in isolation and never touched the endpoint.

That is the failure `HealthController` already warned about twice in its own comments, having been bitten by a hardcoded `redis` probe and a hardcoded `minio` probe. Third time, by me.

**Decision:** separate the questions rather than tune a threshold. `/health` answers "can an uptime monitor reach a working instance?" — its non-200 means *page someone*. A stopped scheduler is real and is not that. Liveness is published under `queue_detail`; `ethr:queue:check` is what acts on it.

**Reverse it if:** never. A probe that cries wolf gets muted, and then it is worth nothing on the day it matters.

---

## D-007 — Payroll: the engine is split, not rewritten

**Date:** 2026-09-15 · **Phase:** 3 · **Status:** implemented

Payroll had to move off the request thread — chunking over every active employee against a documented 30s/500-employee budget measured on dedicated hardware, with a shared host's `max_execution_time` typically 30–120s.

The tempting shape was to restructure `PayrollEngine` around the job. Master plan §20 says do not redesign payroll calculation unless tests prove a defect, and none do.

**Decision:** split `process()` into `begin()` (reserve the run) and `runEntries()` (compute it), with `process()` still calling both. Every existing caller and all 34 payroll tests pass untouched; nothing below that line moved.

`tries = 1` on the job, also deliberate: idempotency protects against a *client* replaying the request, not against the job running twice on the same run, which would append a second set of entries.

---

## D-001 — `withoutGlobalScopes()` stays in the import de-duplication

**Date:** 2026-09-15 · **Phase:** 0.5 · **Status:** implemented

`EmployeeImporter::commit()` de-duplicated on `import_key` through `withoutGlobalScopes()` with no tenant predicate, reading every tenant's rows (P0-1, `audit/BASELINE.md` §11b).

The obvious fix was to delete the bypass: the global scope would then do the right thing, and it would match the surrounding method, which relies on the scope everywhere else.

**That would have been wrong.** `Employee` uses `SoftDeletes`, so the bypass drops the soft-delete scope *as well as* the tenant scope. Re-importing a key whose employee was soft-deleted is meant to be skipped, not duplicated. Removing the bypass would have silently started creating duplicate employees — a second defect introduced while fixing the first, and one no test would have caught.

**Decision:** keep the bypass, add only `->where('tenant_id', $tenantId)`. Fixes the isolation defect and changes nothing else.

**Reverse it if:** `Employee` stops soft-deleting, at which point the bypass has no remaining purpose and should go.

**Evidence:** `api/app/Models/Employee.php:38`, `tests/Feature/Security/TenantImportIsolationTest.php` — the fourth test pins the soft-delete behaviour precisely so this reasoning is not re-litigated.

---

## D-002 — Documentation is indexed, not reorganized

**Date:** 2026-09-15 · **Phase:** 1 · **Status:** implemented, with a deliberate deferral

Master plan §13 specifies a directory structure: `architecture/`, `deployment/`, `operations/`, `security/`, `testing/`, `decisions/`, `audit/`.

Measured before moving anything: 37 top-level documents, 56 markdown links, and roughly **150 backticked bare-name references in prose** — `` `DEPLOYMENT.md` `` appears 12 times, `` `ARCHITECTURE.md` `` 7 times, and so on.

Markdown links break visibly and can be checked. Prose references do not: move `DEPLOYMENT.md` into `deployment/` and all twelve mentions stay rendered, stay plausible, and quietly point at the wrong place. Fixing them means 150 edits across 37 files for the benefit of directory tidiness — the kind of change §56 calls a cosmetic rewrite, with a failure mode that is invisible rather than loud.

**Decision:** create the directories that carry genuinely new content (`decisions/`, `operations/`, alongside the existing `audit/` and `deployment/`), where nothing can break. Leave existing files where they are. Solve the real problem — 37 documents with no index — with `docs/README.md`, grouped by the §13 themes.

The same reasoning defers renaming the three unrelated meanings of "migration" and normalizing the lowercase end-user guides. Both are recorded in `docs/README.md` rather than pretended away.

**Reverse it if:** a link checker runs in CI (Phase 2/3). With prose references mechanically verifiable, the move becomes a safe, checkable change rather than a hopeful one. That is the right time to do it.

---

## D-003 — Documentation stopped claiming a CI that does not exist

**Date:** 2026-09-15 · **Phase:** 1 · **Status:** implemented

Four documents described CI as an active control: `CLAUDE.md` said tenant isolation was validated "in CI — every commit"; `SECURITY.md` assigned OWASP A06 to a "CI job, monthly review". No pipeline of any kind exists.

The tempting alternative was to leave the text and build the pipeline in Phase 3, making it true. Rejected: a documented control that nobody runs is worse than an admitted gap, because it stops people looking. `SECURITY.md`'s A06 row in particular was the only thing standing between this project and an unaudited dependency tree.

**Decision:** correct each to what is true today, with a note in `SECURITY.md` to restore the original wording once Phase 3 wires the pipeline.

**Reverse it if:** Phase 3 lands. Restoring the claims then is the *point*.

---

## D-004 — Test counts: live documents measure, historical records get dated

**Date:** 2026-09-15 · **Phase:** 1 · **Status:** implemented

Seven documents carried six different test counts (954 / 1328 / 1330 / 1647 / 1652 / 1669), none dated, all reading as current.

Replacing them all with one number would have been wrong twice over: it would falsify historical records that were accurate when written, and it would rot again the moment the suite grew.

**Decision:** distinguish by document type. Live documents (`README.md`, `PRODUCTION_CHECKLIST.md`) get the measured figure **and the command to reproduce it**. Point-in-time records (`security-audit.md`, the narrative rows in `ENTERPRISE_ROADMAP.md`) keep their numbers and gain a date.

The measured figure, first in the repository with a command and exit code attached: **1673 passed, 4966 assertions**, 2026-09-15.

**Reverse it if:** CI publishes counts, at which point live documents should link to a run rather than quote a number at all.

---

## D-005 — `docs/VPS_DEPLOYMENT.md` is kept, not deleted

**Date:** 2026-09-15 · **Phase:** 1 · **Status:** implemented

The file was staged for deletion in the working tree with no successor: 20 KB, the only server-specific runbook, carrying two commits of corrections learned from a real first deploy (`27f2f0b`, `f20c169`).

Deleting it is defensible on the face of it — the VPS is not the target any more. But master plan §60 keeps VPS production assets until the Plesk cutover is verified *and observed*, which makes this the rollback path, and its replacement (`deployment/PLESK_DEPLOYMENT.md`) cannot be written until Gate 0 has measured the host.

**Decision:** restore it with a deprecation header stating why it is kept and the condition for retiring it — cutover verified, rollback rehearsed, observation period passed.

**Reverse it if:** those three conditions are met.

---

## D-006 — `docs/AGENTS.md` becomes a pointer rather than a deletion

**Date:** 2026-09-15 · **Phase:** 1 · **Status:** implemented

`AGENTS.md` and `CLAUDE.md` were one document, forked, drifted 288 diff lines apart. Only `CLAUDE.md` received commit `0997faa`'s correction retiring the "ignore all Git functionality" instruction, so `AGENTS.md` went on forbidding Git in a repository with a GitHub remote and 55 branches.

Deleting it was the obvious move. Rejected: agent tooling looks for `AGENTS.md` by convention, and a missing file reads as "this project has no rules" rather than "the rules are elsewhere".

**Decision:** replace the body with a pointer. A pointer cannot drift.

**Reverse it if:** never, unless the `AGENTS.md` convention disappears.

---

## Earlier decisions, recorded elsewhere

| Decision | Where |
|---|---|
| `audit_log` immutability enforced by database triggers, and the cost accepted | [`../AUDIT_LOG_INTEGRITY_DECISION.md`](../AUDIT_LOG_INTEGRITY_DECISION.md) |
| Target Ethio Telecom shared hosting; Options A, C, D withdrawn ("NO VPS", 2026-08-29) | [`../MIGRATION_STATE.md`](../MIGRATION_STATE.md) decision table |
| Redis, Horizon, Reverb and MinIO removed for the shared-hosting target | [`../SHARED_HOSTING_AUDIT.md`](../SHARED_HOSTING_AUDIT.md) §B |
| Git ban retired — Git *is* in use on this project | `0997faa`, and [`../CLAUDE.md`](../CLAUDE.md) |
| Plan-tier gating enforces seat caps only; the `Plan.features` gate deferred | [`../ENTERPRISE_ROADMAP.md`](../ENTERPRISE_ROADMAP.md) row 2.2 |
