<?php

declare(strict_types=1);

/**
 * Every `withoutGlobalScope` / `withoutGlobalScopes` call site in `app/`,
 * counted per file. Pinned so a new one cannot appear unnoticed.
 *
 * ## What this proves, and what it does not
 *
 * It does **not** claim these 158 bypasses are correct. Auditing each one is a
 * human job and this file is not the record of it.
 *
 * (2026-10-24, web push: one more. `Listeners/PushDatabaseNotification` asks
 * whether a notified user has a push subscription. It runs inside queued
 * notifications, where no tenant may be resolved, so it drops the scope and
 * states `tenant_id` from the notified user on the same chain, with
 * `user_id`.)
 *
 * (2026-10-16, story documents: **168 across 65 files**.
 * `Http/Controllers/NewsFileController` serves a document attached to a public
 * news story. A download link cannot send `X-Tenant`, so the organisation is
 * named in the address; the story lookup drops the scope and states
 * `tenant_id` from that organisation on the same chain, with
 * `publiclyListed()`, and refuses a host that resolved a different one.)
 *
 * (2026-10-14, statutory rules: **167 across 64 files**. Pension rates became
 * dated rows (`pension_rules`) with the same platform/organisation split as
 * `tax_brackets`: `Services/Payroll/PensionCalculator` reads the organisation's
 * rule, then the platform's (`whereNull('tenant_id')`), each stating the
 * predicate on its own chain as `TaxCalculator::effectiveBrackets` does;
 * `Http/Controllers/Api/V1/Payroll/PensionRuleController` lists the platform
 * rules beside the organisation's own; `TaxCalculator::basis()` reports which
 * ladder applied, with the same two predicates (1 -> 2).)
 *
 * (2026-10-14: **163 across 62 files**. `Services/Device/AdmsDeviceResolver`
 * finds the device a ZKTeco ADMS request speaks for: pre-authentication, by
 * serial, authenticated by the device's IP allowlist — the webhook policy for
 * token-less firmware. Earlier the same day the count had fallen to 162/61,
 * when `DeviceController::webhookEmployee()` moved onto `IdentityResolver`.)
 *
 * (2026-10-11: **163 across 61 files**. `Services/TenantAddressService` is the one
 * implementation behind the platform console's domain endpoints and the
 * organisation's own `PUT /settings/address`. Its single bypass loads a
 * tenant's latest subscription for the plan gate, stating `tenant_id` from
 * the tenant in hand on the same chain — the console asks about tenants
 * other than the current one, which is why the scope cannot answer.)
 *
 * (2026-10-07: **158 across 58 files**. The kiosk token lookup moved into
 * `KioskSession::resolveActiveByToken()` (audit N47): pre-authentication, the
 * token is the credential and names its own tenant, and it then makes that
 * tenant current. `KioskCheckInController`'s two bypasses, which restated
 * `tenant_id` from the session, became plain scoped queries: 159 - 2 + 1.)
 *
 * (2026-10-04: **159 across 58 files**. `Services/Auth/OrganisationFinder` reads
 * which tenants an email address belongs to, for "find my organisation" on the
 * apex login — pre-authentication, with no tenant resolved, cross-tenant by
 * design. It selects `tenant_id` alone from `users` and returns only each
 * tenant's subdomain and name, which are emailed to the address, never shown.)
 *
 * (2026-10-02, later: **158 across 57 files**. `DispatchWebhookJob::failed()` now
 * counts an exhausted delivery against its webhook (audit N18), so it looks the
 * webhook up the way `handle()` does — `tenant_id` from the job, stated in the
 * same statement.)
 *
 * (2026-10-02: **157 across 57 files**. `Support/AuditSubjects` names the actor
 * and subject of audit rows by public id (audit N12), including in the platform
 * audit view, which resolves no tenant. Its three lookups — users, their
 * employees, and the audited models — each drop the tenant scope and state
 * `tenant_id` in the same statement, taken from the audit row.)
 *
 * (2026-10-01, later: **154 across 56 files**. `DeviceController`'s three
 * identical per-vendor webhook employee lookups became one method, audit B9 —
 * no bypass removed in substance, two duplicate call sites gone.)
 *
 * (2026-10-01: **156 across 56 files**. Three left with dead code, audit B1/B2 —
 * `LoginAttemptService` (1, the whole file) and `HolidayService::getHolidays()` /
 * `isHoliday()` (one each), none of which had a caller.)
 *
 * (Corrected 2026-09-27: this said **157**, which was the figure before
 * `NotifyPayrollRunFailed` and `NotifyDeviceSyncFailed` joined the inventory on
 * 2026-09-25. The data below has summed to **159 across 57 files** since then, and
 * the root `CLAUDE.md` has said 159/57 throughout — so the docblock disagreed with
 * the array it introduces, in a file whose entire purpose is to be the one place
 * this number lives. `CODE-VS-DOCUMENTATION.md` had flagged it as deliberately
 * deferred because it sits in `api/`, outside that pass's write scope. Verified by
 * summing the array, not by grep.)
 *
 * What it does is make adding a bypass a deliberate act. `BelongsToTenant` is
 * fail-closed — no tenant context yields no rows — and that is the property the
 * whole product rests on. A bypass drops it. Most are legitimate: platform-admin
 * surfaces, pre-authentication lookups, global reference data, and queued jobs,
 * which run with no HTTP tenant context and must re-scope by hand.
 *
 * But **every one must re-apply a tenant predicate**, and nothing checked that
 * until now. One was measurably wrong and shipped — `EmployeeImporter::commit()`
 * de-duplicated on a caller-supplied `import_key` across every tenant, which was
 * a cross-tenant existence oracle and silent data loss in one (P0-1,
 * `audit/BASELINE.md` §11b, fixed in `TenantImportIsolationTest`).
 *
 * That defect entered the codebase the way the next one will: someone added a
 * bypass, it looked like the surrounding code, and no gate noticed. This file is
 * the gate. Adding a call site fails the suite until the count here is updated,
 * which forces someone to look at it and say why.
 *
 * ## When the test fails
 *
 * **A count went up.** Open the new call site. Ask the only question that
 * matters: does this query state `tenant_id` itself, or derive from a key that
 * is already tenant-owned? If yes, update the count. If no, you have found the
 * next P0-1.
 *
 * **A count went down, or a file vanished.** A bypass was removed — good. Update
 * the count so this file does not drift into fiction, which is the failure mode
 * §12b catalogues across seven documents.
 *
 * Counts are per file rather than per line on purpose: line numbers move with
 * every unrelated edit, and a check that cries wolf gets muted.
 *
 * ## Counted by the tokeniser, not by grep (2026-09-22)
 *
 * This file was generated from `grep -rEc 'withoutGlobalScopes?\(' app/`, and
 * that is a text search: it counts the same words written in a comment. Five of
 * the 161 it once summed to were comments, every one in a file whose docblock
 * explains why its bypass is safe. `Http/Middleware/EnsurePlatformContext.php`
 * is absent below for exactly that reason — it never held a bypass at all, only
 * a docblock mentioning one.
 *
 * Counting is now `countTenantScopeBypassCalls()` in the test beside this file,
 * which walks `token_get_all()` and skips T_COMMENT, T_DOC_COMMENT and string
 * literals. Regenerate with that, never with grep.
 *
 * Originally generated 2026-09-16; re-counted 2026-09-22.
 */
