<?php

declare(strict_types=1);

use App\Enums\PersonnelActionType;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MigrationStagingRow;
use App\Models\PersonnelAction;
use App\Services\Migration\StagedChanges;
use Tests\Support\StagingFixtures as Staging;

/**
 * A staged row that matched an employee used to be a link and nothing more:
 * whatever else the row said about them was dropped. Owner decision
 * 2026-11-10: fill what ETHR has blank, show a person every difference, and
 * never make a second employee (One employee identity, PR 5).
 */
describe('a matched row fills what ETHR has blank', function () {
    it('writes the blanks when the list is added, and makes no second person', function () {
        [$tenant] = Staging::organisation();
        $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN']);
        $abebe = Staging::employee($tenant);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'rows' => [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0911223344', 'department' => 'Finance']],
        ])->assertCreated()->json();

        $row = $staged['rows'][0];
        expect($row['action'])->toBe('merge')
            ->and($row['can_apply_changes'])->toBeTrue()
            ->and(Staging::change($row['changes'], 'phone'))->toMatchArray(['kind' => 'fill', 'current' => null, 'incoming' => '+251911223344'])
            ->and(Staging::change($row['changes'], 'department'))->toMatchArray(['kind' => 'fill', 'incoming' => 'Finance'])
            ->and(Staging::change($row['changes'], 'name'))->toBeNull();

        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
            ->assertOk()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('totals.merged', 1)
            ->assertJsonPath('totals.updated', 1)
            ->assertJsonPath('totals.created', 0);

        $abebe->refresh();
        expect($abebe->phone)->toBe('+251911223344')
            ->and($abebe->department_id)->toBe($finance->id)
            ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(1);

        // The edit is the form's edit: the same audit record.
        expect(AuditLog::query()->where('action', 'employee.updated')->count())->toBe(1);
    });

    it('never clears a value because the row left it empty', function () {
        [$tenant, $user] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['email' => 'abebe@example.et', 'phone' => '+251911223344']);

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'email' => '', 'phone' => '  ']]);

        expect(Staging::firstRow($batch)->changes)->toBe([]);

        $totals = Staging::service()->commit($batch, $user);

        expect($totals['updated'])->toBe(0)
            ->and($abebe->refresh()->email)->toBe('abebe@example.et')
            ->and($abebe->phone)->toBe('+251911223344');
    });

    it('treats a name and a hire date ETHR made up as blank', function () {
        [$tenant, $user] = Staging::organisation();

        // A person staged with a number and nothing else is created as
        // "Device user D-17", hired today.
        $first = Staging::stage($tenant, [['employee_code' => 'D-17']]);
        Staging::service()->setAction(Staging::firstRow($first), MigrationStagingRow::ACTION_CREATE);
        Staging::service()->commit($first->refresh(), $user);

        $person = Employee::query()->where('tenant_id', $tenant->id)->where('employee_code', 'D-17')->firstOrFail();
        expect($person->name)->toBe('Device user D-17');

        $second = Staging::stage($tenant, [['employee_code' => 'D-17', 'name' => 'Sara Tesfaye', 'hire_date' => '2021-03-04']]);
        $changes = Staging::firstRow($second)->changes;

        expect(Staging::change($changes, 'name'))->toMatchArray(['kind' => StagedChanges::FILL])
            ->and(Staging::change($changes, 'hire_date'))->toMatchArray(['kind' => StagedChanges::FILL]);

        Staging::service()->commit($second, $user);

        expect($person->refresh()->name)->toBe('Sara Tesfaye')
            ->and($person->hire_date->toDateString())->toBe('2021-03-04');
    });

    it('treats a hire date a person entered as theirs, not as a blank', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['hire_date' => now()->toDateString()]);

        $batch = Staging::stage($tenant, [['employee_code' => 'E-1', 'name' => 'Abebe Kebede', 'hire_date' => '2021-03-04']]);

        expect(Staging::change(Staging::firstRow($batch)->changes, 'hire_date'))->toMatchArray(['kind' => StagedChanges::CONFLICT]);
    });
});

