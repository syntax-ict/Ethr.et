# Decision Log

Decisions that shaped ETHR, each with what would reverse it. Newest first.

This log starts at Phase 0 (2026-09-15). Earlier decisions live in commit messages and in the documents they produced — `AUDIT_LOG_INTEGRITY_DECISION.md` and `MIGRATION_STATE.md`'s decision table are the substantial ones, and they are referenced rather than copied here.

A decision belongs here when someone could reasonably have chosen otherwise and will later wonder why we didn't.

---

## D-012 — Employee search drops FULLTEXT and uses `LIKE` everywhere

**Date:** 2026-09-15 · **Phase:** testing · **Status:** implemented

`Employee::scopeSearch()` branched on driver: `MATCH … AGAINST` on MySQL, `LIKE`
on SQLite. The suite runs on SQLite, so the production branch had never executed
in a test, and it was wrong — stripping `-` from the term turned `EMP-1234` into
`EMP1234`, which matches no token, so searching an employee's code in full found
nobody (§13f).

Three repairs were considered.

**Fix the boolean expression** — tokenise and require each token, `+EMP* +1234*`.
Measured: fixes the code, breaks `Ab Kebede`, because `innodb_ft_min_token_size`
is 3 and a required term matching nothing eliminates the row. Compensating for
that means reading a server variable we cannot see on the target host, which §8
forbids assuming.

**`MATCH … OR LIKE …`** — a strict superset, so no result can be lost. Rejected
as the worst of both: the `LIKE` half forces the scan anyway, so the index buys
nothing, and the method keeps two behaviours to reason about.

**`LIKE` on every driver.** Chosen. One implementation, so the tested path *is*
the production path — which is the property whose absence caused the defect, not
merely a tidiness argument.

**What it costs:** `LIKE '%term%'` cannot use an index. The scan is bounded by
`tenant_id`, which is indexed, so it covers one tenant's rows. Measured on
MariaDB 10.4.32 at 5,000 employees in a tenant: **~13ms** against the **100ms**
budget in `docs/CLAUDE.md`. The budget is reached near 40,000 employees in a
single tenant on that hardware.

**Revisit if:** a tenant approaches that size, or a host measurement (§13e, still
absent) shows the shared-hosting figure is much worse than the local one. The
answer then is a prefix/trigram index or a search service — not a return to the
branch, which would restore the divergence.

**Left undone — closed 2026-09-16.** The `emp_search` FULLTEXT index was left in
place, unused. `2026_09_16_000001_drop_employee_fulltext_index` removes it.

An unused FULLTEXT index is not free: InnoDB maintains it on every insert and on
every update touching `name`, `name_am`, `email` or `employee_code` — most
employee writes, bulk imports included — and it carries its own auxiliary
tables. It also misleads, implying a fulltext search that no longer exists.

Verified on MariaDB 10.4.32 through a full round trip: `up()` drops it, `down()`
restores all four columns, `up()` again drops it. Both directions are guarded by
an existence check, so neither fails on a database where it was already dropped
by hand. SQLite skips, as the original migration did.

`down()` is for rollback, not as a plan. The revisit condition above names a
prefix or trigram index, or a search service — **not** a return to the branch
this index existed to serve.

---

## D-011 — The suite can be run on MySQL, but is not gated on it

**Date:** 2026-09-15 · **Phase:** testing · **Status:** implemented

The suite runs on SQLite `:memory:`; production runs on MariaDB. Three defects
this session were driver-specific and invisible on SQLite — a `DATE` column
truncating a time component, `PDO::quote()` not escaping newlines, and
`TRUNCATE` implicitly committing. In each case **the driver the tests ran on was
the one where the bug could not appear.**

The obvious response is to switch the suite to MySQL, or to add a MySQL run to
`scripts/gates.sh`. Both were rejected.

Switching outright costs the property that makes the suite useful: SQLite
`:memory:` needs no service, runs anywhere, and is fast enough that people run
it. Measured on MariaDB 10.4.32, the same tests are roughly 3× slower even
after the seeder fix, and need a server that a fresh clone does not have.

Gating on it is worse, and for a reason this repository has already written
down twice: a gate that cannot run reports success. On a machine with no MySQL
the run would either fail — making the gate permanently red, which is how
`security` and `performance` ended up outside the full sweep — or skip, which
is indistinguishable from passing.

**Decision:** add `api/phpunit.mysql.xml`, differing from `phpunit.xml` in seven
`<env>` lines, and document when to reach for it: before a release, and after
touching dates, raw SQL or schema. Deliberately outside `gates.sh`.

**Reverse it if:** CI gains a MySQL service container. There the server is
guaranteed, so the run cannot silently skip, and it should become a gate.

### Reversed the same day, 2026-09-16 — the condition was already met

`.github/workflows/gates.yml` already ran a `mariadb:10.11` service container
for the API-contract job, so the premise of the paragraph above was wrong when
it was written: CI *did* have a guaranteed server, and the only thing missing
was a job that used it for the suite.

There is now a `mysql` scope and a `backend-mysql` CI job that calls it. Two
details carry the reasoning that the original decision was protecting:

- **The scope fails when no server is reachable. It does not skip.** That is the
  point: a skipped gate reports success, and this repository has been caught
  twice by checks that were green because they had not run. Someone without a
  local MySQL asks for a different scope rather than being handed a false pass.
- **It stays out of `all`**, so a fresh clone can still run the full sweep with
  no services. CI calls it by name, the way it calls `security`.

The CI job connects as the non-root `ethr` user rather than root, which also
exercises whether the `audit_log` trigger migration survives without `SUPER` —
the question gate **G0-F** asks about the production host, and the closest
answer available without the host itself.

**Reverse *that* if:** the job proves flaky for environmental reasons rather than
code ones. The answer then is to fix the environment, not to downgrade the job
to non-blocking — a gate nobody has to pass is documentation.

**Related:** `database/seeders/PermissionSeeder.php:32` — the `truncate()` →
`delete()` change that made a MySQL run finish in minutes rather than hours. Its
comment is the reference for the implicit-commit trap.

---

## D-010 — A generated invoice counts as sent

**Date:** 2026-09-15 · **Phase:** billing · **Status:** decided by the owner

`BillingService::generateMonthlyInvoice()` writes `status => 'draft'`. `HandleOverdueInvoicesJob` tier 1 matches `status = 'sent'`. Nothing in `app/` transitions between them, so no invoice ever enters dunning: no reminders, no past-due subscriptions, no suspensions for non-payment (§15d).

The one-line fix is obvious and wrong. Making invoices `sent` on creation would mean "sent" = "generated" — and **nothing emails invoices either**. Dunning would then suspend customers at 60 days for not paying a bill they were never sent. That is worse than an inert chain, and it fails quietly.

**Owner decision, 2026-09-15: invoices are created `sent`.** `generateMonthlyInvoice()` now writes that status, and the dunning chain is verified end to end — generate, remind at 7 days, past-due at 30, suspend at 60.

The concern above was raised and overruled, which is recorded here rather than argued again. What it means in practice: dunning escalates on a bill the tenant has only seen in-app, and tier 3 suspends them. **The 7-day reminder still sends nothing** — it logs a warning saying so. If a send step is built later, that warning is where to hook it.

**Revisit if:** customers are suspended without having been contacted. That is the failure this shape allows, and it is now reachable.

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
