# Ask ETHR: answers by rule, and what a model would add later

> **Stage A approved by the owner, 2026-11-23.** Stage B is not approved and needs D1 of
> [`OWNER-DECISION-AI-PROVIDER.md`](../decisions/OWNER-DECISION-AI-PROVIDER.md) reopened.
> Written against `main` at `0a6150d0a4`. This is a plan: what is built and measured is
> recorded in [`BASELINE.md`](../audit/BASELINE.md) as each pull request lands.

## 1. Where this starts from

- **The owner decided on 2026-11-22: no paid model API, rules only**
  ([`OWNER-DECISION-AI-PROVIDER.md`](../decisions/OWNER-DECISION-AI-PROVIDER.md), D1).
  [`docs/CLAUDE.md`](../CLAUDE.md) says the same: "No paid API dependencies". Nothing in
  Stage A calls a model.
- **Two parts of the original request are already built as rules:** the import's own hints
  (#842) and the four checks before payroll approval (#844, BASELINE §25ao).
- **The generic build plan the owner pasted does not fit this repository in seven places:**

| The pasted plan says | This repository |
|---|---|
| `laravel/ai`, Laravel Boost, a fallback provider | One provider behind an `AiProvider` contract, the official PHP SDK, a fake in tests (decision document) |
| Keys in `.env` | Set from `/admin`, stored encrypted, never returned (shared-hosting contract; `SmsGateway`, `BackupDriveSetting`) |
| A generic `AiSuggestion` approval engine | Not needed. Output goes to the review screen that exists: the column matcher, the register |
| A streaming chat drawer | No streaming: SSE through Plesk is unproven, and the frontend is a static export with no server routes |
| `queue:work` from cron | No worker. The queue is drained over HTTP, 25 seconds per drain; a request gets 30 |
| `en` and `am` | `en`, `am` and `om` ship (`App\Support\Locales::SHIPPED`) |
| Skills `ethr-laravel`, `ethr-ui`, `ethr-ethiopia`, `ethr-testing` | None exist. The conventions are `docs/CLAUDE.md` |

## 2. What is built

One surface, **Ask ETHR**, in two stages.

- **Stage A answers by rule.** No model, no cost, nothing leaves the country.
- **Stage B adds a model that reads the question and nothing else.** The model picks which
  known question was asked; the answer is still worked out by rule from the same code as
  Stage A. **No employee record is sent to the model**, which is stricter than D2 asks.

Why this shape: every question worth answering already has a rule-based source that respects
the person's reach. The only thing a model adds is understanding free wording, so that is the
only job it is given.

### Who it is for, and what they ask

| Person | Questions (first catalogue) | Source that already exists |
|---|---|---|
| Employee | My leave balance. My late and absent days this month. Why did my pay change? | `ReportEngine` `leave`, `timesheet`; payslip entries and `calculation_log` |
| Department head | Who is absent today? Who is late today? Who is on leave this week? What waits for my approval? | `ReportEngine` `absences`, `attendance`, `leave` with the viewer's reach; `NextSteps\Providers\ApprovalsProvider` |
| HR | Headcount by department. Who is missing a TIN, a bank account, a phone? Contracts ending in 30 days. New hires this month. | `ReportEngine` `employees`; `EmployeeGaps`; the expiring contract list |
| Payroll | Overtime this month by department. What changed in this run? | `ReportEngine` `timesheet`; `PreApprovalChecks::payChanges()` |

A question enters the catalogue only when a source that respects reach already answers it.
The decision document names the sources that do not (`AttendanceIntelligence`,
`DepartmentAnalyticsService`, `ExecutiveDashboardService`): no answer reads them.

### What the person sees

- **In the command palette (Ctrl+K) and the header search**, which already match typed phrases
  by whole words (`lib/navigation/intent-match.ts`). A question row joins the page and action
  rows. Choosing it shows the answer in place: one figure or up to five rows, the period it
  covers, and a link to the full report with the same filter.
- **Opened empty, the palette offers three questions** that suit the person's role.
- **On a payslip, "Why this changed"**: the two or three parts of pay that moved most since
  the last payslip, from the same comparison the approver's register uses.
- **Every answer says how it was made**: "From your records, by rule".
- **A question that matches nothing** says so, offers the closest three, and offers to send
  the wording to ETHR through Get help, with the person's consent, so the catalogue grows
  from real questions. Nothing typed is stored otherwise.
- **Offline**, the row says it needs a connection.

### The name

"Ask ETHR" while answers are by rule. "ETHR AI" is used only where a model is on, so the
product never claims what it does not do.

## 3. Stage A design

### Backend

- `App\Enums\Feature::Assistant` (`assistant`): switchable from the console, overridable per
  organisation, on by default. The platform kill switch and the grant come from `FeatureGate`
  with nothing new.
- `App\Enums\AssistantQuestion`: the closed list of questions. It reaches `generated.ts`
  through Scramble, as the other closed lists do.
- `App\Services\Assistant\`: `Answerer` (contract), `AnswerCatalogue`, `Answer` (value object),
  `AnswerPeriod`, and one `Answerers\*` class per question. An answerer:
  - names the permission its page already checks, and is refused without it;
  - takes no tenant and no user from input, both come from the request;
  - calls an existing service and shapes the result. It holds no query that bypasses the
    tenant scope, so the bypass inventory does not move.
- `GET /api/v1/assistant/answers/{question}` with a FormRequest, `throttle:dashboard`,
  RFC 7807 errors. It returns figures, never sentences: a headline figure, at most five rows,
  the total, the period and where the full report is. The screen writes the sentence in the
  reader's language, calendar and clock.
- The payslip explanation: one entry against the person's previous released entry. The
  comparison is lifted out of `PreApprovalChecks` into one class both use.
- **No migration in Stage A.**

### Frontend

- `features/assistant/`: the API hooks, a registry (question, who may ask, icon, link) and an
  answer panel inside `QueryBoundary`.
- `lib/navigation/palette-rows.ts`: a third row kind, ranked by the existing `intentScore`.
  Phrases live in the locale files for `en`, `am` and `om`.
- `features/payroll`: the "Why this changed" section beside the calculation log.

### Tests

- Per answerer: the figure equals what the report returns for the same viewer; a department
  head gets only their department; an employee gets only themself; another organisation gets
  403 or 404; the feature switched off refuses.
- `ControllerAuthorizationInventoryTest`, `CrossTenantRouteSweepTest` and
  `TenantScopeBypassInventoryTest` stay green.
- A goals test saying which question each phrase opens, in three languages.
- A browser journey: ask a question, read the figure, open the report and see the same figure.

## 4. Stage B design (only if D1 is reopened)

- **Provider.** An `AiProvider` contract, one adapter on the official PHP SDK, a fake bound in
  tests. Timeout 8 seconds, no retries, so a call fits the 30-second request. Any failure
  (timeout, refusal, error, budget reached, feature off) falls back to the rule matcher.
- **What is sent.** The question text and the list of question ids. Before sending, any word
  that matches an employee the viewer can see is replaced by a placeholder, and put back
  after. No record, no figure, no file. The reply is one question id, a period, or "none".
- **Model.** D3 recorded Claude Opus 5.5 for every feature. For this one job a smaller model
  is an option the owner may choose. Both are to be measured, for accuracy in the three
  languages, response time and cost, before choosing.
- **Controls.** A second feature, off by default, granted per organisation (D4). Consent by
  the organisation's admin (D2). A monthly budget per organisation and for the platform, with
  a hard stop.
- **Data.** A global settings row (key encrypted, set from `/admin`) and a per-organisation
  usage ledger that stores no text. Two migrations; deploy and migrate are one step.
- **Proof.** A fixed set of at least 150 questions in three languages with the expected
  question, scored on every change.
- **Before any of it:** one test that the production host can reach the provider at all.

## 5. Sequence

Each pull request follows the model policy: the plan and the review on the advisory model,
the implementation on the default one, the end-to-end suite before deploy.

| PR | What | Migration |
|---|---|---|
| 0 | This plan, and the approval recorded in the decision document | no |
| 1 | Ask a question and get an answer: the feature, the question list, the first six answers, the endpoint, and the rows in the palette and header search, in three languages | no |
| 2 | "Why this changed" on the payslip | no |
| 3 | Six more questions, suggested questions by role, "send this question to ETHR" | no |
| **Owner gate** | Reopen D1, or stop here | |
| 4 | Provider contract, key in `/admin`, usage ledger, budget, consent, feature off by default | yes |
| 5 | The router, name masking, fallback, the question set | no |
| 6 | Security review, load check, deployment notes, release | no |

The backend and the palette of PR 1 go together: the browser journey needs both, and a
follow-up stacked on an unmerged branch conflicts after a squash merge.

## 6. Not verified when this was written

- The expiring contract list, the leave balance source for a single employee, and what the
  dashboard digest already says were named from documents, not read. PR 1 confirms each
  source before its answer is written.
- The header search on a phone was not opened.
- No timing was measured.
- Whether the production host can make an outbound call to a model provider.
