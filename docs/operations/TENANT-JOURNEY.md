# Tenant journey

Where each organisation is between sign-up and using ETHR, and how healthy that
road looks. Written from `api/app/Services/Admin/TenantJourney/JourneyCalculator.php`.
A figure on the console that disagrees with this page is a defect in one of
the two.

Added 2026-11-06. Before then the console knew an organisation's status, trial
end, headcount, plan and invoices, and nothing about whether anyone had set it
up, signed in or punched. The only way to find out was to impersonate its admin.

## What it is

- **A snapshot per organisation**, in `tenant_journey_snapshots`, recomputed
  hourly by `ethr:refresh-tenant-journeys` (only snapshots older than fifty
  minutes) and when one organisation's page is opened with a snapshot older
  than an hour. It is a table because the readiness score alone is about thirty
  queries, too many to run per row of a list.
- **Read only.** Counts, dates and reason codes. The console never shows an
  employee's or a user's own record here (blueprint P15).
- **Derived from history.** No milestone has a hook of its own: each date is read
  back out of records that already exist, so every organisation, however old,
  is back-filled by its first refresh.
- **Shown in two places**: the organisations list (funnel, stage and health
  columns, filters) and the organisation page (the Journey card).

## Stages

An organisation sits at the **highest** stage whose condition holds. An earlier
stage is not required to have been seen first: an organisation that skipped the
setup wizard but punches every day is at `first_use` or beyond.

| Stage | Holds when |
|---|---|
| `registered` | The organisation exists |
| `configured` | `settings.configuration_applied_at` is set, or the audit row `onboarding.configuration_applied` exists |
| `people_added` | At least one current employee (hired, probation, confirmed) who has not been deleted |
| `team_activated` | At least one user other than the founder and platform staff has `activated_at` or `last_login_at` |
| `first_use` | At least one punch or leave request exists |
| `successfully_using` | `configured`, `people_added`, `team_activated` and `first_use` all hold, **and** a punch or leave request was created in the last 14 days, **and** one payroll run is completed, approved or paid if the plan includes payroll |

The founder is the owner (`tenants.owner_user_id`), or the first tenant admin
while that is unset.

A **punch** is a check-in that is not voided and did not come from a bulk
import (`source = csv`): an import says data arrived, not that anyone is using
the product. A manual entry by HR counts.

`successfully_using` is a use-based bar (owner decision, 2026-11-06). The go-live
flag is shown as a milestone but never required: it is advisory, and a
tenant can set it at any readiness score.

## Lifecycle

Separate from stage: `trial`, `trial_expired`, `active`, `suspended`,
`cancelled`, `deleted`.

An expired trial keeps `tenants.status = trial`, because nothing moves it. The
console therefore derives `trial_expired` (`Tenant::isTrialExpired()`) and the
list filter reads it live from the tenant, not from a snapshot.

## Health

`ok`, `warning`, `critical` or `unknown`, with reason codes in the same shape
as the operations centre (`state` plus `reasons[]`). The server sends codes and
the console turns each into a sentence; an unknown code is shown as itself.

`critical` if any critical reason holds, else `warning` if any warning holds,
else `ok`. `unknown` means the organisation is not operating (suspended,
cancelled or deleted, reason `not_operating`) or has no snapshot yet. An
unknown health is never shown as healthy.

| Reason | State | Holds when |
|---|---|---|
| `trial_expired_not_converted` | critical | The trial's end has passed and the status is still `trial` |
| `payroll_run_failed` | critical | `payroll.failed` was audited in the last 14 days, after the last counted run |
| `invoice_past_due` | critical | An invoice has status `past_due` (more than 30 days overdue) |
| `invoice_overdue` | warning | An invoice has status `overdue` |
| `no_login_14d` | warning | Registered more than 14 days ago and no successful sign-in in 14 days |
| `no_activity_14d_after_first_use` | warning | `first_use` holds and nothing was recorded in 14 days |
| `trial_ends_soon_not_ready` | warning | A trial ends within 7 days and the stage is below `first_use` |
| `invitations_unactivated` | warning | A teammate was invited more than 7 days ago, is still `invited`, and the stage is not `successfully_using` |
| `devices_silent` | warning | A device has not synced for 3 days (or never, and is older than 3) |
| `stalled_at_stage` | warning | The stage was reached more than 14 days ago and is not `successfully_using` |
| `open_support_request` | warning | A help request passed to ETHR is not resolved |

## What the snapshot stores

`stage`, `stage_reached_at` (the latest date among the stage's prerequisites),
`lifecycle`, `health_state`, `reasons`, `milestones` (key to date), `metrics`
(counts and dates), `readiness_score`, `readiness_level`, `readiness_gaps` (check
ids and labels only), `last_activity_at` (the later of the last punch, leave
request and sign-in) and `computed_at`.

Milestones: `registered`, `configured`, `people_added`, `team_activated`,
`first_use`, `first_payroll`, `went_live`, `converted` (first `billing.plan_changed`).

## What it reuses

| Need | Reused from |
|---|---|
| Readiness score and the failing checks | `ReadinessScorer::score()` |
| The plan's payroll feature | `FeatureGate::allows()`, with the subscription set through `Subscription::latestForTenant()` |
| Trial state | `Tenant::isTrialExpired()` |
| Health shape and badge | `OperationsStatusService` shape, `StateBadge` |
| Stages display | `components/shared/stepper.tsx` |
| Metric tiles | `components/dashboard/stat-card.tsx` |
| Setup check labels | the wizard's own `setup2.check_<id>` strings |
| Counted payroll statuses | the same set `ExecutiveDashboardService` counts |

## Boundaries

- Every query states `tenant_id` and returns a count, a date or a list of ids.
  The new bypass sites are in `tests/Feature/Security/tenant-scope-bypasses.php`.
- `SetupProvider::summary()` and `OnboardingProgress::completedStepsFor()` are
  tenant-scoped and are not used here.
- `AttendanceHealth::checks()` needs a tenant context and is not used; the
  silent-device count is a single query.

## Not covered

- Operator notes or an owner per organisation (the view is read only).
- Churn and closure reasons: a cancellation is a status change and the reason
  lives only in the audit payload.
- Leads (contact-form enquiries) are not linked to sign-ups.
- Operations centre panel 6, organisations with stuck work
  (`OPERATIONS-CENTRE-SPEC.md`, G8), can read this snapshot's `reasons` later.
- The older `conversion_rate` on the console home is still a proxy; the funnel's
  lifecycle counts are the measured figure.

## Deploying

Needs `migrate`; no seeder. The hourly schedule fills the list. To fill it at
once, run `php artisan ethr:refresh-tenant-journeys --all`, or open an
organisation's page, which refreshes that one.
