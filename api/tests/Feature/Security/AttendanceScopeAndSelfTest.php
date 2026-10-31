<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceDevice;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Tenant;

/*
 * attendance.manage can be given to a custom role scoped to one branch. The
 * attendance tools that act on one person checked only the permission, so
 * that role reached everyone in the organisation. And nobody may enter their
 * own hours or change their own pay: corrections, conflicts, integrity flags
 * and profile reviews already refused it.
 */

function scopeUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

/** @return array{0: Tenant, 1: Employee, 2: Employee, 3: Employee} tenant, the manager, someone in their branch, someone in another */
function scopeBranches(): array
{
    $tenant = createTenant();
    $north = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $south = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $manager = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $north->id]);
    $inside = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $north->id, 'employee_code' => 'N-1']);
    $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $south->id, 'employee_code' => 'S-1']);

    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Branch attendance', 'org_scope' => 'branch']);
    $role->permissions()->sync(Permission::whereIn('name', ['attendance.manage', 'employee.viewAny', 'employee.view'])->pluck('id'));
    actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id, 'employee_id' => $manager->id], $tenant);

    return [$tenant, $manager, $inside, $outside];
}

function scopeManual(Tenant $tenant, Employee $employee, string $key)
{
    return test()->postJson(scopeUrl($tenant, 'attendance/manual'), [
        'idempotency_key' => $key,
        'employee_public_id' => $employee->public_id,
        'date' => now()->subDays(2)->toDateString(),
        'check_in' => '08:00',
        'check_out' => '17:00',
        'reason' => 'Paper sheet',
    ]);
}

function scopePhone(Tenant $tenant, Employee $employee, string $status): AttendanceDevice
{
    return AttendanceDevice::query()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
        'credential_id' => 'c'.$employee->id, 'credential_id_hash' => AttendanceDevice::hashCredential('c'.$employee->id),
        'public_key' => 'x', 'rp_id' => 'ethr.test', 'status' => $status,
    ]);
}

describe('a branch-scoped attendance role', function () {
    it('enters attendance only inside its branch', function () {
        [$tenant, , $inside, $outside] = scopeBranches();

        scopeManual($tenant, $inside, 'in-1')->assertCreated();
        scopeManual($tenant, $outside, 'out-1')->assertNotFound();

        expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $outside->id)->exists())->toBeFalse();
    });

    it('changes attendance mode, kiosk PIN and phone exemption only inside its branch', function () {
        [$tenant, , $inside, $outside] = scopeBranches();

        foreach ([[$inside, 200], [$outside, 404]] as [$employee, $status]) {
            test()->putJson(scopeUrl($tenant, "employees/{$employee->public_id}/attendance-mode"), ['attendance_mode' => 'remote'])->assertStatus($status);
            test()->putJson(scopeUrl($tenant, "employees/{$employee->public_id}/kiosk-pin"), ['pin' => '4821'])->assertStatus($status);
            test()->putJson(scopeUrl($tenant, "employees/{$employee->public_id}/attendance-device-exemption"), ['until' => now()->addWeek()->toDateString()])->assertStatus($status);
        }

        expect($outside->fresh()->kiosk_pin)->toBeNull();
    });

    it('decides on phones only inside its branch, and lists only those', function () {
        [$tenant, , $inside, $outside] = scopeBranches();
        $mine = scopePhone($tenant, $inside, AttendanceDevice::PENDING);
        $theirs = scopePhone($tenant, $outside, AttendanceDevice::PENDING);

        $listed = collect(test()->getJson(scopeUrl($tenant, 'attendance/devices'))->assertOk()->json('data'))->pluck('employee.public_id');
        expect($listed->all())->toBe([$inside->public_id]);

        test()->postJson(scopeUrl($tenant, "attendance/devices/{$theirs->public_id}/approve"))->assertNotFound();
        test()->postJson(scopeUrl($tenant, "attendance/devices/{$mine->public_id}/approve"))->assertOk();

        expect($theirs->fresh()->status)->toBe(AttendanceDevice::PENDING);
    });

    it('imports rows only for its branch', function () {
        [$tenant, , , $outside] = scopeBranches();

        test()->postJson(scopeUrl($tenant, 'attendance/import/commit'), [
            'import_key' => 'scope-import',
            'rows' => [
                ['employee_code' => 'N-1', 'date' => now()->subDays(2)->toDateString(), 'check_in' => '08:00', 'check_out' => '17:00'],
                ['employee_code' => 'S-1', 'date' => now()->subDays(2)->toDateString(), 'check_in' => '08:00', 'check_out' => '17:00'],
            ],
        ])->assertJsonPath('created', 1);

        expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $outside->id)->exists())->toBeFalse();
    });
});

describe('your own record', function () {
    it('takes no manual entry or import row from you', function () {
        [$tenant, $manager] = scopeBranches();
        $manager->update(['employee_code' => 'N-0']);

        scopeManual($tenant, $manager, 'self-1')
            ->assertForbidden()
            ->assertJsonPath('detail', __('attendance.manual_entry_self'));

        test()->postJson(scopeUrl($tenant, 'attendance/import/commit'), [
            'import_key' => 'self-import',
            'rows' => [['employee_code' => 'N-0', 'date' => now()->subDays(2)->toDateString(), 'check_in' => '08:00', 'check_out' => '17:00']],
        ])->assertJsonPath('created', 0);

        expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $manager->id)->exists())->toBeFalse();
    });

    it('takes no pay or bank change from an HR admin', function () {
        $tenant = createTenant();
        $self = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 2_000_000]);
        actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $self->id], $tenant);

        test()->patchJson(scopeUrl($tenant, "employees/{$self->public_id}"), ['salary_cents' => 9_000_000])
            ->assertForbidden()
            ->assertJsonPath('detail', __('employee.cannot_change_own_pay'));
        test()->postJson(scopeUrl($tenant, "employees/{$self->public_id}/bank-details"), [
            'bank_name' => 'CBE', 'account_number' => '1000123456789',
        ])->assertForbidden();
        test()->postJson(scopeUrl($tenant, "employees/{$self->public_id}/personnel-actions"), [
            'type' => 'promotion', 'effective_date' => now()->toDateString(), 'new_salary_cents' => 9_000_000,
        ])->assertForbidden();

        expect($self->fresh()->salary_cents)->toBe(2_000_000);
    });

    it('still lets an HR admin edit the rest of their own record, and other people\'s pay', function () {
        $tenant = createTenant();
        $self = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 2_000_000]);
        $colleague = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 2_000_000]);
        actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $self->id], $tenant);

        test()->patchJson(scopeUrl($tenant, "employees/{$self->public_id}"), ['salary_cents' => 2_000_000, 'phone' => '0911223344'])->assertOk();
        test()->patchJson(scopeUrl($tenant, "employees/{$colleague->public_id}"), ['salary_cents' => 2_500_000])->assertOk();

        expect($colleague->fresh()->salary_cents)->toBe(2_500_000);
    });

    it('is the organisation admin\'s to change', function () {
        $tenant = createTenant();
        $self = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 2_000_000]);
        actingAsUser(['role' => UserRole::TENANT_ADMIN, 'employee_id' => $self->id, 'mfa_enabled' => true], $tenant);

        test()->patchJson(scopeUrl($tenant, "employees/{$self->public_id}"), ['salary_cents' => 2_400_000])->assertOk();
    });
});
