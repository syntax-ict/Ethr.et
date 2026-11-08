# ETHR production test plan

| | |
|---|---|
| Product | ETHR, multi-tenant HCM SaaS for Ethiopian organisations (attendance, leave, payroll, people, self-service) |
| System under test | Production: `https://www.ethr.et` (apex and organisation addresses), `https://admin.ethr.et` (platform console) |
| Release under test | The release tag being verified, `vYYYY.MM.DD.N`. Read it from `https://www.ethr.et/release.json` before starting |
| Document owner | Product owner (Syntax ICT) |
| Prepared | 2026-10-24, from `main` at `c2f86e234`; updated 2026-10-28 and 2026-10-29 from `main` at `87d12d4d0`, 2026-11-03 for the kiosk upgrade from `main` at `a136364e1`, and 2026-11-08 as the third edition, every spec file brought up to date from `main` at `d4b3524ea4` (section 2.3) |
| Companion documents | [UAT guide](02-UAT-GUIDE.md), E2E specifications 03 to 09, [templates](10-TEMPLATES-AND-REFERENCE.md) |

---

## 1. Purpose

Confirm, on the live system and after a deployment, that ETHR:

1. is the release that was meant to be deployed, and is healthy;
2. does what its users need, for every role, in English and Amharic;
3. keeps each organisation's data invisible to every other organisation;
4. calculates pay, tax, pension, leave balances and attendance statuses correctly under Ethiopian rules;
5. refuses what it must refuse (wrong role, wrong plan, self-approval, rate limits) with a clear message.

The plan is written for a tester who has not worked on ETHR before. It assumes no access to the
server, the database or the source code, because production has none of those for testers: there is
no shell on the host, and the only server-side window is the public health endpoint.

## 2. Scope

### 2.1 In scope

