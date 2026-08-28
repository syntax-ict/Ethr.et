<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\ApiKey;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Webhook;
use Illuminate\Support\Facades\Route;

/**
 * @return array<string, int> uri => status, for every parameterless GET api/v1
 *                            route that returned 5xx
 */
function smokeReadEndpoints(string $subdomain): array
{
    $failures = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') || str_contains($uri, '{')) {
            continue;
        }

        $status = test()->get("http://{$subdomain}.ethr.test/{$uri}")->getStatusCode();

        if ($status >= 500) {
            $failures[$uri] = $status;
        }
    }

    return $failures;
}

function assertNoServerErrors(array $failures): void
{
    if ($failures !== []) {
        $lines = collect($failures)->map(fn (int $s, string $u) => "  {$u} => {$s}")->implode("\n");
        test()->fail("Endpoints returning 5xx:\n".$lines);
    }

    expect($failures)->toBe([]);
}

test('parameterless GET api/v1 endpoints never 5xx on an empty tenant', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    assertNoServerErrors(smokeReadEndpoints($tenant->subdomain));
});

test('parameterless GET api/v1 endpoints never 5xx when the tenant has data', function () {
    $tenant = createTenant();

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'department_id' => $dept->id,
    ]);
    // Act as an admin who is also this employee, so self-service reads render too.
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'employee_id' => $employee->id], $tenant);

    $device = Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'device_id' => $device->id,
    ]);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => (int) now()->year,
    ]);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    Holiday::factory()->create(['tenant_id' => $tenant->id]);
    Announcement::factory()->create(['tenant_id' => $tenant->id]);
    Webhook::factory()->create(['tenant_id' => $tenant->id]);
    ApiKey::factory()->create(['tenant_id' => $tenant->id]);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'approved']);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    assertNoServerErrors(smokeReadEndpoints($tenant->subdomain));
});
