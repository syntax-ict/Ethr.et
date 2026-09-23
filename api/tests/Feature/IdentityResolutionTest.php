<?php

declare(strict_types=1);

use App\Jobs\PullDeviceEventsJob;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Device\DeviceManager;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use App\Services\Import\EmployeeImporter;
use Illuminate\Support\Facades\DB;

function identityResolver(): IdentityResolver
{
    return app(IdentityResolver::class);
}

describe('IdentityResolver scoring', function () {
    it('matches confidently on an exact employee code', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-42']);

        $match = identityResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: 'emp-42'));

        expect($match->outcome)->toBe(IdentityMatch::MATCHED);
        expect($match->employee->id)->toBe($emp->id);
        expect($match->confidence)->toBeGreaterThanOrEqual(0.85);
    });

    it('matches on an exact email regardless of case', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'Abebe@Acme.test']);

        $match = identityResolver()->resolve($tenant->id, new IdentitySignals(email: 'abebe@acme.test'));

        expect($match->outcome)->toBe(IdentityMatch::MATCHED);
        expect($match->employee->id)->toBe($emp->id);
    });

    it('treats a phone-only hit as probable, not a certain match', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911 22 33 44', 'name' => 'Solomon T']);

        $match = identityResolver()->resolve($tenant->id, new IdentitySignals(phone: '0911223344', name: 'Completely Different'));

        expect($match->outcome)->toBe(IdentityMatch::PROBABLE);
    });

    it('never matches on name resemblance alone', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede', 'employee_code' => 'X1']);

        $match = identityResolver()->resolve($tenant->id, new IdentitySignals(name: 'Abebe Kebede'));

        expect($match->outcome)->toBe(IdentityMatch::NEW);
        expect($match->employee)->toBeNull();
    });

    it('flags two near-tied candidates as ambiguous', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person A']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person B']);

        $match = identityResolver()->resolve($tenant->id, new IdentitySignals(phone: '0911000000'));

        expect($match->outcome)->toBe(IdentityMatch::AMBIGUOUS);
        expect($match->employee)->toBeNull();
        expect($match->candidates)->toHaveCount(2);
    });

    it('returns new when nothing resembles the signals', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'AAA']);

        $match = identityResolver()->resolve($tenant->id, new IdentitySignals(employeeCode: 'ZZZ', email: 'nobody@x.test'));

        expect($match->outcome)->toBe(IdentityMatch::NEW);
    });

    it('does not match across tenants', function () {
        $other = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $other->id, 'employee_code' => 'SHARED']);

        $mine = createTenant();
        $match = identityResolver()->resolve($mine->id, new IdentitySignals(employeeCode: 'SHARED'));

        expect($match->outcome)->toBe(IdentityMatch::NEW);
    });

    it('normalises through the generated columns rather than restating the rule', function () {
        // Not a performance assertion — BASELINE §15h measures that this query
        // cannot use an index on any of these columns however they are written,
        // because it is a six-way disjunction. What this pins is that the
        // normalisation has ONE definition.
        //
        // `employee_code_normalized` and `phone_normalized` are generated
        // columns carrying `LOWER(employee_code)` and a six-replacement chain
        // over `phone`. Until 2026-09-23 this class spelled both out again in
        // `orWhereRaw`, so the same rule existed twice with nothing keeping the
        // copies in step — and §15e records a known asymmetry in the phone rule
        // that a later fix would have corrected in one place and not the other.
        $tenant = createTenant();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        identityResolver()->resolve($tenant->id, new IdentitySignals(
            employeeCode: 'EMP-001',
            phone: '0911 22 33 44',
            name: 'Almaz Tesfaye',
        ));

        $candidateQuery = collect($queries)->first(fn (string $sql) => str_contains($sql, 'employees'));

        expect($candidateQuery)->not->toBeNull()
            ->and($candidateQuery)->toContain('employee_code_normalized')
            ->and($candidateQuery)->toContain('phone_normalized');

        // The rule itself must not be restated alongside the column that holds it.
        expect(str_contains($candidateQuery, 'lower(employee_code)'))->toBeFalse(
            'The employee-code rule is written twice again: once as the generated column '
            .'`employee_code_normalized` and once as `LOWER(employee_code)` here. See §15h.'
        );
        expect(str_contains($candidateQuery, 'replace('))->toBeFalse(
            'The phone rule is written twice again: once as the generated column '
            .'`phone_normalized` and once as a REPLACE chain here. See §15h.'
        );

        // `badge_number`, `email` and `name` keep LOWER() deliberately — there is
        // no generated column holding those rules, so nothing is duplicated and
        // an index would not be reachable anyway. Asserted so that removing the
        // wrappers is a deliberate act rather than a tidy-up.
        expect($candidateQuery)->toContain('lower(name)');
    });
});

