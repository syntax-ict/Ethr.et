<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Services\Migration\WorkforceMigrationService;
use Tests\Support\StagingFixtures as Staging;

/*
 * A list of people who already work here (One employee identity, PR 9).
 * Everyone a list brought in arrived as Hired, and a Hired person cannot
 * resign, be suspended or have probation end. The reviewer now says once,
 * for the whole list, what its new people arrive as; a line that names its
 * own status keeps it.
 */

it('brings a whole list in as confirmed, a line with its own status keeping it', function () {
    [$tenant] = Staging::organisation();

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [
        ['Name', 'Employee number', 'Status'],
        ['Abebe Kebede', 'E-1', ''],
        ['Hanna Girma', 'E-2', 'Probation'],
        ['Sara Tesfaye', 'E-3', ''],
    ]])->assertCreated()->json();

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"), ['status' => 'confirmed'])
        ->assertOk()
        ->assertJsonPath('totals.created', 3);

    $people = Employee::where('tenant_id', $tenant->id)->orderBy('employee_code')->get();
    expect($people->map(fn (Employee $e) => $e->status)->all())
        ->toBe([EmployeeStatus::CONFIRMED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED])
        ->and($people[0]->confirmation_date?->toDateString())->toBe(now()->toDateString())
        ->and($people[1]->confirmation_date)->toBeNull();

    test()->getJson(Staging::url($tenant, "batches/{$staged['public_id']}"))
        ->assertOk()
        ->assertJsonPath('arrival_status', 'confirmed');
});

it('leaves a list hired when nothing is chosen, as before', function () {
    [$tenant] = Staging::organisation();
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [['Name'], ['Abebe Kebede']]])->assertCreated()->json();

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk();

    expect(Employee::where('tenant_id', $tenant->id)->value('status'))->toBe(EmployeeStatus::HIRED);
    test()->getJson(Staging::url($tenant, "batches/{$staged['public_id']}"))->assertJsonPath('arrival_status', null);
});

it('refuses a status nobody can be brought in with', function (string $status) {
    [$tenant] = Staging::organisation();
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [['Name'], ['Abebe Kebede']]])->assertCreated()->json();

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"), ['status' => $status])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(0);
})->with(['resigned', 'terminated', 'suspended', 'active']);

it('goes on as it began: a request carrying a list on cannot change what it arrives as', function () {
    [$tenant, $user] = Staging::organisation();
    $batch = Staging::stage($tenant, [
        ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
        ['name' => 'Hanna Girma', 'employee_code' => 'E-2'],
    ]);

    // The first slice begins the list as confirmed and is cut short: one
    // row a chunk, no time for a second.
    app()->when(WorkforceMigrationService::class)->needs('$chunk')->give(1);
    app()->when(WorkforceMigrationService::class)->needs('$sliceSeconds')->give(0.0);
    $service = app(WorkforceMigrationService::class);
    $first = $service->commitSlice($batch, $user, ['status' => 'confirmed']);
    expect($first->done)->toBeFalse()->and($batch->refresh()->status)->toBe('committing');

    // Another tab carries it on and says something else.
    test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/commit"), ['status' => 'probation'])->assertOk();

    expect(MigrationBatch::whereKey($batch->id)->firstOrFail()->arrivalStatus())->toBe('confirmed')
        ->and(Employee::where('tenant_id', $tenant->id)->pluck('status')->unique()->all())->toBe([EmployeeStatus::CONFIRMED]);
});

it('brings a device roster in as chosen too', function () {
    [$tenant, $user] = Staging::organisation();
    $batch = Staging::service()->stageFromRows([['name' => 'Device Person', 'badge_number' => '77']], $tenant->id, 'device', 'device:1');

    Staging::service()->commit($batch, $user, ['status' => 'probation']);

    expect(Employee::where('tenant_id', $tenant->id)->value('status'))->toBe(EmployeeStatus::PROBATION);
});
