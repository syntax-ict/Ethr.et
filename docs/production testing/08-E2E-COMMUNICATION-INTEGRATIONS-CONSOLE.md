# E2E specification 08: communication, insight, integrations, platform console and marketing site

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Tenant cases run in
QA Audit; console cases on `https://admin.ethr.et` as PA against QA or `uat-` organisations only.

---

## 1. Announcements

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ANN-001 | P1 | X | Publish to everyone | HR: Announcements → new, title "QA notice", body, priority normal, audience all, category notice, publish now | Visible to every employee; each notified (queued, allow a few minutes) |
| ANN-002 | P1 | W | Department audience | Audience department D1 | EMP (D1) sees it; EMP2 (D2) does not, in the list **and** by opening its address directly |
| ANN-003 | P1 | R | Draft not readable | Save a draft (publish now off); EMP opens its address by id | Not found (a draft is never readable by staff; this was a fixed defect) |
| ANN-004 | P1 | W | Public on the landing page | Audience all, "show on landing" on | On the organisation's public sign-in page and `/news`. With audience department or branch, "show on landing" is refused |
| ANN-005 | P1 | W | Cover image | Upload a 1.4 MB JPEG 1600 px wide; a 600 px wide JPEG; a 3 MB image | First saves (narrower copies made); 600 px: "The cover photo must be at least 960 pixels wide."; 3 MB refused with the limit named |
| ANN-006 | P2 | W | Attachments | Attach PDFs: 3 allowed, a fourth; a `.docx` | Fourth: "A story can carry at most 3 documents."; non-PDF refused |
| ANN-007 | P2 | W | Edit and delete | Edit the title; delete | Updated; deleted (TA; delete is blocked while impersonating) |
| ANN-008 | P2 | R | Rights | EMP creates an announcement | Refused (`announcement.manage` is HR) |

## 2. Vacancies, news site and organisation landing page

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| VAC-001 | P1 | W | Public vacancy | HR: Vacancies → "QA Officer", full time, 2 positions, public, open, closes in 7 days, apply link `https://…` | On the public page while open. An `http://` apply link refused; an open vacancy with a past closing date refused; positions 0 or 1,000 refused |
| VAC-002 | P1 | W | Internal or draft | Internal or draft vacancy | Not on the public page; a direct public address gives 404 |
| VAC-003 | P2 | R | Closed by date | After the closing date | Gone from the public page by itself |
| NEWS-001 | P1 | R | News page | Signed out: `https://www.ethr.et/news`, and the organisation's news | Public stories only, 9 per page, featured first; search with `q` up to 100 characters. A non-public story by id: 404 |
| NEWS-002 | P2 | R | Story documents | Open a public story's PDF; unpublish the story; open the PDF link again | Opens; then refused |
| NEWS-003 | P2 | R | Caching | Publish a story and open the news page | May take up to 2 minutes to appear (120 s cache); not a defect |
| LAND-001 | P1 | W | Landing content | TA: Settings → landing: headline (80 max), message (280), about (600), founded year, background (required), up to 12 FAQs, 8 services, section order | Shown on the public sign-in page in the order set. A founded year after this year, a `http://` link, or 13 FAQs refused |
| LAND-002 | P1 | W | Branding | Colours `#0F4C75` and `#E8A838`; logo (under 1 MB); dark logo, mark, cover (cover at least 960 px wide); cover focal point | Applied to the sign-in page and app header. A colour like `blue` refused; a logo over 1 MB refused |
| LAND-003 | P1 | R | Feed limits | Public page with many items | Up to 5 announcements, 6 vacancies, 4 holidays in the next 120 days, 12 branches; each section only if switched on |
| LAND-004 | P1 | R | Shared template | Compare QA Audit's public page with another organisation's (view only) | Same layout and sign-in component; only branding and content differ |
| LAND-006 | P2 | W | Showcase consent | TA: Settings → showcase, opt in to being named on ETHR's public site; then opt out | Opt in: QA Audit can appear in "trusted by" (if active and the platform has not hidden it, CON-P-008). Opt out: removed. Only the organisation can give consent; the platform can only veto |
| LAND-005 | P2 | R | Search listing | View the organisation page source | Canonical address, `hreflang` en, am and x-default, structured data; `noindex` when the organisation's listing is off |

