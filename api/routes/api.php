<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Accounting\AccountingController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminTenantController;
use App\Http\Controllers\Api\V1\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\V1\Analytics\AnalyticsController;
use App\Http\Controllers\Api\V1\Announcement\AnnouncementController;
use App\Http\Controllers\Api\V1\ApiKey\ApiKeyController;
use App\Http\Controllers\Api\V1\Approval\ApprovalController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceConflictController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceCorrectionController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceImportController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceIntelligenceController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceSettingController;
use App\Http\Controllers\Api\V1\Attendance\KioskAttendanceController;
use App\Http\Controllers\Api\V1\Attendance\ManualAttendanceController;
use App\Http\Controllers\Api\V1\Attendance\MobileAttendanceController;
use App\Http\Controllers\Api\V1\Attendance\OfflineSyncController;
use App\Http\Controllers\Api\V1\Attendance\QrAttendanceController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\MfaSetupController;
use App\Http\Controllers\Api\V1\Auth\MfaVerifyController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionClaimController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\SsoController;
use App\Http\Controllers\Api\V1\Auth\SubdomainCheckController;
use App\Http\Controllers\Api\V1\Auth\TenantContextController;
use App\Http\Controllers\Api\V1\Auth\TrustedDeviceController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\Dashboard\AlertThresholdController;
use App\Http\Controllers\Api\V1\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\Dashboard\DashboardDigestController;
use App\Http\Controllers\Api\V1\Dashboard\ExecutiveDashboardController;
use App\Http\Controllers\Api\V1\Device\DeviceController;
use App\Http\Controllers\Api\V1\Device\DeviceEnrollmentController;
use App\Http\Controllers\Api\V1\Directory\DirectoryController;
use App\Http\Controllers\Api\V1\Employee\BankDetailController;
use App\Http\Controllers\Api\V1\Employee\DisciplinaryCaseController;
use App\Http\Controllers\Api\V1\Employee\EducationController;
use App\Http\Controllers\Api\V1\Employee\EmergencyContactController;
use App\Http\Controllers\Api\V1\Employee\EmployeeAttendanceTimelineController;
use App\Http\Controllers\Api\V1\Employee\EmployeeBulkController;
use App\Http\Controllers\Api\V1\Employee\EmployeeContractController;
use App\Http\Controllers\Api\V1\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\Employee\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\Employee\EmployeeImportController;
use App\Http\Controllers\Api\V1\Employee\EmployeeTransitionController;
use App\Http\Controllers\Api\V1\Employee\PersonnelActionController;
use App\Http\Controllers\Api\V1\Employee\RetirementCaseController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Holiday\HolidayController;
use App\Http\Controllers\Api\V1\Kiosk\KioskCheckInController;
use App\Http\Controllers\Api\V1\Kiosk\KioskSessionController;
use App\Http\Controllers\Api\V1\Leave\LeaveRequestController;
use App\Http\Controllers\Api\V1\Leave\LeaveTypeController;
use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\Notification\NotificationPreferencesController;
use App\Http\Controllers\Api\V1\Onboarding\AccessController as OnboardingAccessController;
use App\Http\Controllers\Api\V1\Onboarding\ConfigurationController as OnboardingConfigurationController;
use App\Http\Controllers\Api\V1\Onboarding\MigrationController;
use App\Http\Controllers\Api\V1\Onboarding\ReadinessController as OnboardingReadinessController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\Organization\BranchController;
use App\Http\Controllers\Api\V1\Organization\CostCenterController;
use App\Http\Controllers\Api\V1\Organization\DepartmentController;
use App\Http\Controllers\Api\V1\Organization\GradeController;
use App\Http\Controllers\Api\V1\Organization\GradeSalaryStepController;
use App\Http\Controllers\Api\V1\Organization\PositionController;
use App\Http\Controllers\Api\V1\Organization\TeamController;
use App\Http\Controllers\Api\V1\Payroll\CostSharingController;
use App\Http\Controllers\Api\V1\Payroll\LoanController;
use App\Http\Controllers\Api\V1\Payroll\OvertimeRateController;
use App\Http\Controllers\Api\V1\Payroll\PayrollController;
use App\Http\Controllers\Api\V1\Payroll\PayrollRuleController;
use App\Http\Controllers\Api\V1\Payroll\TaxBracketController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
use App\Http\Controllers\Api\V1\Profile\ProfileEmergencyContactController;
use App\Http\Controllers\Api\V1\Profile\ProfilePhotoController;
use App\Http\Controllers\Api\V1\Profile\ProfilePreferencesController;
use App\Http\Controllers\Api\V1\Profile\ProfileUpdateRequestController;
use App\Http\Controllers\Api\V1\Report\ReportController;
use App\Http\Controllers\Api\V1\Role\CustomRoleController;
use App\Http\Controllers\Api\V1\Scim\ScimGroupController;
use App\Http\Controllers\Api\V1\Scim\ScimUserController;
use App\Http\Controllers\Api\V1\Settings\AuditLogController;
use App\Http\Controllers\Api\V1\Settings\NotificationTemplateController;
use App\Http\Controllers\Api\V1\Settings\SettingsController;
use App\Http\Controllers\Api\V1\Shift\ShiftController;
use App\Http\Controllers\Api\V1\Shift\ShiftRotationController;
use App\Http\Controllers\Api\V1\Team\TeamMonitoringController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\User\UserController;
use App\Http\Controllers\Api\V1\Webhook\WebhookController;
use App\Http\Middleware\BlockImpersonatedActions;
use App\Http\Middleware\EnsurePlatformContext;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\RejectUnverifiedMfaToken;
use App\Http\Middleware\RequirePlatformMfa;
use App\Http\Middleware\RequiresPlanFeature;
use App\Http\Middleware\ScimAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]))
    ->middleware('throttle:health');
