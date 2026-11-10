<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\MigrationStagingRow;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use App\Services\Import\EmployeeImporter;
use App\Services\Migration\WorkforceMigrationService;

/*
 * One employee record: what the resolver must not do. It added weights and never
 * subtracted, so an equal employee number beside a different national ID was the
 * same person; it could not see an archived employee, so re-importing one made a
 * second record; `0911…` and `+251911…` were two numbers; and a device number
 * could be moved to another person without anyone asking.
 */

function contradictionResolver(): IdentityResolver
{
    return app(IdentityResolver::class);
}

function contradictionDevice(int $tenantId): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenantId,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenantId])->id,
        'adapter_type' => 'mock',
        'status' => 'online',
    ]);
}

describe('phone numbers are one number however they are written', function () {
    it('finds a person stored in E.164 from the local form', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+251911223344', 'name' => 'Solomon Tesfaye']);

        $match = contradictionResolver()->resolve($tenant->id, new IdentitySignals(phone: '0911 22 33 44', name: 'Solomon Tesfaye'));

        expect($match->outcome)->not->toBe(IdentityMatch::NEW)
            ->and($match->employee?->id)->toBe($emp->id)
            ->and($match->candidates[0]['reasons'])->toContain('phone');
    });

    it('finds a person stored in the local form from E.164', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911223344', 'name' => 'Solomon Tesfaye']);

        $match = contradictionResolver()->resolve($tenant->id, new IdentitySignals(phone: '+251 911 223 344', name: 'Solomon Tesfaye'));

        expect($match->employee?->id)->toBe($emp->id)
            ->and($match->candidates[0]['reasons'])->toContain('phone');
    });
});

describe('a contradiction sends a match to review', function () {
    it('does not call an equal employee number the same person when the national IDs differ', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E100', 'national_id' => 'ET-1111-2222']);

        $match = contradictionResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: 'E100', nationalId: 'ET-9999-8888'));

        expect($match->outcome)->toBe(IdentityMatch::PROBABLE)
            ->and($match->isMatch())->toBeFalse()
            ->and($match->employee?->id)->toBe($emp->id)
            ->and($match->conflicts)->toBe(['national_id_differs'])
            ->and($match->candidates[0]['reasons'])->toContain('national_id_differs');
    });

    it('does not call an equal email the same person when the file states another employee number', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E100', 'email' => 'office@acme.test']);

        $match = contradictionResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: 'E200', email: 'office@acme.test'));

        expect($match->outcome)->toBe(IdentityMatch::PROBABLE)
            ->and($match->conflicts)->toBe(['employee_code_differs']);
    });

    it('lets an equal national ID outweigh a different employee number', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E100', 'national_id' => 'ET-1111-2222']);

        $match = contradictionResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: 'E999', nationalId: 'et 1111 2222'));

        expect($match->outcome)->toBe(IdentityMatch::MATCHED)
            ->and($match->employee->id)->toBe($emp->id)
            ->and($match->conflicts)->toBe([]);
    });

    it('still matches when the identifiers agree and only the spelling of the name differs', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E100', 'national_id' => 'ET-1111-2222', 'name' => 'Abebe Kebede']);

        $match = contradictionResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: 'e100', name: 'አበበ ከበደ', nationalId: 'et 1111 2222'));

        expect($match->outcome)->toBe(IdentityMatch::MATCHED)
            ->and($match->employee->id)->toBe($emp->id)
            ->and($match->conflicts)->toBe([]);
    });
});

