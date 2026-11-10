<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Migration\WorkforceMigrationService;
use App\Services\PlanLimitService;
use App\Services\Sso\SsoUser;
use Illuminate\Support\Facades\Cache;
use Tests\Support\IntakeFixtures;

/*
 * Two requests for one person, at the same moment.
 *
 * "Is this someone we already have?" followed by "then create them" is a
 * check and an act, and between the two a second request can ask the same
 * question and hear the same answer. Both then create, and the organisation
 * has the person twice. No amount of matching fixes that; only doing both
 * steps as the organisation's single writer does.
 *
 * EmployeeIntake uses the seat lock every path already held for the plan's
 * limit. These tests pin, for each of the six ways in, that the question and
 * the insert both happen while that lock is held. A path that asked first and
 * took the lock afterwards would pass every other test in the suite.
 *
 * What a test cannot do is run two PHP processes into each other. The proof
 * here is structural: the statements the database was sent, and whether the
 * lock was held when each one ran.
 */

/** @param  list<bool>  $moments */
function allHeld(array $moments): bool
{
    return $moments !== [] && ! in_array(false, $moments, true);
}

it('asks and creates under the lock: the form', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $seen = IntakeFixtures::watchSeatLock($tenant);

    $this->postJson('/api/v1/employees', ['name' => 'Sara Tesfaye', 'phone' => '0922334455', 'hire_date' => '2026-01-05'])
        ->assertCreated();

    IntakeFixtures::assertWatched($seen);
    expect(allHeld($seen->asked))->toBeTrue()
        ->and(allHeld($seen->made))->toBeTrue()
        // Released afterwards: the next writer is not kept waiting.
        ->and(Cache::lock(PlanLimitService::lockKey($tenant, 'employees'), 1)->get())->toBeTrue();
});

it('asks and creates under the lock: the file import', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $seen = IntakeFixtures::watchSeatLock($tenant);

    $this->postJson('/api/v1/employees/import/commit', [
        'import_key' => 'locked',
        'rows' => [
            ['name' => 'Sara Tesfaye', 'phone' => '0922334455', 'hire_date' => '2026-01-05'],
            ['name' => 'Dawit Alemu', 'phone' => '0933445566', 'hire_date' => '2026-01-05'],
        ],
    ])->assertCreated()->assertJsonPath('created', 2);

    IntakeFixtures::assertWatched($seen);
    expect($seen->asked)->toHaveCount(2)
        ->and($seen->made)->toHaveCount(2)
        ->and(allHeld($seen->asked))->toBeTrue()
        ->and(allHeld($seen->made))->toBeTrue();
});

it('asks again and creates under the lock: a staged list', function () {
    $tenant = createTenant();
    $service = app(WorkforceMigrationService::class);
    $batch = $service->stageFromRows([
        ['name' => 'Sara Tesfaye', 'phone' => '0922334455', 'hire_date' => '2026-01-05'],
    ], $tenant->id);

    // Staging asked once already, with no lock: nothing is made there. What
    // matters is the question put again at commit.
    $seen = IntakeFixtures::watchSeatLock($tenant);
    expect($service->commit($batch)['created'])->toBe(1);

    IntakeFixtures::assertWatched($seen);
    expect(allHeld($seen->asked))->toBeTrue()
        ->and(allHeld($seen->made))->toBeTrue();
});

it('asks and creates under the lock: a hire', function () {
    $tenant = createTenant();
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $application = IntakeFixtures::approvedOffer($tenant, $hr);
    $seen = IntakeFixtures::watchSeatLock($tenant);

    $this->postJson("/api/v1/job-applications/{$application->public_id}/hire")->assertCreated();

    IntakeFixtures::assertWatched($seen);
    expect(allHeld($seen->asked))->toBeTrue()
        ->and(allHeld($seen->made))->toBeTrue();
});

it('asks and creates under the lock: SCIM', function () {
    $tenant = createTenant();
    $token = IntakeFixtures::scimToken($tenant);
    $seen = IntakeFixtures::watchSeatLock($tenant);

    $this->postJson('/api/v1/scim/v2/Users', [
        'userName' => 'sara@example.et',
        'name' => ['givenName' => 'Sara', 'familyName' => 'Tesfaye'],
        'emails' => [['value' => 'sara@example.et', 'type' => 'work', 'primary' => true]],
    ], ['Authorization' => "Bearer {$token}"])->assertCreated();

    IntakeFixtures::assertWatched($seen);
    expect(allHeld($seen->asked))->toBeTrue()
        ->and(allHeld($seen->made))->toBeTrue();
});

it('asks and creates under the lock: SSO', function () {
    $tenant = createTenant();
    $acs = IntakeFixtures::ssoSignsIn($tenant, new SsoUser(nameId: 'sara@example.et', email: 'sara@example.et', firstName: 'Sara', lastName: 'Tesfaye'));
    $seen = IntakeFixtures::watchSeatLock($tenant);

    app(CurrentTenant::class)->forget();
    $this->post($acs, ['SAMLResponse' => 'x'])->assertStatus(303);

    IntakeFixtures::assertWatched($seen);
    expect(allHeld($seen->asked))->toBeTrue()
        ->and(allHeld($seen->made))->toBeTrue();
});

it('asks nothing and makes nothing while another writer holds the lock', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $other = Cache::lock(PlanLimitService::lockKey($tenant, 'employees'), 60);
    expect($other->get())->toBeTrue();
    app()->instance(PlanLimitService::class, new PlanLimitService(lockWaitSeconds: 0));

    $seen = IntakeFixtures::watchSeatLock($tenant);

    // Refused, not let through to ask a question whose answer the other
    // writer is about to change.
    $this->postJson('/api/v1/employees', ['name' => 'Sara Tesfaye', 'hire_date' => '2026-01-05'])
        ->assertStatus(409)
        ->assertJsonPath('detail', __('billing.limit_check_busy'));

    expect($seen->asked)->toBe([])
        ->and($seen->made)->toBe([])
        ->and(Employee::where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('makes one record when the same person is sent twice, one after the other', function (Closure $send) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $send($this, $tenant);
    $send($this, $tenant);

    expect(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(1);
})->with([
    'the form' => [function ($test, Tenant $tenant): void {
        $test->postJson('/api/v1/employees', ['name' => 'Sara Tesfaye', 'email' => 'sara@example.et', 'hire_date' => '2026-01-05']);
    }],
    'the file import' => [function ($test, Tenant $tenant): void {
        $test->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'twice-'.bin2hex(random_bytes(4)),
            'rows' => [['name' => 'Sara Tesfaye', 'email' => 'sara@example.et', 'hire_date' => '2026-01-05']],
        ]);
    }],
    'a staged list' => [function ($test, Tenant $tenant): void {
        $service = app(WorkforceMigrationService::class);
        $service->commit($service->stageFromRows([
            ['name' => 'Sara Tesfaye', 'email' => 'sara@example.et', 'hire_date' => '2026-01-05'],
        ], $tenant->id));
    }],
    'SCIM' => [function ($test, Tenant $tenant): void {
        $test->postJson('/api/v1/scim/v2/Users', [
            'userName' => 'sara@example.et',
            'name' => ['givenName' => 'Sara', 'familyName' => 'Tesfaye'],
        ], ['Authorization' => 'Bearer '.IntakeFixtures::scimToken($tenant)]);
    }],
]);
