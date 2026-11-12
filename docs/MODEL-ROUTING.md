# ETHR — Model routing and agent execution policy

> **Moved here from [`CLAUDE.md`](CLAUDE.md) on 2026-10-01** (audit D8), unchanged except where
> marked. It is policy for the AI agents that work in this repository — which model to use, how
> to slice and report work — not a convention of the code. The conventions file is what a
> contributor reads first, and this was a third of it.
>
> Where this file and [`CLAUDE.md`](CLAUDE.md) or root [`../CLAUDE.md`](../CLAUDE.md) disagree,
> those win. One known disagreement: *Completion Report* below says "Do not output Git commands
> … Do not generate commit messages", while the working rules in `CLAUDE.md` → *Local Development Policy* (rewritten
> 2026-08-29) have agents commit in logical slices under its *Commit Convention*. Commit as those say.

## Owner decision — the model hard rule, 2026-11-12

**Stated by the owner on 2026-11-12 and binding on every session in this repository.** It
supersedes nothing below it; it is the shape the rest of this file already described, with the
review step named and the deployment gate attached.

| | |
|---|---|
| **Default development — Sonnet** | Coding and implementation, bug fixing, testing and refactoring, documentation updates |
| **Architecture advisory — Opus** | Multi-tenant architecture, security and tenant isolation, attendance/payroll engine design, complex debugging, critical code review |

**The execution cycle:**

1. Opus analyses and produces an implementation plan.
2. Sonnet implements the approved plan.
3. Opus reviews the implementation and identifies gaps.
4. Sonnet fixes verified issues.
5. End-to-end tests run before deployment.

**What the cycle does not cover.** *Never use Opus for* below still holds, and the cycle holds
with it: a rename, a translation key, a CSS change, a docs-only edit or a one-line fix is
Sonnet start to finish with no plan-and-review round trip. The cycle is for a feature, a
refactor, an engine change or anything touching tenancy, payroll or attendance — the work the
*Opus (Escalate Only)* list already names. Where the two readings of a task differ, the
escalation test under *Cost Optimization* decides.

**How each step is actually executed**, because a session runs on one model:

- **Steps 1 and 2 in one session:** the default model is `opusplan` — plan mode answers on
  Opus, and everything after it on Sonnet. Plan in plan mode, then implement. `/model` changes
  it for a single session. That default reaches terminal sessions and any session with no
  remembered choice; **the desktop app's Code tab keeps its own remembered pick in its model
  picker, and that wins**, so the picker is where a Code session's model is actually decided.
- **Steps 1 and 3 from inside a Sonnet session:** the advisor tool, which is configured to
  Opus, or an Opus subagent (`Agent` with `model: "opus"`) for planning or review only — never
  for implementation. `/code-review` is a legitimate step 3.
- **Step 5 in this repository** is `./scripts/gates.sh e2e-attendance`, which needs a running
  stack and `php artisan queue:work` against the same database (root
  [`../CLAUDE.md`](../CLAUDE.md) → *Quality gates*), plus `./scripts/gates.sh` itself. The
  release steps in [`deployment/PLESK-GO-LIVE.md`](deployment/PLESK-GO-LIVE.md) carry it.
  Say which journeys ran and which did not, and name the ones a session could not run at all
  (a suite that needs credentials this machine does not hold is *not run*, not *passing*).
  Why this rule was stated, and what it did and did not change, is in
  [`audit/BASELINE.md`](audit/BASELINE.md) §25n.

The machine-wide copy of this rule, for every other project, is `~/.claude/CLAUDE.md`, and the
default model is set in `~/.claude/settings.json`. Neither is in this repository, so neither is
reviewable here — this file is the reviewable statement, and **the rule is instruction, not
enforcement**: nothing in the gates fails when a session skips step 3.

---

## AI Execution Policy (Pro/Max)

### Automatic Model Routing

Prefer the lowest-cost model capable of completing the task.

#### Sonnet (Default)

Use Sonnet automatically for:

