<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\PersonnelActionType;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeContract;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeTransition;
use App\Models\Grade;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\PersonnelAction;
use App\Models\Position;
use App\Services\Migration\StagedChanges;
use App\Services\Migration\WorkforceMigrationService;
use App\Support\EmployeeImportRow;
use Tests\Support\StagingFixtures as Staging;

/*
 * Completing the people who are already here, from a workbook. A staged row
 * that matches someone fills what ETHR has blank and shows every difference;
 * a job difference is shown and never written, whoever accepts. A list staged
 * with `mode: complete` adds nobody, and on a person's job and pay it fills
 * blanks and does nothing else.
 */

/** One person with a blank profile, in an organisation that has what a list can name. */
function incompleteOrganisation(array $person = []): array
{
    [$tenant, $user] = Staging::organisation();
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Accountant', 'code' => 'ACC']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bole']);
    $grade = Grade::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Senior']);
    $centre = CostCenter::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations', 'code' => 'OPS']);
    $abebe = Staging::employee($tenant, [
        'gender' => null, 'date_of_birth' => null, 'marital_status' => null, 'nationality' => null, 'name_am' => null,
        'status' => EmployeeStatus::CONFIRMED,
        ...$person,
    ]);

    return [$tenant, $user, $abebe, compact('position', 'branch', 'grade', 'centre')];
}

/** @return array<string, mixed> */
function abebeRow(array $more = []): array
{
    return ['name' => 'Abebe Kebede', 'employee_code' => 'E-1', ...$more];
}

describe('a list that completes people adds nobody', function () {
    it('skips a line that matches nobody, refuses to make it a new person, and ignores a create that got through', function () {
        [$tenant] = incompleteOrganisation();

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'mode' => 'complete',
            'rows' => [abebeRow(['gender' => 'male']), ['name' => 'Nobody Known', 'employee_code' => 'ZZ-9']],
        ])->assertCreated()->json();

        expect($staged['mode'])->toBe('complete')
            ->and($staged['rows'][0]['action'])->toBe('merge')
            ->and($staged['rows'][1])->toMatchArray(['action' => 'skip', 'match_outcome' => 'new']);

        $url = Staging::url($tenant, "rows/{$staged['rows'][1]['public_id']}");
        test()->patchJson($url, ['action' => 'create'])->assertUnprocessable();
        test()->patchJson($url, ['action' => 'skip'])->assertOk();

        // A create that reached the row some other way is still not a person.
        MigrationStagingRow::query()->where('public_id', $staged['rows'][1]['public_id'])->update(['action' => 'create']);

        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
            ->assertOk()
            ->assertJsonPath('totals.created', 0)
            ->assertJsonPath('totals.skipped', 1)
            ->assertJsonPath('totals.merged', 1);

        expect(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('is a list that adds people when it says nothing', function () {
        [$tenant] = incompleteOrganisation();

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'rows' => [['name' => 'Nobody Known', 'employee_code' => 'ZZ-9']],
        ])->assertCreated()->json();

        expect($staged['mode'])->toBe('add')
            ->and($staged['rows'][0]['action'])->toBe('create');

        test()->patchJson(Staging::url($tenant, "rows/{$staged['rows'][0]['public_id']}"), ['action' => 'create'])->assertOk();
    });

    it('stays a list that completes people after the commit says how new people arrive', function () {
        [$tenant] = incompleteOrganisation();

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'mode' => 'complete',
            'rows' => [abebeRow(['gender' => 'male']), ['name' => 'Nobody Known', 'employee_code' => 'ZZ-9']],
        ])->assertCreated()->json();

        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"), ['status' => 'confirmed'])->assertOk();

        $batch = MigrationBatch::query()->where('public_id', $staged['public_id'])->firstOrFail();
        expect($batch->isCompleting())->toBeTrue()
            ->and($batch->arrivalStatus())->toBe('confirmed')
            ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('commits inside a slice that is two lines long', function () {
        app()->when(WorkforceMigrationService::class)->needs('$chunk')->give(2);
        app()->when(WorkforceMigrationService::class)->needs('$sliceSeconds')->give(0.0);

        [$tenant, , $abebe] = incompleteOrganisation();
        $sara = Staging::employee($tenant, ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2', 'gender' => null]);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'mode' => 'complete',
            'rows' => [
                abebeRow(['gender' => 'male']),
                ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2', 'gender' => 'female'],
                ['name' => 'Nobody Known', 'employee_code' => 'ZZ-9'],
            ],
        ])->assertCreated()->json();

        while ($staged['status'] === 'staging') {
            $staged = test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/stage"))->assertOk()->json();
        }

        $commit = Staging::url($tenant, "batches/{$staged['public_id']}/commit");
        test()->postJson($commit)->assertOk()->assertJsonPath('status', 'committing')->assertJsonPath('remaining', 1);
        test()->postJson($commit)->assertOk()->assertJsonPath('status', 'committed')
            ->assertJsonPath('totals.created', 0)
            ->assertJsonPath('totals.skipped', 1)
            ->assertJsonPath('totals.updated', 2);

        expect($abebe->refresh()->gender)->toBe('male')
            ->and($sara->refresh()->gender)->toBe('female')
            ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(2);
    });
});