describe('a difference waits for a person', function () {
    it('leaves ETHR as it is until the reviewer accepts the field', function () {
        [$tenant] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['phone' => '+251911000001']);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'rows' => [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000002']],
        ])->assertCreated()->json();

        expect(Staging::change($staged['rows'][0]['changes'], 'phone'))->toMatchArray([
            'kind' => 'conflict', 'current' => '+251911000001', 'incoming' => '+251922000002', 'accepted' => false,
        ]);

        test()->patchJson(Staging::url($tenant, "rows/{$staged['rows'][0]['public_id']}"), ['action' => 'merge'])->assertOk();
        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
            ->assertOk()
            ->assertJsonPath('totals.merged', 1)
            ->assertJsonPath('totals.updated', 0);

        expect($abebe->refresh()->phone)->toBe('+251911000001');
    });

    it('writes the field the reviewer accepted, and only that one', function () {
        [$tenant] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['phone' => '+251911000001', 'email' => 'abebe@example.et']);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'rows' => [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000002', 'email' => 'a.kebede@example.et']],
        ])->assertCreated()->json();

        $patched = test()->patchJson(Staging::url($tenant, "rows/{$staged['rows'][0]['public_id']}"), [
            'action' => 'merge',
            'accept' => ['phone'],
        ])->assertOk()->json();

        expect(Staging::change($patched['changes'], 'phone')['accepted'])->toBeTrue()
            ->and(Staging::change($patched['changes'], 'email')['accepted'])->toBeFalse();

        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
            ->assertOk()
            ->assertJsonPath('totals.updated', 1);

        expect($abebe->refresh()->phone)->toBe('+251922000002')
            ->and($abebe->email)->toBe('abebe@example.et');
    });

    it('drops an acceptance when the value it was given for has changed since', function () {
        [$tenant, $user] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['phone' => '+251911000001']);

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000002']]);
        Staging::service()->setAction(Staging::firstRow($batch), MigrationStagingRow::ACTION_MERGE, accept: ['phone']);

        // Someone corrects the number on the form while the list waits.
        $abebe->update(['phone' => '+251933000003']);

        $totals = Staging::service()->commit($batch->refresh(), $user);

        expect($totals['updated'])->toBe(0)
            ->and($abebe->refresh()->phone)->toBe('+251933000003');
    });

    it('starts the list again when the reviewer says the row is someone else', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['phone' => '+251911000001']);
        $other = Staging::employee($tenant, ['name' => 'Abebe Kebede', 'employee_code' => 'E-2', 'phone' => null]);

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000002']]);
        $row = Staging::firstRow($batch);
        Staging::service()->setAction($row, MigrationStagingRow::ACTION_MERGE, accept: ['phone']);

        Staging::service()->setAction($row->refresh(), MigrationStagingRow::ACTION_MERGE, $other);

        $changes = $row->refresh()->changes;
        expect(Staging::change($changes, 'phone'))->toMatchArray(['kind' => StagedChanges::FILL, 'accepted' => false])
            ->and(Staging::change($changes, 'employee_code'))->toMatchArray(['kind' => StagedChanges::CONFLICT, 'taken' => true]);
    });
});

describe('what a list may not write', function () {
    it('never offers a terminal\'s own number as an employee number or a badge', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['badge_number' => '17']);

        $batch = Staging::service()->stageFromRows([[
            'device_user_id' => '17',
            'name' => 'ABEBE K',
            'employee_code' => '17',
            'badge_number' => '17',
            'code_is_guess' => true,
        ]], $tenant->id, 'device', 'device:1');

        $row = Staging::firstRow($batch);
        expect($row->match_outcome)->toBe('matched')
            ->and(Staging::change($row->changes, 'employee_code'))->toBeNull()
            ->and(Staging::change($row->changes, 'badge_number'))->toBeNull()
            ->and(Staging::change($row->changes, 'name'))->toMatchArray(['kind' => StagedChanges::CONFLICT]);
    });

    it('shows a number somebody else holds and leaves it with them', function () {
        [$tenant, $user] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['email' => 'abebe@example.et']);
        Staging::employee($tenant, ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2', 'badge_number' => '500']);

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'email' => 'abebe@example.et', 'badge_number' => '500']]);
        $row = Staging::firstRow($batch);
        Staging::service()->setAction($row, MigrationStagingRow::ACTION_MERGE, $abebe);

        expect(Staging::change($row->refresh()->changes, 'badge_number'))->toMatchArray(['taken' => true]);

        Staging::service()->commit($batch->refresh(), $user);

        expect($abebe->refresh()->badge_number)->toBeNull();
    });

    it('flags an employee with a change scheduled for a later date, and leaves their record', function () {
        [$tenant] = Staging::organisation();
        $abebe = Staging::employee($tenant);
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

        $staged = test()->postJson(Staging::url($tenant, 'rows'), [
            'rows' => [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0911223344']],
        ])->assertCreated()->json();

        expect($staged['rows'][0]['scheduled_action'])->toBeTrue();

        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
            ->assertOk()
            ->assertJsonPath('totals.merged', 1)
            ->assertJsonPath('totals.updated', 0);

        expect($abebe->refresh()->phone)->toBeNull();
    });

    it('names a national id, a TIN and a salary on the review screen and never shows them', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['national_id' => '1111222233334444', 'tin' => '0011223344', 'salary_cents' => 900000]);

        $batch = Staging::stage($tenant, [[
            'name' => 'Abebe Kebede',
            'employee_code' => 'E-1',
            'national_id' => '1111222233334444',
            'tin' => '0099887766',
            'salary_cents' => 1250000,
        ]]);

        $response = test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertOk();
        $changes = $response->json('rows.0.changes');

        expect(Staging::change($changes, 'national_id'))->toBeNull()
            ->and(Staging::change($changes, 'tin'))->toMatchArray(['kind' => 'conflict', 'masked' => true, 'current' => null, 'incoming' => null])
            ->and(Staging::change($changes, 'salary_cents'))->toMatchArray(['kind' => 'conflict', 'masked' => true, 'current' => null, 'incoming' => null]);

        expect($response->getContent())
            ->not->toContain('0011223344')
            ->not->toContain('0099887766')
            ->not->toContain('1250000')
            ->not->toContain('900000');
    });
});
