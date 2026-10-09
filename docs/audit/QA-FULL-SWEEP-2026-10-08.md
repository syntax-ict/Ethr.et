# QA sweep of every screen and the main write flows — 2026-10-08

**On the owner's instruction, before go-live, after [`QA-CANONICAL-ADDRESS-2026-10-08.md`](QA-CANONICAL-ADDRESS-2026-10-08.md).**
The dev stack was restarted (`web-3010` against the API on `:8010`, XAMPP MariaDB, the demo
tenant: 150 employees, 3 payroll runs, 15 leave requests, 3 devices) and driven from the
desktop app's built-in Chromium, with the dev database read after each write. Three layers:

1. **The repository's Playwright suite**, `gates.sh e2e` against `:3010`: **126 passed**
   (auth, employees, attendance, leave, payroll, admin, marketing, accessibility, Amharic
   layout, responsive at four widths, theme switching), 8.3 minutes. The two specs that gate
   skips by default (UX audit, PWA/offline) were run afterwards; result in the log below.
2. **Every route, read-only** — all **90 pages** the frontend defines (62 dashboard screens as
   the tenant admin, 6 console screens as the platform admin, 9 auth pages, 13 marketing
   pages in English and Amharic, the kiosk and offline shells). Each was loaded, probed for
   error wording, alerts and stuck skeletons, and its API calls read: **every call answered
   2xx**, no page error, no uncaught exception. The only console errors were the dev server's
   HMR websocket, which the built-in browser cannot open.
3. **Write flows, end to end** (UI → API → MariaDB → UI), listed below, with the negative cases
   beside them.

Production was not touched; it is not live.

## Write flows

| # | Flow | Verified |
|---|---|---|
| W1 | Create an employee from the form | 201; row with name, `salary_cents`, encrypted national ID, hire date, `employee.created` audit; found by search |
| W2 | Approve a leave request from Approvals; reject one with a reason | `leave.approved` / `leave.rejected`, balance 2 → 6 days used, `rejected_reason` and `rejected_by` stored, pending 6 → 5; the employee's `LeaveApprovedNotification` row after the queue drained |
| W3 | Publish an announcement | 201, listed, audience notifications after the queue drained; the empty form is blocked by the browser's `required` |
| W4 | Save organisation settings | `PUT /settings/organization` 200, tenant row updated, `settings.organization_updated` audited |
| W7 | Run payroll for October (Gregorian pickers via the calendar toggle), approve it, void it with a reason | 202 → `ProcessPayrollJob` on the queue → 151 entries, `payroll.processed`; the new employee's entry correct under Proclamation 1395/2025 (9,500 → 1,525 tax, 665 pension, 7,310 net); approve 200 + `payroll.approved`; void 200 → *Voided*, Reprocess offered. **Findings 1 and 2** |
| W9 | Switch the interface language to Amharic and back | `PUT /profile/preferences` 200, `lang=am`, sidebar in Ethiopic, persisted |
| W10–W15 | Holiday, leave type, webhook, API key, kiosk session, through the session's own `fetch` | 201 each; webhook secret and API key shown once; kiosk token returned |
| W12 | Invite a user from Settings → Users | 201, "Invitation sent", row `invited`/`hr_admin`, `user.provisioned` audited, "Set up your account" mail in the log |
| W16 | Log out | `POST /auth/logout` 200, `/auth/me` 401 after |

**Negative and authorization cases:** duplicate employee code 422; invalid email 422; negative
`salary_cents` 422; approving an already-decided request → per-item error; unknown request id →
per-item error; invalid batch action 422; a webhook URL at `127.0.0.1` refused 422; a tenant
admin reaching the console 403; a super admin without MFA: reads 200, writes 403
`mfa-required`; a session for one organisation presenting another in `X-Tenant` 403
`tenant-mismatch`; the API key of one organisation used with another's slug 401.

## Findings

### 1 · No employee ever received a payslip notification — S2, fixed

- **Seen:** four approved runs, 151 payslips logged as released, and **zero** payslip
  notification rows in the whole database, on either channel (`database`, `mail`).
- **Root cause:** `PayslipAvailableNotification::toArray()` reads `$entry->payrollRun`.
  Lazy loading is disabled outside production (`Model::preventLazyLoading`), the read threw
  inside `Notification::send()`, and `SendsNotifications::notify()` turned the throw into a
  log warning (`Attempted to lazy load [payrollRun] on model [PayrollEntry]`), so the listener
  still logged the run as released. No test caught it because `Notification::fake()` never
  calls `toArray()`. The guard only arms on models hydrated in a result set larger than one,
  which is why a single-entry run — and any single-entry test — passes.
- **Fix:** `NotifyPayslipsReleased` hands the run to each entry (`setRelation`) before
  notifying. `PayslipNotificationDeliveryTest` builds a two-entry run, runs the listener
  without faking, and expects the rows and no warning; it fails on the old listener.
- **In production** (`APP_ENV=production`) lazy loading is allowed, so the notification would
  have gone out there, with an N+1 query per payslip. The fix removes both.

### 2 · Approving a payroll run took one click — S3, fixed

- **Seen:** "Approve Payroll" approved the run immediately, with no confirmation; Void asked
  for a reason in a dialog; Reprocess used a native `confirm()`, which this codebase's own
  comments reject.
- **Fix:** approve and reprocess go through the shared `ConfirmDialog`, with copy saying what
  approval does (every employee is notified; undo means void and reprocess). Verified live:
  the dialog opened on the September run, Cancel sent nothing. `payroll-run-detail.test.tsx`
  gains *sends nothing until the approval is confirmed, then approves*, failing on the old
  page.

