<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\MfaSetupController;
use App\Http\Controllers\Api\V1\Auth\MfaVerifyController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SubdomainCheckController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\Organization\BranchController;
use App\Http\Controllers\Api\V1\Organization\CostCenterController;
use App\Http\Controllers\Api\V1\Organization\DepartmentController;
use App\Http\Controllers\Api\V1\Organization\GradeController;
use App\Http\Controllers\Api\V1\Organization\PositionController;
use App\Http\Controllers\Api\V1\Organization\TeamController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceImportController;
use App\Http\Controllers\Api\V1\Attendance\KioskAttendanceController;
use App\Http\Controllers\Api\V1\Attendance\ManualAttendanceController;
use App\Http\Controllers\Api\V1\Attendance\MobileAttendanceController;
use App\Http\Controllers\Api\V1\Attendance\OfflineSyncController;
use App\Http\Controllers\Api\V1\Attendance\QrAttendanceController;
use App\Http\Controllers\Api\V1\Employee\BankDetailController;
use App\Http\Controllers\Api\V1\Employee\EducationController;
use App\Http\Controllers\Api\V1\Employee\EmergencyContactController;
use App\Http\Controllers\Api\V1\Employee\EmployeeBulkController;
use App\Http\Controllers\Api\V1\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\Employee\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\Employee\EmployeeImportController;
use App\Http\Controllers\Api\V1\Employee\EmployeeAttendanceTimelineController;
use App\Http\Controllers\Api\V1\Employee\EmployeeTransitionController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceCorrectionController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceIntelligenceController;
use App\Http\Controllers\Api\V1\Device\DeviceController;
use App\Http\Controllers\Api\V1\Holiday\HolidayController;
use App\Http\Controllers\Api\V1\Payroll\LoanController;
use App\Http\Controllers\Api\V1\Payroll\PayrollController;
use App\Http\Controllers\Api\V1\Leave\LeaveRequestController;
use App\Http\Controllers\Api\V1\Leave\LeaveTypeController;
use App\Http\Controllers\Api\V1\Accounting\AccountingController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminTenantController;
use App\Http\Controllers\Api\V1\Analytics\AnalyticsController;
use App\Http\Controllers\Api\V1\ApiKey\ApiKeyController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\Settings\AuditLogController;
use App\Http\Controllers\Api\V1\Settings\SettingsController;
use App\Http\Controllers\Api\V1\Webhook\WebhookController;
use App\Http\Controllers\Api\V1\Announcement\AnnouncementController;
use App\Http\Controllers\Api\V1\Approval\ApprovalController;
use App\Http\Controllers\Api\V1\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\Dashboard\ExecutiveDashboardController;
use App\Http\Controllers\Api\V1\Directory\DirectoryController;
use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\Notification\NotificationPreferencesController;
use App\Http\Controllers\Api\V1\Report\ReportController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Shift\ShiftController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
use App\Http\Controllers\Api\V1\Team\TeamMonitoringController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]));
Route::get('/health', HealthController::class);

// Public endpoints
Route::get('/plans', [PlanController::class, 'index']);
Route::get('/templates', [TemplateController::class, 'index']);
Route::get('/templates/{slug}', [TemplateController::class, 'show']);
Route::post('/contact', ContactController::class)->middleware('throttle:auth');

// Public auth routes
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', RegisterController::class);
    Route::post('/login', LoginController::class);
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot']);
    Route::post('/password/reset', [PasswordResetController::class, 'reset']);
});

