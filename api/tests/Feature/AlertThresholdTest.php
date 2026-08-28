<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Jobs\RunDashboardDigestsJob;
use App\Models\AlertThreshold;
use App\Models\Branch;
use App\Models\DashboardDigest;
use App\Models\Employee;
use App\Notifications\DashboardDigestNotification;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\ExecutiveDashboardService;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 6.7 — configurable alert thresholds over the metrics
 * ExecutiveDashboardService already computes. See AlertEvaluator's docblock
 * for why the metric catalog is fixed rather than free text.
 */
describe('AlertEvaluator', function () {
    it('triggers a gt rule when the current value exceeds the threshold', function () {
        $threshold = AlertThreshold::factory()->make([
            'metric' => 'turnover_rate',
            'operator' => 'gt',
            'threshold_value' => 5.0,
            'severity' => 'critical',
        ]);

        $triggered = (new AlertEvaluator)->evaluate(
            ['turnover' => ['rate' => 7.5]],
            [],
            collect([$threshold]),
        );

        expect($triggered)->toHaveCount(1);
        expect($triggered[0]['current_value'])->toBe(7.5);
        expect($triggered[0]['severity'])->toBe('critical');
    });

    it('does not trigger a gt rule when the current value is at or below the threshold', function () {
        $threshold = AlertThreshold::factory()->make([
            'metric' => 'turnover_rate',
            'operator' => 'gt',
            'threshold_value' => 5.0,
        ]);

        $triggered = (new AlertEvaluator)->evaluate(
            ['turnover' => ['rate' => 5.0]],
            [],
            collect([$threshold]),
        );

        expect($triggered)->toBeEmpty();
    });

    it('triggers an lt rule when the current value falls below the threshold', function () {
        $threshold = AlertThreshold::factory()->make([
            'metric' => 'attendance_rate_today',
            'operator' => 'lt',
            'threshold_value' => 90.0,
        ]);

        $triggered = (new AlertEvaluator)->evaluate(
            ['attendance_rate' => ['today' => 82.0]],
            [],
            collect([$threshold]),
        );

        expect($triggered)->toHaveCount(1);
    });

    it('reads compliance metrics from the merged compliance snapshot', function () {
        $threshold = AlertThreshold::factory()->make([
            'metric' => 'expiring_documents_count',
            'operator' => 'gt',
            'threshold_value' => 0.0,
        ]);

        $triggered = (new AlertEvaluator)->evaluate(
            [],
            ['expiring_documents' => ['count' => 3]],
            collect([$threshold]),
        );

        expect($triggered)->toHaveCount(1);
        expect($triggered[0]['current_value'])->toBe(3.0);
    });

    it('skips a threshold whose metric path is missing from the data', function () {
        $threshold = AlertThreshold::factory()->make([
            'metric' => 'turnover_rate',
            'operator' => 'gt',
            'threshold_value' => 0.0,
        ]);

        $triggered = (new AlertEvaluator)->evaluate([], [], collect([$threshold]));

        expect($triggered)->toBeEmpty();
    });
});

describe('configuration permissions', function () {
    it('lets an executive-view holder create a threshold', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/dashboard/alert-thresholds', [
            'metric' => 'turnover_rate',
            'operator' => 'gt',
            'threshold_value' => 5,
            'severity' => 'warning',
        ])->assertCreated()
            ->assertJsonPath('metric', 'turnover_rate');
    });

    it('denies a regional-only holder from configuring thresholds', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $employee->id], $tenant);

        $this->postJson('/api/v1/dashboard/alert-thresholds', [
            'metric' => 'turnover_rate',
            'operator' => 'gt',
            'threshold_value' => 5,
            'severity' => 'warning',
        ])->assertForbidden();
    });

    it('rejects an unknown metric', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/dashboard/alert-thresholds', [
            'metric' => 'made_up_metric',
            'operator' => 'gt',
            'threshold_value' => 5,
            'severity' => 'warning',
        ])->assertUnprocessable();
    });

    it('lists and cancels thresholds', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $id = $this->postJson('/api/v1/dashboard/alert-thresholds', [
            'metric' => 'turnover_rate',
            'operator' => 'gt',
            'threshold_value' => 5,
            'severity' => 'warning',
        ])->assertCreated()->json('public_id');

        $this->getJson('/api/v1/dashboard/alert-thresholds')->assertOk()->assertJsonCount(1, 'thresholds');

        $this->deleteJson("/api/v1/dashboard/alert-thresholds/{$id}")->assertNoContent();

        $this->getJson('/api/v1/dashboard/alert-thresholds')->assertOk()->assertJsonCount(0, 'thresholds');
    });
});

describe('triggered endpoint', function () {
    it('reports a currently-breached threshold for an executive-view holder', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        AlertThreshold::factory()->create([
            'tenant_id' => $tenant->id,
            'metric' => 'expiring_documents_count',
            'operator' => 'gt',
            'threshold_value' => -1, // any expiring_documents count (0+) breaches
        ]);

        $response = $this->getJson('/api/v1/dashboard/alert-thresholds/triggered')->assertOk();
        expect($response->json('alerts'))->toHaveCount(1);
        expect($response->json('alerts.0.metric'))->toBe('expiring_documents_count');
    });

    it('scopes the evaluation to a regional holder\'s own branch', function () {
        $tenant = createTenant();
        $ownBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $callerEmployee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $ownBranch->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        Employee::factory()->count(9)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $otherBranch->id,
            'status' => EmployeeStatus::RESIGNED,
        ]);

        AlertThreshold::factory()->create([
            'tenant_id' => $tenant->id,
            'metric' => 'turnover_rate',
            'operator' => 'gt',
            'threshold_value' => 1.0,
        ]);

        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $callerEmployee->id], $tenant);

        // The caller's own branch has no resignations, so the tenant-wide
        // turnover spike from $otherBranch must not leak into their scope.
        $response = $this->getJson('/api/v1/dashboard/alert-thresholds/triggered')->assertOk();
        expect($response->json('alerts'))->toBeEmpty();
    });
});

describe('digest integration', function () {
    it('includes a breached threshold in the digest email', function () {
        Notification::fake();

        $tenant = createTenant();
        Employee::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);

        AlertThreshold::factory()->create([
            'tenant_id' => $tenant->id,
            'metric' => 'attendance_rate_today',
            'operator' => 'lt',
            'threshold_value' => 999, // always breaches — attendance rate can't exceed 999%
        ]);

        DashboardDigest::factory()->create([
            'tenant_id' => $tenant->id,
            'recipients' => ['ops@example.com'],
            'next_run_at' => now()->subMinute(),
        ]);

        (new RunDashboardDigestsJob)->handle(
            app(ExecutiveDashboardService::class),
            app(AlertEvaluator::class),
            app(CurrentTenant::class),
        );

        Notification::assertSentOnDemand(
            DashboardDigestNotification::class,
            function (DashboardDigestNotification $notification, array $channels, $notifiable) {
                $mail = $notification->toMail($notifiable);
                $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);
                expect($body)->toContain('Alert:');
                expect($body)->toContain("Today's attendance rate");

                return true;
            },
        );
    });
});
