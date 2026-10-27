# Employment law register

The Ethiopian laws that shape ETHR's employee lifecycle features: what each instrument requires, where it applies, what it means for the product, and how sure we are.

**This is a software team's reading of primary texts. It is not legal advice and it does not make ETHR compliant.** Every row marked 3 needs a qualified Ethiopian lawyer or the competent authority, and rows marked 1 still need one before any feature is described to customers as compliant.

*Researched 2026-10-27 from the Federal Negarit Gazette PDFs published on [justice.gov.et](https://justice.gov.et/). The payroll rules (tax and pension rates) are in [`payroll/STATUTORY-RULES-RESEARCH.md`](payroll/STATUTORY-RULES-RESEARCH.md) and are not repeated here.*

## How to read it

| Class | Meaning |
|---|---|
| **1** | Read in the current primary text, and nothing found that amends it |
| **2** | Identified, but not fully verified: a secondary copy, an unclear status, a poor scan, or a directive not read |
| **3** | Needs qualified Ethiopian counsel or the competent authority before the product relies on it |

**Product status** says whether ETHR does something about the row today: *Built* (with the file), *Partial*, or *Not built*. Article text is paraphrased, never quoted at length.

## Who each law covers

The first decision for any lifecycle rule is which framework governs the employee. The product must not apply one framework's rule to another's employees.

| Framework | Instrument | Covers | Does not cover |
|---|---|---|---|
| Private-sector labour law | Labour Proclamation No. 1156/2019 (in force on publication, 5 Sep 2019; repeals 377/2003 and its amendments) | Contracts of employment with private employers, including recruitment (Art. 3) | Managerial employees, personal service, armed forces, police, state administration employees, judges, prosecutors, others under special laws (Art. 3) |
| Federal civil service | Federal Civil Servants Proclamation No. 1353/2024 (repeals 1064/2017; in force on publication) | Federal government offices and their employees | Regional civil servants, which have their own regional laws |
| Regional civil service (Amhara, first region) | Amhara National Regional State Civil Servants Revised Proclamation No. 171/2010 *(class 2/3, see below)* | Amhara regional civil servants | Federal and private employees |
| Personal data, everyone | Personal Data Protection Proclamation No. 1321/2024 (in force on publication, 24 Jul 2024) | Private and public, federal and regional (Art. 3(3)) | |
| Public-sector pension | Public Servants' Pension Proclamation No. 1267/2022 (repeals 714/2011) | Public servants, federal **and regional** ("Government" includes the regions, Art. 2(7)) | |
| Private-sector pension | Private Organization Employees' Pension Proclamation No. 1268/2022 (repeals 715/2011 and 908/2015) | Ethiopian employees of private organisations | Domestic workers, international organisations, sole owners (Art. 3) |
| Electronic documents | Electronic Transaction Proclamation No. 1205/2020; Electronic Signature Proclamation No. 1072/2018 as amended by No. 1358/2024 | Legal effect of electronic records and signatures | Personal status, wills, court process, immovable property, powers of attorney (1205 Art. 3(2)) |

**Product consequence:** a framework is a property of the organisation, and occasionally of an employee (a public enterprise can employ under labour law). ETHR records the industry and workforce profile at onboarding (`docs/INDUSTRY_TEMPLATES.md`) but has **no field that says which employment framework governs an organisation**. Until it does, no lifecycle rule in ETHR may be framework-specific, and the recruitment slice built on 2026-10-27 deliberately encodes none: probation length, notice periods and selection rules are chosen by HR, not computed. Adding the framework field is step 1 of the plan in [`EMPLOYEE-LIFECYCLE.md`](EMPLOYEE-LIFECYCLE.md).

## Labour Proclamation No. 1156/2019 (private employers)

| Article | Requirement (paraphrased) | Product implication | Class | Product status |
|---|---|---|---|---|
| 4(3), 6 | A contract states the type of work, place, wage rate and method, payment interval and duration; a written one also names both parties and is signed by both | Contract fields | 1 | Partial: `EmployeeContract` holds type, dates, salary and free-text terms; no generated document |
| 5, 8 | No special form is needed unless a law says so; failing Art. 6/7 does not remove the worker's rights | Warn, never block | 1 | — |
| 7(1)–(2) | Without a written contract, the employer gives a signed letter with the Art. 6 particulars within 15 days; the worker has 15 days to object | A 15-day task after hire | 1 | Not built. Next after the recruitment slice |
| 9, 10 | Default is indefinite. Fixed term only on listed grounds; two grounds are capped at 45 working days | Fixed-term contracts need a ground | 1 | Not built |
| 11(1)–(3) | Probation is optional, agreed in writing, at most 60 working days from the first day; none on re-employment for the same job | Probation counted in working days; no repeat probation | 1 | Partial: the hire asks HR whether and until when, and states no limit (`HireApplicationRequest`) |
| 11(5)–(7) | Either side may end during probation without notice or severance; working past the end concludes the contract from day one | Flag the probation end; never end employment automatically | 1 | Partial: overdue flag exists; no automatic action, by design |
| 12(7) | Keep a register of the Art. 6 particulars, rest days, holidays, leave, health (not HIV status) and injuries | Personnel register; never store HIV status | 1 | Partial |
| 12(8) | On termination, or whenever asked, give a free certificate of the type of work, length of service and wage | Certificate on exit and on request | 1 | Partial: employment letters exist (`EmploymentLetters`); not tied to exit |
| 23–25 | Termination only by law, collective agreement or agreement; agreed termination binds the worker only in writing | Written record of mutual termination | 1 | Not built |
| 26(2) | Union activity, nation, sex, religion, political view, marital status, pregnancy, disability and similar are never valid grounds | The reason list must not offer them | 1 | Not built (reasons are free text) |
| 27 | Exhaustive grounds for dismissal without notice; written reasons and date; the right lapses 30 working days after the employer knew | Countdown from the date the employer knew | 1 | Not built |
| 31 | After probation, a worker resigns with 30 days' notice | Default resignation notice for this framework only | 1 | Not built |
| 34, 35 | Notice is written, with reasons and effective date. Employer notice is 1, 2 or 3 months by length of service | Notice calculator for this framework | 1 | Not built |
| 36–38 | Final pay within 7 working days; late payment can cost up to 3 months' wages | Final-pay deadline alert | 1 | Partial: payroll now includes a leaver up to the termination date (`PayrollEngine`, blueprint D3); no 7-working-day alert |
| 39–41, 43–45 | Severance and compensation formulas; a worker who quits without notice may owe up to 30 days' wages | Calculator, handed to payroll | 1 | Not built. Never deduct without the legal basis and an authorised decision |
| 163 | Limitation periods: 1 year general, 3 months reinstatement, 6 months wages and termination payments | A floor for keeping records, not a retention rule | 1 (as limitation) / 3 (as retention) | — |

## Federal Civil Servants Proclamation No. 1353/2024

**Its effective date is uncertain inside its own text** (class 2): the cover reads 10 December 2024 while every page footer and the signature line read No. 34, 10 July 2024. Art. 157(5) keeps regulations and directives made under 1064/2017 in force until replaced; those directives were not read.

| Article | Requirement (paraphrased) | Product implication | Class | Product status |
|---|---|---|---|---|
| 24 | Eligibility: age 18 to under 60, competency certificate, oath; a conviction bars for 5 years; medical certificate without an HIV test, and police clearance | Eligibility fields for civil-service tenants; never collect HIV results | 1 | Not built. The applicant form warns against recording health data |
| 27–28 | Every vacancy is reported to the Institute and advertised in public media; hiring needs a competency certificate | A civil-service recruitment step outside ETHR | 1 | Not built |
| 29 | Recruitment, promotion and transfer by competition and merit; the highest-scoring qualified candidate fills the post; Commission directives give the detail | Scored, auditable selection | 1 (directive: 3) | Partial: interview scores and every decision are recorded with the actor; no ranking rule, by design |
| 33(2) | The probation letter states position, level, salary, start date and job description, signed by the head or a delegate | Template fields | 1 | Not built |
| 33(3)–(9), 34 | Probation 6 months, extendable by 3 if below average; evaluation deadlines | Civil-service probation workflow | 1 | Not built. HR sets the date at hire |
| 49 | Promotion needs a renewed competency certificate and completed probation | Promotion eligibility check | 1 (directive: 3) | Not built |
| 51–55 | Transfer at equal salary; acting assignment at most 1 year; secondment; redeployment | Limits on personnel actions | 1 | Partial: `PersonnelAction` records acting and secondment; no limit |
| 88, 92 | The personal file's contents; the servant may see and copy it all; no third-party access without consent, a court order or a law; nothing filed without telling the servant | Self-service file view; notify on every upload | 1 | Partial |
| 97, 99, 101, 102 | Penalty scale; disciplinary committee; suspension at most 3 months in writing; limitation 6 months for simple penalties; records kept 2 or 5 years | Disciplinary workflow with timers | 1 | Partial: `DisciplinaryCase` workflow exists; no framework timers |
| 104–106, 130 | Grievance committee, written decision, Administrative Court appeal | Grievance module | 1 | Not built (the voice module's concerns are not a grievance procedure) |
| 135 | Resignation on 2 months' notice; release may be delayed up to 4 months | Different from the private 30 days | 1 | Not built |
| 141 | Retirement takes effect on the last day of the month of retirement age, with 3 months' written notice | Retirement notice at minus 3 months | 1 | Partial: `RetirementCase` exists |
| 143 | Certificate of service; may be withheld until clearance if a training bond or debt is outstanding | Certificate tied to exit clearance | 1 | Not built |

## Personal Data Protection Proclamation No. 1321/2024

The regulator is the Ethiopian Communications Authority (Art. 2(36)). The existing breach runbook, [`operations/DATA-BREACH-RESPONSE.md`](operations/DATA-BREACH-RESPONSE.md), already applies Arts. 43–44.

| Article | Requirement (paraphrased) | Applied in the recruitment slice | Class |
|---|---|---|---|
| 6 | Purpose limitation, minimisation, storage limitation, security | The applicant record holds name, contact, source and what they sent; the form tells HR not to record health, religion, ethnicity or political views | 1 |
| 7(2) | A lawful basis is needed; steps before a contract are one | Recording an applicant is a pre-contract step. **Which basis each tenant relies on is the tenant's call as controller** | 1 / 3 |
| 2(5), 9 | Sensitive data (health, biometrics, religion, ethnicity, political views, convictions) is prohibited without a listed exception | Not collected by the slice | 1 |
| 15, 50 | Keep only as long as the purpose needs, then destroy irreversibly | HR can erase a rejected or withdrawn applicant's personal details (`POST /job-applications/{id}/erase`). **No automatic period**: no statute found sets one, and choosing one is the owner's or each tenant's decision | 1 / 3 |
| 22 | Locally collected personal data is stored on a server in Ethiopia | ETHR is hosted by Ethio Telecom. Offshore backups or processors need review | 1 / 3 |
| 24 | An information notice at collection | **Not built for applicants.** A tenant collecting applications needs one; ETHR has no applicant-facing form yet | 1 |
| 25–32 | Access, rectification, erasure, objection, no solely-automated significant decisions | Erasure: built for closed applications. Automated decisions: none exist, nothing scores or rejects on its own | 1 |
| 40 | A DPO is mandatory for government bodies and large-scale sensitive processing | Civil-service tenants need one | 1 |
| 46 | Keep a record of processing, including who **read** personal data | Writes are audited; **reads of an application are not**. Gap recorded in the plan | 1 (period: 3) |

## Pension proclamations

| Instrument, article | Requirement | Product implication | Class |
|---|---|---|---|
| 1267/2022 Art. 4(2)(b); 1268/2022 Art. 4(2) | Register a new employee with the pension administration within 60 days of employment | An onboarding task after hire, per framework | 1 |
| 1267/2022 Art. 18(1)(c); 1268/2022 Art. 18 | Retirement age 60 from the birth date registered at first employment; later changes not accepted | Lock the date of birth used for retirement | 1 |

## Electronic documents and signatures

Electronic Transaction Proclamation No. 1205/2020 gives electronic records and signatures legal effect, with conditions: no one can be forced to use them (Art. 4); "writing" requirements are met electronically (Arts. 7–8); a reliability test for signatures (Art. 9); a seal needs a digital signature (Art. 10); retention electronically is allowed if the record is unaltered and its origin and time are known (Art. 14). Amendment No. 1358/2024 to the Electronic Signature Proclamation only adds regulation-making powers. The base text of 1072/2018 was **not read** (class 2).

**Product consequence:** ETHR must not describe any contract, letter or certificate it produces as legally signed. Whether a given HR document is valid electronically is class 3.

## Amhara National Regional State

Proclamation No. 171/2010 was read **only from a secondary copy** (chilot.wordpress.com): probation 6 months evaluated every 3 months (Art. 20), resignation on one month's notice (Art. 76). **Whether it is still in force, or has been replaced, is unknown.** No authoritative regional source was reachable. Nothing in ETHR relies on it, and nothing should until the regional Bureau or counsel confirms the current text.

## Federal discipline and grievance

Proclamation 1353/2024 Arts. 96–106 and 130 now set the core rules (class 1). Council of Ministers Regulation No. 77/2002 is listed "In Force" on justice.gov.et, but it was made under Proclamation 262/2002, which has been repealed, and 1353 Art. 157(5) preserves only instruments made under 1064/2017. Whether 77/2002 still applies, or a Commission directive replaced it, is **class 3**.

## Could not verify

- The base text of Electronic Signature Proclamation 1072/2018.
- The effective date of 1353/2024 (July or December 2024).
- The current Amhara civil service law.
- Whether Regulation 77/2002 still governs federal discipline; every Civil Service Commission directive on recruitment, promotion, discipline and transfer.
- **Any statutory retention period for employment or applicant records.** None found in 1156, 1353 or 1321; only limitation periods and 1205 Art. 14's "as long as the paper equivalent". Tax and pension record-keeping periods were not checked.
- PDPP registration rules, the read-log retention period, and whether ETHR itself must register as a processor (Authority directives not read).
- Any amendment to 1156/2019 after 2019: none listed on its justice.gov.et page; not independently confirmed.
- Eight directives counted but not shown on the Ministry of Justice labour listing (29 counted, 21 listed).

## Sources read

| Instrument | Where |
|---|---|
| 1156/2019 | justice.gov.et/en/law/labour-proclamation/ and its Gazette PDF |
| 1353/2024 | justice.gov.et/en/law/federal-civil-servants-proclamation-5/ and its Gazette PDF |
| 1321/2024 | justice.gov.et/en/law/personal-data-protection-proclamation/ and its Gazette PDF |
| 1267/2022 | justice.gov.et/en/law/public-servants-pension-proclamation/ and its Gazette PDF |
| 1268/2022 | justice.gov.et/en/law/private-organization-employees-pension-proclamation/ and its Gazette PDF |
| 1205/2020 | justice.gov.et/en/law/electronic-transaction-proclamation/ and its Gazette PDF |
| 1358/2024 | justice.gov.et/en/law/electronic-signature-proclamation/ (the amendment) and its Gazette PDF |
| Reg. 77/2002 | justice.gov.et listing and its PDF (poor scan) |
| Labour and employment listing | justice.gov.et/en/sector/labor-employment-sector-specific-regulations/ |
| Amhara 171/2010 | chilot.wordpress.com (secondary host) |