Route::get('/health', HealthController::class)->middleware('throttle:health');

// Public endpoints
Route::get('/plans', [PlanController::class, 'index']);
Route::get('/templates', [TemplateController::class, 'index']);
Route::get('/templates/{slug}', [TemplateController::class, 'show']);
Route::post('/contact', ContactController::class)->middleware('throttle:auth');

// Public auth routes
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', RegisterController::class);
    Route::post('/login', LoginController::class);
    // OTP sign-in. The dedicated `otp` limiter (3/min per phone, SMS cost
    // control) existed in AppServiceProvider but had no route to protect.
    Route::post('/otp/request', [OtpController::class, 'request'])->middleware('throttle:otp');
    Route::post('/otp/verify', [OtpController::class, 'verify']);

    Route::post('/password/forgot', [PasswordResetController::class, 'forgot']);
    Route::post('/password/reset', [PasswordResetController::class, 'reset']);

    // Tenant-host half of the impersonation handoff. Necessarily unauthenticated
    // — the caller has no session on this host yet, which is the entire reason
    // the handoff exists. The nonce is the credential, and `throttle:auth`
    // covers this group, so guessing is rate-limited alongside login.
    Route::post('/session/claim', SessionClaimController::class);
});

Route::get('/register/check-subdomain', SubdomainCheckController::class)->middleware('throttle:auth');

// Pre-auth tenant display info for the login page (name/logo only — never
// credentials-adjacent). Loaded once per page view, so it rides the general
// `api` limiter rather than `auth`'s 10/min, which login POSTs also share.
Route::get('/auth/tenant-context', TenantContextController::class)->middleware('throttle:api');

// SSO/SAML endpoints (public — IdP redirects users here)
Route::prefix('sso/saml/{subdomain}')->middleware('throttle:auth')->group(function () {
    Route::get('/initiate', [SsoController::class, 'initiate']);
    Route::post('/acs', [SsoController::class, 'callback']);
    Route::get('/metadata', [SsoController::class, 'metadata']);
});

// SCIM 2.0 provisioning (bearer token auth via ApiKey with 'scim' ability)
Route::prefix('scim/v2')->middleware([ScimAuth::class, 'throttle:api'])->group(function () {
    Route::get('/Users', [ScimUserController::class, 'index']);
    Route::get('/Users/{publicId}', [ScimUserController::class, 'show']);
    Route::post('/Users', [ScimUserController::class, 'store']);
    Route::put('/Users/{publicId}', [ScimUserController::class, 'update']);
    Route::delete('/Users/{publicId}', [ScimUserController::class, 'destroy']);

    Route::get('/Groups', [ScimGroupController::class, 'index']);
    Route::get('/Groups/{publicId}', [ScimGroupController::class, 'show']);
    Route::post('/Groups', [ScimGroupController::class, 'store']);
    Route::put('/Groups/{publicId}', [ScimGroupController::class, 'update']);
    Route::delete('/Groups/{publicId}', [ScimGroupController::class, 'destroy']);
});

// Device webhooks (public endpoints, verified by webhook_token or serial_number)
Route::prefix('devices/webhook')->middleware('throttle:api')->group(function () {
    Route::post('/hikvision', [DeviceController::class, 'webhookHikvision']);
    Route::post('/zkteco', [DeviceController::class, 'webhookZkteco']);
    Route::post('/suprema', [DeviceController::class, 'webhookSuprema']);
});

// Kiosk public endpoints (authenticated by kiosk session token, not user login)
Route::prefix('kiosk')->middleware('throttle:api')->group(function () {
    Route::post('/authenticate', [KioskSessionController::class, 'authenticate']);
    Route::post('/check-in', KioskCheckInController::class);
});