| Area | Specification |
|---|---|
| Release verification and health | [10, section 3](10-TEMPLATES-AND-REFERENCE.md#3-release-smoke-checklist) |
| Sign-in (password, sign-in code by email or SMS, SSO), MFA, sessions, password reset, invitations, in-person activation codes, find my organisation, registration, impersonation | [03](03-E2E-AUTH-TENANCY-ACCESS.md) |
| Organisation addresses (`ethr.et/{slug}`, subdomain, custom domain), renaming the address, tenant statuses | [03](03-E2E-AUTH-TENANCY-ACCESS.md) |
| Roles, permissions, reach, custom roles, plan gates and limits, billing | [03](03-E2E-AUTH-TENANCY-ACCESS.md) |
| Setup wizard, organisation structure, employees, import and export, lifecycle and scheduled personnel changes, documents, profile self-service, recruitment from applicant to hire | [04](04-E2E-PEOPLE-AND-ORGANISATION.md) |
| Attendance (web, mobile, QR, kiosk, manual, import, offline, biometric devices), status rules, corrections, overtime requests, conflicts, integrity flags, risk score and liveness, rules by branch, work sites, setup status, field visits, registered phones, photos, shifts, rotations, default schedules, holidays | [05](05-E2E-ATTENDANCE-SHIFTS-DEVICES.md) |
| Leave, balances, approval chains, batch approvals, employment letters, exit clearance, disciplinary and retirement cases | [06](06-E2E-LEAVE-AND-APPROVALS.md) |
| Payroll calculation and rounding, run lifecycle and payment, attendance reconciliation, lateness deductions, statutory rules by kind of organisation, loans, cost sharing, bank file, register, statutory schedules, payslips, accounting journal | [07](07-E2E-PAYROLL.md) |
| Announcements, news site, organisation landing page and public listing, vacancies, notifications and phone push, employee voice, search, dashboards and Next steps, reports, analytics | [08](08-E2E-COMMUNICATION-INTEGRATIONS-CONSOLE.md) |
| API keys, webhooks, SCIM, SSO settings, data export, audit log, platform console and operations centre, marketing site | [08](08-E2E-COMMUNICATION-INTEGRATIONS-CONSOLE.md) |
| Security, isolation, rate limits, performance, accessibility, localisation, Ethiopian calendar, responsive layout, PWA and offline | [09](09-NON-FUNCTIONAL.md) |

### 2.2 Out of scope

| Item | Why |
|---|---|
| Server configuration, Plesk, DNS, backups, restore rehearsal | Owner and host work, tracked in `docs/deployment/CUTOVER-CHECKLIST.md` |
| Load and stress testing on production | Shared hosting with stock PHP limits and no worker process. A load test can take every customer down. Only the light timing checks in [09](09-NON-FUNCTIONAL.md) are allowed |
| Penetration testing beyond the black-box checks in [09](09-NON-FUNCTIONAL.md) | Needs a separate authorisation from the owner |
| Physical biometric devices | Only if the owner provides a test device registered to the QA organisation |
| Face matching (comparing a selfie with a stored face) | Not built. The live face check before a selfie (liveness) is in scope, [05, section 6a](05-E2E-ATTENDANCE-SHIFTS-DEVICES.md#6a-risk-score-liveness-and-pattern-scans) |
| Tigrinya, Somali, Sidama | Listed as coming soon; they cannot be selected. Afaan Oromoo shipped on 2026-11-02 and **is** in scope ([09, section 6](09-NON-FUNCTIONAL.md#6-localisation-and-the-ethiopian-calendar)) |
| Real payment collection | ETHR raises invoices; payment is confirmed by hand in the console |

### 2.3 Changes since the first edition

Merged on `main` between `c2f86e234` and `87d12d4d0`, plus the kiosk rows at the end. **Check
`/release.json` (S-01) before running these cases: a merged change is not necessarily deployed.** A
case for a change that is not live is recorded as Blocked, not Fail.

**The third edition's rows follow, file by file, up to `d4b3524ea4`.** A change merged after that is
not in this table: list it with `scripts/qa-pack-coverage.sh <release tag>`
([README](README.md#keeping-this-pack-current)) and record each user-visible one as **Not covered** in
the execution log.

| Change | PRs | Cases |
|---|---|---|
| Attendance upgrade: rules by branch, where each employee punches, work sites on a map, setup status, risk score, liveness, field visits, a default schedule from the first minute, phone push and reminders | #412 to #423 | 05 sections 1a to 1c, 6a, 7a, 9a; MOB-010 to MOB-012; PUSH-001 to PUSH-005 |
| Registered phones (passkeys), and managing them | #427, #459 | 05 section 15; ISO-012; SEC-024 to SEC-027 |
| The punch each screen offers (web, QR, kiosk Automatic) | #455 | 05 section 5a |
| Every capture channel pays the same; manual night shifts; imported rows rated | #443 | CAP-001, CAP-002, MAN-002, AIMP-002, PAY-031 |
| Absences report source; the manager card's absence count | #407 | REP-005, ABS-003 |
| Problem JSON for signed-out calls, validation, refused punches, unreadable devices | #406, #408 | SEC-021 to SEC-023 |
| Organisation renames its address | #439 | TEN-015 to TEN-023, ORG-005 |
| In-person activation for staff with no email or phone | #440 | 03 section 5a, EMP-014, EMP-015 |
| Next steps card | #441 | NXT-001 to NXT-006, EMP-016 |
| Recruitment from applicant to hire | #444 | 04 section 9 |
| Attendance-to-payroll reconciliation, missed punches, leave kinds that settle absences | #446 | 07 section 8, COR-008, COR-009, LT-005, LR-016, LR-017 |
| Lateness deductions within Art. 59's one third | #449 | 07 section 9 |
| Exact rounding, statutory rules by kind of organisation, the paid state | #448 | RND-001, 07 sections 10 and 11, corrections in 07 sections 1 to 5 |
| Future-dated personnel actions held until their date | #451 | LIFE-009 to LIFE-013 |
| Console: operations centre, truthful figures, readable audit, platform sign-in lands on the console | #452, #453, #457 | 08 section 9a, CON-P-011, CON-P-016, CON-P-018, CON-P-019, AUD-003 |
| Console deletes with a reason only (no typed address, no authenticator code) | #439, #442 | CON-P-009 |
| Every organisation in use listed on Google and under Trusted by unless it opts out | #438 | LAND-005 to LAND-008, CON-P-008, MKT-006, MKT-007 |
| Sidebar product mark as a platform setting; footer row | #425, #426, #437 | CON-P-020 to CON-P-022, UI-009, A11Y-006 |
| Animated landing stories | #424 | MKT-010 to MKT-012, CON-P-013 |
| web-push 11 | #436 | PUSH-003 |
| One feature gate over the plan (no visible change; switches have no screen yet) | #460 | PLAN-014 to PLAN-018 |
| Health times survive a release's cache clear | #454 | OPC-008 |
| Invitations for many people sent from the queue, with progress, reminders and the Not activated filter; a person with only a phone activated in person | #458 | INV-001 to INV-013, EMP-015, IMP-E-004, IMP-E-014, ACT-008, CON-P-023, ISO-013 |
| Logins for employees brought in from a device, and the hire-date warning | #462 | MIG-001 to MIG-009, UAT-H13 |
| The kiosk's punch answer carries only who punched, not the whole employee record | #516 | KIO-013 |
| The kiosk shows who punched, takes "Not you?", locks its screen (3-second hold to exit), and offers self-service behind the employee's PIN | #540 | KIO-007, KIO-013 to KIO-022 |
| A sound for every kiosk punch result, in the kiosk's language | #541 | KIO-023 |
| Type only your number at the kiosk | #542 | KIO-024, KIO-025 |

**Third edition, stage 2, file 03** (2026-11-08, from `main` at `af68fa495`):

| Change | PRs | Cases |
|---|---|---|
| Sign-in limits per organisation and per phone number; codes and authenticator codes lock after five wrong tries; even sign-in timing; the post-SSO redirect | #486, #492, #632 | AUTH-035 to AUTH-038, MFA-012, MFA-013, PWD-015; AUTH-026, ACT-009, IMP-002, SSO-005 changed |
| Writes from another site's origin are signed out; a stale XSRF cookie is retried once | #632 | SES-008, SES-009, AUTH-036 |
| The sign-in form, its other ways in, and the hero logo | #623, #638, #640 | AUTH-031 to AUTH-034 |
| A department reaches its own units, never another department; dashboards closed below HR | #561 | ACC-017 to ACC-019; ACC-001, ACC-004, ACC-009 changed |
| One role per job at level 70; nobody changes their own pay or enters their own hours; attendance tools follow a role's reach | #488, #490, #624 | ACC-020 to ACC-025; ACC-008 changed |
| Access denied names the role; permissions as a list of names; the organisation's owner, who can hand it on | #478, #625 | ACC-026 to ACC-030; ORG-004, IMP-001 changed |
| An organisation locked out for money can still see and pay its bill | #635 | TEN-024 to TEN-026; TEN-006, TEN-007, PLAN-011 changed |
| Feature controls as an organisation sees them: grants, revokes, platform switches, switches that work or are not offered | #464, #480, #636 | PLAN-019 to PLAN-021, AUTH-039, ORG-008; PLAN-002, PLAN-014 to PLAN-018 rewritten |
| A-Z review items: a trial converts to active, a trial extended from today, dunning | #624 | PLAN-007, PLAN-009, PLAN-012 changed |

**Third edition, stage 2, file 08** (2026-11-08):

| Change | PRs | Cases |
|---|---|---|
| Get help, from an employee to their HR and on to ETHR | #471 | HELP-001 to HELP-006 |
| Dashboards: a department head's today, Organisation today band, deciding requests from the dashboard, the reader's calendar, phones, fewer requests | #561, #569, #571, #574, #575, #578, #581, #584, #591, #594, #597, #633, #467 | DASH-006 to DASH-016; DASH-002, NXT-003 changed |
| Charts and printing | #584, #597, #613, #614, #615, #617, #619 | CHT-001 to CHT-004, PRN-001, PRN-002 |
| Reports and analytics: monthly timesheet, reports by organisation type, figures that count what they say, period comparison | #472, #474, #477, #479 | REP-006 to REP-009, ANA-002, ANA-003 |
| Console feature controls (the organisation's side is in 03) | #464, #480, #636 | FC-001 to FC-004; CON-P-003 changed |
| Backups off-host, disk watch, mail budget | #509, #517, #530, #532, #534 | BAK-001 to BAK-006; OPC-001, OPC-005, CON-P-011 changed |
| Release status reads pending migrations | #620 | OPC-009 |
| Console: an organisation's administrators, tenant journey, A-Z review corrections | #624, #625, #643 | CON-P-024 to CON-P-027, NXT-007; CON-P-001, CON-P-002, CON-P-005, CON-P-014, CON-P-016 changed |
| Notices in the reader's language and only to active people; vacancies on the public site and not over-filled; announcements fit | #466, #482, #510, #624 | NOT-006, NOT-007, VAC-004, ANN-009; NOT-005, VAC-001 changed |
| Public site: what the product does, call links, feed out of shared caches | #507, #519, #632 | MKT-013, MKT-014, LAND-009; MKT-006, NEWS-003 changed |
| Webhooks: delivery id signed, held deliveries expire, connect to the checked address | #489, #624 | WH-008, WH-009; WH-003, KEY-006 changed |

**Third edition, stage 2, file 05** (2026-11-08). #646 merged after the rest; check S-01 before running its cases:

| Change | PRs | Cases |
|---|---|---|
| A single-use, short-lived QR on the kiosk screen | #641 | KIO-026 to KIO-028; KIO-010, PHN-007 changed |
| One record a day across every method; no duplicate departure | #644 | PUNCH-007, PUNCH-009, AIMP-004; ATT-006, AIMP-002 changed |
| Every punch method names the day type and what the punch came to; a second check-in answers "Already checked in" instead of opening a conflict | #646 | MOB-013, PUNCH-008, PUNCH-010; ATT-001, ATT-002, ATT-004, ATT-005, ATT-007, KIO-003, CON-001, MOB-011 changed |
| ADMS terminals report who is enrolled, and belong to the organisation whose address they call | #461, #498 | DEV-014, DEV-015 |
| The attendance page title says whose records it shows; times in the reader's clock | #511, #508, #512, #514 | ATT-014, ATT-015; MOB-012, SHF-012 changed |
| A-Z review: a required geofence for QR and web too, a retry key answers only its own person, check-out photo consent and size, corrections, offline order, devices | #624 | QR-009, ATT-013, COR-010, OFF-010, DEV-016; ATT-011, MOB-003, MOB-007, MOB-008 changed |
| Attendance refusals by role and reach (cases in 03) | #490 | ACC-023, ACC-024; PHN-001 changed |

**Third edition, stage 2, file 09** (2026-11-08):

| Change | PRs | Cases |
|---|---|---|
| Afaan Oromoo end to end: the interface, the platform's labels and plans, an organisation's portal | #546, #549, #552, #553 | L10N-010 to L10N-013; L10N-009 changed |
| One word for attendance and a payroll run; the chosen language follows the user; a switch refetches server text; one Language and calendar menu | #513, #485, #478, #484, #539 | L10N-014 to L10N-017 |
| Ethiopian time of day: a display preference, on every screen, in notices and in files | #508, #512, #514, #520 | L10N-018 to L10N-020; L10N-008 changed |
| The installed app opens offline; pay data never kept offline, a 401 empties the cache; offline copies expire after a week; the offline page | #483, #494, #496, #466, #469 | PWA-006 to PWA-010; PWA-001, PWA-002, SEC-010 changed |
| Sign-in limits: the numbers (the flow is in 03) | #486, #632 | RL-012 to RL-015; RL-001 to RL-003 changed |
| Cron and kiosk tokens from headers and bodies only; outbound connections only to the checked address; headers and framing | #497, #489, #495, #632 | SEC-028, SEC-029; SEC-003, SEC-006, SEC-011, SEC-018, RL-009 changed |
| Every route that names a record refuses another organisation's admin (automated, L0) | #645 | Section 2 note |
| Focus after the skip link, a 12 px floor | #588 | A11Y-007, A11Y-008 |
| Lighter pages: no Sentry without a DSN, no full dictionary on the 404 or the dashboard, charts only for those shown them, pre-compressed files | #487, #491, #500, #518, #538 | PERF-008 to PERF-012 |

**Third edition, stage 2, file 07** (2026-11-08):

| Change | PRs | Cases |
|---|---|---|
| An organisation sets its own working days per month (22 by default) | #642 | PAY-032 to PAY-034; sections 1.3, 1.4, 9, 11 and STAT-005 changed |
| Sick leave pays as Art. 85 sets out: a month in full, two at half, three unpaid | #637 | SICK-001 to SICK-004 (section 12); PAY-012 changed |
| A run too big for one request finishes across several | #639 | PAY-035 |
| A-Z review: overtime at the contract's hourly rate (golden G8), allowances prorated by days employed, the journal's new accounts, the approved-period check | #624 | PAY-036 to PAY-038, OUT-012; PAY-007, OUT-008, OUT-009 changed |
| Payslips in the reader's language and calendar | #520 | OUT-011; OUT-004, OUT-006 changed |
| Finance and its own deductions; pay-setting contracts | #490, #488 | LOAN-009; PAY-020 changed (the role rules are ACC-020, ACC-022 in 03) |
| A run reads what is the same for the organisation once (figures must not move) | #625 | PAY-011 changed |

**Third edition, stage 2, file 06** (2026-11-08):

| Change | PRs | Cases |
|---|---|---|
| Each organisation chooses its leave year; Ethiopian by default unless leave is already recorded | #521, #536 | LB-009 to LB-011 |
| HR cancels approved leave or ends it early, and the balance moves back | #634 | LD-010 to LD-015; LR-012 changed |
| Sick leave under Art. 85, the leave side (the pay side is 07 section 12) | #637 | LR-018, LR-019, LB-012; LT-001 changed |
| Deciding requests from the dashboard, the approval engine's side (the dashboard is DASH-009) | #578 | LD-016, LD-017; LD-006 changed |
| A-Z review: notice and length messages, working-day counting, reminders in the reader's language | #624 | LR-020, LR-021; LR-009, LR-010, LD-005, CH-010 changed |

**Third edition, stage 2, file 04** (2026-11-08). Wizard cases run on a fresh `uat-setup-<date>` organisation:

| Change | PRs | Cases |
|---|---|---|
| The wizard offers only the kind of organisation chosen at sign-up | #499 | SET-008; SET-001 changed |
| Bring people in from a spreadsheet inside the wizard: Excel paste, CSV, header detection | #501 | SET-009 to SET-011; SET-005a changed |
| The People step counts existing staff, reads Ethiopian hire dates, warns about missing ones, and its example follows the calendar | #505, #506 | SET-012 to SET-014 |
| Public listing, admin name, pasted hire dates, honest readiness | #481 | SET-016, SET-017; SET-006 changed |
| A-Z review: Ethiopian dates in the import, People step limits, pay data in imports and profile reviews, structure deletions, signed file links | #624, #625 | SET-015, IMP-E-015, IMP-E-016, PRO-011, DOC-011; ORG-S-004, EMP-005, EMP-008, REC-009, DOC-001, PRO-007 changed |

## 3. Approach

Testing runs in four layers, cheapest first. A layer that fails stops the next one.

| Layer | What | Who | Time |
|---|---|---|---|
| **L0 Pre-production** | CI must be green on the release commit: `./scripts/gates.sh` (backend, frontend, docs, API contract) and the MariaDB gate. The automated Playwright suite (`src/e2e/`) may also be pointed at the local production rehearsal (`scripts/local-production/up.sh`, port 8081) | Developer | Before deploy |
| **L1 Smoke** | Release identity, health, redirects, sign-in per role, one read per module. Read-only | Tester | 30 min, after every deploy |
| **L2 Functional E2E** | The cases in 03 to 08, in the QA organisations | Tester | 3 to 5 days for a full pass; changed areas only for a small release |
| **L3 Non-functional** | Security, isolation, accessibility, localisation, responsive, offline, timings ([09](09-NON-FUNCTIONAL.md)) | Tester | 1 to 2 days |
| **L4 UAT** | Business users run the persona scenarios in [02](02-UAT-GUIDE.md) and sign off | Owner and named business users | 1 to 2 days |

**Regression selection for a small release.** Run L1 in full. Then run every P1 case of every module
the release notes mention, plus all of [09, section 2](09-NON-FUNCTIONAL.md#2-tenant-isolation) (tenant
isolation), which is run on every release whatever changed.

**Automated support.** The Playwright suite in `src/e2e/` can run against production only with
`BASE_URL`, `API_URL`, `PLATFORM_BASE_URL` and the role accounts overridden to QA organisation
accounts (`src/e2e/helpers.ts`). Its defaults are demo accounts that do not exist on production.
Run only read-only specs there (`auth`, `marketing`, `accessibility`, `responsive`,
`theme-switching`, `amharic-layout`, `offline`) unless the owner agrees otherwise, and run them with
`workers: 1` (the default) so rate limits are not tripped.

## 4. Environments

| Environment | Address | Use |
|---|---|---|
| Production, public site | `https://www.ethr.et` (the apex `https://ethr.et` redirects here) | Marketing pages, `/news`, organisation entry pages `/{slug}` |
| Production, organisation address | One of: `https://www.ethr.et/{slug}`, `https://{slug}.ethr.et`, or a verified custom domain. The console shows which one each organisation uses | Every tenant test |
| Production, platform console | `https://admin.ethr.et` | Console tests, platform operator only |
| Production, API | `https://<organisation address>/api/v1/...` | API-level checks with a browser's developer tools or `curl` |
| Health | `GET https://www.ethr.et/api/v1/health` | Health of database, cache, queue, storage, scheduler and mail |
| Release identity | `GET https://www.ethr.et/release.json`, or `scripts/release-status.sh https://www.ethr.et` from a checkout | Which commit is live |
| Local production rehearsal | `http://localhost:8081` (`scripts/local-production/up.sh`) | Destructive or high-volume cases that are not allowed on production |

**Facts about production that change expected results:**

- **Uploads above about 2 MB fail on production.** The host's PHP limits are 2 MB per file and 8 MB
  per request, and cannot be raised from the panel. The application knows the limit: forms shrink
  photos in the browser and refuse larger files with a message naming both sizes. A file over the
  limit being **refused clearly** is a pass; a file being refused with a raw server error, or
  appearing to save and then vanishing, is a defect.
- **There is no background worker.** Queued work (payroll calculation, emails, notifications,
  reports, device pulls) runs when the scheduler caller fires (every 5 minutes) or when traffic
  drains the queue. Allow **up to 10 minutes** before declaring an asynchronous result missing.
  `/api/v1/health` shows the last drain under `queue_detail`.
- **There is no live push.** Notifications appear by polling (about every 30 seconds). Reload before
  declaring one missing.
- **Mail works through a pinned certificate.** `/api/v1/health` shows `mail_detail`. A missing email
  is first checked there.
- **SMS** goes through the gateway configured in the console. Every SMS is real and billed; there is a
  daily cap.

## 5. Test accounts and personas

The owner creates these in the designated QA organisation before testing and hands the credentials
over privately. They never appear in this pack, a defect or a log.

| Persona | Role | Linked employee | Used for |
|---|---|---|---|
| **TA** | `tenant_admin` | Yes (employee record linked) | Settings, devices, payroll approval, API keys, webhooks, billing, deletions |
| **TA2** | `tenant_admin` | Yes | Second approver: payroll maker-checker, self-approval refusals |
| **HR** | `hr_admin` | Yes | People, attendance management, leave types, announcements, user invitations |
| **FIN** | `finance_admin` | Yes | Payroll processing, loans, cost sharing |
| **DA** | `dept_admin` | Yes, in department D1 | Department reach |
| **SUP** | `supervisor` | Yes; EMP and EMP2 report to SUP | Team approvals, team attendance |
| **EMP** | `employee` | Yes, reports to SUP, department D1, branch B1 | Self-service |
| **EMP2** | `employee` | Yes, reports to SUP, department D2 | Reach and isolation within the organisation |
| **EMP3** | `employee` | Yes, no supervisor, department D2 | Supervisor reach negative tests |
| **PA** | `super_admin` on the platform | No | Platform console. Operated by the owner or a delegate with console MFA |

Everyone with an admin role must have an authenticator app ready (MFA), and PA must have MFA set
up, because the console refuses changes without it.

### 5.1 Organisations

| Organisation | Purpose | Allowed |
|---|---|---|
| **QA Audit** (`qa-audit`) | Primary write organisation for this plan | Read and write |
| **Syntax** (`syntax`) | Standing QA organisation used for post-deploy checks | Read and write, coordinate with the owner, it holds standing data |
| **QA Isolation** (a second organisation, registered for this plan with a `uat-` prefix, for example `uat-iso-2610`) | The "other tenant" for isolation tests, so that no real customer is ever touched | Read and write; delete it through the console when testing ends |
| Any other organisation | Real customers | Public pages only (`/{slug}` entry page, `/news`). **Never sign in or change anything** |

The plan's tenant isolation cases ([09, section 2](09-NON-FUNCTIONAL.md#2-tenant-isolation)) are always
run between QA Audit and QA Isolation, never against a customer.

### 5.2 Plan (subscription) states needed

Plan gates are tested by changing the QA Isolation organisation's plan, not QA Audit's, so that the
main data set is not affected. A trial organisation has every feature, so plan-gate refusals are
only visible once an organisation is on a paid or free plan and out of trial. The console operator
(PA) changes plans and statuses on request.

## 6. Test data

Set up once in QA Audit, before the functional pass ([04, section 1](04-E2E-PEOPLE-AND-ORGANISATION.md)
builds it step by step):

| Item | Value |
|---|---|
| Branches | B1 "QA Head Office" with coordinates and a 200 m geofence; B2 "QA Branch 2" |
| Departments | D1 "QA Operations", D2 "QA Finance"; DA is in D1 |
| Positions, grades | P1 "QA Officer"; grade G1 with salary steps 1 to 3 |
| Shifts | S1 "QA Day" 08:30 to 17:30, grace 15, early departure 15, Monday to Friday; S2 "QA Night" 22:00 to 06:00 crossing midnight |
| Leave types | The defaults from the setup wizard (annual 16 days, sick 30, maternity 120, paternity 3 and so on) |
| Holidays | Auto-detected for the current year |
| Employees | The persona employees above, plus E-PAY-1 to E-PAY-8 with the golden-payslip salaries in [07, section 2](07-E2E-PAYROLL.md#2-golden-payslips); E-REC-1 (basic 6,600.00) and E-LATE-1 to E-LATE-3 (basic 8,800.00) for [07, sections 8 and 9](07-E2E-PAYROLL.md#8-attendance-reconciliation-before-payroll); E-NC, with no email and no phone (EMP-014); E-PH, with a phone and no email (EMP-015) |
| Work site | "QA-Site North" at `9.0300, 38.7400`, radius 200 m, open to EMP2 and D1 (PLACE-001) |
| Test files | A CSV employee import of 5 rows; an attendance import of 5 rows; a 1.4 MB JPEG; a 2.5 MB PDF; a 3 MB JPEG; a `.exe` renamed to `.pdf`; a 960 px and a 600 px wide JPEG; an Amharic CSV saved as UTF-8 with a byte order mark |

File 03 needs four more items (a login shared across QA Audit and QA Isolation, a sub-unit under each
of D1 and D2, MFA on EMP3, a `uat-` organisation holding an unpaid invoice); its *Extra test data*
table lists them with the cases that use them. File 07 adds E-PAY-9 (golden G8), E-PAY-10, E-SICK-1 and
E-SICK-2 in its own *Extra test data* table, and file 04 a fresh `uat-setup-<date>` organisation, spreadsheet
people lists and a custom role "QA Pay-less HR" in its.

**Naming rule.** Everything a tester creates carries the prefix `QA-` (people, branches, announcements)
or `uat-` (organisations, slugs), so it can be told apart and cleaned up.

**Money figures.** Payroll in QA Audit produces real-looking figures. Mark every run "QA" in its
notes, and never send its bank file anywhere.

## 7. Entry and exit criteria

### 7.1 Entry (before L1 starts)

- The release is tagged and `release.json` on production names the expected commit.
- The release steps for that release have been run (migrate, clear, optimize, and seeders when the
  release notes say so). A new route answering 404 while old routes answer 401 means they did not
  run; report it to the owner before going further.
- `/api/v1/health` reports healthy, `queue_detail` shows a scheduler heartbeat less than 15
  minutes old, and `schema_detail.pending_migrations` is `0`.
- The QA accounts and organisations in section 5 exist and the tester has their credentials.
- CI was green on the release commit (L0).

### 7.2 Exit (release accepted)

- L1 smoke: 100 percent pass.
- No open **S1** defect. No open **S2** defect without a written owner decision.
- Every P1 case of the changed modules passed; overall P1 pass rate 100 percent, P2 at least 95
  percent, with every failure logged.
- Tenant isolation section passed in full.
- UAT signed by the owner and at least one business user per persona that the release touches
  ([02, section 9](02-UAT-GUIDE.md#9-sign-off)).
- Test data cleaned up (section 12).

### 7.3 Suspension and resumption

Stop testing and tell the owner at once when:

- data from a real customer organisation is visible anywhere in a QA session (S1);
- `/api/v1/health` reports a core service `unhealthy` or the site answers 500 everywhere;
- payroll figures are wrong in a way that could reach a real payslip;
- a test caused messages to be sent to real people who are not testers.

Resume when the owner confirms the cause is understood. Re-run L1 first.

## 8. Severity and priority

| Severity | Definition | Examples |
|---|---|---|
| **S1 Critical** | Data of one organisation visible to another; money calculated wrongly; data loss; sign-in impossible for a role; security control missing | Payslip net pay wrong; another tenant's employee found by search; MFA can be skipped |
| **S2 Major** | A main flow fails or gives a wrong result with no reasonable workaround | Leave approval does not update the balance; attendance status wrong; import silently skips rows |
| **S3 Minor** | A flow works with a workaround, or a secondary feature fails | Filter does not apply; export column misnamed; raw translation key shown |
| **S4 Cosmetic** | Look and wording | Misalignment; truncated Amharic label; dark-mode contrast |

Priority (P1 to P3) on a test case says how important it is to run. Severity on a defect says how
bad the failure is. A P3 case can find an S1 defect.

## 9. Production safety rules

These are not optional. A breach of rule 1 or 2 is itself an incident.

1. **Write only in QA Audit, Syntax and the `uat-` organisations.** Before every write, read the
   organisation name in the header.
2. **Never sign in to, impersonate or change a customer organisation.** PA must not use
   impersonation on any organisation other than the QA ones.
3. **Secrets stay out of everything you write.** No passwords, tokens, API keys, webhook secrets, kiosk
   tokens, MFA codes or reset links in defects, logs, screenshots or chat. Blur them in screenshots.
4. **Outside effects (Mode X) need the owner's go-ahead for that session:** SMS sends and gateway
   tests, emails to addresses outside the test team, webhook tests to outside URLs, invoices,
   data exports, tenant delete and restore, backups.
5. **Use only addresses and phone numbers the test team controls** for invitations, sign-in codes,
   password resets, digests and scheduled reports.
6. **Mind the rate limits.** Five failed sign-ins in a minute for one identifier, at one organisation,
   from one address, block it until a minute after the first failure; ten in fifteen minutes lock it
   until fifteen minutes after the first. A phone number counts once however it is written. Five
   wrong authenticator codes or sign-in codes lock that account for fifteen minutes from the first,
   from any address ([09, section 3](09-NON-FUNCTIONAL.md#3-rate-limits)). Two payroll runs per hour per organisation. Five imports per
   hour per organisation. Plan negative tests so they do not lock the accounts you need next.
7. **Do not try to break the host.** No load tests, no scanners, no large-file floods. Performance
   checks are single requests.
8. **Never approve a payroll run, issue a letter or close a case for a real employee.** QA Audit
   holds only test people.
9. **Leave what you find.** Do not delete production data you did not create, including other
   testers' data, without asking.
10. **The host's firewall bans your address after a few refused requests.** A request it refuses
    (a secret path such as `/.env`, or a `POST` with no body) gets an HTML 403 from the server, and
    a handful of those within minutes closes ports 80 and 443 to your address for about ten minutes
    (measured 2026-10-09, and twice in the 2026-10-28 pass). Everyone behind the same office address
    is locked out with you. Send every `POST` with a body (`--data ''`), run the secret-path probes
    (S-05, SEC-004) at most three in ten minutes, and when the site stops answering, check it from
    outside (for example `check-host.net`) before recording an outage. A tool that fetches from
    your own machine, including Claude Code's web fetch, is not an outside check.

## 10. Defect management

- Log every failure in the project's issue tracker (GitHub Issues on `syntax-ict/Ethr.et`, label
  `qa-production`) with the template in [10, section 1](10-TEMPLATES-AND-REFERENCE.md#1-defect-report-template).
- One defect per root symptom. Quote the case ID, the release tag, the organisation, the persona, the
  language and the time in EAT (UTC+3).
- Attach the API response for an API-visible failure: the status, and the problem JSON body
  (`type`, `title`, `status`, `detail`). Remove cookies and tokens first.
- S1 defects are also reported to the owner directly, at once, not only in the tracker.
- A fix is verified on production after the next release, with the original case, and the defect is
  closed only then.

## 11. Schedule (full pass)

| Day | Work |
|---|---|
| 0 | Entry checks, account handover, test data set-up ([04, section 1](04-E2E-PEOPLE-AND-ORGANISATION.md)) |
| 1 | L1 smoke; 03 (auth, tenancy, access) |
| 2 | 04 (people), 06 (leave and approvals) |
| 3 | 05 (attendance), started on day 1 so there is real attendance history |
| 4 | 07 (payroll), using the attendance and leave from days 1 to 3 |
| 5 | 08 (communication, integrations, console); 09 (non-functional) |
| 6 | Re-tests, UAT with business users ([02](02-UAT-GUIDE.md)), sign-off, clean-up |

Attendance and leave need time to pass: start check-ins on day 1 so that the payroll period on day 4
has worked days, lateness, an absence and an approved leave to calculate.

## 12. Clean-up

At the end of the pass:

- Void QA payroll runs that will not be kept, with the reason "QA".
- End or cancel test loans and cost-sharing obligations.
- Revoke test API keys, delete test webhooks, deactivate test kiosk sessions.
- Unpublish test announcements and vacancies, or mark them internal.
- Delete the `uat-` organisations through the console (cancel, then delete with a reason; no typed
  address or authenticator code is asked).
- Set "Registered phone" back to Off, delete the `ethr.et` passkeys from every test phone's password
  manager, and end test phone exemptions (05 section 15).
- Set reconciliation to Off and lateness deductions off (07 sections 8 and 9); remove branch rules and
  work sites made for the pass; set test employees back to "At their branch".
- Cancel scheduled personnel changes left pending (LIFE-011); withdraw live activation codes (ACT-007).
- Keep QA Audit, Syntax and QA Isolation off "Trusted by" and out of search (LAND-007).
- Withdraw photo consent on test accounts if the photo tests are finished.
- Record what was left in QA Audit on purpose, in the execution log.

## 13. Deliverables

| Deliverable | Where |
|---|---|
| Execution log (every case: pass, fail, blocked, not run) | [10, section 2](10-TEMPLATES-AND-REFERENCE.md#2-execution-log) format, one file per release |
| Defects | Issue tracker, label `qa-production` |
| Test summary report | `docs/audit/QA-PRODUCTION-YYYY-MM-DD.md`, in the format of the earlier reports in that folder: method, what passed, findings with severity, cause where known |
| UAT sign-off | [02, section 9](02-UAT-GUIDE.md#9-sign-off), signed copy kept by the owner |

## 14. Risks

| Risk | Effect | Mitigation |
|---|---|---|
| A test writes into a customer organisation | Data damage, trust | Rule 9.1; read the header before writing; isolation tests only between QA organisations |
| Queue delay mistaken for a defect | False defects | Wait up to 10 minutes; read `queue_detail` in `/health` |
| Upload limit on the host | Document and cover tests fail | Expect a clear refusal above 2 MB, not a failure |
| Rate limits lock test accounts | Lost time | Run negative sign-in tests last, on a spare account |
| Payroll figures differ from the accountant's | Wrong pay | Golden payslips in [07](07-E2E-PAYROLL.md); tax ladder confirmed by a tax adviser before the first real run (owner action) |
| MFA devices unavailable | Admin cases blocked | Admins enrol on day 0; the tenant admin can reset MFA for a test account |
| The release steps were not run | New features 404 | Entry criterion 7.1 |
| A paid run cannot be voided | Its period can never be run again in QA Audit | Record payment only on the last run of a pass (PAID-001), and use new periods next time |
| QA organisations are public by default | QA names under "Trusted by" and in search results | LAND-007 before the pass |
| The bulk resend emails every invited login | Invitations reach people outside the team | Run INV-002 on QA Audit only, after INV-001 shows only team addresses |
| A merged change is not yet deployed | Cases fail for a feature that is not live | Check `/release.json` against section 2.3 first; record Blocked |
