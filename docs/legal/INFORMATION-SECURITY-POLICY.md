# Information security policy (customer summary)

> **DRAFT — NOT LEGAL ADVICE.** A summary of the controls as built on 2026-11-25, for customers, auditors and Annex 3 of the DPA. Counsel has not confirmed it. The engineering detail, and where it lives in the code, is in [`../SECURITY.md`](../SECURITY.md). Where the two disagree, the code decides and both are corrected.

**Describe only what exists.** Each control below names the code that enforces it. A control that is planned is listed under §9, not above it.

## 1. Separating customers

- Each organisation's data is separated by a database-level rule that **fails closed**: with no organisation established, a query returns nothing, not everything (`BelongsToTenant`).
- Every place the rule is deliberately bypassed is counted and pinned by a test, so a new bypass cannot be added unnoticed (`TenantScopeBypassInventoryTest`).
- A test calls every API route with a record from one organisation as another organisation's administrator, and requires a refusal (`CrossTenantRouteSweepTest`).

## 2. Signing in

- Passwords stored as bcrypt hashes. Each organisation sets its own password policy (minimum 8 characters, optional complexity) (`PasswordPolicy`).
- Two-factor authentication by authenticator app. Organisations can require it of their users; **platform administrators must use it** (`RequireTenantMfaEnrolment`, `RequirePlatformMfa`).
- Sessions expire after 8 hours, and after an idle period each organisation sets (`EnforceSessionIdleTimeout`).
- Sign-in, code requests and other sensitive endpoints are rate-limited.
- Every sign-in, successful or not, is recorded with its IP address and browser (`login_histories`).

## 3. Access within an organisation

- Permissions by module, assigned through roles, including custom roles ([`../PERMISSIONS.md`](../PERMISSIONS.md)). Checked by permission, never by role name.
- Sensitive views are narrower than ordinary ones: attendance selfies, documents and TINs each need their own permission.

## 4. Access by ETHR staff

- ETHR staff reach an organisation's data only by **impersonation**. It needs two-factor authentication, ends after a set time, blocks destructive actions, and records the staff member in every audit entry it produces (`impersonated_by`) ([`../operations/IMPERSONATION.md`](../operations/IMPERSONATION.md)).
- Who holds which production credential is recorded by name and reviewed quarterly by two people ([`../operations/CUSTODY.md`](../operations/CUSTODY.md)).

## 5. Encryption

- **In transit:** HTTPS only, with HSTS (including preload) and secure cookies in production.
- **At rest, field level:** TINs, national ID numbers, bank account numbers, two-factor secrets, single sign-on certificates, device credentials, and the values in pending profile changes are encrypted in the database (Laravel `encrypted` casts). A copy of the database alone does not reveal them.
- **Backups:** encrypted on the server before leaving it (`ethr:backup`).
- **Not encrypted at rest:** uploaded files (including selfies and scanned documents) and the remaining database columns. They are protected by access control and the hosting provider's controls. See §9.

## 6. The application

- A strict Content Security Policy, `frame-ancestors 'none'`, `nosniff`, and a restrictive Permissions-Policy (`SecurityHeaders`; `docs/deployment/shared-hosting/.htaccess`).
- Every request validated by a dedicated form request; every endpoint authorised. Both are enforced by tests across all controllers.
- Uploaded files are checked by their content, not their name, and image metadata is stripped.
- Webhooks are signed (HMAC), and their addresses refused if they point at internal networks.
- Error reports exclude personal data and are scrubbed (`SentryScrubber`); session replay is off.

## 7. Records and accountability

- An **audit log** of about 360 kinds of action, including views and exports of sensitive data (documents, selfies, bank and statutory exports). The table refuses UPDATE and DELETE at database level, so it cannot be edited after the fact.
- National ID numbers and TINs are kept out of audit entries.

## 8. Continuity and incidents

- Nightly backups, kept on the host and copied off it when off-host storage is configured ([`../deployment/BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md)).
- A breach procedure with the 72-hour clock ([`../operations/DATA-BREACH-RESPONSE.md`](../operations/DATA-BREACH-RESPONSE.md)), and an on-call guide ([`../operations/ON-CALL.md`](../operations/ON-CALL.md)).
- Vulnerability reports: [`../../SECURITY.md`](../../SECURITY.md).
- Hosting in Ethiopia, with Ethio Telecom.

## 9. Known gaps

Stated so a customer can weigh them, not discovered later:

1. Uploaded files, including selfies, are not encrypted at rest.
2. Backups can be copied to storage outside Ethiopia (register row L16).
3. **A restore from backup has not yet been rehearsed on the production host** ([`../deployment/CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md)).
4. Ordinary views of an employee record are not audit-logged; sensitive views are.
5. No independent penetration test or INSA assessment has been done (register row L34).
