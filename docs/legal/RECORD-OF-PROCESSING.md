# Record of processing activities

> **DRAFT — NOT LEGAL ADVICE.** Read out of the code on 2026-11-25. Counsel has not confirmed it. Register row L12 in [`LEGAL-REGISTRATION-REGISTER.md`](LEGAL-REGISTRATION-REGISTER.md).

The record Art. 46 of Proclamation 1321/2024 requires ([`../EMPLOYMENT-LAW-REGISTER.md`](../EMPLOYMENT-LAW-REGISTER.md), class 1). It is also the answer sheet for registering with the Ethiopian Communications Authority: what is processed, for whom, why, where, for how long, and how it is protected.

**Keep it true.** A pull request that adds a personal-data column, an upload type, an outbound integration or a retention rule changes this document in the same pull request.

## Who is who

| Party | Role | For which data |
|---|---|---|
| Syntax Technology `[confirm legal name, registration number, address]`, trading as ETHR | **Processor** | Everything an organisation puts into ETHR about its people (Part 1) |
| Syntax Technology | **Controller** | ETHR's own customers, users of the public site, billing, support (Part 2) |
| Each customer organisation | **Controller** | Its own employees' data, held in ETHR (Part 1) |
| Data Protection Officer | `[confirm: role and contact]` | Register row L18 |

ETHR does not decide why an organisation collects its employees' data, or which features it switches on. It does decide how the software stores, protects and deletes it, which is what a processor answers for (Art. 16, class 1).

## Part 1: ETHR as processor, for each customer organisation

### Data subjects

The organisation's employees, former employees, job applicants, and the emergency contacts employees name.

### Categories of personal data

| Category | What, exactly | Where in the code | Protection beyond access control |
|---|---|---|---|
| Identity | Name in English and Amharic, gender, date of birth, nationality and nationality status, marital status, photo | `api/app/Models/Employee.php` | — |
| Identity numbers | National ID number, TIN | `Employee.php` casts | **Encrypted at rest** (Laravel `encrypted` cast). National ID also has a keyed lookup hash. Kept out of audit entries (`EmployeeAuditMask`) |
| Contact | Email, phone, address to woreda, kebele and house number | `Employee.php` | — |
| Emergency contacts | Name, relationship, phone | `EmployeeEmergencyContact` | — |
| Employment | Position, grade, department, branch, contracts, salary history, education, disciplinary cases, grievances, retirement cases | Employee relations | — |
| Pay | Salary, salary step, allowances, deductions, loans, payslips, payroll calculation log | Payroll models | — |
| Bank | Bank name, account number | `EmployeeBankDetail.php` | Account number **encrypted at rest** |
| Documents | Uploaded contracts, certificates, identity copies, academic records, **medical documents**, leave attachments (including sick-leave evidence) | `EmployeeDocument`, `leave_requests.attachment_path` | Signed, time-limited download links; every view is audit-logged (`employee.document.viewed`). Files are not encrypted on disk |
| **Biometric: attendance selfies** | A photo taken by the employee's phone at check-in, if the organisation turns it on and the employee consents; a 64-bit perceptual hash of it | `attendance_records.photo_path`, `attendance_visits.photo_path`; `App\Support\PhotoConsent` | Refused without recorded consent; deleted after the organisation's retention period (7 days by default); every view audit-logged. See [`BIOMETRIC-DATA-POLICY.md`](BIOMETRIC-DATA-POLICY.md) |
| Biometric enrolment metadata (not biometrics) | For each person enrolled on a terminal: device user id, name, card number, whether admin, **how many** fingerprints are enrolled, **whether** a face is | `device_enrollment_snapshots` | The fingerprint and face templates themselves are **never stored**: `AdmsProtocol` counts them and discards them |
| Attendance | Punch times and directions, method (device, phone, kiosk, QR), shift, lateness, overtime, corrections, leave | `attendance_records`, `leave_requests` | Never deleted (attendance and payroll audit requirement) |
| Location | Latitude, longitude and accuracy at phone check-in; presence checks; attendance sites | `MobileCheckInRequest`, `attendance_presence_checks`, `attendance_visits` | Presence checks deleted after 90 days (`PresenceChecks::RETENTION_DAYS`) |
| Device and network | IP address and user agent on each punch and sign-in; an install id stored only as a keyed hash; passkey public key and credential id | `AttendanceEngine`, `login_histories`, `PasskeyVerifier` | Private keys never leave the phone |
| Kiosk PIN | A PIN for shared-kiosk punching | `KioskPinGuard` | **Hashed**, not stored |
| Raw device events | The text a terminal sends with a punch, up to 16 KB | `device_inbound_punches.raw` | Nulled once processed; rows deleted after 7 days |
| Applicants | Name, contact, source, what they sent | `JobApplication` | Can be erased by anonymising (`RecruitmentService::erase`) |