describe('a matched row fills a blank personal or job field', function () {
    it('writes every one that is blank, in a list that adds people and in one that completes them', function (?string $mode) {
        [$tenant, $user, $abebe, $named] = incompleteOrganisation();

        $batch = Staging::stage($tenant, [abebeRow([
            'name_am' => 'አበበ ከበደ', 'gender' => 'male', 'date_of_birth' => '1990-05-17', 'marital_status' => 'married',
            'nationality' => 'Ethiopian', 'position' => 'Accountant', 'branch' => 'Bole', 'grade' => 'Senior',
            'cost_center' => 'Operations', 'probation_end_date' => '2026-12-31',
        ])], $mode);

        $changes = Staging::firstRow($batch)->changes;
        foreach (['name_am', 'gender', 'date_of_birth', 'marital_status', 'nationality', 'position', 'branch', 'grade', 'cost_center', 'probation_end_date'] as $field) {
            expect(Staging::change($changes, $field))->toMatchArray(['kind' => StagedChanges::FILL, 'current' => null], $field);
        }

        Staging::service()->commit($batch, $user);
        $abebe->refresh();

        expect($abebe->name_am)->toBe('አበበ ከበደ')
            ->and($abebe->gender)->toBe('male')
            ->and($abebe->date_of_birth->toDateString())->toBe('1990-05-17')
            ->and($abebe->marital_status)->toBe('married')
            ->and($abebe->nationality)->toBe('Ethiopian')
            ->and($abebe->position_id)->toBe($named['position']->id)
            ->and($abebe->branch_id)->toBe($named['branch']->id)
            ->and($abebe->grade_id)->toBe($named['grade']->id)
            ->and($abebe->cost_center_id)->toBe($named['centre']->id)
            ->and($abebe->probation_end_date->toDateString())->toBe('2026-12-31')
            ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(1);
    })->with([[null], ['complete']]);

    it('takes the nationality status ETHR gives everyone as a value, not as a blank', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation();
        expect($abebe->refresh()->nationality_status->value)->toBe('ethiopian');

        $batch = Staging::stage($tenant, [abebeRow(['nationality_status' => 'foreign'])]);

        expect(Staging::change(Staging::firstRow($batch)->changes, 'nationality_status'))
            ->toMatchArray(['kind' => StagedChanges::CONFLICT, 'current' => 'ethiopian', 'incoming' => 'foreign']);

        Staging::service()->commit($batch, $user);

        expect($abebe->refresh()->nationality_status->value)->toBe('ethiopian');
    });

    it('never changes anything because a cell was left blank', function () {
        [$tenant, $user, $abebe, $named] = incompleteOrganisation([
            'gender' => 'female', 'date_of_birth' => '1991-01-01', 'marital_status' => 'single', 'nationality' => 'Ethiopian',
            'name_am' => 'አበበ', 'position_id' => null, 'grade_id' => null,
        ]);
        $abebe->update(['branch_id' => $named['branch']->id]);

        $batch = Staging::stage($tenant, [abebeRow([
            'name_am' => '', 'gender' => '  ', 'date_of_birth' => null, 'marital_status' => '', 'nationality' => '',
            'position' => '', 'branch' => '', 'grade' => '', 'cost_center' => '', 'probation_end_date' => '',
            'bank_name' => '', 'bank_account' => '', 'emergency_name' => '', 'contract_type' => '', 'status' => '',
        ])], 'complete');

        expect(Staging::firstRow($batch)->changes)->toBe([]);

        $totals = Staging::service()->commit($batch, $user);
        $abebe->refresh();

        expect($totals['updated'])->toBe(0)
            ->and($abebe->gender)->toBe('female')
            ->and($abebe->name_am)->toBe('አበበ')
            ->and($abebe->branch_id)->toBe($named['branch']->id)
            ->and($abebe->position_id)->toBeNull()
            ->and(EmployeeBankDetail::query()->where('employee_id', $abebe->id)->count())->toBe(0);
    });

    it('does not find a position, branch or grade that belongs to another organisation', function () {
        $other = createTenant();
        Position::factory()->create(['tenant_id' => $other->id, 'title' => 'Chief Surveyor', 'code' => 'SRV']);
        Grade::factory()->create(['tenant_id' => $other->id, 'name' => 'Principal']);
        [$tenant, $user, $abebe] = incompleteOrganisation();

        $batch = Staging::stage($tenant, [abebeRow(['position' => 'Chief Surveyor', 'grade' => 'Principal', 'gender' => 'male'])]);

        $changes = Staging::firstRow($batch)->changes;
        expect(Staging::change($changes, 'position'))->toBeNull()
            ->and(Staging::change($changes, 'grade'))->toBeNull();

        Staging::service()->commit($batch, $user);

        expect($abebe->refresh()->position_id)->toBeNull()
            ->and($abebe->grade_id)->toBeNull()
            ->and($abebe->gender)->toBe('male');
    });

    it('leaves a person with a change scheduled for a later date as they are', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation();
        PersonnelAction::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $abebe->id,
            'type' => PersonnelActionType::TRANSFER,
            'effective_date' => now()->addMonth()->toDateString(),
            'changes' => [],
            'pending_values' => ['department_id' => null],
            'is_temporary' => false,
            'applied_at' => null,
        ]);

        $batch = Staging::stage($tenant, [abebeRow([
            'gender' => 'male', 'bank_name' => 'Commercial Bank', 'bank_account' => '1000123456789', 'status' => 'probation',
        ])], 'complete');

        $totals = Staging::service()->commit($batch, $user);

        expect($totals['updated'])->toBe(0)
            ->and($abebe->refresh()->gender)->toBeNull()
            ->and($abebe->status)->toBe(EmployeeStatus::CONFIRMED)
            ->and(EmployeeBankDetail::query()->where('employee_id', $abebe->id)->count())->toBe(0);
    });
});