describe('a device number is a guess at an employee number, not a statement of one', function () {
    it('holds a device user whose number is an employee number and whose name is someone else', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => '12', 'name' => 'Abebe Kebede']);
        $device = contradictionDevice($tenant->id);

        $match = contradictionResolver()->resolve($tenant->id, IdentitySignals::forDeviceUser($device, '12', name: 'Guest card'));

        expect($match->outcome)->toBe(IdentityMatch::PROBABLE)
            ->and($match->employee?->id)->toBe($emp->id)
            ->and($match->conflicts)->toBe(['name_differs']);
    });

    it('matches a device user by number when the terminal holds only part of the name', function (string $onDevice) {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => '12', 'name' => 'Abebe Kebede Tesfaye', 'name_am' => 'አበበ ከበደ ተስፋዬ']);
        $device = contradictionDevice($tenant->id);

        $match = contradictionResolver()->resolve($tenant->id, IdentitySignals::forDeviceUser($device, '12', name: $onDevice));

        expect($match->outcome)->toBe(IdentityMatch::MATCHED)
            ->and($match->employee->id)->toBe($emp->id);
    })->with(['ABEBE', 'Abebe K.', 'kebede abebe', 'አበበ', 'Abebe 2']);

    it('matches a device user by number when the terminal holds no name at all', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => '12']);
        $device = contradictionDevice($tenant->id);

        $match = contradictionResolver()->resolve($tenant->id, IdentitySignals::forDeviceUser($device, '12'));

        expect($match->outcome)->toBe(IdentityMatch::MATCHED)
            ->and($match->employee->id)->toBe($emp->id);
    });

    it('does not read a different employee number as a contradiction of a card match', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001', 'name' => 'Abebe Kebede']);
        $device = contradictionDevice($tenant->id);

        $match = contradictionResolver()->resolve($tenant->id, IdentitySignals::forDeviceUser($device, '1001', name: 'Abebe Kebede'));

        expect($match->outcome)->toBe(IdentityMatch::MATCHED)
            ->and($match->employee->id)->toBe($emp->id);
    });
});

describe('archived and former employees', function () {
    it('reports an archived employee instead of calling the person new', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E9', 'name' => 'Hanna Girma']);
        $emp->delete();

        $match = contradictionResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: 'E9', name: 'Hanna Girma'));

        expect($match->outcome)->toBe(IdentityMatch::ARCHIVED)
            ->and($match->isMatch())->toBeFalse()
            ->and($match->isActionable())->toBeFalse()
            ->and($match->employee?->id)->toBe($emp->id)
            ->and($match->candidates[0]['reasons'])->toContain('archived');
    });

    it('lets a current employee win over an archived one without becoming ambiguous', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => '1001'])->delete();
        $current = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => '1001']);

        $match = contradictionResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: '1001', badgeNumber: '1001'));

        expect($match->outcome)->toBe(IdentityMatch::MATCHED)
            ->and($match->employee->id)->toBe($current->id);
    });

    it('says when the matched person has left', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E5', 'status' => EmployeeStatus::RESIGNED]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E6', 'status' => EmployeeStatus::CONFIRMED]);

        $left = contradictionResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: 'E5'));
        $here = contradictionResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: 'E6'));

        expect($left->outcome)->toBe(IdentityMatch::MATCHED)
            ->and($left->isFormerEmployee())->toBeTrue()
            ->and($left->candidates[0]['reasons'])->toContain('former_employee')
            ->and($here->isFormerEmployee())->toBeFalse();
    });
});

