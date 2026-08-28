<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\Shift;
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