## 3. Notifications

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| NOT-001 | P1 | R | Bell and list | After LD-001, EMP: bell icon | Unread count; the notification; opening marks it read; "mark all read" clears the count |
| NOT-002 | P1 | W | Preferences | EMP: Notifications → Preferences: turn off email for leave decisions | Saved; in-app cannot be turned off; SMS offered only when SMS is set up, off by default. The next leave decision sends no email |
| NOT-003 | P1 | X | Failure notices cannot be muted | FIN mutes "payroll processed"; a run fails (only if one fails naturally; do not force it on production) | The failure notice still arrives by email |
| NOT-004 | P2 | W | Templates | TA: Settings → notification templates: edit subject (200 max) and body (2,000 max) with a valid variable; then an unknown `{foo}` | Saved; unknown variable: "This template cannot use {foo}. Available variables: …" |
| NOT-005 | P2 | X | Email content | Read a leave, password reset and invitation email | Organisation name, correct link to the organisation's own address, no broken template text, Amharic where the user's language is Amharic |

## 4. Employee voice

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| VOI-001 | P1 | W | Weekly pulse | EMP answers score 4, two tags, comment | Thanked. A second answer this week: "You have already answered this week. Thank you - the next pulse opens on Monday." Score 0 or 6, or 6 tags refused |
| VOI-002 | P1 | R | Insights privacy | HR: Voice → insights | 12 weeks; a group with fewer than 5 answers shows no average |
| VOI-003 | P1 | W | Anonymous concern | EMP raises a concern, anonymous on | A tracking code shown once, form `XXXX-XXXX-XXXX-XXXX`, no 0, 1, I, L or O |
| VOI-004 | P1 | R | Anonymity holds | HR opens the concern | No name, email or employee id of EMP anywhere on the page or in the API response |
| VOI-005 | P1 | W | Track and reply | EMP tracks with the code, replies; HR replies; EMP tracks again | Conversation in order. Wrong code: 404. Empty reply: "Write a message first." |
| VOI-006 | P2 | W | Named concern | Anonymous off | HR sees the author; EMP sees it under "mine" |
| VOI-007 | P2 | W | Resolve | HR resolves without, then with, a resolution | Resolution required |
| VOI-008 | P2 | W | Recognition | EMP recognises EMP2; EMP recognises EMP | Posted; self: "Recognise a colleague - you cannot recognise yourself." |

## 5. Search, dashboards, analytics, reports

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SRCH-001 | P1 | R | Palette | Ctrl+K (Cmd+K on Mac), type 2 characters of a name | Results grouped, up to 5 per group; 1 character or over 100 refused |
| SRCH-002 | P1 | R | Permission-aware | Same search as EMP, SUP, HR | EMP: no employee results; SUP: only reachable people; HR: all. Departments group needs `org.create` |
| SRCH-003 | P1 | R | Own organisation only | Search a QA Isolation employee's name | Nothing |
| SRCH-004 | P2 | R | Navigation | Type a page name ("payroll") | Offers the page if the user may open it; selecting navigates |
| DASH-001 | P1 | R | Employee and manager dashboards | EMP and SUP dashboards | Figures agree with the underlying pages (attendance today, leave balance, pending approvals) |
| DASH-002 | P1 | R | Executive dashboard | HR or FIN: executive, attendance, payroll, workforce, compliance, forecast | Loads; figures plausible against the data; SUP sees the regional (own branch) version only |
| DASH-003 | P2 | R | Dashboard rate | Reload the executive dashboard 31 times in a minute | 429 on the excess (30 a minute) |
| DASH-004 | P2 | X | Digest | Schedule a weekly digest to a team address | Saved; sent at 06:00 on schedule; a non-email recipient refused |
| DASH-005 | P2 | W | Alert threshold | Threshold "attendance rate today lt 90, critical" | Saved; listed under triggered when true |
| ANA-001 | P2 | R | Analytics | Analytics → departments and branches, open D1 | Figures agree with the employee list |
| REP-001 | P1 | R | Generate | HR: Reports → employees source, CSV; attendance source PDF | Files download; PDF A4 landscape; at most 1,000 rows |
| REP-002 | P1 | R | Payroll source rights | HR (no `payroll.viewAll`) generates the payroll source | Refused; FIN can |
| REP-003 | P2 | W | Save and schedule | Save a report; schedule it weekly to a team address (Enterprise) | Saved and scheduled; on Professional: 403 plan feature; deleting works on any plan |
| REP-004 | P2 | R | No reports for supervisors | SUP opens Reports | Not available (ACC-005) |

## 6. API keys

