# ETHR — agent instructions

**This file is a pointer. Nothing here is authoritative.**

Read, in this order:

1. **[`CLAUDE.md`](CLAUDE.md)** — the four environment traps that will otherwise cost you
   a day, the quality gates, and the tenant-isolation rules.
2. **[`docs/CLAUDE.md`](docs/CLAUDE.md)** — the 15 Non-Negotiable Conventions, the design
   system, the API and testing rules.
3. **[`CONTRIBUTING.md`](CONTRIBUTING.md)** — how to actually run and test the project.
4. **[`docs/audit/BASELINE.md`](docs/audit/BASELINE.md)** — what is *measured* versus merely
   documented. Several documents in this tree assert controls that do not exist; the
   baseline says which.

## Why this file exists

It exists for the same reason the root `CLAUDE.md` does, and for the same reason
`docs/AGENTS.md` was kept as a stub rather than deleted: **agent tooling looks for
`AGENTS.md` at the repository root by convention, and an absent file reads as "this project
has no rules" rather than "the rules are elsewhere."**

There was a `docs/AGENTS.md` and no root one. That is the worse arrangement of the two,
because tooling that searches the root finds nothing and tooling that finds
`docs/AGENTS.md` lands on a stub. Created 2026-09-27 from the forensic audit's finding
C-10(h).

**It is deliberately a pointer and not a copy.** `docs/AGENTS.md` records what happened the
last time this project had two rule files: they drifted 288 diff lines apart, only one was
being corrected, and the uncorrected one went on telling readers that Git was forbidden
while the project ran on it. A pointer cannot drift.

## If you are about to work on the hosting migration

The production target is **Ethio Telecom Bronze shared hosting under Plesk**. Three things
outrank the rest, and getting them wrong is expensive:

- **[`docs/deployment/SHARED-HOSTING-CONTRACT.md`](docs/deployment/SHARED-HOSTING-CONTRACT.md)**
  outranks every deployment document. Work happens in the repository; hosting settings are
  not a path.
- **[`docs/deployment/GATE-0-RESULT.md`](docs/deployment/GATE-0-RESULT.md)** is the
  host-evidence register. Read its *Gate status reconciliation* section for current status —
  the tables above it are dated. **Never convert `NOT VERIFIED` into `VERIFIED` without a
  measurement**, and never treat a 404 on an absent probe as a pass.
- **[`docs/decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md`](docs/decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md)**
  was **decided 2026-09-27: static export served by Apache**. Do not enable the Plesk
  Node.js application, and do not render deployed rules with `--branch=a` (that is the
  Node branch).

Two standing prohibitions, both with reasons recorded in the tree: **do not soften the
audit-log trigger migration** to get past a possibly-missing `CREATE TRIGGER` grant, and
**do not change the Plesk Document root field** — the default is already correct and a wrong
value web-serves `~/ethr/api/.env`.
