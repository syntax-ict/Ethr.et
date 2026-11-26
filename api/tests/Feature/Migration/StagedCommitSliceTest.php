<?php

declare(strict_types=1);

use App\Enums\OnboardingStep;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeContract;
use App\Models\EmployeeEmergencyContact;
use App\Models\MigrationBatch;
use App\Models\OnboardingProgress;
use App\Models\Tenant;
use App\Services\EmployeeRelatedRecords;
use App\Services\Migration\HeldNumbers;
use App\Services\Migration\HeldSet;
use App\Services\Migration\WorkforceMigrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\StagingFixtures as Staging;
use Tests\Support\UndoFixtures as Undo;

/**
 * One request has thirty seconds on the host and the whole list was committed
 * in one transaction, so a long list could not be added at all. A commit now
 * works for a few seconds, records how far it got, and is asked again
 * (One employee identity, PR 5).
 *
 * These tests make a slice two rows long and give it no time, so every
 * request writes exactly one chunk.
 */
beforeEach(function () {
    app()->when(WorkforceMigrationService::class)->needs('$chunk')->give(2);
    app()->when(WorkforceMigrationService::class)->needs('$sliceSeconds')->give(0.0);
});

/** @return list<array<string, string>> */
function fivePeople(): array
{
    return [
        ['name' => 'Abebe Kebede'],
        ['name' => 'Sara Tesfaye'],
        ['name' => 'Dawit Haile'],
        ['name' => 'Hana Girma'],
        ['name' => 'Yonas Bekele'],
    ];
}

/**
 * The list, staged and ready for review. A slice has no time here, so staging
 * takes a request for each chunk of matching, as commit does.
 *
 * @return array<string, mixed>
 */
function stagedFivePeople(Tenant $tenant): array
{
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => fivePeople()])->assertCreated()->json();

    while ($staged['status'] === 'staging') {
        $staged = test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/stage"))->assertOk()->json();
    }

    return test()->getJson(Staging::url($tenant, "batches/{$staged['public_id']}"))->assertOk()->json();
}

it('adds a list a slice at a time and says how much is left', function () {
    [$tenant] = Staging::organisation();
    $staged = stagedFivePeople($tenant);
    $commit = Staging::url($tenant, "batches/{$staged['public_id']}/commit");

    test()->postJson($commit)->assertOk()
        ->assertJsonPath('status', 'committing')
        ->assertJsonPath('remaining', 3)
        ->assertJsonPath('totals.created', 2)
        ->assertJsonPath('without_login', []);

    expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'migration.committed')->count())->toBe(0);

    test()->postJson($commit)->assertOk()
        ->assertJsonPath('status', 'committing')
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('totals.created', 4);

    test()->postJson($commit)->assertOk()
        ->assertJsonPath('status', 'committed')
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('totals.created', 5);

    // Everyone once, each with a number of their own, drawn a chunk at a time.
    $codes = Employee::query()->where('tenant_id', $tenant->id)->pluck('employee_code');
    expect($codes)->toHaveCount(5)
        ->and($codes->unique())->toHaveCount(5);

    // Recorded once, when the list was finished.
    expect(AuditLog::query()->where('action', 'migration.committed')->count())->toBe(1)
        ->and(OnboardingProgress::query()->where('tenant_id', $tenant->id)->value('completed_steps'))
        ->toContain(OnboardingStep::WORKFORCE_MIGRATION->value);
});

it('adds nobody when a finished list is committed again', function () {
    [$tenant] = Staging::organisation();
    $staged = stagedFivePeople($tenant);
    $commit = Staging::url($tenant, "batches/{$staged['public_id']}/commit");

    test()->postJson($commit);
    test()->postJson($commit);
    test()->postJson($commit)->assertJsonPath('status', 'committed');

    test()->postJson($commit)->assertOk()
        ->assertJsonPath('status', 'committed')
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('totals.created', 5);

    expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(5);
});

it('refuses a changed decision once the list has begun to be added', function () {
    [$tenant] = Staging::organisation();
    $staged = stagedFivePeople($tenant);

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
        ->assertJsonPath('status', 'committing');

    // The last row has not been written yet, and it still cannot be changed:
    // the list the reviewer approved is the list that is added.
    test()->patchJson(Staging::url($tenant, "rows/{$staged['rows'][4]['public_id']}"), ['action' => 'skip'])
        ->assertStatus(409);
});

