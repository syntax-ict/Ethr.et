# 10: templates, smoke checklist, known issues and reference

---

## 1. Defect report template

Copy into a new issue on `syntax-ict/Ethr.et` with the label `qa-production`. Remove every cookie,
token, password, code and reset link before posting.

```markdown
**Title:** [MODULE] short statement of what is wrong (for example: [Payroll] net pay off by 0.01 for 9,000 ETB basic)

| | |
|---|---|
| Case ID | PAY-011 |
| Severity | S1 / S2 / S3 / S4 |
| Release | v2026.10.24.N (from /release.json, commit abc123) |
| Organisation | QA Audit (`qa-audit`) |
| Persona and role | FIN (finance_admin) |
| Language / calendar / theme | am / Ethiopian / dark |
| Browser and device | Chrome 131, Windows 11 / Safari, iPhone 14 |
| Time (EAT) | 2026-10-24 14:32 |

**Steps to reproduce**
1. ...
2. ...

**Expected** (quote the specification line)
...

**Actual**
...

**Evidence**
- Screenshot or recording (secrets blurred)
- API: `POST /api/v1/...` → status 422, body: `{ "type": "...", "title": "...", "status": 422, "detail": "..." }`
- Record public ids involved (ULIDs, never names of real people)

**Frequency:** always / intermittent (n of m) / once
**Workaround:** none / describe
```

## 2. Execution log

One file per release, for example `docs/audit/QA-PRODUCTION-2026-10-24-log.md`, or a spreadsheet
with the same columns.

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| AUTH-001 | Pass | | | TA, HR, FIN, DA, SUP, EMP | en | |
| PAY-011 | Fail | | | FIN | en | #123 |
| DEV-007 | Blocked | | | TA | en | No test device |
| PLAN-011 | Not run | | | | | Needs 60 days |