describe('a device number is not moved to another person unasked', function () {
    function reassignedNumber(): IdentitySignals
    {
        return new IdentitySignals(sourceType: 'zkteco', sourceRef: 'device:1', identifierType: 'device_user_id', identifierValue: '5');
    }

    it('leaves a confirmed number with its holder', function () {
        $tenant = createTenant();
        $a = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $b = Employee::factory()->create(['tenant_id' => $tenant->id]);
        contradictionResolver()->link($a, reassignedNumber(), 1.0, verified: true);

        $mapping = contradictionResolver()->link($b, reassignedNumber(), 0.9);

        expect($mapping->employee_id)->toBe($a->id)
            ->and(EmployeeExternalIdentity::where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('leaves an unconfirmed number with a holder who still works here', function () {
        $tenant = createTenant();
        $a = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $b = Employee::factory()->create(['tenant_id' => $tenant->id]);
        contradictionResolver()->link($a, reassignedNumber(), 0.9);

        expect(contradictionResolver()->link($b, reassignedNumber(), 0.9)->employee_id)->toBe($a->id);
    });

    it('lets an unconfirmed number follow the match once its holder is archived', function () {
        $tenant = createTenant();
        $a = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $b = Employee::factory()->create(['tenant_id' => $tenant->id]);
        contradictionResolver()->link($a, reassignedNumber(), 0.9);
        $a->delete();

        expect(contradictionResolver()->link($b, reassignedNumber(), 0.9)->employee_id)->toBe($b->id);
    });

    it('keeps a confirmed number with an archived holder until someone reassigns it', function () {
        $tenant = createTenant();
        $a = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $b = Employee::factory()->create(['tenant_id' => $tenant->id]);
        contradictionResolver()->link($a, reassignedNumber(), 1.0, verified: true);
        $a->delete();

        expect(contradictionResolver()->link($b, reassignedNumber(), 0.9)->employee_id)->toBe($a->id);
    });

    it('moves a number when told to, and writes down who had it', function () {
        $tenant = createTenant();
        $a = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $b = Employee::factory()->create(['tenant_id' => $tenant->id]);
        contradictionResolver()->link($a, reassignedNumber(), 1.0, verified: true);

        $mapping = contradictionResolver()->link($b, reassignedNumber(), 1.0, verified: true, reassign: true);

        $audit = AuditLog::where('tenant_id', $tenant->id)->where('action', 'identity.reassigned')->first();

        expect($mapping->employee_id)->toBe($b->id)
            ->and($mapping->verified_at)->not->toBeNull()
            ->and($audit)->not->toBeNull()
            ->and($audit->payload['from_employee_id'])->toBe($a->public_id)
            ->and($audit->payload['to_employee_id'])->toBe($b->public_id);
    });

    it('refreshes the same person without an audit entry', function () {
        $tenant = createTenant();
        $a = Employee::factory()->create(['tenant_id' => $tenant->id]);
        contradictionResolver()->link($a, reassignedNumber(), 0.9);

        $mapping = contradictionResolver()->link($a, reassignedNumber(), 1.0, verified: true, reassign: true);

        expect($mapping->verified_at)->not->toBeNull()
            ->and(AuditLog::where('tenant_id', $tenant->id)->where('action', 'identity.reassigned')->exists())->toBeFalse();
    });
});

describe('the file import and the migration pass what they know', function () {
    it('counts a row as the same person by badge number, as the migration does', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => 'B-7', 'name' => 'Abebe Kebede']);

        $result = app(EmployeeImporter::class)->commit('badge', [
            ['name' => 'Abebe Kebede', 'badge_number' => 'b-7', 'hire_date' => '2026-01-01'],
        ]);

        expect($result['matched'])->toBe(1)
            ->and($result['created'])->toBe(0)
            ->and($result['errors'])->toBe([]);
    });

    it('refuses a row that contradicts the person it looks like, rather than skipping or adding it', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E100', 'email' => 'office@acme.test']);

        $result = app(EmployeeImporter::class)->commit('conflict', [
            ['name' => 'Someone Else', 'employee_code' => 'E200', 'email' => 'office@acme.test', 'hire_date' => '2026-01-01'],
        ]);

        expect($result['created'])->toBe(0)
            ->and($result['matched'])->toBe(0)
            ->and($result['errors'][0][0])->toBe(__('employee.import_needs_review', ['row' => 0]))
            ->and(Employee::where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('refuses a row for an archived employee and says to restore them', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E9'])->delete();

        $result = app(EmployeeImporter::class)->commit('archived', [
            ['name' => 'Hanna Girma', 'employee_code' => 'E9', 'hire_date' => '2026-01-01'],
        ]);

        expect($result['created'])->toBe(0)
            ->and($result['errors'][0][0])->toBe(__('employee.import_archived', ['row' => 0]));
    });

    it('matches a staged row by national ID', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'national_id' => 'ET-1111-2222']);

        $batch = app(WorkforceMigrationService::class)->stageFromRows([
            ['name' => 'Spelled Differently', 'national_id' => 'et 1111 2222'],
        ], $tenant->id);

        $row = $batch->rows()->first();

        expect($row->match_outcome)->toBe(IdentityMatch::MATCHED)
            ->and($row->resolved_employee_id)->toBe($emp->id);
    });

    it('holds a staged match with someone who has left, or who is archived, for a person to decide', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E5', 'status' => EmployeeStatus::RESIGNED]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E9'])->delete();

        $batch = app(WorkforceMigrationService::class)->stageFromRows([
            ['name' => 'Left Us', 'employee_code' => 'E5'],
            ['name' => 'Was Archived', 'employee_code' => 'E9'],
        ], $tenant->id);

        $rows = $batch->rows()->orderBy('id')->get();

        expect($rows[0]->match_outcome)->toBe(IdentityMatch::MATCHED)
            ->and($rows[0]->action)->toBe(MigrationStagingRow::ACTION_DEFER)
            ->and($rows[1]->match_outcome)->toBe(IdentityMatch::ARCHIVED)
            ->and($rows[1]->action)->toBe(MigrationStagingRow::ACTION_DEFER);
    });

    it('holds a device user whose number matches and whose name does not', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => '12', 'name' => 'Abebe Kebede']);

        $match = contradictionResolver()->resolve($tenant->id, IdentitySignals::forDeviceUser(contradictionDevice($tenant->id), '12', name: 'Test User'));

        expect($match->outcome)->toBe(IdentityMatch::PROBABLE);
    });
});
