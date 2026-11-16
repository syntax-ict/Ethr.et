<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\StagingFixtures as Staging;

/**
 * Lists in progress: a staged list can be found again, and one nobody will
 * finish can be discarded. Before this the browser held a list's id in the
 * page alone, so a reload or a staging request that died left a list on the
 * server that no screen named.
 */

/**
 * A list in a chosen state, with a chosen number of lines.
 *
 * @param  array<string, mixed>  $attributes
 */
function inProgressBatch(Tenant $tenant, string $status, int $lines = 1, array $attributes = []): MigrationBatch
{
    $batch = (new MigrationBatch)->forceFill([
        'tenant_id' => $tenant->id,
        'source_type' => 'csv',
        'status' => $status,
        ...$attributes,
    ]);
    $batch->save();

    for ($i = 1; $i <= $lines; $i++) {
        MigrationStagingRow::create([
            'tenant_id' => $tenant->id,
            'batch_id' => $batch->id,
            'raw' => ['name' => "Person {$i}"],
            'display_name' => "Person {$i}",
        ]);
    }

    return $batch->refresh();
}

describe('the index', function () {
    it('lists the unfinished lists of this organisation, newest first, each with what it holds', function () {
        [$tenant, $user] = Staging::organisation();
        $user->forceFill(['employee_id' => null, 'display_name' => 'Hanna Girma'])->save();
        $other = User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => null, 'display_name' => 'Selam Bekele']);

        $oldest = inProgressBatch($tenant, 'reviewing', 2, ['created_by' => $user->id, 'created_at' => Carbon::parse('2026-11-01 08:00:00')]);
        $middle = inProgressBatch($tenant, 'staging', 3, ['created_by' => $other->id, 'created_at' => Carbon::parse('2026-11-02 08:00:00')]);
        $newest = inProgressBatch($tenant, 'committing', 1, ['created_by' => null, 'options' => ['mode' => 'complete'], 'created_at' => Carbon::parse('2026-11-03 08:00:00')]);
        inProgressBatch($tenant, 'committed', 4);

        $foreign = createTenant();
        inProgressBatch($foreign, 'reviewing', 5);

        $data = test()->getJson(Staging::url($tenant, 'batches'))->assertOk()->json('data');

        expect(array_column($data, 'public_id'))->toBe([$newest->public_id, $middle->public_id, $oldest->public_id])
            ->and(array_column($data, 'status'))->toBe(['committing', 'staging', 'reviewing'])
            ->and(array_column($data, 'rows'))->toBe([1, 3, 2])
            ->and(array_column($data, 'mode'))->toBe(['complete', 'add', 'add'])
            ->and(array_column($data, 'mine'))->toBe([false, false, true])
            ->and(array_column($data, 'created_by_name'))->toBe([null, 'Selam Bekele', 'Hanna Girma'])
            ->and($data[0])->toHaveKeys(['public_id', 'status', 'mode', 'source_type', 'rows', 'created_at', 'updated_at', 'created_by_name', 'mine'])
            ->and($data[0])->not->toHaveKeys(['id', 'tenant_id', 'created_by'])
            ->and(json_encode($data))->not->toContain('Person 1');
    });

    it('lists at most twenty, the newest', function () {
        [$tenant] = Staging::organisation();

        foreach (range(1, 23) as $n) {
            inProgressBatch($tenant, 'reviewing', 0, ['created_at' => Carbon::parse('2026-11-01 08:00:00')->addMinutes($n)]);
        }

        $data = test()->getJson(Staging::url($tenant, 'batches'))->assertOk()->json('data');

        expect($data)->toHaveCount(20)
            ->and(MigrationBatch::query()->orderByDesc('created_at')->first()->public_id)->toBe($data[0]['public_id']);
    });

    it('is empty for an organisation with nothing in progress', function () {
        [$tenant] = Staging::organisation();

        test()->getJson(Staging::url($tenant, 'batches'))->assertOk()->assertExactJson(['data' => []]);
    });

    it('needs the permission to view employees', function () {
        $tenant = createTenant();
        inProgressBatch($tenant, 'reviewing');
        test()->actingAs(createScopedUser($tenant, null, 'all', ['leave.viewOwn']));

        test()->getJson(Staging::url($tenant, 'batches'))->assertForbidden();
    });

    it('asks the database the same number of questions however many lists there are', function () {
        [$tenant, $user] = Staging::organisation();
        inProgressBatch($tenant, 'reviewing', 2, ['created_by' => $user->id]);

        $measure = function () use ($tenant): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->getJson(Staging::url($tenant, 'batches'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // The first request fills what is read once per process (permissions).
        $measure();
        $one = $measure();

        foreach (range(1, 5) as $n) {
            $creator = User::factory()->create(['tenant_id' => $tenant->id]);
            inProgressBatch($tenant, 'staging', 3, ['created_by' => $creator->id]);
        }

        expect(test()->getJson(Staging::url($tenant, 'batches'))->json('data'))->toHaveCount(6)
            ->and($measure())->toBe($one);
    });
});