it('finishes the list in one call for a caller with no time limit', function () {
    [$tenant, $user] = Staging::organisation();
    $batch = Staging::stage($tenant, fivePeople());

    $totals = Staging::service()->commit($batch, $user);

    expect($totals['created'])->toBe(5)
        ->and($batch->refresh()->status)->toBe('committed')
        ->and(MigrationBatch::query()->whereKey($batch->id)->value('commit_cursor'))->not->toBeNull();
});

it('gives the commit a rate limit of its own', function () {
    $route = Route::getRoutes()->getByAction('App\Http\Controllers\Api\V1\Onboarding\MigrationController@commit');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:migration-commit');
});

/*
 * What a new person is brought in with is written in the transaction of the
 * chunk that made them. A related record that cannot be written takes the
 * chunk with it: nobody is left without what their row brought.
 */
it('writes a new person\'s contract, bank account and emergency contact inside the slice', function () {
    [$tenant, $user] = Staging::organisation();
    $rows = array_map(fn (array $person): array => [
        ...$person,
        'hire_date' => '2026-01-15',
        'bank_name' => 'Commercial Bank',
        'bank_account' => '10001'.random_int(10000, 99999),
        'contract_type' => 'permanent',
        'emergency_name' => 'Hana Bekele',
        'emergency_relationship' => 'Sister',
        'emergency_phone' => '0911223344',
    ], fivePeople());

    $batch = Staging::stage($tenant, $rows);
    $progress = Staging::service()->commitSlice($batch, $user);

    // One chunk of two people, each whole.
    expect($progress->done)->toBeFalse()
        ->and(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(EmployeeContract::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(EmployeeBankDetail::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(EmployeeEmergencyContact::query()->where('tenant_id', $tenant->id)->count())->toBe(2);

    Staging::service()->commit($batch->refresh(), $user);

    expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(5)
        ->and(EmployeeContract::query()->where('tenant_id', $tenant->id)->count())->toBe(5)
        ->and(EmployeeBankDetail::query()->where('tenant_id', $tenant->id)->count())->toBe(5);
});

it('leaves nobody half made when a related record cannot be written, and goes on once it can', function () {
    [$tenant, $user] = Staging::organisation();
    $rows = array_map(fn (array $person): array => [
        ...$person,
        'bank_name' => 'Commercial Bank',
        'bank_account' => '1000123456789',
    ], fivePeople());

    $batch = Staging::stage($tenant, $rows);

    app()->bind(EmployeeRelatedRecords::class, fn () => new class extends EmployeeRelatedRecords
    {
        public function addBankDetail(Employee $employee, array $data): EmployeeBankDetail
        {
            throw new RuntimeException('The bank account could not be written.');
        }
    });
    app()->forgetInstance(WorkforceMigrationService::class);

    expect(fn () => Staging::service()->commitSlice($batch, $user))->toThrow(RuntimeException::class);

    // The chunk's first person is gone with the chunk, and the list has not moved.
    expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(EmployeeBankDetail::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(MigrationBatch::query()->whereKey($batch->id)->value('commit_cursor'))->toBeNull()
        ->and($batch->refresh()->status)->not->toBe('committed');

    app()->bind(EmployeeRelatedRecords::class, EmployeeRelatedRecords::class);
    app()->forgetInstance(WorkforceMigrationService::class);

    Staging::service()->commit($batch->refresh(), $user);

    expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(5)
        ->and(EmployeeBankDetail::query()->where('tenant_id', $tenant->id)->count())->toBe(5);
});

/*
 * An employee number is held by every record that carries it, archived or
 * not: `(tenant_id, employee_code)` is unique over the soft-deleted rows too.
 * A list that brings such a number used to be able to set the row to "add as
 * new", and the insert then failed inside the chunk's transaction, again on
 * every retry.
 */
describe('a number another record holds', function () {
    /** @return array<string, mixed> */
    function heldRowOf(Tenant $tenant, MigrationBatch $batch, int $index = 0): array
    {
        return test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertOk()->json("rows.{$index}");
    }

    function forceCreate(MigrationBatch $batch, int $index): void
    {
        // What the PATCH now refuses: the choice written as a list that was
        // reviewed before the rule would carry it.
        $row = $batch->rows()->orderBy('id')->skip($index)->firstOrFail();
        DB::table('migration_staging_rows')->where('id', $row->id)->update(['action' => 'create']);
    }

    it('holds a row whose number an archived record holds, names the number, and will not add it as new', function () {
        [$tenant] = Staging::organisation();
        // Someone used since cannot be brought back by the row (RestoreFromListTest).
        Undo::use($tenant, Staging::archived($tenant, ['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001']), 'attendance');

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'emp-001']]);
        $row = heldRowOf($tenant, $batch);

        expect($row['action'])->toBe('defer')
            ->and($row['warnings'])->toHaveCount(1)
            ->and($row['warnings'][0]['code'])->toBe('number_held')
            ->and($row['warnings'][0]['message'])->toContain('emp-001')->toContain('archived');

        test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'create'])
            ->assertStatus(422)
            ->assertJsonPath('detail', __('onboarding.migration.number_held', ['value' => 'emp-001']));

        expect(heldRowOf($tenant, $batch)['action'])->toBe('defer');
    });

    it('never throws at commit for a held number, even when the choice was forced, and counts the row as left for review', function () {
        [$tenant, $user] = Staging::organisation();
        Staging::archived($tenant, ['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001']);

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001'], ['name' => 'Sara Tesfaye', 'employee_code' => 'EMP-002']]);
        forceCreate($batch, 0);

        $totals = Staging::service()->commit($batch, $user);

        expect($totals)->toMatchArray(['created' => 1, 'deferred' => 1])
            ->and($batch->refresh()->status)->toBe('committed')
            ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(2)
            ->and($batch->rows()->orderBy('id')->first()->processed_at)->toBeNull();
    });

    it('finishes a list stuck half way on a held number at its next commit request', function () {
        [$tenant] = Staging::organisation();
        Staging::archived($tenant, ['name' => 'Dawit Haile', 'employee_code' => 'EMP-003']);

        $batch = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'EMP-002'],
            ['name' => 'Dawit Haile', 'employee_code' => 'EMP-003'],
            ['name' => 'Hana Girma', 'employee_code' => 'EMP-004'],
            ['name' => 'Yonas Bekele', 'employee_code' => 'EMP-005'],
        ]);
        forceCreate($batch, 2);
        $commit = Staging::url($tenant, "batches/{$batch->public_id}/commit");

        test()->postJson($commit)->assertOk()->assertJsonPath('status', 'committing')->assertJsonPath('totals.created', 2);

        // The held row is in the second slice. It used to fail there on every request.
        test()->postJson($commit)->assertOk()->assertJsonPath('status', 'committing')->assertJsonPath('totals.created', 3)->assertJsonPath('totals.deferred', 1);
        test()->postJson($commit)->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('totals.created', 4);

        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(4);
    });

    it('defaults to leaving the row for review when the national IDs differ, not to adding it', function () {
        [$tenant] = Staging::organisation();
        Staging::archived($tenant, ['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001', 'national_id' => '1111111111111111']);

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001', 'national_id' => '2222222222222222']]);
        $row = heldRowOf($tenant, $batch);

        expect($row['match_outcome'])->toBe('new')
            ->and($row['action'])->toBe('defer')
            ->and($row['warnings'][0]['code'])->toBe('number_held');

        $totals = Staging::service()->commit($batch);
        expect($totals)->toMatchArray(['created' => 0, 'deferred' => 1]);
    });

    it('refuses to add as new a likely match whose number a current employee holds, and cannot crash a commit', function () {
        [$tenant, $user] = Staging::organisation();
        $holder = Staging::employee($tenant, ['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001', 'national_id' => '1111111111111111']);

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001', 'national_id' => '2222222222222222']]);
        $row = heldRowOf($tenant, $batch);

        expect($row['match_outcome'])->toBe('probable')
            ->and($row['warnings'][0]['code'])->toBe('number_held')
            ->and($row['warnings'][0]['message'])->not->toContain('archived');

        test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'create'])->assertStatus(422);

        forceCreate($batch, 0);
        expect(Staging::service()->commit($batch, $user))->toMatchArray(['created' => 0, 'deferred' => 1])
            ->and(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and($holder->refresh()->name)->toBe('Abebe Kebede');
    });

    it('adds the person without a badge number someone else holds, and keeps the warning', function () {
        [$tenant, $user] = Staging::organisation();
        $holder = Staging::employee($tenant, ['name' => 'Meron Alemu', 'employee_code' => 'M-1', 'badge_number' => 'B-100']);

        $batch = Staging::stage($tenant, [['name' => 'Yonas Bekele', 'employee_code' => 'Y-1', 'badge_number' => 'b-100']]);
        $row = heldRowOf($tenant, $batch);

        expect($row['warnings'])->toHaveCount(1)
            ->and($row['warnings'][0]['code'])->toBe('badge_held');

        test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'create'])->assertOk();

        expect(Staging::service()->commit($batch, $user))->toMatchArray(['created' => 1]);

        $made = Employee::query()->where('tenant_id', $tenant->id)->where('name', 'Yonas Bekele')->sole();
        expect($made->badge_number)->toBeNull()
            ->and($holder->refresh()->badge_number)->toBe('B-100')
            ->and(heldRowOf($tenant, $batch->refresh())['warnings'][0]['code'])->toBe('badge_held');
    });

    it('does not call a number held that another organisation holds', function () {
        $other = createTenant();
        Staging::archived($other, ['employee_code' => 'EMP-001', 'badge_number' => 'B-100']);
        Staging::employee($other, ['employee_code' => 'EMP-002', 'badge_number' => 'B-200']);
        [$tenant, $user] = Staging::organisation();

        $batch = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001', 'badge_number' => 'B-100'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'EMP-002', 'badge_number' => 'B-200'],
        ]);

        foreach ([0, 1] as $index) {
            expect(heldRowOf($tenant, $batch, $index)['warnings'])->toBe([])
                ->and(heldRowOf($tenant, $batch, $index)['action'])->toBe('create');
        }

        expect(Staging::service()->commit($batch, $user))->toMatchArray(['created' => 2])
            ->and(Employee::query()->where('tenant_id', $tenant->id)->pluck('badge_number')->sort()->values()->all())->toBe(['B-100', 'B-200']);
    });

    it('survives the unique index itself when the held number was not seen, and goes on with the rest of the chunk', function () {
        [$tenant, $user] = Staging::organisation();
        Staging::archived($tenant, ['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001']);

        // A holder the question did not see: a record made a moment ago by
        // another request, as far as this commit can tell.
        app()->bind(HeldNumbers::class, fn () => new class extends HeldNumbers
        {
            public function among(int $tenantId, iterable $codes, iterable $badges = []): HeldSet
            {
                return new HeldSet([], []);
            }
        });
        app()->forgetInstance(WorkforceMigrationService::class);

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001'], ['name' => 'Sara Tesfaye', 'employee_code' => 'EMP-002']]);
        forceCreate($batch, 0);

        // The first row's insert fails inside the chunk; the second row is in the same chunk.
        expect(Staging::service()->commit($batch, $user))->toMatchArray(['created' => 1, 'deferred' => 1])
            ->and($batch->refresh()->status)->toBe('committed')
            ->and(Employee::query()->where('tenant_id', $tenant->id)->pluck('name')->all())->toBe(['Sara Tesfaye']);
    });

    it('names the holder only to someone who may see that person', function () {
        [$tenant] = Staging::organisation();
        $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN']);
        $sales = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'SAL']);
        Undo::use($tenant, Staging::archived($tenant, ['name' => 'Hana Girma', 'employee_code' => 'EMP-009', 'department_id' => $sales->id]), 'attendance');
        Undo::use($tenant, Staging::archived($tenant, ['name' => 'Meron Alemu', 'employee_code' => 'EMP-010', 'department_id' => $finance->id]), 'attendance');

        $batch = Staging::stage($tenant, [
            ['name' => 'Hana Girma', 'employee_code' => 'EMP-009'],
            ['name' => 'Meron Alemu', 'employee_code' => 'EMP-010'],
        ]);

        $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $finance->id]);
        test()->actingAs(createScopedUser($tenant, $head->id, 'department', ['employee.viewAny', 'employee.view']));

        $rows = test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertOk()->json('rows');
        expect($rows[0]['warnings'][0]['message'])->toContain('EMP-009')->not->toContain('Hana Girma')
            ->and($rows[1]['warnings'][0]['message'])->toContain('Meron Alemu');

        test()->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));
        $rows = test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertOk()->json('rows');
        expect($rows[0]['warnings'][0]['message'])->toContain('Hana Girma');
    });
});
