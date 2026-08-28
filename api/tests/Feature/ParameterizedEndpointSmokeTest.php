<?php

declare(strict_types=1);

use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLoan;
use App\Models\Grade;
use App\Models\Holiday;
use App\Models\KioskSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MigrationBatch;
use App\Models\OrganizationTemplate;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\Team;
use App\Models\Webhook;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('parameterized GET api/v1 endpoints never 5xx with valid bound records', function () {
    $tenant = createTenant();

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'department_id' => $dept->id,
    ]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'employee_id' => $employee->id], $tenant);

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

    $params = [
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
        // Import status is keyed by the caller-supplied import key, not a model
        // public_id. Any string resolves — an unknown key is a well-formed 404,
        // which is a valid non-5xx outcome for this smoke sweep.
        'key' => 'smoke-import-key',
    ];

    // Skipped: DomPDF payslip (memory-heavy) and the invoice receipt (needs a Subscription chain).
    $skipUris = [
        'api/v1/payroll/payslips/{payrollEntry}/pdf',
        'api/v1/billing/invoices/{invoice}/receipt',
    ];

    $failures = [];
    $unresolved = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') || ! str_contains($uri, '{')) {
            continue;
        }
        if (in_array($uri, $skipUris, true)) {
            continue;
        }

        preg_match_all('/\{(\w+)\}/', $uri, $m);
        $names = $m[1];
        $resolved = $uri;
        $ok = true;
        foreach ($names as $name) {
            if (! array_key_exists($name, $params)) {
                $ok = false;
                break;
            }
            $resolved = str_replace('{'.$name.'}', (string) $params[$name], $resolved);
        }
        if (! $ok) {
            $unresolved[] = $uri;

            continue;
        }

        $status = test()->get("http://{$tenant->subdomain}.ethr.test/{$resolved}")->getStatusCode();
        if ($status >= 500) {
            $failures[$uri] = $status;
        }
    }

    if ($failures !== []) {
        $lines = collect($failures)->map(fn (int $s, string $u) => "  {$u} => {$s}")->implode("\n");
        test()->fail("Endpoints returning 5xx:\n".$lines);
    }

    expect($failures)->toBe([]);
    // Coverage guard: every non-skipped parameterized route must have been resolvable and hit,
    // so a newly added route with an unmapped param fails loudly instead of being silently skipped.
    expect($unresolved)->toBe([]);
});
