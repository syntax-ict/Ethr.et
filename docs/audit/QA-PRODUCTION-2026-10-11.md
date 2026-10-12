# Production QA and audit of ethr.et — 2026-10-11

**On the owner's instruction**, a live QA pass of production (`https://www.ethr.et`, release
`b1b9cbe5`, v2026.10.11.3 — `release.json` matched `origin/main` HEAD) driven through Claude in
Chrome at human pace. Read-only by default; every write happened inside test organisations the
owner created for this run. No other organisation was touched beyond the cross-tenant probes
listed under *Isolation*, which are refused before any data is read. No password, token, `.env`
or internal address appears in this document; the owner typed every password, and email
addresses are written as `<owner>+alias@gmail.com`.

Severity: **S1** blocks or silently breaks a core promise for every customer · **S2** breaks a
main flow or a security property for some · **S3** a defect with a workaround · **S4** polish.

## Summary

| # | Sev | Finding | Status |
|---|---|---|---|
| P16 | **S1** | **Log out does not sign a tenant user out** — after logout `/auth/me` and the data APIs still answer 200 (reproduced twice) | Open |
| P1 | **S1** | The scheduler runs 4–5 times a day, not every 5 minutes; fixed-time tasks — including the nightly **backup** — essentially never run | Open — owner decision |
| P2 | **S1** | A newly registered admin is signed out ~15 minutes after registering, mid-setup | Open |
| P15 | S2 | No UI path gives an employee a login linked to their record — nobody can request leave, see payslips or check in as themselves | Open |
| P17 | S2 | The console's System Health panel is empty: `GET /admin/health` answers 500, and not as RFC-7807 | Open |
| P3 | S2 | `POST /api/v1/api-keys` answers **500** in production — no API key can be created | Open |
| P4 | S2 | CI's "MySQL" gate runs a non-strict `mariadb` connection; production runs strict `mysql` (MySQL 8.0) — P3's class of defect cannot be caught | Open |
| P5 | S2 | The Ethiopian-calendar year box cannot be typed into: "2" becomes 1902, the next digit blanks the date | Open |
| P6 | S2 | The apex sign-in hand-off lands on a blank, unexplained sign-in form in another language | Open |
| P7 | S2 | Self-registration never verifies the admin's email (phase docs claim it does) | Open — owner decision |
| P8 | S2 | An English visitor's "Start Free Trial" opens an Amharic sign-up form | Open |
| P9 | S3 | API error messages are English while the UI is Amharic (client and UI disagree on the default locale) | Open |
| P10 | S3 | Every `/icons/*` file 404s — the host's Apache `Alias /icons/` shadows the app's directory | Open |
| P11 | S3 | Invitation (activation) links expire after 60 minutes | Open — owner decision |
| P12 | S3 | Next.js prefetch `HEAD` requests are answered 503 by the host | Open — needs host log |
| P13 | S3 | The apex 301s to `www`, while canonical, `robots.txt` `Host` and the sitemap name the apex | Open — owner decision |
| P14 | S4 | Smaller items (listed under P14) | Open |

## Findings

### P1 · The scheduler runs a few times a day; fixed-time tasks do not run — S1

- **Seen:** `.github/workflows/cron.yml` is `*/5 * * * *`. GitHub fired it **25 times in six days**
  (2026-10-06 15:15 → 2026-10-11 13:34 UTC), gaps of 2 h 50 m to 7 h: e.g. 10-10 22:06, 10-11 00:56,
  06:48, 13:34. `/api/v1/health` reported `queue_detail.status: unhealthy`, *"scheduler last ran
  5421s ago (threshold 900s)"*, while its top-level `status` still read `healthy`.
