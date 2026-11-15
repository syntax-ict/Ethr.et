<?php

declare(strict_types=1);

use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Services\Migration\WorkforceMigrationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\StagingFixtures as Staging;

/**
 * One request has thirty seconds on the host and matching a person costs
 * about 65 ms there, so a list of a few hundred people could not be staged
 * in one request. Its people are now stored at once and matched a slice at a
 * time, and the caller asks again, as it does to commit.
 *
 * These tests make a slice two rows long and give it no time, so every
 * request matches exactly one chunk.
 */
beforeEach(function () {
    app()->when(WorkforceMigrationService::class)->needs('$chunk')->give(2);
    app()->when(WorkforceMigrationService::class)->needs('$sliceSeconds')->give(0.0);
});

/** @return list<array<string, string>> */
function peopleToStage(): array
{
    return [
        ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
        ['name' => 'Sara Tesfaye'],
        ['name' => 'Dawit Haile'],
        ['name' => 'Hana Girma'],
        ['name' => 'Yonas Bekele'],
    ];
}

it('stores a list at once and matches it a slice at a time', function () {
    [$tenant] = Staging::organisation();
    Staging::employee($tenant, ['phone' => '+251911000001']);

    $first = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => peopleToStage()])->assertCreated()
        ->assertJsonPath('status', 'staging')
        ->assertJsonPath('staged', 2)
        ->assertJsonPath('remaining', 3)
        ->assertJsonMissingPath('rows')
        ->json();

    $batch = MigrationBatch::query()->where('public_id', $first['public_id'])->firstOrFail();
    expect($batch->rows()->count())->toBe(5)
        ->and($batch->rows()->whereNull('match_outcome')->count())->toBe(3);

    $stage = Staging::url($tenant, "batches/{$first['public_id']}/stage");

    test()->postJson($stage)->assertOk()
        ->assertJsonPath('status', 'staging')
        ->assertJsonPath('staged', 4)
        ->assertJsonPath('remaining', 1);

    test()->postJson($stage)->assertOk()
        ->assertJsonPath('status', 'reviewing')
        ->assertJsonPath('staged', 5)
        ->assertJsonPath('remaining', 0)
        ->assertJsonMissingPath('rows');

    test()->getJson(Staging::url($tenant, "batches/{$first['public_id']}"))->assertOk()
        ->assertJsonPath('status', 'reviewing')
        ->assertJsonCount(5, 'rows');
});

it('gives the same verdicts as staging the list in one go', function () {
    [$tenant] = Staging::organisation();
    Staging::employee($tenant, ['phone' => '+251911000001']);

    $verdicts = fn (MigrationBatch $batch): array => $batch->rows()->orderBy('id')->get()
        ->map(fn (MigrationStagingRow $row): array => [
            $row->display_name, $row->match_outcome, $row->action, $row->resolved_employee_id, $row->changes,
        ])->all();

    $whole = Staging::stage($tenant, peopleToStage());

    $sliced = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => peopleToStage()])->assertCreated()->json();
    while ($sliced['status'] === 'staging') {
        $sliced = test()->postJson(Staging::url($tenant, "batches/{$sliced['public_id']}/stage"))->assertOk()->json();
    }
    $batch = MigrationBatch::query()->where('public_id', $sliced['public_id'])->firstOrFail();

    // Abebe Kebede is the employee already here: a merge, with its fills.
    expect($verdicts($whole)[0][1])->toBe('matched')
        ->and($verdicts($whole)[0][2])->toBe('merge')
        ->and($verdicts($batch))->toBe($verdicts($whole))
        ->and($batch->status)->toBe('reviewing');
});

it('answers a short list whole, matched, as it always did', function () {
    app()->when(WorkforceMigrationService::class)->needs('$sliceSeconds')->give(WorkforceMigrationService::SLICE_SECONDS);
    app()->when(WorkforceMigrationService::class)->needs('$chunk')->give(WorkforceMigrationService::CHUNK);

    [$tenant] = Staging::organisation();

    test()->postJson(Staging::url($tenant, 'rows'), ['rows' => peopleToStage()])->assertCreated()
        ->assertJsonPath('status', 'reviewing')
        ->assertJsonCount(5, 'rows')
        ->assertJsonPath('rows.1.match_outcome', 'new')
        ->assertJsonPath('rows.1.action', 'create');
});

it('refuses to add a list that is still being matched', function () {
    [$tenant] = Staging::organisation();
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => peopleToStage()])->assertCreated()->json();

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
        ->assertStatus(409)
        ->assertJsonPath('detail', __('onboarding.migration.still_staging'));

    expect(MigrationBatch::query()->where('public_id', $staged['public_id'])->value('status'))->toBe('staging');
});

it('refuses a decision on a row of a list that is still being matched', function () {
    [$tenant] = Staging::organisation();
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => peopleToStage()])->assertCreated()->json();
    $row = MigrationBatch::query()->where('public_id', $staged['public_id'])->firstOrFail()->rows()->orderByDesc('id')->firstOrFail();

    test()->patchJson(Staging::url($tenant, "rows/{$row->public_id}"), ['action' => 'skip'])
        ->assertStatus(409)
        ->assertJsonPath('detail', __('onboarding.migration.still_staging'));

    expect($row->refresh()->action)->toBe('pending');
});

it('changes nothing when a list that is ready is asked to carry on', function () {
    [$tenant] = Staging::organisation();
    $batch = Staging::stage($tenant, peopleToStage());
    $before = $batch->rows()->orderBy('id')->pluck('updated_at', 'public_id')->all();

    test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/stage"))->assertOk()
        ->assertJsonPath('status', 'reviewing')
        ->assertJsonPath('staged', 5)
        ->assertJsonPath('remaining', 0);

    expect($batch->rows()->orderBy('id')->pluck('updated_at', 'public_id')->all())->toEqual($before)
        ->and($batch->refresh()->status)->toBe('reviewing');
});

it('stores a bulk-inserted row encrypted and reads it back through the model', function () {
    [$tenant] = Staging::organisation();
    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => [
        ['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000002'],
    ]])->assertCreated()->json();

    $batch = MigrationBatch::query()->where('public_id', $staged['public_id'])->firstOrFail();
    $stored = DB::table('migration_staging_rows')->where('batch_id', $batch->id)->first();

    expect($stored->raw)->not->toContain('Abebe')->not->toContain('922000002')
        ->not->toStartWith('{')->not->toStartWith('[')
        ->and($stored->public_id)->toHaveLength(26)
        ->and((int) $stored->tenant_id)->toBe($tenant->id)
        ->and(MigrationStagingRow::query()->where('batch_id', $batch->id)->firstOrFail()->raw)
        ->toMatchArray(['name' => 'Abebe Kebede', 'employee_code' => 'E-1']);
});

it('cannot carry on another organisation list', function () {
    [$mine] = Staging::organisation();
    $theirs = Staging::stage(createTenant(), peopleToStage());

    test()->postJson(Staging::url($mine, "batches/{$theirs->public_id}/stage"))->assertNotFound();
});
