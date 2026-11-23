# AI features: rules only for now, no paid model API

> **OWNER DECIDED, 2026-11-22.** ETHR adds no hosted AI model, so no paid model API
> dependency. [`docs/CLAUDE.md`](../CLAUDE.md) ("No paid API dependencies") stands unchanged.
> The "smart" checks the owner asked for are built as rules:
>
> - the checks before payroll approval
>   ([syntax-ict/Ethr.et#842](https://github.com/syntax-ict/Ethr.et/pull/842) is the import half;
>   the payroll half is the pull request that adds this document);
> - BASELINE §25ao.
>
> Three further answers were given for the day this is reopened. They are recorded below and
> **authorise nothing today**.

## The request

On 2026-11-22 the owner pasted a generic "ETHR AI Build Plan" and asked for a plan to add AI
features. The plan had six phases:

1. Laravel Boost, the Laravel AI SDK, and new agent skills.
2. An AI gateway and a generic "AI suggestion" approval engine.
3. A smart import assistant.
4. A bilingual HR question-answering assistant.
5. AI payroll and attendance checks.
6. Ship.

A read of the codebase that day found two things that changed the plan.

**Much of it already existed as rules.** The import already had:

- header aliases, Amharic included;
- saved per-organisation mappings;
- phone canonicalisation;
- duplicate matching;
- a fill-empty mode, run in slices.

Attendance already flagged buddy punching and shared devices. The payroll register already listed
unexplained absences, the deduction ceiling, overtime limits and open integrity flags.

**The rest of the "AI" value was deterministic and missing.** The register lacked four checks:

- net pay compared with the last run;
- duplicate bank accounts;
- missing TIN;
- people paid with no attendance.

The import lacked four things:

- calendar detection from the dates themselves;
- Amharic spelling variants;
- typo-tolerant suggestions;
- a warning for one name on two lines.

## The four questions, and the answers

| | Question | Answer |
|---|---|---|
| **D1** | Grant an exception to "no paid API dependencies" for a hosted model? | **No, rules only for now.** |
| D2 | What employee data may be sent to a provider outside Ethiopia (Data Protection Proclamation 1321/2024 covers cross-border transfer)? | Redacted data only, after each organisation's admin consents. Names, numbers, phone, salary, bank and TIN never leave. |
| D3 | Which model? | Claude Opus 5.5 for every feature. |
| D4 | Who gets AI features? | Off by default, granted per organisation through the Feature Control Center. |

D2 to D4 apply only if D1 is reopened.

## 2026-11-23: answers by rule approved, D1 unchanged

The owner asked again for an AI feature and approved **Stage A** of
[`docs/ai/ASK-ETHR-PLAN.md`](../ai/ASK-ETHR-PLAN.md): a person types a question and gets the
answer from their own records, worked out by rule. It calls no model, costs nothing and sends
nothing out of the country, so it needs no exception and D1 stands.

Stage B of that plan (a model that reads the question, and only the question) is **not
approved**. It waits for D1, and the plan lists what would have to be settled first.

## If D1 is reopened

What the 2026-11-22 read of the codebase says an AI layer would have to respect:

- **One provider, behind a contract.** Use the official `anthropic-ai/sdk` behind an
  `AiProvider` interface, with a fake bound in tests. The SDK does not go through Laravel's HTTP
  client, so `Http::fake` would not intercept it.
- **The key is set from `/admin`.** Store it encrypted and never return it, as
  `SmsGateway.auth_secret` and `BackupDriveSetting` are. It is not set in `.env` or the hosting
  panel; see the shared-hosting contract.
- **Every feature is a `Feature` case**, so the platform kill switch and the per-organisation
  grant come from `FeatureGate` with nothing new. Consent is a `tenants.settings` key. Usage is a
  ledger of its own (`ai_usage`), not rows in the immutable `audit_log`.
- **Time limits.** Plesk PHP allows 30 seconds per request and a queue drain gets 25, so a whole
  call, retries included, must fit inside them. The SDK defaults to a 10-minute timeout and 2
  retries. There is no streaming: SSE through Plesk is unproven, and the frontend is a static
  export with no server routes.
- **An assistant's tools read only the sources that respect a person's reach:** `ReportEngine`,
  `EmployeeGaps`, the expiring contract and document lists, `DepartmentDashboardService`.
  `AttendanceIntelligence`, `DepartmentAnalyticsService` and `ExecutiveDashboardService` are
  organisation-wide or branch-wide and would show a department head everyone. A tool takes no
  tenant or user argument; both come from the request.
- **A model only suggests.** Its output goes to the review screen that exists for it: the
  column matcher for import, the register for payroll. A generic suggestion engine is not
  needed for that, and `ApprovalEngine` is typed to four models.
