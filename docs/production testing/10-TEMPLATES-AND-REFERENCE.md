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
At the end, total per module and per priority, and the pass rates the exit criteria ask for.

## 3. Release smoke checklist

Run after every deployment, before anything else. **Read-only.** About 30 minutes. A failure stops
the test pass (test plan 7.3).

| # | Check | How | Pass when |
|---|---|---|---|
| S-01 | Right release is live | `https://www.ethr.et/release.json`, or `scripts/release-status.sh https://www.ethr.et` | Commit and tag equal the release under test; the script exits 0 |
| S-02 | Healthy | `GET /api/v1/health` | All core services healthy; scheduler heartbeat under 15 min; failed jobs not growing |
| S-03 | Release steps ran | Call a route added in this release (from the release notes) while signed out | 401 (needs sign-in), **not** 404. A 404 here while old routes give 401 means the route cache is stale |
| S-04 | Redirects | `http://ethr.et` → `https://www.ethr.et`; `https://ethr.et/qa-audit` → `https://www.ethr.et/qa-audit` (301); organisation subdomain (if used) → `/login`; `https://admin.ethr.et` → `/admin/` | As stated |
| S-05 | Secrets closed | `/.env`, `/api/.env`, `/artisan`, logs | 403 or 404 |
| S-06 | Marketing site | `/en`, `/am`, `/en/pricing` | 200, no error, prices shown |
| S-07 | Organisation entry page | `/qa-audit` signed out | Branded sign-in page |
| S-08 | Sign-in per role | TA (with MFA), HR, FIN, SUP, EMP in QA Audit | Each reaches the dashboard |
| S-09 | Console | PA signs in at `https://admin.ethr.et` with MFA | Lands on `/admin`, not an organisation dashboard; KPI cards, the Operations strip and the tenant list load with no error toast |
| S-10 | One read per module | As TA: employees list, attendance list, leave page, payroll runs, reports page, announcements, settings, billing, devices | Each loads with data (or a proper empty state), no error toast, console clean |
| S-11 | Self-service | As EMP: My Attendance, Leave balances, My Payslips, Profile | Load |
| S-12 | Amharic | Switch to አማርኛ on the dashboard | Translated, no raw keys |
| S-13 | Phone | Dashboard at 375 px | Bottom bar, no horizontal scroll |
| S-14 | No stale browser bundle | Hard reload after the release; check the version shown or `release.json` fetched by the page | New version served (the site sends no-cache on HTML) |
| S-15 | Release finished its steps | PA: `/admin/operations` | Release is Healthy: commit equals S-01, pending migrations 0, configuration and routes cached after "Built", maintenance routes closed. Background work Healthy, external caller under 30 min. "Needs a look" on Failed jobs alone does not fail the smoke; read the jobs (OPC-004) |

## 4. Known issues and suspected defects to verify

Found by reading the code while writing this pack (2026-10-24, K-01 to K-18; 2026-10-28, K-19 to
K-40) and from earlier production QA. **They
are not confirmed on production.** Run the named case, then either file the defect or record
"not reproduced" in the log.

