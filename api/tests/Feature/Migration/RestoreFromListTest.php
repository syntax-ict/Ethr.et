<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\StagingFixtures as Staging;
use Tests\Support\UndoFixtures as Undo;

/**
 * The corrected file after an undo: its rows reuse the numbers of the people
 * the wrong list made and the undo archived, and each such row brings its
 * person back with the file's details, in place of being held for review.
 */

/** A list brought in, then undone: everyone it made is archived and holds their number. */
function wrongListUndone(Tenant $tenant, $user, array $people): MigrationBatch
{
    $first = Undo::committed($tenant, $user, $people);
    test()->postJson(Undo::url($tenant, $first), ['confirm' => true])->assertOk()->assertJsonPath('archived', count($people));

    return $first;
}

/** @return array<int, array<string, mixed>> */
function reviewRows(Tenant $tenant, MigrationBatch $batch): array
{
    return test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertOk()->json('rows');
}

function commitList(Tenant $tenant, MigrationBatch $batch)
{
    return test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/commit"));
}

function actAs(UserRole $role, Tenant $tenant): void
{
    app('auth')->forgetGuards();
    test()->actingAs(createUser(['role' => $role], $tenant));
}

describe('the corrected list defaults to bringing the people back', function () {
    it('restores each archived person with the details of the file, and the list ends committed', function () {
        [$tenant, $user] = Undo::admin();
        wrongListUndone($tenant, $user, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'email' => 'wrong1@example.test'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
            ['name' => 'Dawit Haile', 'employee_code' => 'E-3'],
        ]);

        $corrected = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede Tadesse', 'employee_code' => 'E-1', 'email' => 'abebe@example.test', 'hire_date' => '2024-03-01', 'salary_cents' => 500000],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2', 'phone' => '0911223344'],
            ['name' => 'Dawit Haile', 'employee_code' => 'E-3'],
            ['name' => 'Hana Girma', 'employee_code' => 'E-4'],
        ]);

        $rows = reviewRows($tenant, $corrected);
        expect(array_column($rows, 'action'))->toBe(['restore', 'restore', 'restore', 'create'])
            ->and($rows[0]['restore'])->toBe(['possible' => true, 'allowed' => true, 'reasons' => []])
            ->and($rows[0]['warnings'])->toBe([])
            ->and($rows[3]['restore'])->toBeNull();

        commitList($tenant, $corrected)->assertOk()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('totals.restored', 3)
            ->assertJsonPath('totals.created', 1)
            ->assertJsonPath('totals.deferred', 0);

        $people = Employee::query()->where('tenant_id', $tenant->id)->orderBy('employee_code')->get();
        expect($people->pluck('employee_code')->all())->toBe(['E-1', 'E-2', 'E-3', 'E-4'])
            ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(4);

        $abebe = $people->firstWhere('employee_code', 'E-1');
        expect($abebe->name)->toBe('Abebe Kebede Tadesse')
            ->and($abebe->email)->toBe('abebe@example.test')
            ->and($abebe->hire_date->toDateString())->toBe('2024-03-01')
            ->and($abebe->salary_cents)->toBe(500000)
            ->and($people->firstWhere('employee_code', 'E-2')->phone)->not->toBeNull();

        // The people the file brought back are marked done, and counted.
        expect($corrected->refresh()->totals)->toMatchArray(['restored' => 3, 'created' => 1]);

        $restored = AuditLog::query()->where('action', 'migration.restored')->get();
        expect($restored)->toHaveCount(3)
            ->and($restored->every(fn (AuditLog $e): bool => ($e->payload['batch'] ?? null) === $corrected->public_id))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'employee.restored')->count())->toBe(3);
    });

    it('holds a row whose name shares nothing with the archived person, and lets the reviewer restore it', function () {
        [$tenant, $user] = Undo::admin();
        wrongListUndone($tenant, $user, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']]);

        $corrected = Staging::stage($tenant, [['name' => 'Meron Alemu', 'employee_code' => 'E-1']]);
        $row = reviewRows($tenant, $corrected)[0];

        expect($row['action'])->toBe('defer')
            ->and(array_column($row['warnings'], 'code'))->toContain('maybe_other_person')
            ->and($row['warnings'][0]['message'])->toBe(__('onboarding.migration.maybe_other_person_archived'))
            ->and($row['restore'])->toMatchArray(['possible' => true]);

        test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'restore'])
            ->assertOk()
            ->assertJsonPath('action', 'restore');

        commitList($tenant, $corrected)->assertOk()->assertJsonPath('totals.restored', 1);

        $person = Employee::query()->where('tenant_id', $tenant->id)->sole();
        expect($person->name)->toBe('Meron Alemu')->and($person->employee_code)->toBe('E-1');
    });

    it('does not hold a restore row for the number its own person holds, and still holds another record\'s badge', function () {
        [$tenant, $user] = Undo::admin();
        wrongListUndone($tenant, $user, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']]);
        Staging::archived($tenant, ['name' => 'Yonas Bekele', 'employee_code' => 'E-9', 'badge_number' => 'B-77']);

        $corrected = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'badge_number' => 'B-77']]);
        $row = reviewRows($tenant, $corrected)[0];

        expect($row['action'])->toBe('restore')
            ->and(array_column($row['warnings'], 'code'))->toBe(['badge_held']);

        commitList($tenant, $corrected)->assertOk()->assertJsonPath('totals.restored', 1);

        // The badge another person holds is left off, as for a new person.
        expect(Undo::who($tenant, 'Abebe Kebede')->badge_number)->toBeNull();
    });
});

