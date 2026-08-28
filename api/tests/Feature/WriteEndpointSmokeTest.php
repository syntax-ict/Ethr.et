<?php

declare(strict_types=1);

use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\ApiKey;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLoan;
use App\Models\Grade;
use App\Models\Holiday;
use App\Models\KioskSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\SavedReport;
use App\Models\Shift;
use App\Models\Team;
use App\Models\Webhook;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Write-surface smoke: no POST/PUT/PATCH/DELETE endpoint may return 5xx.
 *
 * POST/PUT/PATCH are hit with an empty body — this exercises the validation +
 * authorization layer (FormRequest rules, `authorize()`), where a rule pointing at
 * a missing column or a throwing custom rule surfaces as a 500 rather than a 422.
 * DELETE is hit against real bound records, exercising the delete/cascade/audit path.
 *
 * Action endpoints with side effects that would corrupt the test run (auth/session,
 * device network calls, imports, external calls, super-admin, SCIM/SSO, heavy payroll)
 * are skipped by prefix.
 */
test('write api/v1 endpoints never return 5xx', function () {
    $tenant = createTenant();

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'department_id' => $dept->id,
    ]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'employee_id' => $employee->id], $tenant);

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
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
    ]);
    $holiday = Holiday::factory()->create(['tenant_id' => $tenant->id]);
    $announcement = Announcement::factory()->create(['tenant_id' => $tenant->id]);
    $webhook = Webhook::factory()->create(['tenant_id' => $tenant->id]);
    $apiKey = ApiKey::factory()->create(['tenant_id' => $tenant->id]);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $position = Position::factory()->create(['tenant_id' => $tenant->id]);
    $grade = Grade::factory()->create(['tenant_id' => $tenant->id]);
    $team = Team::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
    $costCenter = CostCenter::factory()->create(['tenant_id' => $tenant->id]);
    $loan = EmployeeLoan::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'draft']);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
    ]);
    $rule = PayrollRule::factory()->create(['tenant_id' => $tenant->id]);
    $kiosk = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $savedReport = SavedReport::factory()->create(['tenant_id' => $tenant->id, 'created_by' => $employee->id]);
    $customRole = CustomRole::create([
        'public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'name' => 'Auditor',
        'description' => 'Read-only', 'is_active' => true, 'org_scope' => OrgScope::ALL,
    ]);
    $document = EmployeeDocument::create([
        'public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
        'type' => 'contract', 'title' => 'Contract', 'file_path' => 'docs/c.pdf',
        'file_size' => 1024, 'mime_type' => 'application/pdf',
    ]);

    $params = [
        'payrollRun' => $run->public_id, 'branch' => $branch->public_id, 'department' => $dept->public_id,
        'announcement' => $announcement->public_id, 'correction' => $correction->public_id,
        'device' => $device->public_id, 'employee' => $employee->public_id, 'document' => $document->public_id,
        'holiday' => $holiday->public_id, 'kioskSession' => $kiosk->public_id, 'leave_type' => $leaveType->public_id,
        'cost_center' => $costCenter->public_id, 'grade' => $grade->public_id, 'position' => $position->public_id,
        'team' => $team->public_id, 'loan' => $loan->public_id, 'payrollRule' => $rule->public_id,
        'customRole' => $customRole->public_id, 'shift' => $shift->public_id, 'webhook' => $webhook->public_id,
        'apiKey' => $apiKey->public_id, 'savedReport' => $savedReport->public_id,
        'leaveRequest' => $leaveRequest->public_id,
    ];

    // Prefixes whose side effects would corrupt the run or reach out to the network.
    $hazardPrefixes = [
        'api/v1/auth/', 'api/v1/admin/', 'api/v1/scim/', 'api/v1/sso/', 'api/v1/contact',
        'api/v1/onboarding/', 'api/v1/devices/webhook/', 'api/v1/devices/sync-all',
        'api/v1/devices/{device}/pull', 'api/v1/attendance/import/', 'api/v1/employees/import/',
        'api/v1/webhooks/{webhook}/test', 'api/v1/payroll/process',
        'api/v1/payroll/runs/{payrollRun}/reprocess',
    ];

    $isHazard = function (string $uri) use ($hazardPrefixes): bool {
        foreach ($hazardPrefixes as $p) {
            if (str_starts_with($uri, $p)) {
                return true;
            }
        }

        return false;
    };

    $resolve = function (string $uri) use ($params): ?string {
        preg_match_all('/\{(\w+)\}/', $uri, $m);
        $out = $uri;
        foreach ($m[1] as $name) {
            if (! array_key_exists($name, $params)) {
                return null;
            }
            $out = str_replace('{'.$name.'}', (string) $params[$name], $out);
        }

        return $out;
    };

    $mutating = [];   // [verb, uri] for POST/PUT/PATCH
    $deletes = [];    // [verb, uri] for DELETE
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') || $isHazard($uri)) {
            continue;
        }
        foreach ($route->methods() as $verb) {
            if (in_array($verb, ['POST', 'PUT', 'PATCH'], true)) {
                $mutating[] = [$verb, $uri];
            } elseif ($verb === 'DELETE') {
                $deletes[] = [$verb, $uri];
            }
        }
    }

    $failures = [];
    $skipped = [];

    $hit = function (string $verb, string $uri) use ($tenant, $resolve, &$failures, &$skipped) {
        $resolved = $resolve($uri);
        if ($resolved === null) {
            $skipped[$uri] = 'unbound-param';

            return;
        }
        $status = test()->json($verb, "http://{$tenant->subdomain}.ethr.test/{$resolved}", [])->getStatusCode();
        if ($status >= 500) {
            $failures["{$verb} {$uri}"] = $status;
        }
    };

    // Empty-body writes first (validation blocks most, so state is largely untouched),
    // then deletes (which mutate).
    foreach ($mutating as [$verb, $uri]) {
        $hit($verb, $uri);
    }
    foreach ($deletes as [$verb, $uri]) {
        $hit($verb, $uri);
    }

    if ($failures !== []) {
        $lines = collect($failures)->map(fn (int $s, string $k) => "  {$k} => {$s}")->implode("\n");
        test()->fail("Write endpoints returning 5xx:\n".$lines);
    }

    expect($failures)->toBe([]);
});
