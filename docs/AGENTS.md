# ETHR — Development Rules

**This file is a pointer. The rules live in [`docs/CLAUDE.md`](CLAUDE.md).**

Read that. Nothing here is authoritative.

---

## Why this file is now a stub

`docs/AGENTS.md` and `docs/CLAUDE.md` both opened with the identical header — *"ETHR — Global Development Rules (v2.0)"* — and the same section list. They were the same document, forked. By Phase 1 they had drifted 288 diff lines apart (859 lines vs 1026), and only one of them was being corrected.

The drift that mattered most: commit `0997faa` retired a "LOCAL DEVELOPMENT POLICY (NON-NEGOTIABLE)" section that instructed agents to *"Ignore ALL Git-related functionality"*. That instruction had always contradicted the Commit Convention sitting a few hundred lines below it in the same file, and the repository is a Git repository with a GitHub remote and 55 branches. `CLAUDE.md` received that correction. **`AGENTS.md` never did**, so it went on telling readers that Git was forbidden while the project ran on it.

Two rule files, one corrected and one not, is the exact failure `0997faa` was written to end — reproduced one file over. Every session had to pick one and guess.

So this one stops being a rule file. It is not deleted, because agent tooling looks for `AGENTS.md` by convention and a missing file reads as "no rules" rather than "rules are elsewhere". A pointer that cannot drift is better than a copy that can.

## If you are an agent

1. Read [`docs/CLAUDE.md`](CLAUDE.md) — the 15 Non-Negotiable Conventions, the design system, the API and testing rules.
2. Read the root [`CLAUDE.md`](../CLAUDE.md) for the environment traps that will otherwise cost you a day.
3. Read [`CONTRIBUTING.md`](../CONTRIBUTING.md) for how to actually run and test the thing.
4. Read [`docs/audit/BASELINE.md`](audit/BASELINE.md) for what is measured versus merely documented. Several documents in this tree assert controls that do not exist — CI is the notable one — and the baseline says which.

## What this replaced

The full text lives in git history. To read it as it stood:

```bash
git log --follow -- docs/AGENTS.md
git show <commit>:docs/AGENTS.md
```

Its last substantive revision was `21c572b` ("docs: consolidate documentation under docs/"). Everything in it that was still true is in `CLAUDE.md`; what was not is the reason this file is a stub.