describe('a difference in a job field is shown and left', function () {
    it('is locked, is not written, and cannot be accepted', function () {
        [$tenant, $user, $abebe, $named] = incompleteOrganisation();
        $other = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Auditor', 'code' => 'AUD']);
        $abebe->update(['position_id' => $named['position']->id, 'probation_end_date' => '2026-06-30']);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'rows' => [abebeRow(['position' => 'Auditor', 'probation_end_date' => '2026-12-31', 'gender' => 'male'])],
        ])->assertCreated()->json();

        $row = $staged['rows'][0];
        expect(Staging::change($row['changes'], 'position'))
            ->toMatchArray(['kind' => 'locked', 'current' => 'Accountant', 'incoming' => 'Auditor', 'accepted' => false])
            ->and(Staging::change($row['changes'], 'probation_end_date')['kind'])->toBe('locked');

        $url = Staging::url($tenant, "rows/{$row['public_id']}");
        test()->patchJson($url, ['action' => 'merge', 'accept' => ['position']])->assertUnprocessable();

        $patched = test()->patchJson($url, ['action' => 'merge', 'accept' => ['gender']])->assertOk()->json();
        expect(Staging::change($patched['changes'], 'position')['accepted'])->toBeFalse();

        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk();

        expect($abebe->refresh()->position_id)->toBe($named['position']->id)
            ->and($abebe->position_id)->not->toBe($other->id)
            ->and($abebe->probation_end_date->toDateString())->toBe('2026-06-30');
    });

    it('stays unwritten even when a stored acceptance names it', function () {
        [$tenant, $user, $abebe, $named] = incompleteOrganisation();
        Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Auditor', 'code' => 'AUD']);
        $abebe->update(['position_id' => $named['position']->id]);

        $batch = Staging::stage($tenant, [abebeRow(['position' => 'Auditor'])]);
        $row = Staging::firstRow($batch);
        $row->update(['changes' => array_map(fn (array $c): array => [...$c, 'accepted' => true], $row->changes)]);

        $totals = Staging::service()->commit($batch->refresh(), $user);

        expect($totals['updated'])->toBe(0)
            ->and($abebe->refresh()->position_id)->toBe($named['position']->id);
    });
});

