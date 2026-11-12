<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\OrgScope;
use App\Models\AlertThreshold;
use App\Models\Announcement;
use App\Models\ApiKey;
use App\Models\ApprovalWorkflow;
use App\Models\AttendanceConflict;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSite;
use App\Models\Branch;
use App\Models\Concern;
use App\Models\CostCenter;
use App\Models\CustomRole;
use App\Models\DashboardDigest;
use App\Models\Department;
use App\Models\Device;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeContract;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducation;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeLoan;
use App\Models\ExitClearance;
use App\Models\ExitClearanceItem;
use App\Models\Grade;
use App\Models\GradeSalaryStep;
use App\Models\Holiday;
use App\Models\ImportMapping;
use App\Models\Invoice;
use App\Models\JobApplication;
use App\Models\KioskSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MarketingItem;
use App\Models\MarketingSection;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\OrganizationTemplate;
use App\Models\OvertimeRequest;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Models\PayrollRun;
use App\Models\PersonnelAction;
use App\Models\Position;
use App\Models\ProfileUpdateRequest;
use App\Models\RetirementCase;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRotation;
use App\Models\SupportRequest;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TrustedDevice;
use App\Models\Vacancy;
use App\Models\Webhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One record of every kind that a parameterised `api/v1` route can name,
 * owned by one organisation, and the route-parameter map that points at them.
 *
 * Shared by `ParameterizedEndpointSmokeTest` (every route answers without a 5xx
 * for the owner) and `CrossTenantRouteSweepTest` (every route refuses another
 * organisation's admin), so the two cannot drift: a route added with a
 * parameter that is in neither the map nor the sweep's own exemptions fails
 * both.
 */
final class ParameterizedFixtures
{
    /**
     * @return array{params: array<string, string>, employee: Employee, overrides: array<string, array<string, string>>}
     */
    public static function build(Tenant $tenant): array
    {
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'department_id' => $dept->id,
        ]);
        // Mock adapter so the {device}/status endpoint does not make a real (20s-timeout)
        // network call to a non-existent device IP.
        $device = Device::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'adapter_type' => 'mock',
        ]);
        $record = AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'device_id' => $device->id,
        ]);
        $correction = AttendanceCorrection::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'attendance_record_id' => $record->id,
        ]);
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
        ]);
        $holiday = Holiday::factory()->create(['tenant_id' => $tenant->id]);
        $announcement = Announcement::factory()->create(['tenant_id' => $tenant->id]);
        $webhook = Webhook::factory()->create(['tenant_id' => $tenant->id]);
        $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
        $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id]);
        $position = Position::factory()->create(['tenant_id' => $tenant->id]);
        $grade = Grade::factory()->create(['tenant_id' => $tenant->id]);
        $team = Team::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
        $costCenter = CostCenter::factory()->create(['tenant_id' => $tenant->id]);
        $loan = EmployeeLoan::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);
        $costSharing = EmployeeCostSharing::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);
        $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'approved']);
        $entry = PayrollEntry::factory()->create([
            'tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
        ]);
        $rule = PayrollRule::factory()->create(['tenant_id' => $tenant->id]);
        $kiosk = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $customRole = CustomRole::create([
            'public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'name' => 'Auditor',
            'description' => 'Read-only auditor', 'is_active' => true, 'org_scope' => OrgScope::ALL,
        ]);
        $document = EmployeeDocument::create([
            'public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'type' => 'contract', 'title' => 'Contract', 'file_path' => 'docs/contract.pdf',
            'file_size' => 1024, 'mime_type' => 'application/pdf',
        ]);

        $template = OrganizationTemplate::create([
            'public_id' => (string) Str::ulid(), 'name' => 'Private Company', 'slug' => 'private-company',
            'description' => 'Smoke template', 'icon' => 'building', 'template_data' => [],
            'is_active' => true, 'sort_order' => 1,
        ]);
        $templateSlug = $template->slug;

        $migrationBatch = MigrationBatch::create([
            'tenant_id' => $tenant->id, 'source_type' => 'csv', 'status' => 'reviewing',
        ]);

        $vacancy = Vacancy::query()->create([
            'tenant_id' => $tenant->id, 'title' => 'Smoke role', 'employment_type' => 'full_time',
            'description' => 'x', 'status' => 'open', 'visibility' => 'public', 'published_at' => now()->subDay(),
        ]);

        $application = JobApplication::query()->create([
            'tenant_id' => $tenant->id, 'vacancy_id' => $vacancy->id, 'name' => 'Smoke applicant',
            'source' => 'email', 'stage' => 'applied',
        ]);

        $concern = Concern::query()->create([
            'tenant_id' => $tenant->id, 'category' => 'safety', 'subject' => 'Smoke',
            'description' => 'x', 'status' => 'new', 'anonymous' => false,
        ]);

        $supportRequest = SupportRequest::query()->create([
            'tenant_id' => $tenant->id, 'category' => 'other', 'body' => 'Smoke',
            'status' => 'open', 'handled_by' => 'organisation',
        ]);

        // A landing-page row with no picture: the image route is a well-formed 404.
        MarketingSection::query()->create(['kind' => 'problems', 'heading' => 'Smoke', 'sort_order' => 1]);
        $marketingItem = MarketingItem::query()->create(['section_kind' => 'problems', 'slug' => 'smoke', 'title' => 'Smoke', 'sort_order' => 1]);

        $params = [
            'owner' => $marketingItem->public_id,
            'variant' => 'image-640',
            'concern' => $concern->public_id,
            'supportRequest' => $supportRequest->public_id,
            'vacancy' => $vacancy->public_id,
            'application' => $application->public_id,
            'payrollRun' => $run->public_id, 'branch' => $branch->public_id, 'department' => $dept->public_id,
            'announcement' => $announcement->public_id, 'correction' => $correction->public_id,
            'attendanceRecord' => $record->public_id, 'device' => $device->public_id, 'employee' => $employee->public_id,
            'document' => $document->public_id, 'holiday' => $holiday->public_id, 'kioskSession' => $kiosk->public_id,
            'leave_type' => $leaveType->public_id, 'cost_center' => $costCenter->public_id, 'grade' => $grade->public_id,
            'leaveRequest' => $leaveRequest->public_id,
            'position' => $position->public_id, 'team' => $team->public_id, 'loan' => $loan->public_id,
            'costSharing' => $costSharing->public_id,
            'payrollEntry' => $entry->public_id, 'payrollRule' => $rule->public_id, 'customRole' => $customRole->public_id,
            'shift' => $shift->public_id, 'rotation' => $rotation->public_id, 'webhook' => $webhook->public_id,
            'publicId' => $tenant->public_id, 'employeePublicId' => $employee->public_id,
            'subdomain' => $tenant->subdomain, 'slug' => (string) $templateSlug,
            'batch' => $migrationBatch->public_id,
            'importMapping' => ImportMapping::query()->forceCreate([
                'tenant_id' => $tenant->id, 'name' => 'Old payroll', 'slug' => 'old-payroll',
                'columns' => [['header' => 'Emp Nm', 'column' => 'name']],
            ])->public_id,
            // Import status is keyed by the caller-supplied import key, not a model
            // public_id. Any string resolves — an unknown key is a well-formed 404,
            // which is a valid non-5xx outcome for this smoke sweep.
            'key' => 'smoke-import-key',
            // Which punch's selfie: `check-in` or `check-out` (AttendancePhotoController::show).
            'which' => 'check-in',
            // An organisation logo's content hash. The seeded tenant has no
            // uploaded logo, so a well-formed hash is a 404 — a valid outcome.
            'version' => '0123456789abcdef',
            // Which of the organisation's brand images: one the public asset route
            // and the settings upload route both accept.
            'kind' => 'mark',
            // A news story's cover: the story, and which of its sizes. The seeded
            // announcement has no cover, so the request is a well-formed 404.
            'story' => $announcement->public_id,
            'size' => 'cover-640',
            // A story document's id: a well-formed ULID that names no file, so 404.
            'file' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            // An employment letter's id: well-formed, names no letter, so 404.
            'letter' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            // A data export's id: well-formed, names no export, so 404.
            'export' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            'slot' => 'primary',
            // A statutory schedule of a payroll run: one of StatutorySchedules::SCHEDULES.
            'schedule' => 'income-tax',
        ];

        $member = createUser(['role' => 'employee'], $tenant);
        $secondRecord = AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'device_id' => $device->id,
        ]);
        $ulid = fn (): string => (string) Str::ulid();

        $exitClearance = ExitClearance::query()->forceCreate([
            'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'exit_status' => 'resigned',
        ]);

        $more = [
            'disciplinaryCase' => DisciplinaryCase::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id])->public_id,
            'retirementCase' => RetirementCase::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id])->public_id,
            'user' => $member->public_id,
            'overtimeRequest' => OvertimeRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id])->public_id,
            'site' => AttendanceSite::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'name' => 'Head office', 'latitude' => 9.03, 'longitude' => 38.74,
            ])->public_id,
            'bankDetail' => EmployeeBankDetail::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
                'bank_name' => 'Commercial Bank of Ethiopia', 'account_number' => '1000123456789',
            ])->public_id,
            'contract' => EmployeeContract::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id])->public_id,
            'education' => EmployeeEducation::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
                'institution' => 'Addis Ababa University', 'degree' => 'BSc',
            ])->public_id,
            'emergencyContact' => EmployeeEmergencyContact::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
                'name' => 'Contact', 'relationship' => 'sibling', 'phone' => '+251911000000',
            ])->public_id,
            'salaryStep' => GradeSalaryStep::factory()->create(['tenant_id' => $tenant->id, 'grade_id' => $grade->id])->public_id,
            'profileUpdateRequest' => ProfileUpdateRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id])->public_id,
            'approvalWorkflow' => ApprovalWorkflow::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'request_type' => 'leave', 'name' => 'Leave approval',
            ])->public_id,
            'assignment' => ShiftAssignment::factory()->create([
                'tenant_id' => $tenant->id, 'assignable_type' => $employee->getMorphClass(), 'assignable_id' => $employee->id, 'shift_id' => $shift->id,
            ])->public_id,
            'invoice' => ($invoice = Invoice::factory()->create(['tenant_id' => $tenant->id]))->public_id,
            'invoicePublicId' => $invoice->public_id,
            'apiKey' => ApiKey::factory()->create(['tenant_id' => $tenant->id, 'created_by' => $member->id])->public_id,
            'conflict' => AttendanceConflict::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
                'record_a_id' => $record->id, 'record_b_id' => $secondRecord->id, 'conflict_type' => 'time_overlap',
            ])->public_id,
            'flag' => AttendanceIntegrityFlag::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'attendance_record_id' => $record->id,
                'employee_id' => $employee->id, 'punch' => 'check_in', 'type' => 'duplicate', 'severity' => 'low',
            ])->public_id,
            'alertThreshold' => AlertThreshold::factory()->create(['tenant_id' => $tenant->id])->public_id,
            'dashboardDigest' => DashboardDigest::factory()->create(['tenant_id' => $tenant->id])->public_id,
            'exitClearanceItem' => ExitClearanceItem::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'exit_clearance_id' => $exitClearance->id,
                'position' => 1, 'label' => 'Return laptop', 'owner' => 'it',
            ])->public_id,
            'personnelAction' => PersonnelAction::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
                'type' => 'transfer', 'effective_date' => now()->addMonth()->toDateString(), 'changes' => [],
            ])->public_id,
            'leaveType' => $leaveType->public_id,
            'savedReport' => ($saved = SavedReport::factory()->create(['tenant_id' => $tenant->id, 'created_by' => $member->id]))->public_id,
            'scheduledReport' => ScheduledReport::factory()->create(['tenant_id' => $tenant->id, 'saved_report_id' => $saved->id])->public_id,
            'row' => MigrationStagingRow::query()->forceCreate([
                'public_id' => $ulid(), 'tenant_id' => $tenant->id, 'batch_id' => $migrationBatch->id, 'raw' => [],
            ])->public_id,
            // The platform console's failed jobs and maintenance tasks are not
            // an organisation's records: an organisation's admin is refused by
            // permission or by the maintenance token, whatever id is named.
            'uuid' => '9f1b7c1e-0000-4000-8000-000000000001',
            'task' => 'backup',
        ];

        // Parameters named `id` mean a different record on each route, and each
        // belongs to one user rather than to the organisation as a whole.
        $notificationId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notificationId, 'type' => 'Sweep', 'notifiable_type' => $member->getMorphClass(),
            'notifiable_id' => $member->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $overrides = [
            'api/v1/notifications/{id}/read' => ['id' => $notificationId],
            'api/v1/auth/sessions/{id}' => ['id' => (string) $member->createToken('sweep')->accessToken->id],
            'api/v1/auth/devices/{id}' => ['id' => (string) TrustedDevice::query()->forceCreate([
                'user_id' => $member->id, 'device_hash' => hash('sha256', 'sweep'),
            ])->id],
        ];

        // The profile routes call an emergency contact `contact`.
        $more['contact'] = $more['emergencyContact'];

        $params += $more;

        return ['params' => $params, 'employee' => $employee, 'overrides' => $overrides];
    }
}