describe('who can be brought back', function () {
    it('keeps a person with history out of the restore, and says why', function () {
        [$tenant, $user] = Undo::admin();
        wrongListUndone($tenant, $user, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
        ]);
        Undo::use($tenant, Undo::who($tenant, 'Abebe Kebede'), 'attendance');

        $corrected = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
        ]);
        $rows = reviewRows($tenant, $corrected);

        expect(array_column($rows, 'action'))->toBe(['defer', 'restore'])
            ->and($rows[0]['restore'])->toBe(['possible' => false, 'allowed' => true, 'reasons' => ['attendance']])
            ->and(array_column($rows[0]['warnings'], 'code'))->toBe(['number_held']);

        test()->patchJson(Staging::url($tenant, "rows/{$rows[0]['public_id']}"), ['action' => 'restore'])
            ->assertStatus(422)
            ->assertJsonPath('detail', __('onboarding.migration.restore_refused_in_use'));

        commitList($tenant, $corrected)->assertOk()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('totals.restored', 1)
            ->assertJsonPath('totals.deferred', 1);

        expect(Undo::who($tenant, 'Abebe Kebede')->trashed())->toBeTrue()
            ->and(Undo::who($tenant, 'Sara Tesfaye')->trashed())->toBeFalse();
    });

    it('asks again at commit, and holds a row whose person was used after the review', function () {
        [$tenant, $user] = Undo::admin();
        wrongListUndone($tenant, $user, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
        ]);
        $corrected = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
        ]);
        expect(array_column(reviewRows($tenant, $corrected), 'action'))->toBe(['restore', 'restore']);

        Undo::use($tenant, Undo::who($tenant, 'Sara Tesfaye'), 'payroll');

        commitList($tenant, $corrected)->assertOk()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('totals.restored', 1)
            ->assertJsonPath('totals.deferred', 1);

        $held = reviewRows($tenant, $corrected)[1];
        expect($held['action'])->toBe('defer')
            ->and($held['processed_at'])->toBeNull()
            ->and($held['warnings'][0]['code'])->toBe('restore_refused')
            ->and($held['warnings'][0]['message'])->toBe(__('onboarding.migration.restore_refused_in_use'))
            ->and(Undo::who($tenant, 'Sara Tesfaye')->trashed())->toBeTrue();
    });

    it('is refused for a row whose person is here, and for a list that completes people', function () {
        [$tenant] = Undo::admin();
        Staging::employee($tenant, ['name' => 'Abebe Kebede', 'employee_code' => 'E-1']);
        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']]);
        $row = reviewRows($tenant, $batch)[0];

        expect($row['action'])->toBe('merge')->and($row['restore'])->toBeNull();
        test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'restore'])
            ->assertStatus(422)
            ->assertJsonPath('detail', __('onboarding.migration.restore_refused_not_archived'));

        $completing = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']], 'complete');
        $one = reviewRows($tenant, $completing)[0];
        test()->patchJson(Staging::url($tenant, "rows/{$one['public_id']}"), ['action' => 'restore'])->assertStatus(422);
    });

    it('never matches a person archived in another organisation', function () {
        [$other] = Undo::admin();
        Staging::archived($other, ['name' => 'Abebe Kebede', 'employee_code' => 'E-1']);

        [$tenant] = Undo::admin();
        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']]);
        $row = reviewRows($tenant, $batch)[0];

        expect($row['action'])->toBe('create')->and($row['restore'])->toBeNull();

        test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'restore'])->assertStatus(422);

        commitList($tenant, $batch)->assertOk()->assertJsonPath('totals.created', 1)->assertJsonPath('totals.restored', 0);
        expect(DB::table('employees')->where('tenant_id', $other->id)->value('deleted_at'))->not->toBeNull();
    });
});

