<?php

declare(strict_types=1);

/**
 * Every `withoutGlobalScope` / `withoutGlobalScopes` call site in `app/`,
 * counted per file. Pinned so a new one cannot appear unnoticed.
 *
 * ## What this proves, and what it does not
 *
 * It does **not** claim these 156 bypasses are correct. Auditing each one is a
 * human job and this file is not the record of it.
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
    'Console/Commands/SyncDevicesCommand.php' => 1,
    'Http/Controllers/Api/V1/Admin/AdminDashboardController.php' => 3,
    // Platform-admin plan catalog. Both sites count subscriptions on one plan,
    // cross-tenant by intent, from a surface that runs with no tenant resolved
    // (EnsurePlatformContext) — where the fail-closed scope would return 0 for
    // every plan. Each states plan_id as its own predicate.
    'Http/Controllers/Api/V1/Admin/AdminPlanController.php' => 2,
    'Http/Controllers/Api/V1/Admin/AdminTenantController.php' => 15,
    'Http/Controllers/Api/V1/Auth/OtpController.php' => 1,
    'Http/Controllers/Api/V1/Auth/PasswordResetController.php' => 2,
    'Http/Controllers/Api/V1/Auth/SubdomainCheckController.php' => 1,
    'Http/Controllers/Api/V1/Device/DeviceController.php' => 5,
    'Http/Controllers/Api/V1/Kiosk/KioskCheckInController.php' => 2,
    'Http/Controllers/Api/V1/Payroll/TaxBracketController.php' => 1,
    'Http/Middleware/ScimAuth.php' => 1,
    'Http/Requests/Auth/LoginRequest.php' => 1,
    'Jobs/BackupTenantJob.php' => 3,
    'Jobs/DispatchWebhookJob.php' => 3,
    'Jobs/GenerateMonthlyInvoicesJob.php' => 2,
    'Jobs/HandleOverdueInvoicesJob.php' => 6,
    'Jobs/NotifyAnnouncementAudienceJob.php' => 2,
    'Jobs/NotifyExpiringTrialsJob.php' => 1,
    'Jobs/ProcessPayrollJob.php' => 2,
    'Jobs/RunDashboardDigestsJob.php' => 1,
    'Jobs/RunScheduledReportsJob.php' => 1,
    'Jobs/ScanMissingPunchesJob.php' => 1,
    'Listeners/NotifyDeviceOffline.php' => 1,
    'Listeners/NotifyPayrollProcessed.php' => 1,
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
    'Services/Billing/BillingService.php' => 5,
    'Services/Dashboard/EmployeeDashboardService.php' => 1,
    'Services/Holiday/HolidayService.php' => 4,
    'Services/Identity/IdentityResolver.php' => 2,
    'Services/Import/EmployeeImporter.php' => 1,
    'Services/Leave/LeaveBalanceService.php' => 5,
    'Services/Leave/LeaveDayCalculator.php' => 1,
    'Services/LoginAttemptService.php' => 1,
    'Services/Migration/WorkforceMigrationService.php' => 1,
    'Services/Onboarding/OrganizationProvisioner.php' => 2,
    'Services/Onboarding/ReadinessScorer.php' => 10,
    'Services/Payroll/AllowanceService.php' => 1,
    'Services/Payroll/OvertimeCalculator.php' => 1,
    'Services/Payroll/PayrollEngine.php' => 4,
    'Services/Payroll/TaxCalculator.php' => 1,
    'Services/Report/ReportEngine.php' => 4,
    'Services/UserProvisioningService.php' => 1,
    'Services/Webhook/WebhookDispatcher.php' => 1,
];