describe('in a list that completes people, job and pay are fill only', function () {
    it('locks a department and a salary that differ, where a list that adds people lets a reviewer accept them', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation();
        $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN']);
        $sales = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'SAL']);
        $abebe->update(['department_id' => $finance->id, 'salary_cents' => 1000000]);
        $line = abebeRow(['department' => 'Sales', 'salary_cents' => 2000000]);

        $completing = Staging::firstRow(Staging::stage($tenant, [$line], 'complete'));
        expect(Staging::change($completing->changes, 'department')['kind'])->toBe(StagedChanges::LOCKED)
            ->and(Staging::change($completing->changes, 'salary_cents')['kind'])->toBe(StagedChanges::LOCKED);

        $url = Staging::url($tenant, "rows/{$completing->public_id}");
        test()->patchJson($url, ['action' => 'merge', 'accept' => ['department']])->assertUnprocessable();
        test()->patchJson($url, ['action' => 'merge', 'accept' => ['salary_cents']])->assertUnprocessable();

        $adding = Staging::stage($tenant, [$line]);
        expect(Staging::change(Staging::firstRow($adding)->changes, 'department')['kind'])->toBe(StagedChanges::CONFLICT);

        $addingRow = Staging::firstRow($adding);
        test()->patchJson(Staging::url($tenant, "rows/{$addingRow->public_id}"), ['action' => 'merge', 'accept' => ['department', 'salary_cents']])->assertOk();
        Staging::service()->commit($adding->refresh(), $user);

        expect($abebe->refresh()->department_id)->toBe($sales->id)
            ->and($abebe->salary_cents)->toBe(2000000);
    });

    it('still fills a department and a salary that are blank', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation();
        $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN']);

        $batch = Staging::stage($tenant, [abebeRow(['department' => 'Finance', 'salary_cents' => 1500000])], 'complete');
        Staging::service()->commit($batch, $user);

        expect($abebe->refresh()->department_id)->toBe($finance->id)
            ->and($abebe->salary_cents)->toBe(1500000);
    });

    it('applies a personal difference only when a reviewer accepted it', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation(['gender' => 'male', 'marital_status' => 'single']);
        $line = abebeRow(['gender' => 'female', 'marital_status' => 'married']);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), ['mode' => 'complete', 'rows' => [$line]])->assertCreated()->json();
        $row = $staged['rows'][0];

        expect(Staging::change($row['changes'], 'gender'))->toMatchArray(['kind' => 'conflict', 'current' => 'male', 'incoming' => 'female']);

        test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'merge', 'accept' => ['marital_status']])->assertOk();
        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk();

        expect($abebe->refresh()->gender)->toBe('male')
            ->and($abebe->marital_status)->toBe('married');
    });
});