Results: **Pass**, **Fail** (with a defect), **Blocked** (cannot run; say why), **Not run** (say why).
A change in the release that the pack has no case for is logged by its PR number as **Not covered**
(from `scripts/qa-pack-coverage.sh`; [README](README.md#keeping-this-pack-current)), so the summary
shows what was never tested rather than reading as a clean pass.
At the end, total per module and per priority, and the pass rates the exit criteria ask for.

## 3. Release smoke checklist

Run after every deployment, before anything else. **Read-only.** About 30 minutes. A failure stops
the test pass (test plan 7.3).

| # | Check | How | Pass when |
|---|---|---|---|
| S-01 | Right release is live | `https://www.ethr.et/release.json`, or `scripts/release-status.sh https://www.ethr.et` | Commit and tag equal the release under test; the script exits 0 |
| S-02 | Healthy | `GET /api/v1/health` | All core services healthy; scheduler heartbeat under 15 min; failed jobs not growing; `schema_detail.pending_migrations` is `0`. Above 0 the code is ahead of its schema (the release steps did not run): stop and tell the owner. The release-status script fails on it too |
| S-03 | Release steps ran | Call a route added in this release (from the release notes) while signed out | 401 (needs sign-in), **not** 404. A 404 here while old routes give 401 means the route cache is stale |
| S-04 | Redirects | `http://ethr.et` → `https://www.ethr.et`; `https://ethr.et/qa-audit` → `https://www.ethr.et/qa-audit` (301); organisation subdomain (if used) → `/login`; `https://admin.ethr.et` → `/admin/` | As stated |
| S-05 | Secrets closed | `/.env`, `/api/.env`, `/artisan`, logs, at most three in ten minutes ([01, rule 10](01-TEST-PLAN.md#9-production-safety-rules)) | 403 or 404 |
| S-06 | Marketing site | `/en`, `/am`, `/en/pricing` | 200, no error, prices shown |
| S-07 | Organisation entry page | `/qa-audit` signed out | Branded sign-in page |
| S-08 | Sign-in per role | TA (with MFA), HR, FIN, SUP, EMP in QA Audit | Each reaches the dashboard |
| S-09 | Console | PA signs in at `https://admin.ethr.et` with MFA | Lands on `/admin`, not an organisation dashboard; KPI cards, the Operations strip and the tenant list load with no error toast |
| S-10 | One read per module | As TA: employees list, attendance list, leave page, payroll runs, reports page, announcements, settings, billing, devices | Each loads with data (or a proper empty state), no error toast, console clean |
| S-11 | Self-service | As EMP: My Attendance, Leave balances, My Payslips, Profile | Load |
| S-12 | Amharic and Afaan Oromoo | Header menu, Language: switch to አማርኛ, then to Afaan Oromoo, on the dashboard | Translated each time, no raw keys; switch back to English |
| S-13 | Phone | Dashboard at 375 px | Bottom bar, no horizontal scroll |
| S-14 | No stale browser bundle | Hard reload after the release; check the version shown or `release.json` fetched by the page | New version served (the site sends no-cache on HTML) |
| S-15 | Release finished its steps | PA: `/admin/operations` | Release is Healthy: commit equals S-01, pending migrations 0, configuration and routes cached after "Built", maintenance routes closed. Background work Healthy, external caller under 30 min. "Needs a look" on Failed jobs alone does not fail the smoke; read the jobs (OPC-004) |

## 4. Known issues and suspected defects to verify

Found by reading the code while writing this pack (2026-10-24, K-01 to K-18; 2026-10-28, K-19 to
K-43) and from earlier production QA. **They
are not confirmed on production.** Run the named case, then either file the defect or record
"not reproduced" in the log.

**Every entry was re-checked against the code on 2026-11-08** (third edition), and K-44 to K-51 were
found then. "Fixed" means fixed in the code on `main`; it still needs verifying on production, and a
fix that is merged but not released is recorded as still open there.

| # | Area | Observation | Case |
|---|---|---|---|
| K-01 | Billing | **Fixed in #624** (A-Z review, `BillingService.php:145`): a trial converted to a paid plan now becomes `active`. Verify | PLAN-007 |
| K-02 | Leave | **Fixed in #624** (A-Z review): `leave.min_notice` and `leave.max_consecutive` now have text in en, am and om. Verify | LR-009, LR-010 |
| K-03 | Leave | *Re-checked 2026-11-08, still open, both halves; the controller passes remaining and requested days, but the message never uses them.* Overlap message says "approved" even when the clash is with a pending request; the insufficient-balance message does not show remaining or requested days | LR-007, LR-006 |
| K-04 | Leave | *Re-checked 2026-11-08, still open.* No half-day leave in the API | (none; product gap) |
| K-05 | Team attendance | **Confirmed in code 2026-11-08, worse than first written.** `summary.present` filters with `whereIn` on an enum-cast status (`TeamMonitoringController.php:88`), which never matches, so only members with no recorded status count as present: anyone present or late drops out and the figure can read 0. `late` (a plain `where`) is right | TEAM-002 |
| K-06 | Team attendance | *Re-checked 2026-11-08, still open.* `/attendance/team` without a date may use the UTC date (between 00:00 and 03:00 EAT it shows yesterday) | TEAM-004 |
| K-07 | Team overtime | *Re-checked 2026-11-08, still open; the month is also taken in UTC.* Voided records may be counted | TEAM-005 |
| K-08 | Attendance | *Confirmed in the code 2026-11-08.* **Fixed 2026-10-24** (e9e76b85d): night-shift lateness after midnight is dated the night the shift began. Verify | SHF-004 |
| K-09 | Attendance | *Confirmed in the code 2026-11-08.* **Fixed 2026-10-27** (#443): manual entry records a night shift crossing midnight. Verify | MAN-002 |
| K-10 | Attendance import | *Confirmed in the code 2026-11-08.* **Fixed 2026-10-27** (#443): imported rows are rated against the day's shift. Verify | AIMP-002, CAP-002 |
| K-11 | Contracts | *Re-checked 2026-11-08, by design.* The contracts-ending list includes contracts already past their end but still active. **By design** since #441 ("end within 30 days or have ended") | LIFE-006 |
| K-12 | Approvals | *Re-checked 2026-11-08, still open; each item's reason has no length limit either, while a single rejection allows 1,000 characters.* Batch approval has no maximum size | LD-006 |
| K-13 | Payroll | *Re-checked 2026-11-08, still open; A-Z review decision D-F1 keeps it for the accountant.* There is no statutory transport-allowance exemption; taxability is the rule's own flag. Confirm with the accountant | PAY-004 |
| K-14 | Console | *Re-checked 2026-11-08, still open; user search now also needs console MFA to read.* Platform user search and statutory layouts have no screen; API only | CON-P-017 |
| K-15 | Security | **Answered in the code 2026-11-08:** `/api/docs` is installed on production but closed. Scramble's `RestrictedDocsAccess` lets only the `viewApiDocs` gate in outside `local`, and only a platform super admin passes it; anyone else gets 403. Confirm on production | SEC-007 |
| K-16 | Hosting | *Re-checked 2026-11-08, still open on the host side.* Uploads over 2 MB are refused by the host's PHP limits (provider-locked). The application reports the real limit in `/auth/me` (`upload_limit_bytes`) and refuses an oversized request by name. Expected: a clear refusal | DOC-002, ANN-005, OPS-005 |
| K-17 | Mobile check-out | **Fixed in #624** (A-Z review): check-out re-checks photo consent and takes the 1.5 MB selfie limit. Verify | MOB-008 |
| K-18 | Copy | *Re-checked 2026-11-08, still open:* 7 strings in `api/lang/en` carry a long dash (3 in `auth.php`, 2 in `personnel.php`, 1 each in `disciplinary.php` and `payroll.php`), as do the same keys in `am` and `om`, and `copy-style.test.ts` reads only `en.json` and `am.json`. `om.json`'s `admin_features.exception_title` has one too | MKT-009 |
| K-19 | Activation | *Re-checked 2026-11-08, still open.* The typed-code form `/login/activate` is linked from nowhere and says "Invalid activation link" without `?tenant=`, though HR's dialog tells staff to "open the sign-in page and type the code" | ACT-012 |
| K-20 | Employees | *Re-checked 2026-11-08.* **Fixed in #458 for the API and the profile:** an employee with a phone and no email now gets a login for in-person activation, and the code works while text invitations are off. The form's box is still disabled (K-42) | EMP-015, INV-010 |
| K-21 | Activation | *Re-checked 2026-11-08, still open; since #486 the wrong code also counts toward the 15-minute authenticator lock (MFA-012).* A wrong or missing authenticator code when issuing a code is reported on `mfa_code` as "The password is incorrect." | ACT-009 |
| K-22 | Payroll | *Re-checked 2026-11-08, still open.* Finance can open the Reconcile tab but cannot settle (no `leave.adjustBalance` or `attendance.manage`); HR can settle but cannot open the tab. Only the tenant admin can do both | RCN-005 |
| K-23 | Field visits | *Re-checked 2026-11-08, still open.* The Visits date filter may use the UTC date, so a visit between 00:00 and 03:00 EAT shows under the previous day | VIS-005 |
| K-24 | Public site | *Re-checked 2026-11-08, narrowed: a trial is named only once it holds 5 people (`Tenant::PUBLIC_MIN_EMPLOYEES`), so an empty new `uat-` organisation is not named; QA Audit and Syntax still are.* Since 2026-10-26 every organisation in use is named under Trusted by and listed for search engines unless switched off, including QA Audit, Syntax, QA Isolation and every `uat-` organisation from the moment it is created | LAND-007 |
| K-25 | Console | *Re-checked 2026-11-08, partly fixed: "PHP limits" is translated; the raw codes and `reverb` as `disabled` remain.* The operations Services panel shows raw codes (`api`, `reverb`, queue names) and lists `reverb` as `disabled`, while the console home translates names and hides disabled services; "PHP limits" stays English in Amharic. Cosmetic | OPC-006 |
| K-26 | Console | *Re-checked 2026-11-08, still open.* Any failed job, however old, keeps Failed jobs at "Needs a look" until it is retried or dismissed, so production rarely reads "Nothing needs attention" | OPC-006 |
| K-27 | Console | *Re-checked 2026-11-08, unchanged by owner decision.* Deleting a cancelled organisation needs only a reason from a signed-in PA with MFA on, no fresh code (owner decision 2026-10-27; Restore undoes it) | CON-P-009 |
| K-28 | Console | *Re-checked 2026-11-08, still open.* "Recurring revenue" leaves out trials and uses each subscription's captured price, not the plan's current price. "Conversion" counts any active organisation with a trial end date as converted | CON-P-011 |
| K-29 | Marketing | *Re-checked 2026-11-08, by design.* Uploading a picture to the hero or a problems row replaces its animation (by design; do not do it on production) | CON-P-013, MKT-010 |
| K-30 | Registered phones | *Re-checked 2026-11-08, still open.* A person who is exempt, or on a listed office network, but still has an active registered phone is asked for the passkey; under "Only the registered phone" dismissing it stops the punch, though the server would accept it | PHN-016 |
| K-31 | Registered phones | *Re-checked 2026-11-08, still open.* "Check and review" and an exemption score 35, an office computer 5; the default review threshold is 40, so alone they never reach Integrity, although the screens say the punches are sent for review | PHN-009, PHN-015, PHN-017 |
| K-32 | Registered phones | *Re-checked 2026-11-08, still open.* Passkeys belong to the platform domain: on a verified custom domain every punch is refused `device-wrong-host`, and the "Open" link may be the same custom domain | PHN-020 |
| K-33 | Registered phones | *Re-checked 2026-11-08, still open as written, but low risk: the server notes that production passes the real client address. Confirm with PHN-017.* Office networks are matched against the address PHP sees, with no trusted-proxy setting, so behind a proxy no office address may ever match | PHN-017 |
| K-34 | Registered phones | **Partly fixed in #490:** the Phones list and decisions now follow reach. HR can still approve their own waiting phone (no self check in `approve()`) | SEC-026, SEC-027 |
| K-35 | Copy | *Re-checked 2026-11-08, still open.* Phone messages promise "a manager will confirm it" though only HR and organisation admins approve; a first phone still waiting is told to "Use your registered phone"; the exemption limit reads "before +92 days" | PHN-005, PHN-007, PHN-015 |
| K-36 | Registered phones | *Re-checked 2026-11-08, still open.* "One phone, one person" is checked per browser: a colleague in a different browser app on the same handset can register | PHN-010 |
| K-37 | Payroll | *Re-checked 2026-11-08, still open, and wider: Art. 85 sick-leave deductions are not itemised either, and the register API has no field for them.* A lateness deduction is not itemised on the payslip or register CSV; basic is shown already reduced (only the calculation log and the register API's `lateness_cents` show it) | LATE-005 |
| K-38 | Payroll | *Re-checked 2026-11-08, still open.* `paid_on` is checked against today's UTC date, so between 00:00 and 03:00 EAT today's EAT date may be refused | PAID-002 |
| K-39 | Address | *Re-checked 2026-11-08, still open: `SettingsController@updateSubdomain` is not on `BlockImpersonatedActions`' list.* A platform operator impersonating an organisation can rename its address (renaming is not on the impersonation block list) | TEN-015 |
| K-40 | Attendance setup | *Re-checked 2026-11-08, still open.* The "no kiosk" check fires only when the kiosk PIN is also required, not whenever the kiosk method is on with no kiosk | SETUP-003 |
| K-41 | Feature gate | **Superseded.** The console now switches features for every organisation and grants or revokes them for one, integrations and public pages can be switched off without a deploy, and every switch offered either works or is not shown. PLAN-014 to PLAN-018 were rewritten for them, and PLAN-019 to PLAN-021 added | PLAN-014 to PLAN-021 |
| K-42 | Employees | *Re-checked 2026-11-08, still open; the sign-in fix in #652 does not change this form.* The Add employee form still disables the login box for a phone and no email, saying "Add an email to send a login invitation.", although an in-person code now works for them. Workaround: save without a login, then "Activate in person" from the profile | EMP-015 |
| K-43 | Users | *Re-checked 2026-11-08, still open.* After a bulk resend that queued nothing, the toast always reads "No one is waiting for an invitation by email.", including when people were skipped as invited in the last 10 minutes or counted for in-person activation. Suspected; confirm the wording is acceptable | INV-003 |
| K-44 | Sign-in | **Fix open in #652 (not merged or deployed yet).** Found 2026-11-08 while refreshing file 03. The sign-in form labels its one field "Email" and refuses anything else with "Enter a valid email address." (`login-form.tsx:52`), although the API can resolve a phone number or an employee code when the organisation turns those on, and a finished in-person activation says "Sign in with your employee code and the password you just chose." An employee with no email may have no way in from the form. Confirm before a pass that relies on in-person activation | AUTH-004, ACT-002 |
| K-45 | Public site | Found 2026-11-08 while refreshing file 08. The organisations sitemap gives each portal `hreflang` links for `en`, `am` and `x-default` only (`OrganisationSitemapController.php:108`), while the portal page itself declares `om` too. Search engines may not find the Afaan Oromoo portals from the sitemap | MKT-006 |
| K-46 | Payroll | Found 2026-11-08 while refreshing file 07. An Art. 85 sick day approved in a period an approved run already paid is not refused: the period lock in `LeaveDecisionService.php:61` checks unpaid leave types only, and Sick Leave is a paid type. Its half-pay or unpaid deduction is never taken, the gap A-Z review E-03 closed for unpaid leave | SICK-004 |
| K-47 | Payroll | Found 2026-11-08 while refreshing file 07. Finance can create, lower or cancel a loan or cost-sharing obligation on its own employee record: the controllers check the permission only, and #490's rule that nobody below the organisation admin changes their own pay does not reach deductions. Owner decision | LOAN-009 |
| K-48 | Payslips | Found 2026-11-08 while refreshing file 07. The payslip PDF lists basic, the allowance rules and gross, but never overtime (`payslip.blade.php`; overtime is not in the entry's allowances), so with overtime the printed lines do not add up to gross | OUT-006 |
| K-49 | Setup wizard | **Fixed in #656 (not merged or deployed yet):** `general` and a missing type now narrow nothing, so a General Company sees every industry with none chosen. Verify. Found 2026-11-08 while refreshing file 04. A "General Company" sign-up is stored as type `general`, and the industry step keeps every industry whose key or base matches the type (`industry-config-step.tsx:80`). Ten catalogue industries have base `general`, so such an organisation sees only those ten, although the code says General keeps them all. "Show every type" is the workaround. The #499 test passes only because its fixture has no `general`-based industry | SET-008 |
| K-50 | Leave | **Fixed in #656 (not merged or deployed yet):** for an Art. 85 type the card shows days taken in the current twelve-month period, "N of M days in the twelve months from" its first day, and what the next sick day pays; the dashboard tile and kiosk show the same, and no remainder goes below zero. Verify. Found 2026-11-08 while refreshing file 06. Sick leave under Art. 85 is no longer held to the 30-day balance, but the Sick balance card still shows the 30-day figure, so after a long illness `remaining_days` goes below zero and the card shows the negative number | LB-012 |
| K-51 | Leave | Found 2026-11-08 while refreshing file 06, owner decision. `GET /leave/balance/{employee}` needs `leave.viewAll`: a supervisor gets 403 even for their own report, while Finance can read anyone's balance. The pack said a supervisor could see a report's balance; LB-008 now states what the code does | LB-008 |

## 5. Production facts in one place

| Fact | Value |
|---|---|
| Public site | `https://www.ethr.et` (apex redirects to www) |
| Platform console | `https://admin.ethr.et` |
| Organisation addresses | `https://www.ethr.et/{slug}`, `https://{slug}.ethr.et` when subdomains are on, or a verified custom domain; one canonical address per organisation |
| Health | `/api/v1/health` (30 a minute per IP) |
| Release identity | `/release.json`; tags `vYYYY.MM.DD.N` |
| Hosting | Ethio Telecom shared hosting (Plesk), Linux Gold: Apache, PHP 8.3, MariaDB; no shell; one 50 GB quota (51,200 MB) for database, files, backups and mail together. The nightly backup measures use against it (`DISK_QUOTA_MB`), amber at 70%, red at 85% |
| Languages | English, Amharic and Afaan Oromoo (since 2026-11-02). Tigrinya, Somali and Sidama are listed as coming soon and cannot be selected |
| Mail | One mailbox for every organisation, with a daily budget (`MAIL_DAILY_CAP`, 500 until the provider's real cap is read), counted per Ethiopian day. Bulk email (payslips released, missing punches, reminders, digests) stops at 60% of it and other email at 90%; sign-in codes, resets and activation are never held. A held email skips only the mail: the in-app notice still arrives. The operations centre shows sent and held today |
| PHP limits in force | upload 2 MB, post 8 MB, memory 128 MB, time 30 s |
| Background work | No worker. Scheduler and queue run every 5 minutes from two outside callers, and briefly after ordinary requests |
| Realtime | Off; the client polls |
| Time zone | Stored UTC, shown EAT (Africa/Addis_Ababa, UTC+3, no DST) |
| Currency | ETB in cents, shown `X,XXX.XX ETB` |
| Trial | 6 months on Starter, every feature open, no limits |
| Plans (as seeded; editable in the console) | Starter 0.00 (10 employees, 1 branch, 2 devices; attendance, leave, employees); Professional 999.00 (100, 5, 20; adds payroll, reports, notifications); Enterprise 8,999.00 (unlimited; adds API, webhooks, saved and scheduled reports, audit log, custom domain) |
| Token and session | Access token 15 min, refreshed silently; idle timeout per organisation, default 480 min (5 to 480) |
| MFA | TOTP, 6 digits; trusted browser 30 days; no recovery codes |
| Sign-in code | 6 digits, 5 minutes, single use |
| Reset link / invitation | 60 minutes / 72 hours |
| In-person activation code | 12 characters, 24 hours, single use, locked after 5 wrong tries; purged 30 days after expiry |
| Bulk invitations | Queued; 30 emails a minute per organisation (texts 10); a second request for the same person within 10 minutes sends nothing; reminders 3 days after the last invitation, at most 2, only for invitations under 30 days old; text invitations off |
| Payroll run states | processing, completed (or failed), approved, paid; voided. **A paid run cannot be voided** |
| Rounding | Every amount rounded once, half away from zero; cost sharing rounds down |
| Registered phones | Passkeys for the platform domain (`ethr.et`): they work on `www.ethr.et/{slug}` and `{slug}.ethr.et`, not on a custom domain. Off by default |
| Public listing | Trusted by and search listing are on for every organisation in use (active, or a trial not yet ended) unless it switches them off; the platform can hide one from Trusted by |
| Operations centre | `https://admin.ethr.et/admin/operations` (PA; read-only) |

## 6. Scheduled jobs a tester may see the effect of

Times are UTC (EAT is +3).

| Job | When | Effect |
|---|---|---|
| Device sync | every 5 min | Pulls punches from online, auto-sync devices |
| Auto check-out | every 5 min | Closes open records at shift end once end + N min passed (when on) |
| Photo retention | hourly | Starts reviews and deletes expired selfies |
| Scheduled reports, dashboard digests | hourly | Sends due reports and digests |
| Leave accrual | Daily, 00:30 | Raises annual balances when a new month of the leave year starts |
| Holiday roll-forward | 1st of the month, 01:30 | Copies recurring holidays to next year |
| Leave carry-forward | Daily 01:00 in the first week of the leave year (1 January, or Meskerem 1) | Sets carried days |
| Backup | daily 01:00 | `ethr:backup`: a local archive, an encrypted off-host copy when storage is set in the console, and a disk-use reading against the plan's quota |
| Tenant journeys | hourly at :20 | Recomputes each organisation's journey stage in the console |
| Data clean-up | daily 02:00 | Notifications over 90 days, webhook deliveries over 30 days, import staging over 7 days removed |
| Monthly invoices | 1st of the month, 03:00 | Raises invoices for paid plans |
| Overdue invoices | daily 04:00 | Overdue at 7 days, past due 30, suspended 60 |
| Trial expiry notices | daily 05:00 | Warns organisations near trial end |
| Approval reminders and escalation | daily 06:00 | Reminders after 48 h; escalation past the SLA |
| Contract and probation reminders | daily 06:05 | 30, 7, 1 days (contracts); 14, 1 (probation) |
| Missing punches | daily 15:30 | Notifies employee and supervisor for yesterday |
| Attendance anomalies | daily 15:45 | Feeds Intelligence |
| Custom domain re-check | Mondays 03:00 | Logs a domain whose DNS no longer verifies |
| Apply due personnel actions | hourly at :05 | Applies held actions whose date has come (LIFE-012) |
| Attendance baseline | hourly | Gives an organisation with no active shift its industry's usual week (BASE-001) |
| Punch reminders by push | every 5 min | Shift start, missed check-in, check-out reminders to subscribed phones (MOB-012) |
| Attendance pattern scan | daily 16:00 | `buddy_pattern` and `grace_edge` flags (RISK-009) |
| Purge activation codes | daily 00:00 | Deletes activation codes more than 30 days past expiry (ACT-013) |
| Invitation reminders | daily 06:00 | Queues another invitation for people invited 3 or more days before who have not activated (INV-013) |
| Passkey challenge purge | daily 00:00 | Deletes passkey challenges an hour after they expire (a challenge lives 3 minutes) |

## 7. Glossary

| Term | Meaning |
|---|---|
| Organisation, tenant | One customer of ETHR, with its own data, address and users |
| Slug | The organisation's short name in its address, for example `qa-audit` |
| Canonical address | The one address an organisation is reached at; others redirect to it |
| Reach (scope) | Whose records a role may act on: self, direct reports, team, department, branch, all |
| Maker-checker | The person who processed a payroll run may not approve it while another approver exists |
| Approval chain | Configured sequence of approval steps for leave, corrections, overtime, profile changes |
| Integrity flag | A mark on a suspicious punch for review; the punch is still recorded |
| Risk score | 0 to 100 on every punch from fixed signal weights; at the review threshold (default 40) the day is sent for review, never refused |
| Work site | A named circle on the map, besides the branch, where certain people may punch |
| Attendance mode | Where an employee punches: at their branch (office), in the field, or remotely |
| Registered phone, passkey | The one phone allowed to punch for an employee when the organisation checks phones; the passkey is the key it holds, unlocked by fingerprint, face or PIN |
| Reconciliation | Settling a pay period's attendance exceptions (absences, lateness, missing punches) through requests before payroll |
| Scheduled change | A personnel action dated in the future, held until its date |
| Offline grant | A signed permission held by the phone or kiosk so offline punches can be verified when they sync |
| Idempotency key | A unique key on each write so a retried request does not record twice |
| Pagume | The 13th Ethiopian month, 5 or 6 days, early September |
| EAT | East Africa Time, UTC+3 |
| ULID / public id | The 26-character identifier the API uses for every record |
| Problem JSON | The error format: `type`, `title`, `status`, `detail`, `errors` |
| QA Audit, Syntax, `uat-` | The organisations a tester may write in |
| PA, TA, HR, FIN, DA, SUP, EMP | Personas, see the test plan section 5 |

## 8. Where the facts in this pack come from

| Topic | Source of truth |
|---|---|
| Permissions and roles | `docs/PERMISSIONS.md`, `api/database/seeders/PermissionSeeder.php` |
| Routes | `api/routes/api.php`, `api/routes/web.php` |
| Rate limits | `api/app/Providers/AppServiceProvider.php` |
| Messages | `api/lang/en/*.php`, `api/lang/am/*.php`, `src/src/lib/i18n/locales/*.json` |
| Payroll rules and golden payslips | `api/app/Services/Payroll/`, `docs/payroll/ACCOUNTANT-SIGN-OFF.md`, `docs/payroll/STATUTORY-SCHEDULES.md` |
| Attendance engine | `api/app/Services/Attendance/` |
| Approval engine | `docs/APPROVAL-ENGINE-DESIGN.md`, `api/app/Services/Approval/` |
| Invitations | `docs/EMPLOYEE-INVITATIONS-PLAN.md`, `api/app/Services/Invitations/`, `api/config/invitations.php` |
| Operations and health | `docs/operations/ON-CALL.md`, `docs/deployment/shared-hosting/health-check.md` |
| Earlier production QA | `docs/audit/QA-PRODUCTION-*.md`, `docs/audit/QA-ATTENDANCE-PRODUCTION-2026-10-12.md` |
| Payroll changes and statutory parameters | `docs/payroll/PAY-CHANGES.md`, `docs/payroll/STATUTORY-PARAMETERS.md` |
| Operations centre | `docs/operations/` (spec and gap list) |
