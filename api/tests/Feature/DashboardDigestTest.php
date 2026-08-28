<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Jobs\RunDashboardDigestsJob;
use App\Models\Branch;
use App\Models\DashboardDigest;
use App\Models\Employee;
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