- **Live effect, measured:** a payroll run started at 15:05 and an announcement published at
  15:05 sat in the queue (`default` 1, `notifications` 1) until the workflow was dispatched by
  hand at 15:10 (with the owner's approval); both then completed in 3.4 s. Without the dispatch
  they would have waited for GitHub — on that day's pattern, hours.
- **The worse half:** `schedule:run` only runs entries **due in the minute it is called**. Every
  fixed-time entry in `routes/console.php` therefore runs only if a GitHub run happens to land
  in that exact minute: `ethr:backup` 01:00, `CleanupExpiredDataJob` 02:00, overdue invoices
  04:00, trial notices 05:00, approval reminders 06:00, missing-punch scan 15:30, anomaly scan
  15:45, monthly invoices / leave accrual / holiday roll-forward on the 1st, custom-domain
  recheck weekly. None of the 25 runs started in any of those minutes. The comment above
  `ethr:backup` states the assumption outright — *"01:00 is a multiple of five, so the \*/5
  caller reaches it"* — and GitHub does not honour it. **On this evidence the nightly backup has
  not run on schedule since at least 2026-10-06.** (Not verified on the host: there is no shell.
  The platform console's backup list, or the backup directory in Plesk, will confirm.)
- **Fix (owner decision):** GitHub documents `schedule` as best-effort and drops runs under load,
  so no change to the workflow fixes this. Options: (a) an external pinger that can send a
  header on a fixed minute cadence (e.g. a cron-as-a-service) calling the same two endpoints;
  (b) make fixed-time entries catch-up tolerant — record each task's last run and run it on the
  first tick after it was due — which survives any caller; (c) both. (b) is the code change and
  is worth doing regardless. Also make the health endpoint's top-level `status` reflect a stale
  scheduler.

### P2 · A newly registered admin is signed out about 15 minutes after registering — S1

- **Seen, twice:** both sessions created by self-registration (`qa-audit`, then `syntax`) ended
  without user action: a background `GET /dashboard/manager` answered 401, the client fired
  `POST /auth/refresh` (four times in parallel the second time), each answered 401, and the
  page hard-navigated to `/login` — mid-way through a form the first time.
- **Root cause (code):** `AuthService::SESSION_MINUTES = 15` and `registerTenant()` issues a
  token with `expires_at = now + 15 min`. Unlike `LoginRequest::authenticate()`, it never calls
  `Auth::login()`, so there is no web session to fall back on. The client only refreshes *after*
  a 401 (`src/src/api/client.ts:152`), and `/auth/refresh` sits behind `auth:sanctum`, which
  refuses the expired token. Nothing can renew the session, so it ends at the 15-minute mark
  regardless of activity.
- **Registration only.** A password sign-in also calls `Auth::login()` and gets a web session
  (`SESSION_LIFETIME` 480) that Sanctum consults before the bearer token, so it survives the
  token's expiry. Observed 2026-10-12: a password sign-in on `syntax.ethr.et` stayed signed in
  across its first token's 10:35:40 expiry. (A second tab sharing the cookie also renewed the
  token at 10:33:42, so the observation supports rather than proves this; the code path is
  what decides.)
- **Fix:** call `Auth::login($user)` in `registerTenant()` as the password path does, and make
  renewal not depend on a live token — either refresh proactively before `expires_in`, or let
  `/auth/refresh` accept the web session. Add a feature test that registers, travels 16
  minutes, and expects `/auth/me` 200; it fails today.

### P3 · Creating an API key answers 500 — S2

- **Steps:** Settings → API keys → Create Key, name "QA UI key", ability Read → *"Failed to create
  API key"*; `POST /api/v1/api-keys` → **500**. Reproduced through the session's own request.
- **Root cause:** `api_keys.key_prefix` is `string(8)`
  (`2026_06_28_300001_create_api_keys_table.php:19`); `ApiKeyController::store` writes
  `substr($plainKey, 0, 12)` — `ethr_` plus seven characters. Strict MySQL refuses the insert
  (*data too long*). SQLite ignores `VARCHAR` length, and see P4 for why the MariaDB gate passed.
  SCIM keys (`SettingsController:223`) write 8 and work.
- **Fix:** widen the column to 16 with a migration (keeps the 12-character prefix the UI shows),
  and add a MariaDB-strict test for the store path. Also note `ApiKeyAuthenticationTest.php:47`
  writes 12 characters directly — it passes only because its connection is not strict.
- **Consequence:** finding 3 of the 2026-10-08 sweep (console API keys usable since #172) cannot
  be used in production at all until this is fixed.

### P4 · The CI database gate is not production-shaped — S2

- `config/database.php` sets `'strict' => false` on the `mariadb` connection; `phpunit.mysql.xml`
  forces `DB_CONNECTION=mariadb`. Production's template is `DB_CONNECTION=mysql`
  (`.env.shared-hosting.example:73`, `'strict' => true`), and the panel reports **MySQL 8.0**,
  not MariaDB. Non-strict mode turns over-long strings, out-of-range numbers and invalid dates
  into silent truncation, so the one gate that exists to catch production-only database
  behaviour cannot catch this class — P3 passed it.
- **Fix:** run the gate on the `mysql` connection with `strict => true` (ideally against MySQL 8,
  matching the host), and expect a handful of red tests the first time — each is a P3.

### P5 · The Ethiopian year box cannot be typed into — S2

- **Steps:** Employees → Add Employee → Hire Date (Ethiopian mode, the default here) → type
  `2019` in *Year*. After "2" the box reads **1902**; after "0" it is empty and month/day reset.
  The same component drives the Run Payroll dialog, so a payroll period cannot be keyed either.
- **Root cause:** `components/shared/dual-calendar-date-input.tsx:200-204` emits a full date on
  every keystroke. Year 2 becomes `toGregorian(2, …)`, which reaches JavaScript's
  `new Date(y, …)` rule that maps years 0–99 to 1900–1999, and comes back as Ethiopian 1902.
- **Fix:** hold the year as a local draft string and emit only once it has four digits (or on
  blur); build dates with `setFullYear` so two-digit years cannot be remapped. A Vitest case that
  types "2019" one key at a time fails today.
- **Workaround used in this run:** setting the value in one event; the form then saved correctly.

### P6 · The apex sign-in hand-off is unexplained — S2

- **Steps:** `https://www.ethr.et/login` → organisation `syntax`/`qa-audit`, email, password →
  Sign In. The server answers 409 `canonical-address` and the app moves to
  `https://qa-audit.ethr.et/login` — **an empty form**, no message, email not carried over, and
  in Amharic (the English choice lives in the apex origin's storage).
- **Effect:** the owner signed in this way during the run and reasonably believed the sign-in
  had worked. A customer will too.
- **Fix:** carry the email and a reason (`?email=…&reason=canonical`) to the canonical login and
  show one line — "QA Audit signs in at qa-audit.ethr.et; enter your password again". Carry the
  locale the same way.

### P7 · Self-registration never verifies email ownership — S2 (owner decision)

- Registering sends **no email at all**; the admin is `active` immediately, and nothing checks
  `email_verified_at` anywhere. Anyone can open an organisation in someone else's name, and the
  owner of that address is never told. `docs/phases/PHASE_01.md:144` ticks *"register → verify
  email → login"* as done.
- **Fix:** either send a verification email and gate sensitive actions (inviting users, payroll,
  API keys) on it, or correct the phase document. Owner's call; a welcome email is the minimum.

### P8 · English "Start Free Trial" opens an Amharic form — S2

- From `https://www.ethr.et/en`, the hero CTA opens `/register` with `lang="am"`. The marketing
  pages carry the locale in the path; the app reads `localStorage.locale`, which the marketing
  pages never set, and falls back to `DEFAULT_LOCALE = "am"`.
- **Fix:** have `/en/*` and `/am/*` write the app's locale key, or link to `/register?lang=en`.

### P9 · API messages are English while the UI is Amharic — S3

- `src/src/api/client.ts:26` sends `Accept-Language: localStorage.locale || "en"`; the UI's
  fallback is `"am"`. A first-visit user sees Amharic screens and English errors (*"These
  credentials do not match our records."* on the Amharic sign-in form). With `Accept-Language:
  am` the API answers in Amharic, so only the client fallback is wrong.
- **Fix:** use `DEFAULT_LOCALE` (or the same resolver the UI uses) in the interceptor.

### P10 · All `/icons/*` assets 404 — S3

- `/icons/icon-144.png`, `-192`, `-512` → 404 HTML, though they are in the `production` branch
  tree. `/icons/blank.gif` → 200 `image/gif`: the server's stock Apache `Alias /icons/` (the
  autoindex icon set) takes precedence over the document root. The PWA manifest, notification
  badge and any `apple-touch-icon` under that path are broken.
- **Fix:** move the directory (e.g. `/app-icons/`) and update `manifest.json`, the service worker
  and any layout references. The alias is server config we cannot change.

### P11 · Invitation links expire after 60 minutes — S3 (owner decision)

- "Set up your Syntax QA account" arrived in seconds, links to the canonical host, and says the
  link expires in 60 minutes — the password-reset broker's lifetime. An invitee who opens the
  email the next morning cannot activate. Consider a separate, longer lifetime (e.g. 72 h) for
  activation, keeping 60 minutes for resets.

### P12 · Prefetch `HEAD` requests get 503 — S3 (needs the host log)

- Every load of a tenant sign-in page issues `HEAD /`, `/login/forgot`, `/login/otp`,
  `/register` (Next.js router prefetch) and the host answers **503**. 503 is nginx's default
  `limit_req` status; it may also be the body-less-request filter. Navigation still works, but
  if these count toward the firewall's ban threshold, ordinary page loads move users toward a
  block. Not probed further on purpose. **Fix:** check the Plesk/nginx log for the 503 source;
  if it is the filter, disable prefetch on these links (`prefetch={false}`) or allow `HEAD`.

### P13 · Apex and `www` disagree about which is canonical — S3

- `https://ethr.et/*` → 301 → `https://www.ethr.et/*` (Plesk's preferred-domain setting, not the
  repository). Every page's canonical tag, `robots.txt` `Host:` and `Sitemap:` name
  `https://ethr.et`, a redirecting URL. Pick one: set Plesk's preferred domain to the apex, or
  change `NEXT_PUBLIC_SITE_URL`/canonical generation to `www`.

### P15 · No UI path gives an employee a login — S2

- **Seen:** Add Employee created `QA-001` with an email but no login. Settings → Users → Invite
  user (role *employee*) created an active login linked to **no** employee record, so that user
  cannot request leave — W2 could not be run through the UI. The employee profile has no
  "invite"/"give access" action.
- **Cause:** the API supports both links — `create_login` on `POST /employees`
  (`EmployeeController.php:143`) and `employee_id` on `POST /users` (`StoreUserRequest.php:41`)
  — but no frontend code sends either (`grep create_login|employee_id src/src` finds neither).
  In a new organisation, only CSV import or SCIM can produce an employee who can sign in.
- **Fix:** a "Send login invitation" checkbox on Add Employee (sends `create_login`) and an
  "Invite to ETHR" action on the employee profile; optionally an employee picker in Invite user.
- Note also: `employee_id` uses an unscoped `exists:employees,public_id` — the same oracle shape as
  the leave-type rule under P14.

### P16 · Log out does not sign a tenant user out — S1 (security)

- **Steps:** signed in on `syntax.ethr.et` (password + MFA) → user menu → *Log out* →
  `POST /auth/logout` 200, page shows `/login`. Then `GET /auth/me` → **200** as the admin,
  immediately and 10 s later; `GET /employees` → 200; opening `/dashboard` shows the full admin
  dashboard. `/auth/sessions` still listed the pre-logout token as current. Reproduced twice,
  ten minutes apart.
- **Effect:** on a shared or office computer the next person is signed in as the previous user,
  for up to `SESSION_LIFETIME` (480 min).
- **Root cause (code):** `AuthService::logout()` deletes `currentAccessToken()` and clears the
  token cookie. On a request authenticated by the web session (Sanctum stateful), that token is a
  `TransientToken` — nothing is deleted — and the web guard is never logged out nor the session
  invalidated. `EnforceSessionIdleTimeout::expire()` does all three; logout should do the same:
  delete the bearer token named by the cookie, `Auth::guard('web')->logout()`,
  `session()->invalidate()` and `regenerateToken()`.
- **Open question:** the same steps on the console (`admin.ethr.et`, super admin) **did** sign out
  (`/auth/me` 401 afterwards). Why the two hosts differ is not settled from outside; the host log
  or a feature test that logs out a session-authenticated tenant user will show it. Fix the code
  path either way.
- **Test:** sign in through `LoginRequest` on a tenant host, log out, expect `/auth/me` 401 on the
  same cookie jar. It fails today if the finding holds.

### P17 · The console's System Health panel answers 500 — S2

- `GET /api/v1/admin/health` → **500** `{"message":"Server Error"}` on every console home load;
  the panel renders empty. The body is not RFC-7807 (Convention #8), so the exception escaped the
  API handler's problem rendering too.
- **Probable cause:** `SystemHealthService::resourceUsage()` calls `disk_free_space('/')` outside
  any `try`. Plesk sets `open_basedir` to the subscription's directories; `/` is outside it, PHP
  raises a warning, and Laravel converts warnings to exceptions. Every other probe in the class is
  wrapped. Not confirmed without the host log.
- **Fix:** measure `storage_path()` instead of `/`, wrap the call, and report `null` on failure.

### P14 · Smaller items — S4

- Unknown subdomains (`anything.ethr.et`) serve the whole marketing site (the canonical tag
  points at the apex, which limits SEO harm); they could redirect to the apex.
- `/am/privacy` and `/am/terms`: Amharic `<title>`, English `<h1>` and body.
- The 404 page has no `<title>`; many dashboard pages keep the generic site title
  (`/employees`, `/settings/api-keys`, `/admin`).
- After registration the admin is left on `www.ethr.et`, not their canonical address; their
  next sign-in there gets P6.
- The registration form calls `check-subdomain` on every keystroke of the organisation name
  (5 calls for "QA Audit") — on a host that bans fast clients, debounce it.
- `/auth/refresh` is fired once per failed request in parallel (4× seen); single-flight it.
- Starting a payroll run is not audited — only `payroll.processed`, written by the job.
- `StoreLeaveRequestRequest` validates `leave_type_public_id` with an unscoped
  `exists:leave_types,public_id` — a cross-tenant existence oracle of the kind `CLAUDE.md`
  describes (code reading only; not probed).
- The invite dialog keeps the previous email after Cancel.
- `X-Powered-By: PleskLin` is sent on every response.
- The platform sign-in (`admin.ethr.et/login`) offers *Forgot Password?*, which cannot work for a
  super admin — password reset is tenant-only.
- Settings → Users: the per-row action buttons are icons with no accessible name.
- Profile → Security lists the active session as "not used yet" while it is in use
  (`last_used_at` is never written).
- Platform audit log: `user.password_reset_requested` / `_completed` rows show "—" for the
  account concerned; one requested from a signed-in tab is attributed to the signed-in admin
  rather than the account being reset.
- *Question, not a defect yet:* the console shows QA Audit's trial ending 11 May 2027 while
  Syntax QA and another organisation registered the same day end 11 Apr 2027 (the advertised six
  months). If nobody extended it, registration grants inconsistent trials.

## What passed

- **TLS:** `ethr.et`, `www`, `admin` and a tenant subdomain present valid Let's Encrypt
  certificates (wildcard `*.ethr.et` + apex, `admin.ethr.et` its own); TLS 1.3/1.2 only — 1.0
  and 1.1 refused.
- **Headers:** CSP with `frame-ancestors 'none'`, HSTS, `X-Frame-Options: DENY`, `nosniff`,
  `Referrer-Policy`, `Permissions-Policy`. (CSP allows `'unsafe-inline'` scripts — a known
  static-export constraint.)
- **Marketing:** all 14 pages in English and Amharic 200 with translated titles; `robots.txt`,
  `sitemap.xml`, `manifest.json`, `sw.js` 200; unknown paths 404 with `noindex`; no horizontal
  scroll at 375 px on `/en`, `/en/pricing`, `/am/features`, `/register`.
- **Addressing:** `ethr.et/ppp` → `ppp.ethr.et/login`; `admin.ethr.et` → console sign-in; apex
  sign-in → 409 → canonical host (see P6 for the UX); sessions do not leak across hosts
  (host-only cookies).
- **Auth:** wrong password and unknown email both answer the same generic 422; login limiter
  sends `X-RateLimit-*` (10 per window, keyed per account+IP — `CLAUDE.md`'s table says 5/min per
  IP); forgot-password answers a neutral message and the email arrives in the inbox, not spam,
  within seconds, linking to the canonical host.
- **Every dashboard screen:** 56 tenant pages loaded by in-app navigation, **90 API calls, all
  2xx**, no console error, no uncaught exception, no stuck skeleton; `/setup` → `/setup/guided`.
- **Write flows (org `syntax`):**

  | # | Flow | Result |
  |---|---|---|
  | W1 | Create employee (form) | 201, found by search, `salary_cents` 950000, no numeric `id`; duplicate code 422; bad email + negative salary 422 |
  | W3 | Publish announcement | 201; audience job queued — see P1 |
  | W4 | Save organisation name | `PUT /settings/organization` 200, sidebar updated |
  | W7 | Run payroll (Meskerem 2019) | 202, "Processing"; completed when the queue was drained (P1) |
  | W9 | Language → Amharic → English | `PUT /profile/preferences` 200 both ways, persisted |
  | W10 | Holiday | 201 |
  | W11 | Leave type | 201 |
  | W12 | Invite user | 201, "Invitation sent", row *Invited*; activation email delivered (see P11) |
  | W13 | Webhook (`example.com`) | 201, secret shown once; `http://127.0.0.1/…` refused 422 |
  | W14 | API key | **500 — P3** |
  | W15 | Branch + kiosk session | 201 each, token returned once |
  | W2 | Approve / reject leave | **Blocked by P15** — no employee can sign in to request leave |
  | W16 | Log out | `POST /auth/logout` 200 — **but the session survives (P16)** |

  Every write above appears in the tenant audit log (12 entries), except the payroll start (P14).
- **Isolation:** `X-Tenant` naming two other organisations → 403 `tenant-mismatch`; an unknown
  organisation → 404; the console API from a tenant host → 404 *"Platform administration is not
  available on this host"*; the console UI shows *Access Denied* to a tenant admin without
  calling the API; empty employee create → 422 naming the missing fields.
- **Ops:** maintenance endpoint (`POST …/maintenance/mail-probe`, with a body) → 404 with no
  token and with an empty one; the cron endpoint refuses a browser-originated call (419); mail
  delivery works (two messages, both inbox); `/api/v1/health` reports database, cache, storage
  healthy and `failed_jobs: 0`.

- **MFA:** the owner enrolled an authenticator on the `syntax` admin; sign-in then required the
  code, Profile → Security shows two-step verification *enabled* and the browser as a trusted
  device for 30 days. The super admin signs in with MFA too.
- **Platform console (super admin, read-only):** home (5 tenants, all trial; P17 aside), tenants
  list, Syntax QA detail — address mode *Own subdomain* `https://syntax.ethr.et`, plan Starter,
  activity trail matching this run — and the platform audit log (50 rows, times in EAT); every
  call 2xx except `/admin/health`, no console errors. Console logout signs out (`/auth/me` 401).

## Not run, and why

- **Leave approve/reject (W2)** — blocked by P15.
- **Rate limiting to the threshold** — deliberately not driven past `X-RateLimit-Remaining`; the
  host bans fast clients.
- **Payroll approve** — needs the owner's go-ahead per the run's rules.

## Test data left in production

- Organisation **`qa-audit`** (no data; its admin never signed in again — the password was lost).
- Organisation **`syntax`** ("Syntax QA"): employee `QA-001`, branch, kiosk session, holiday,
  leave type, webhook to `example.com`, announcement, one invited user (`<owner>+qa-invite`,
  activated, not linked to an employee), one payroll run (September 2026 / Meskerem 2019),
  processed, not approved. Its admin has MFA enabled.

Removing either organisation is a delete and waits for the owner.

## Execution log

- 2026-10-11 13:55–15:12 UTC and 2026-10-12 10:17–10:55 UTC, Claude in Chrome on the owner's
  machine; mobile width via the desktop app's built-in browser emulation (375×812). Requests
  paced 2.5–7 s apart; no 403 from the host firewall at any point.
- The scheduler workflow was dispatched by hand once (2026-10-11 15:10 UTC), with the owner's
  approval, to drain the queue for W3/W7.
- **Harness mistakes, recorded so they are not read as findings:** a probe list briefly included
  `/.env` (20th of 22, 2.5 s apart); the loop was stopped by reloading the page well before it
  should have reached that entry, and its results were discarded unread. It was re-run
  without it. `.env` sits outside the document root (`api/public`) in any case. The first invite attempt sent a doubled email address typed by the harness
  (correct 422). A checkbox click missed once (no request sent).