describe('a bank account, an emergency contact and a contract are added once', function () {
    $group = [
        'bank_name' => 'Commercial Bank', 'bank_branch' => 'Bole', 'bank_account' => '1000123456789',
        'emergency_name' => 'Hana Bekele', 'emergency_relationship' => 'Sister', 'emergency_phone' => '0911223344',
        'contract_type' => 'permanent', 'hire_date' => '2020-01-01',
    ];

    it('adds each to a person who has none, and names what it adds without showing the account', function () use ($group) {
        [$tenant, $user, $abebe] = incompleteOrganisation();

        $staged = test()->postJson(Staging::url($tenant, 'rows'), ['mode' => 'complete', 'rows' => [abebeRow($group)]])->assertCreated()->json();
        $row = $staged['rows'][0];

        expect(Staging::change($row['changes'], 'bank'))->toMatchArray(['kind' => 'fill', 'current' => null, 'incoming' => 'Commercial Bank'])
            ->and(Staging::change($row['changes'], 'emergency_contact'))->toMatchArray(['kind' => 'fill', 'current' => null, 'incoming' => 'Hana Bekele'])
            ->and(Staging::change($row['changes'], 'contract'))->toMatchArray(['kind' => 'fill', 'current' => null, 'incoming' => 'permanent'])
            ->and(json_encode($staged))->not->toContain('1000123456789');

        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
            ->assertOk()->assertJsonPath('totals.updated', 1);

        $bank = EmployeeBankDetail::query()->where('employee_id', $abebe->id)->get();
        $contract = EmployeeContract::query()->where('employee_id', $abebe->id)->get();

        expect($bank)->toHaveCount(1)
            ->and($bank[0]->is_primary)->toBeTrue()
            ->and($bank[0]->account_number)->toBe('1000123456789')
            ->and(EmployeeEmergencyContact::query()->where('employee_id', $abebe->id)->first()->phone)->toBe('+251911223344')
            ->and($contract)->toHaveCount(1)
            ->and($contract[0]->start_date->toDateString())->toBe($abebe->hire_date->toDateString())
            ->and($contract[0]->salary_cents)->toBeNull()
            ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('leaves a person who has one alone, and says nothing about it', function () use ($group) {
        [$tenant, $user, $abebe] = incompleteOrganisation();
        $abebe->bankDetails()->create(['tenant_id' => $tenant->id, 'bank_name' => 'Awash Bank', 'account_number' => '5550001112223', 'is_primary' => true]);
        $abebe->emergencyContacts()->create(['tenant_id' => $tenant->id, 'name' => 'Dawit Kebede', 'relationship' => 'Brother', 'phone' => '+251922000000']);
        EmployeeContract::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $abebe->id, 'contract_type' => 'fixed_term',
            'start_date' => '2020-01-01', 'end_date' => '2021-01-01', 'status' => 'expired',
        ]);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), ['mode' => 'complete', 'rows' => [abebeRow($group)]])->assertCreated()->json();

        expect($staged['rows'][0]['changes'])->toBe([])
            ->and(json_encode($staged))->not->toContain('Awash')->not->toContain('Dawit');

        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk()->assertJsonPath('totals.updated', 0);

        expect(EmployeeBankDetail::query()->where('employee_id', $abebe->id)->count())->toBe(1)
            ->and(EmployeeBankDetail::query()->where('employee_id', $abebe->id)->value('bank_name'))->toBe('Awash Bank')
            ->and(EmployeeEmergencyContact::query()->where('employee_id', $abebe->id)->value('name'))->toBe('Dawit Kebede')
            ->and(EmployeeContract::query()->where('employee_id', $abebe->id)->count())->toBe(1);
    });

    it('adds the bank account only for someone who may change pay data', function () use ($group) {
        [$tenant, , $abebe] = incompleteOrganisation();
        $batch = Staging::stage($tenant, [abebeRow([...$group, 'gender' => 'male'])], 'complete');

        test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.create', 'employee.viewAny', 'employee.view', 'employee.update']));

        test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/commit"))->assertOk()->assertJsonPath('totals.updated', 1);

        expect($abebe->refresh()->gender)->toBe('male')
            ->and(EmployeeBankDetail::query()->where('employee_id', $abebe->id)->count())->toBe(0)
            ->and(EmployeeEmergencyContact::query()->where('employee_id', $abebe->id)->count())->toBe(1)
            ->and(EmployeeContract::query()->where('employee_id', $abebe->id)->count())->toBe(1);
    });

    it('does not add half a group', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation();

        $batch = Staging::stage($tenant, [abebeRow(['bank_name' => 'Commercial Bank', 'emergency_name' => 'Hana Bekele'])], 'complete');

        expect(Staging::firstRow($batch)->changes)->toBe([]);

        Staging::service()->commit($batch, $user);

        expect(EmployeeBankDetail::query()->where('employee_id', $abebe->id)->count())->toBe(0);
    });
});

