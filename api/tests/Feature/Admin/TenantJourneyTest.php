<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\Feature;
use App\Enums\FeatureState;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\TenantJourneySnapshot;
use App\Models\User;
use App\Services\Admin\TenantJourney\JourneyCalculator;
use App\Services\CurrentTenant;
use App\Services\Onboarding\ReadinessScorer;
use Illuminate\Support\Facades\Artisan;

/**
 * Where an organisation is between sign-up and using ETHR
 * (docs/operations/TENANT-JOURNEY.md). Each stage boundary is driven from
 * both sides, and the calculator runs with no tenant resolved, as it does on
 * the platform host, because a query that quietly fails closed there would
 * read every organisation as empty.
 */
function journeyOf(Tenant $tenant): array
{
    return app(JourneyCalculator::class)->compute($tenant->fresh());
}

/** Runs `$fn` the way the console does: no organisation resolved. */
function journeyOnPlatformHost(Closure $fn): mixed
{
    app(CurrentTenant::class)->forget();

    return $fn();
}

function journeyTenant(array $attributes = []): Tenant
{
    return createTenant($attributes + ['status' => TenantStatus::ACTIVE, 'owner_user_id' => null]);
}

function journeyFounder(Tenant $tenant): User
{
    $founder = createUser(['role' => UserRole::TENANT_ADMIN, 'status' => 'active'], $tenant);
    $tenant->forceFill(['owner_user_id' => $founder->id])->save();

    return $founder;
}

function journeyTeammate(Tenant $tenant, array $attributes = []): User
{
    return createUser(['role' => UserRole::EMPLOYEE, 'status' => 'active', ...$attributes], $tenant);
}

function journeyConfigure(Tenant $tenant): void
{
    $tenant->forceFill(['settings' => ['configuration_applied_at' => now()->subDays(3)->toIso8601String()]])->save();
}

function journeyPunch(Tenant $tenant, Employee $employee, array $attributes = []): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        ...$attributes,
    ]);
}

function journeyStaff(Tenant $tenant, int $count = 1): Employee
{
    return Employee::factory()->count($count)->create(['tenant_id' => $tenant->id])->first();
}

describe('stages', function () {
    it('starts every organisation at registered', function () {
        $tenant = journeyTenant();

        $result = journeyOnPlatformHost(fn () => journeyOf($tenant));

        expect($result['stage'])->toBe('registered')
            ->and($result['milestones'])->toHaveKey('registered');
    });

    it('moves to configured once the setup was applied', function () {
        $tenant = journeyTenant();
        journeyConfigure($tenant);

        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('configured');
    });

    it('moves to people_added with one current employee, and not for a leaver', function () {
        $tenant = journeyTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::TERMINATED]);

        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('registered');

        journeyStaff($tenant);

        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('people_added');
    });

    it('does not count the founder as an activated team member', function () {
        $tenant = journeyTenant();
        $founder = journeyFounder($tenant);
        $founder->forceFill(['activated_at' => now(), 'last_login_at' => now()])->save();
        journeyStaff($tenant);

        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('people_added');

        journeyTeammate($tenant, ['activated_at' => now()->subDay()]);

        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('team_activated');
    });

    it('reaches first_use on a real punch, but not on a voided one or an import', function () {
        $tenant = journeyTenant();
        journeyFounder($tenant);
        $employee = journeyStaff($tenant);
        journeyTeammate($tenant, ['activated_at' => now()->subDay()]);

        journeyPunch($tenant, $employee, ['status' => 'voided']);
        journeyPunch($tenant, $employee, ['source' => 'csv', 'idempotency_key' => 'csv-1']);

        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('team_activated');

        journeyPunch($tenant, $employee, ['idempotency_key' => 'real-1']);

        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('first_use');
    });

    it('reaches first_use on a leave request as well', function () {
        $tenant = journeyTenant();
        journeyFounder($tenant);
        $employee = journeyStaff($tenant);
        journeyTeammate($tenant, ['activated_at' => now()->subDay()]);
        $type = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
        LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id]);

        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('first_use');
    });

    it('is successfully_using without payroll when the plan has none', function () {
        switchFeature(Feature::Payroll, FeatureState::Disabled);
        $tenant = journeyTenant();
        journeyConfigure($tenant);
        journeyFounder($tenant);
        $employee = journeyStaff($tenant);
        journeyTeammate($tenant, ['activated_at' => now()->subDay()]);
        journeyPunch($tenant, $employee);

        $result = journeyOnPlatformHost(fn () => journeyOf($tenant));

        expect($result['metrics']['payroll_enabled'])->toBeFalse()
            ->and($result['stage'])->toBe('successfully_using')
            ->and($result['health_state'])->toBe('ok');
    });

    it('needs a completed payroll run when the plan includes payroll', function () {
        switchFeature(Feature::Payroll, FeatureState::Enabled);
        $tenant = journeyTenant();
        journeyConfigure($tenant);
        journeyFounder($tenant);
        $employee = journeyStaff($tenant);
        journeyTeammate($tenant, ['activated_at' => now()->subDay()]);
        journeyPunch($tenant, $employee);

        $without = journeyOnPlatformHost(fn () => journeyOf($tenant));
        expect($without['metrics']['payroll_enabled'])->toBeTrue()
            ->and($without['stage'])->toBe('first_use');

        PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'draft']);
        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('first_use');

        PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'paid']);
        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['stage'])->toBe('successfully_using');
    });

    it('falls back from successfully_using when activity stops for 14 days', function () {
        switchFeature(Feature::Payroll, FeatureState::Disabled);
        $tenant = journeyTenant();
        journeyConfigure($tenant);
        journeyFounder($tenant);
        $employee = journeyStaff($tenant);
        journeyTeammate($tenant, ['activated_at' => now()->subDay()]);
        $record = journeyPunch($tenant, $employee);
        $record->forceFill(['created_at' => now()->subDays(20)])->save();

        $result = journeyOnPlatformHost(fn () => journeyOf($tenant));

        expect($result['stage'])->toBe('first_use')
            ->and($result['reasons'])->toContain('no_activity_14d_after_first_use');
    });
});

