<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\MigrationStagingRow;
use App\Models\Tenant;
use Tests\Support\StagingFixtures as Staging;

/*
 * A list brought in a second time (2026-11-18). An organisation's staff list
 * carried names and departments and nothing a person has only one of: no
 * national ID, phone or email. The second time it was committed, all 167 of
 * its people were added again, because a name alone never reaches "may be
 * this person" (IdentityResolver).
 *
 * A row nobody matched is now looked for by its full name. Someone already
 * here under exactly that name, in the department the row names, holds the
 * row for review. A name still never matches anyone on its own: committing
 * the list as it stands adds nobody twice, and links nobody either.
 */

/** @return array{public_id: string, summary: array<string, int>, rows: list<array<string, mixed>>} */
function sameNameStage(Tenant $tenant, array $grid, ?string $mode = null): array
{
    return test()->postJson(Staging::url($tenant, 'rows'), array_filter(['grid' => $grid, 'mode' => $mode]))
        ->assertCreated()
        ->json();
}

function sameNameCommit(Tenant $tenant, string $batch): array
{
    return test()->postJson(Staging::url($tenant, "batches/{$batch}/commit"))->assertOk()->json('totals');
}

function sameNameHeadcount(Tenant $tenant): int
{
    return Employee::query()->where('tenant_id', $tenant->id)->count();
}

const SAME_NAME_LIST = [
    ['Name', 'Department'],
    ['Semira Aragaw Sisay', 'Revenue Office'],
    ['Abebe Kebede Tesfaye', 'Revenue Office'],
    ['Hanna Girma Alemu', 'Health Office'],
];

function sameNameDepartments(Tenant $tenant): void
{
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Revenue Office', 'code' => 'REV']);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Health Office', 'code' => 'HEA']);
}

it('creates nobody the second time the same list is brought in', function () {
    [$tenant] = Staging::organisation();
    sameNameDepartments($tenant);

    $first = sameNameStage($tenant, SAME_NAME_LIST);
    expect(collect($first['rows'])->pluck('match_outcome')->unique()->all())->toBe(['new']);
    expect(sameNameCommit($tenant, $first['public_id'])['created'])->toBe(3);

    $second = sameNameStage($tenant, SAME_NAME_LIST);

    expect($second['summary'])->toBe([MigrationStagingRow::OUTCOME_SAME_NAME => 3]);
    foreach ($second['rows'] as $row) {
        $here = Employee::query()->where('tenant_id', $tenant->id)->where('name', $row['display_name'])->sole();

        expect($row['match_outcome'])->toBe('same_name')
            ->and($row['action'])->toBe('defer')
            ->and($row['resolved_employee_public_id'])->toBe($here->public_id)
            ->and($row['resolved_employee_code'])->toBe($here->employee_code)
            ->and($row['candidates'])->toHaveCount(1)
            ->and($row['candidates'][0]['employee_public_id'])->toBe($here->public_id)
            ->and($row['candidates'][0]['employee_code'])->toBe($here->employee_code)
            ->and($row['candidates'][0]['reasons'])->toBe(['same_full_name']);
    }

    $totals = sameNameCommit($tenant, $second['public_id']);

    expect($totals['created'])->toBe(0)
        ->and($totals['deferred'])->toBe(3)
        ->and(sameNameHeadcount($tenant))->toBe(3);
});

it('adds one of them when the reviewer says it is a different person, and links one they say is them', function () {
    [$tenant] = Staging::organisation();
    sameNameDepartments($tenant);
    sameNameCommit($tenant, sameNameStage($tenant, SAME_NAME_LIST)['public_id']);

    $second = sameNameStage($tenant, SAME_NAME_LIST);
    [$semira, $abebe] = $second['rows'];

    test()->patchJson(Staging::url($tenant, "rows/{$semira['public_id']}"), ['action' => 'create'])
        ->assertOk()
        ->assertJsonPath('action', 'create');
    test()->patchJson(Staging::url($tenant, "rows/{$abebe['public_id']}"), ['action' => 'merge'])
        ->assertOk()
        ->assertJsonPath('action', 'merge');

    $totals = sameNameCommit($tenant, $second['public_id']);

    expect($totals['created'])->toBe(1)
        ->and($totals['merged'])->toBe(1)
        ->and($totals['deferred'])->toBe(1)
        ->and(sameNameHeadcount($tenant))->toBe(4)
        ->and(Employee::query()->where('tenant_id', $tenant->id)->where('name', 'Semira Aragaw Sisay')->count())->toBe(2)
        ->and(Employee::query()->where('tenant_id', $tenant->id)->where('name', 'Abebe Kebede Tesfaye')->count())->toBe(1);
});

it('compares the whole name, ignoring case and spaces, and nothing less', function () {
    [$tenant] = Staging::organisation();
    Staging::employee($tenant, ['name' => 'Semira Aragaw Sisay']);

    $staged = sameNameStage($tenant, [
        ['Name'],
        ['  semira   ARAGAW sisay '],
        ['Semira Aragaw'],
        ['Semira Aragaw Sisaye'],
    ]);

    expect(collect($staged['rows'])->pluck('match_outcome')->all())->toBe(['same_name', 'new', 'new']);
});