describe('a person who is still hired is moved by the transition service', function () {
    it('moves hired to confirmed with the record the status endpoint leaves', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation(['status' => EmployeeStatus::HIRED]);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'mode' => 'complete',
            'rows' => [abebeRow(['status' => 'confirmed'])],
        ])->assertCreated()->json();

        expect(Staging::change($staged['rows'][0]['changes'], 'status'))->toMatchArray(['kind' => 'fill', 'incoming' => 'confirmed']);

        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
            ->assertOk()->assertJsonPath('totals.updated', 1);

        $abebe->refresh();
        $transition = EmployeeTransition::query()->where('employee_id', $abebe->id)->firstOrFail();

        expect($abebe->status)->toBe(EmployeeStatus::CONFIRMED)
            ->and($abebe->confirmation_date)->not->toBeNull()
            ->and($transition->from_status)->toBe(EmployeeStatus::HIRED)
            ->and($transition->to_status)->toBe(EmployeeStatus::CONFIRMED);
    });

    it('moves hired to probation too', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation(['status' => EmployeeStatus::HIRED]);

        Staging::service()->commit(Staging::stage($tenant, [abebeRow(['status' => 'probation'])], 'complete'), $user);

        expect($abebe->refresh()->status)->toBe(EmployeeStatus::PROBATION)
            ->and(EmployeeTransition::query()->where('employee_id', $abebe->id)->count())->toBe(1);
    });

    it('locks every other difference in status and moves nobody', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation(['status' => EmployeeStatus::CONFIRMED]);

        $batch = Staging::stage($tenant, [abebeRow(['status' => 'probation'])], 'complete');

        expect(Staging::change(Staging::firstRow($batch)->changes, 'status'))
            ->toMatchArray(['kind' => StagedChanges::LOCKED, 'current' => 'confirmed', 'incoming' => 'probation']);

        $totals = Staging::service()->commit($batch, $user);

        expect($totals['updated'])->toBe(0)
            ->and($abebe->refresh()->status)->toBe(EmployeeStatus::CONFIRMED)
            ->and(EmployeeTransition::query()->where('employee_id', $abebe->id)->count())->toBe(0);
    });

    it('does not read a status in a list that adds people as a change to someone who is here', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation(['status' => EmployeeStatus::HIRED]);

        $batch = Staging::stage($tenant, [abebeRow(['status' => 'confirmed'])]);

        expect(Staging::change(Staging::firstRow($batch)->changes, 'status'))->toBeNull();

        Staging::service()->commit($batch, $user);

        expect($abebe->refresh()->status)->toBe(EmployeeStatus::HIRED);
    });

    it('shows the move as a fill to someone who may make it, and as locked to someone who may not', function () {
        [$tenant, , $abebe] = incompleteOrganisation(['status' => EmployeeStatus::HIRED]);
        $batch = Staging::stage($tenant, [abebeRow(['status' => 'confirmed'])], 'complete');
        $url = Staging::url($tenant, "batches/{$batch->public_id}");

        $kind = fn () => Staging::change(test()->getJson($url)->assertOk()->json('rows.0.changes'), 'status')['kind'];

        expect($kind())->toBe('fill');

        test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.create', 'employee.viewAny', 'employee.view', 'employee.update']));
        expect($kind())->toBe('locked');

        test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.create', 'employee.viewAny', 'employee.view', 'employee.update', 'employee.transition']));
        expect($kind())->toBe('fill');

        test()->postJson("{$url}/commit")->assertOk();
        expect($abebe->refresh()->status)->toBe(EmployeeStatus::CONFIRMED);
    });

    it('reads M and F as the import does', function () {
        [$tenant, $user, $abebe] = incompleteOrganisation();

        Staging::service()->commit(Staging::stage($tenant, [abebeRow(['gender' => 'F', 'marital_status' => 'Married'])], 'complete'), $user);

        expect($abebe->refresh()->gender)->toBe('female')->and($abebe->marital_status)->toBe('married');
    });

    it('leaves the status for someone who may not move people through them', function () {
        [$tenant, , $abebe] = incompleteOrganisation(['status' => EmployeeStatus::HIRED]);
        $batch = Staging::stage($tenant, [abebeRow(['status' => 'confirmed', 'gender' => 'male'])], 'complete');

        test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.create', 'employee.viewAny', 'employee.view', 'employee.update']));
        test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/commit"))->assertOk();

        expect($abebe->refresh()->status)->toBe(EmployeeStatus::HIRED)
            ->and($abebe->gender)->toBe('male');
    });
});

describe('the gaps a completed list starts from', function () {
    it('lists the people missing a department, a position and a gender', function () {
        [$tenant, , $abebe, $named] = incompleteOrganisation();
        $department = Department::factory()->create(['tenant_id' => $tenant->id]);
        $complete = Staging::employee($tenant, [
            'name' => 'Sara Tesfaye', 'employee_code' => 'E-2', 'gender' => 'female',
            'department_id' => $department->id, 'position_id' => $named['position']->id,
        ]);

        foreach (['department', 'position', 'gender'] as $gap) {
            $names = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees?filter[missing]={$gap}")
                ->assertOk()->json('data.*.name');

            expect($names)->toContain($abebe->name)->not->toContain($complete->name);
        }
    });
});

describe('the workbook a person downloads to complete', function () {
    it('has only column names the import template has, so each cell lands under its own column', function () {
        [$tenant] = incompleteOrganisation();

        $export = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/export")->assertOk()->json();
        $header = str_getcsv(strtok($export['csv'], "\n"));

        expect($export['count'])->toBe(1)
            ->and(array_diff($header, EmployeeImportRow::TEMPLATE))->toBe([]);
    });
});