| # | Area | Observation | Case |
|---|---|---|---|
| K-01 | Billing | Upgrading during a trial may leave the organisation in `trial`, so it is treated as inactive once the trial date passes | PLAN-007 |
| K-02 | Leave | Messages for minimum notice and maximum consecutive days may show raw keys (`leave.min_notice`, `leave.max_consecutive`) | LR-009, LR-010 |
| K-03 | Leave | Overlap message says "approved" even when the clash is with a pending request; the insufficient-balance message does not show remaining or requested days | LR-007, LR-006 |
| K-04 | Leave | No half-day leave in the API | (none; product gap) |
| K-05 | Team attendance | `summary.present` may under-count. A fix to the present count on `/attendance/today` (2026-10-24) may have closed it: re-test and record | TEAM-002 |
| K-06 | Team attendance | `/attendance/team` without a date may use the UTC date (between 00:00 and 03:00 EAT it shows yesterday) | TEAM-004 |
| K-07 | Team overtime | Voided records may be counted | TEAM-005 |
| K-08 | Attendance | **Fixed 2026-10-24** (e9e76b85d): night-shift lateness after midnight is dated the night the shift began. Verify | SHF-004 |
| K-09 | Attendance | **Fixed 2026-10-27** (#443): manual entry records a night shift crossing midnight. Verify | MAN-002 |
| K-10 | Attendance import | **Fixed 2026-10-27** (#443): imported rows are rated against the day's shift. Verify | AIMP-002, CAP-002 |
| K-11 | Contracts | The contracts-ending list includes contracts already past their end but still active. **By design** since #441 ("end within 30 days or have ended") | LIFE-006 |
| K-12 | Approvals | Batch approval has no maximum size | LD-006 |
| K-13 | Payroll | There is no statutory transport-allowance exemption; taxability is the rule's own flag. Confirm with the accountant | PAY-004 |
| K-14 | Console | Platform user search and statutory layouts have no screen; API only | CON-P-017 |
| K-15 | Security | Whether the API documentation UI at `/api/docs` should be public on production | SEC-007 |
| K-16 | Hosting | Uploads over 2 MB are refused by the host's PHP limits (provider-locked). Expected: a clear refusal | DOC-002, ANN-005 |
| K-17 | Mobile check-out | Check-out does not re-check photo consent and uses the general 8 MB image cap | MOB-008 |
| K-18 | Copy | Backend messages are outside the copy-style check (some contain an em dash) | MKT-009 |
| K-19 | Activation | The typed-code form `/login/activate` is linked from nowhere and says "Invalid activation link" without `?tenant=`, though HR's dialog tells staff to "open the sign-in page and type the code" | ACT-012 |
| K-20 | Employees | An employee with a phone but no email gets no login from "create a login" (silently skipped through the API), and an in-person code is refused for them | EMP-015 |
| K-21 | Activation | A wrong or missing authenticator code when issuing a code is reported on `mfa_code` as "The password is incorrect." | ACT-009 |
| K-22 | Payroll | Finance can open the Reconcile tab but cannot settle (no `leave.adjustBalance` or `attendance.manage`); HR can settle but cannot open the tab. Only the tenant admin can do both | RCN-005 |
| K-23 | Field visits | The Visits date filter may use the UTC date, so a visit between 00:00 and 03:00 EAT shows under the previous day | VIS-005 |
| K-24 | Public site | Since 2026-10-26 every organisation in use is named under Trusted by and listed for search engines unless switched off, including QA Audit, Syntax, QA Isolation and every `uat-` organisation from the moment it is created | LAND-007 |
| K-25 | Console | The operations Services panel shows raw codes (`api`, `reverb`, queue names) and lists `reverb` as `disabled`, while the console home translates names and hides disabled services; "PHP limits" stays English in Amharic. Cosmetic | OPC-006 |
| K-26 | Console | Any failed job, however old, keeps Failed jobs at "Needs a look" until it is retried or dismissed, so production rarely reads "Nothing needs attention" | OPC-006 |
| K-27 | Console | Deleting a cancelled organisation needs only a reason from a signed-in PA with MFA on, no fresh code (owner decision 2026-10-27; Restore undoes it) | CON-P-009 |
| K-28 | Console | "Recurring revenue" leaves out trials and uses each subscription's captured price, not the plan's current price. "Conversion" counts any active organisation with a trial end date as converted | CON-P-011 |
| K-29 | Marketing | Uploading a picture to the hero or a problems row replaces its animation (by design; do not do it on production) | CON-P-013, MKT-010 |
| K-30 | Registered phones | A person who is exempt, or on a listed office network, but still has an active registered phone is asked for the passkey; under "Only the registered phone" dismissing it stops the punch, though the server would accept it | PHN-016 |
| K-31 | Registered phones | "Check and review" and an exemption score 35, an office computer 5; the default review threshold is 40, so alone they never reach Integrity, although the screens say the punches are sent for review | PHN-009, PHN-015, PHN-017 |
| K-32 | Registered phones | Passkeys belong to the platform domain: on a verified custom domain every punch is refused `device-wrong-host`, and the "Open" link may be the same custom domain | PHN-020 |
| K-33 | Registered phones | Office networks are matched against the address PHP sees, with no trusted-proxy setting, so behind a proxy no office address may ever match | PHN-017 |
| K-34 | Registered phones | The Phones list and decisions ignore reach, and HR can approve their own waiting phone | SEC-026, SEC-027 |
| K-35 | Copy | Phone messages promise "a manager will confirm it" though only HR and organisation admins approve; a first phone still waiting is told to "Use your registered phone"; the exemption limit reads "before +92 days" | PHN-005, PHN-007, PHN-015 |
| K-36 | Registered phones | "One phone, one person" is checked per browser: a colleague in a different browser app on the same handset can register | PHN-010 |
| K-37 | Payroll | A lateness deduction is not itemised on the payslip or register CSV; basic is shown already reduced (only the calculation log and the register API's `lateness_cents` show it) | LATE-005 |
| K-38 | Payroll | `paid_on` is checked against today's UTC date, so between 00:00 and 03:00 EAT today's EAT date may be refused | PAID-002 |
| K-39 | Address | A platform operator impersonating an organisation can rename its address (renaming is not on the impersonation block list) | TEN-015 |
| K-40 | Attendance setup | The "no kiosk" check fires only when the kiosk PIN is also required, not whenever the kiosk method is on with no kiosk | SETUP-003 |

## 5. Production facts in one place

| Fact | Value |
|---|---|
| Public site | `https://www.ethr.et` (apex redirects to www) |
| Platform console | `https://admin.ethr.et` |
| Organisation addresses | `https://www.ethr.et/{slug}`, `https://{slug}.ethr.et` when subdomains are on, or a verified custom domain; one canonical address per organisation |
| Health | `/api/v1/health` (30 a minute per IP) |
| Release identity | `/release.json`; tags `vYYYY.MM.DD.N` |
| Hosting | Ethio Telecom shared hosting (Plesk): Apache, PHP 8.3, MariaDB; no shell; 5 GB quota for database, files and backups together |
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
| Leave accrual | 1st of the month, 00:30 | Raises annual balances |
| Holiday roll-forward | 1st of the month, 01:30 | Copies recurring holidays to next year |
| Leave carry-forward | 1 January, 01:00 | Sets carried days |
| Backup | daily 01:00 | `ethr:backup` |
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
| Operations and health | `docs/operations/ON-CALL.md`, `docs/deployment/shared-hosting/health-check.md` |
| Earlier production QA | `docs/audit/QA-PRODUCTION-*.md`, `docs/audit/QA-ATTENDANCE-PRODUCTION-2026-10-12.md` |
| Payroll changes and statutory parameters | `docs/payroll/PAY-CHANGES.md`, `docs/payroll/STATUTORY-PARAMETERS.md` |
| Operations centre | `docs/operations/` (spec and gap list) |