// Authenticated routes
Route::middleware(['auth:sanctum', EnsureUserBelongsToTenant::class, RejectUnverifiedMfaToken::class, BlockImpersonatedActions::class])->group(function () {
    // Broadcasting (Reverb) private-channel auth. Registered here — inside the
    // api/v1 group — so the httpOnly `access_token` cookie (path=/api) is sent and
    // AuthenticateFromCookie can resolve the user. The framework default lives at
    // /broadcasting/auth (web guard), which the token cookie never reaches.
    Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request));

    Route::prefix('auth')->group(function () {
        Route::post('/logout', LogoutController::class);
        Route::post('/refresh', RefreshController::class);
        Route::get('/me', MeController::class);
        Route::post('/password/change', [PasswordResetController::class, 'change']);

        // MFA
        Route::post('/mfa/setup', [MfaSetupController::class, 'setup']);
        Route::post('/mfa/enable', [MfaSetupController::class, 'enable']);
        Route::post('/mfa/disable', [MfaSetupController::class, 'disable']);
        Route::post('/mfa/verify', MfaVerifyController::class);

        // Active session management
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::post('/sessions/revoke-all', [SessionController::class, 'revokeAll']);
        Route::delete('/sessions/{id}', [SessionController::class, 'destroy']);

        // Trusted devices (skip MFA on this browser)
        Route::get('/devices', [TrustedDeviceController::class, 'index']);
        Route::delete('/devices/{id}', [TrustedDeviceController::class, 'destroy']);
    });

    // Onboarding
    Route::prefix('onboarding')->group(function () {
        Route::get('/progress', [OnboardingController::class, 'getProgress']);
        Route::put('/progress/{step}', [OnboardingController::class, 'updateStep']);
        Route::post('/apply-template', [OnboardingController::class, 'applyTemplate']);
        Route::post('/invite', [OnboardingController::class, 'inviteTeam']);
        Route::post('/complete', [OnboardingController::class, 'complete']);

        // Smart configuration (OnboardingStep::SMART_CONFIGURATION)
        Route::get('/industries', [OnboardingConfigurationController::class, 'industries']);
        Route::post('/configuration/preview', [OnboardingConfigurationController::class, 'preview']);
        Route::post('/configuration/apply', [OnboardingConfigurationController::class, 'apply']);

        // Access & identity (OnboardingStep::ACCESS_IDENTITY)
        Route::get('/access', [OnboardingAccessController::class, 'show']);
        Route::put('/access', [OnboardingAccessController::class, 'update']);

        // Readiness & Go Live (OnboardingStep::READINESS_GO_LIVE)
        Route::get('/readiness', [OnboardingReadinessController::class, 'show']);
        Route::post('/go-live', [OnboardingReadinessController::class, 'goLive']);

        // Workforce migration (OnboardingStep::WORKFORCE_MIGRATION)
        Route::post('/migration/devices/{device}', [MigrationController::class, 'stageFromDevice']);
        Route::post('/migration/rows', [MigrationController::class, 'stageFromRows']);
        Route::get('/migration/batches/{batch}', [MigrationController::class, 'show']);
        Route::patch('/migration/rows/{row}', [MigrationController::class, 'updateRow']);
        Route::post('/migration/batches/{batch}/commit', [MigrationController::class, 'commit']);
    });

    // User & access management (invite/activate any role)
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::patch('/{user}', [UserController::class, 'update']);
        Route::post('/{user}/resend-invite', [UserController::class, 'resendInvite']);
        Route::delete('/{user}', [UserController::class, 'destroy']);
    });

    // Attendance
    Route::prefix('attendance')->group(function () {
        Route::post('/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('/my', [AttendanceController::class, 'my']);
        Route::get('/team', [AttendanceController::class, 'team']);
        Route::get('/today', [AttendanceController::class, 'today']);

        Route::post('/manual', [ManualAttendanceController::class, 'store']);
        Route::post('/kiosk', [KioskAttendanceController::class, 'store']);

        Route::post('/import/template', [AttendanceImportController::class, 'template']);
        Route::post('/import/preview', [AttendanceImportController::class, 'preview']);
        Route::post('/import/commit', [AttendanceImportController::class, 'commit']);
        Route::post('/import/legacy', [AttendanceImportController::class, 'parseLegacy']);

        Route::post('/mobile/check-in', [MobileAttendanceController::class, 'checkIn']);
        Route::post('/mobile/check-out', [MobileAttendanceController::class, 'checkOut']);

        Route::get('/qr/generate', [QrAttendanceController::class, 'generate']);
        Route::post('/qr', [QrAttendanceController::class, 'scan']);

        Route::post('/sync', [OfflineSyncController::class, 'sync']);

        Route::get('/intelligence', [AttendanceIntelligenceController::class, 'dashboard']);
        Route::get('/overtime', [AttendanceIntelligenceController::class, 'overtime']);

        Route::get('/settings', [AttendanceSettingController::class, 'show']);
        Route::put('/settings', [AttendanceSettingController::class, 'update']);

        Route::prefix('corrections')->group(function () {
            Route::get('/', [AttendanceCorrectionController::class, 'index']);
            Route::get('/pending', [AttendanceCorrectionController::class, 'pending']);
            Route::post('/', [AttendanceCorrectionController::class, 'store']);
            Route::get('/{correction}/payroll-impact', [AttendanceCorrectionController::class, 'payrollImpact']);
            Route::put('/{correction}/approve', [AttendanceCorrectionController::class, 'approve']);
            Route::put('/{correction}/reject', [AttendanceCorrectionController::class, 'reject']);
        });

        Route::prefix('conflicts')->group(function () {
            Route::get('/', [AttendanceConflictController::class, 'index']);
            Route::put('/{conflict}/resolve', [AttendanceConflictController::class, 'resolve']);
        });

        Route::get('/', [AttendanceController::class, 'index']);
        Route::get('/{attendanceRecord}', [AttendanceController::class, 'show']);
    });

    // Shifts
    Route::prefix('shifts')->group(function () {
        Route::post('/assign', [ShiftController::class, 'assign']);
        Route::get('/schedule', [ShiftController::class, 'schedule']);
    });

    // Shift rotations (multi-week / non-weekly repeating patterns).
    // Declared before the `shifts` apiResource so "rotations" is not captured
    // as a {shift} route-model binding.
    Route::prefix('shift-rotations')->group(function () {
        Route::post('/assign', [ShiftRotationController::class, 'assign']);
        Route::get('/{rotation}/preview', [ShiftRotationController::class, 'preview']);
    });
    Route::apiResource('shift-rotations', ShiftRotationController::class)
        ->parameters(['shift-rotations' => 'rotation'])
        ->except(['edit', 'create']);

    Route::apiResource('shifts', ShiftController::class);

    // Employees
    Route::get('/employees/stats', [EmployeeController::class, 'stats']);
    Route::get('/employees/export', [EmployeeBulkController::class, 'export']);

    // Tenant-wide expiring-document watchlist. Declared before the
    // /employees/{employee} routes so "documents" is not captured as an employee.
    Route::get('/employees/documents/expiring', [EmployeeDocumentController::class, 'expiring']);
    // Same reasoning for the expiring-contract watchlist.
    Route::get('/employees/contracts/expiring', [EmployeeContractController::class, 'expiring']);

    // Recover the outcome of a committed import when the client lost the response.
    Route::get('/employees/import/status/{key}', [EmployeeImportController::class, 'status']);
    Route::post('/employees/bulk-update', [EmployeeBulkController::class, 'bulkUpdate']);
    Route::post('/employees/import/template', [EmployeeImportController::class, 'template'])->middleware('throttle:imports');
    Route::post('/employees/import/preview', [EmployeeImportController::class, 'preview'])->middleware('throttle:imports');
    Route::post('/employees/import/commit', [EmployeeImportController::class, 'commit'])->middleware('throttle:imports');
    Route::apiResource('employees', EmployeeController::class);

    // scopeBindings(): a child must belong to the {employee} in the path. Without it
    // Laravel resolves each child by its own key alone, so any child id in the tenant
    // resolves under any employee's URL — and the audit record names the wrong person.
    // Child parameter names must match the Employee relationship they scope through
    // (Str::plural(Str::camel($param))): bankDetail→bankDetails, document→documents,
    // education→education, emergencyContact→emergencyContacts.
    Route::prefix('employees/{employee}')->scopeBindings()->group(function () {
        Route::get('/transitions', [EmployeeTransitionController::class, 'index']);
        Route::post('/transition', [EmployeeTransitionController::class, 'store']);
        Route::get('/personnel-actions', [PersonnelActionController::class, 'index']);
        Route::post('/personnel-actions', [PersonnelActionController::class, 'store']);
        Route::get('/disciplinary-cases', [DisciplinaryCaseController::class, 'index']);
        Route::post('/disciplinary-cases', [DisciplinaryCaseController::class, 'store']);
        Route::post('/disciplinary-cases/{disciplinaryCase}/notes', [DisciplinaryCaseController::class, 'addNote']);
        Route::post('/disciplinary-cases/{disciplinaryCase}/decision', [DisciplinaryCaseController::class, 'decide']);
        Route::post('/disciplinary-cases/{disciplinaryCase}/appeal', [DisciplinaryCaseController::class, 'appeal']);
        Route::post('/disciplinary-cases/{disciplinaryCase}/appeal-decision', [DisciplinaryCaseController::class, 'resolveAppeal']);
        Route::post('/disciplinary-cases/{disciplinaryCase}/close', [DisciplinaryCaseController::class, 'close']);
        Route::get('/retirement-cases', [RetirementCaseController::class, 'index']);
        Route::post('/retirement-cases', [RetirementCaseController::class, 'store']);
        Route::post('/retirement-cases/{retirementCase}/notes', [RetirementCaseController::class, 'addNote']);
        Route::post('/retirement-cases/{retirementCase}/decision', [RetirementCaseController::class, 'decide']);
        Route::post('/retirement-cases/{retirementCase}/finalize', [RetirementCaseController::class, 'finalize']);
        Route::post('/retirement-cases/{retirementCase}/cancel', [RetirementCaseController::class, 'cancel']);
        Route::get('/contracts', [EmployeeContractController::class, 'index']);
        Route::post('/contracts', [EmployeeContractController::class, 'store']);
        Route::post('/contracts/{contract}/renew', [EmployeeContractController::class, 'renew']);
        Route::post('/contracts/{contract}/end', [EmployeeContractController::class, 'end']);
        Route::get('/attendance/timeline', EmployeeAttendanceTimelineController::class);

        Route::get('/emergency-contacts', [EmergencyContactController::class, 'index']);
        Route::post('/emergency-contacts', [EmergencyContactController::class, 'store']);
        Route::put('/emergency-contacts/{emergencyContact}', [EmergencyContactController::class, 'update']);
        Route::delete('/emergency-contacts/{emergencyContact}', [EmergencyContactController::class, 'destroy']);

        Route::get('/bank-details', [BankDetailController::class, 'index']);
        Route::post('/bank-details', [BankDetailController::class, 'store']);
        Route::put('/bank-details/{bankDetail}', [BankDetailController::class, 'update']);
        Route::delete('/bank-details/{bankDetail}', [BankDetailController::class, 'destroy']);

        Route::get('/education', [EducationController::class, 'index']);
        Route::post('/education', [EducationController::class, 'store']);
        Route::put('/education/{education}', [EducationController::class, 'update']);
        Route::delete('/education/{education}', [EducationController::class, 'destroy']);

        Route::get('/documents', [EmployeeDocumentController::class, 'index']);
        // `uploads` limiter (30/min per user) — was defined but unapplied, so file
        // uploads carried only the global 60/min API limit.
        Route::post('/documents', [EmployeeDocumentController::class, 'store'])
            ->middleware('throttle:uploads');
        Route::get('/documents/{document}', [EmployeeDocumentController::class, 'show']);
        Route::delete('/documents/{document}', [EmployeeDocumentController::class, 'destroy']);
    });

    // Kiosk Sessions (admin management)
    Route::prefix('kiosk-sessions')->group(function () {
        Route::get('/', [KioskSessionController::class, 'index']);
        Route::post('/', [KioskSessionController::class, 'store']);
        Route::get('/{kioskSession}', [KioskSessionController::class, 'show']);
        Route::post('/{kioskSession}/deactivate', [KioskSessionController::class, 'deactivate']);
        Route::post('/{kioskSession}/activate', [KioskSessionController::class, 'activate']);
        Route::post('/{kioskSession}/regenerate-token', [KioskSessionController::class, 'regenerateToken']);
        Route::delete('/{kioskSession}', [KioskSessionController::class, 'destroy']);
    });

    // Devices
    Route::prefix('devices')->group(function () {
        Route::get('/dashboard', [DeviceController::class, 'dashboard']);
        Route::post('/sync-all', [DeviceController::class, 'syncAll']);
        Route::get('/{device}/status', [DeviceController::class, 'status']);
        Route::post('/{device}/pull', [DeviceController::class, 'pull']);
        Route::post('/{device}/import-history', [DeviceController::class, 'importHistory']);
        Route::post('/{device}/regenerate-token', [DeviceController::class, 'regenerateToken']);
        Route::get('/{device}/sync-logs', [DeviceController::class, 'syncLogs']);
        Route::get('/{device}/events', [DeviceController::class, 'deviceEvents']);
        Route::get('/{device}/enrollments', [DeviceEnrollmentController::class, 'index']);
    });
    Route::apiResource('devices', DeviceController::class);

    // Leave
    Route::prefix('leave')->group(function () {
        Route::post('/request', [LeaveRequestController::class, 'store']);
        Route::get('/my', [LeaveRequestController::class, 'my']);
        Route::get('/team', [LeaveRequestController::class, 'team']);
        Route::get('/balance', [LeaveRequestController::class, 'balance']);
        Route::get('/balance/{employee}', [LeaveRequestController::class, 'employeeBalance']);
        Route::put('/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
        Route::put('/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
        Route::put('/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
        // Kept last so the literal GET routes above are matched first.
        Route::get('/{leaveRequest}', [LeaveRequestController::class, 'show']);
    });
    Route::apiResource('leave-types', LeaveTypeController::class);

    // Payroll
    //
    // Plan gating is on the paths that *produce* payroll, not the ones that read
    // it back. A tenant that downgrades keeps its runs, payslips and bank
    // exports visible — withdrawing sight of a period an employee was already
    // paid for would be a records problem, not a billing control.
    Route::prefix('payroll')->group(function () {
        Route::post('/process', [PayrollController::class, 'process'])
            ->middleware(['throttle:payroll-process', RequiresPlanFeature::class.':payroll']);
        Route::get('/runs', [PayrollController::class, 'index']);
        Route::get('/runs/{payrollRun}', [PayrollController::class, 'show']);
        Route::put('/runs/{payrollRun}/approve', [PayrollController::class, 'approve'])
            ->middleware(RequiresPlanFeature::class.':payroll');
        Route::post('/runs/{payrollRun}/void', [PayrollController::class, 'void'])
            ->middleware(RequiresPlanFeature::class.':payroll');
        Route::post('/runs/{payrollRun}/reprocess', [PayrollController::class, 'reprocess'])
            ->middleware(['throttle:payroll-process', RequiresPlanFeature::class.':payroll']);
        Route::get('/runs/{payrollRun}/export/bank', [PayrollController::class, 'bankExport']);
        Route::get('/runs/{payrollRun}/export/bank-csv', [PayrollController::class, 'downloadBankExport']);
        Route::get('/payslips/my', [PayrollController::class, 'myPayslips']);
        Route::get('/payslips/{employeePublicId}', [PayrollController::class, 'employeePayslips']);
        Route::get('/payslips/{payrollEntry}/pdf', [PayrollController::class, 'downloadPayslip']);

        Route::get('/loans', [LoanController::class, 'index']);
        Route::post('/loans', [LoanController::class, 'store']);
        Route::get('/loans/{loan}', [LoanController::class, 'show']);
        Route::put('/loans/{loan}', [LoanController::class, 'update']);
        Route::put('/loans/{loan}/cancel', [LoanController::class, 'cancel']);

        // Cost sharing has no DELETE: an obligation that payroll has deducted
        // against is referenced by approved runs, so it is cancelled via status.
        Route::get('/cost-sharing', [CostSharingController::class, 'index']);
        Route::post('/cost-sharing', [CostSharingController::class, 'store']);
        Route::get('/cost-sharing/{costSharing}', [CostSharingController::class, 'show']);
        Route::put('/cost-sharing/{costSharing}', [CostSharingController::class, 'update']);

        // Payroll configuration
        Route::get('/tax-brackets', [TaxBracketController::class, 'index']);
        Route::put('/tax-brackets', [TaxBracketController::class, 'replace']);
        Route::get('/overtime-rates', [OvertimeRateController::class, 'show']);
        Route::put('/overtime-rates', [OvertimeRateController::class, 'update']);
        Route::apiResource('rules', PayrollRuleController::class)->parameters(['rules' => 'payrollRule']);
    });

    // Holidays
    Route::post('/holidays/auto-detect', [HolidayController::class, 'autoDetect']);
    Route::apiResource('holidays', HolidayController::class);

    // Dashboards
    Route::prefix('dashboard')->middleware('throttle:dashboard')->group(function () {
        Route::get('/employee', [DashboardController::class, 'employee']);
        Route::get('/manager', [DashboardController::class, 'manager']);
        Route::get('/executive', [ExecutiveDashboardController::class, 'overview']);
        Route::get('/executive/attendance', [ExecutiveDashboardController::class, 'attendance']);
        Route::get('/executive/payroll', [ExecutiveDashboardController::class, 'payroll']);
        Route::get('/executive/workforce', [ExecutiveDashboardController::class, 'workforce']);
        Route::get('/executive/compliance', [ExecutiveDashboardController::class, 'compliance']);
        Route::get('/executive/forecast', [ExecutiveDashboardController::class, 'forecast']);
        Route::post('/digests', [DashboardDigestController::class, 'store']);
        Route::get('/digests', [DashboardDigestController::class, 'index']);
        Route::delete('/digests/{dashboardDigest}', [DashboardDigestController::class, 'destroy']);
        Route::post('/alert-thresholds', [AlertThresholdController::class, 'store']);
        Route::get('/alert-thresholds', [AlertThresholdController::class, 'index']);
        Route::get('/alert-thresholds/triggered', [AlertThresholdController::class, 'triggered']);
        Route::delete('/alert-thresholds/{alertThreshold}', [AlertThresholdController::class, 'destroy']);
    });

    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('/departments', [AnalyticsController::class, 'departments']);
        Route::get('/departments/{department}', [AnalyticsController::class, 'departmentDetail']);
        Route::get('/branches', [AnalyticsController::class, 'branches']);
        Route::get('/branches/{branch}', [AnalyticsController::class, 'branchDetail']);
    });

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/sources', [ReportController::class, 'sources']);
        Route::post('/generate', [ReportController::class, 'generate'])
            ->middleware(RequiresPlanFeature::class.':reports');
        Route::post('/export', [ReportController::class, 'export'])
            ->middleware(RequiresPlanFeature::class.':reports');

        // `custom_reports` is the Enterprise tier's own key, distinct from
        // `reports`: saving and scheduling a report definition is the paid
        // capability, running one ad hoc is not. Deletes stay ungated so a
        // downgraded tenant can still clean up what it created.
        Route::post('/save', [ReportController::class, 'save'])
            ->middleware(RequiresPlanFeature::class.':custom_reports');
        Route::get('/saved', [ReportController::class, 'savedList']);
        Route::delete('/saved/{savedReport}', [ReportController::class, 'deleteSaved']);
        Route::post('/schedule', [ReportController::class, 'schedule'])
            ->middleware(RequiresPlanFeature::class.':custom_reports');
        Route::get('/scheduled', [ReportController::class, 'scheduledList']);
        Route::delete('/scheduled/{scheduledReport}', [ReportController::class, 'deleteScheduled']);
    });

    // Profile self-service
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/preferences', [ProfilePreferencesController::class, 'update']);

    // The photo is its own endpoint because PHP populates $_FILES on POST only —
    // a `photo` field on the PUT above could never have arrived.
    Route::post('/profile/photo', [ProfilePhotoController::class, 'store'])->middleware('throttle:uploads');
    Route::delete('/profile/photo', [ProfilePhotoController::class, 'destroy']);

    // Own emergency contacts — the employee-facing twin of the HR-managed routes
    // under /employees/{employee}/emergency-contacts.
    Route::get('/profile/emergency-contacts', [ProfileEmergencyContactController::class, 'index']);
    Route::post('/profile/emergency-contacts', [ProfileEmergencyContactController::class, 'store']);
    Route::put('/profile/emergency-contacts/{contact}', [ProfileEmergencyContactController::class, 'update']);
    Route::delete('/profile/emergency-contacts/{contact}', [ProfileEmergencyContactController::class, 'destroy']);

    // HR review queue for approval-gated profile fields
    Route::get('/profile-update-requests', [ProfileUpdateRequestController::class, 'index']);
    Route::post('/profile-update-requests/{profileUpdateRequest}/review', [ProfileUpdateRequestController::class, 'review']);
    // Employee-side: retract a request nobody has reviewed yet.
    Route::delete('/profile-update-requests/{profileUpdateRequest}', [ProfileUpdateRequestController::class, 'withdraw']);

    // Team monitoring (manager)
    Route::prefix('team')->group(function () {
        Route::get('/attendance/today', [TeamMonitoringController::class, 'attendanceToday']);
        Route::get('/attendance/summary', [TeamMonitoringController::class, 'attendanceSummary']);
        Route::get('/overtime', [TeamMonitoringController::class, 'overtime']);
        Route::get('/leave/calendar', [TeamMonitoringController::class, 'leaveCalendar']);
    });

    // Directory
    Route::get('/directory', [DirectoryController::class, 'index']);

    // Announcements
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);

    // Approvals
    Route::get('/approvals/pending', [ApprovalController::class, 'pending']);
    Route::post('/approvals/batch', [ApprovalController::class, 'batch']);

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::get('/preferences', [NotificationPreferencesController::class, 'index']);
        Route::put('/preferences', [NotificationPreferencesController::class, 'update']);
        Route::put('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::put('/{id}/read', [NotificationController::class, 'markAsRead']);
    });

    // API Keys
    Route::prefix('api-keys')->group(function () {
        Route::get('/', [ApiKeyController::class, 'index']);
        // Issuing a key is the capability; listing and revoking stay open so a
        // downgraded tenant can see and withdraw keys that are still live.
        Route::post('/', [ApiKeyController::class, 'store'])
            ->middleware(RequiresPlanFeature::class.':api_access');
        Route::delete('/{apiKey}', [ApiKeyController::class, 'destroy']);
    });

    // Webhooks
    Route::prefix('webhooks')->group(function () {
        Route::get('/', [WebhookController::class, 'index']);
        Route::post('/', [WebhookController::class, 'store'])
            ->middleware(RequiresPlanFeature::class.':webhooks');
        Route::put('/{webhook}', [WebhookController::class, 'update'])
            ->middleware(RequiresPlanFeature::class.':webhooks');
        // Delete stays open: a downgraded tenant must be able to switch off an
        // endpoint that is still receiving its data.
        Route::delete('/{webhook}', [WebhookController::class, 'destroy']);
        // The `webhooks-test` limiter (10/hour per tenant, CLAUDE.md rate-limit
        // policy) was defined but applied to no route, so the abuse control it
        // documents was never actually in force.
        Route::post('/{webhook}/test', [WebhookController::class, 'test'])
            ->middleware('throttle:webhooks-test');
        Route::get('/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
    });

    // Accounting
    Route::prefix('accounting')->group(function () {
        Route::get('/chart-of-accounts', [AccountingController::class, 'chartOfAccounts']);
        Route::put('/chart-of-accounts', [AccountingController::class, 'updateChartOfAccounts']);
        Route::get('/journal/{payrollRun}', [AccountingController::class, 'journal']);
        Route::get('/export/{payrollRun}', [AccountingController::class, 'export']);
    });

    // Admin (Super Admin only)
    // Platform administration — served only where no tenant is resolved, i.e.
    // admin.ethr.et. nginx already refuses the /admin *console* elsewhere; this
    // covers the API, which that rule does not match.
    // Platform administration.
    //
    // `RequirePlatformMfa` — an operator reaching across every tenant may not
    // change anything behind a password alone (impersonation already demanded
    // MFA; nothing else did). `throttle:platform-admin` — this surface drives
    // cross-tenant queries and queue operations, so it gets its own budget
    // rather than sharing the global 60/min every tenant endpoint uses.
    Route::prefix('admin')
        ->middleware([EnsurePlatformContext::class, RequirePlatformMfa::class, 'throttle:platform-admin'])
        ->group(function () {
            Route::get('/tenants', [AdminTenantController::class, 'index']);
            Route::get('/tenants/{publicId}', [AdminTenantController::class, 'show']);
            Route::put('/tenants/{publicId}/status', [AdminTenantController::class, 'updateStatus']);
            Route::post('/tenants/{publicId}/extend-trial', [AdminTenantController::class, 'extendTrial']);
            Route::post('/tenants/{publicId}/impersonate', [AdminTenantController::class, 'impersonate']);
            Route::post('/exit-impersonation', [AdminTenantController::class, 'exitImpersonation']);
            Route::post('/tenants/{publicId}/backup', [AdminTenantController::class, 'backup']);
            Route::get('/revenue', [AdminDashboardController::class, 'revenue']);
            Route::get('/health', [AdminDashboardController::class, 'health']);
            Route::get('/audit', [AdminDashboardController::class, 'auditLog']);
            Route::get('/failed-jobs', [AdminDashboardController::class, 'failedJobs']);
            Route::post('/failed-jobs/{uuid}/retry', [AdminDashboardController::class, 'retryFailedJob']);
            Route::post('/failed-jobs/retry-all', [AdminDashboardController::class, 'retryAllFailedJobs']);
            // CLAUDE.md's Queue Failure Recovery table promises "retry/dismiss"
            // actions here; only retry existed, so a job that can never succeed sat
            // in the console's Attention Required banner permanently.
            Route::delete('/failed-jobs/{uuid}', [AdminDashboardController::class, 'dismissFailedJob']);

            // Cross-tenant operator lookup: "which tenant is this person on?" is the
            // first question every support request starts with, and nothing answered
            // it without direct database access.
            Route::get('/users/search', [AdminDashboardController::class, 'searchUsers']);

            // Platform-wide settings (bank account tenants pay into, etc.)
            Route::get('/platform-settings', [PlatformSettingsController::class, 'show']);
            Route::put('/platform-settings', [PlatformSettingsController::class, 'update']);
        });

    // Billing
    Route::prefix('billing')->group(function () {
        Route::get('/dashboard', [BillingController::class, 'dashboard']);
        Route::post('/change-plan', [BillingController::class, 'changePlan']);
        Route::put('/invoices/{invoice}/mark-paid', [BillingController::class, 'markPaid']);
        Route::get('/invoices/{invoice}/receipt', [BillingController::class, 'receipt']);
    });

    // Settings
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::put('/settings/organization', [SettingsController::class, 'updateOrganization']);
    Route::put('/settings/branding', [SettingsController::class, 'updateBranding']);
    // Multipart, so POST: PHP does not populate $_FILES on a PUT, which is why
    // the logo cannot simply be another field on the branding endpoint.
    Route::post('/settings/branding/logo', [SettingsController::class, 'uploadBrandingLogo'])
        ->middleware('throttle:uploads');

    // The public landing page served at {tenant}.ethr.et — see routes/public.php
    // for the anonymous half. These are the authenticated controls for it.
    Route::get('/settings/public-page', [SettingsController::class, 'showPublicPage']);
    Route::put('/settings/public-page', [SettingsController::class, 'updatePublicPage']);
    Route::post('/settings/public-page/hero', [SettingsController::class, 'uploadPublicHero'])
        ->middleware('throttle:uploads');
    Route::put('/settings/sso', [SettingsController::class, 'updateSso']);
    Route::post('/settings/scim-token', [SettingsController::class, 'generateScimToken']);
    Route::get('/settings/notification-templates', [NotificationTemplateController::class, 'index']);
    Route::put('/settings/notification-templates/{type}', [NotificationTemplateController::class, 'update']);

    // Audit logs
    //
    // The one gate applied to a read, and deliberately so: for every other
    // feature the paid capability is *doing* something, so gating the write
    // enforces the tier while leaving history visible. Here the capability IS
    // the read — gating anything else would leave `audit_log` unenforceable.
    // Records are still written for every tenant regardless of plan (convention
    // #5 is a compliance guarantee, not a tier benefit); only reading them back
    // through the API is tiered.
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware(RequiresPlanFeature::class.':audit_log');

    // Custom roles
    Route::get('/permissions', [CustomRoleController::class, 'permissions']);
    Route::apiResource('roles', CustomRoleController::class)->parameters(['roles' => 'customRole']);

    // Organization structure
    Route::prefix('organization')->group(function () {
        Route::get('/tree', [DepartmentController::class, 'tree']);
        Route::get('/reporting-tree', [EmployeeController::class, 'reportingTree']);

        Route::apiResource('branches', BranchController::class);
        Route::apiResource('departments', DepartmentController::class);
        Route::apiResource('teams', TeamController::class);
        Route::apiResource('positions', PositionController::class);
        Route::apiResource('grades', GradeController::class);
        Route::apiResource('cost-centers', CostCenterController::class);

        // A child must belong to the {grade} in the path — same reasoning as
        // the employees/{employee} child routes below.
        Route::prefix('grades/{grade}')->scopeBindings()->group(function () {
            Route::get('/salary-steps', [GradeSalaryStepController::class, 'index']);
            Route::post('/salary-steps', [GradeSalaryStepController::class, 'store']);
            Route::put('/salary-steps/{salaryStep}', [GradeSalaryStepController::class, 'update']);
            Route::delete('/salary-steps/{salaryStep}', [GradeSalaryStepController::class, 'destroy']);
        });
    });
});
