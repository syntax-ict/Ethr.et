<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Jobs\RunDashboardDigestsJob;
use App\Models\Branch;
use App\Models\DashboardDigest;
use App\Models\Employee;
use App\Notifications\DashboardDigestFailedNotification;
use App\Notifications\DashboardDigestNotification;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\ExecutiveDashboardService;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 6.6 — dashboard scheduling / digest delivery, deliberately its own
 * feature (not reusing scheduled reports — see RunDashboardDigestsJob's
 * docblock). Mirrors ScheduledReportDeliveryTest's structure: schedule over
 * the API, then verify the job actually delivers real numbers, not a
 * "your digest ran" placeholder.
 */
describe('scheduling permissions and scoping', function () {
    it('lets an executive-view holder schedule a tenant-wide digest', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/dashboard/digests', [
            'frequency' => 'weekly',
            'recipients' => ['ceo@example.com'],
        ])->assertCreated();

        expect($response->json('frequency'))->toBe('weekly');
        expect($response->json('recipients'))->toBe(['ceo@example.com']);
        expect(DashboardDigest::where('tenant_id', $tenant->id)->first()->branch_id)->toBeNull();
    });

    it('forces a regional-view holder onto their own branch, ignoring any submitted branch', function () {
        $tenant = createTenant();
        $ownBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $ownBranch->id]);
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $employee->id], $tenant);

        $this->postJson('/api/v1/dashboard/digests', [
            'frequency' => 'daily',
            'recipients' => ['manager@example.com'],
            'branch_public_id' => $otherBranch->public_id,
        ])->assertCreated();

        $digest = DashboardDigest::where('tenant_id', $tenant->id)->first();
        expect($digest->branch_id)->toBe($ownBranch->id);
    });

    it('denies a plain employee', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->postJson('/api/v1/dashboard/digests', [
            'frequency' => 'weekly',
            'recipients' => ['x@example.com'],
        ])->assertForbidden();
    });

    it('lists active digests and excludes cancelled ones', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $created = $this->postJson('/api/v1/dashboard/digests', [
            'frequency' => 'monthly',
            'recipients' => ['finance@example.com'],
        ])->assertCreated()->json('public_id');

        $this->getJson('/api/v1/dashboard/digests')
            ->assertOk()
            ->assertJsonCount(1, 'digests');

        $this->deleteJson("/api/v1/dashboard/digests/{$created}")->assertNoContent();

        $this->getJson('/api/v1/dashboard/digests')
            ->assertOk()
            ->assertJsonCount(0, 'digests');
    });
});

describe('delivery', function () {
    it('delivers real KPI numbers, not a placeholder', function () {
        Notification::fake();

        $tenant = createTenant();
        Employee::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);

        $digest = DashboardDigest::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => null,
            'frequency' => 'weekly',
            'recipients' => ['ops@example.com'],
            'next_run_at' => now()->subMinute(),
        ]);

        (new RunDashboardDigestsJob)->handle(app(ExecutiveDashboardService::class), app(AlertEvaluator::class), app(CurrentTenant::class));

        Notification::assertSentOnDemand(
            DashboardDigestNotification::class,
            function (DashboardDigestNotification $notification, array $channels, $notifiable) {
                if ($notifiable->routes['mail'] !== 'ops@example.com') {
                    return false;
                }

                $mail = $notification->toMail($notifiable);
                $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);
                expect($body)->toContain('3 active');
                expect($body)->toContain('3 total');

                return true;
            },
        );

        $digest->refresh();
        expect($digest->last_run_at)->not->toBeNull();
        expect($digest->next_run_at->isFuture())->toBeTrue();
    });

    it('scopes the digest to its branch when one is set', function () {
        Notification::fake();

        $tenant = createTenant();
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Branch A']);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);

        Employee::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        Employee::factory()->count(9)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchB->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);

        DashboardDigest::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'recipients' => ['regional@example.com'],
            'next_run_at' => now()->subMinute(),
        ]);

        (new RunDashboardDigestsJob)->handle(app(ExecutiveDashboardService::class), app(AlertEvaluator::class), app(CurrentTenant::class));

        Notification::assertSentOnDemand(
            DashboardDigestNotification::class,
            function (DashboardDigestNotification $notification, array $channels, $notifiable) {
                $mail = $notification->toMail($notifiable);
                $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);
                expect($body)->toContain('Branch A');
                expect($body)->toContain('2 active');

                return true;
            },
        );
    });

    it('skips a malformed recipient address without failing the whole digest', function () {
        Notification::fake();

        $tenant = createTenant();
        DashboardDigest::factory()->create([
            'tenant_id' => $tenant->id,
            'recipients' => ['not-an-email', 'valid@example.com'],
            'next_run_at' => now()->subMinute(),
        ]);

        (new RunDashboardDigestsJob)->handle(app(ExecutiveDashboardService::class), app(AlertEvaluator::class), app(CurrentTenant::class));

        Notification::assertSentOnDemandTimes(DashboardDigestNotification::class, 1);
    });

    it('does not run a digest that is not yet due', function () {
        Notification::fake();

        $tenant = createTenant();
        DashboardDigest::factory()->create([
            'tenant_id' => $tenant->id,
            'recipients' => ['future@example.com'],
            'next_run_at' => now()->addWeek(),
        ]);

        (new RunDashboardDigestsJob)->handle(app(ExecutiveDashboardService::class), app(AlertEvaluator::class), app(CurrentTenant::class));

        Notification::assertNothingSent();
    });
});

