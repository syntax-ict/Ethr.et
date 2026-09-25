<?php

declare(strict_types=1);

use App\Jobs\CleanupExpiredDataJob;
use App\Models\Webhook;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The retention rows in `docs/CLAUDE.md`'s soft-delete policy that nothing
 * asserted.
 *
 * That table has fifteen rows. `SoftDeletePolicyTest` reflects over every one
 * that has an Eloquent model and pins whether it carries `SoftDeletes` — but it
 * records, in its own file, that **Notifications is unpinnable that way**:
 * `app/Models/` has `NotificationPreference` and nothing mapping the
 * `notifications` table, which Laravel serves through `DatabaseNotification`.
 * The note there says the row "needs the 90-day sweep tested, not a trait
 * check". This is that test.
 *
 * `CleanupExpiredDataJob` implements three of those rows and only one was
 * covered: `MigrationWorkspaceTest` pins import staging at 7 days, and
 * `JobFailureHandlingTest` pins `failed()`. Notifications (90 days) and webhook
 * deliveries (30 days) were both unasserted — a sweep that quietly stopped
 * deleting, or started deleting too much, would have broken nothing visible.
 *
 * **The boundary is the point, not the happy path.** The job uses
 * `where('created_at', '<', now()->subDays(N))`, a strict comparison, so a row
 * exactly N days old survives. An off-by-one here is a data-loss bug in one
 * direction and an unbounded table in the other, and neither announces itself.
 *
 * **The clock is frozen, and it has to be.** The job calls `now()` microseconds
 * AFTER the test does, so on a live clock the job's threshold is fractionally
 * later than the one the boundary row was written against — making that row
 * strictly older, and deleting it. The test would fail for a reason that is not
 * the code's fault, intermittently, which is worse than not testing it.
 * `Illuminate\Foundation\Testing\TestCase::tearDown()` resets `setTestNow`.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00'));
});
function insertNotification(string $id, CarbonInterface $createdAt): void
{
    DB::table('notifications')->insert([
        'id' => $id,
        'tenant_id' => null,
        'type' => 'App\\Notifications\\SystemAlertNotification',
        'notifiable_type' => 'App\\Models\\User',
        'notifiable_id' => 1,
        'data' => json_encode(['title' => 'x', 'message' => 'y']),
        'read_at' => null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function insertDelivery(int $webhookId, CarbonInterface $createdAt): int
{
    return (int) DB::table('webhook_deliveries')->insertGetId([
        'webhook_id' => $webhookId,
        'event' => 'employee.created',
        'payload' => json_encode(['a' => 1]),
        'response_status' => 200,
        'attempt' => 1,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

test('prunes notifications older than 90 days and keeps the boundary row', function () {
    $stale = (string) Str::uuid();
    $boundary = (string) Str::uuid();
    $fresh = (string) Str::uuid();

    insertNotification($stale, now()->subDays(91));
    // Exactly 90 days: the comparison is `<`, so this one SURVIVES. A test that
    // only checked 91 and 1 would pass just as happily against `<=`, which
    // would delete a day early — silently, and only ever noticed by whoever
    // needed the record.
    insertNotification($boundary, now()->subDays(90));
    insertNotification($fresh, now()->subDay());

    (new CleanupExpiredDataJob)->handle();

    expect(DB::table('notifications')->where('id', $stale)->exists())->toBeFalse();
    expect(DB::table('notifications')->where('id', $boundary)->exists())->toBeTrue();
    expect(DB::table('notifications')->where('id', $fresh)->exists())->toBeTrue();
});

test('prunes webhook deliveries older than 30 days and keeps the boundary row', function () {
    $tenant = createTenant();
    $webhook = Webhook::factory()->create(['tenant_id' => $tenant->id]);

    $stale = insertDelivery($webhook->id, now()->subDays(31));
    $boundary = insertDelivery($webhook->id, now()->subDays(30));
    $fresh = insertDelivery($webhook->id, now()->subDay());

    (new CleanupExpiredDataJob)->handle();

    expect(DB::table('webhook_deliveries')->where('id', $stale)->exists())->toBeFalse();
    expect(DB::table('webhook_deliveries')->where('id', $boundary)->exists())->toBeTrue();
    expect(DB::table('webhook_deliveries')->where('id', $fresh)->exists())->toBeTrue();
});

test('the sweep is cross-tenant by design, and that is what retention requires', function () {
    // These are raw `DB::table()` deletes, so no global scope applies and none
    // should: retention is a platform obligation, not a tenant preference, and
    // a sweep that only pruned the tenant whose context happened to be resolved
    // would leave every other tenant's rows forever. The queue worker carries
    // no tenant context anyway.
    //
    // Pinned because "unscoped raw SQL" is otherwise exactly the shape root
    // CLAUDE.md warns about, and a later reader is right to be suspicious of
    // it. The answer is that it is deliberate here.
    $tenantA = createTenant();
    $tenantB = createTenant();
    $webhookA = Webhook::factory()->create(['tenant_id' => $tenantA->id]);
    $webhookB = Webhook::factory()->create(['tenant_id' => $tenantB->id]);

    $staleA = insertDelivery($webhookA->id, now()->subDays(31));
    $staleB = insertDelivery($webhookB->id, now()->subDays(31));

    (new CleanupExpiredDataJob)->handle();

    expect(DB::table('webhook_deliveries')->whereIn('id', [$staleA, $staleB])->count())->toBe(0);
});
