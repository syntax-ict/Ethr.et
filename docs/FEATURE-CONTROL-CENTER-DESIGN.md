# Platform Feature Control Center — design

**Prepared:** 2026-10-28 · **Status: OWNER DECIDED, 2026-10-28** — all seven decisions in §10 taken as recommended. Ready to build from PR 1 in §9; nothing here is built yet.

**Method, same rule as every document in this tree:** the current state in §1 was read out of the source, with the file named so it can be checked. Nothing was measured against a running build. Where a choke point is named in §6 but was not read, it says so.

---

## The argument, in one paragraph

The platform admin can already decide what an organisation may use — through its plan — but cannot turn a capability off for everyone during an incident, give one organisation a feature its plan does not include, or show a new feature to three organisations before the rest. Each of those today is a code change, a release and a Plesk deploy. The request is a console page that does all three without a deploy. **The design rule is that this becomes the one place a feature's availability is decided, not a third system beside the plan gate and the unused flag table.** A switch that the screen honours and the API ignores is worse than no switch, so every state below is enforced on the server first and shown on the screen second.

---

## 1. What exists today

| Piece | Where | State |
|---|---|---|
| Plan entitlement | `App\Enums\PlanFeature` (11 keys), `App\Services\PlanFeatureService::allows()` | **Enforced.** 13 write routes in `routes/api.php` carry `RequiresPlanFeature::class.':<key>'`. Reads are open by design. Fails open for trials, plan-less tenants and plans with no feature list; `custom_domain` is an add-on and fails closed |
| Plan list to the screen | `MeController` → `plan_features` (null = everything) | **Used.** `features/auth/api.ts` selects it; payroll, reports, report builder, API keys, webhooks and audit log pages gate on it |
| Flag table | `feature_flags` (`tenant_id` nullable, `key`, `enabled`, unique `(tenant_id, key)`), created in `0001_01_01_000000_create_users_table.php` | **Dead.** `FeatureFlag::enabled()` exists and nothing in `app/` calls it. Listed in `TenantIsolationTest::GLOBAL_MODELS`. Each call would run two queries |
| Platform settings | `PlatformSetting` singleton, `PlatformSettingsController` | Console-editable platform state. The pattern this design copies for the console side |
| SMS switch | `SmsSetting.enabled`, `/admin/sms` | Already a kill switch for one integration, with test-then-switch and a daily cap (PR #254) |
| Gradual rollout | attendance device binding, off → flag → enforce, in the organisation's attendance settings | A per-tenant rollout owned by the organisation, not the platform |
| Admin route guard | `EnsurePlatformContext`, `RequirePlatformMfa`, `throttle:platform-admin`, `Gate::authorize('admin.manage')` | Every new console endpoint sits behind all four |
| Cache | `CACHE_STORE=database`; no Redis on the host | A cache read is a query |

---

## 2. Goals and non-goals

**Goals**

1. The platform admin can set any catalogued feature to **Enabled, Hidden, Restricted or Disabled** for the whole platform, effective on the next request.
2. The platform admin can **grant or revoke** one feature for one organisation, with a reason and an optional expiry.
3. Anyone looking at an organisation in the console can see **why** each feature is on or off for it.
4. Every change is audited with who, when, what and why.

**Non-goals**

- **Adding functionality without a deploy.** A switch works only where code checks it. A new feature needs one release to add its catalogue entry and its checks; after that it is switchable. The console says this in its empty state rather than implying otherwise.
- Replacing plans. Plans stay the commercial answer; this layer sits above them.
- Replacing role permissions. Who inside an organisation may use a feature stays in RBAC (`docs/PERMISSIONS.md`).
- Replacing organisation settings. What an organisation chooses for itself (device binding mode, photo retention) stays in its settings.
- A/B experiments or percentage rollouts. Restricted is an explicit list of organisations, nothing random.
- Limits on counts. `PlanLimitService` keeps seats, branches and devices.

---

## 3. The four states and the two overrides

### Platform state (one per feature)

| State | The screen | The API (writes) | The API (reads) | Queued and scheduled work |
|---|---|---|---|---|
| **Enabled** | Shown | Allowed, subject to plan | Open | Runs |
| **Hidden** | Removed from navigation, command palette, Next Steps, dashboard cards | **Still allowed** | Open | Runs |
| **Restricted** | Shown only to organisations with a grant | Allowed only with a grant | Open | Runs only for organisations with a grant |
| **Disabled** | A notice in place of the page's actions | Refused | Open, except public surfaces (§6) | Per the feature's posture (§6) |

**Hidden is not a security control, and the console must say so beside the option.** It exists for two real cases: a feature being retired from the screen while API customers still use it, and a feature not ready to announce. Anything that must *stop* uses Disabled. A feature can be Hidden only if its catalogue entry allows it, because hiding a feature whose only interface is the screen is Disabled with extra steps.

### Organisation override (zero or one per feature per organisation)

| Override | Effect |
|---|---|
| **Grant** | On for this organisation regardless of plan or Restricted. Beats everything except platform Disabled |
| **Revoke** | Off for this organisation regardless of plan |

Both need a reason; both take an optional `expires_at`, after which the resolver ignores the row. An expired row is kept as history, not deleted.

### Resolution order

The first matching line decides:

1. Platform state is **Disabled** → off, reason `platform_disabled`. An incident switch beats every grant.
2. Override **Revoke**, unexpired → off, reason `revoked`.
3. Override **Grant**, unexpired → on, reason `granted`.
4. Platform state is **Restricted** → off, reason `not_in_rollout`.
5. The feature maps to a `PlanFeature` and `PlanFeatureService::allows()` says no → off, reason `plan`.
6. Otherwise → on.

Visibility is a separate answer: visible when on and the platform state is not Hidden.

`PlanFeatureService` is **kept unchanged and becomes step 5.** Its fail-open rules were argued carefully (trial, no plan, no list) and nothing here reopens them.

---

## 4. The catalogue lives in code; the state lives in the database

A new enum, `App\Enums\Feature`, is the only list of switchable features. Each case declares:

| Property | Meaning |
|---|---|
| `kind` | `module` (a product area), `integration` (an outside connection), `public` (a surface anonymous visitors reach), `rollout` (temporary, removed once fully on) |
| `planFeature` | The `PlanFeature` it maps to, if any. The 11 existing keys map one to one with the same string, so `plans.features` needs no data migration |
| `default` | The state when no row exists. `Enabled` for everything that exists today, so shipping the catalogue changes nothing |
| `hideable` | Whether Hidden is offered |
| `overridable` | Whether per-organisation grant and revoke are offered. False for platform-only switches such as self-registration, which has no organisation yet |
| `jobPosture` | What queued work does while Disabled (§6) |
| `removeBy` | `rollout` only: the date it is meant to be fully on and deleted. The console flags it once passed. **Not a test** — a test that fails on a date turns CI red with no code change, the failure mode `./scripts/gates.sh security` was moved out of the sweep to avoid |

A typo in a route argument is a `ValueError`, the same guarantee `PlanFeature::from()` gives `RequiresPlanFeature` today.

### Tables

The unused `feature_flags` table cannot hold the states, the reason, the expiry or the actor, and mixes global and organisation rows in one shape. **Replace it with two tables:**

```
platform_feature_states
  id, public_id, key (unique), state (enabled|hidden|restricted|disabled),
  reason, changed_by_user_id, timestamps

tenant_feature_overrides
  id, public_id, tenant_id (FK, cascade), key, mode (grant|revoke),
  reason, expires_at nullable, changed_by_user_id, timestamps
  unique (tenant_id, key)
```

- `PlatformFeatureState` is global and joins `GLOBAL_MODELS`, replacing `FeatureFlag` — in `TenantIsolationTest` and in the Global Model List in [`CLAUDE.md`](CLAUDE.md), which names `FeatureFlag` today.
- `TenantFeatureOverride` uses `BelongsToTenant`. The resolver reads it with the tenant resolved, so the scope does the work. The console writes it across organisations, which needs `withoutGlobalScope` in the admin controller, stating `tenant_id` from the tenant in the route on the same chain. **That moves `tests/Feature/Security/tenant-scope-bypasses.php`**, and the entry should say so, as every console entry before it has.
- The migration dropping `feature_flags` **refuses to run if the table has any rows**, rather than assuming it is empty in production. Nothing reads it, but nothing proves nothing wrote it.
- Run the migration on a scratch MariaDB before pushing: SQLite does not enforce MariaDB's 64-character index-name limit.

### Reading the state

All platform states (one small table) and this organisation's overrides load **once per request**, in a `scoped` binding — the same reason `CurrentTenant` is `scoped`: a `singleton` would carry one job's organisation into the next on the queue worker.

**No cross-request cache in the first phase.** With `CACHE_STORE=database` a cache read is a query anyway, the two reads are on indexed keys, and no cache means a switch takes effect on the next request with nothing to invalidate. Measure with `./scripts/gates.sh performance` before and after; add a cache only if the number moves.

**If either table cannot be read, the resolver uses the catalogue default and logs once per request; it never returns 500.** This is not hypothetical: on Plesk the code deploys before `migrate` runs, so for a few minutes every release that adds these tables is code reading tables that do not exist yet.

---

## 5. API

Console, behind the four guards in §1:

| Method | Path | Body | Effect |
|---|---|---|---|
| GET | `/api/v1/admin/features` | | Catalogue, platform state, count of organisations with a grant or revoke, last change |
| PUT | `/api/v1/admin/features/{key}` | `state`, `reason` | Sets the platform state |
| GET | `/api/v1/admin/tenants/{tenant}/features` | | Every feature's decision for that organisation: on, visible, reason, and the override if any |
| PUT | `/api/v1/admin/tenants/{tenant}/features/{key}` | `mode`, `reason`, `expires_at?` | Sets the override |
| DELETE | `/api/v1/admin/tenants/{tenant}/features/{key}` | `reason` | Removes the override |

Organisation side:

- `GET /me` gains `features: { <key>: { on, visible, reason } }`. `reason` is the enum from §3, never the admin's free-text reason.
- **`plan_features` stays, derived from the resolver**, for at least one release after the frontend stops reading it. A browser holding an old bundle (the stale-cache problem PR #401 addressed) still reads it.
- A refused write returns 403 with a distinct problem type, `feature-unavailable`, beside today's plan message, so the screen can say "temporarily unavailable" rather than "upgrade your plan". Both strings in English and Amharic, under the house copy rules.

Scramble reads the request rules and response arrays. **No explanatory comment above a key in those arrays or directly above the `return`** — it is published into `generated.ts`.

---

## 6. Enforcement points and the first catalogue

### Where a check goes

| Surface | Check | Note |
|---|---|---|
| Write routes | `RequiresFeature::class.':<key>'` | Replaces `RequiresPlanFeature` on its 13 routes; the plan check moves inside the resolver |
| Read routes | none | A downgraded or switched-off organisation keeps sight of its own data |
| Public surfaces | read routes gated too | Disabling public news should remove the page, not just its editor |
| Queued jobs and listeners | at the top of `handle()`, after setting tenant context | Per `jobPosture` below |
| Scheduled sweeps | per organisation inside the sweep | |
| Inbound devices | `503` with `Retry-After` | Devices buffer and retry; a 403 teaches them to drop |
| Screen | `/me` `features` | Navigation, command palette (`GET /search` filters on the server), Next Steps providers, dashboard cards |

`jobPosture` values:

- `release` — put the job back with a delay. For work whose loss is damage: outbound webhooks.
- `drop` — log and finish. For work that is worthless late: a push notification about a punch an hour ago.
- `finish` — run anyway. For work already committed to: a payroll run that started before the switch.

### Two rules that override any switch

1. **A record captured while a feature was on is never discarded.** Offline attendance sync accepts punches captured before the switch changed; only new online punches are refused.
2. **Disabling never interrupts work in progress.** A payroll run already processing finishes; Disabled refuses starting the next one.

### Phase 1 catalogue

**Modules** — the 11 `PlanFeature` keys, same strings, all `overridable`. `custom_domain` stays an add-on: a grant gives it free; nothing else does.

**Integrations and public surfaces:**

| Key | Choke point | Posture while Disabled | Read? |
|---|---|---|---|
| `webhooks_outbound` | `Services/Webhook/WebhookDispatcher`, `DispatchWebhookJob` | `release` | read |
| `web_push` | `Listeners/PushDatabaseNotification` | `drop` | read |
| `email_sign_in_codes` | `Auth/OtpController` email path | refuse with the generic "use your password" message | read |
| `attendance_selfies` | `Attendance/AttendancePhotoController` | punch accepted without a photo, never refused | read |
| `kiosk_check_in` | `Kiosk/KioskCheckInController` | refuse | read |
| `self_registration` | `Auth/RegisterController` | refuse; not `overridable` | read |
| `scim` | `Middleware/ScimAuth` | `503` | read |
| `device_webhooks_inbound` | device webhook and ADMS endpoints | `503` + `Retry-After` | not read: routes to confirm |
| `public_news` | public news pages and `NewsFileController` | page gone | not read |
| `public_vacancies` | public vacancy pages (PR #444) | page gone | not read |

**SMS is not added.** `/admin/sms` already switches it, with a test step and a cap; a second switch for the same thing would let the two disagree. The control center lists SMS as a row that links to `/admin/sms` and reads its state from `SmsSetting`.

**Existing rollouts are not migrated.** Device binding's off → flag → enforce is an organisation's choice and stays in its settings. New platform-led rollouts use Restricted.

---

## 7. Console screens

**Features** (`/admin/features`): grouped by kind. Each row shows the state, organisations with a grant or revoke, and the last change (who, when, reason). Changing a state opens a confirmation that names its consequence in one sentence from the table in §3 and, for Disabled, how many organisations used the feature in the last 30 days. A reason is required. Hidden carries the line "Hiding does not stop the API".

**Organisation → Features** (a tab on the existing tenant page): every feature with its decision and the reason in words ("Off: not in the Starter plan", "On: granted until 2026-12-31 by A. Admin"). Grant and revoke from here, with reason and optional expiry. This is the "why is payroll off for this organisation" view, and it is the part support will use most.

Neither screen offers anything the catalogue does not declare: no free-text keys.

---

## 8. Audit

Every change writes `AuditLog::record()`: platform-state changes as platform rows, overrides against the target organisation, with old value, new value, reason and expiry. Confirm before building that a platform row (no `tenant_id`) renders in the platform audit view the way `Support/AuditSubjects` expects; PR #452 added the organisation column there.

---

## 9. Delivery

Four PRs, each shippable alone, each through local → remote → `main` → `production` → Plesk with no stage skipped.

| PR | Contents | Behaviour change | Release steps |
|---|---|---|---|
| 1 | `Feature` enum, two tables, `feature_flags` dropped (guarded), resolver, `RequiresFeature` on the 13 routes, `/me` `features` alongside `plan_features` | **None.** No rows means every answer equals today's | migrate, clear, optimize |
| 2 | Both console screens, five admin endpoints, audit | Platform admin can now change things | clear, optimize |
| 3 | Integration and public-surface checks from §6, job postures, `503` for inbound, screen notices on `feature-unavailable` | Integrations become switchable | clear, optimize |
| 4 | Hidden in navigation, palette, Next Steps, dashboard; frontend stops reading `plan_features` | Hidden becomes visible | none |

### Tests

- The resolver against a truth table: every state × every override × expired or not × plan yes/no.
- PR 1's compatibility claim: for an organisation with no rows, `plan_features` from the resolver equals `PlanFeatureService::enabledFor()` for every seeded plan, including trial and plan-less.
- Tables missing → catalogue defaults, no 500.
- Isolation: an override for one organisation never changes another's answer; the bypass inventory updated.
- The scoped binding: two queued jobs for different organisations in one worker see their own overrides.
- Each integration in §6 on and off, including the two overriding rules: an offline punch captured before Disabled is accepted, and a payroll run in progress finishes.
- Frontend: navigation and palette honour `visible`; a `feature-unavailable` 403 shows the notice, not a generic error.
- `generated.ts` regenerated, and `./scripts/gates.sh` plus `./scripts/gates.sh mysql` green.

---

## 10. Owner decisions

**Decided by the owner on 2026-10-28: every recommendation below was accepted as written.** The design above already assumes each of them, so nothing earlier in this document changed when they were taken. Reversing one later is a design change, and the row says what it would touch.

| # | Question | Decision | Reversing it would touch |
|---|---|---|---|
| D1 | May a grant give an organisation a paid feature its plan lacks? | **Yes**, with a required reason and an expiry by default. That is most of the reason to have overrides | Resolution order step 3 in §3, and the `custom_domain` add-on note in §6 |
| D2 | When a module is Disabled, are the organisation's admins told? | **A banner in the app, no email.** An email for a switch that may be on for ten minutes is noise | The screen notice in PR 3; a notification would add a queued listener |
| D3 | Does Disabled need an authenticator code on top of the console's MFA session? | **No**, consistent with PR #442, which removed the per-action code from organisation deletion. Reason plus route MFA | The `PUT /admin/features/{key}` request and its console dialog |
| D4 | Fold the SMS switch into the control center? | **No.** Link to `/admin/sms` and read its state | §6: SMS would need its own catalogue entry and a migration of `SmsSetting.enabled` |
| D5 | Drop `feature_flags`? | **Yes**, behind the migration guard in §4 | The PR 1 migration and the Global Model List |
| D6 | Who besides the super admin may use this? | **Only `admin.manage`**, as every other console write | A new permission and its seeder |
| D7 | Should a Disabled public surface return 404 or a "temporarily unavailable" page? | **A page that says so**, so a shared link does not look broken | The public-surface checks in PR 3 |