**Not collected:** religion, ethnicity, political opinion, disability, blood type, passport number, dependants. A medical document is collected only if the organisation uploads one. That is sensitive data (Art. 2(5), class 1), and the lawful basis for it is the organisation's (DPA Annex 2).

### Purposes

Running the organisation's HR: the employee record, attendance and time, leave, payroll and its statutory schedules (income tax for the Ministry of Revenues, pension for POESSA or PSSSA), letters and documents, recruitment, and announcements. ETHR uses this data for nothing else and builds no other product from it (Terms, *Your data stays yours*).

### Recipients

- **People inside the organisation**, according to the roles and permissions it grants (`docs/PERMISSIONS.md`).
- **ETHR platform staff**, only by impersonation: MFA-gated, time-limited, audited with `impersonated_by` ([`../operations/IMPERSONATION.md`](../operations/IMPERSONATION.md)).
- **Sub-processors** listed in [`SUB-PROCESSORS.md`](SUB-PROCESSORS.md).
- **Destinations the organisation chooses itself**: webhooks it configures (employee names and attendance times, [`../WEBHOOKS.md`](../WEBHOOKS.md)), bank payment files and statutory schedules it downloads and files. ETHR files nothing with any agency.

### Transfers outside Ethiopia

Production is hosted in Ethiopia by Ethio Telecom (`docs/deployment/host-evidence/HOST-EVIDENCE.json`), as Art. 22(1) requires (class 1). Off-host backups are held to the same rule since 2026-11-25 (register row L16):

- **Google Drive**: production never copies to it. Archives placed there before the rule can still be restored.
- **S3-compatible storage**: production copies only to storage stated to be in Ethiopia, by the super admin in the console or by `BACKUP_OFF_HOST_IN_ETHIOPIA` in `.env`. The statement is audited. Its truth is the operator's responsibility, since nothing in an endpoint proves where a bucket is.

Calls that leave Ethiopia but carry no personal data are listed in [`SUB-PROCESSORS.md`](SUB-PROCESSORS.md) §B.

### Retention

[`RETENTION-SCHEDULE.md`](RETENTION-SCHEDULE.md).

### Security measures

[`INFORMATION-SECURITY-POLICY.md`](INFORMATION-SECURITY-POLICY.md).

## Part 2: ETHR as controller

| Data subjects | Data | Purpose | Lawful basis (Art. 7, class 1 unless marked) | Retention |
|---|---|---|---|---|
| People who send an enquiry | Name, email, message; optionally phone and organisation | Answering | Consent; steps before a contract | `[confirm: no rule yet]` |
| Administrators who register an organisation | Name, email, optional phone, password hash, organisation name and address | Opening and running the account | Contract | The life of the account |
| Users of customer accounts | Sign-ins: IP, user agent, status, failure reason (`login_histories`) | Security of the account | Legitimate interest, or a legal duty of security (class 3: counsel to confirm the basis) | Not deleted today |
| Billing contacts | Invoices, plan, payments marked received | Billing | Contract; tax record-keeping | `[confirm: tax record period, register row L30]` |
| Support requesters | Support requests and messages | Support | Contract | `[confirm]` |
| Everyone acting in ETHR | Audit log: who did what, when, from where | Integrity and accountability | Legal duty and legitimate interest (class 3) | Never deleted; the table refuses UPDATE and DELETE |

The public site sets no analytics or advertising cookies and loads no analytics script (`src/src/test/legal-pages.test.tsx` pins the claim).
