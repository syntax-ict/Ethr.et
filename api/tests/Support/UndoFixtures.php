<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MigrationBatch;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the "undo this list's additions" tests share. A class, not helper
 * functions, for the reason StagingFixtures gives.
 */
final class UndoFixtures
{
    /** @var list<string> invented names, each different enough never to be held as the same person */
    public const NAMES = [
        'Abebe Kebede', 'Sara Tesfaye', 'Dawit Haile', 'Hana Girma', 'Yonas Bekele',
        'Meron Alemu', 'Tigist Wolde', 'Bereket Assefa', 'Selam Mengistu', 'Kalkidan Desta',
    ];

    /**
     * The organisation's admin, who holds `employee.delete` (HR_ADMIN does not).
     *
     * @return array{0: Tenant, 1: User}
     */
    public static function admin(): array
    {
        return StagingFixtures::organisation(UserRole::TENANT_ADMIN);
    }

    /**
     * People brought in by a list committed as it stands.
     *
     * @param  list<array<string, mixed>>  $people
     */
    public static function committed(Tenant $tenant, User $user, array $people, ?string $mode = null): MigrationBatch
    {
        $batch = StagingFixtures::stage($tenant, $people, $mode);
        StagingFixtures::service()->commit($batch, $user);

        return $batch->refresh();
    }

    /**
     * @param  list<string>  $names
     * @return list<array<string, string>>
     */
    public static function people(array $names): array
    {
        return array_map(fn (string $name): array => ['name' => $name], $names);
    }

    public static function who(Tenant $tenant, string $name): Employee
    {
        return Employee::withTrashed()->where('tenant_id', $tenant->id)->where('name', $name)->firstOrFail();
    }

    public static function url(Tenant $tenant, MigrationBatch $batch): string
    {
        return StagingFixtures::url($tenant, "batches/{$batch->public_id}/undo");
    }

    /** A list as it was before the columns that record what it did: every row says nothing. */
    public static function legacy(MigrationBatch $batch): void
    {
        DB::table('migration_staging_rows')->where('batch_id', $batch->id)->update(['created_employee' => null]);
    }

    /** A list in a chosen state, as the index tests make them. */
    public static function batch(Tenant $tenant, string $status, array $attributes = []): MigrationBatch
    {
        $batch = (new MigrationBatch)->forceFill([
            'tenant_id' => $tenant->id,
            'source_type' => 'csv',
            'status' => $status,
            ...$attributes,
        ]);
        $batch->save();

        return $batch->refresh();
    }

    /** Make the employee older than any list, as someone who was here before it. */
    public static function olderThanTheList(Employee $employee): void
    {
        DB::table('employees')->where('id', $employee->id)->update(['created_at' => now()->subDays(30)]);
    }

    /**
     * Give the employee one kind of use. Each is what a person, or HR for a
     * person, does after a list has brought them in; none of them is something
     * the list itself writes.
     */
    public static function use(Tenant $tenant, Employee $employee, string $reason): void
    {
        $tenantId = $tenant->id;
        $id = $employee->id;
        $now = now()->toDateTimeString();

        match ($reason) {
            'attendance' => AttendanceRecord::factory()->create(['tenant_id' => $tenantId, 'employee_id' => $id]),
            'signed_in' => User::factory()->create(['tenant_id' => $tenantId, 'employee_id' => $id, 'last_login_at' => now()]),
            'token' => User::factory()->create(['tenant_id' => $tenantId, 'employee_id' => $id])->createToken('auth'),
            'payroll' => PayrollEntry::factory()->create([
                'tenant_id' => $tenantId,
                'employee_id' => $id,
                'payroll_run_id' => PayrollRun::factory()->create(['tenant_id' => $tenantId])->id,
            ]),
            'leave' => LeaveRequest::factory()->create([
                'tenant_id' => $tenantId,
                'employee_id' => $id,
                'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenantId])->id,
            ]),
            'leave_balance' => DB::table('leave_balances')->insert([
                'tenant_id' => $tenantId,
                'employee_id' => $id,
                'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenantId])->id,
                'year' => (int) now()->year,
                'entitled_days' => 20,
                'used_days' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'documents' => DB::table('employee_documents')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'employee_id' => $id,
                'type' => 'contract',
                'title' => 'Signed copy',
                'file_path' => 'documents/signed-copy.pdf',
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'hr_records' => DB::table('employee_transitions')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'employee_id' => $id,
                'from_status' => 'hired',
                'to_status' => 'probation',
                'effective_date' => now()->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'in_charge' => DB::table('employees')
                ->where('id', Employee::factory()->create(['tenant_id' => $tenantId])->id)
                ->update(['supervisor_id' => $id]),
            'assigned' => DB::table('shift_assignments')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'shift_id' => Shift::factory()->create(['tenant_id' => $tenantId])->id,
                'assignable_type' => Employee::class,
                'assignable_id' => $id,
                'effective_from' => now()->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            default => throw new \InvalidArgumentException("No such use: {$reason}"),
        };
    }
}