describe('IdentityResolver external mappings', function () {
    it('resolves instantly through an existing mapping and links new ones', function () {
        $tenant = createTenant();
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => '1102']);

        $signals = new IdentitySignals(
            badgeNumber: '1102',
            sourceType: 'zkteco',
            sourceRef: 'device:7',
            identifierType: 'device_user_id',
            identifierValue: '1102',
        );

        // First resolution matches by badge and we persist the mapping.
        $first = identityResolver()->resolve($tenant->id, $signals);
        expect($first->outcome)->toBe(IdentityMatch::MATCHED);
        identityResolver()->link($first->employee, $signals, $first->confidence);

        // Change the employee's badge; the stored mapping must still resolve.
        $emp->update(['badge_number' => 'changed']);

        $second = identityResolver()->resolve($tenant->id, $signals);
        expect($second->outcome)->toBe(IdentityMatch::MATCHED);
        expect($second->employee->id)->toBe($emp->id);
        expect($second->candidates[0]['reasons'])->toContain('existing_mapping');

        expect(EmployeeExternalIdentity::where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('keeps the same device user id on two devices as distinct mappings', function () {
        $tenant = createTenant();
        $a = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $b = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $onDeviceA = new IdentitySignals(sourceType: 'zkteco', sourceRef: 'device:1', identifierType: 'device_user_id', identifierValue: '5');
        $onDeviceB = new IdentitySignals(sourceType: 'zkteco', sourceRef: 'device:2', identifierType: 'device_user_id', identifierValue: '5');

        identityResolver()->link($a, $onDeviceA, 1.0);
        identityResolver()->link($b, $onDeviceB, 1.0);

        expect(identityResolver()->resolve($tenant->id, $onDeviceA)->employee->id)->toBe($a->id);
        expect(identityResolver()->resolve($tenant->id, $onDeviceB)->employee->id)->toBe($b->id);
        expect(EmployeeExternalIdentity::where('tenant_id', $tenant->id)->count())->toBe(2);
    });
});

describe('device sync uses identity resolution', function () {
    it('records attendance and auto-links the device user id', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $emp = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_code' => 'EMP-1',
            'badge_number' => '1001',
        ]);
        $device = Device::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'adapter_type' => 'mock',
            'status' => 'online',
        ]);

        (new PullDeviceEventsJob($device))->handle(
            app(DeviceManager::class),
            app(AttendanceEngine::class),
            app(IdentityResolver::class),
        );

        expect(AttendanceRecord::where('tenant_id', $tenant->id)->where('employee_id', $emp->id)->exists())->toBeTrue();

        $identity = EmployeeExternalIdentity::where('tenant_id', $tenant->id)
            ->where('identifier_value', '1001')
            ->where('source_ref', 'device:'.$device->id)
            ->first();

        expect($identity)->not->toBeNull();
        expect($identity->employee_id)->toBe($emp->id);
        expect($identity->verified_at)->toBeNull();
    });

    it('does not duplicate the mapping across repeated syncs', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);
        $device = Device::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'adapter_type' => 'mock',
            'status' => 'online',
        ]);

        foreach (range(1, 2) as $_) {
            (new PullDeviceEventsJob($device))->handle(
                app(DeviceManager::class),
                app(AttendanceEngine::class),
                app(IdentityResolver::class),
            );
        }

        expect(EmployeeExternalIdentity::where('tenant_id', $tenant->id)->where('identifier_value', '1001')->count())->toBe(1);
    });
});

describe('employee import uses identity resolution', function () {
    it('skips a person who already exists under a different import batch', function () {
        $tenant = createTenant();
        $importer = app(EmployeeImporter::class);

        $first = $importer->commit('batchA', [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E100', 'email' => 'abebe@acme.test', 'hire_date' => '2026-01-01'],
        ]);
        expect($first['created'])->toBe(1);

        $second = $importer->commit('batchB', [
            ['name' => 'Abebe K.', 'employee_code' => 'E100', 'email' => 'abebe@acme.test', 'hire_date' => '2026-01-01'],
        ]);

        expect($second['created'])->toBe(0);
        expect($second['matched'])->toBe(1);
        expect(Employee::where('tenant_id', $tenant->id)->where('employee_code', 'E100')->count())->toBe(1);
    });

    it('still creates genuinely new hires', function () {
        $tenant = createTenant();
        $importer = app(EmployeeImporter::class);

        $result = $importer->commit('batchA', [
            ['name' => 'One Person', 'employee_code' => 'N1', 'hire_date' => '2026-01-01'],
            ['name' => 'Two Person', 'employee_code' => 'N2', 'hire_date' => '2026-01-01'],
        ]);

        expect($result['created'])->toBe(2);
        expect($result['matched'])->toBe(0);
    });
});
