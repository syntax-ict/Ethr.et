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
| LAND-006 | P2 | W | Showcase default and opt-out | TA: Settings → ETHR website ("Show our organisation on the ETHR website"): read; switch off; switch on | On by default. "Listed since :date." then "It is shown now.", "It is shown while your trial or subscription is active." or "ETHR is not showing it at the moment." (platform veto). Off: "Your organisation will no longer appear on the ETHR website.", gone from Trusted by at once, audit `settings.showcase_withdrawn`. On: "Thank you. Your organisation can now appear on the ETHR website.", audit `settings.showcase_consented`; listed-since resets to now (end of the list). Only the organisation switches this; the platform can only veto (CON-P-008) |
| LAND-007 | P1 | W | QA organisations kept off the public site | Before the pass, signed out: home "Trusted by", `/sitemap-organisations.xml`, `curl -I` on the entry pages of QA Audit, Syntax, QA Isolation. Then, with the owner's decision, TA in each: Settings → ETHR website off, and the landing's "List this page in search engines" off (or PA hides them, CON-P-008) | Before: every QA organisation in use is named and listed (the default since 2026-10-26, K-24). After: gone from "Trusted by" at once and from the sitemap (allow up to 1 hour of caching); entry pages answer `X-Robots-Tag: noindex, nofollow` with a matching robots meta tag |
| LAND-008 | P2 | X | Leaving use leaves search and Trusted by | With the owner: a listed `uat-` organisation, suspend it (CON-P-004), check the three places from LAND-007, reactivate | Suspended: gone from "Trusted by" at once, absent from the sitemap, pages `noindex, nofollow`. Reactivated: back in all three. An ended trial leaves the same way, but "Trusted by" may take up to 10 minutes (cached 600 s). Switch it off again afterwards (LAND-007) |
| LAND-005 | P2 | R | Search listing | View the organisation page source and `curl -I` its entry page | Canonical address, `hreflang` en, am and x-default, structured data. `<meta name="robots">` and `X-Robots-Tag` say `index, follow` while the organisation is in use (active, or a trial not yet ended) and "List this page in search engines" is on, which is the default; `noindex, nofollow` when it is off, or the organisation is suspended, cancelled or its trial has ended. The same rule decides the organisations sitemap (MKT-006) |

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
| DASH-001 | P1 | R | Employee and manager dashboards | EMP and SUP dashboards | Figures agree with the underlying pages (attendance today, leave balance, pending approvals); the Next steps card shows only that role's items (NXT-001, NXT-002) |
| DASH-002 | P1 | R | Executive dashboard | HR or FIN: executive, attendance, payroll, workforce, compliance, forecast | Loads; figures plausible against the data; SUP sees the regional (own branch) version only |
| DASH-003 | P2 | R | Dashboard rate | Reload the executive dashboard 31 times in a minute | 429 on the excess (30 a minute) |
| DASH-004 | P2 | X | Digest | Schedule a weekly digest to a team address | Saved; sent at 06:00 on schedule; a non-email recipient refused |
| DASH-005 | P2 | W | Alert threshold | Threshold "attendance rate today lt 90, critical" | Saved; listed under triggered when true |
| NXT-001 | P1 | R | Next steps: employee | `GET /next-steps` (each item checks its own permission, reach and plan; no single permission). EMP with no photo and no emergency contact opens the dashboard | "Next steps" card, "What needs you first, for the work you do here.": "Finish your profile", ":done of :total done. HR needs the rest to pay you and reach you.", listing photo, phone, date of birth, emergency contact (TIN and bank only on a plan with payroll). A field waiting for HR (PRO-002) counts as done. Nothing to do: no card |
| NXT-002 | P1 | R | Next steps: supervisor | SUP with one pending leave request and one direct report absent today | "1 request is waiting for your decision" → "Decide" opens `/approvals` (count equals Approvals); ":count of your team are absent today" opens `/attendance/team`. DA the same within reach |
| NXT-003 | P1 | R | Next steps: HR and finance | HR and FIN open the dashboard | HR: ":count employees have no TIN" / no bank account / no emergency contact / no phone number, each opening exactly the EMP-016 set; ":count contracts end within 30 days or have ended"; punches to review and unmatched badges where present. FIN: no records items; latest run completed: "The :period payroll run is waiting for approval"; failed: "The :period payroll run failed" |
| NXT-004 | P1 | R | Next steps: setup | TA on a fresh `uat-` organisation (ORG-004); PA calls `GET /next-steps` | Blockers first ("No administrator is set up" when relevant), then "Add your first branch", "Add your departments", "Finish the guided setup" with ":done of :total steps done." No item for a feature the plan lacks. PA: `steps: []`, no card |
| NXT-005 | P1 | W | Put away and bring back | HR "Put away" on a records item, reload, another device; fix one employee it counts; "Bring back put-away steps" | Hidden across reloads and devices (stored on the server); returns when its count changes; bring back restores every put-away item. Routes `POST /next-steps/{step}/dismiss`, `DELETE /next-steps/dismissals` |
| NXT-006 | P2 | R | Blockers can only be snoozed | A blocker shows "Remind me in 7 days". API: dismiss a blocker without `snooze_days`, with 8; a non-blocker with 31; step `nope` | "The snooze days field is required."; "The snooze days field must not be greater than 7."; 31: 422 (at most 30); unknown step 404. A snoozed blocker returns when the snooze ends |
| ANA-001 | P2 | R | Analytics | Analytics → departments and branches, open D1 | Figures agree with the employee list |
| REP-001 | P1 | R | Generate | HR: Reports → employees source, CSV; attendance source PDF | Files download; PDF A4 landscape; at most 1,000 rows |
| REP-002 | P1 | R | Payroll source rights | HR (no `payroll.viewAll`) generates the payroll source | Refused; FIN can |
| REP-003 | P2 | W | Save and schedule | Save a report; schedule it weekly to a team address (Enterprise) | Saved and scheduled; on Professional: 403 plan feature; deleting works on any plan |
| REP-004 | P2 | R | No reports for supervisors | SUP opens Reports | Not available (ACC-005) |
| REP-005 | P1 | R | Absences source | HR: Reports → source "Absences", no dates, then a 120-day span; DA runs it; run it in an organisation with no attendance | One row per missed workday (employee, code, department, branch, date), newest first. Default span: the 30 days ending yesterday, never today. A longer span is cut to the 92 days before yesterday. Leave, holidays and rest days excluded. DA sees D1 only. No attendance, no rows. At most 1,000 rows |

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
| AUD-003 | P3 | R | Audit rows read as words | TA (Enterprise or trial): Settings → audit logs, English and Amharic | Actions read as words ("Payroll approved", "Authenticator code accepted") with the code in small type beneath; the filter still matches the code. The entity reads as a word ("Attendance flag"). No organisation column in an organisation's own log |

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
| CON-P-008 | P1 | W | Showcase veto | Console → QA Audit → "On the ETHR website": read the status; switch "Allow on the ETHR website" off, check the home page; switch it on | Status: "Shown under Trusted by", "Shown once active", "Hidden by the platform" or "Opted out of being named". Off: gone from Trusted by at once, audit `admin.tenant.showcase_hidden`. On: back, `admin.tenant.showcase_unhidden`. The public list holds every organisation in use (active, or a trial not yet ended) that has not opted out and is not hidden, oldest listing first, at most 48 |
| CON-P-009 | P1 | X | Delete and restore | On a `uat-` organisation: (a) while active, look for Delete, then `POST /api/v1/admin/tenants/{publicId}/delete` with a reason; (b) cancel it, "Delete organisation", type 4 characters; (c) a reason of 5 or more; (d) Deleted filter → the organisation → Restore | (a) No Delete button; API 409 `tenant-not-cancelled`, "Cancel this organisation before deleting it." (b) "Delete :name?" names the address and asks only for a reason: **no typed address, no authenticator code** (since 2026-10-26/27, K-27). Under 5 characters the button stays disabled (API 422 "The reason field must be at least 5 characters."; over 500 refused). (c) ":name was deleted. Its records are kept and it can be restored."; soft-deleted, sessions ended, users cannot sign in, its address and sign-in page answer not found; audit `admin.tenant.deleted` with the reason; gone from the dashboard counts and Trusted by. A `code` or `confirm` field from an old client is ignored. (d) "Restore :name?", ":name was restored. It is still cancelled."; audit `admin.tenant.restored`. PA without MFA: buttons disabled, API 403 `mfa-required` |
| CON-P-010 | P2 | X | Backup | Back up a `uat-` organisation | Queued; PA notified when done |
| CON-P-011 | P1 | R | Console figures, health and failed jobs | Console home: KPI cards, Revenue Trend, System Health, failed jobs; compare with the tenant list and invoices | Total Tenants excludes deleted organisations. Under Active, ":count paying" counts active organisations whose latest subscription is active or past due with a price above zero. "Recurring revenue" sums those subscriptions' captured prices (free plans 0, trials excluded; K-28); beneath it "Paid this month: :amount". Revenue Trend: paid invoices for six months, or "No payments recorded in the last six months". System Health names services in the reader's language and omits a disabled one; "Memory per request" is against PHP's `memory_limit` (128 MB); "Free disk on the server" says it is not the hosting quota. Health agrees with `/api/v1/health`. Retry or dismiss a failed job only after reading its error; **never "retry all" on a payroll job** without the owner |
| CON-P-012 | P2 | W | Plans editor | Create a test plan, edit its bullets in English and Amharic, retire it | Shown on the pricing page while active; retiring hides it but keeps subscribers' prices. Currency must be 3 capital letters; up to 20 bullets per language |
| CON-P-013 | P1 | W | Marketing editor | Marketing: edit the hero text and put it back; in a section with room, add a test row, reorder, upload a picture to that row, delete it; try a seventh "problems" row | Public landing page follows each change. Seventh row: "The problems section holds at most 6 rows." A forbidden claim: "This text makes a claim the public site may not make…". **Never upload a picture to the hero or one of the six problems rows**: it replaces that animated story (MKT-010, MKT-011, K-29) |
| CON-P-014 | P2 | W | Platform settings | Bank account `1000 2345-678`; `abc` | First saved; second refused. A testimonial needs an author and a consent date not in the future |
| CON-P-015 | P2 | X | SMS console | Read gateways and the daily cap; only with the owner: send a test SMS | SMS can be switched on only after the primary gateway delivers a test; tests limited to 10 an hour; the send log holds no number or text |
| CON-P-016 | P2 | R | Platform audit | Console → audit; filter action `admin.tenant`; export CSV; switch to Amharic | Platform actions recorded with operator and target. An Organisation column gives the name (public id on hover), or "Platform". Actions read as words ("Organisation deleted", "Trial extended") with the stored code beneath, which the filter matches; entities read as words; Amharic labels in Amharic. The CSV has an Organisation column |
| CON-P-017 | P3 | R | API-only tools | `GET /admin/users/search?q=…` (2+ characters); statutory layouts | Work through the API; there is no console screen for them (known) |
| CON-P-018 | P1 | R | Platform sign-in lands on the console | PA signs in at `https://admin.ethr.et/login` with MFA, watching the address bar and network log. Then, as PA, `GET /api/v1/dashboard/executive` (copy an authenticated console request, change its path) | Arrives on `/admin`, never an organisation dashboard: no error toast, no request to `/api/v1/dashboard/*`, analytics or reports, no 500. The API: 422 `tenant-required`, "This page belongs to an organisation. A platform account has none: open the organisation from the console instead." The same for executive, analytics, reports, accounting, alert threshold, vacancy and voice reads |
| CON-P-019 | P2 | R | Console words and tab titles | Tenant list and QA Audit's detail in English and Amharic; move between console pages with the sidebar, watching the tab title | Organisation type and the service and queue names on System Health read as words in the interface language. Titles "Tenants · ETHR", "Operations · ETHR" and so on, correct after each client navigation, never "ETHR \| HR and Payroll Software for Ethiopia". A page with no title of its own reads "ETHR" |
| CON-P-020 | P2 | R | Product mark card | Console → Platform Settings → "Sidebar product mark". As TA, look for anything similar | "The ETHR mark at the foot of every organisation's sidebar. Organisations cannot change it. Changes are recorded in the audit log." "Show the mark" (on by default), "Wording", "Wording (Amharic)", "Link (https)", a "Preview" on the sidebar's surface. TA finds no such setting |
| CON-P-021 | P2 | R | Product mark refusals | Wording of 61 characters, save; Link `http://example.com`, save; check QA Audit's sidebar | 422 toasts: "The product mark label field must not be greater than 60 characters."; "The product mark url field must be a valid URL." No organisation's sidebar changes |
| CON-P-022 | P2 | X | Product mark change and revert | Only with the owner (it changes every customer's sidebar). English wording "Powered by ETHR QA", Amharic blank, save; reload QA Audit in both languages. "Show the mark" off, save, reload. Back on with wording and link blank, save | "Sidebar product mark updated"; QA Audit shows it within about a minute or on reload; Amharic falls back to the English. Off: the mark gone, expanded and on the rail; preview "No mark. Organisations see only the collapse button here." Back on with blanks: "Powered by ETHR" / "በETHR የሚሰራ" linking to the public site. Each save audited `platform.settings.updated` |
| CON-P-023 | P2 | R | Text invitations switch | Console → SMS: read the invitations settings. Change nothing: SMS is not live | "Send invitations by text" off, reading "Off: people with only a phone are activated in person, with a code HR gives them." "Share of the daily limit for invitations (%)" 30, with ":sent invitations sent today. Sign-in codes keep the rest of the limit." Switching on, with the owner once SMS is live, is a separate pass |

## 9a. Operations centre (PA)

`GET /api/v1/admin/operations` behind `admin.manage`, page `/admin/operations`. Phase 1 is read-only.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| OPC-001 | P1 | R | Where it is and who sees it | PA: console home, then the sidebar's "Operations". TA on QA Audit's address opens `/admin/operations` and calls the API | Console home has an "Operations" card with 5 tiles (Background work, Email delivery, Release, Failed jobs, Services), each "Healthy", "Needs a look", "Action needed" or "Unknown", linking to its panel. "Open operations" opens the page; tab "Operations · ETHR". A PA without MFA can read it (unless strict mode, CON-P-001). TA: redirected to `admin.ethr.et`; API 404 (TEN-009) |
| OPC-002 | P1 | R | Summary and states | Read the summary; "Check again"; leave the page open 2 minutes | The badge shows the worst area; "Unknown" never outranks "Needs a look". "Nothing needs attention", "One area needs attention" or ":count areas need attention", "Checked :when". Chips only for areas that need a look or action, each jumping to its panel. Re-reads by itself every 60 s. No actions |
| OPC-003 | P1 | R | Background work | Read the rows and the queue table | "Scheduler last ran", "External cron caller last called", "Page traffic last ran background work", "Queue last drained". Queues `default`, `attendance`, `notifications`, `exports` with "Waiting jobs", "Oldest has waited" ("Nothing waiting"), "State". Thresholds: scheduler over 10 min needs a look, over 15 action; external caller over 30 min / 2 h; oldest job over 15 / 30 min. Never recorded = "Unknown", with "No call from the external cron caller has been recorded yet." With two callers every 5 min, Healthy. Quiet caller: "The external cron caller has gone quiet. Page views keep work moving during the day, but nothing runs overnight, including the backup." |
| OPC-004 | P1 | R | Release panel after a deployment | Read Release just after the release steps; compare with `/release.json` (S-01) | Commit = first 12 characters of `release.json`'s commit; Built = `built_at`; Environment `production`; Pending migrations 0; "Configuration cached" and "Routes cached" later than Built; Maintenance routes Closed; Cron token set Yes; Debug No; Queue not `sync`; Broadcast `null`; PHP limits `8.3.x · upload 2M · post 8M · memory 128M · time 30s`. Otherwise the reason names the step ("Database migrations are waiting. Run the release steps.", "The route cache is older than this release. Run optimize, or new pages will answer 404.", "The maintenance routes are open. Empty MAINTENANCE_TOKEN."): a true finding about the release, not a page defect |
| OPC-005 | P2 | R | Email delivery | Read the panel; again after any email the pass sends anyway (for example AUTH-020) | "Mailer" (`smtp`), "Last email sent", "Last email failed", "How the mail server was trusted" (`pinned` or `verified`). Healthy when the last send is later than the last failure; otherwise "Action needed", "The last email failed after the last one that was sent. Check the mail server and its certificate."; no record: "Unknown". "Last email sent" moves after the pass's email |
| OPC-006 | P2 | R | Failed jobs and services | Read both panels; "Review failed jobs" | Count and "Last job failed". Any failed job, however old: "Needs a look", "Some jobs failed. Retry or dismiss them on the console home." (never "Action needed" alone; K-26). The button opens `/admin`. Services `api`, `database`, `cache`, `storage`, `reverb` with timings; `reverb` `disabled` is not a fault (raw codes here, K-25). An `unhealthy` service: "Action needed", "A service check failed." Disk free in GB |
| OPC-007 | P1 | R | No secrets in the payload | Developer tools: responses of `GET /api/v1/admin/operations` and `GET /api/v1/admin/health` | No token or password value or length, no email address, phone number, SMTP error text, database host, schema or user. Cron token and maintenance as booleans only. A failed probe's `error` is exactly `unavailable` |
| OPC-008 | P1 | R | Health survives a release | Right after a release's clear and optimize steps, read Background work and Email delivery | "External cron caller last called", "Queue last drained" and "Last email sent" keep their times from before the release; none reads "never" because of the cache clear (#454) |
## 10. Public marketing site

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| MKT-001 | P1 | R | Pages | Signed out, open each in `/en/` and `/am/`: home, features, pricing, faq, contact, privacy, terms, payroll-software, attendance-system, leave-management, ethiopian-calendar-hr | Each 200, fully translated, no broken image, no raw translation key |
| MKT-002 | P1 | R | Language redirect | Open `https://www.ethr.et/` in a fresh browser | Redirects to a language (saved choice, then the cookie, then the browser language; default Amharic) |
| MKT-003 | P1 | R | Links | Click every in-site link on the home page | No dead link |
| MKT-004 | P1 | R | Contact form | Send name, email, message (10 to 5,000 characters) | 201 and a thank-you. Short message refused |
| MKT-005 | P1 | R | Pricing | Pricing page | Plans and prices from the catalogue (PLAN-013) |
| MKT-006 | P2 | R | SEO files | `/robots.txt`, `/sitemap.xml`, `/sitemap-organisations.xml` | robots allows `/`, blocks `/api/`, `/dashboard`, `/admin`, `/register`, `/forgot-password`, `/reset-password` (not `/login`), and names both sitemaps. The sitemap lists 11 pages × 2 languages with `hreflang` and x-default. The organisations sitemap: one entry per organisation in use (active, or a trial not yet ended) whose listing is on, at its canonical sign-in address with `?lang=en`, `?lang=am` and x-default; its news page and up to 50 newest public stories only when it has its own host and announcements are on. Suspended, cancelled, deleted, ended-trial and listing-off organisations absent. `max-age=3600` |
| MKT-007 | P2 | R | Trusted by | Home page "Trusted by"; compare with `GET /api/v1/trusted-by` | Every organisation in use that has not opted out (LAND-006) and is not hidden (CON-P-008), at most 48; logo (grey until hover) or name, linking to its sign-in page. No suspended, cancelled, deleted or ended-trial organisation. No section when the list is empty |
| MKT-008 | P2 | R | No unsupported claims | Read the home page copy | No claim an operator has not entered (for example certifications or customer counts not set in the console) |
| MKT-009 | P3 | R | Copy style | English and Amharic interface text | No em dash, no emoji, no sentence ending in an exclamation mark (house style, enforced for the interface; backend messages are not covered by that check) |
| MKT-010 | P2 | R | Animated problems rows | Home page, en and am, 1280 px: the problems section ("Today", "With ETHR"); press a story's round button, scroll away and back, press it again | Each of six rows: Today, the story, With ETHR. Each story loops every 12 s (6 s pain, 6 s answer) with a progress line. The button reads "Pause the animation", then "Play the animation". A paused story stays paused after scrolling; stories off screen do not move. A row with an uploaded picture shows it, still |
| MKT-011 | P2 | R | Animated hero | Home hero, en and am, 1280 px and 375 px | With no uploaded hero picture: a 16-second loop in 8 steps (phone check-in, wall reader, an offline punch that queues then uploads, payroll computed and approved, payslips in Latin and Ethiopic with the Ethiopian date), same progress line and pause. Nothing overlaps the heading or buttons at 375 px |
| MKT-012 | P1 | R | Reduced motion and no script | OS reduce-motion on (or devtools `prefers-reduced-motion: reduce`), reload; turn it on while a story plays; view source | Every story shows its finished frame (the answer; the hero's finished day), no progress line, no pause button, nothing moves. Turning it on mid-play stops on the finished frame. The source carries the finished frame. With motion allowed, the pause control works by keyboard (A11Y-002) |
