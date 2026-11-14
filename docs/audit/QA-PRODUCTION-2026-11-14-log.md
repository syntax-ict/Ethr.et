# Production QA execution log, 2026-11-14

Pack: [`docs/production testing/`](<../production testing/README.md>), sixth edition (this branch). Format: [10, section 2](<../production testing/10-TEMPLATES-AND-REFERENCE.md#2-execution-log>).

| | |
|---|---|
| Release under test | `v2026.11.14.3`, `release.json` commit `d591fb2ad33c` (#723), built 2026-11-14 05:23:47Z. `production` carries `v2026.11.14.4` (#724, docs only), not deployed and not needed |
| Organisation | Public pages of QA Audit (`qa-audit`), Syntax Technology (`syntaxtech`) and the marketing site; then QA Audit signed in, writes agreed by the owner |
| Persona | TA in QA Audit, from about 13:25 (signed in by the owner). Before that none: no saved sessions under `src/e2e-production/.auth/`. In Chrome, checked by reading only: `qa-audit.ethr.et/login`, `www.ethr.et/login` and `admin.ethr.et` (13:25) each showed their sign-in form, so no QA Audit, apex or platform session was held. Other organisations' hosts were not checked. This is the owner's everyday Chrome, used today for Plesk; it is not the dedicated ETHR QA profile of 11, section 6.1, which is why only public pages were read in it |
| Browser | Scripted: guarded Playwright (`playwright.production.config.ts`, `PW_CHANNEL=chrome`, service workers blocked). Agent: Chrome, unguarded, signed out, public pages only. A single `curl` GET or HEAD where a case asks for one |
| Tester | Playwright and Claude Code; to be signed by the owner |

Times: the scripted smoke's are the script's own; the other rows' times were noted after the fact and are approximate (within about ten minutes).

Until the owner agreed to writes (see the QA Audit section), read-only. Nothing was written to production in that part: no POST, no form submitted, no secret path
probed (S-05, SEC-004 and SEC-006 are left to a person, 01 rule 10).

**Coverage before this pass.** `scripts/qa-pack-coverage.sh d591fb2ad33c` listed 13 user-visible PRs
(#707 to #723) cited nowhere in the fifth edition, and `02-UAT-GUIDE.md` had not changed since
2026-10-29. The sixth edition on this branch writes cases for all 13 and brings 02 up to date. Rows
marked "new" below were run for the first time today.

## Release smoke (10, section 3)

The first scripted run (12:26) failed to launch: the bundled Playwright browser is not installed on
this machine, so nothing was sent. The rows below are the re-run on the installed Chrome.

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| S-01 | Pass | Playwright | 12:27 | | en | live commit `d591fb2ad33ca5a046f1485b1672ebd78b284efa` = `EXPECTED_COMMIT`, built 2026-11-14T05:23:47Z |
| S-02 | Pass | Playwright | 12:27 | | en | healthy, schema current, scheduler running. Failed-jobs growth not compared (one reading) |
| S-03 | Not run | | | | | No script; a new route is checked by hand |
| S-04 | Pass | Playwright | 12:27 | | en | redirects |
| S-05 | Not run | | | | | Secret probes are a person's (01 rule 10); excluded with `--grep-invert` |
| S-06 | Pass | Playwright | 12:27 | | en | `/en`, `/am`, `/en/pricing`. Prices not checked by eye |
| S-07 | Pass | Playwright | 12:27 | | en | QA Audit sign-in page. Branding not checked by eye |
| S-08 to S-11, S-13 | Not run | Playwright | 12:27 | TA, HR, FIN, SUP, EMP, PA | en | No saved session (11, section 3) |

## Public pages and headers

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| SEC-001 | Pass | Claude Code | 12:50 | | | `http://www.ethr.et/` and `http://admin.ethr.et/` answer 301 to `https://`. Certificates on organisation subdomains not inspected |
| SEC-002 | Pass | Claude Code | 12:50 | | | `curl -I /api/v1/ping`: `X-Frame-Options: DENY`, `nosniff`, HSTS one year with `includeSubDomains`, CSP with `frame-ancestors 'none'`, Referrer-Policy, Permissions-Policy. The host adds a second, shorter HSTS header (`max-age=15768000`) after the app's; noted, outside the release |
| SEC-003 | Pass | Claude Code | 12:50 | | | `/en`, `/features` and `/` carry CSP, HSTS, `X-Frame-Options: DENY`, `nosniff` |
| SEC-005 | Pass (404 only) | Claude Code | 12:51 | | | Unknown API route: 404 `application/problem+json`, no stack trace or path. The 422 half needs a POST and was left to a person |
| SEC-007 | Pass (signed out) | Claude Code | 12:51 | | | `/api/docs`: 403 `application/problem+json`. Signed-in half not run |
| SEC-008 | Pass (headers only) | Claude Code | 13:15 | | | `/login` sends `X-Frame-Options: DENY` and `frame-ancestors 'none'`. The iframe itself was not loaded |
| SEC-021 | Pass (one route) | Claude Code | 12:51 | | | `/api/v1/employees` with `X-Tenant: qa-audit` and no `Accept`: 401 problem+json "You are not authenticated." The other routes were not repeated |
| MKT-001 | Pass (served HTML) | Claude Code | 12:58 | | en, am, om | Read as 36 sequential `curl` GETs, 1.5 s apart (one per page, no retries), not opened in a browser. All 33 pages (11 × 3) answer 200 with `<html lang>` matching the address and no raw translation key in the served text; `/ti`, `/so`, `/sid` answer 404. Translation completeness and images not read by eye |
| MKT-006 | **Fail** | Claude Code | 13:05 | | | robots.txt as expected; `sitemap.xml` has 33 entries with x-default; the organisations sitemap has `max-age=3600`. **The organisations sitemap names `en` and `am` alternates only, while every portal page declares `en`, `am`, `om` and x-default.** Defect D-1 below. The pack row expected the same two languages, so it is corrected too |
| MKT-007 | Pass | Claude Code | 13:00 | | | `GET /api/v1/trusted-by`: 7 organisations; Alice sec and Bob sec absent since they were hidden on 2026-11-14 |
| LAND-005 | Pass | Claude Code | 13:08 | | | syntaxtech `/login`: canonical, `hreflang` en, am, om, x-default, one JSON-LD block, `X-Robots-Tag: index, follow` |
| LAND-010 | Pass | Chrome, unguarded | 13:10 | | en | Live DOM after hydration: one robots tag. syntaxtech `/login` `index, follow` (header agrees); QA Audit `/login` (a trial below the listing floor) `noindex, nofollow` (header agrees) |
| LAND-011 | Pass (news only) | Chrome, unguarded | 13:10 | | en | syntaxtech `/news` and one story: one tag each, `index, follow`, agreeing with the header. No vacancy page checked |
| PERF-009 | Pass | Chrome, unguarded | 13:12 | | en | `/en`: no `/register` or `/login` resource loaded (#722) |
| PERF-012 | Pass | Claude Code | 13:15 | | | One script and one stylesheet from `/en`: `Content-Encoding: br` with `Accept-Encoding: br`, plain without; `Vary: Accept-Encoding` |
| L10N-012 | Pass (served HTML) | Claude Code | 12:58 | | om | `/om` and its ten pages answer 200 with `lang="om"` (from MKT-001) |
| L10N-022 | Pass (a to c) | Chrome, unguarded | 13:13 | | am, en, om | new (#723). `/am/no-such-page`, `/en/no-such-page`, `/om/no-such-page` each settle with the `<html lang>` of their address; the Oromo tab title reads "Fuulli hin argamne". Part (d) not run |
| PWA-011 | Pass (files) | Claude Code | 12:55 | | | new (#719). `/favicon.ico` 200; `/app-icons/favicon.svg` 200 (64-unit tile, title "ETHR", amber dot); `apple-touch-icon.png`, `icon-512.png`, `maskable-512.png`, `badge-72.png` 200; the manifest lists 72 to 512 `any`, two `maskable`, `badge-72` `monochrome`; `/en` links the ICO, the SVG and the apple icon. Install comparison not run |
| MKT-016 | Pass (light theme) | Chrome, unguarded | 13:18 | | en | new (#719). The `/en` header logo link and the `/login` panel logo link are both named "ETHR"; the panel shows the reversed lowercase e with the amber dot and the drawn "ethr." wordmark. Dark theme not checked |

**An observation for the owner, not a defect.** The organisations sitemap still lists `alice-sec` and
`bob-sec`, the two security-test organisations hidden from Trusted by today. That is consistent with
the rules: the sitemap follows "List this page in search engines" (LAND-005), and the platform's
showcase veto (CON-P-008) governs Trusted by only. To take them out of search as well, switch their
listing off or suspend them.

## QA Audit, signed in as TA, writes agreed (about 13:25 to 13:40)

After 13:25 the owner agreed in chat to writes in QA Audit for this session and signed Chrome in to
QA Audit. The header read "QA Audit, Organisation Admin" (`syntaxict01+qa-audit`) before each step.
The Chrome is the one connected all day; the console was confirmed signed out at 13:25. Rows say
`Chrome, unguarded`.

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| S-08 | Pass | Claude Code | ~13:30 | TA | en | `/dashboard` as Organisation Admin; no console error |
| S-10 | Pass | Claude Code | ~13:30 | TA | en | `/employees` (2 rows), `/attendance`, `/leave`, `/payroll`, `/reports`, `/announcements`, `/settings`, `/billing`, `/devices`: each its own heading, no error state, console clean |
| UI-010 | Pass (sidebar, light) | Claude Code | ~13:30 | TA | en | Sidebar footer: the new mark (lowercase e, amber dot) beside "Powered by ETHR"; its svg is `aria-hidden`. Loading screen, dark theme and the console not checked |
| EMP-024 | Pass | Claude Code | ~13:30 | TA | en | "Add Employee" goes to `/employees/new`; "More ways to add" lists "One person", "From a file or pasted rows" |
| EMP-025 | Pass (b, d) | Claude Code | ~13:30 | TA | en | (d) no device: two items and no `/devices` request from the menu. (b) after the push-only terminal below: still two items. (a) and (c) need a readable terminal and the Pay-less HR login |
| EMP-026 | Pass | Claude Code | ~13:30 | TA | en | `/employees/new` shows "Other ways to add" |
| EMP-027 | Pass (a) | Claude Code | ~13:30 | TA | en | `/employees/from-device` with no `?device=`: Back reads "Employees", `/employees` |
| DEV-028 | Pass (a) | Claude Code | ~13:30 | TA | en | **Record created:** device "QA push terminal 2026-11-14", Hikvision, office network on, QA Branch (`01M7CSY250T9JMD5RCS67AQWET`). Card and page read "Not connected yet". The first Register answered 422 (D-3) |
| DEV-024 | Pass (a, b) | Claude Code | ~13:30 | TA | en | Card menu: Edit and Delete only. `/employees/from-device`: "Your terminal sends its punches to ETHR and cannot be asked who is enrolled on it. Badges it has sent that nobody owns yet are listed under Devices, where each can become an employee." with a Devices link, nothing staged. (c) needs a second, pulled device that ETHR would call |
| DEV-030 | **Fail** | Claude Code | ~13:30 | TA | en | Card and page: no Test, Pull, Discover or Import history; "Nothing received from this terminal yet." and its advice; the page's Edit dialog has "The terminal is on our office network" (on). **Sync History reads "No sync history yet. Pull records or wait for the automatic sync."**, not the push wording. D-4 |
| DEV-032 | Pass (push half) | Claude Code | ~13:30 | TA | en | Settings, Attendance: "1 device not heard from yet" and "Nothing has arrived from them. Finish setting them up: the device page shows what to enter on the terminal." No pulled device, so the "silent" half was not run |
| UAT-H1 | Pass (steps 1, 4, 5) | Claude Code | ~13:30 | TA | en | Step 4: born 2012-06-01, hired 2026-11-14: 422 "The employee must be at least 15 years old on the hire date." Step 1: **record created**, "QA UAT Hire Abebe Tesfaye" (`01M7CT5HW3VMRF4X7D55Z6K55B`), number EMP-0001 given, toast "Employee created successfully". Step 5: "QA UAT Hire Abebe Tesfay", same national ID: 409 "Already an employee", "QA UAT Hire Abebe Tesfaye is already an employee here. Open their record to make changes.", choices Close and Open their record; nothing created. Steps 2 and 3 need a mailbox the team controls (an invitation is an email); "Create a login to activate in person" was unticked so no activation code was made |
| PERF-013 | Pass | Claude Code | ~13:30 | TA | en | One load of `/dashboard`: 12 `/api/v1` calls, at most 3 in flight (from the browser's resource timings), page complete |
| SET-026 | Pass (QA Audit) | Claude Code | ~13:30 | TA | en | Run on QA Audit, not uat-setup. Industry lists the eight, no "Private company". With "General company" all 16 kinds in four groups and no link. Industry changed to "Government" in the form, not saved: "Public enterprise", "Government office (civil service)", "Regional or city government office", "Defence or police" plus the stored "Private organisation", and "Show every kind of organisation in law". Put back to "General company"; Save went back to disabled; nothing saved |

**Left in QA Audit on purpose (01, section 12):** the device "QA push terminal 2026-11-14", for the
push cases a real terminal can finish (DEV-029, DEV-033 onward), and the employee "QA UAT Hire
Abebe Tesfaye" (EMP-0001), which QA Audit's thin data can use. Both can be deleted from their pages.
QA Audit still shows "Confirm the kind of organisation below": it was not confirmed, because that is a
write the cases did not call for. **The owner should sign that Chrome out of QA Audit** when the pass
is over (11, section 6.1).

## Not run, and why

- **Signed-in cases as HR, FIN, SUP, EMP and PA**, and the TA cases above marked partial. Only TA
  was signed in.
- **Mode X cases, and the device cases that need a real terminal, a pulled device ETHR would
  call, or a token** (DEV-025 to DEV-027, DEV-029, DEV-031, DEV-033 onward).
- **QA Audit's data still blocks K-05, K-48 and K-50** (2 employees, no supervisor, no Sick Leave
  type, payroll runs with no entries), as recorded on 2026-11-13.

## Defect drafts (10, section 1), for the tester to file

**All four are fixed by [#727](https://github.com/syntax-ict/Ethr.et/pull/727)** (D-2 apart from the missing Oromo text, which is wider than this defect). Re-run MKT-006, DEV-030 and the D-3 dialog on production once #727 is live; until then they stand as found.

**D-1. The organisations sitemap leaves out Afaan Oromoo.** P3, search listing.

- Where: `https://www.ethr.et/sitemap-organisations.xml`, built by
  `api/app/Http/Controllers/OrganisationSitemapController.php:115-117`.
- Seen: each `<url>` carries `hreflang` `en`, `am` and `x-default`.
- Expected: the set the page itself declares. `api/app/Support/PortalHead.php:65-68` gives every
  portal page `en`, `am`, `om` and `x-default`, and Afaan Oromoo shipped on 2026-11-02
  (`App\Support\Locales::SHIPPED`). Search engines expect the two to agree, and the controller
  restates the language list as literals, which convention 9 forbids.
- Fix: build the alternates from `Locales::SHIPPED` in the controller. MKT-006 is corrected on this
  branch to expect `om`.

**D-2. The wizard's policy names carry an em dash, and have no Afaan Oromoo text.** P3, copy style.
Found reading the code while reviewing SET-024; not yet seen on production (the wizard needs a
signed-in, writable organisation).

- Where: the setup wizard's "Which rules apply" select, whose options come from
  `api/app/Services/Onboarding/PolicyProfileCatalog.php:46` and `:60`.
- Seen in the source: `'Ethiopia — private sector'`, `'Ethiopia — public sector (civil service)'`,
  and the same dash in `label_am`. There is no `label_om`.
- Expected: no em dash in English or Amharic interface text (house style, MKT-009; the frontend gate
  does not read backend catalogues, which is how these got through), and an Oromo label, since
  Afaan Oromoo is a shipped language.
- Fix: "Ethiopia, private sector" or "Ethiopia: private sector" (and the Amharic equivalent), plus
  `label_om`. SET-024 quotes the labels as they are today.

**D-3. Registering a device without a branch answers with the field's internal name.** P3, usability.

- Where: Devices, Register Device. `api/app/Http/Requests/Device/StoreDeviceRequest.php:31` requires
  `branch_public_id`; the dialog marks Branch as neither required nor chosen, and
  `api/lang/en/validation.php` names no attribute for it.
- Seen on production, about 13:30: Register with no branch chosen gives 422 and the toast "The branch public
  id field is required."; the dialog stays open with no mark on the Branch field.
- Expected: Branch shown as required (or chosen for the person when the organisation has one), and a
  message a person understands ("Choose a branch.") under the field, in en, am and om.

**D-4. A Hikvision push terminal's Sync History says to pull records.** P3, #709 not fully applied.

- Where: `src/src/app/(dashboard)/devices/[id]/device-detail.tsx:503`, which chooses
  `device_detail_page.push_no_sync_history` only for `adapter_type === "zkteco_adms"`.
- Seen on production, about 13:30: the receives-only Hikvision terminal's Sync History tab reads "No sync
  history yet. Pull records or wait for the automatic sync."
- Expected (DEV-030, #709): "This terminal sends each punch as it happens, so there are no scheduled
  pulls to list. Its punches are under Attendance Events." The same file already has
  `sendsOnly = device.receives_only === true` (line 263); the condition should use it as well.

## Re-run after #727 went live (v2026.11.14.7, about 16:40 EAT)

Deployed 2026-11-14 13:38Z: Plesk Pull now, then Deploy now (the pull alone did not deploy).
`release.json` reads `b23a491d6dcc`, built 13:38:34Z; `scripts/release-status.sh` reports the
latest release running and no migration waiting. No release step was needed: no migration, and
`optimize` does not cache translations.

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| MKT-006 | Pass | Claude Code | ~16:42 | | | D-1 fixed: the organisations sitemap names `en`, `am`, `om` and x-default for each entry |
| DEV-030 | Pass | Chrome, unguarded | ~16:43 | TA | en | D-4 fixed: the push terminal's Sync History reads "This terminal sends each punch as it happens, so there are no scheduled pulls to list. Its punches are under Attendance Events." |
| D-3 dialog | Pass | Chrome, unguarded | ~16:44 | TA | en | Register Device: "Branch *", `aria-required`, QA Branch filled in (the only branch), Register enabled; closed without registering. The server's "The branch field is required." is no longer reachable from the dialog here and is pinned by `DeviceManagementTest` |
| D-2 | Pass | Chrome, unguarded | ~16:45 | TA | en, am | `GET /api/v1/onboarding/industries`: "Ethiopian private sector", "Ethiopian public sector (civil service)" and their Amharic, no dash. Afaan Oromoo text for the wizard's lists is still missing (a wider gap, not this defect) |
