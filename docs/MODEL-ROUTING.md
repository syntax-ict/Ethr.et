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