Needs the `api_access` plan feature (Enterprise, or trial).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| KEY-001 | P1 | W | Create | TA: Settings → API keys → abilities `read` + `employees`, expiry next month | The key (`ethr_` + 40 characters) shown once only; a past expiry refused |
| KEY-002 | P1 | R | Read with the key | `curl -H "Authorization: Bearer <key>" https://<org address>/api/v1/employees` | 200, QA Audit employees only |
| KEY-003 | P1 | R | Ability enforced | With that key: `POST /employees`; `GET /payroll/runs` | 403 `api-key-ability`, "This API key needs one of these abilities: …" |
| KEY-004 | P1 | R | Forbidden areas | With a key holding every ability: `GET /users`, `/api-keys`, `/webhooks`, `/billing/dashboard`, `/roles`, `/admin/tenants`, `/auth/me` | All refused |
| KEY-005 | P1 | W | Revoke | Revoke the key; call again | 401 |
| KEY-006 | P2 | R | Reference | Settings → API keys → API reference | Every operation with the abilities it needs; examples use the organisation's own address |
| KEY-007 | P2 | R | Not while impersonating | PA impersonating creates a key | 403 (IMP-004) |
| KEY-008 | P2 | R | Key from another organisation | QA Isolation's key against QA Audit's address | Refused (no data) |

## 7. Webhooks

Needs the `webhooks` plan feature. Use a receiver the test team controls (a request-inspection
service the owner approves). Every delivery is an outside call (X).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| WH-001 | P1 | X | Create | TA: Settings → webhooks, the receiver URL, events `leave.approved`, `attendance.checked_in` | A 32-character secret shown once. Internal or private addresses (`http://127.0.0.1`, `http://10.0.0.1`, `http://localhost`) refused |
| WH-002 | P1 | X | Events available | The event list | 12: employee.created, employee.updated, leave.requested, leave.approved, leave.rejected, payroll.processed, payroll.approved, payroll.voided, payroll.reprocessed, attendance.checked_in, attendance.checked_out, attendance.corrected |
| WH-003 | P1 | X | Delivery and signature | Approve a leave | The receiver gets a POST with `X-ETHR-Event`, `X-ETHR-Delivery`, `X-ETHR-Timestamp`, `X-ETHR-Signature` (`sha256=` HMAC of the body with the secret) and `X-ETHR-Signature-V2` (HMAC of `"{timestamp}.{body}"`), `User-Agent: ETHR-Webhooks/1.0`. Recompute both signatures: they match. The body has public ids only, no numeric ids |
| WH-004 | P1 | X | Test button | Send test | Delivered; more than 10 tests an hour: 429 |
| WH-005 | P2 | X | Retries | Receiver answers 500 | Retried at about 1 min, 5 min, 30 min, 2 h, 24 h (6 tries); a 400 is not retried; redirects are not followed. Deliveries list shows each attempt |
| WH-006 | P2 | X | Auto-disable | 10 failed deliveries in a row | Webhook disabled |
| WH-007 | P2 | R | Downgrade | After a downgrade, list and delete the webhook | Allowed; creating or editing refused |

## 8. SCIM, SSO settings, data export, audit log

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SCIM-001 | P2 | W | Token | TA: Settings → SCIM → generate token | Shown once; valid 1 year |
| SCIM-002 | P2 | W | Users | `GET /scim/v2/Users?filter=userName eq "…"`; create a user; create the same again; delete | List with `startIndex` 1, `count` up to 100; 201; 409 uniqueness; delete makes the user inactive, revokes sessions and suspends the employee. Unsupported filter: 400 `invalidFilter` |
| SCIM-003 | P2 | R | Wrong token | Any SCIM call with an API key lacking the `scim` ability | 401 |
| EXP-001 | P2 | X | Data export | TA: Settings → data export | One export at a time (409 while one runs); download when ready (409 before, 410 after 7 days); more than 3 in 24 h: 429. Contents only QA Audit's data |
| AUD-001 | P1 | R | Audit log | TA (Enterprise or trial): Settings → audit logs, filter by action `payroll`, by user, by date | 50 per page; entries for payroll approve and void, role changes, MFA resets, photo views, statutory downloads, impersonation. Entries cannot be edited or deleted (no control offered) |
| AUD-002 | P2 | R | Plan gate | Professional plan | 403 plan feature; the records are still being written |

