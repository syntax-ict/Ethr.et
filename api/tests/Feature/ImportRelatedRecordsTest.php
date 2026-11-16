<?php

declare(strict_types=1);

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\AuditLog;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeContract;
use App\Models\EmployeeEmergencyContact;
use App\Models\Grade;
use App\Models\Tenant;
use Tests\Support\StagingFixtures as Staging;

/*
 * A staged list brings a new person in with the rest of their profile: a
 * grade, a cost centre, a probation end, a contract, a bank account and an
 * emergency contact. Each is written through the service its own screen uses.
 * A person the list matched to someone already here is not touched by them.
 */

/**
 * One line of the list under PROFILE_HEADER, with the cells a test changes.
 *
 * @param  array<string, string>  $overrides
 * @return list<string>
 */
function profileLine(array $overrides = []): array
{
    return array_values([
        'Name' => 'Selam Bekele', 'Hire date' => '2026-01-15', 'Salary' => '15000', 'Grade' => 'Senior',
        'Cost centre' => 'Operations', 'Probation end date' => '2026-04-15', 'Contract type' => 'fixed term',
        'Contract end date' => '2027-01-14', 'Bank name' => 'Commercial Bank', 'Bank branch' => 'Bole',
        'Bank account' => '1000123456789', 'Emergency name' => 'Hana Bekele', 'Emergency relationship' => 'Sister',
        'Emergency phone' => '0911223344',
        ...$overrides,
    ]);
}

/** @return list<string> */
function profileHeader(): array
{
    return [
        'Name', 'Hire date', 'Salary', 'Grade', 'Cost centre', 'Probation end date', 'Contract type', 'Contract end date',
        'Bank name', 'Bank branch', 'Bank account', 'Emergency name', 'Emergency relationship', 'Emergency phone',
    ];
}

/** @return array{0: Tenant, 1: Grade, 2: CostCenter} */
function profileOrganisation(): array
{
    [$tenant] = Staging::organisation();
    $grade = Grade::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Senior']);
    $centre = CostCenter::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations', 'code' => 'OPS']);

    return [$tenant, $grade, $centre];
}

/**
 * Staged and committed through the endpoints, as the screen does it.
 *
 * @param  list<list<string>>  $lines
 */
function commitProfileList(Tenant $tenant, array $lines): void
{
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [profileHeader(), ...$lines]])
        ->assertCreated()->json();

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
        ->assertOk()->assertJsonPath('status', 'committed');
}

it('brings a new person in with the whole of their profile', function () {
    [$tenant, $grade, $centre] = profileOrganisation();

    commitProfileList($tenant, [profileLine()]);

    $selam = Employee::query()->where('tenant_id', $tenant->id)->where('name', 'Selam Bekele')->firstOrFail();

    expect($selam->grade_id)->toBe($grade->id)
        ->and($selam->cost_center_id)->toBe($centre->id)
        ->and($selam->probation_end_date?->toDateString())->toBe('2026-04-15')
        ->and($selam->salary_cents)->toBe(1500000);

    $contracts = EmployeeContract::query()->where('employee_id', $selam->id)->get();
    expect($contracts)->toHaveCount(1)
        ->and($contracts[0]->contract_type)->toBe(ContractType::FIXED_TERM)
        ->and($contracts[0]->status)->toBe(ContractStatus::ACTIVE)
        ->and($contracts[0]->start_date->toDateString())->toBe('2026-01-15')
        ->and($contracts[0]->end_date?->toDateString())->toBe('2027-01-14')
        ->and($contracts[0]->salary_cents)->toBeNull();

    $bank = EmployeeBankDetail::query()->where('employee_id', $selam->id)->get();
    expect($bank)->toHaveCount(1)
        ->and($bank[0]->bank_name)->toBe('Commercial Bank')
        ->and($bank[0]->branch_name)->toBe('Bole')
        ->and($bank[0]->account_number)->toBe('1000123456789')
        ->and($bank[0]->is_primary)->toBeTrue()
        ->and($bank[0]->tenant_id)->toBe($tenant->id);

    $contact = EmployeeEmergencyContact::query()->where('employee_id', $selam->id)->get();
    expect($contact)->toHaveCount(1)
        ->and($contact[0]->name)->toBe('Hana Bekele')
        ->and($contact[0]->relationship)->toBe('Sister')
        ->and($contact[0]->phone)->toBe('+251911223344')
        ->and($contact[0]->tenant_id)->toBe($tenant->id);

    $audited = AuditLog::query()->where('auditable_id', $selam->id)->pluck('action')->all();
    expect($audited)->toContain('employee.contract_created', 'employee.bank_detail.created', 'employee.emergency_contact.created');

    // Nothing in the audit log holds the account number or the relative's phone.
    expect(json_encode(AuditLog::query()->where('auditable_id', $selam->id)->pluck('payload')->all()))
        ->not->toContain('1000123456789')->not->toContain('911223344');
});

