<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\ExitClearance;
use App\Models\ExitClearanceItem;
use App\Models\Tenant;
use App\Services\Offboarding\ExitClearanceService;
use App\Support\ExitClearanceTemplate;
use Carbon\Carbon;

// Exit clearance (blueprint L10): a resignation, termination or retirement
// starts a checklist from the organisation's template. HR ticks any line,
// finance its own, the leaver's supervisor theirs. It records and never
// blocks: the exit and the final pay go ahead whatever it says.

function clearanceUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

/** @return array{0: Tenant, 1: Employee, 2: Employee} tenant, leaver, supervisor */
function clearanceFixture(): array
{
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);
    $leaver = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => EmployeeStatus::CONFIRMED,
        'supervisor_id' => $supervisor->id,
    ]);

    return [$tenant, $leaver, $supervisor];
}

function clearanceLeave(Tenant $tenant, Employee $employee, string $to = 'resigned'): void
{
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(clearanceUrl($tenant, "employees/{$employee->public_id}/transition"), [
        'to_status' => $to,
        'effective_date' => Carbon::today()->addDays(14)->toDateString(),
        'reason' => 'Leaving',
    ])->assertSuccessful();
}

it('starts a checklist from the default template when someone leaves', function (string $to) {
    [$tenant, $leaver] = clearanceFixture();

    clearanceLeave($tenant, $leaver, $to);

    $clearance = ExitClearance::where('employee_id', $leaver->id)->sole();
    expect($clearance->status)->toBe('open')
        ->and($clearance->exit_status)->toBe($to)
        ->and($clearance->leaving_date?->toDateString())->toBe(Carbon::today()->addDays(14)->toDateString())
        ->and($clearance->items()->pluck('key')->all())->toBe(array_column(ExitClearanceTemplate::DEFAULTS, 'key'));
    expect(AuditLog::where('action', 'exit_clearance.started')->count())->toBe(1);
})->with(['resigned', 'terminated', 'retired']);

it('starts nothing for a transition that is not a departure', function () {
    [$tenant, $leaver] = clearanceFixture();

    clearanceLeave($tenant, $leaver, 'suspended');

    expect(ExitClearance::count())->toBe(0);
});

it('makes one clearance however many times it is started', function () {
    [$tenant, $leaver] = clearanceFixture();
    clearanceLeave($tenant, $leaver);

    $first = ExitClearance::sole();
    $again = app(ExitClearanceService::class)->start($leaver->fresh());

    expect($again->id)->toBe($first->id)
        ->and(ExitClearance::count())->toBe(1);
});

