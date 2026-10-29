# E2E specification 09: non-functional

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Security checks here
are **black-box and single-request**. No scanner, fuzzer or load tool may be pointed at production
without the owner's written authorisation (test plan, rule 9.7).

---

## 1. Security

### 1.1 Transport, headers and files

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SEC-001 | P1 | R | HTTPS only | `http://www.ethr.et/`, `http://admin.ethr.et/` | Redirect to HTTPS; valid certificate on every host used (apex, www, admin, organisation subdomains if used) |
| SEC-002 | P1 | R | API headers | `curl -I https://www.ethr.et/api/v1/ping` | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Strict-Transport-Security` (one year, includeSubDomains), a restrictive `Content-Security-Policy` with `frame-ancestors 'none'`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` limiting camera and geolocation to self |
| SEC-003 | P1 | R | Page headers | `curl -I https://www.ethr.et/en` | CSP with `default-src 'self'` and `frame-ancestors 'none'`; HSTS; no `X-Powered-By` |
| SEC-004 | P1 | R | Secret files | Request `/.env`, `/api/.env`, `/.git/config`, `/artisan`, `/composer.json`, `/storage/logs/laravel.log`, `/api/storage/logs/`, at most three in ten minutes ([01, rule 10](01-TEST-PLAN.md#9-production-safety-rules)) | 403 or 404, never contents |
| SEC-005 | P1 | R | No debug output | Cause a 404 and a 422 on the API; open an unknown page | Problem JSON or the product's own error page; no stack trace, file path, SQL or framework banner |
| SEC-006 | P1 | R | Cron and maintenance closed | `POST /api/v1/cron/queue` without a token, with a wrong token; `POST /api/v1/maintenance/migrate`. Send each with a body (`curl --data ''`) | JSON 404 for each (a wrong token is answered as if the route did not exist). An HTML 403 means the host's firewall refused a `POST` with no body before the application saw it, and counts towards a ban ([01, rule 10](01-TEST-PLAN.md#9-production-safety-rules)) |
| SEC-007 | P2 | R | API docs | `https://www.ethr.et/api/docs` | Record whether the API documentation UI is public on production and report it to the owner for a decision (it is registered when the documentation package is installed) |
| SEC-008 | P1 | R | Framing | Embed `https://www.ethr.et/login` in an iframe on a local HTML page | Refused by the browser |
| SEC-009 | P1 | R | CORS | From a page on another origin, call the API with credentials | Blocked. Only `https://www.ethr.et` is allowed: any other origin, an organisation's own `*.ethr.et` address included, gets `Access-Control-Allow-Origin: https://www.ethr.et` and the browser refuses it. Organisation pages call their own address, so they never need CORS (decided 2026-10-29) |

### 1.2 Session and request integrity

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SEC-010 | P1 | R | Token not in storage | After sign-in, inspect `localStorage`, `sessionStorage`, page source | No access token; it is an httpOnly cookie (SES-007) |
| SEC-011 | P1 | R | CSRF | Send a state-changing API call with the session cookie but without the XSRF token | 419; the app itself fetches `/sanctum/csrf-cookie` and succeeds |
| SEC-012 | P1 | R | Numeric ids never exposed | Inspect any API response | Ids are 26-character ULIDs (`public_id`); no numeric `id` field |
| SEC-013 | P1 | R | Id guessing | Change one character of a record's ULID in the URL | 404 "The requested resource was not found." |
| SEC-014 | P1 | W | Mass assignment | Update own profile with extra fields `role: tenant_admin`, `tenant_id`, `salary_cents` (API) | Ignored or refused; nothing changes |
| SEC-015 | P1 | W | Stored script | Put `<script>alert(1)</script>` and `<img src=x onerror=alert(1)>` in an employee name, announcement body, concern, vacancy title, landing headline | Shown as text everywhere, including the public landing page, PDFs, emails and exports; no script runs |
| SEC-016 | P1 | W | File content check | Upload a renamed executable as a PDF and as a JPEG in each upload (documents, cover, logo, photo, import) | Refused by content, not name |
| SEC-017 | P1 | W | EXIF removed | Upload a JPEG with GPS EXIF as a profile photo; download it | No GPS or camera EXIF in the stored file |
| SEC-018 | P2 | W | Webhook targets | Register webhooks to `http://169.254.169.254`, `http://[::1]`, a domain resolving to a private IP | Refused at save or at send time; no request reaches it |
| SEC-019 | P1 | R | Sensitive data at rest (observable part) | TIN, national ID never in responses; bank account masked | As stated (encryption itself can only be confirmed by the owner on the database) |
| SEC-020 | P1 | R | Error enumeration | Sign-in, reset, find organisation, sign-in code for real and unknown accounts | Identical messages and status codes (AUTH-003, PWD-002, ORG-002, AUTH-024) |
| SEC-021 | P1 | R | Signed out, no Accept header | `curl -i https://www.ethr.et/api/v1/employees -H 'X-Tenant: qa-audit'` with no `Accept`; repeat for `/payroll/runs`, `/leave/requests`, and once with `Accept: application/json` | 401 every time, `application/problem+json`, `type` `https://ethr.et/errors/unauthenticated`, "You are not authenticated." Never a 500 or a redirect page |
| SEC-022 | P1 | R | Validation is a problem document | `POST /api/v1/auth/password/forgot` with an empty JSON body | 422, `application/problem+json`, `type` `https://ethr.et/errors/validation`, `title` "Validation Failed", "The given data was invalid.", `errors.email` |
| SEC-023 | P2 | R | Refused punch and unreadable device | EMP with nothing open: `POST /attendance/check-out` (with an idempotency key). TA, a registered test device switched off: `GET /devices/{id}/enrollments` | Punch: 422 problem+json, "There is no open check-in to close." Device: 502 problem+json, `type` `https://ethr.et/errors/device-unreachable`, "The device could not be read. Check that it is switched on and reachable from this server, then try again." |
| SEC-024 | P1 | R | No key material | Inspect `GET /attendance/devices`, `GET /attendance/device`, `GET /attendance/devices/unregistered` and the audit entries from 05 section 15 | No `credential_id`, `public_key`, `install_hash`, `cookie_hash` or challenge; only the employee's own `credential_id` in their offline grant |
| SEC-025 | P1 | W | Another person's passkey (API) | Laptop holding EMP's registered passkey; sign in there as EMP2 (who has a phone); sign EMP2's challenge with EMP's passkey (`navigator.credentials.get`, no `allowCredentials`) and send it as EMP2's web check-in under enforce | 422 `device-belongs-to-another`; audit `attendance.device_refused` on EMP2; nothing recorded |
| SEC-026 | P2 | W | Approving one's own phone | HR registers HR's own phone away from work and approves it | Record the result. The code allows it (no self-approval check, unlike INT-004 and COR-004; K-34) |
| SEC-027 | P2 | R | Phones and reach | A custom role with `attendance.manage` and branch reach (B1) opens Phones and revokes a B2 employee's phone | Record the result. The code applies no reach: every branch is listed and can be decided (K-34) |

## 2. Tenant isolation

Run **on every release**, between QA Audit and QA Isolation only. Create in QA Isolation one of each:
employee, department, branch, leave request, attendance record, payroll run, announcement (draft and
published internal), document, webhook, API key. Record their public ids. Then, signed in to QA
Audit as TA (the widest reach):

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ISO-001 | P1 | R | Direct read | `GET` each QA Isolation record by its public id on QA Audit's address | 404 for every one, never data |
| ISO-002 | P1 | R | Lists | Employee list, directory, search palette, announcements, reports, exports, dashboards, analytics | No QA Isolation record anywhere |
| ISO-003 | P1 | R | Write by id | `PUT`, `DELETE`, approve, void on QA Isolation records by id | 404, nothing changes in QA Isolation (check from QA Isolation) |
| ISO-004 | P1 | R | Relation ids | Create a QA Audit employee with QA Isolation's department, branch, position, grade, team, cost centre, supervisor ids | 422, generic message (EMP-007) |
| ISO-005 | P1 | R | Header switching | On the apex, send QA Audit's session with `X-Tenant: <QA Isolation slug>` | 403 `tenant-mismatch` |
| ISO-006 | P1 | R | Cross-tenant tokens | Use QA Isolation's API key, kiosk token, QR code, webhook token, offline grant and SCIM token against QA Audit | Each refused |
| ISO-007 | P1 | R | Child routes | `/employees/{QA Audit employee}/documents/{QA Isolation document}` and the same for bank details, contacts, education, contracts | 404 |
| ISO-008 | P1 | R | Public surfaces | QA Audit's public landing feed, news, vacancies | Only QA Audit's public items |
| ISO-009 | P1 | R | Imports | Import into QA Audit a CSV naming QA Isolation's employee codes and department names | Treated as not found or as new; nothing is linked to QA Isolation |
| ISO-010 | P1 | R | Reverse direction | Repeat ISO-001 and ISO-003 from QA Isolation towards QA Audit | Same results |
| ISO-011 | P1 | R | Notifications and emails | Any email or notification produced during the pass | Names only the recipient's own organisation and its people |
| ISO-012 | P1 | R | Phones across organisations | QA Audit TA: `POST /attendance/devices/{QA Isolation phone id}/approve`, `/reject`, `DELETE /attendance/devices/{id}`, `PUT /employees/{QA Isolation employee}/attendance-device-exemption` | 404 each; nothing changes in QA Isolation. QA Audit's Phones page and Not registered list never show QA Isolation people |
| ISO-013 | P1 | R | Invitations across organisations | Run INV-005, INV-008 and MIG-007 from QA Audit towards QA Isolation, and back | No invitation is queued for the other organisation, its batch reads 404, its employee ids read as unknown; QA Isolation's users receive nothing |

**Any failure in this section is S1.** Stop and tell the owner (test plan, section 7.3).

## 3. Rate limits

Values are per `api/app/Providers/AppServiceProvider.php`. Each throttled answer is 429 problem JSON
with `Retry-After`. Test each once, on a spare account, and wait for the window to clear.

| ID | P | Mode | Endpoint | Limit | Expected |
|---|---|---|---|---|---|
| RL-001 | P1 | R | Sign-in failures | 5 a minute per identifier and IP; 10 in 15 min locks 15 min | AUTH-010 |
| RL-002 | P2 | R | Auth group (register, login, reset, find organisation, contact, SSO, hand-off) | 10 a minute per account and IP; 100 a minute per IP | 429 on the 11th |
| RL-003 | P2 | X | Sign-in code | 3 a minute per address or phone | AUTH-025 |
| RL-004 | P1 | R | Payroll process and reprocess | 2 an hour per organisation | PAY-024 |
| RL-005 | P2 | R | Employee import preview and commit | 5 an hour per organisation | IMP-E-009 |
| RL-006 | P3 | R | Uploads | 30 a minute per user | DOC-010 |
| RL-007 | P3 | R | Dashboards | 30 a minute per user | DASH-003 |
| RL-008 | P3 | X | Webhook test | 10 an hour per organisation | WH-004 |
| RL-009 | P3 | R | Kiosk exit | 5 a minute per kiosk | KIO-007 |
| RL-010 | P3 | R | Health | 30 a minute per IP | 429 on the 31st |
| RL-011 | P3 | R | Global | 300 a minute per user (or per IP for guests) | Not tested on production; only on the rehearsal |

## 4. Performance (single-request timings)

Measure with the browser's developer tools (Network, "disable cache" off, an ordinary connection in
Ethiopia if possible), three times, and record the median. These are the product targets; on shared
hosting, record the figures and flag only large misses (more than double) as S3.

| ID | P | Mode | Measure | Target |
|---|---|---|---|---|
| PERF-001 | P2 | R | API p95 for common reads (`/auth/me`, `/employees`, `/attendance/my`) | under 200 ms server time |
| PERF-002 | P2 | R | Dashboard page load to interactive | under 1.5 s |
| PERF-003 | P2 | R | Employee list, 1,000 rows (only if QA Audit has them) | under 500 ms |
| PERF-004 | P2 | R | Employee search | under 100 ms server time |
| PERF-005 | P2 | R | Payroll run of the QA employees, from submit to completed | under 30 s of processing once picked up from the queue (queue wait is separate; record both) |
| PERF-006 | P2 | R | Lighthouse on `/en` and on the dashboard (mobile) | Performance over 80, Accessibility over 90; FCP under 1.5 s, LCP under 2.5 s, CLS under 0.1 |
| PERF-007 | P3 | R | Offline sync of 100 records | under 5 s |

## 5. Accessibility (WCAG 2.1 AA)

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| A11Y-001 | P1 | R | Automated scan | axe DevTools (or the `test:e2e:a11y` spec pointed at production) on sign-in, dashboard, employees, attendance, leave, payroll run, settings, kiosk, marketing home | No critical or serious violation |
| A11Y-002 | P1 | R | Keyboard only | Complete sign-in, leave request and check-in with the keyboard | Every control reachable in a sensible order; focus visible; dialogs trap focus and close with Escape; Enter submits dialogs |
| A11Y-003 | P2 | R | Screen reader | NVDA or VoiceOver on sign-in and the leave form | Fields have labels; errors are announced |
| A11Y-004 | P2 | R | Contrast | Light, dark and high-contrast themes | Text and controls meet AA contrast |
| A11Y-005 | P2 | R | Zoom | Browser zoom 200 percent | Content usable, no overlap |
| A11Y-006 | P2 | R | Sidebar footer contrast and names | axe or a contrast checker on the footer in light, dark and high-contrast; accessibility tree of both controls | Label at least 4.5:1 (designed 7.0:1 on navy, 7.8:1 on dark). The collapsed mark and the toggle each have an `aria-label`; the toggle has `aria-keyshortcuts="Control+B Meta+B"`; neither uses a `title` tooltip |

## 6. Localisation and the Ethiopian calendar

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| L10N-001 | P1 | R | Full Amharic | Switch to አማርኛ; visit every page in the sidebar for TA | No English left, no raw keys (`leave.min_notice` style), no `?` boxes for missing glyphs. Note pages that fail |
| L10N-002 | P1 | R | No clipping | Amharic on buttons, table headers, sidebar, mobile bottom bar | Not cut off; line height comfortable |
| L10N-003 | P1 | R | Messages | Trigger validation errors and API refusals in Amharic | Shown in Amharic (server messages too where translated); note any English |
| L10N-004 | P1 | R | Ethiopian dates | Calendar Ethiopian: attendance list, leave dates, payroll period, holidays | Correct Ethiopian dates. Example: 2026-10-24 is 14 Tikimt 2019; 2026-09-11 is 1 Meskerem 2019 |
| L10N-005 | P1 | R | Pagume | Date pickers around early September | Pagume shows 5 days, or 6 in an Ethiopian leap year (Pagume 2019 EC, 6 to 11 September 2027, has 6 days, so Meskerem 1, 2020 falls on 12 September 2027) |
| L10N-006 | P1 | R | Time zone | Every time shown | EAT (UTC+3); a record at 09:00 EAT shows 09:00, never 06:00 |
| L10N-007 | P2 | R | Currency format | Amounts in the interface and exports | `X,XXX.XX ETB`; no floating-point artefacts (for example 1,349.9999) |
| L10N-008 | P2 | R | Amharic in files | PDFs (payslip, letters, statutory schedules, reports), CSV exports opened in a spreadsheet | Amharic renders; CSV opens as UTF-8 |
| L10N-009 | P3 | R | Other languages | Preferences language list | Afaan Oromoo, Tigrinya, Somali, Sidama listed as coming soon, not selectable |

## 7. Responsive layout, themes and browsers

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| UI-001 | P1 | R | Phone, 375 px | Dashboard, attendance mobile, leave, payslips, approvals | No horizontal scroll; bottom bar (Home, Attendance, Leave, Payslips or Approvals for approvers, More) |
| UI-002 | P1 | R | Tablet 768 px, desktop 1280 px and over | Same pages plus employees and payroll run | Sidebar on desktop; tables scroll inside their container, not the page |
| UI-003 | P2 | R | Themes | Light, dark, system, high contrast | Every page repaints; no unreadable text; choice persists |
| UI-004 | P2 | R | Four data states | For list pages: loading, empty, error (go offline mid-load), data | Skeleton while loading; an empty state with an action; an error with a retry; never a blank page |
| UI-005 | P2 | R | Unsaved changes | Edit a long form, navigate away | Warned |
| UI-006 | P1 | R | Browsers | Chrome, Edge, Firefox (latest), Safari on iPhone, Chrome on Android | Sign-in, check-in, leave request and payslip download work in each |
| UI-007 | P3 | R | Console clean | Developer tools console on the main pages | No errors |
| UI-008 | P2 | R | Shortcuts | Ctrl+K palette, Ctrl+B collapse sidebar | Work |
| UI-009 | P2 | R | Sidebar footer | QA Audit, desktop, sidebar expanded then collapsed (Ctrl+B or the icon), en and am; hover each footer control | One row: the ETHR logo tile (white E on brand blue, or the platform's uploaded logo) and "Powered by ETHR" / "በETHR የሚሰራ", opening the public site in a new tab, plus a square collapse icon with tooltip "Collapse sidebar" or "Expand sidebar". No "Command Palette Ctrl+K" or "Collapse Ctrl+B" row. On the rail the tile remains, "Powered by ETHR" as a tooltip. Ctrl+B (Cmd+B) still toggles |

## 8. PWA and offline

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PWA-001 | P1 | R | Installable | Chrome: install prompt or Add to Home screen; iPhone: Add to Home Screen | Installs with the ETHR icon and name; `/manifest.json` and icons load (`/app-icons/icon-192.png` 200) |
| PWA-002 | P1 | R | Offline page | Installed app, airplane mode, open a page not cached | The offline page in English and Amharic with a retry button |
| PWA-003 | P1 | W | Offline attendance | OFF-002 | As stated |
| PWA-004 | P2 | R | Update after release | After a new release, reopen the installed app | The new version loads (no stale bundle); `release.json` matches |
| PWA-005 | P2 | R | Offline banner | Lose network while using the app | A banner says the app is offline; actions that need the network say so |
| PUSH-001 | P1 | R | Push configured | `GET /push/config` (any signed-in user) | `enabled: true` with a `public_key`. If false, VAPID keys are missing and `POST /push/subscriptions` answers 409 "Phone notifications are not set up on this server yet." |
| PUSH-002 | P1 | W | Subscribe on a phone | EMP: `/attendance/mobile` → "Even when ETHR is closed" on; allow notifications (iPhone: add to the Home Screen first). Block notifications and retry | 201; `GET /push/subscriptions` shows `devices: 1`. Blocked: "Notifications are blocked for ETHR. Allow them in your browser settings." |
| PUSH-003 | P1 | X | Every in-app notice reaches the phone | Close ETHR on EMP's phone; HR approves an EMP leave request (or SUP a correction); PA then reads the console's failed jobs | A push with the notification's own text (title from the notification or "ETHR"); tapping opens ETHR on the linked page. It may be a few minutes late (sent from the queue by the cron caller). No `SendWebPush` failed job, and no `TypeError`, which would mean the web-push 11 regression is back |
| PUSH-004 | P2 | W | Shared phone | EMP subscribes, signs out; EMP2 signs in on the same browser and subscribes; EMP unsubscribes from another device | The endpoint moves to EMP2 (one owner per endpoint). DELETE removes only the caller's own subscription |
| PUSH-005 | P2 | R | Content Security Policy for map and liveness | Read the CSP header on `/attendance/mobile` | Allows `'wasm-unsafe-eval'` (not `'unsafe-eval'`), `storage.googleapis.com`, `nominatim.openstreetmap.org`, `maps.googleapis.com` by name, nothing wider |

## 9. Resilience and operations (observe only)

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| OPS-001 | P1 | R | Health | `GET /api/v1/health` | `database`, `cache`, `queue`, `storage`, `database_read` healthy; `queue_detail` heartbeat under 900 s old, a recent `last_drain_at`, low queue depth, failed jobs count; `mail_detail` with last sent and TLS mode |
| OPS-002 | P1 | R | Scheduler running | Read `queue_detail` three times, 5 minutes apart | Heartbeat advances about every 5 minutes |
| OPS-003 | P2 | R | Queue drains | Trigger something queued (announcement to all) | Notifications arrive and queue depth returns to near zero within 10 minutes |
| OPS-004 | P2 | R | Logs grow sensibly | Ask the owner for the day's log size after the pass | No flood of errors caused by the test pass |
