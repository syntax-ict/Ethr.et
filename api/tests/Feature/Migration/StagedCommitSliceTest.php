<?php

declare(strict_types=1);

use App\Enums\OnboardingStep;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\OnboardingProgress;
use App\Services\Migration\WorkforceMigrationService;
use Illuminate\Support\Facades\Route;
use Tests\Support\StagingFixtures as Staging;

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

it('adds a list a slice at a time and says how much is left', function () {
    [$tenant] = Staging::organisation();
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => fivePeople()])->assertCreated()->json();
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
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => fivePeople()])->assertCreated()->json();
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
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => fivePeople()])->assertCreated()->json();

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
