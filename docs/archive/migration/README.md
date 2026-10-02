# Archive — hosting-migration documents (moved 2026-10-01)

**Everything in this directory is superseded. Do not act on it.** These are the working
documents of the move from the VPS to Ethio Telecom shared hosting, written between
2026-08-29 and 2026-09-23, mostly before an account existed to measure. They are kept
because they record *why* the migration took the shape it did, not *what to do now*. Each
already carries its own dated supersession notes; this file says where to go instead.

They were moved here, unchanged in content, by audit item **D12** (`auditrecent.md` §6),
which found the migration cluster duplicated across a dozen files that a reader could not
rank. Links into them across the repository were updated in the same change, and the docs
gate (`./scripts/gates.sh docs`) checks every markdown link.

## Read these instead — the canonical set

| Question | Document |
|---|---|
| What may a deployment procedure assume? (the hard rule; outranks every deployment document) | [`../../deployment/SHARED-HOSTING-CONTRACT.md`](../../deployment/SHARED-HOSTING-CONTRACT.md) |
| What has actually been measured on the hosting account? | [`../../deployment/GATE-0-RESULT.md`](../../deployment/GATE-0-RESULT.md) — its *Gate status reconciliation* section |
| How do I deploy? | [`../../deployment/shared-hosting/DEPLOYMENT.md`](../../deployment/shared-hosting/DEPLOYMENT.md) |
| Can we go live? | [`../../deployment/CUTOVER-CHECKLIST.md`](../../deployment/CUTOVER-CHECKLIST.md) |
| What is the plan, stage by stage? | [`../../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](../../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md) |

## What is here

| Document | Written | What it was | Superseded by |
|---|---|---|---|
| [`SHARED_HOSTING_AUDIT.md`](SHARED_HOSTING_AUDIT.md) | 2026-08-29 | Phase 0+1 code audit: what in the application assumed Redis, MinIO, Horizon, a worker | `GATE-0-RESULT.md`, `audit/BASELINE.md` |
| [`SHARED_HOSTING_MIGRATION_PLAN.md`](SHARED_HOSTING_MIGRATION_PLAN.md) | 2026-08-29 | Phase 3 options A/B/C and the pre-registered Go/No-Go rule (§4) | `shared-hosting/DEPLOYMENT.md`; the owner decisions of 2026-09-27 (static export, GitHub Actions scheduler) |
| [`TCO_COMPARISON.md`](TCO_COMPARISON.md) | 2026-08-29 | Cost comparison of the options, with unconfirmed third-party prices | The decision it fed has been taken; the plan is Bronze |
| [`PHASE_A_CHANGE_REVIEW.md`](PHASE_A_CHANGE_REVIEW.md) | 2026-08-29 | Review of the first configuration slice (`migration/config`) | Merged in `12f53de` the same day, so its *"NOT COMMITTED"* status line is stale; `git log` |
| [`MIGRATION_CHANGELOG.md`](MIGRATION_CHANGELOG.md) | 2026-08-29 → 09-18 | Chronological log of the migration effort | Root `CHANGELOG.md`, `git log` |

Two things to know when reading them:

- **Relative paths in their prose are as written.** A mention like `docs/SHARED_HOSTING_AUDIT.md`
  inside these files means the document's old location; the markdown *links* were fixed.
- **`src/api/...` means `src/src/api/...`.** `src/` is the Next.js project root and the source
  is one level down (root `CLAUDE.md` §1). `SHARED_HOSTING_AUDIT.md` writes
  `src/api/client.ts`; the file is `src/src/api/client.ts`.

## Part of the same cluster, and deliberately not moved

D12 named nine documents. Four stay where they are, each for a stated reason — moving a
file that code or a script cites by path leaves that citation silently wrong, which is the
failure this move exists to avoid.

| Document | Why it stays |
|---|---|
| [`../../MIGRATION_STATE.md`](../../MIGRATION_STATE.md) | Cited by path in `scripts/hosting-verification/ethr-hosting-check.php:16` and linked from `scripts/hosting-verification/htaccess-canary/README.md:218` |
| [`../../deployment/SHARED_HOSTING_PLAN.md`](../../deployment/SHARED_HOSTING_PLAN.md) | Cited in `scripts/shared-hosting/deploy.sh:205` and `scripts/shared-hosting/smoke-check.sh:46,74` |
| [`../../B1-B5_GATE_REPORT.md`](../../B1-B5_GATE_REPORT.md) | Cited by path in `scripts/hosting-verification/htaccess-canary/README.md:146`, and by name in `src/src/app/(marketing)/[locale]/landing-content.tsx:140,217` |
| [`../../DATABASE_MIGRATION_PLAN.md`](../../DATABASE_MIGRATION_PLAN.md) | **Not superseded.** The live deployment procedure sends the operator to its *"A data condition that aborts the run"* check before `migrate --force` on imported data (`shared-hosting/DEPLOYMENT.md`), and `audit/MIGRATION-DOCUMENT-INVENTORY.md` classes it CURRENT |

The first three are superseded in substance and can follow once their citations in code and
scripts are updated — a code change, outside a documentation pass.