Route::get('/register/check-subdomain', SubdomainCheckController::class)->middleware('throttle:auth');

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
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
    });

    // Onboarding
    Route::prefix('onboarding')->group(function () {
        Route::get('/progress', [OnboardingController::class, 'getProgress']);
        Route::put('/progress/{step}', [OnboardingController::class, 'updateStep']);
        Route::post('/apply-template', [OnboardingController::class, 'applyTemplate']);
        Route::post('/complete', [OnboardingController::class, 'complete']);
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

        Route::post('/mobile/check-in', [MobileAttendanceController::class, 'checkIn']);
        Route::post('/mobile/check-out', [MobileAttendanceController::class, 'checkOut']);

        Route::get('/qr/generate', [QrAttendanceController::class, 'generate']);
        Route::post('/qr', [QrAttendanceController::class, 'scan']);

        Route::post('/sync', [OfflineSyncController::class, 'sync']);

        Route::get('/intelligence', [AttendanceIntelligenceController::class, 'dashboard']);
        Route::get('/overtime', [AttendanceIntelligenceController::class, 'overtime']);

        Route::prefix('corrections')->group(function () {
            Route::get('/', [AttendanceCorrectionController::class, 'index']);
            Route::get('/pending', [AttendanceCorrectionController::class, 'pending']);
            Route::post('/', [AttendanceCorrectionController::class, 'store']);
            Route::put('/{correction}/approve', [AttendanceCorrectionController::class, 'approve']);
            Route::put('/{correction}/reject', [AttendanceCorrectionController::class, 'reject']);
        });

        Route::get('/', [AttendanceController::class, 'index']);
        Route::get('/{attendanceRecord}', [AttendanceController::class, 'show']);
    });

    // Shifts
    Route::prefix('shifts')->group(function () {
        Route::post('/assign', [ShiftController::class, 'assign']);
        Route::get('/schedule', [ShiftController::class, 'schedule']);
    });
    Route::apiResource('shifts', ShiftController::class);

    // Employees
    Route::get('/employees/stats', [EmployeeController::class, 'stats']);
    Route::get('/employees/export', [EmployeeBulkController::class, 'export']);
    Route::post('/employees/bulk-update', [EmployeeBulkController::class, 'bulkUpdate']);
    Route::post('/employees/import/template', [EmployeeImportController::class, 'template']);
    Route::post('/employees/import/preview', [EmployeeImportController::class, 'preview']);
    Route::post('/employees/import/commit', [EmployeeImportController::class, 'commit']);
    Route::apiResource('employees', EmployeeController::class);

    Route::prefix('employees/{employee}')->group(function () {
        Route::get('/transitions', [EmployeeTransitionController::class, 'index']);
        Route::post('/transition', [EmployeeTransitionController::class, 'store']);
        Route::get('/attendance/timeline', EmployeeAttendanceTimelineController::class);

        Route::get('/emergency-contacts', [EmergencyContactController::class, 'index']);
        Route::post('/emergency-contacts', [EmergencyContactController::class, 'store']);
        Route::put('/emergency-contacts/{contact}', [EmergencyContactController::class, 'update']);
        Route::delete('/emergency-contacts/{contact}', [EmergencyContactController::class, 'destroy']);

        Route::get('/bank-details', [BankDetailController::class, 'index']);
        Route::post('/bank-details', [BankDetailController::class, 'store']);
        Route::put('/bank-details/{bankDetail}', [BankDetailController::class, 'update']);
        Route::delete('/bank-details/{bankDetail}', [BankDetailController::class, 'destroy']);

        Route::get('/education', [EducationController::class, 'index']);
        Route::post('/education', [EducationController::class, 'store']);
        Route::put('/education/{education}', [EducationController::class, 'update']);
        Route::delete('/education/{education}', [EducationController::class, 'destroy']);

        Route::get('/documents', [EmployeeDocumentController::class, 'index']);
        Route::post('/documents', [EmployeeDocumentController::class, 'store']);
        Route::get('/documents/{document}', [EmployeeDocumentController::class, 'show']);
        Route::delete('/documents/{document}', [EmployeeDocumentController::class, 'destroy']);
    });

    // Devices
    Route::prefix('devices')->group(function () {
        Route::get('/dashboard', [DeviceController::class, 'dashboard']);
        Route::get('/{device}/status', [DeviceController::class, 'status']);
        Route::post('/{device}/pull', [DeviceController::class, 'pull']);
    });
    Route::apiResource('devices', DeviceController::class);

    // Device webhooks (inside auth for tenant resolution, but also accessible externally)
    Route::post('/devices/webhook/hikvision', [DeviceController::class, 'webhookHikvision']);
    Route::post('/devices/webhook/zkteco', [DeviceController::class, 'webhookZkteco']);

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
    });
    Route::apiResource('leave-types', LeaveTypeController::class);

    // Payroll
    Route::prefix('payroll')->group(function () {
        Route::post('/process', [PayrollController::class, 'process']);
        Route::get('/runs', [PayrollController::class, 'index']);
        Route::get('/runs/{payrollRun}', [PayrollController::class, 'show']);
        Route::put('/runs/{payrollRun}/approve', [PayrollController::class, 'approve']);
        Route::get('/runs/{payrollRun}/export/bank', [PayrollController::class, 'bankExport']);
        Route::get('/payslips/my', [PayrollController::class, 'myPayslips']);
        Route::get('/payslips/{employeePublicId}', [PayrollController::class, 'employeePayslips']);

        Route::get('/loans', [LoanController::class, 'index']);
        Route::post('/loans', [LoanController::class, 'store']);
        Route::get('/loans/{loan}', [LoanController::class, 'show']);
    });

    // Holidays
    Route::post('/holidays/auto-detect', [HolidayController::class, 'autoDetect']);
    Route::apiResource('holidays', HolidayController::class);

    // Dashboards
    Route::prefix('dashboard')->group(function () {
        Route::get('/employee', [DashboardController::class, 'employee']);
        Route::get('/manager', [DashboardController::class, 'manager']);
        Route::get('/executive', [ExecutiveDashboardController::class, 'overview']);
        Route::get('/executive/attendance', [ExecutiveDashboardController::class, 'attendance']);
        Route::get('/executive/payroll', [ExecutiveDashboardController::class, 'payroll']);
        Route::get('/executive/workforce', [ExecutiveDashboardController::class, 'workforce']);
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
        Route::post('/generate', [ReportController::class, 'generate']);
        Route::post('/export', [ReportController::class, 'export']);
        Route::post('/save', [ReportController::class, 'save']);
        Route::get('/saved', [ReportController::class, 'savedList']);
        Route::delete('/saved/{savedReport}', [ReportController::class, 'deleteSaved']);
        Route::post('/schedule', [ReportController::class, 'schedule']);
        Route::get('/scheduled', [ReportController::class, 'scheduledList']);
        Route::delete('/scheduled/{scheduledReport}', [ReportController::class, 'deleteScheduled']);
    });

    // Profile self-service
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

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
        Route::post('/', [ApiKeyController::class, 'store']);
        Route::delete('/{apiKey}', [ApiKeyController::class, 'destroy']);
    });

    // Webhooks
    Route::prefix('webhooks')->group(function () {
        Route::get('/', [WebhookController::class, 'index']);
        Route::post('/', [WebhookController::class, 'store']);
        Route::put('/{webhook}', [WebhookController::class, 'update']);
        Route::delete('/{webhook}', [WebhookController::class, 'destroy']);
        Route::post('/{webhook}/test', [WebhookController::class, 'test']);
        Route::get('/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
    });

    // Accounting
    Route::prefix('accounting')->group(function () {
        Route::get('/journal/{payrollRun}', [AccountingController::class, 'journal']);
        Route::get('/export/{payrollRun}', [AccountingController::class, 'export']);
    });

    // Admin (Super Admin only)
    Route::prefix('admin')->group(function () {
        Route::get('/tenants', [AdminTenantController::class, 'index']);
        Route::get('/tenants/{publicId}', [AdminTenantController::class, 'show']);
        Route::put('/tenants/{publicId}/status', [AdminTenantController::class, 'updateStatus']);
        Route::post('/tenants/{publicId}/extend-trial', [AdminTenantController::class, 'extendTrial']);
        Route::post('/tenants/{publicId}/impersonate', [AdminTenantController::class, 'impersonate']);
        Route::get('/revenue', [AdminDashboardController::class, 'revenue']);
        Route::get('/health', [AdminDashboardController::class, 'health']);
        Route::get('/audit', [AdminDashboardController::class, 'auditLog']);
    });

    // Billing
    Route::prefix('billing')->group(function () {
        Route::get('/dashboard', [BillingController::class, 'dashboard']);
        Route::post('/change-plan', [BillingController::class, 'changePlan']);
        Route::put('/invoices/{invoice}/mark-paid', [BillingController::class, 'markPaid']);
    });

    // Settings
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::put('/settings/organization', [SettingsController::class, 'updateOrganization']);
    Route::put('/settings/branding', [SettingsController::class, 'updateBranding']);

    // Audit logs
    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    // Organization structure
    Route::prefix('organization')->group(function () {
        Route::get('/tree', [DepartmentController::class, 'tree']);

        Route::apiResource('branches', BranchController::class);
        Route::apiResource('departments', DepartmentController::class);
        Route::apiResource('teams', TeamController::class);
        Route::apiResource('positions', PositionController::class);
        Route::apiResource('grades', GradeController::class);
        Route::apiResource('cost-centers', CostCenterController::class);
    });
});