## 9. Platform console (PA)

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| CON-P-001 | P1 | R | MFA for changes | PA without MFA tries any change | 403 `mfa-required`, "…must be enabled on your account before you can make changes… Set it up under Profile > Security." Reads allowed unless strict mode is on |
| CON-P-002 | P1 | R | Tenant list | Tenants: search by name and slug; filter active, suspended, cancelled, deleted | Correct results |
| CON-P-003 | P1 | R | Tenant detail | Open QA Audit | Plan, status, trial end, address mode, domain, invoices, showcase setting |
| CON-P-004 | P1 | W | Status | Suspend and reactivate a `uat-` organisation | TEN-006 behaviour while suspended; normal after |
| CON-P-005 | P1 | W | Extend trial | +30 days on a `uat-` organisation; 0 and 181 days | Trial end moves 30 days; 0 and 181 refused |
| CON-P-006 | P2 | X | Custom domain | Assign a domain the team controls to a `uat-` organisation on a plan with the add-on; verify before and after adding TXT `_ethr-verification.<domain>` = `ethr-verification=<token>` and the CNAME | Pending until both records resolve; then verified and links use it. Without the add-on: refused |
| CON-P-007 | P2 | W | Address mode | Switch a `uat-` organisation between path and subdomain address | Links (emails, entry page) follow; the old form redirects to the canonical one |
| CON-P-008 | P1 | W | Showcase | Hide or show QA Audit in "trusted by" | The public list follows (only organisations that opted in, are active and not hidden) |
| CON-P-009 | P1 | X | Delete and restore | On a `uat-` organisation: delete while active; cancel; delete with a wrong typed address; wrong MFA; right everything with a reason | 409 "Cancel this organisation before deleting it."; 422 "Type the organisation's address exactly as shown to confirm."; 422 `invalid-mfa-code` (audited); then soft-deleted, sessions ended, users cannot sign in. Restore: back, still cancelled |
| CON-P-010 | P2 | X | Backup | Back up a `uat-` organisation | Queued; PA notified when done |
| CON-P-011 | P1 | R | Health and failed jobs | Console dashboard: health, revenue, failed jobs | Agree with `/api/v1/health`. Retry or dismiss a failed job only after reading its error; **never "retry all" on a payroll job** without the owner (it can duplicate entries) |
| CON-P-012 | P2 | W | Plans editor | Create a test plan, edit its bullets in English and Amharic, retire it | Shown on the pricing page while active; retiring hides it but keeps subscribers' prices. Currency must be 3 capital letters; up to 20 bullets per language |
| CON-P-013 | P1 | W | Marketing editor | Marketing: edit the hero text, add a row to "problems" (max 6), reorder, upload a row picture, delete the row | Public landing page follows each change. A text making a forbidden claim: "This text makes a claim the public site may not make…" |
| CON-P-014 | P2 | W | Platform settings | Bank account `1000 2345-678`; `abc` | First saved; second refused. A testimonial needs an author and a consent date not in the future |
| CON-P-015 | P2 | X | SMS console | Read gateways and the daily cap; only with the owner: send a test SMS | SMS can be switched on only after the primary gateway delivers a test; tests limited to 10 an hour; the send log holds no number or text |
| CON-P-016 | P2 | R | Platform audit | Console → audit | Platform actions above recorded, with operator and target |
| CON-P-017 | P3 | R | API-only tools | `GET /admin/users/search?q=…` (2+ characters); statutory layouts | Work through the API; there is no console screen for them (known) |

## 10. Public marketing site

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| MKT-001 | P1 | R | Pages | Signed out, open each in `/en/` and `/am/`: home, features, pricing, faq, contact, privacy, terms, payroll-software, attendance-system, leave-management, ethiopian-calendar-hr | Each 200, fully translated, no broken image, no raw translation key |
| MKT-002 | P1 | R | Language redirect | Open `https://www.ethr.et/` in a fresh browser | Redirects to a language (saved choice, then the cookie, then the browser language; default Amharic) |
| MKT-003 | P1 | R | Links | Click every in-site link on the home page | No dead link |
| MKT-004 | P1 | R | Contact form | Send name, email, message (10 to 5,000 characters) | 201 and a thank-you. Short message refused |
| MKT-005 | P1 | R | Pricing | Pricing page | Plans and prices from the catalogue (PLAN-013) |
| MKT-006 | P2 | R | SEO files | `/robots.txt`, `/sitemap.xml`, `/sitemap-organisations.xml` | robots blocks `/api/`, `/dashboard`, `/admin`, `/register` and the reset pages; sitemap lists 11 pages × 2 languages with `hreflang` and x-default |
| MKT-007 | P2 | R | Trusted by | Home page "trusted by" | Only organisations that opted in (CON-P-008) |
| MKT-008 | P2 | R | No unsupported claims | Read the home page copy | No claim an operator has not entered (for example certifications or customer counts not set in the console) |
| MKT-009 | P3 | R | Copy style | English and Amharic interface text | No em dash, no emoji, no sentence ending in an exclamation mark (house style, enforced for the interface; backend messages are not covered by that check) |