/**
 * A failed digest used to be indistinguishable from a delivered one.
 *
 * `handle()` catches per-digest failures so one broken digest cannot stop every
 * other tenant's — correct, and it stays. But the catch then wrote
 * `last_run_at` and `next_run_at` with exactly the values the success path
 * writes, so a digest that delivered nothing read as delivered and the failure
 * lived only in a log line.
 *
 * The same defect `ScheduledReportDeliveryTest` covers for scheduled reports.
 * The two jobs were written to share a shape and not code, so the defect was
 * duplicated the day the second one was written — which is the argument for
 * these tests existing twice as well.
 */
function dueDigest(array $recipients = ['ceo@example.com']): DashboardDigest
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);

    return DashboardDigest::factory()->create([
        'tenant_id' => $tenant->id,
        'frequency' => 'weekly',
        'recipients' => $recipients,
        'next_run_at' => now()->subMinute(),
        'is_active' => true,
    ]);
}

/**
 * Injected at `CurrentTenant::set()`, which `run()` calls before it touches
 * either analytics service. What is under test is the CATCH's behaviour,
 * not a particular cause, so the cheapest injection point that reaches it
 * is the right one — and it leaves `ExecutiveDashboardService` and
 * `AlertEvaluator` `final`, which they are.
 */
function runDigestSweepFailing(): void
{
    $tenantResolver = mock(CurrentTenant::class);
    $tenantResolver->shouldReceive('set')->andThrow(new RuntimeException('dashboard service exploded'));

    (new RunDashboardDigestsJob)->handle(
        app(ExecutiveDashboardService::class),
        app(AlertEvaluator::class),
        $tenantResolver,
    );
}

describe('failure is visible', function () {
    it('records why on the digest instead of looking like a success', function () {
        Notification::fake();
        $digest = dueDigest();

        runDigestSweepFailing();

        $digest->refresh();
        expect($digest->last_error)->toContain('dashboard service exploded');

        // The clock still advances — that is why the catch exists — and
        // `last_error` is what makes advancing safe rather than silent.
        expect($digest->last_run_at)->not->toBeNull();
        expect($digest->next_run_at->isFuture())->toBeTrue();
    });

    it('tells the recipients the digest did not arrive', function () {
        Notification::fake();
        dueDigest();

        runDigestSweepFailing();

        Notification::assertSentOnDemand(
            DashboardDigestFailedNotification::class,
            function (DashboardDigestFailedNotification $notification, array $channels, $notifiable) {
                if ($notifiable->routes['mail'] !== 'ceo@example.com') {
                    return false;
                }

                // The reason is deliberately absent: `recipients` is a
                // free-text list rather than a set of authenticated users, and
                // an exception message can carry column names or a connection
                // string. The reason lives on `last_error`.
                $mail = $notification->toMail($notifiable);
                $rendered = $mail->subject.' '.implode(' ', $mail->introLines);
                expect($rendered)->not->toContain('dashboard service exploded');

                return true;
            },
        );
    });

    it('clears the previous failure on a later success', function () {
        Notification::fake();
        $digest = dueDigest();

        $digest->forceFill([
            'last_error' => 'dashboard service exploded',
            'next_run_at' => now()->subMinute(),
        ])->save();

        (new RunDashboardDigestsJob)->handle(
            app(ExecutiveDashboardService::class),
            app(AlertEvaluator::class),
            app(CurrentTenant::class),
        );

        // `last_error` describes the LAST run — the only question its name can
        // answer — so a digest that failed yesterday and succeeded today must
        // not still read as failing.
        expect($digest->refresh()->last_error)->toBeNull();
    });
});
