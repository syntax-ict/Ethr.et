<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\OnboardingProgress;
use App\Models\Position;
use App\Models\Shift;
use App\Services\Onboarding\ReadinessScorer;

function scorer(): ReadinessScorer
{
    return app(ReadinessScorer::class);
}

describe('ReadinessScorer', function () {
    it('reports a low, not-ready score for a bare tenant', function () {
        $tenant = createTenant();

        $report = scorer()->score($tenant->id);

        expect($report['level'])->toBe('not_ready');
        expect($report['overall_score'])->toBeLessThan(60);
        expect($report['gaps'])->not->toBeEmpty();
    });

    it('lists failing checks as gaps with a remediation deep link', function () {
        $tenant = createTenant();

        $report = scorer()->score($tenant->id);

        $ids = array_column($report['gaps'], 'id');
        expect($ids)->toContain('has_employees');

        $employeesGap = collect($report['gaps'])->firstWhere('id', 'has_employees');
        expect($employeesGap['remediation'])->toBe('/employees');
    });

    it('rises toward ready as configuration and data are filled in', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
        Position::factory()->create(['tenant_id' => $tenant->id]);
        Shift::factory()->create(['tenant_id' => $tenant->id]);
        LeaveType::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);

        $report = scorer()->score($tenant->id);

        expect($report['overall_score'])->toBeGreaterThan(60);
        expect($report['categories']['configuration']['score'])->toBeGreaterThan(0);
        expect($report['categories']['data']['score'])->toBeGreaterThan(0);
    });

    it('scores each category independently', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $report = scorer()->score($tenant->id);

        // An admin exists (the acting user), so security scores above zero even
        // when configuration and data are empty.
        expect($report['categories']['security']['score'])->toBeGreaterThan(0);
        expect($report['categories']['configuration']['score'])->toBe(0);
    });
});

describe('readiness endpoints', function () {
    it('returns the readiness report', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/readiness")
            ->assertOk()
            ->assertJsonStructure(['overall_score', 'level', 'categories', 'gaps']);
    });

    it('goes live and completes onboarding', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/go-live")
            ->assertOk()
            ->assertJsonPath('redirect', '/dashboard')
            ->assertJsonStructure(['readiness' => ['overall_score', 'level']]);

        $progress = OnboardingProgress::where('tenant_id', $tenant->id)->first();
        expect($progress->completed_at)->not->toBeNull();
        expect($progress->completed_steps)->toContain(7);
    });

    it('denies readiness to employee-role users', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/readiness")
            ->assertForbidden();
    });
});
