# Employee lifecycle

What ETHR does across an employee's working life, from an approved position to the certificate after they leave: what is built, what is missing, the ordered plan to close the gaps, and the rules every lifecycle feature follows.

*Written 2026-10-27 from the code, not from earlier documents. The legal sources are in [`EMPLOYMENT-LAW-REGISTER.md`](EMPLOYMENT-LAW-REGISTER.md); the item-by-item register is [`audit/BLUEPRINT-ASSESSMENT-2026-10-20.md`](audit/BLUEPRINT-ASSESSMENT-2026-10-20.md) §L and §R, updated to match.*

> **Product direction.** `PRODUCT_VISION.md` §20 and blueprint row C4 deferred applicant tracking, performance and learning to v2.0. On 2026-10-27 the owner asked for the full lifecycle, so this document plans all of it. The deferral is superseded for recruitment, which is now built; performance and learning stay in the plan below, unbuilt.

## Capability matrix

| Lifecycle capability | What exists (evidence) | Gap | Action |
|---|---|---|---|
| Position planning | Departments, branches, positions, grades and salary steps (`OrganizationStructureTest`, `GradeSalaryStepTest`) | No establishment or headcount per position (blueprint B6); positions not linked to grades (B5); no requisition | Extend: establishment on `positions`, then requisitions (plan step 6) |
| Recruitment | Vacancies with internal/public visibility, shown on the organisation's sign-in page (`VacancyController`, `VacancyTest`). **New 2026-10-27:** applicants, stages, interview records, offers with second approval, duplicate warnings, erasure (`JobApplicationController`, `RecruitmentTest`) | No public apply form; no requisition approval; no rejection message to the applicant; reads not audited | Built the core; the rest is plan steps 2, 6 and 9 |
| Hiring and onboarding | **New 2026-10-27:** hire from an approved offer creates one employee, with department and branch from the vacancy, salary from the offer and a numbered employee code (`RecruitmentService::hire`). In-person activation for staff with no email or phone (#440) | No new-hire checklist (blueprint L2: `OnboardingProgress` is organisation setup, not a person's); no pension registration task | Add a new-hire checklist (plan step 3) |
| Contracts and probation | Contracts with type, dates, salary, renewal chain and an expiring list (`EmployeeContractService`, `EmployeeContractTest`). Probation status and end date, overdue flag. Status transitions validated (`EmployeeStatus::allowedTransitions`) | Hire does not start a contract; no written particulars or 15-day letter (Labour Proc. Art. 7); probation has no confirmation decision with evidence | Plan steps 2 and 4 |
| Transfers and promotions | Personnel actions for promotion, transfer, acting, secondment and others, with before/after changes (`PersonnelActionService`, `PersonnelActionTest`) | **A future-dated action changes the employee record the moment it is recorded** (`PersonnelActionService::record` applies `$apply` immediately, whatever `effective_date` says), so payroll can pay a promotion before it starts. No approval step | Fix the effective-date defect first (plan step 1b) |
| Performance and training | Education records (`EducationController`); recognition and pulse in the voice module | No review cycles, objectives, training or skills (blueprint R9–R15) | Plan steps 7 and 8 |
| Discipline and grievances | Disciplinary cases from offence to appeal, reached one employee at a time (`DisciplinaryCaseService`, `DisciplinaryCaseController::reach`, blueprint D5 closed) | No framework timers (Labour Proc. Art. 27 30-working-day lapse, Civil Servants Proc. Art. 102); no grievance procedure (the voice module's concerns are not one); case files sit with ordinary employee access rules | Plan step 5 |
| Separation and clearance | Resignation, termination and retirement transitions; retirement cases; exit clearance checklist started on any leaving transition (`StartExitClearance`, `ExitClearanceTest`); payroll includes a leaver to the termination date (blueprint D3) | No notice or reason rules per framework; **no code found that ends a leaver's sign-in** (blueprint L9); no certificate tied to exit | Plan step 4 |
| Documents and audit | Employee documents with magic-byte checks; employment letters on request (`EmploymentLetters`); append-only `audit_log` | Letters are not tied to exit; no read audit on sensitive records (PDPP Art. 46) | Plan steps 4 and 9 |
| Reporting and permissions | Permission model with custom roles and org scope (`docs/PERMISSIONS.md`); reports engine | No lifecycle reports (time to hire, offers pending approval, probation ending) | Plan step 9 |

## What the 2026-10-27 slice built

**Recruitment to hire**, reusing what existed: vacancies (the parent), the employee record and its numbering (`EmployeeNumbering::create`, the same path the Employees form uses), the plan limit check, the audit log, the webhook `employee.created`, the permission system and the Vacancies page.

### The flow

```
applied ─▶ screening ─▶ interview ─▶ offer ─(approved by someone else)─▶ hired
   │           │            │          │
   └───────────┴────────────┴──────────┴──▶ rejected / withdrawn (reason required)
```

- **Every move is validated** against `ApplicationStage::allowedMoves()`. Skipping a stage, reopening a closed application, or setting `hired` by hand is refused with 422. `hired` is reachable only through the hire endpoint.
- **An offer needs a start date** and may carry a monthly salary (setting it needs `employee.updateFinancial`; seeing it needs `employee.viewFinancial`) and terms. Making an offer again replaces it and clears any approval.
- **The person who made the offer cannot approve it**, whatever role they hold. Approval needs `recruitment.approve`.
- **Hiring needs an approved offer** and `employee.create`, and creates **exactly one** employee: the application row is locked, a repeat returns the same employee with `meta.was_duplicate: true` and status 200, and `job_applications.employee_id` is unique.
- **Hiring stops** when the vacancy's positions are all filled, or when a **current** employee has the same e-mail or phone. A former employee can be hired again.
- **Probation is HR's choice at hire**, with an end date after the start. No length is assumed, because it differs by framework (60 working days for private employers, 6 months for federal civil servants; see the register).
- **Possible duplicates** (other applications and employees with the same e-mail or phone) are shown, never merged.
- **Erasure:** a rejected or withdrawn application's name, contact details and every note can be erased. Stages and dates stay, so the selection record still adds up.
- **Nothing decides automatically.** Interview scores are a panel's written assessment, not an input to any rule.

### API

| Method and path | Needs |
|---|---|
| `GET /vacancies/{vacancy}/applications` | `recruitment.manage` |
| `POST /vacancies/{vacancy}/applications` | `recruitment.manage` |
| `GET /job-applications/{application}` | `recruitment.manage` |
| `POST /job-applications/{application}/stage` | `recruitment.manage` (+ `employee.updateFinancial` to set a salary) |
| `POST /job-applications/{application}/events` (note or interview) | `recruitment.manage` |
| `POST /job-applications/{application}/approve-offer` | `recruitment.manage` + `recruitment.approve`, and not the offer's maker |
| `POST /job-applications/{application}/hire` | `recruitment.manage` + `employee.create` |
| `POST /job-applications/{application}/erase` | `recruitment.manage` |

Another organisation's vacancy or application is a 404 on every route (the tenant scope; `RecruitmentTest` covers each one).

### Files

- Backend: `app/Enums/ApplicationStage.php`, `app/Models/JobApplication.php`, `app/Models/JobApplicationEvent.php`, `app/Services/RecruitmentService.php`, `app/Http/Controllers/Api/V1/Recruitment/JobApplicationController.php`, `app/Http/Requests/Recruitment/*`, `app/Http/Resources/JobApplication*Resource.php`, `lang/{en,am}/recruitment.php`, routes, `PermissionSeeder`.
- Migration: `2026_10_27_000001_create_job_applications_tables.php` (two new tables; nothing existing is altered).
- Frontend: `features/recruitment/api.ts`, `features/recruitment/components/applicants-panel.tsx`, an **Applicants** button on each vacancy card (`app/(dashboard)/vacancies/page.tsx`), `usePermissions` flags, 78 en + am strings.
- Tests: `tests/Feature/RecruitmentTest.php` (21), `src/test/recruitment-applicants.test.tsx` (5).

### Deploying it

The usual release steps after Plesk Git **Deploy**, through the maintenance endpoint ([`deployment/PLESK-GO-LIVE.md`](deployment/PLESK-GO-LIVE.md), *Every release after this*), with `MAINTENANCE_TOKEN` set only while they run:

1. `backup`, since the release migrates.
2. `migrate` (two new tables). Until it runs, opening the Applicants panel fails.
3. `seed`. It runs `ProductionSeeder`, which calls `PermissionSeeder`, so the two permissions exist and reach HR Admin and Tenant Admin. Until then every recruitment route answers 403 and the Applicants button does not appear.
4. `optimize` last. A route cache from before the deploy answers 404 on the new routes.

Rollback: `migrate:rollback --step=1` drops both tables (applicant data with them). Employees already hired stay; only their link to the application goes. Checked on MariaDB 2026-10-27 (up, down, up).

## Rules for every lifecycle feature

1. **The framework decides the rule.** Never apply a private-sector rule to civil servants or the federal civil-service rule to a regional institution. Until an organisation records its framework (plan step 1a), a lifecycle feature asks HR rather than computing a legal value.
2. **Proposed is not effective.** A change to someone's job, pay or status is recorded first and applied only once it is approved and its effective date has come.
3. **A person decides.** No timer, flag or score ends employment, imposes a penalty or rejects an applicant.
4. **History is kept.** Changes append; nothing overwrites the earlier state.
5. **Reuse the owner of each fact.** One employee record, one contract chain, payroll for money, the approval engine for chains of approval, the audit log for attribution.

## Ordered plan

Each step is a separate, reviewable change with its own tests. Acceptance criteria are what a reviewer checks.

| # | Step | Acceptance criteria |
|---|---|---|
| 1a | **Employment framework per organisation** (private labour law, federal civil service, regional civil service with region, public enterprise, other), set at onboarding and in settings; effective-dated | Stored per tenant with history; shown on lifecycle screens; no rule reads it yet |
| 1b | **Fix: personnel actions apply only when effective** | A future-dated promotion leaves the employee and payroll unchanged until its date; a scheduled sweep applies due actions once; tests on both sides of the boundary |
| 2 | **Hire starts the contract** and a particulars letter | Hire can create the first `EmployeeContract` from the vacancy's type and the offer; the letter lists the Labour Proc. Art. 6 particulars; a task reminds HR within 15 days when no written contract exists (private framework only) |
| 3 | **New-hire checklist** from a template per organisation | Tasks with owner and due date (documents, pension registration within 60 days, equipment, account); completion recorded; incomplete items visible on the employee |
| 4 | **Separation workflow**: proposed, approved, effective, completed | Reason codes exclude Art. 26(2) grounds; notice per framework (30 days private resignation, 2 months federal); sign-in ends on the effective date; certificate of service generated on exit and tied to clearance |
| 5 | **Grievances and disciplinary timers** | Grievance cases separate from voice concerns; case files restricted beyond ordinary employee access; framework deadlines shown, never acted on automatically |
| 6 | **Requisitions and establishment** | Positions carry an approved headcount; a requisition goes through the approval engine before a vacancy can open; hire checks it |
| 7 | **Performance cycles** | Cycles, objectives, self and manager assessment, acknowledgement, appeal where the framework requires it |
| 8 | **Training and skills** | Training records, certifications with expiry, needs linked from reviews |
| 9 | **Public applications, privacy notice and reporting** | An applicant-facing form on public vacancies with an information notice (PDPP Art. 24) and abuse limits; reads of applications audited (Art. 46); time-to-hire and pending-offer reports; an owner decision on how long closed applications are kept |

**Next priority: 1b.** It is a defect in a built feature that can pay someone wrongly today, and every later step depends on "proposed is not effective" holding.

## Known limitations of the recruitment slice

- **A single-admin organisation cannot hire from the pipeline**, because the offer's maker cannot approve it. That is deliberate separation of duties; such an organisation can still add the employee directly. Whether to offer a recorded self-approval for very small organisations is an owner decision.
- **No public apply form.** Applications are recorded by HR from e-mail, walk-ins or referrals. The vacancy's own `apply_email` / `apply_url` still direct applicants.
- **Applicants are not told anything** by ETHR: no acknowledgement or rejection message.
- **No retention period.** Erasure is by hand. No statute read sets one (see the register).
- **Reads are not audited**, only changes.
- **The hire does not start a contract or a checklist** (plan steps 2 and 3).
- **Interviewers who are not HR cannot record their own scores**; HR records them.

## Test evidence (2026-10-27)

Run locally on Windows, PHP 8.2 native. The slice was first built on `58ecf1a25` and then moved onto `f786f0879` (#442); the full sweep below is against the moved tree.

- `RecruitmentTest`: 21 passed. `recruitment-applicants.test.tsx`: 5 passed.
- Full `./scripts/gates.sh` on `f786f0879`: Composer, Pint, PHPStan level 6, Pest, i18n, Prettier, ESLint, tsc, docs links, static export and API contract all pass. Pest collected 353/353 classes (matching `find api/tests/Unit api/tests/Feature`), 3933 passed and 1 risky (the marketing images test, unrelated).
- Vitest was reported failed by that sweep with every run test passing: 273 of 280 files, 1605 tests, and 7 files whose worker timed out before starting on a loaded machine. Those 7 files re-run on their own with the recruitment test: 8 files, 96 tests, all passed. A clean single-run Vitest gate was not repeated.
- Earlier, on `58ecf1a25`, the backend gate's first run failed four pins the change had to move (permission count 87 to 89 in `PermissionSystemTest` and `ProductionSeederTest`, the `{application}` parameter in `ParameterizedEndpointSmokeTest`), and the frontend gate failed the design ratchet on text below 12px from the stage badge. All fixed before the sweep above.
- PHPStan needed the two models added to the existing per-model `BelongsToTenant` ignore in `phpstan.neon`.
- Migration up, down and up on local MariaDB 10.4 (XAMPP).
- Driven in the browser on the demo organisation: an HR admin recorded an applicant and made an offer and was shown no approve button; the tenant admin approved and hired, which created employee EMP-0151. Checked at 375px. No API errors were logged.
