# Repository governance — 2026-10-09

A synchronization and branch-governance pass over the local clone (`F:\ethr.et`) and
`github.com/syntax-ict/Ethr.et`. Every figure below was read from Git or the GitHub API
on the day. The standing procedure is in
[`CONTRIBUTING.md` → *Branches and releases*](../../CONTRIBUTING.md#branches-and-releases).

## A. Initial state

| | |
|---|---|
| Origin | `git@github.com:syntax-ict/Ethr.et.git`, matching the expected remote. Public, user-owned, viewer has ADMIN |
| Git | 2.50.1.windows.1; not shallow; no submodules; **no tags** |
| Local branch | `main` at `af48c7ea`, **2 behind** `origin/main` `60105ca7` |
| Working tree | One modified file, `src/next-env.d.ts`. `next dev` rewrote `.next/types` to `.next/dev/types`. It is generated, it is not user work, and it was left as found |
| GitHub default branch | `main` |
| Stash | `stash@{0}` on `feat/laravel-toolkit-docroot` (#164, merged). It still differs from `main` in 3 scripts under `scripts/shared-hosting/` |
| Worktrees | `.claude/worktrees/redundancy-audit` on `feat/maintenance-endpoint`, clean and pushed |

**Discrepancies found:**

1. **`main` was unprotected.** `CONTRIBUTING.md` recorded a ruleset "applied 2026-09-24 and
   verified enforcing". The live reads were:
   - `GET /rulesets` (with and without `includes_parents`): `[]`
   - `GET /rules/branches/main` and `/rules/branches/production`: `[]`
   - Classic protection: 404 on both branches

   Nothing on GitHub was protecting either branch.
2. **`production` is not a promotion target, and the request assumed it was.** It shares no
   history with `main`: `git merge-base` returns nothing, and `rev-list --left-right` gave
   800/15. [`release.yml`](../../.github/workflows/release.yml) builds it as a sequence of
   `release: <sha>` commits. The owner chose to keep this model: no main→production pull
   requests.
3. `docs/CLAUDE.md` says to merge with `--no-ff`, but the repository squash-merges every PR.
   Flagged here and left unchanged.

## B. Final branch model

| Branch | Tip | Upstream | Protection (effective rules) |
|---|---|---|---|
| `main` | `1916df5d` (#176) | `origin/main` | Ruleset `main` 24799448, **active**: `deletion`, `non_fast_forward`, `pull_request` (0 approvals), `required_status_checks` (9 jobs, strict) |
| `production` | `7440b097` = `release: 1916df5dc90f` | none locally (remote only; nothing is developed on it) | Ruleset `production` 24799471, **active**: `deletion`, `non_fast_forward` |

The default branch is `main`. *Automatically delete head branches* was turned on
2026-10-09.

## C. Synchronization matrix

| | Local | Remote | Ahead / behind | Working tree | Result |
|---|---|---|---|---|---|
| `main` | `1916df5d` | `1916df5d` | 0 / 0 | `src/next-env.d.ts` modified (generated) | **synchronized** |
| `production` | (not checked out) | `7440b097` | n/a | n/a | **release of the `main` tip**. Plesk release run for `1916df5d`: success |

**Git sync and release sync are separate questions:**

- **Git sync:** both branches on GitHub match what was read locally.
- **Release sync:** `production` is the build of the current `main` tip.
- **Deployment:** this pass did not observe it. Plesk Git deploys only when the owner presses
  Deploy.

## D. Branch cleanup ledger

Each PR head SHA was checked against the local branch tip before deletion. The PRs were
squash-merged, so `-D` was required, and that check is why it was safe.

| Branch | Tip | PR | Local | Remote |
|---|---|---|---|---|
| `chore/clear-vps-dependencies` | `1dbd347a` | #170 merged | deleted | deleted (GitHub web UI, see G) |
| `chore/redundancy-audit` | `58fc8180` | #174 merged | deleted | deleted (GitHub web UI, see G) |
| `test/e2e-coverage-gaps` | `21b0675f` | #175 merged | deleted | deleted (GitHub web UI, see G) |
| `feat/maintenance-endpoint` | `42b7fda2` | #176 merged by the owner 15:21Z | deleted; worktree deregistered | deleted (GitHub web UI, see G) |
| `feat/canonical-tenant-address` | `42f5a5a5` | #168 merged | deleted | already gone |
| `feat/tenancy-without-subdomains` | `a5cdea62` | #166 merged | deleted | already gone |
| `fix/production-readiness-audit` | `afcf5da2` | #167 merged | deleted | already gone |
| `migration/bronze-plesk` | `1822d0a5` | #133 merged | deleted | already gone |
| `origin/chore/ignore-claude-local-state`, `origin/feat/api-key-guard` | — | #173, #172 merged | stale tracking refs pruned by `fetch --prune` | already gone |
| `fix/qa-full-sweep` | `de84d7ca` | #171 merged | n/a | **retained**: eligible, but not in this pass's authorization |
| `claude/clever-noether-u8dj10` | `b730183c` | #21 **closed unmerged** | n/a | **retained**: 18 patch-unique commits, owner undecided |
| `claude/ethr-tenant-landing-pages-a59gly` | `9b3d41ed` | #17 open | n/a | retained |
| `claude/public-landing-page-upgrade-u6m2aq` | `75f08406` | #18 open | n/a | retained |
| `claude/sandbox-local-toolchain` | `f48c1366` | #132 open | n/a | retained |
| `dependabot/…` ×3 | — | #156, #158, #161 open | n/a | retained |

## E. Production release flow (verified against `release.yml`)

`feature branch → PR into main (9 required checks) → merge → Quality gates on the push →
Plesk release builds, rehearses two deploys, pushes release: <sha> to production →
owner presses Deploy in Plesk Git → release steps in PLESK-GO-LIVE.md`

- **Promotion status:** none pending. `production` already holds the build of `main`'s
  tip.
- **Deployment trigger:** manual (Plesk Git *Deploy*). Nothing in the repository
  deploys.

## F. Changes made

- **Remote operations** (the 4 branch deletions are listed in D):
  - `delete_branch_on_merge` → `true`.
  - Created rulesets 24799448 (`main`) and 24799471 (`production`).
  - Verified both through `/rules/branches/*`.
- **Local operations:**
  - `fetch --prune`, then fast-forwarded `main` to `1916df5d`.
  - Deleted 8 local branches (ledger D).
  - Deregistered the worktree. Windows kept the empty directory because another process
    held it.
- **Files:**
  - `CONTRIBUTING.md`: the protection section records the absence and the re-application;
    the required checks went from 7 to 9; added the `production` ruleset and *Branches and
    releases*.
  - This report.

## G. Outstanding

1. **Closed later the same day:** four merged remote branches were deleted from the
   GitHub *Branches* page, at the owner's request: `chore/clear-vps-dependencies`,
   `chore/redundancy-audit`, `test/e2e-coverage-gaps` and `feat/maintenance-endpoint`.
   The agent's permission classifier had refused `git push origin --delete`.
   `git ls-remote --heads` confirmed each deletion. Each can be restored from its PR page.
   Merges from now on clean up after themselves.
2. Release `release: 1916df5dc90f` is published, but deployment is the owner's step in
   Plesk. Per earlier records, the host database is empty, and the cron secrets are
   deferred until the release steps have run.
3. Six open PRs need owner triage. `stash@{0}` and `claude/clever-noether-u8dj10` need an
   owner decision.
4. The `--no-ff` instruction in `docs/CLAUDE.md` contradicts the repository's
   squash-merge practice.

## H. Verdict

**VERIFIED SYNCHRONIZED** for Git:

- `main` is 0/0 against GitHub.
- `production` is the release build of `main`'s tip.
- Both rulesets are in force.
- No user work was lost: the generated `src/next-env.d.ts`, the stash and every unmerged
  branch are intact.

Deployment to the host is a separate, manual step that this pass did not perform or
observe.
