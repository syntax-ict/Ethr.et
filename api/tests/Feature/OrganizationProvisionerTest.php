<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Grade;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Shift;
use App\Services\Onboarding\OrganizationProvisioner;

function provisioner(): OrganizationProvisioner
{
    return app(OrganizationProvisioner::class);
}

/** The shorthand shape every seeded template has used since Phase 1. */
function legacyPlan(): array
{
    return [
        'departments' => ['Administration', 'Human Resources'],
        'positions' => ['Director General', 'Expert'],
        'shifts' => [['name' => 'Regular', 'start' => '08:30', 'end' => '17:30', 'days' => '1,2,3,4,5']],
        'leave_types' => ['annual', 'maternity'],
        'holidays' => false,
    ];
}

describe('OrganizationProvisioner', function () {
    it('creates real records from the legacy template shape', function () {
        $tenant = createTenant();

        $result = provisioner()->apply($tenant, legacyPlan())->toArray();

        expect(Department::where('tenant_id', $tenant->id)->pluck('name')->all())
            ->toEqualCanonicalizing(['Administration', 'Human Resources']);
        expect(Position::where('tenant_id', $tenant->id)->pluck('title')->all())
            ->toEqualCanonicalizing(['Director General', 'Expert']);
        expect(Shift::where('tenant_id', $tenant->id)->count())->toBe(1);
        expect(LeaveType::where('tenant_id', $tenant->id)->pluck('code')->all())
            ->toEqualCanonicalizing(['annual', 'maternity']);

        expect($result['resources']['departments']['created'])->toBe(2);
        expect($result['resources']['positions']['created'])->toBe(2);
    });

    it('derives unique short codes for departments and positions', function () {
        $tenant = createTenant();

        provisioner()->apply($tenant, legacyPlan());

        $codes = Department::where('tenant_id', $tenant->id)->pluck('code');

        expect($codes)->not->toContain(null);
        expect($codes->unique())->toHaveCount($codes->count());
        expect($codes->every(fn (string $c): bool => strlen($c) <= 20))->toBeTrue();
    });

    it('expands bare leave-type codes into statutory Ethiopian defaults', function () {
        $tenant = createTenant();

        provisioner()->apply($tenant, legacyPlan());

        $annual = LeaveType::where('tenant_id', $tenant->id)->where('code', 'annual')->first();
        $maternity = LeaveType::where('tenant_id', $tenant->id)->where('code', 'maternity')->first();

        // Labour Proclamation 1156/2019: 16 working days annual, 120 days maternity.
        expect((float) $annual->default_days)->toBe(16.0);
        expect($annual->name_am)->not->toBeNull();
        expect((float) $maternity->default_days)->toBe(120.0);
        expect($maternity->gender_restriction)->toBe('female');
    });

    it('marks a midnight-crossing shift', function () {
        $tenant = createTenant();

        provisioner()->apply($tenant, [
            'shifts' => [['name' => 'Night', 'start' => '22:00', 'end' => '06:00', 'days' => '1,2,3,4,5']],
            'holidays' => false,
        ]);

        $shift = Shift::where('tenant_id', $tenant->id)->first();

        expect($shift->crosses_midnight)->toBeTrue();
        expect($shift->is_default)->toBeTrue();
    });

    it('is idempotent — re-applying creates nothing and duplicates nothing', function () {
        $tenant = createTenant();

        provisioner()->apply($tenant, legacyPlan());
        $second = provisioner()->apply($tenant, legacyPlan())->toArray();

        expect($second['total_created'])->toBe(0);
        expect($second['resources']['departments']['skipped'])->toBe(2);

        expect(Department::where('tenant_id', $tenant->id)->count())->toBe(2);
        expect(Position::where('tenant_id', $tenant->id)->count())->toBe(2);
        expect(LeaveType::where('tenant_id', $tenant->id)->count())->toBe(2);
        expect(Shift::where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('never overwrites configuration the tenant has edited', function () {
        $tenant = createTenant();

        provisioner()->apply($tenant, legacyPlan());

        LeaveType::where('tenant_id', $tenant->id)
            ->where('code', 'annual')
            ->update(['default_days' => 25]);

        provisioner()->apply($tenant, legacyPlan());

        $annual = LeaveType::where('tenant_id', $tenant->id)->where('code', 'annual')->first();
        expect((float) $annual->default_days)->toBe(25.0);
    });

    it('restores a soft-deleted record instead of colliding on its unique code', function () {
        $tenant = createTenant();

        provisioner()->apply($tenant, legacyPlan());

        $department = Department::where('tenant_id', $tenant->id)->where('name', 'Administration')->first();
        $department->delete();

        $result = provisioner()->apply($tenant, legacyPlan())->toArray();

        expect($result['resources']['departments']['restored'])->toBe(1);
        expect(Department::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(2);
        expect(Department::where('tenant_id', $tenant->id)->where('name', 'Administration')->exists())->toBeTrue();
    });

    it('provisions grades and auto-detects Ethiopian holidays including movable feasts', function () {
        $tenant = createTenant();

        provisioner()->apply($tenant, [
            'grades' => [
                ['name' => 'Grade I', 'min_salary_cents' => 400000, 'max_salary_cents' => 540000],
            ],
        ]);

        expect(Grade::where('tenant_id', $tenant->id)->first()->min_salary_cents)->toBe(400000);

        $holidays = Holiday::where('tenant_id', $tenant->id)->pluck('name');
        expect($holidays)->toContain('Adwa Victory Day');
        expect($holidays)->toContain('Ethiopian New Year (Enkutatash)');
        // Movable feasts are computed exactly by HolidayService, not skipped.
        expect($holidays)->toContain('Ethiopian Easter (Fasika)');
    });

    it('does not seed holidays when the template opts out', function () {
        $tenant = createTenant();

        provisioner()->apply($tenant, ['holidays' => false]);

        expect(Holiday::where('tenant_id', $tenant->id)->count())->toBe(0);
    });

    it('skips a shift with no usable times and reports why', function () {
        $tenant = createTenant();

        $result = provisioner()->apply($tenant, [
            'shifts' => [['name' => 'Broken', 'start' => 'not-a-time', 'end' => '17:00']],
            'holidays' => false,
        ])->toArray();

        expect(Shift::where('tenant_id', $tenant->id)->count())->toBe(0);
        expect(implode(' ', $result['warnings']))->toContain('Broken');
    });

    it('merges settings without clobbering namespaces the tenant already set', function () {
        $tenant = createTenant();
        $tenant->update(['settings' => ['payroll' => ['pay_frequency' => 'weekly']]]);

        provisioner()->apply($tenant, [
            'holidays' => false,
            'settings' => [
                'payroll' => ['pay_frequency' => 'monthly', 'pension_employee_rate' => 0.07],
                'employee_number_format' => 'GOV-{SEQ:4}',
            ],
        ]);

        $settings = $tenant->fresh()->settings;

        expect($settings['payroll'])->toBe(['pay_frequency' => 'weekly']);
        expect($settings['employee_number_format'])->toBe('GOV-{SEQ:4}');
    });

    it('keeps provisioned records inside the tenant that asked for them', function () {
        $one = createTenant();
        provisioner()->apply($one, legacyPlan());

        $two = createTenant();
        provisioner()->apply($two, legacyPlan());

        expect(Department::withoutGlobalScope('tenant')->where('tenant_id', $one->id)->count())->toBe(2);
        expect(Department::withoutGlobalScope('tenant')->where('tenant_id', $two->id)->count())->toBe(2);

        // createTenant() left tenant two current, so the scoped query must not
        // see tenant one's rows.
        expect(Department::count())->toBe(2);
    });
});