it('starts from the organisation template, keeping built-in lines translatable', function () {
    [$tenant, $leaver] = clearanceFixture();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(clearanceUrl($tenant, 'settings/exit-clearance'), ['items' => [
        ['key' => 'id_card', 'label' => 'ID and access card returned', 'owner' => 'hr'],
        ['key' => 'property', 'label' => 'Laptop and phone returned', 'owner' => 'hr'],
        ['label' => 'Exit interview held', 'owner' => 'supervisor'],
        ['key' => 'obligations', 'label' => 'Loans and advances settled', 'owner' => 'finance', 'kind' => 'obligations'],
    ]])->assertOk()->assertJsonPath('is_customized', true);

    clearanceLeave($tenant, $leaver);

    $items = ExitClearance::sole()->items()->get();
    expect($items->pluck('key')->all())->toBe(['id_card', null, null, 'obligations'])
        ->and($items->pluck('label')->all())->toBe([
            'ID and access card returned', 'Laptop and phone returned', 'Exit interview held', 'Loans and advances settled',
        ]);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->withHeader('Accept-Language', 'am')
        ->getJson(clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance"))
        ->assertOk()
        ->assertJsonPath('clearance.items.0.label', __('exit_clearance.items.id_card', [], 'am'))
        ->assertJsonPath('clearance.items.1.label', 'Laptop and phone returned');
});

it('refuses a template with two balance lines', function () {
    [$tenant] = clearanceFixture();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(clearanceUrl($tenant, 'settings/exit-clearance'), ['items' => [
        ['label' => 'Loans settled', 'owner' => 'finance', 'kind' => 'obligations'],
        ['label' => 'Advances settled', 'owner' => 'finance', 'kind' => 'obligations'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('items');
});

it('keeps the template to those who manage settings', function () {
    [$tenant] = clearanceFixture();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(clearanceUrl($tenant, 'settings/exit-clearance'))->assertForbidden();
});

it('lets HR tick any line, and completes the clearance when every line is done', function () {
    [$tenant, $leaver] = clearanceFixture();
    clearanceLeave($tenant, $leaver);
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $clearance = ExitClearance::sole();
    foreach ($clearance->items()->get() as $item) {
        test()->putJson(clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance/items/{$item->public_id}"), ['done' => true])
            ->assertOk()
            ->assertJsonPath('done', true)
            ->assertJsonPath('done_by', $hr->name);
    }

    expect($clearance->fresh()->status)->toBe('complete')
        ->and($clearance->fresh()->completed_at)->not->toBeNull();

    $first = $clearance->items()->first();
    test()->putJson(clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance/items/{$first->public_id}"), ['done' => false])
        ->assertOk()
        ->assertJsonPath('done', false);

    expect($clearance->fresh()->status)->toBe('open')
        ->and($clearance->fresh()->completed_at)->toBeNull();
    expect(AuditLog::where('action', 'exit_clearance.item_reopened')->count())->toBe(1);
});

it('lets finance tick finance lines only, and the supervisor theirs only', function () {
    [$tenant, $leaver, $supervisor] = clearanceFixture();
    clearanceLeave($tenant, $leaver);
    $items = ExitClearance::sole()->items()->get()->keyBy('key');
    $tick = fn (ExitClearanceItem $item) => test()->putJson(
        clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance/items/{$item->public_id}"),
        ['done' => true],
    );

    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $tick($items['final_pay'])->assertOk();
    $tick($items['property'])->assertForbidden();
    $tick($items['handover'])->assertForbidden();

    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    $tick($items['handover'])->assertOk();
    $tick($items['final_pay'])->assertForbidden();

    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $stranger->id], $tenant);
    $tick($items['handover'])->assertForbidden();
    test()->getJson(clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance"))->assertForbidden();
});

it('says per line who may tick it', function () {
    [$tenant, $leaver, $supervisor] = clearanceFixture();
    clearanceLeave($tenant, $leaver);

    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    $items = test()->getJson(clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance"))
        ->assertOk()
        ->json('clearance.items');

    expect(collect($items)->pluck('can_tick', 'key')->all())->toBe([
        'id_card' => false, 'property' => false, 'handover' => true, 'obligations' => false, 'final_pay' => false,
    ]);
});

it('shows the outstanding balance and asks for a note to tick it while one is owed, without blocking', function () {
    [$tenant, $leaver] = clearanceFixture();
    EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $leaver->id,
        'remaining_cents' => 1_250_00,
        'status' => 'active',
    ]);
    clearanceLeave($tenant, $leaver);
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    test()->getJson(clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance"))
        ->assertOk()
        ->assertJsonPath('obligations.loans_cents', 1_250_00)
        ->assertJsonPath('obligations.total_cents', 1_250_00);

    $line = ExitClearance::sole()->items()->where('kind', 'obligations')->sole();
    $url = clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance/items/{$line->public_id}");

    test()->putJson($url, ['done' => true])->assertUnprocessable()->assertJsonValidationErrors('note');
    test()->putJson($url, ['done' => true, 'note' => 'Deducted from final pay'])
        ->assertOk()
        ->assertJsonPath('note', 'Deducted from final pay');
});

it('does not resolve a line through another employee', function () {
    [$tenant, $leaver] = clearanceFixture();
    clearanceLeave($tenant, $leaver);
    $other = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $line = ExitClearance::sole()->items()->first();

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->putJson(clearanceUrl($tenant, "employees/{$other->public_id}/exit-clearance/items/{$line->public_id}"), ['done' => true])
        ->assertNotFound();
});

it('returns null for someone with no clearance, and HR can start one for an earlier leaver', function () {
    [$tenant, $leaver] = clearanceFixture();
    $leaver->update(['status' => EmployeeStatus::RESIGNED, 'termination_date' => '2026-09-30']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance"))
        ->assertOk()
        ->assertJsonPath('clearance', null);

    test()->postJson(clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance"))
        ->assertCreated()
        ->assertJsonPath('exit_status', 'resigned')
        ->assertJsonPath('leaving_date', '2026-09-30')
        ->assertJsonPath('item_count', 5)
        ->assertJsonPath('done_count', 0);
});

it('refuses to start a clearance by hand for someone still employed', function () {
    [$tenant, $leaver] = clearanceFixture();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(clearanceUrl($tenant, "employees/{$leaver->public_id}/exit-clearance"))
        ->assertUnprocessable();
});

it('lists open clearances by who has a part in them', function () {
    [$tenant, $leaver, $supervisor] = clearanceFixture();
    clearanceLeave($tenant, $leaver);
    $otherLeaver = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);
    clearanceLeave($tenant, $otherLeaver);

    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    test()->getJson(clearanceUrl($tenant, 'exit-clearances'))->assertOk()->assertJsonCount(2, 'data');

    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->getJson(clearanceUrl($tenant, 'exit-clearances'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee.public_id', $leaver->public_id);

    test()->getJson(clearanceUrl($tenant, 'exit-clearances?status=complete'))->assertOk()->assertJsonCount(0, 'data');
});

it('never fails the exit when the clearance cannot start', function () {
    [$tenant, $leaver] = clearanceFixture();
    app()->instance(ExitClearanceService::class, Mockery::mock(ExitClearanceService::class, function ($mock) {
        $mock->shouldReceive('start')->andThrow(new RuntimeException('no table yet'));
    }));

    clearanceLeave($tenant, $leaver);

    expect($leaver->fresh()->status)->toBe(EmployeeStatus::RESIGNED)
        ->and(ExitClearance::count())->toBe(0);
});

it('keeps clearances within their organisation', function () {
    [$tenant, $leaver] = clearanceFixture();
    clearanceLeave($tenant, $leaver);

    $other = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $other);

    test()->getJson(clearanceUrl($other, 'exit-clearances'))->assertOk()->assertJsonCount(0, 'data');
    test()->getJson(clearanceUrl($other, "employees/{$leaver->public_id}/exit-clearance"))->assertNotFound();
});
