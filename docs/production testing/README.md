# ETHR production testing pack

This folder is the complete hand-over for a test engineer verifying **ETHR on production**
(`https://www.ethr.et`, platform console `https://admin.ethr.et`) after a release. It covers the test plan,
the user acceptance test (UAT) guide and the end-to-end (E2E) test specification.

Written 2026-10-24 from the code on `main` (`c2f86e234`) and the product documents, and brought up
to date on 2026-10-29 from `main` at `87d12d4d0` (PRs #406 to #462; the list is in
[01, section 2.3](01-TEST-PLAN.md#23-changes-since-the-first-edition)). The figures
here (limits, rates, messages) were read from the source, with file references, so a tester can
tell a real defect from a misunderstanding. **When the product and this pack disagree, record a
defect against one or the other and say which.** Do not silently adjust the expectation.

## Read in this order

| # | Document | Who it is for | What it gives you |
|---|---|---|---|
| 1 | [01-TEST-PLAN.md](01-TEST-PLAN.md) | Test lead, owner | Scope, environments, accounts, production safety rules, entry and exit criteria, severity, schedule, reporting |
| 2 | [02-UAT-GUIDE.md](02-UAT-GUIDE.md) | Business users, HR, finance, the owner | Persona-based acceptance scenarios in plain language, with a sign-off sheet |
| 3 | [03-E2E-AUTH-TENANCY-ACCESS.md](03-E2E-AUTH-TENANCY-ACCESS.md) | Test engineer | Sign-in, MFA, sessions, in-person activation, tenancy, addresses and renaming, roles and permissions, plans and billing |
| 4 | [04-E2E-PEOPLE-AND-ORGANISATION.md](04-E2E-PEOPLE-AND-ORGANISATION.md) | Test engineer | Setup wizard, organisation structure, employees, import and export, lifecycle and scheduled changes, self-service profile, recruitment |
| 5 | [05-E2E-ATTENDANCE-SHIFTS-DEVICES.md](05-E2E-ATTENDANCE-SHIFTS-DEVICES.md) | Test engineer | Every punch method, rules by branch, work sites, setup status, status rules, corrections and missed punches, overtime, conflicts, integrity, risk score and liveness, field visits, photos, shifts, holidays, kiosks, devices, registered phones |
| 6 | [06-E2E-LEAVE-AND-APPROVALS.md](06-E2E-LEAVE-AND-APPROVALS.md) | Test engineer | Leave types, balances, requests, the approval engine, letters, exit clearance, cases |
| 7 | [07-E2E-PAYROLL.md](07-E2E-PAYROLL.md) | Test engineer, finance | Tax, pension, overtime, allowances, rounding, run lifecycle and payment, loans, cost sharing, exports, golden payslips, reconciliation, lateness deductions, statutory rules by kind of organisation |
| 8 | [08-E2E-COMMUNICATION-INTEGRATIONS-CONSOLE.md](08-E2E-COMMUNICATION-INTEGRATIONS-CONSOLE.md) | Test engineer | Announcements, news, landing page and public listing, notifications, voice, search, Next steps, reports, API keys, webhooks, SCIM, SSO, platform console and operations centre, marketing site |
| 9 | [09-NON-FUNCTIONAL.md](09-NON-FUNCTIONAL.md) | Test engineer | Security, tenant isolation, rate limits, performance, accessibility, Amharic and the Ethiopian calendar, responsive, PWA and offline, browsers |
| 10 | [10-TEMPLATES-AND-REFERENCE.md](10-TEMPLATES-AND-REFERENCE.md) | Everyone | Defect report template, execution log, release smoke checklist, known issues to verify, glossary |

## How a test case is written

Every case in documents 03 to 09 has the same columns:

| Column | Meaning |
|---|---|
| **ID** | Stable identifier, for example `ATT-031`. Quote it in every defect and log entry |
| **P** | Priority. **P1** blocks release sign-off, **P2** must pass before UAT sign-off, **P3** is checked when time allows |
| **Mode** | **R** read-only, safe in any organisation. **W** writes data, allowed only in the designated QA organisations. **X** has an outside effect (a real SMS, email, webhook call, invoice or money figure someone may act on); coordinate with the owner first |
| **Steps** | What to do, in order |
| **Expected** | What must happen. Quoted messages are the English text; the Amharic text must appear when the interface is in Amharic |

Personas used in the steps (TA, HR, FIN, DA, SUP, EMP, EMP2, PA) are defined in
[01-TEST-PLAN.md, section 5](01-TEST-PLAN.md#5-test-accounts-and-personas).

## The three rules that matter most on production

1. **Write only inside the QA organisations** named in the test plan. Every other organisation on
   production is a real customer. Reading their public sign-in page is fine; signing in, creating,
   editing or deleting anything is not.
2. **Never type or record a real password, token or secret in a test log, defect or screenshot.**
   Accounts and secrets come from the owner (see `docs/operations/CUSTODY.md`).
3. **Stop and tell the owner** before anything marked **X**, and immediately if you see another
   organisation's data, which is a P1 security defect (section 9 of the test plan).
