# QA — platform console on production, 2026-10-11

A live, read-only pass over the platform console at `https://admin.ethr.et/admin/`,
signed in as the platform super admin, on release `v2026.10.11.3` (`b1b9cbe5`).
Driven through Claude in Chrome plus signed-out `curl` probes, spaced to stay
under the host firewall's burst limit. **The first pass made no write**: every
confirm dialog was opened and cancelled, and the three controls that act on a
single click (F4–F6) were not touched. A second pass on 2026-10-12, after the
owner enabled MFA and approved it, ran the console's write actions against the
owner's throwaway organisation **QA Audit** only — see *Write tests* below.

What this report does not contain, deliberately: customer email addresses, IP
addresses from the audit log, tokens, cookie values, platform-settings values.

Severity: **S1** data loss / cross-tenant exposure / outage · **S2** a broken
feature, or a security weakness with a workaround · **S3** wrong or risky
behaviour an operator can live with · **S4** polish, wording, hardening.

## Summary

| # | Sev | Finding |
|---|---|---|
| F1 | S2 | The platform super admin has **no second factor**: the server reports `mfa_enabled: false` and no `auth.mfa_enabled` audit row exists — **resolved 2026-10-12** (enrolled after F18's fix) |
| F2 | S2 | `GET /api/v1/admin/health` answers **500** on every call; the System Health card never loads — **fixed in PR #200, deployed `v2026.10.12.3`** |
| F3 | S2 | The service worker stores **admin API responses in Cache Storage** (23 found) and nothing clears them on logout — **fixed in PR #200, deployed `v2026.10.12.3`** |
| F4 | S2 | **Mark paid** on an invoice acts on a single click, with no confirmation — **fixed in PR #200, deployed `v2026.10.12.3`** |
| F5 | S3 | The **address-mode select** changes an organisation's canonical address the moment an option is picked |
| F6 | S3 | **Backup Data** queues a backup on a single click |
| F7 | S3 | Every action button stays enabled while the account lacks MFA, though the server refuses them all |
| F8 | S3 | The audit **action filter sends one request per keystroke** |
| F9 | S3 | The System Health title shows a **green dot** while the card shows "Couldn't load system health" |
| F10 | S3 | **Platform settings are all empty**, including the bank details organisations pay into |
| F11 | S4 | Session lifetime for the console is 8 hours |
| F12 | S4 | Calendar: Ethiopian calendar active, console tables show Gregorian dates, the audit filter asks for Ethiopian ones |
| F13 | S4 | 42 console strings in `am.json` are still English |
| F14 | S4 | Wording and labels (eight small items) |
| F15 | S4 | Response headers: duplicate HSTS, version disclosure, `'unsafe-inline'` scripts |
| F16 | S4 | Invoices card is hidden when an organisation has none; invoice rendering untested |
| F17 | S4 | `HEAD /admin/audit` answered 503 once in the browser; not reproducible |
| F18 | S2 | **Enable now** on the MFA banner cannot work: the console host redirects `/profile/security` to `/admin/` — **fixed in PR #191, deployed `v2026.10.12.2`** |
| F19 | S3 | **Extend Trial** moves `trial_ends_at` but not the subscription's `current_period_end`; the console then shows two different end dates |
| F20 | S4 | An organisation on the **shared address** shows no address in the console (`canonical_url` is null by design; the entry URL is not shown instead) |
| F21 | S4 | `admin.tenant.backup_triggered` stores `triggered_by` as the **numeric user id** (Convention #4) |
| F22 | S4 | On the console host, the Profile tabs other than Security lead back to `/admin/` |
| F23 | S2 | The scheduler asks GitHub for a run every 5 minutes and gets **4–6 a day**: queued work waits hours |

## Findings

### F1 · S2 · The platform super admin has no second factor

**Steps.** Signed in at `admin.ethr.et`; open `/admin`; read `GET /api/v1/auth/me`;
filter the platform audit log for `mfa`.

**Expected.** After the owner enabled two-factor authentication: `mfa_enabled: true`,
an `auth.mfa_enabled` audit row, and no "Two-factor authentication required" banner.

**Actual.** `mfa_enabled: false`; `GET /api/v1/admin/audit?filter[action]=mfa` →
200, `total: 0`; the banner is still shown. The client refetches `/auth/me` after a
successful enable (`features/auth/api.ts:115`), so a stale banner is ruled out —
`POST /auth/mfa/enable` never succeeded. The host clock is correct (matches two
public references to the second), so server-side TOTP drift is ruled out too.

**Impact.** Writes are still blocked by `RequirePlatformMfa`, but every cross-tenant
**read** in the console — tenant list, audit log with IP addresses, platform
settings — is reachable with the password alone.

**Fix.** Owner: enrol again from the banner and wait for the "MFA enabled" toast;
an "Invalid code" toast means the enrolment did not happen. Product: on a 422 from
`/auth/mfa/enable`, show the server's `detail` rather than a generic message, so a
failed enrolment cannot be mistaken for a successful one.

### F2 · S2 · `/admin/health` returns 500

**Steps.** Open `/admin`, or `GET /api/v1/admin/health`.

**Expected.** 200 with database, cache, storage, queue and resource figures.

**Actual.** 500 `{"message":"Server Error"}` on every call (≈ 0.4 s). Revenue,
audit and failed-jobs on the same page are 200.

**Probable cause.** `SystemHealthService::resourceUsage()`
(`api/app/Services/Admin/SystemHealthService.php:149-158`) calls
`disk_free_space('/')` and is the only check without a `try`. Plesk sets
`open_basedir` to the subscription, so the call raises a PHP warning; Laravel
turns warnings into `ErrorException`, and the whole endpoint 500s. Not confirmed
from the log (the console has no log view; Plesk → Logs would confirm it).

**Fix.** Measure `base_path()` instead of `/`, and contain the call so one figure
cannot take down the panel; a test that makes `disk_free_space` warn and asserts
the endpoint still answers 200 with `disk_free_gb: null`.

### F3 · S2 · Admin API responses persist in the browser after logout

**Steps.** Browse the console, then in DevTools: `caches.keys()` →
`ethr-v1-api`.

**Expected.** Cross-tenant platform data is not written to disk on the operator's
device, or is removed at logout.

**Actual.** `ethr-v1-api` holds 26 responses, **23 of them `/api/v1/admin/*`**
(audit log with IP addresses, platform settings, tenants). `public/sw.js:58-60`
caches every successful `/api/` GET (network-first); nothing in `src/src` clears
that cache, and the worker only removes caches of an *older version* on activate.

**Impact.** On a shared or lost machine the console's data outlives the session.
The worker serves it only when the network fails, but it is readable from disk
by anyone using that browser profile.

**Fix.** Never cache `/api/v1/admin/*` or `/api/v1/auth/*` in the worker; on
logout, delete `ethr-*-api` (from the page via `caches.delete`, or by message to
the worker). A test over `sw.js`'s routing for the excluded prefixes.

### F4 · S2 · "Mark paid" has no confirmation

**Where.** Tenant detail → Invoices → **Mark paid**
(`tenant-detail.tsx:812-835`): `onClick` calls `markInvoicePaid.mutate` directly.

**Expected.** Like Activate/Suspend/Cancel: a confirm dialog naming the invoice
and the amount.

**Actual.** One click confirms a payment, which activates the subscription and is
recorded as revenue. Not exercised live (no organisation has an invoice).

**Fix.** Route it through `ConfirmDialog` with the invoice number and amount in
the confirm button's label.

### F5 · S3 · Address mode changes without confirmation

**Where.** Tenant detail → Tenant Profile → Address mode select
(`tenant-detail.tsx:530-545`): `onValueChange` calls `updateAddressMode.mutate`.

**Expected.** A dialog that shows the old and the new address — the change moves
every sign-in link and email the organisation receives.

**Actual.** Choosing an option saves immediately. Not exercised live.

**Fix.** Hold the chosen mode in state and confirm in a dialog showing
`canonical_url` before and after.

### F6 · S3 · Backup Data has no confirmation

**Where.** `tenant-detail.tsx:469-489`. One click queues a full tenant backup.
Lower impact than F4/F5 (it creates rather than changes), but every other action
in the card asks first. **Fix:** a short confirm, or at least a disabled state
that lasts until the queued job is visible.

### F7 · S3 · Actions look available while the account cannot use them

**Steps.** Without MFA, open any tenant.

**Actual.** All seven action buttons and the address-mode select are enabled
(`disabled: false` on each). The server refuses every one (`RequirePlatformMfa`).
**Impersonate Admin** opens a dialog asking for an MFA code the account cannot
produce.

**Fix.** When `mfa_enabled` is false, disable the actions with a hint that links
to the enrolment banner.

### F8 · S3 · Audit action filter is not debounced

**Steps.** `/admin/audit` → type `tenant` in Action.

**Actual.** Six requests: `filter[action]=t`, `te`, `ten`, `tena`, `tenan`,
`tenant`. The Tenants search on the same console is debounced (one request for
`mersa`).

**Impact.** The host firewall bans bursts, and the route has its own
`throttle:platform-admin` budget; a fast typist can hit either.

**Fix.** Reuse the Tenants search debounce.

### F9 · S3 · Health indicator is green while health failed

**Where.** `/admin` → System Health. The card body reads "Couldn't load system
health · Try again" (a good error state) while the dot beside the title is green.
**Fix:** derive the dot from the query state — grey while loading or failed, red
for unhealthy.

### F10 · S3 · Platform settings are unset

**Where.** `/admin/platform-settings`. All 26 fields are empty: bank, account
number and name, payment instructions (en/am), public name and tagline, contact
email/phone/address, social links, the proof figures and the testimonial.

**Impact.** An organisation leaving its trial has no account to pay into, and the
public site's contact block is blank. An owner task, not a defect.

### F11 · S4 · Console session lasts 8 hours

`ethr-session` is issued with `Max-Age=28800` (`SESSION_LIFETIME=480`). It is
correctly `Secure; HttpOnly; SameSite=Lax` and host-only. For an account that
reaches every tenant, a shorter idle lifetime for platform sessions is worth
considering. The timeout itself was not waited out.

### F12 · S4 · Calendar inconsistency

The header shows **EC** (Ethiopian calendar active). The Tenants table, tenant
detail and audit table show Gregorian dates (`11 Oct 2026`), while the audit
date filter says "Enter the date in the Ethiopian calendar". One screen, two
calendars. **Fix:** format console dates through the calendar context, or label
the console as Gregorian-only and make the filter match.

### F13 · S4 · Amharic: 42 console strings untranslated

`src/src/lib/i18n/locales/am.json` holds English text for 42 of 207 console keys:
`admin_plans.*` (7), `admin_plans.feature.*` (11), `admin_tenant_detail_page.*`
(24 — the custom-domain dialog and its errors, among others). The i18n gate passes
because the keys exist; it does not check that the value is Amharic.

### F14 · S4 · Wording and labels

1. Tenants, Plans and Audit all carry the subtitle "Platform-wide administration and tenant management"; tenant detail uses a different one ("View and manage all tenant organizations").
2. Sidebar says **Platform Audit Log**, the page heading and breadcrumb say **Audit**.
3. Tenant detail has no page title — the tab reads "ETHR — Ethiopian Workforce Operating System"; every other console page has "<Page> · ETHR".
4. The Subdomain stat card renders `Qa-Audit` (capitalised by CSS); the subdomain is `qa-audit`.
5. Tenant Profile shows raw enum values: Type `general` (the stat card says `General`), Subscription Status `trial`.
6. The Cancel-tenant dialog's buttons read **Cancel** and **Cancel QA Audit** side by side.
7. The Activate dialog says "restores full access" on a trial organisation that was never restricted.
8. The Custom domain dialog's input stays editable when the plan excludes custom domains (Save is correctly disabled).

### F15 · S4 · Response headers

- **HSTS is sent twice** with different `max-age` (31536000 from the app,
  15768000 from Plesk). Browsers honour the first; remove one.
- **Version disclosure**: `X-Powered-By: PHP/8.3.35` and `X-Powered-By: PleskLin`.
  `expose_php = Off` in Plesk's PHP settings removes the first.
- CSP allows `script-src 'unsafe-inline'` — the known cost of the static export's
  inline bootstrap; noted, not new.

### F16 · S4 · Invoices

The Invoices card renders only when `invoices.length > 0`
(`tenant-detail.tsx:759`), so an organisation with none shows no card and no
empty state. All four organisations have zero invoices, so invoice rendering and
Mark paid (F4) could not be exercised.

### F17 · S4 · One unexplained 503

While the audit page loaded, the browser logged `HEAD https://admin.ethr.et/admin/audit`
→ 503. The service worker ignores non-GET requests (`sw.js:46`), and `curl -I` on
the same URL answers 200 three times out of three. Recorded in case it recurs.

### F18 · S2 · The console's MFA link is redirected away

*Found after the first pass, when the owner reported that **Enable now** "won't show
any message".*

**Steps.** On `admin.ethr.et`, click **Enable now** on the "Two-factor
authentication required" banner (it links to `/profile/security`).

**Expected.** The account-security page, with the two-factor setup.

**Actual.** The browser lands back on `/admin/`. `curl` shows
`admin.ethr.et/profile/security/` → **302 → `/admin/`**, while
`www.ethr.et/profile/security/` → 200. The console-host rule in `.htaccess`
redirects every path outside `/admin` except `login|logout|_next|…`, and
`middleware.ts` listed `/profile` as a tenant route. Changing the password from
the console fails the same way.

**Impact.** The platform admin cannot enrol in MFA from the console — which is
why F1 exists. Workaround until deployed: sign in at `www.ethr.et` and enrol at
`/profile/security` there; it is the same account.

**Fix.** PR #191 excludes `/profile/security` (and only that) on the console host
in both places, pinned by `SharedHostingHtaccessTest` and
`middleware-hostname.test.ts`.

### F19 · S3 · Extending a trial leaves the subscription's end date behind

**Steps.** QA Audit → Extend Trial → 30 days → Extend (2026-10-12).

**Actual.** POST 200; `trial_ends_at` moved 2027-04-11 → **2027-05-11**, audited as
`admin.tenant.trial_extended {days: 30, new_end: 2027-05-11}`. The subscription's
`current_period_end` stayed at **2027-04-11**, so the console shows "Trial ends
11 May 2027" beside "Renews 11 Apr 2027". Registration sets both to the same date
(`AuthService.php:77`); `AdminTenantController::extendTrial` updates only the tenant.

**Impact.** Trial expiry itself runs from `trial_ends_at` (`NotifyExpiringTrialsJob`,
`BillingService::trialDaysLeft`), so access is not cut early — but billing's
days-remaining and proration read `current_period_end` (`BillingService.php:79-80`).

**Fix.** Move a trialing subscription's `current_period_end` with the tenant's
`trial_ends_at` in the same statement.

### F20 · S4 · No address shown for the shared-address tier

Switching QA Audit to **Shared address** (PUT 200) left the profile row with no
address under the select: `FrontendUrl::canonicalOrigin()` returns null for path
mode by design. The organisation is reached at `https://ethr.et/qa-audit` (measured:
→ `www.ethr.et/login?org=qa-audit`). **Fix:** show the entry URL when
`canonical_url` is null.

### F21 · S4 · An audit row carries a numeric id

`admin.tenant.backup_triggered` records `{"triggered_by": 1}` — the platform admin's
internal primary key, returned to the console by `GET /admin/audit`. Convention #4
says never expose a numeric PK. **Fix:** record the actor's `public_id` (the audit
row already names the actor).

### F22 · S4 · Profile tabs lead nowhere on the console host

After F18's fix, `/profile/security` opens on `admin.ethr.et`, but its tab bar still
offers Overview, Personal details, Change requests and Preferences — tenant pages
that the console host redirects to `/admin/`. **Fix:** show only the Security tab
for a platform account on the console host.

### F23 · S2 · The external scheduler runs a few times a day, not every 5 minutes

**Found while waiting for QA Audit's backup**, queued 10:31 UTC on 2026-10-12.

**Expected.** `.github/workflows/cron.yml` is `cron: "*/5 * * * *"` — 288 runs a
day draining the queue and running `schedule:run`.

**Actual.** The last 40 runs of *ETHR scheduler (external cron caller)*, 2026-10-04
→ 2026-10-12, are **1.5 to 7 hours apart — about 4–6 a day**, every one `success`.
The latest before the backup was 07:29 UTC; the backup had not run an hour later.
`cron-caller.md` says delivery is "best-effort" and may be delayed or dropped; the
measured rate is roughly **2 % of the requested ticks**.

**Impact.** Everything asynchronous waits hours: tenant backups, queued
notification mail, trial-expiry reminders, scheduled reports, invoicing, the
attendance scans. Nothing is lost — Laravel's scheduler catches up on the next
tick — but "minutes" in any user-facing promise is not true on this host.

**Fix (owner decision).** Keep GitHub as a fallback and add a second caller with a
reliable clock hitting the same two token-guarded endpoints — a free external cron
service, or a Plesk scheduled task if the account ever gains one (G0-D). Until
then, say "within a few hours" wherever the product implies sooner.

## Verified after deployment (2026-10-12, `v2026.10.12.3`)

- **F2:** `GET /api/v1/admin/health` → **200**; api, database, cache and storage
  healthy, reverb disabled, `disk_free_gb` reported. The System Health card renders.
- **F3:** the live `sw.js` is `ethr-v2` with `NEVER_CACHED_API`; on the owner's
  browser the v1 caches are gone and `ethr-v2-api` holds **0** admin or auth
  responses.
- **F4:** shipped; not exercised live, as no organisation has an invoice.
- **F23, observed again:** the health card's `default` queue depth was **1** —
  QA Audit's backup, still waiting for a scheduler run four hours after it was
  queued.

## Write tests on QA Audit (2026-10-12)

Approved by the owner for the organisation **QA Audit** only, after MFA was enabled.
Each action was taken once, at human pace, and read back from the API and the
platform audit log.

| Action | Request | Audit row | Read back |
|---|---|---|---|
| Address mode → Shared address | `PUT …/address-mode` **200**, toast "Address mode updated" | `admin.tenant.address_mode_changed` subdomain → path | `address_mode: path`, `canonical_url: null` (F20); `ethr.et/qa-audit` → `www.ethr.et/login?org=qa-audit` |
| Address mode → Own subdomain | `PUT …/address-mode` **200** | `admin.tenant.address_mode_changed` path → subdomain | `canonical_url: https://qa-audit.ethr.et` — restored |
| Extend Trial, 30 days | `POST …/extend-trial` **200**, toast "Trial extended by 30 days" | `admin.tenant.trial_extended` `{days: 30, new_end: 2027-05-11}` | `trial_ends_at: 2027-05-11`; subscription still 2027-04-11 (F19) |
| Backup Data | `POST …/backup` **200** (one click, no confirm — F6) | `admin.tenant.backup_triggered` `{triggered_by: 1}` (F21) | Queued; still waiting for a scheduler run an hour later (F23); 0 failed jobs |
| Impersonate Admin | **Not run** | — | Needs the owner's MFA code typed into the dialog; the owner chose to skip it. The hand-off is covered by `SessionHandoffTest` and `HostnameImpersonationTest` |

Not reversed, deliberately: the trial extension (QA Audit now ends 2027-05-11).

## Passed

| Area | Result |
|---|---|
| Overview | Loads; revenue 200, audit 200, failed-jobs 200 (0 jobs), health error state with retry (but F2/F9) |
| Tenants | List 200; search debounced and correct; status filter (`filter[status]`) correct; both empty states worded; API pagination `page=2&per_page=2` → `current_page 2 / last_page 2 / total 4` |
| Tenant detail | One API call, 0.7–1.4 s; address mode, effective mode and canonical URL agree for all four organisations (`subdomain`, `https://{slug}.ethr.et`); custom domain none; trial dates and subscription shown |
| Confirm dialogs | Activate, Suspend, Cancel name the organisation and focus **Cancel**; Extend Trial (1–180, default 30), Custom domain, Impersonate open with focus in the input; Escape closes each with no request sent |
| Plans | 200; three plans, prices match `PlanSeeder` (Starter is 0 ETB by design) |
| Audit | 200; substring action filter works (but F8) |
| `/admin/users/search` | 422 "Enter at least 2 characters" for `q` absent or 1 char; 200 empty for no match; each search is itself audited (`admin.user.searched`) |
| Signed out | `GET /api/v1/admin/tenants` → **401** problem+json on `admin.`, `www.` and a tenant subdomain; `/api/v1/auth/me` → 401 |
| `/admin` pages off the console host | **403** on `www.ethr.et` and `qa-audit.ethr.et` — the deliberate `.htaccess` `[F]` rule; the console host answers 200 |
| Framing | `X-Frame-Options: DENY` and `frame-ancestors 'none'`; a same-origin iframe of the console is refused |
| Cookies | `ethr-session`: `Secure; HttpOnly; SameSite=Lax`, host-only. `XSRF-TOKEN`: `Secure; SameSite=Lax` (readable by design) |
| Other headers | `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` present |
| Keyboard | Skip link first; visible focus ring on every stop; tenant rows are focusable buttons |
| Wrong password on `admin.ethr.et` | Now "These credentials do not match our records." — PR #189, deployed in `v2026.10.11.3` |
| Login page at 375 px, light | No horizontal overflow; no organisation field on the console host |

## Not covered, and why

- **375 px for the signed-in screens.** The operator's Chrome window is maximised
  and could not be resized from the extension; the built-in browser has no
  session, and signing it in would mean typing credentials. Needs a phone, or the
  window restored, and a second pass.
- **Logout invalidates `/auth/me`.** Not driven deliberately. Observed once on
  2026-10-12: after the owner signed out, the same tab's `GET /api/v1/auth/me`
  answered **401** and the page sat on `/login`.
- **Session idle timeout.** Observed from the cookie (8 h); not waited out.
- **Impersonation round trip.** Needs the owner's MFA code; skipped by the owner's
  choice (see *Write tests*).
- **Amharic in the browser.** Switching language saves a preference to the
  account; the translation file was checked instead (F13).