it('fills in what a person the list matched to someone already here lacks, and makes no second person', function () {
    [$tenant, $grade, $centre] = profileOrganisation();
    $abebe = Staging::employee($tenant, ['employee_code' => 'E-1', 'email' => 'abebe@example.et']);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [
        [...profileHeader(), 'Email'],
        [...profileLine(['Name' => 'Abebe Kebede']), 'abebe@example.et'],
    ]])->assertCreated()->json();
    $row = $staged['rows'][0];

    expect($row['match_outcome'])->not->toBe('new');

    test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'merge'])->assertOk();
    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk();

    // What PR 4 left alone, the list now completes (complete-existing-profiles):
    // a blank is filled, a record the person has none of is added once.
    $abebe->refresh();
    expect($abebe->grade_id)->toBe($grade->id)
        ->and($abebe->cost_center_id)->toBe($centre->id)
        ->and($abebe->probation_end_date->toDateString())->toBe('2026-04-15')
        ->and($abebe->contracts()->count())->toBe(1)
        ->and($abebe->bankDetails()->count())->toBe(1)
        ->and($abebe->emergencyContacts()->count())->toBe(1)
        ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('names the column of each half-filled group, and of a contract that cannot be made', function (array $line, string $column) {
    [$tenant] = profileOrganisation();

    $response = test()->postJson(Staging::url($tenant, 'read'), ['grid' => [profileHeader(), profileLine($line)]])
        ->assertOk();

    expect($response->json('people'))->toBe(0)
        ->and(json_encode($response->json('problems')))->toContain(str_replace('_', ' ', $column));
})->with([
    'bank account without a bank' => [['Bank name' => ''], 'bank_name'],
    'bank without an account' => [['Bank account' => ''], 'bank_account'],
    'emergency name without a phone' => [['Emergency phone' => ''], 'emergency_phone'],
    'emergency phone without a name' => [['Emergency name' => ''], 'emergency_name'],
    'emergency without a relationship' => [['Emergency relationship' => ''], 'emergency_relationship'],
    'a contract end without a type' => [['Contract type' => ''], 'contract type'],
    'a fixed term without an end' => [['Contract end date' => ''], 'contract end date'],
    'a contract that ends on its start' => [['Contract end date' => '2026-01-15'], 'contract end date'],
    'a probation that ends before the hire' => [['Probation end date' => '2026-01-01'], 'probation end date'],
    'a contract type that does not exist' => [['Contract type' => 'forever'], 'contract type'],
    'a grade that is not here' => [['Grade' => 'Imaginary'], 'grade'],
    'a cost centre that is not here' => [['Cost centre' => 'Nowhere'], 'cost centre'],
]);

