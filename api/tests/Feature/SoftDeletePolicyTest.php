<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Grade;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MigrationStagingRow;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Shift;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ── Employee documents ──

test('deleting an employee document soft-deletes it and keeps the underlying file', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $upload = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('contract.pdf', '%PDF-1.4 fake content'),
        'title' => 'Employment Contract',
        'type' => 'contract',
    ]);
    $upload->assertCreated();

    $document = EmployeeDocument::where('employee_id', $employee->id)->first();
    Storage::disk('minio')->assertExists($document->file_path);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}/documents/{$document->public_id}")
        ->assertNoContent();

    expect(EmployeeDocument::find($document->id))->toBeNull();
    expect(EmployeeDocument::withTrashed()->find($document->id))->not->toBeNull();
    expect(EmployeeDocument::withTrashed()->find($document->id)->deleted_at)->not->toBeNull();
    Storage::disk('minio')->assertExists($document->file_path);
});

// ── Grades ──

test('deleting a grade soft-deletes it', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $grade = Grade::factory()->create(['tenant_id' => $tenant->id]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/organization/grades/{$grade->public_id}")
        ->assertNoContent();

    expect(Grade::find($grade->id))->toBeNull();
    expect(Grade::withTrashed()->find($grade->id))->not->toBeNull();
});

// ── Shifts ──

test('deleting a shift soft-deletes it', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'is_default' => false]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/{$shift->public_id}")
        ->assertNoContent();

    expect(Shift::find($shift->id))->toBeNull();
    expect(Shift::withTrashed()->find($shift->id))->not->toBeNull();
});

// ── Leave types ──

test('deleting a leave type soft-deletes it', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave-types/{$leaveType->public_id}")
        ->assertNoContent();

    expect(LeaveType::find($leaveType->id))->toBeNull();
    expect(LeaveType::withTrashed()->find($leaveType->id))->not->toBeNull();
});

// ── The policy table itself ──
//
// The four tests above are behaviour tests: they drive a DELETE route and check
// what happened. They cover four of the fifteen rows in `docs/CLAUDE.md`'s soft
// delete policy table, which is why that table carried a note reading "this table
// is a convention, not a control" and offering three ways out — build a PHPStan
// rule, widen the test to all fifteen rows, or stop claiming enforcement.
//
// This is the second option, taken structurally rather than by writing eleven more
// HTTP tests. It reflects over every row's model and pins whether it carries
// `SoftDeletes`, so the table and the code cannot drift apart silently. Measured
// 2026-09-25: every row below already matches. The value is not that it finds a
// defect today — it is that changing one now has to be deliberate.
//
// What this does NOT assert, stated so nobody reads more into a green run than it
// earns: that "never delete" entities are actually never deleted. Absence of
// `SoftDeletes` means a `delete()` would be permanent, not that nothing calls it.
// Proving that needs the route surface, which is a different test.

test('every model in the soft delete policy table matches its row', function (string $model, bool $expectsSoftDeletes) {
    expect(class_exists($model))->toBeTrue();

    $usesSoftDeletes = in_array(
        SoftDeletes::class,
        class_uses_recursive($model),
        true,
    );

    expect($usesSoftDeletes)->toBe($expectsSoftDeletes);
})->with([
    // "Soft delete" rows — legal retention, historical reference.
    'Employees' => [Employee::class, true],
    'Leave Requests' => [LeaveRequest::class, true],
    'Departments' => [Department::class, true],
    'Branches' => [Branch::class, true],
    'Positions' => [Position::class, true],
    'Grades' => [Grade::class, true],
    'Documents' => [EmployeeDocument::class, true],
    'Shifts' => [Shift::class, true],
    'Devices' => [Device::class, true],

    // "Never delete" rows — audit and financial requirements. No SoftDeletes,
    // so a delete would be permanent rather than recoverable; the protection is
    // that nothing calls one.
    'Attendance Records' => [AttendanceRecord::class, false],
    'Payroll Entries' => [PayrollEntry::class, false],
    'Payroll Runs' => [PayrollRun::class, false],
    'Audit Logs' => [AuditLog::class, false],

    // "Hard delete after N days" rows — storage management sweeps.
    'Webhook Deliveries' => [WebhookDelivery::class, false],
    'Import staging' => [MigrationStagingRow::class, false],
]);

// The fifteenth row, "Notifications — hard delete after 90 days", is absent from
// the dataset above and that is deliberate rather than an oversight: there is no
// Eloquent model for it. `app/Models/` has `NotificationPreference` but nothing
// mapping the `notifications` table, which Laravel serves through
// `DatabaseNotification`. Pinning that row needs the sweep itself to be tested —
// that the 90-day cleanup runs and deletes — not a trait check. Recorded here so
// the gap stays visible instead of looking closed by a green run.