return [
    'Console/Commands/CreateAdminCommand.php' => 2,
    // ResetMfaCommand (2026-10-09): turns MFA off for an account no one in the
    // app can reset. Platform accounts have no tenant, so the scope cannot see
    // them; the query states `tenant_id` itself — the --tenant organisation's,
    // or NULL (platform accounts only) when none is named.
    'Console/Commands/ResetMfaCommand.php' => 1,
    'Console/Commands/SyncDevicesCommand.php' => 1,
    'Http/Controllers/Api/V1/Admin/AdminDashboardController.php' => 3,
    // Platform-admin plan catalog. Both sites count subscriptions on one plan,
    // cross-tenant by intent, from a surface that runs with no tenant resolved
    // (EnsurePlatformContext) — where the fail-closed scope would return 0 for
    // every plan. Each states plan_id as its own predicate.
    'Http/Controllers/Api/V1/Admin/AdminPlanController.php' => 2,
    'Http/Controllers/Api/V1/Admin/AdminTenantController.php' => 16,
    // Pre-authentication: the phone lookup and, since email sign-in codes, the
    // email lookup. Each states tenant_id from the resolved tenant on its own
    // chain, as AuthIdentifierResolver does for password sign-in.
    'Http/Controllers/Api/V1/Auth/OtpController.php' => 2,
    'Http/Controllers/Api/V1/Auth/PasswordResetController.php' => 2,
    'Http/Controllers/Api/V1/Auth/SubdomainCheckController.php' => 1,
    // Two device lookups in resolveWebhookDevice() (token; serial + IP
    // allowlist) and, until 2026-10-14, one employee lookup in webhookEmployee(), which states
    // `tenant_id` from the authenticated device. The employee lookup was written
    // out three times, once per vendor handler, until audit B9 (2026-10-01) made
    // it one method: 5 -> 3, the same query at fewer call sites. 2026-10-14:
    // 3 -> 2, webhookEmployee() now goes through IdentityResolver, which states
    // `tenant_id` on its own queries (as the pull path always did). Later that
    // day the employee and punch steps moved to Services/Device/DevicePunchRecorder,
    // shared with ZKTeco ADMS; it has no bypass of its own.
    'Http/Controllers/Api/V1/Device/DeviceController.php' => 2,
    // The platform's pension rules (tenant_id NULL), listed beside the
    // organisation's own in Settings → Payroll (2026-10-14).
    'Http/Controllers/Api/V1/Payroll/PensionRuleController.php' => 1,
    'Http/Controllers/Api/V1/Payroll/TaxBracketController.php' => 1,
    // A public story's document (2026-10-16): the organisation is named in the
    // download address, and the lookup states its `tenant_id` and
    // `publiclyListed()` on the same chain. Refused when the host resolved a
    // different organisation.
    'Http/Controllers/NewsFileController.php' => 1,
    // AuthenticateApiKey (2026-10-09): the key lookup is pre-authentication and
    // keyed on the presented secret, as ScimAuth's is; the creator lookup
    // states `tenant_id` from the key it belongs to.
    'Http/Middleware/AuthenticateApiKey.php' => 2,
    'Http/Middleware/ScimAuth.php' => 1,
    'Http/Requests/Auth/LoginRequest.php' => 1,
    // Cross-tenant on purpose, and it states no `tenant_id` — the honest answer
    // to this file's own question. Device serial uniqueness is *global*, because
    // the webhook lookup it guards (`DeviceController::resolveWebhookDevice()`)
    // cannot state `tenant_id` and so cannot tell two tenants' identical serials
    // apart. A tenant-scoped check would leave exactly the ambiguity the index
    // exists to remove. It reads existence only — no row is returned, no field
    // read, and the message names nothing. BASELINE.md §11h.
    'Http/Requests/Device/Concerns/ValidatesSerialUniqueness.php' => 1,
    'Jobs/BackupTenantJob.php' => 3,
    'Jobs/DispatchWebhookJob.php' => 4,
    'Jobs/GenerateMonthlyInvoicesJob.php' => 2,
    'Jobs/HandleOverdueInvoicesJob.php' => 6,
    'Jobs/NotifyAnnouncementAudienceJob.php' => 2,
    'Jobs/NotifyExpiringTrialsJob.php' => 1,
    'Jobs/ProcessPayrollJob.php' => 2,
    'Jobs/RunDashboardDigestsJob.php' => 1,
    'Jobs/RunScheduledReportsJob.php' => 1,
    'Jobs/ScanMissingPunchesJob.php' => 1,
    'Listeners/NotifyDeviceOffline.php' => 1,
    'Listeners/NotifyDeviceSyncFailed.php' => 1,
    'Listeners/NotifyPayrollProcessed.php' => 1,
    'Listeners/NotifyPayrollRunFailed.php' => 1,
    'Listeners/PushDatabaseNotification.php' => 1,
    // Pre-authentication: the kiosk token (globally unique, 64 random hex)
    // is the credential and selects its own tenant, which is refused if it
    // differs from a tenant already resolved, or is not active. A shared
    // kiosk terminal has no login to name the tenant otherwise (N47).
    'Models/KioskSession.php' => 1,
    'Models/PersonalAccessToken.php' => 1,
    'Notifications/Concerns/RespectsNotificationPreferences.php' => 1,
    'Services/Accounting/AccountingExportService.php' => 1,
    'Services/Admin/PlatformAnalyticsService.php' => 3,
    'Services/Analytics/BranchAnalyticsService.php' => 5,
    'Services/Analytics/DepartmentAnalyticsService.php' => 5,
    'Services/Analytics/ExecutiveDashboardService.php' => 22,
    'Services/Attendance/AttendanceEngine.php' => 2,
    'Services/Attendance/AttendanceImporter.php' => 1,
    'Services/Attendance/ConflictResolver.php' => 1,
    'Services/Auth/AuthIdentifierResolver.php' => 7,
    // "Find my organisation" on the apex login (2026-10-04). Pre-authentication,
    // on the apex where no tenant resolves, and cross-tenant by its nature: the
    // question is which tenants an address belongs to. From `users` it selects
    // `tenant_id` only, for active accounts; the caller gets back each
    // operational tenant's subdomain and name and nothing else, and that goes to
    // the address by email — the endpoint answers every request identically.
    'Services/Auth/OrganisationFinder.php' => 1,
    'Services/Billing/BillingService.php' => 5,
    'Services/Dashboard/EmployeeDashboardService.php' => 1,
    // ZKTeco ADMS (2026-10-14). Pre-authentication, like the webhook lookup:
    // the firmware sends its serial and nothing else, so the serial selects a
    // `zkteco_adms` device and the device's IP allowlist authenticates the
    // request (fail-closed when empty). It states no `tenant_id` — the tenant
    // is what it finds out — and two rows for one serial authenticate neither.
    'Services/Device/AdmsDeviceResolver.php' => 1,
    'Services/Holiday/HolidayService.php' => 2,
    'Services/Identity/IdentityResolver.php' => 2,
    'Services/Import/EmployeeImporter.php' => 1,
    'Services/Leave/LeaveBalanceService.php' => 5,
    'Services/Leave/LeaveDayCalculator.php' => 1,
    'Services/Migration/WorkforceMigrationService.php' => 1,
    'Services/Onboarding/OrganizationProvisioner.php' => 2,
    'Services/Onboarding/ReadinessScorer.php' => 10,
    'Services/Payroll/AllowanceService.php' => 1,
    'Services/Payroll/OvertimeCalculator.php' => 1,
    'Services/Payroll/PayrollEngine.php' => 4,
    // The rule in force: the organisation's (`where tenant_id = X`), else the
    // platform's (`whereNull`) — the tax ladder's arrangement (2026-10-14).
    'Services/Payroll/PensionCalculator.php' => 1,
    // effectiveBrackets() and basis() (2026-10-14), each stating tenant_id.
    'Services/Payroll/TaxCalculator.php' => 2,
    'Services/Report/ReportEngine.php' => 4,
    'Services/TenantAddressService.php' => 1,
    'Services/UserProvisioningService.php' => 1,
    'Services/Webhook/WebhookDispatcher.php' => 1,
    'Support/AuditSubjects.php' => 3,
];