describe('health and lifecycle', function () {
    it('derives an expired trial without changing its status', function () {
        $tenant = journeyTenant(['status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDays(2)]);

        $result = journeyOnPlatformHost(fn () => journeyOf($tenant));

        expect($result['lifecycle'])->toBe('trial_expired')
            ->and($result['health_state'])->toBe('critical')
            ->and($result['reasons'])->toContain('trial_expired_not_converted')
            ->and($tenant->fresh()->status)->toBe(TenantStatus::TRIAL);
    });

    it('warns when a trial ends within a week before the organisation uses the product', function () {
        $tenant = journeyTenant(['status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->addDays(3)]);

        $result = journeyOnPlatformHost(fn () => journeyOf($tenant));

        expect($result['reasons'])->toContain('trial_ends_soon_not_ready')
            ->and($result['health_state'])->toBe('warning');
    });

    it('flags an organisation that has sat at one stage for two weeks', function () {
        $tenant = journeyTenant();
        $tenant->forceFill(['created_at' => now()->subDays(30)])->save();

        $reasons = journeyOnPlatformHost(fn () => journeyOf($tenant))['reasons'];

        expect($reasons)->toContain('stalled_at_stage', 'no_login_14d');
    });

    it('does not monitor a suspended organisation', function () {
        $tenant = journeyTenant(['status' => TenantStatus::SUSPENDED]);

        $result = journeyOnPlatformHost(fn () => journeyOf($tenant));

        expect($result['reasons'])->toBe(['not_operating'])
            ->and($result['health_state'])->toBe('unknown')
            ->and($result['lifecycle'])->toBe('suspended');
    });

    it('marks a past-due invoice critical and an overdue one a warning', function () {
        $tenant = journeyTenant();
        Invoice::factory()->create(['tenant_id' => $tenant->id, 'status' => 'overdue']);

        $overdue = journeyOnPlatformHost(fn () => journeyOf($tenant));
        expect($overdue['reasons'])->toContain('invoice_overdue')
            ->and($overdue['health_state'])->toBe('warning');

        Invoice::factory()->create(['tenant_id' => $tenant->id, 'status' => 'past_due']);

        expect(journeyOnPlatformHost(fn () => journeyOf($tenant))['health_state'])->toBe('critical');
    });
});

describe('platform host', function () {
    it('reads the same readiness from the platform host as from the tenant host', function () {
        switchFeature(Feature::Payroll, FeatureState::Enabled);
        $tenant = journeyTenant();
        journeyConfigure($tenant);
        journeyFounder($tenant);
        $employee = journeyStaff($tenant);
        EmployeeBankDetail::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'bank_name' => 'CBE',
            'account_number' => '1000123456789',
            'is_primary' => true,
        ]);

        $onTenantHost = app(ReadinessScorer::class)->score($tenant->id);
        $onPlatform = journeyOnPlatformHost(fn () => app(ReadinessScorer::class)->score($tenant->id));

        expect($onPlatform['overall_score'])->toBe($onTenantHost['overall_score']);
        expect(array_column($onPlatform['gaps'], 'id'))->toBe(array_column($onTenantHost['gaps'], 'id'));
    });

    it('keeps one organisation out of another organisation\'s snapshot', function () {
        switchFeature(Feature::Payroll, FeatureState::Disabled);
        $busy = journeyTenant();
        journeyConfigure($busy);
        journeyFounder($busy);
        $employee = journeyStaff($busy);
        journeyTeammate($busy, ['activated_at' => now()->subDay()]);
        journeyPunch($busy, $employee);

        $quiet = journeyTenant();

        $result = journeyOnPlatformHost(fn () => journeyOf($quiet));

        expect($result['stage'])->toBe('registered')
            ->and($result['metrics']['employees'])->toBe(0)
            ->and($result['metrics']['punches_30d'])->toBe(0)
            ->and($result['metrics']['team_users'])->toBe(0);
    });
});

describe('console endpoints', function () {
    function journeyOperator(): array
    {
        $home = createTenant();
        $operator = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home);
        test()->actingAs($operator);

        return [$home, "http://{$home->subdomain}.ethr.test/api/v1/admin"];
    }

    it('answers 403 to an organisation administrator', function () {
        $tenant = createTenant();
        test()->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/journey-summary")->assertForbidden();
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$tenant->public_id}/journey")->assertForbidden();
    });

    it('returns one organisation\'s journey and stores the snapshot', function () {
        [, $base] = journeyOperator();
        $target = Tenant::factory()->create(['status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->addDays(20)]);

        $response = test()->getJson("{$base}/tenants/{$target->public_id}/journey")->assertOk();

        $response->assertJsonPath('data.stage', 'registered')
            ->assertJsonPath('data.lifecycle', 'trial')
            ->assertJsonStructure(['data' => [
                'stage', 'stages', 'stage_reached_at', 'lifecycle', 'health_state', 'reasons', 'milestones',
                'metrics', 'readiness' => ['score', 'level', 'gaps'], 'last_activity_at', 'computed_at', 'events',
            ]]);
        expect(TenantJourneySnapshot::query()->where('tenant_id', $target->id)->count())->toBe(1);
    });

    it('lists stage and health on the organisations list and filters by them', function () {
        [, $base] = journeyOperator();
        $a = Tenant::factory()->create(['name' => 'Alpha Trading']);
        $b = Tenant::factory()->create(['name' => 'Beta Foods', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDay()]);
        Artisan::call('ethr:refresh-tenant-journeys', ['--all' => true]);

        $all = test()->getJson("{$base}/tenants")->assertOk()->json('data');
        $beta = collect($all)->firstWhere('public_id', $b->public_id);
        expect($beta['stage'])->toBe('registered')
            ->and($beta['health_state'])->toBe('critical')
            ->and($beta['trial_expired'])->toBeTrue();

        $critical = test()->getJson("{$base}/tenants?filter[health]=critical")->assertOk()->json('data');
        expect(array_column($critical, 'public_id'))->toBe([$b->public_id]);

        $expired = test()->getJson("{$base}/tenants?filter[lifecycle]=trial_expired")->assertOk()->json('data');
        expect(array_column($expired, 'public_id'))->toBe([$b->public_id]);

        $registered = test()->getJson("{$base}/tenants?filter[stage]=registered")->assertOk()->json('data');
        expect(array_column($registered, 'public_id'))->toContain($a->public_id, $b->public_id);

        test()->getJson("{$base}/tenants?filter[stage]=first_use")->assertOk()->assertJsonCount(0, 'data');
    });

    it('summarises the funnel, counting organisations not yet computed as unknown', function () {
        [$home, $base] = journeyOperator();
        Tenant::factory()->create();
        Tenant::factory()->create(['status' => TenantStatus::SUSPENDED]);
        app(JourneyCalculator::class)->refresh(Tenant::withoutGlobalScopes()->where('id', '!=', $home->id)->first());

        $data = test()->getJson("{$base}/tenants/journey-summary")->assertOk()->json('data');

        expect($data['total'])->toBe(3)
            ->and($data['not_computed'])->toBe(2)
            ->and($data['stages'][0])->toBe(['stage' => 'registered', 'count' => 1])
            ->and(array_column($data['stages'], 'stage'))->toBe(JourneyCalculator::STAGES);
        expect($data['health']['unknown'])->toBeGreaterThanOrEqual(2);
    });

    it('shows lifecycle events and whether the platform made them, without naming anyone', function () {
        [$home, $base] = journeyOperator();
        $target = Tenant::factory()->create();
        app(CurrentTenant::class)->forget();
        AuditLog::record('tenant.registered', $target);
        AuditLog::record('admin.tenant.trial_extended', $target);
        AuditLog::record('employee.created', $target);

        $events = test()->getJson("{$base}/tenants/{$target->public_id}/journey")->assertOk()->json('data.events');

        $actions = array_column($events, 'action');
        expect($actions)->toContain('tenant.registered', 'admin.tenant.trial_extended')
            ->and($actions)->not->toContain('employee.created');
        expect(array_keys($events[0]))->toBe(['action', 'created_at', 'by_platform']);
    });
});

describe('refresh command', function () {
    it('is idempotent and limits itself to one organisation with --tenant', function () {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        Artisan::call('ethr:refresh-tenant-journeys', ['--tenant' => $a->public_id]);
        expect(TenantJourneySnapshot::query()->count())->toBe(1);

        Artisan::call('ethr:refresh-tenant-journeys');
        Artisan::call('ethr:refresh-tenant-journeys');

        expect(TenantJourneySnapshot::query()->count())->toBe(Tenant::withoutGlobalScopes()->count());
        expect(TenantJourneySnapshot::query()->where('tenant_id', $b->id)->count())->toBe(1);
    });

    it('fails for an unknown organisation', function () {
        expect(Artisan::call('ethr:refresh-tenant-journeys', ['--tenant' => 'nope']))->toBe(1);
    });
});