describe('what stops a restore at commit', function () {
    it('holds the person the plan has no seat for and restores the others, and the list is not stuck', function () {
        [$tenant, $user] = Undo::admin();
        wrongListUndone($tenant, $user, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
            ['name' => 'Dawit Haile', 'employee_code' => 'E-3'],
        ]);
        // The admin's own login has no employee record, so two seats are free.
        Subscription::factory()->for($tenant)->create(['plan_id' => Plan::factory()->create(['max_employees' => 2])->id]);

        $corrected = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
            ['name' => 'Dawit Haile', 'employee_code' => 'E-3'],
        ]);

        commitList($tenant, $corrected)->assertOk()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('totals.restored', 2)
            ->assertJsonPath('totals.deferred', 1);

        expect($corrected->refresh()->isCommitted())->toBeTrue()
            ->and(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(2);

        $held = collect(reviewRows($tenant, $corrected))->firstWhere('action', 'defer');
        expect($held['warnings'][0]['code'])->toBe('restore_refused')
            ->and($held['warnings'][0]['message'])->toBe(__('onboarding.migration.restore_refused_no_seat'))
            ->and(Undo::who($tenant, 'Dawit Haile')->trashed())->toBeTrue();
    });

    it('needs the permission to restore people, as well as to add them', function () {
        [$tenant, $user] = Undo::admin();
        wrongListUndone($tenant, $user, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']]);

        actAs(UserRole::HR_ADMIN, $tenant);

        $corrected = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']]);
        $row = reviewRows($tenant, $corrected)[0];

        expect($row['action'])->toBe('restore')->and($row['restore']['allowed'])->toBeFalse();

        test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'restore'])->assertForbidden();

        commitList($tenant, $corrected)->assertOk()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('totals.restored', 0)
            ->assertJsonPath('totals.deferred', 1);

        expect(Undo::who($tenant, 'Abebe Kebede')->trashed())->toBeTrue()
            ->and(reviewRows($tenant, $corrected)[0]['warnings'][0]['message'])->toBe(__('onboarding.migration.restore_refused_no_permission'));
    });
});

describe('undoing a list that brought people back', function () {
    it('archives them again, never erases them, and keeps anyone used since', function () {
        [$tenant, $user] = Undo::admin();
        wrongListUndone($tenant, $user, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
        ]);
        $corrected = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
            ['name' => 'Hana Girma', 'employee_code' => 'E-4'],
        ]);
        commitList($tenant, $corrected)->assertOk()->assertJsonPath('totals.restored', 2);
        $corrected->refresh();

        Undo::use($tenant, Undo::who($tenant, 'Sara Tesfaye'), 'attendance');

        test()->getJson(Undo::url($tenant, $corrected))->assertOk()
            ->assertJsonPath('archive_count', 2)
            ->assertJsonPath('keep_count', 1)
            ->assertJsonPath('keep.0.name', 'Sara Tesfaye')
            ->assertJsonPath('keep.0.reasons', ['attendance']);

        test()->postJson(Undo::url($tenant, $corrected), ['confirm' => true])->assertOk()->assertJsonPath('archived', 2);

        expect(Undo::who($tenant, 'Abebe Kebede')->trashed())->toBeTrue()
            ->and(Undo::who($tenant, 'Hana Girma')->trashed())->toBeTrue()
            ->and(Undo::who($tenant, 'Sara Tesfaye')->trashed())->toBeFalse()
            ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(3)
            ->and(AuditLog::query()->where('action', 'migration.undone')->latest('id')->first()->payload['restored'])->toBe(2);
    });
});