describe('discarding', function () {
    it('removes a list still being read, and its lines', function () {
        [$tenant, $user] = Staging::organisation();
        $batch = inProgressBatch($tenant, 'staging', 3, ['created_by' => $user->id]);
        $id = $batch->id;

        test()->deleteJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertNoContent();

        expect(MigrationBatch::query()->whereKey($id)->exists())->toBeFalse()
            ->and(MigrationStagingRow::query()->where('batch_id', $id)->count())->toBe(0);

        $entry = AuditLog::query()->where('action', 'migration.discarded')->sole();
        expect($entry->tenant_id)->toBe($tenant->id)
            ->and($entry->user_id)->toBe($user->id)
            ->and($entry->payload)->toMatchArray([
                'batch' => $batch->public_id,
                'source' => 'csv',
                'mode' => 'add',
                'status' => 'staging',
                'rows' => 3,
            ]);
    });

    it('removes a list waiting for review, whatever its mode', function () {
        [$tenant] = Staging::organisation();
        $batch = inProgressBatch($tenant, 'reviewing', 2, ['options' => ['mode' => 'complete']]);

        test()->deleteJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertNoContent();

        expect(MigrationBatch::query()->count())->toBe(0)
            ->and(MigrationStagingRow::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'migration.discarded')->sole()->payload)->toMatchArray(['mode' => 'complete', 'status' => 'reviewing', 'rows' => 2]);
    });

    it('does not remove a list that is being added, or one that was added', function (string $status, string $message) {
        [$tenant] = Staging::organisation();
        $batch = inProgressBatch($tenant, $status, 2);

        test()->deleteJson(Staging::url($tenant, "batches/{$batch->public_id}"))
            ->assertStatus(409)
            ->assertJsonPath('detail', $message);

        expect(MigrationBatch::query()->whereKey($batch->id)->exists())->toBeTrue()
            ->and(MigrationStagingRow::query()->where('batch_id', $batch->id)->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'migration.discarded')->count())->toBe(0);
    })->with([
        'committing' => ['committing', 'This list is being added, so it cannot be discarded.'],
        'committed' => ['committed', 'This list has been added. There is nothing to discard.'],
    ]);

    it('needs the permission to add employees', function () {
        $tenant = createTenant();
        $batch = inProgressBatch($tenant, 'reviewing');
        test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.viewAny']));

        test()->deleteJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertForbidden();

        expect(MigrationBatch::query()->whereKey($batch->id)->exists())->toBeTrue();
    });

    it('does not reach another organisation\'s list', function () {
        [$tenant] = Staging::organisation();
        $foreign = createTenant();
        $theirs = inProgressBatch($foreign, 'reviewing', 2);

        test()->deleteJson(Staging::url($tenant, "batches/{$theirs->public_id}"))->assertNotFound();

        expect(MigrationBatch::withoutGlobalScopes()->whereKey($theirs->id)->exists())->toBeTrue()
            ->and(MigrationStagingRow::withoutGlobalScopes()->where('batch_id', $theirs->id)->count())->toBe(2);
    });

    it('does not remove a list whose commit began after the request found it', function () {
        [$tenant] = Staging::organisation();
        $batch = inProgressBatch($tenant, 'reviewing', 2);

        // The request finds the list `reviewing` when it binds the route; a
        // commit's first slice then runs. SQLite compiles the row lock to
        // nothing, so this proves the status is read again inside the
        // transaction, not that the lock holds: that is a MariaDB property.
        $flipped = false;
        $event = 'eloquent.retrieved: '.MigrationBatch::class;
        Event::listen($event, function () use (&$flipped, $batch): void {
            if (! $flipped) {
                $flipped = true;
                DB::table('migration_batches')->where('id', $batch->id)->update(['status' => 'committing']);
            }
        });

        try {
            test()->deleteJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertStatus(409);
        } finally {
            Event::forget($event);
        }

        expect($flipped)->toBeTrue()
            ->and(MigrationBatch::query()->whereKey($batch->id)->exists())->toBeTrue()
            ->and(MigrationStagingRow::query()->where('batch_id', $batch->id)->count())->toBe(2);
    });
});