- CRUD implementation
- Controllers
- FormRequests
- Policies
- API Resources
- Models
- Migrations
- Frontend components
- React pages
- Tailwind styling
- API hooks
- Unit tests
- Feature tests
- Documentation
- Translation keys
- Refactoring under 10 files
- Bug fixes
- Formatting
- Type fixes
- Lint fixes

Target: 90% of development work.

---

#### Opus (Escalate Only)

Automatically escalate to Opus when any of these are true:

- Architectural decisions
- Security review
- Multi-file refactor (>10 files)
- Tenant isolation changes
- Authentication or authorization design
- Complex debugging after two failed attempts
- Performance optimization
- Query optimization
- Database redesign
- Event-driven architecture
- Queue design
- Offline sync logic
- PWA synchronization
- Payroll calculation engine
- Attendance matching engine
- Conflict resolution logic
- Permission system changes
- Large context analysis (>30 files)
- Reviewing an entire phase
- Release readiness audit

Return to Sonnet immediately after the architecture is decided.

---

#### Never use Opus for

- Formatting
- Renaming files
- Small UI changes
- CSS
- Translation
- Documentation only
- Boilerplate CRUD
- Simple tests
- Small bug fixes

These should always stay on Sonnet.

---

#### Cost Optimization

Before escalating to Opus ask internally:

1. Is this mainly implementation?
2. Is reasoning actually required?
3. Can Sonnet complete this safely?

If YES to implementation, remain on Sonnet.

Only escalate when reasoning complexity exceeds implementation complexity.

---

## Task Decomposition Rules

Never attempt an entire phase in one execution.

Break work into slices no larger than:

- one feature
- one controller
- one policy
- one page
- one service

Each slice must compile independently.

Complete:

Analyze → Implement → Test → Commit

before starting another slice.

---

## Context Management

Avoid re-reading the entire repository.

At the beginning of each task:

Read:

- CLAUDE.md
- Relevant phase document
- Files directly related to the feature

Do not scan unrelated modules.

If more than 30 files are required:

Create an implementation plan first.

Then implement incrementally.

---

## Response Policy

Do not explain every generated file.

Output only:

- files changed
- summary
- remaining tasks
- blockers

Keep explanations under 200 words unless asked.

Minimize token usage.

---

## Large Refactor Rules

When touching more than 20 files:

Phase 1: Analyze, identify dependencies, produce plan.

Phase 2: Implement.

Phase 3: Run tests.

Never mix planning and implementation in the same large task.

---

## Build Lifecycle

Every task must follow this lifecycle.

Do not skip any step.

Do not mark a task complete until every validation passes.

```
Read CLAUDE.md
       |
Read relevant phase document(s)
       |
Analyze current code
       |
Verify existing implementation
       |
Determine completed vs missing work
       |
Understand architecture + dependencies
       |
Implement ONE feature slice
       |
Backend
(migration → model → service → controller → FormRequest → Policy → tests)
       |
Frontend
(types → API hooks → components → pages → tests)
       |
Run formatter
(Pint + Prettier)
       |
Run static analysis
(PHPStan + TypeScript)
       |
Run Pest tests
       |
Run Vitest tests
       |
Run TenantIsolationTest
(if applicable)
       |
Verify Browser
Desktop
Tablet
Mobile
Dark
Light
       |
Fix issues
       |
Re-run validation
       |
Update documentation
       |
Output:
Files Changed
Summary
Remaining Tasks
Blockers
       |
Continue to next highest-priority unfinished feature
```
### Completion Report

After every completed task output ONLY:

Files changed

Summary

Validation performed

Remaining tasks

Known blockers

Do not output Git commands.

Do not generate commit messages.

Do not reference repositories.

*(A stray closing code fence after this list, in the original, opened a code block that swallowed
the next section of `CLAUDE.md` — removed in the move.)*

---

## Auto Resume Protocol

If generation stops for any reason:

- rate limit
- internet disconnect
- browser refresh
- power outage
- token limit

then the NEXT user message:

Continue

means:

1. Reload entire CLAUDE.md
2. Read all completed work
3. Determine unfinished task
4. Continue exactly where stopped
5. Do not repeat completed work
6. Do not ask unnecessary questions
7. Continue until task completes or another interruption occurs.
