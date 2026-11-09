# E2E specification 09: non-functional

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Security checks here
are **black-box and single-request**. No scanner, fuzzer or load tool may be pointed at production
without the owner's written authorisation (test plan, rule 9.7).

**Third edition (2026-11-08).** Cases were added for Afaan Oromoo (#546, #549, #552, #553), Amharic
wording (#513), language and calendar choices that follow the person (#478, #484, #485, #539), the
Ethiopian time of day and the reader's calendar on screens, notices and files (#508, #512, #514,
#520), the installed app offline (#466, #483, #494, #496), tokens read only from headers and bodies
(#497), device and webhook addresses (#489, #495), the skip link (#588) and page weight (#487, #491,
#500, #518, #538). Section 3 was re-read from the code after #486 and #632. Cases these changed were
corrected in place: SEC-003, SEC-006, SEC-007, SEC-010, SEC-011, SEC-018, RL-001 to RL-003, RL-009,
L10N-008, L10N-009, PWA-001 (#469) and PWA-002. The sign-in flow itself is file 03's (AUTH-035 to AUTH-038, MFA-012,
MFA-013, SES-008, SES-009, PWD-015); exported pages and the landing feed's caching are 08's LAND-009
and NEWS-003; dashboard requests, "this month" and phone tap targets are 08's DASH-014, DASH-007 and
DASH-012; times on attendance screens are 05's ATT-014. Priorities were re-tiered: **P1** is kept for
what would be an S1: tenant isolation, missing security controls (headers, CSRF, token handling,
upload validation, pay data kept offline) and sign-in limits. Localisation, calendar, offline
behaviour, accessibility blockers and lesser security observations are **P2**; timings, page weight,
cosmetic checks and secondary limits are **P3**.

**Since the third edition (2026-11-09).** Cases were added or changed for phones nobody below the
organisation admin approves or exempts for themselves (#680: SEC-026, SEC-027), push endpoints held to
the browsers' push services (#670: PUSH-006) and a failed scheduled task that no longer reports "ok"
(#669: OPS-006). How stored files are named and served (#679) is 04's DOC-012; a write that names no
organisation (#668) is 03's TEN-027.

---

## 1. Security

### 1.1 Transport, headers and files

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SEC-001 | P1 | R | HTTPS only | `http://www.ethr.et/`, `http://admin.ethr.et/` | Redirect to HTTPS; valid certificate on every host used (apex, www, admin, organisation subdomains if used) |
| SEC-002 | P1 | R | API headers | `curl -I https://www.ethr.et/api/v1/ping` | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Strict-Transport-Security` (one year, includeSubDomains), a restrictive `Content-Security-Policy` with `frame-ancestors 'none'`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` limiting camera and geolocation to self |
| SEC-003 | P1 | R | Page headers (#632) | `curl -I https://www.ethr.et/en` and `https://www.ethr.et/features`; then `curl -I https://www.ethr.et/` | Clean addresses: CSP with `default-src 'self'` and `frame-ancestors 'none'`; HSTS; `X-Frame-Options: DENY`; no `X-Powered-By` naming PHP (`X-Powered-By: PleskLin` is added by the host's nginx, outside the release's reach, and is expected). `/` and any direct `.html` address are answered by the host's nginx without these headers; those pages carry the policy themselves and hide inside a frame (LAND-009) |
| SEC-004 | P1 | R | Secret files | Request `/.env`, `/api/.env`, `/.git/config`, `/artisan`, `/composer.json`, `/storage/logs/laravel.log`, `/api/storage/logs/`, at most three in ten minutes ([01, rule 10](01-TEST-PLAN.md#9-production-safety-rules)) | 403 or 404, never contents |
| SEC-005 | P1 | R | No debug output | Cause a 404 and a 422 on the API; open an unknown page | Problem JSON or the product's own error page; no stack trace, file path, SQL or framework banner |
| SEC-006 | P1 | R | Cron and maintenance closed (#497) | `POST /api/v1/cron/queue` without a token, with a wrong `X-Cron-Token`, and with `?token=x` in the address; `POST /api/v1/maintenance/migrate`. Send each with a body (`curl --data ''`) | JSON 404 for each (a wrong token is answered as if the route did not exist). Only the `X-Cron-Token` header is read: a token in the address is ignored. An HTML 403 means the host's firewall refused a `POST` with no body before the application saw it, and counts towards a ban ([01, rule 10](01-TEST-PLAN.md#9-production-safety-rules)) |
| SEC-007 | P2 | R | API docs (K-15) | Signed out: `curl -i https://www.ethr.et/api/docs`. Then open it in a browser signed in as TA | 403 both times, never the API map: the documentation is served only to a signed-in platform super admin (`viewApiDocs`). A 200 is a defect (S2). The organisation's own API reference (Settings → API Keys → API reference) is a separate page and is expected |
| SEC-008 | P1 | R | Framing | Embed `https://www.ethr.et/login` in an iframe on a local HTML page | Refused by the browser |
| SEC-009 | P1 | R | CORS | From a page on another origin, call the API with credentials | Blocked. Only `https://www.ethr.et` is allowed: any other origin, an organisation's own `*.ethr.et` address included, gets `Access-Control-Allow-Origin: https://www.ethr.et` and the browser refuses it. Organisation pages call their own address, so they never need CORS (decided 2026-10-29) |

### 1.2 Session and request integrity

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SEC-010 | P1 | R | Token not in storage (#483) | After sign-in, inspect `localStorage`, `sessionStorage`, page source | No access token anywhere; it is an httpOnly cookie (SES-007). The only identity in `localStorage` is `ethr.offline-identity`, with no token, email, phone or photo (PWA-007) |
| SEC-011 | P1 | R | CSRF (#632) | Send a state-changing API call with the session cookie but without the XSRF token | 419. In the app, a 419 makes it fetch `/sanctum/csrf-cookie` and send the write once more (SES-009). A write with the session cookie from another site's origin is 401 (SES-008) |
| SEC-012 | P1 | R | Numeric ids never exposed | Inspect any API response | Ids are 26-character ULIDs (`public_id`); no numeric `id` field |
| SEC-013 | P1 | R | Id guessing | Change one character of a record's ULID in the URL | 404 "The requested resource was not found." |
| SEC-014 | P1 | W | Mass assignment | Update own profile with extra fields `role: tenant_admin`, `tenant_id`, `salary_cents` (API) | Ignored or refused; nothing changes |
| SEC-015 | P1 | W | Stored script | Put `<script>alert(1)</script>` and `<img src=x onerror=alert(1)>` in an employee name, announcement body, concern, vacancy title, landing headline | Shown as text everywhere, including the public landing page, PDFs, emails and exports; no script runs |
| SEC-016 | P1 | W | File content check | Upload a renamed executable as a PDF and as a JPEG in each upload (documents, cover, logo, photo, import) | Refused by content, not name. What an accepted file is stored and served as is 04's DOC-012 (#679) |
| SEC-017 | P1 | W | EXIF removed | Upload a JPEG with GPS EXIF as a profile photo; download it | No GPS or camera EXIF in the stored file |
| SEC-018 | P2 | W | Webhook targets (#489) | Register webhooks to `http://169.254.169.254`, `http://[::1]`, a domain resolving to a private IP | 422 at save, "The url must not point to an internal address." (English in every language: the message is not a translation key). A name that points somewhere internal only after saving is refused at send time (WH-009); no request reaches it |
| SEC-019 | P1 | R | Sensitive data at rest (observable part) | TIN, national ID never in responses; bank account masked | As stated (encryption itself can only be confirmed by the owner on the database) |
| SEC-020 | P1 | R | Error enumeration | Sign-in, reset, find organisation, sign-in code for real and unknown accounts | Identical messages and status codes (AUTH-003, PWD-002, ORG-002, AUTH-024) |
| SEC-021 | P2 | R | Signed out, no Accept header | `curl -i https://www.ethr.et/api/v1/employees -H 'X-Tenant: qa-audit'` with no `Accept`; repeat for `/payroll/runs`, `/leave/requests`, and once with `Accept: application/json` | 401 every time, `application/problem+json`, `type` `https://ethr.et/errors/unauthenticated`, "You are not authenticated." Never a 500 or a redirect page |
| SEC-022 | P2 | R | Validation is a problem document | `POST /api/v1/auth/password/forgot` with an empty JSON body | 422, `application/problem+json`, `type` `https://ethr.et/errors/validation`, `title` "Validation Failed", "The given data was invalid.", `errors.email` |
| SEC-023 | P2 | R | Refused punch and unreadable device | EMP with nothing open: `POST /attendance/check-out` (with an idempotency key). TA, a registered test device switched off: `GET /devices/{id}/enrollments` | Punch: 422 problem+json, "There is no open check-in to close." Device: 502 problem+json, `type` `https://ethr.et/errors/device-unreachable`, "The device could not be read. Check that it is switched on and reachable from this server, then try again." |
| SEC-024 | P1 | R | No key material | Inspect `GET /attendance/devices`, `GET /attendance/device`, `GET /attendance/devices/unregistered` and the audit entries from 05 section 15 | No `credential_id`, `public_key`, `install_hash`, `cookie_hash` or challenge; only the employee's own `credential_id` in their offline grant |
| SEC-025 | P1 | W | Another person's passkey (API) | Laptop holding EMP's registered passkey; sign in there as EMP2 (who has a phone); sign EMP2's challenge with EMP's passkey (`navigator.credentials.get`, no `allowCredentials`) and send it as EMP2's web check-in under enforce | 422 `device-belongs-to-another`; audit `attendance.device_refused` on EMP2; nothing recorded |
| SEC-026 | P1 | W | Approving or exempting one's own phone (#680, K-34) | Registered phone on (flag or enforce). HR registers HR's own phone away from work, so it waits; HR approves it (Phones, then `POST /attendance/devices/{id}/approve`); HR sets, then ends, an exemption on HR's own record (`PUT /employees/{HR}/attendance-device-exemption`). Then TA approves HR's phone; HR revokes it. TA exempts TA's own record and ends it | Approve: 403 "Someone else in your organisation must approve your own phone."; the phone stays waiting. Exemption, set or ended: 403 "Someone else in your organisation must exempt you from punching with your registered phone." Refusing or revoking one's own phone is allowed (it only takes access away). TA: approved, and TA's own exemption saved and ended, since the organisation admin has no one above them. Before #680 HR could choose the phone that proves HR was at work, as INT-004 and COR-004 already refuse for flags and corrections |
| SEC-027 | P2 | R | Phones and reach | A custom role with `attendance.manage` and branch reach (B1) opens Phones and revokes a B2 employee's phone | B1 people only in every list and card; the B2 decision answers 404 and nothing changes (#490, as 05's PHN-001; K-34) |
| SEC-028 | P1 | W | Kiosk token never read from the address (#497) | QA Lobby kiosk (KIO-001, KIO-002): copy `POST /api/v1/kiosk/authenticate` from the kiosk's network tab as cURL. On your own machine send it once unchanged, then with the token taken out of the body (`--data '{}'`) and put in the address as `?token=`. Never paste the token into a log. Afterwards deactivate QA Lobby and register a new kiosk, since the second request put the token in the host's access log | Unchanged: accepted. Token in the address: 401 problem JSON, "Invalid or inactive kiosk session." Check-in reads its token from the `X-Kiosk-Token` header only, and kiosk exit from the body only |
| SEC-029 | P2 | W | Device addresses (#495) | HR: Devices → add a Hikvision terminal with address `10.0.0.5`, then `127.0.0.1`, then `http://1.2.3.4:80/x` | First two: refused at save, "The device address points to a private or internal network, which this server does not connect to." Third: "The device address must be a bare IP address or hostname, with no scheme, port or path." Nothing is saved. Do not try other addresses: a saved device is connected to at its checked address only |

## 2. Tenant isolation

**L0 evidence, in CI (#645).** `CrossTenantRouteSweepTest` builds one record of every kind in
organisation A and calls every `api/v1` route that names a record (242 routes; GET, PUT, PATCH, POST
and DELETE) as organisation B's tenant admin. Each must answer 403 or 404; a 422 is accepted only for
nine routes that validate the body first, each read by hand. A green CI run on the release commit is
the precondition for this section, not a substitute for it: the sweep sends no body and runs on test
databases, not on production.

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

Values are per `api/app/Providers/AppServiceProvider.php`, `Http/Middleware/RateLimitLoginAttempts.php`,
`Services/MfaService.php` and `Services/Auth/OtpService.php` (read 2026-11-08). Each throttled answer
is 429 problem JSON with `Retry-After`. A window starts at the first counted attempt, so a lock ends a
fixed time after the first failure, not after the last. Test each once, on a spare account, and wait
for the window to clear.

| ID | P | Mode | Endpoint | Limit | Expected |
|---|---|---|---|---|---|
| RL-001 | P1 | R | Sign-in failures (#486, #632) | 5 failed sign-ins (401 or 422) in a minute per identifier, organisation and IP address; 10 in 15 minutes lock the same for 15 minutes. A phone number counts once however it is written; each organisation counts separately; a successful sign-in clears both counts | AUTH-010, AUTH-035, AUTH-036: "Too many failed login attempts. Please try again in N seconds." |
| RL-002 | P2 | R | Auth group (register, sign-in, password reset, sign-in codes, find organisation, contact, SSO, hand-off, concern tracking) (#632) | 10 a minute per account, organisation and IP address; 100 a minute per IP address | With an address that has no account (so nothing is emailed): the 11th `POST /auth/password/forgot` in a minute is 429 |
| RL-003 | P2 | X | Sign-in code sends (#486) | 3 a minute and 10 an hour per address or phone number (a number counts once however written); 30 a minute per IP address | AUTH-025 |
| RL-004 | P2 | R | Payroll process and reprocess | 2 an hour per organisation | PAY-024 |
| RL-005 | P2 | R | Employee import preview and commit | 5 an hour per organisation | IMP-E-009 |
| RL-006 | P3 | R | Uploads | 30 a minute per user | DOC-010 |
| RL-007 | P3 | R | Dashboards and Next steps | 30 a minute per user | DASH-003 |
| RL-008 | P3 | X | Webhook test | 10 an hour per organisation | WH-004 |
| RL-009 | P3 | R | Kiosk exit | 5 a minute per kiosk, counted on the token in the body | KIO-007 |
| RL-010 | P3 | R | Health | 30 a minute per IP | 429 on the 31st |
| RL-011 | P3 | R | Global | 300 a minute per user (or per IP for guests); an API key has its own 300 | Not tested on production; only on the rehearsal |
| RL-012 | P1 | R | Wrong authenticator and sign-in codes (#486) | 5 wrong codes in 15 minutes per account (per purpose for sign-in codes) lock for 15 minutes from the first; a right code is refused meanwhile | Run as MFA-012 and AUTH-038, not twice |
| RL-013 | P2 | R | In-person activation code redemption | 20 a minute per IP address; 10 a minute per organisation and employee code; the code itself locks after five wrong tries | ACT-006, ACT-013 |
| RL-014 | P3 | R | Public reads | Plans, site content, templates, organisation types, customer list: 120 a minute per IP. An organisation's sign-in branding (`/auth/tenant-context`): 300 a minute per IP. Landing feed, kiosk, SCIM and device webhooks: 60 a minute per IP or user | Not tested on production. A whole office behind one address loading the sign-in page at once is not refused |
| RL-015 | P3 | R | Secondary limits | Letter requests 10 an hour per user; help requests 10 an hour per user, replies 10 a minute; address rename 5 an hour per organisation; platform console 120 a minute per user; SMS gateway test and backup storage test 10 an hour per user; backup key 5 an hour | Not tested on their own; if a case reaches one, the answer is 429 with `Retry-After` |

## 4. Performance (single-request timings)

Measure with the browser's developer tools (Network, "disable cache" off, an ordinary connection in
Ethiopia if possible), three times, and record the median. These are the product targets; on shared
hosting, record the figures and flag only large misses (more than double) as S3. PERF-008 to PERF-012
are observations, not timings: read them in the developer tools on one load. The number of API calls
per dashboard load is DASH-014.

| ID | P | Mode | Measure | Target |
|---|---|---|---|---|
| PERF-001 | P3 | R | API p95 for common reads (`/auth/me`, `/employees`, `/attendance/my`) | under 200 ms server time |
| PERF-002 | P3 | R | Dashboard page load to interactive | under 1.5 s |
| PERF-003 | P3 | R | Employee list, 1,000 rows (only if QA Audit has them) | under 500 ms |
| PERF-004 | P3 | R | Employee search | under 100 ms server time |
| PERF-005 | P3 | R | Payroll run of the QA employees, from submit to completed | under 30 s of processing once picked up from the queue (queue wait is separate; record both) |
| PERF-006 | P3 | R | Lighthouse on `/en` and on the dashboard (mobile) | Performance over 80, Accessibility over 90; FCP under 1.5 s, LCP under 2.5 s, CLS under 0.1 |
| PERF-007 | P3 | R | Offline sync of 100 records | under 5 s |
| PERF-008 | P3 | R | No Sentry SDK without a DSN (#487): `/en` and `/dashboard`, developer tools Search (Ctrl+Shift+F) across loaded sources for `sentry.javascript` | Not found, and no request to a Sentry host. If the owner has set a DSN for this release, record that instead |
| PERF-009 | P3 | R | Public pages carry no Amharic dictionary (#491): signed out, `/en`, `/en/pricing` and `/en/no-such-page`; Search across loaded sources for `የሂሳብ ኮድ` (a dashboard-only Amharic string) | Not found on any of the three. The 404 page still reads in the page's language, and `/am/no-such-page` in Amharic |
| PERF-010 | P3 | R | The dashboard loads only its reader's dictionary (#500): EMP in English, hard reload `/dashboard`, the same search; then switch the header to አማርኛ | English: not found. After the switch one script containing it loads, and the sidebar and page are in Amharic with no English left over |
| PERF-011 | P3 | R | Charts fetched only for those shown them (#518): EMP, then HR, on `/dashboard` at 1280 px; Search across loaded sources for `recharts-wrapper`; HR then at 375 px before and after scrolling to the charts | EMP: never found. HR: found once the chart area is near the screen; at 375 px only after scrolling down, behind a skeleton of the same height |
| PERF-012 | P3 | R | Pre-compressed scripts (#538): take one `/_next/static/…/*.js` and one `.css` address from the page source; `curl -sI -H 'Accept-Encoding: br' <address>`, then the same without the header | With `br`: `Content-Encoding: br`, `Vary` naming `Accept-Encoding`, `Cache-Control` with `public, immutable`. Without it: not `br`, same caching |

## 5. Accessibility (WCAG 2.1 AA)

Phone tap targets on the dashboard (44 px) are DASH-012.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| A11Y-001 | P2 | R | Automated scan | axe DevTools (or the `test:e2e:a11y` spec pointed at production) on sign-in, dashboard, employees, attendance, leave, payroll run, settings, kiosk, marketing home | No critical or serious violation |
| A11Y-002 | P2 | R | Keyboard only | Complete sign-in, leave request and check-in with the keyboard | Every control reachable in a sensible order; focus visible; dialogs trap focus and close with Escape; Enter submits dialogs |
| A11Y-003 | P2 | R | Screen reader | NVDA or VoiceOver on sign-in and the leave form | Fields have labels; errors are announced |
| A11Y-004 | P2 | R | Contrast | Light, dark and high-contrast themes | Text and controls meet AA contrast |
| A11Y-005 | P2 | R | Zoom | Browser zoom 200 percent | Content usable, no overlap |
| A11Y-006 | P2 | R | Sidebar footer contrast and names | axe or a contrast checker on the footer in light, dark and high-contrast; accessibility tree of both controls | Label at least 4.5:1 (designed 7.0:1 on navy, 7.8:1 on dark). The collapsed mark and the toggle each have an `aria-label`; the toggle has `aria-keyshortcuts="Control+B Meta+B"`; neither uses a `title` tooltip |
| A11Y-007 | P2 | R | Skip link lands in the page (#588) | TA: reload `/dashboard`, press Tab, then Enter, then Tab; repeat in አማርኛ | Tab shows "Skip to main content" («ወደ ዋና ይዘት ዝለል») visibly. After Enter, the next Tab focuses the page's first action ("Add Employee"), not the sidebar's first item |
| A11Y-008 | P3 | R | 12 px floor on the dashboard (#588) | Inspect the computed font size of badges, calendar weekday headers, leave balance footers, announcement and activity timestamps, the approvals badge and team tile percentages | 12 px or more each |

## 6. Localisation and the Ethiopian calendar

The rules: the Ethiopian clock counts from dawn and dusk (06:00 is 12:00 ጠዋት, 12:00 is 6:00 ቀን,
18:00 is 12:00 ምሽት, 00:00 is 6:00 ሌሊት; in Afaan Oromoo the periods are ganama, waaree booda,
galgala, halkan). A person's clock is their own choice, else the organisation's, else "Automatic",
which is Ethiopian time for an Amharic or Oromo reader on the Ethiopian calendar and 24-hour for
anyone else. It changes only what is shown and typed: the API carries UTC and 24-hour times
throughout. "This month" on dashboards is 08's DASH-007.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| L10N-001 | P2 | R | Full Amharic | Switch to አማርኛ; visit every page in the sidebar for TA | No English left, no raw keys (`leave.min_notice` style), no `?` boxes for missing glyphs. Note pages that fail |
| L10N-002 | P2 | R | No clipping | Amharic on buttons, table headers, sidebar, mobile bottom bar | Not cut off; line height comfortable |
| L10N-003 | P2 | R | Messages | Trigger validation errors and API refusals in Amharic | Shown in Amharic (server messages too where translated); note any English |
| L10N-004 | P2 | R | Ethiopian dates | Calendar Ethiopian: attendance list, leave dates, payroll period, holidays | Correct Ethiopian dates. Example: 2026-10-24 is 14 Tikimt 2019; 2026-09-11 is 1 Meskerem 2019 |
| L10N-005 | P2 | R | Pagume | Date pickers around early September | Pagume shows 5 days, or 6 in an Ethiopian leap year (Pagume 2019 EC, 6 to 11 September 2027, has 6 days, so Meskerem 1, 2020 falls on 12 September 2027) |
| L10N-006 | P2 | R | Time zone | Every time shown | EAT (UTC+3); a record at 09:00 EAT shows 09:00 (or 3:00 ጠዋት on the Ethiopian clock), never 06:00 |
| L10N-007 | P2 | R | Currency format | Amounts in the interface and exports | `X,XXX.XX ETB`; no floating-point artefacts (for example 1,349.9999) |
| L10N-008 | P2 | R | Amharic in files (#520) | PDFs (payslip, letters, statutory schedules, reports) and CSV exports opened in a spreadsheet, downloaded by a reader in Amharic on the Ethiopian calendar | Amharic renders, and ብር in amounts, never `??`. Payslip and report PDFs follow the downloader's language, calendar and clock (07 owns the payslip itself); a letter's Ethiopian date is followed by the Gregorian one. A CSV keeps the ISO date and adds a column ":label (Ethiopian calendar)" beside it, and opens as UTF-8 |
| L10N-009 | P3 | R | Other languages (#546) | The language switcher on the sign-in page, the public site and an organisation's portal; then Profile → Preferences and the header's language menu | Switchers: English, አማርኛ and Afaan Oromoo can be selected; ትግርኛ (Tigrinya), Soomaali (Somali) and Sidaamu Afoo (Sidama) are listed as "Coming soon" and cannot be selected. Preferences and the header menu list only the three that can be selected |
| L10N-010 | P2 | R | Full Afaan Oromoo (#546, #549) | Switch to Afaan Oromoo; visit every page in the sidebar for TA and the self-service pages for EMP | No English left and no raw keys; page titles in Oromo ("Hojjetaa Dabali" on the add-employee page); Ethiopian months by their Oromo names (2026-10-24 falls in Onkololeessa 2019). Note pages that fail |
| L10N-011 | P2 | R | Server messages in Oromo (#546) | Interface in Afaan Oromoo: change one character of a record's ULID in the address; leave a required field empty on any form. API: `POST /api/v1/auth/password/forgot` with `{}`, `Accept: application/json` and `Accept-Language: om` | "Odeeffannoon gaafatame hin argamne."; the field error in Oromo (":attribute barbaachisaadha." form); the API's `errors.email` in Oromo. Any English is a defect, quoted in the report |
| L10N-012 | P2 | R | Public site and plans in Oromo (#546, #552) | Signed out: `https://www.ethr.et/om`, `/om/features`, `/om/pricing`, `/om/contact`; view the source of `/om`; read `/sitemap.xml` | Every section, the footer and the contact details in Afaan Oromoo; each plan's summary and selling points in Oromo. The page names `om_ET` as its `og:locale`; the sitemap lists the `/om` pages with `hreflang` alternates for `en`, `am` and `om` |
| L10N-013 | P2 | W | An organisation's portal in Oromo (#553) | TA in QA Audit: Settings → Public website, write the about text, one service and one question in Afaan Oromoo only, and one service in English only; open `ethr.et/qa-audit` signed out in Afaan Oromoo, then in English | Oromo: the Oromo text, and the English-only service in English. English: the English service; the Oromo-only items still shown in Oromo rather than dropped. Section headings and the sign-in panel in the page's language |
| L10N-014 | P2 | R | One Amharic word for each thing, no raw keys (#513) | HR in አማርኛ: Payroll → a run → reconciliation; open the approve and void dialogs and cancel; a device with no webhook address; Settings → Accounting run picker; Billing invoices; Settings hub. Employee → History: an acting assignment with no end date; a step of 101 | Attendance is «መገኘት» throughout (never «ታዳሚ»); a payroll run is «ሂደት» in the sidebar, dialogs, reconciliation and API errors; kiosk PIN is «ፒን»; the settings card is «ይፋዊ ድረ ገጽ». History errors read «የውክልና ምደባ፣ ውክልና እና የውሰት ምደባ የማብቂያ ቀን ያስፈልጋቸዋል።» and «ከ1 እስከ 100 ያለ እርከን ያስገቡ።», never `employee.history.end_date_required`. No English `sent`, run status or `N/A` |
| L10N-015 | P2 | R | A chosen language follows the person; every title translated (#485) | EMP on browser A chooses Afaan Oromoo in the header. Sign in as EMP on browser B (a private window). Sign in as EMP3, who never chose. Then HR in አማርኛ: open the add-employee, Phones, Rotations, Opening balances and Personal details pages, and find them in the Ctrl+K palette | Browser B opens in Afaan Oromoo after sign-in; EMP3 gets the organisation's default. Header and palette titles in Amharic: «ሰራተኛ ጨምር», «ስልኮች», «ዙር ፈረቃዎች» and the rest; no English title. A person who chose before this release is followed only after choosing again |
| L10N-016 | P2 | R | A language switch refetches server text (#478) | HR in English with Approvals open (summaries that name months) and the bell open; switch the header to አማርኛ, then Afaan Oromoo | Summaries and notice titles change language in place, without a reload; the network tab shows them fetched again with the new `Accept-Language` |
| L10N-017 | P2 | R | One menu for language, calendar and time of day (#484, #539) | EMP at 1280 px and 375 px: open the globe in the header; choose each language, calendar and clock in turn; read the accessibility tree | One menu, its button reading "English · Ethiopian" (icon only on a phone), named "Language, calendar and time of day" («ቋንቋ፣ የቀን መቁጠሪያ እና የሰዓት አቆጣጠር»). Three radio groups: Language, Calendar (each calendar showing today's date) and Time of day ("Automatic (07:30)" for an English reader, "Ethiopian time (1:30 morning)", "24-hour (07:30)"; in Amharic on the Ethiopian calendar «ራስ-ሰር (1:30 ጠዋት)»). Each applies at once, is announced as checked, and Profile → Preferences shows the same choice. No separate "ኢ.ቀ" or "GC" button |
| L10N-018 | P2 | W | Time of day as a preference (#508) | EMP: Profile → Preferences → "Time of day", each choice. TA: Settings → General → "Time of day" set to 24-hour; EMP3 (never chose) in Amharic on the Ethiopian calendar; then set it back | Preferences shows the hint "Automatic uses Ethiopian time when you read Amharic or Afaan Oromo on the Ethiopian calendar, and 24-hour otherwise." EMP's own choice wins over the organisation's; EMP3 follows the organisation's 24-hour setting, then Ethiopian time once it is back to Automatic |
| L10N-019 | P2 | R | Every screen follows the reader's calendar and clock (#512) | HR in አማርኛ on the Ethiopian calendar: an employee's detail and HR tabs, payslips, Analytics axes, devices, the team leave calendar; a date field (leave form, cost sharing "Started On"); a time field (a shift's start). Network tab on a save. Then signed out: the organisation's news and vacancies in አማርኛ and in English | Dates in Ethiopian, weekdays in Amharic, times on the Ethiopian clock. Date fields take Ethiopian year, month and day and refuse dates outside their limits; a time field takes hour, minute and ጠዋት / ቀን / ምሽት / ሌሊት and shows «በ24 ሰዓት: 07:30» beside it. The request still sends an ISO date and `07:30`. Signed-out pages: Amharic is the Ethiopian calendar and clock, English Gregorian and 24-hour. "Today" is the organisation's date, also between 00:00 and 03:00 |
| L10N-020 | P2 | X | Notices and emails follow the reader (#514) | EMP (Amharic, Ethiopian calendar, an address the team controls) and EMP2 (English, Gregorian): HR decides a leave request from each; read the bell and the emails. EMP then switches to English and Gregorian and reopens the bell | Dates in each reader's own calendar and language (for example «ጥቅምት 22, 2019», "Tikimt 22, 2019", "01 Nov 2026") and times in their clock. The same stored notice re-reads in EMP's new choice. Each email is in the recipient's chosen language, else the organisation's. Buttons in emails are translated |

## 7. Responsive layout, themes and browsers

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| UI-001 | P2 | R | Phone, 375 px | Dashboard, attendance mobile, leave, payslips, approvals | No horizontal scroll; bottom bar (Home, Attendance, Leave, Payslips or Approvals for approvers, More) |
| UI-002 | P2 | R | Tablet 768 px, desktop 1280 px and over | Same pages plus employees and payroll run | Sidebar on desktop; tables scroll inside their container, not the page |
| UI-003 | P2 | R | Themes | Light, dark, system, high contrast | Every page repaints; no unreadable text; choice persists |
| UI-004 | P2 | R | Four data states | For list pages: loading, empty, error (go offline mid-load), data | Skeleton while loading; an empty state with an action; an error with a retry; never a blank page |
| UI-005 | P2 | R | Unsaved changes | Edit a long form, navigate away | Warned |
| UI-006 | P2 | R | Browsers | Chrome, Edge, Firefox (latest), Safari on iPhone, Chrome on Android | Sign-in, check-in, leave request and payslip download work in each |
| UI-007 | P3 | R | Console clean | Developer tools console on the main pages | No errors |
| UI-008 | P2 | R | Shortcuts | Ctrl+K palette, Ctrl+B collapse sidebar | Work |
| UI-009 | P2 | R | Sidebar footer | QA Audit, desktop, sidebar expanded then collapsed (Ctrl+B or the icon), en and am; hover each footer control | One row: the ETHR logo tile (white E on brand blue, or the platform's uploaded logo) and "Powered by ETHR" / "በETHR የሚሰራ", opening the public site in a new tab, plus a square collapse icon with tooltip "Collapse sidebar" or "Expand sidebar". No "Command Palette Ctrl+K" or "Collapse Ctrl+B" row. On the rail the tile remains, "Powered by ETHR" as a tooltip. Ctrl+B (Cmd+B) still toggles |

## 8. PWA and offline

Cache names and entries are read in the developer tools: Application → Cache Storage, and
Application → Local Storage. The service worker's caches are named `ethr-v5-static` and `ethr-v5-api`.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PWA-001 | P2 | R | Installable (#469) | Chrome: install prompt or Add to Home screen; iPhone: Add to Home Screen | Installs with the ETHR icon and the name "ETHR: Ethiopian Workforce OS" (a colon, no long dash); `/manifest.json` and icons load (`/app-icons/icon-192.png` 200) |
| PWA-002 | P2 | R | Offline page (#466) | Installed app, airplane mode, open a page not cached; in English, then አማርኛ, then Afaan Oromoo | The offline page in the reader's language ("You are offline", «ከመስመር ውጪ ነዎት», "Toora ala jirtu") with "Try again", never "Something went wrong". After a release, the offline copy is renewed on an online visit once it is six hours old |
| PWA-003 | P2 | W | Offline attendance | OFF-002 | As stated |
| PWA-004 | P2 | R | Update after release | After a new release, reopen the installed app | The new version loads (no stale bundle); `release.json` matches |
| PWA-005 | P2 | R | Offline banner | Lose network while using the app | A banner says the app is offline; actions that need the network say so |
| PWA-006 | P2 | R | The installed app opens offline, signed in (#483) | EMP: install, sign in, open the app once online. Close it fully; airplane mode; open it from the home-screen icon and from each shortcut ("Check in or out", "My attendance", "Request leave", "My payslips"); wait five minutes | Opens on the dashboard (or the shortcut's page) as EMP, not on the offline page and not on sign-in; still signed in after five minutes. A punch made now is queued under EMP (PWA-003) |
| PWA-007 | P2 | R | The identity kept for offline is minimal (#483) | After PWA-006, read `ethr.offline-identity` in Local Storage; sign out; open the app offline again | It holds the name, role, permissions, employee code, language, calendar and the organisation's name, zone and colours; no email, phone, photo or token. After sign-out the key is gone and no one's dashboard opens offline |
| PWA-008 | P1 | R | Pay data never kept offline (#494) | HR on a desktop browser: open a payroll run, a report, an employee's bank details and contracts, and HR's own payslips; read Cache Storage. Then go offline and reopen the payroll run | Only `ethr-v5-…` caches (no `ethr-v4` or older). In `ethr-v5-api`: nothing under `/api/v1/payroll/` except `/api/v1/payroll/payslips/my`, nothing under `/api/v1/reports/`, `/api/v1/auth/` or `/api/v1/admin/`, and no path containing `/bank-details` or `/contracts`. Offline, the run is not shown from a copy. Any such entry is S1 |
| PWA-009 | P1 | R | A 401 empties the offline cache (#494) | EMP signed in, browse a few pages so `ethr-v5-api` has entries. Delete the `access_token` cookie (Application → Cookies) and reload | The app goes to sign-in; `ethr-v5-api` is gone or empty, and `ethr.offline-identity` is removed |
| PWA-010 | P2 | R | Offline copies expire after a week (#496) | Select any entry in `ethr-v5-api` and read its response headers. Optional: a device left signed in but unused for more than a week, opened offline first | Every entry carries `X-ETHR-Stored-At` (milliseconds). The week-old device shows no stored data for those pages (the offline answer instead), while punches queued on it still sync |
| PUSH-001 | P2 | R | Push configured | `GET /push/config` (any signed-in user) | `enabled: true` with a `public_key`. If false, VAPID keys are missing and `POST /push/subscriptions` answers 409 "Phone notifications are not set up on this server yet." |
| PUSH-002 | P2 | W | Subscribe on a phone | EMP: `/attendance/mobile` → "Even when ETHR is closed" on; allow notifications (iPhone: add to the Home Screen first). Block notifications and retry | 201; `GET /push/subscriptions` shows `devices: 1`. Blocked: "Notifications are blocked for ETHR. Allow them in your browser settings." |
| PUSH-003 | P2 | X | Every in-app notice reaches the phone | Close ETHR on EMP's phone; HR approves an EMP leave request (or SUP a correction); PA then reads the console's failed jobs | A push with the notification's own text (title from the notification or "ETHR"); tapping opens ETHR on the linked page. It may be a few minutes late (sent from the queue by the cron caller). No `SendWebPush` failed job, and no `TypeError`, which would mean the web-push 11 regression is back |
| PUSH-004 | P2 | W | Shared phone | EMP subscribes, signs out; EMP2 signs in on the same browser and subscribes; EMP unsubscribes from another device | The endpoint moves to EMP2 (one owner per endpoint). DELETE removes only the caller's own subscription |
| PUSH-005 | P2 | R | Content Security Policy for map and liveness | Read the CSP header on `/attendance/mobile` | Allows `'wasm-unsafe-eval'` (not `'unsafe-eval'`), `storage.googleapis.com`, `nominatim.openstreetmap.org`, `maps.googleapis.com` by name, nothing wider |
| PUSH-006 | P2 | W | Only a browser push service is an endpoint (#670) | From the network tab of PUSH-002, copy EMP's `POST /push/subscriptions`; resend it with `endpoint` changed to `https://example.com/push`, `https://fcm.googleapis.com:8443/x`, `http://fcm.googleapis.com/x` and `https://127.0.0.1/x`; then unchanged. Subscribe on each browser the pass uses (Chrome, Firefox, Safari on an iPhone, Edge) | Each changed one: 422 on `endpoint`, "The endpoint field must be a valid URL."; `GET /push/subscriptions` unchanged. Unchanged and every real browser: 201. Allowed are `fcm.googleapis.com`, `android.googleapis.com`, `updates.push.services.mozilla.com` and hosts under `push.apple.com` and `notify.windows.com`, over HTTPS on port 443. A subscription saved before #670 that points elsewhere is never sent to. Before #670 any `https://` address was accepted, and every notice made the server call it |

## 9. Resilience and operations (observe only)

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| OPS-001 | P2 | R | Health | `GET /api/v1/health` | `database`, `cache`, `queue`, `storage`, `database_read` healthy; `queue_detail` heartbeat under 900 s old, a recent `last_drain_at`, low queue depth, failed jobs count; `mail_detail` with last sent and TLS mode |
| OPS-002 | P2 | R | Scheduler running | Read `queue_detail` three times, 5 minutes apart | Heartbeat advances about every 5 minutes |
| OPS-003 | P2 | R | Queue drains | Trigger something queued (announcement to all) | Notifications arrive and queue depth returns to near zero within 10 minutes |
| OPS-004 | P3 | R | Logs grow sensibly | Ask the owner for the day's log size after the pass | No flood of errors caused by the test pass |
| OPS-005 | P3 | R | Upload limit as the host applies it (K-16) | `GET /api/v1/auth/me` as EMP; read `upload_limit_bytes` | Record the figure (2 MB, `2097152`, when last measured: the host's stock PHP limit). A larger file is refused with a message naming that limit, never a 500 (DOC-002) |
| OPS-006 | P2 | R | A scheduled task that fails says so (#669) | Owner or test lead: GitHub → Actions → the `cron` workflow; open a few runs of the `schedule` task, including the one after 01:00 UTC (the backup). Compare with 08's BAK-001 | A run whose response reads `"status":"ok"` is green. When any scheduled command exits non-zero (a backup that could not run, an off-host copy refused), `/cron/schedule` answers `"status":"failed"` with "failed: exit code N" in its `output`, and the run is red with "schedule ran and FAILED (HTTP 200, status=failed)." Before #669 such a failure answered "ok" and the run stayed green while the backup had not been taken: a green run beside a BAK-001 alarm is that defect back |