it('lets a permanent contract have no end date, and a list leave every group blank', function () {
    [$tenant] = profileOrganisation();

    $blank = array_fill_keys(
        ['Grade', 'Cost centre', 'Probation end date', 'Contract type', 'Contract end date', 'Bank name', 'Bank branch', 'Bank account', 'Emergency name', 'Emergency relationship', 'Emergency phone'],
        '',
    );

    commitProfileList($tenant, [
        profileLine(['Contract type' => 'permanent', 'Contract end date' => '']),
        profileLine(['Name' => 'Dawit Haile', ...$blank]),
    ]);

    $selam = Employee::query()->where('name', 'Selam Bekele')->firstOrFail();
    $dawit = Employee::query()->where('name', 'Dawit Haile')->firstOrFail();

    expect($selam->contracts()->firstOrFail()->contract_type)->toBe(ContractType::PERMANENT)
        ->and($dawit->contracts()->count())->toBe(0)
        ->and($dawit->bankDetails()->count())->toBe(0)
        ->and($dawit->emergencyContacts()->count())->toBe(0);
});

it('refuses a list with a bank column from someone without the pay permission, as it refuses a salary', function () {
    $tenant = createTenant();
    Grade::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Senior']);
    CostCenter::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations', 'code' => 'OPS']);
    test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.create', 'employee.viewAny']));

    $noPay = ['Salary' => '', 'Bank name' => '', 'Bank branch' => '', 'Bank account' => '', 'Contract type' => '', 'Contract end date' => ''];

    // Everything but pay is accepted.
    test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [profileHeader(), profileLine($noPay)]])
        ->assertCreated();

    $banked = [
        'bank name' => ['Bank name' => 'Commercial Bank', 'Bank account' => '1000123456789'],
        'bank account' => ['Bank name' => 'Commercial Bank', 'Bank account' => '1000123456789', 'Bank branch' => 'Bole'],
    ];
    foreach ($banked as $line) {
        test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [profileHeader(), profileLine([...$noPay, ...$line])]])
            ->assertForbidden();
    }

    // A salary is refused the same way.
    test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [profileHeader(), profileLine([...$noPay, 'Salary' => '15000'])]])
        ->assertForbidden();
});

it('refuses the keyed shape too, naming the column of each problem', function () {
    [$tenant] = profileOrganisation();

    $url = Staging::url($tenant, 'rows');
    $row = ['name' => 'Selam Bekele', 'hire_date' => '2026-01-15'];

    // A group left half filled is a rule's answer.
    expect(array_keys(test()->postJson($url, ['rows' => [[...$row, 'bank_account' => '1000123456789']]])->assertUnprocessable()->json('errors')))
        ->toContain('rows.0.bank_name');

    // What compares two columns, or asks the organisation, is asked once the rules pass.
    expect(array_keys(test()->postJson($url, ['rows' => [[...$row, 'grade' => 'Imaginary', 'contract_end_date' => '2027-01-14']]])->assertUnprocessable()->json('errors')))
        ->toContain('rows.0.grade', 'rows.0.contract_type');
});

it('never shows the account number back on the review screen', function () {
    [$tenant] = profileOrganisation();

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [profileHeader(), profileLine()]])
        ->assertCreated()->json();

    expect(json_encode($staged))->not->toContain('1000123456789');
});

it('refuses a contract with no hire date, in the grid and in the keyed shape, naming the hire date', function () {
    [$tenant] = profileOrganisation();

    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => [profileHeader(), profileLine(['Hire date' => ''])]])
        ->assertOk();

    expect($read->json('people'))->toBe(0)
        ->and(array_column($read->json('problems'), 'message'))
        ->toContain(__('onboarding.migration.profile_contract_needs_hire_date'));

    $keyed = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => [['name' => 'Selam Bekele', 'contract_type' => 'permanent']]])
        ->assertUnprocessable();

    expect(array_keys($keyed->json('errors')))->toContain('rows.0.hire_date');

    // With a hire date the same row is accepted.
    test()->postJson(Staging::url($tenant, 'rows'), ['rows' => [['name' => 'Selam Bekele', 'hire_date' => '2026-01-15', 'contract_type' => 'permanent']]])
        ->assertCreated();
});