it('still adds two people with the same name in different departments', function () {
    [$tenant] = Staging::organisation();
    sameNameDepartments($tenant);
    $revenue = Department::query()->where('tenant_id', $tenant->id)->where('code', 'REV')->sole();
    Staging::employee($tenant, ['name' => 'Semira Aragaw Sisay', 'department_id' => $revenue->id]);

    $staged = sameNameStage($tenant, [['Name', 'Department'], ['Semira Aragaw Sisay', 'Health Office']]);

    expect($staged['rows'][0]['match_outcome'])->toBe('new')
        ->and($staged['rows'][0]['action'])->toBe('create')
        ->and(sameNameCommit($tenant, $staged['public_id'])['created'])->toBe(1)
        ->and(Employee::query()->where('tenant_id', $tenant->id)->where('name', 'Semira Aragaw Sisay')->count())->toBe(2);
});

it('holds a row that names no department against everyone with the name, and lets the reviewer say which', function () {
    [$tenant] = Staging::organisation();
    sameNameDepartments($tenant);
    $departments = Department::query()->where('tenant_id', $tenant->id)->orderBy('code')->get();
    $health = Staging::employee($tenant, ['name' => 'Semira Aragaw Sisay', 'employee_code' => 'WOR-0001', 'department_id' => $departments[0]->id]);
    Staging::employee($tenant, ['name' => 'Semira Aragaw Sisay', 'employee_code' => 'WOR-0002', 'department_id' => $departments[1]->id]);

    $row = sameNameStage($tenant, [['Name'], ['Semira Aragaw Sisay']])['rows'][0];

    expect($row['match_outcome'])->toBe('same_name')
        ->and($row['resolved_employee_public_id'])->toBeNull()
        ->and(array_column($row['candidates'], 'employee_code'))->toBe(['WOR-0001', 'WOR-0002']);

    test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), [
        'action' => 'merge',
        'employee_public_id' => $health->public_id,
    ])->assertOk()->assertJsonPath('resolved_employee_public_id', $health->public_id);
});

it('holds a row whose department the organisation does not have against someone in no department', function () {
    // The pasted list refuses a department nobody has; a terminal's roster
    // does not, and its people are added with none.
    [$tenant] = Staging::organisation();
    Staging::employee($tenant, ['name' => 'Semira Aragaw Sisay', 'department_id' => null]);

    $batch = Staging::stage($tenant, [['name' => 'Semira Aragaw Sisay', 'department' => 'Revenue Office']]);

    expect(Staging::firstRow($batch)->match_outcome)->toBe(MigrationStagingRow::OUTCOME_SAME_NAME);
});

it('never counts a name held in another organisation', function () {
    [$tenant] = Staging::organisation();
    $other = createTenant();
    Employee::factory()->create(['tenant_id' => $other->id, 'name' => 'Semira Aragaw Sisay', 'department_id' => null]);

    $staged = sameNameStage($tenant, [['Name'], ['Semira Aragaw Sisay']]);

    expect($staged['rows'][0]['match_outcome'])->toBe('new')
        ->and($staged['rows'][0]['candidates'])->toBe([])
        ->and(json_encode($staged))->not->toContain((string) Employee::withoutGlobalScopes()->where('tenant_id', $other->id)->value('public_id'));
});

it('does not hold a row for someone deleted', function () {
    [$tenant] = Staging::organisation();
    Staging::employee($tenant, ['name' => 'Semira Aragaw Sisay'])->delete();

    expect(sameNameStage($tenant, [['Name'], ['Semira Aragaw Sisay']])['rows'][0]['match_outcome'])->toBe('new');
});

it('lets a list that completes people find them by name, once the reviewer says it is them', function () {
    [$tenant] = Staging::organisation();
    $here = Staging::employee($tenant, ['name' => 'Semira Aragaw Sisay', 'phone' => null]);

    $staged = sameNameStage($tenant, [['Name', 'Phone'], ['Semira Aragaw Sisay', '0911223344']], 'complete');
    $row = $staged['rows'][0];

    // A name alone used to be "not found, left out" in a list that adds nobody.
    expect($row['match_outcome'])->toBe('same_name')
        ->and($row['action'])->toBe('defer');

    test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'create'])->assertStatus(422);
    test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'merge'])->assertOk();

    $totals = sameNameCommit($tenant, $staged['public_id']);

    expect($totals['created'])->toBe(0)
        ->and($totals['merged'])->toBe(1)
        ->and($here->refresh()->phone)->toBe('+251911223344');
});

it('holds a row whose badge nobody holds when its name is someone else\'s', function () {
    [$tenant] = Staging::organisation();
    Staging::employee($tenant, ['name' => 'Semira Aragaw Sisay', 'employee_code' => 'WOR-0144']);

    $batch = Staging::stage($tenant, [['name' => 'Semira Aragaw Sisay', 'badge_number' => '9001']]);

    expect(Staging::firstRow($batch)->match_outcome)->toBe(MigrationStagingRow::OUTCOME_SAME_NAME)
        ->and(Staging::firstRow($batch)->action)->toBe(MigrationStagingRow::ACTION_DEFER);
});