### 3 · Console API keys have no consumer — S3, **fixed 2026-10-09** under delegation

> **Closed:** `AuthenticateApiKey` accepts a console key on the tenant API as its creator, on
> its own tenant; `EnforceApiKeyAbilities` lets it call only what its abilities cover.
> `ApiKeyAuthenticationTest` (13 cases, 9 failing on the old code). Decision and the
> alternatives it was chosen over: [`../decisions/DECISION-API-KEY-GUARD.md`](../decisions/DECISION-API-KEY-GUARD.md).
> The text below is the finding as reported.


- **Seen:** Settings → API keys mints keys with abilities (`read`, `write`, `employees`,
  `attendance`, `leave`, `payroll`, `reports`), behind the `api_access` plan feature. Used
  alone, such a key answers **401** on every tenant endpoint: the only code that accepts an
  `ApiKey` is `ScimAuth`, which requires the `scim` ability — one the console cannot grant;
  SCIM keys come from the SCIM settings page instead.
- **Why not fixed here:** making the keys work means adding an authentication guard and
  ability enforcement to the public API, a feature with a security surface, not a defect
  repair. Until that exists, the page sells something nothing accepts. Owner's call: build the
  guard, or hide the page and the `api_access` feature.
- *Recorded for honesty:* an earlier reading in this pass had those keys "reading payroll"
  — the probes were sent from the browser with the session cookie alongside the key, so the
  session answered. The key alone answers 401.

### 4 · The attendance-intelligence date control had no accessible name — S3, fixed

- **Seen:** the UX-audit spec (the one `gates.sh e2e` skips by default) failed
  `/attendance/intelligence` on every theme with a **critical** axe violation, *Form elements
  must have labels*: the page captions its date control with a `<Label>` bound to nothing.
- **Fix:** `htmlFor="intelligence-date"` on the caption and `id="intelligence-date"` on the
  control. Verified live (`input.labels` → "Date:", no unlabelled control left) and by the
  audit, which now passes the page in all three themes; it failed before.

### 5 · The UX audit measured animation frames — S4, test determinism, fixed

- **Seen:** the landing page failed colour contrast in light and dark but not high-contrast,
  with the offline pill and its copy at `#a3acba` — a half-faded frame of the product-flow
  animation. `globals.css` snaps every animation to its last keyframe under
  `prefers-reduced-motion`; the spec never asked for it.
- **Fix:** `page.emulateMedia({ reducedMotion: 'reduce' })` before the viewport loop. The
  settled landing page passes all three themes. Not a defect in the page.
- The six **offline** failures are expected on this harness: the service worker is disabled
  under `next dev` on purpose, which is why the gate excludes that spec.

### Notes, not defects

- `/api/v1/auth/tenant-context` is limited to 60/min per IP. Every login-family page now asks
  it once (the custom-domain fix of #169), so an office behind one NAT address gets 60 login
  page loads a minute before the page falls back to the plain form. The limit is a prior
  design choice; raise it to the `public-catalogue` budget if that proves tight.
- A full page load makes five layout calls (`auth/me`, `onboarding/progress`,
  `dashboard/manager`, two notification calls); in-app navigation caches them for 60 s. Two
  browser tabs plus the Playwright suite on one account did hit the 300/min `api-global`
  limit once; a single user will not.
- Logging in from the same device hash revokes that device's earlier session (one session
  per device, by design). The Playwright Chromium shares the built-in browser's hash, so its
  logins signed my tab out once. Not a defect; worth knowing when testing.
- `hire_date` accepts any date, including 2099; `date_of_birth` has `before:today`. A product
  choice (pre-boarding), left as is.
- The console's "enable MFA" banner renders on `/admin` home only; sub-pages rely on the 403
  toast. Design as documented in `RequirePlatformMfa`.
- Exports (payroll register, bank file, journal) are built client-side from the loaded JSON
  and saved as a Blob; no request to measure, and the built-in browser does not surface the
  download.
- `localStorage.tenant` survives logout so the next sign-in is prefilled; nothing sensitive.

## Execution log

- **Playwright, default gate** (`gates.sh e2e` against `:3010`): 126 passed, 8.3 min.
- **Playwright, the skipped specs** (`E2E_ALL=1`, UX audit + offline): 203 passed, 11 failed,
  27.7 min. Six are the offline spec under `next dev` (expected); five were findings 4 and 5.
  After the fixes the six affected cases (landing and attendance-intelligence, three themes
  each) pass.
- **Route sweep:** 90 pages, every API call 2xx. **Write flows:** W1–W16 as tabled.
- **Gates after the fixes:** `gates.sh quick` green; `gates.sh backend` green, Pest
  **2695 passed**, **239/239** classes collected, PHPStan clean; `gates.sh docs` green;
  Vitest **978 passed** in 176 files.
- **Harness mistakes, recorded so they are not read as findings:** probes sent from a page
  carried the session cookie (hence the retracted API-key reading); one probe deleted the
  seed employee behind `emp@demo.ethr.et` (soft-deleted, restored at once); the Playwright
  Chromium's logins revoked the built-in browser's session twice (one session per device
  hash). The dev database was returned to its seeded state at the end: the test employee,
  run, holiday, leave type, webhook, kiosk session, API keys, invited user and announcement
  removed; the tenant name and the two decided leave requests restored.
