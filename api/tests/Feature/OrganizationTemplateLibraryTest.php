<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AttendanceSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OnboardingProgress;
use App\Models\OrganizationTemplate;
use App\Models\PayrollRule;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRotation;
use App\Models\Tenant;
use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\IndustryProfileResolver;
use App\Services\Onboarding\LeaveTypeCatalog;
use App\Services\Onboarding\PolicyProfileCatalog;
use App\Services\Onboarding\WorkforceProfileCatalog;
use Database\Seeders\OrganizationTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The composable template library: industry × workforce profile × policy
 * profile, what applying one creates, and the management guards around what it
 * creates. See INDUSTRY_TEMPLATES.md, "Composing a plan".
 */
function tplUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

/** Paid hours in one occurrence of a profile shift, net of its break. */
function shiftHours(array $shift): float
{
    [$sh, $sm] = array_map('intval', explode(':', $shift['start_time']));
    [$eh, $em] = array_map('intval', explode(':', $shift['end_time']));
    $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);
    if ($minutes <= 0) {
        $minutes += 24 * 60;
    }

    return ($minutes - (int) ($shift['break_minutes'] ?? 60)) / 60;
}

describe('catalogue integrity', function () {
    it('offers every requested archetype and government tier, each with a real profile and policy', function () {
        $catalog = app(IndustryCatalog::class);
        $profiles = app(WorkforceProfileCatalog::class);
        $policies = app(PolicyProfileCatalog::class);

        foreach (['woreda_administration', 'city_administration', 'zonal_administration', 'regional_bureau', 'ministry', 'federal_agency', 'sector_office',
            'small_business', 'professional_services', 'retail', 'wholesale', 'manufacturing', 'hospital', 'health_center', 'school', 'university',
            'hotel', 'restaurant', 'construction', 'logistics', 'agriculture', 'ngo', 'technology_company', 'telecom', 'bank', 'insurance',
            'security_company', 'bpo', 'multi_branch', 'custom'] as $key) {
            expect($catalog->has($key))->toBeTrue("missing industry {$key}");
        }

        foreach ($catalog->all() as $industry) {
            expect($profiles->has($industry['profile']))->toBeTrue("{$industry['key']} names unknown profile {$industry['profile']}");
            expect($policies->has($industry['policy']))->toBeTrue("{$industry['key']} names unknown policy {$industry['policy']}");
        }

        expect(collect($catalog->all())->where('group', 'public_sector')->pluck('policy')->unique()->all())
            ->toBe(['ethiopia_public']);
    });

    it('defines only shifts and rotations the shift engine can run, within 48 hours a week', function () {
        $catalog = app(WorkforceProfileCatalog::class);

        foreach ($catalog->keys() as $key) {
            $profile = $catalog->find($key);
            $names = [];

            foreach ($profile['shifts'] as $shift) {
                expect($shift['start_time'])->toMatch('/^([01]\d|2[0-3]):[0-5]\d$/');
                expect($shift['end_time'])->toMatch('/^([01]\d|2[0-3]):[0-5]\d$/');
                expect($shift['start_time'])->not->toBe($shift['end_time']);
                expect($shift['working_days'])->toMatch('/^[1-7](,[1-7])*$/', "{$key}: {$shift['name']} has a non-ISO day");
                expect(shiftHours($shift))->toBeGreaterThan(0);
                $names[$shift['name']] = $shift;

                // Without a rotation a person works one shift on its days.
                $weekly = shiftHours($shift) * count(explode(',', $shift['working_days']));
                if ($profile['rotations'] === []) {
                    expect($weekly)->toBeLessThanOrEqual(48.0, "{$key}: {$shift['name']} is {$weekly}h a week");
                }
            }

            foreach ($profile['rotations'] as $rotation) {
                $offsets = array_column($rotation['steps'], 'day_offset');
                expect($offsets)->toHaveCount(count(array_unique($offsets)));
                expect(max($offsets))->toBeLessThan($rotation['cycle_days']);

                $hours = 0.0;
                foreach ($rotation['steps'] as $step) {
                    if ($step['shift'] !== null) {
                        expect($names)->toHaveKey($step['shift']);
                        $hours += shiftHours($names[$step['shift']]);
                    }
                }

                $weekly = $hours * 7 / $rotation['cycle_days'];
                expect($weekly)->toBeLessThanOrEqual(48.0, "{$key}: {$rotation['name']} averages {$weekly}h a week");
            }
        }
    });

    it('resolves every policy leave type and translates every review note in English and Amharic', function () {
        $leave = app(LeaveTypeCatalog::class);
        $codes = [];

        foreach (app(PolicyProfileCatalog::class)->keys() as $key) {
            $policy = app(PolicyProfileCatalog::class)->find($key);
            foreach ($policy['leave_types'] as $code) {
                expect($leave->resolve($code))->not->toBeNull("{$key}: {$code}");
            }
            $codes = [...$codes, ...$policy['review']];
        }
        foreach (app(WorkforceProfileCatalog::class)->keys() as $key) {
            $codes = [...$codes, ...app(WorkforceProfileCatalog::class)->find($key)['review']];
        }

        foreach (array_unique($codes) as $code) {
            foreach (['en', 'am'] as $locale) {
                expect(__("onboarding.review.{$code}", [], $locale))->not->toBe("onboarding.review.{$code}", "{$locale} lacks {$code}");
            }
        }
    });
});

describe('composing a plan', function () {
    beforeEach(function () {
        $this->seed(OrganizationTemplateSeeder::class);
        createTenant();
    });

    it('gives a hospital round-the-clock cover, private-sector leave and its sector extras by default', function () {
        $plan = app(IndustryProfileResolver::class)->resolve('hospital')->toArray();

        expect($plan['profiles']['workforce']['key'])->toBe('continuous_24_7');
        expect($plan['profiles']['policy']['key'])->toBe('ethiopia_private');
        expect(array_column($plan['plan']['shifts'], 'name'))->toBe(['Morning', 'Afternoon', 'Night']);
        expect($plan['plan']['rotations'][0]['cycle_days'])->toBe(8);
        expect(array_column($plan['plan']['leave_types'], 'code'))->toContain('annual', 'marriage', 'study', 'injury');
        expect($plan['plan']['leave_types'][0]['name'])->toBe('Annual Leave');
        expect(array_column($plan['plan']['allowances'], 'name'))->toContain('Transport allowance', 'Hazard allowance');
        expect($plan['plan']['settings']['working_days'])->toBe([1, 2, 3, 4, 5, 6, 7]);
        expect($plan['plan']['settings']['fiscal_year_start_month'])->toBe(1);
        expect(array_column($plan['review'], 'code'))->toContain('leave_private', 'allowances_inactive', 'rotation_crews');
        expect($plan['sections']['shifts']['source'])->toBe('industry_default');
        expect($plan['catalog_version'])->toBe(IndustryCatalog::VERSION);
    });

    it('lets the admin compose a hospital with office hours under civil-service policy', function () {
        $plan = app(IndustryProfileResolver::class)->resolve('hospital', [
            'workforce_profile' => 'standard_office',
            'policy_profile' => 'ethiopia_public',
        ])->toArray();

        expect(array_column($plan['plan']['shifts'], 'name'))->toBe(['Regular']);
        expect($plan['plan'])->not->toHaveKey('rotations');
        expect($plan['sections']['shifts']['source'])->toBe('explicit');
        expect($plan['sections']['leave_types']['source'])->toBe('explicit');
        expect($plan['plan']['settings']['fiscal_year_start_month'])->toBe(11);
        expect($plan['plan']['settings']['payroll']['fiscal_year_start'])->toBe('hamle');
        expect(array_column($plan['review'], 'code'))->toContain('leave_public', 'payroll_public');
    });

    it('gives each government tier its own structure', function () {
        $resolver = app(IndustryProfileResolver::class);

        $woreda = $resolver->resolve('woreda_administration')->toArray()['plan']['departments'];
        $zone = $resolver->resolve('zonal_administration')->toArray()['plan']['departments'];
        $bureau = $resolver->resolve('regional_bureau')->toArray()['plan']['departments'];

        expect($woreda)->toContain('Education Office');
        expect($zone)->toContain("Zone Administrator's Office");
        expect($bureau)->toContain("Bureau Head's Office");
        expect($woreda)->not->toBe($zone);
    });

    it('refuses an unknown profile on preview', function () {
        $tenant = Tenant::query()->firstOrFail();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson(tplUrl($tenant, '/onboarding/configuration/preview'), [
            'industry' => 'hospital',
            'workforce_profile' => 'split_shift',
        ])->assertUnprocessable()->assertJsonValidationErrors('workforce_profile');
    });

    it('lists the profiles beside the industries', function () {
        $tenant = Tenant::query()->firstOrFail();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->getJson(tplUrl($tenant, '/onboarding/industries'))
            ->assertOk()
            ->assertJsonPath('data.0.policy', 'ethiopia_public')
            ->assertJsonCount(count(app(WorkforceProfileCatalog::class)->keys()), 'workforce_profiles')
            ->assertJsonCount(3, 'policy_profiles');
    });
});

describe('applying a composed plan', function () {
    beforeEach(function () {
        $this->seed(OrganizationTemplateSeeder::class);
        $this->tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);
    });

    it('creates connected, usable configuration and records where it came from', function () {
        $preview = $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/preview'), ['industry' => 'hospital'])
            ->assertOk()->json();

        $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/apply'), [
            'industry' => 'hospital',
            'workforce_profile' => 'continuous_24_7',
            'policy_profile' => 'ethiopia_private',
            'plan' => $preview['plan'],
        ])->assertOk()
            ->assertJsonPath('provisioned.resources.shifts.created', 3)
            ->assertJsonPath('provisioned.resources.rotations.created', 1)
            ->assertJsonPath('provisioned.warnings', []);

        $night = Shift::query()->where('name', 'Night')->firstOrFail();
        expect($night->crosses_midnight)->toBeTrue();
        expect($night->working_days)->toBe('1,2,3,4,5,6,7');

        $rotation = ShiftRotation::query()->with('steps')->where('name', 'Continuous 2-2-2-2')->firstOrFail();
        expect($rotation->cycle_days)->toBe(8);
        expect($rotation->steps)->toHaveCount(8);
        expect($rotation->steps->whereNull('shift_id'))->toHaveCount(2);
        expect($rotation->steps->firstWhere('day_offset', 4)->shift_id)->toBe($night->id);

        $allowances = PayrollRule::query()->where('category', 'allowance')->get();
        expect($allowances->pluck('name')->all())->toContain('Hazard allowance', 'Transport allowance');
        expect($allowances->every(fn (PayrollRule $rule): bool => ! $rule->is_active && ($rule->formula['amount_cents'] ?? null) === 0))->toBeTrue();

        expect(LeaveType::query()->where('code', 'unpaid')->value('is_paid'))->toBeFalse();
        expect(AttendanceSetting::query()->value('auto_checkout_enabled'))->toBeTrue();

        $this->tenant->refresh();
        expect($this->tenant->settings['working_days'])->toBe([1, 2, 3, 4, 5, 6, 7]);
        expect($this->tenant->settings['fiscal_year_start_month'])->toBe(1);
        expect($this->tenant->settings['configuration_profile'])->toMatchArray([
            'industry' => 'hospital',
            'workforce_profile' => 'continuous_24_7',
            'policy_profile' => 'ethiopia_private',
            'catalog_version' => IndustryCatalog::VERSION,
        ]);
    });

    it('creates nothing the second time', function () {
        $plan = $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/preview'), ['industry' => 'manufacturing'])->json('plan');

        $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/apply'), ['industry' => 'manufacturing', 'plan' => $plan])->assertOk();
        $counts = fn (): array => [Shift::count(), ShiftRotation::count(), LeaveType::count(), PayrollRule::count(), Department::count()];
        $before = $counts();

        $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/apply'), ['industry' => 'manufacturing', 'plan' => $plan])
            ->assertOk()
            ->assertJsonPath('provisioned.total_created', 0);

        expect($counts())->toBe($before);
    });

    it('never overwrites an attendance setting the admin has changed', function () {
        AttendanceSetting::query()->create([...AttendanceSetting::defaults(), 'tenant_id' => $this->tenant->id, 'grace_period_minutes' => 30]);

        $plan = $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/preview'), [
            'industry' => 'technology_company',
        ])->json('plan');

        expect($plan['attendance']['grace_period_minutes'])->toBe(120);

        $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/apply'), ['industry' => 'technology_company', 'plan' => $plan])
            ->assertOk()
            ->assertJsonPath('provisioned.resources.attendance_settings.skipped', 1);

        expect(AttendanceSetting::query()->value('grace_period_minutes'))->toBe(30);
    });

    it('skips a rotation whose shift the admin removed, rather than leaving a hole', function () {
        $plan = $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/preview'), ['industry' => 'hotel'])->json('plan');
        $plan['shifts'] = array_values(array_filter($plan['shifts'], fn (array $shift): bool => $shift['name'] !== 'Night'));

        $warnings = $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/apply'), ['industry' => 'hotel', 'plan' => $plan])
            ->assertOk()
            ->json('provisioned.warnings');

        expect(ShiftRotation::count())->toBe(0);
        expect(implode(' ', $warnings))->toContain('Night');
    });

    it('rolls back every record when a later step of the apply fails', function () {
        Event::listen('eloquent.creating: '.OnboardingProgress::class, function (): void {
            throw new RuntimeException('progress write failed');
        });

        $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/apply'), [
            'industry' => 'custom',
            'plan' => ['departments' => ['Operations'], 'shifts' => [['name' => 'Day', 'start' => '08:00', 'end' => '16:00']], 'holidays' => false],
        ])->assertServerError();

        expect(Department::count())->toBe(0);
        expect(Shift::count())->toBe(0);
        expect($this->tenant->fresh()->settings['configuration_applied_at'] ?? null)->toBeNull();
    });

    it('keeps what it creates inside the tenant that applied it', function () {
        $other = Tenant::factory()->create();

        $plan = $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/preview'), ['industry' => 'security_company'])->json('plan');
        $this->postJson(tplUrl($this->tenant, '/onboarding/configuration/apply'), ['industry' => 'security_company', 'plan' => $plan])->assertOk();

        foreach ([Shift::class, ShiftRotation::class, PayrollRule::class, LeaveType::class] as $model) {
            expect($model::withoutGlobalScope('tenant')->where('tenant_id', $other->id)->count())->toBe(0, $model);
            expect($model::withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->count())->toBeGreaterThan(0, $model);
        }
        expect(AttendanceSetting::withoutGlobalScope('tenant')->where('tenant_id', $other->id)->exists())->toBeFalse();
    });
});

describe('managing generated configuration', function () {
    beforeEach(function () {
        $this->tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);
    });

    it('duplicates a shift as an unassigned, non-default copy', function () {
        $shift = Shift::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Night', 'is_default' => true]);

        $this->postJson(tplUrl($this->tenant, "/shifts/{$shift->public_id}/duplicate"))
            ->assertCreated()
            ->assertJsonPath('name', 'Night (copy)')
            ->assertJsonPath('is_default', false);
        $this->postJson(tplUrl($this->tenant, "/shifts/{$shift->public_id}/duplicate"))
            ->assertCreated()
            ->assertJsonPath('name', 'Night (copy 2)');

        expect(Shift::query()->where('is_default', true)->count())->toBe(1);
    });

    it('says who an edit to a shift would reach before it is made', function () {
        $shift = Shift::factory()->create(['tenant_id' => $this->tenant->id]);
        $department = Department::factory()->create(['tenant_id' => $this->tenant->id]);
        $direct = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'status' => EmployeeStatus::CONFIRMED->value]);
        Employee::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'department_id' => $department->id, 'status' => EmployeeStatus::CONFIRMED->value]);
        Employee::factory()->create(['tenant_id' => $this->tenant->id, 'department_id' => $department->id, 'status' => EmployeeStatus::RESIGNED->value]);

        foreach ([[Employee::class, $direct->id], [Department::class, $department->id]] as [$type, $id]) {
            ShiftAssignment::factory()->create([
                'tenant_id' => $this->tenant->id, 'shift_id' => $shift->id,
                'assignable_type' => $type, 'assignable_id' => $id,
                'effective_from' => now()->subMonth()->toDateString(), 'effective_to' => null,
            ]);
        }

        $this->getJson(tplUrl($this->tenant, "/shifts/{$shift->public_id}/usage"))
            ->assertOk()
            ->assertJsonPath('data.assignments.employees', 1)
            ->assertJsonPath('data.assignments.departments', 1)
            ->assertJsonPath('data.people_up_to', 3);
    });

    it('refuses to delete a leave type that requests use, and points to deactivating it', function () {
        $type = LeaveType::factory()->create(['tenant_id' => $this->tenant->id]);
        $unused = LeaveType::factory()->sick()->create(['tenant_id' => $this->tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        LeaveRequest::factory()->create(['tenant_id' => $this->tenant->id, 'leave_type_id' => $type->id, 'employee_id' => $employee->id]);

        $this->deleteJson(tplUrl($this->tenant, "/leave-types/{$type->public_id}"))
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://ethr.et/errors/leave-type-in-use');
        expect($type->fresh()->trashed())->toBeFalse();

        $this->deleteJson(tplUrl($this->tenant, "/leave-types/{$unused->public_id}"))->assertNoContent();
    });

    it('duplicates a leave type as an inactive copy with its own code', function () {
        $type = LeaveType::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'annual', 'name' => 'Annual Leave', 'is_active' => true]);

        $this->postJson(tplUrl($this->tenant, "/leave-types/{$type->public_id}/duplicate"))
            ->assertCreated()
            ->assertJsonPath('code', 'annual_copy')
            ->assertJsonPath('name', 'Annual Leave (copy)')
            ->assertJsonPath('is_active', false);
    });

    it('answers a duplicate leave code with 422, not a database error', function () {
        LeaveType::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'annual']);

        $this->postJson(tplUrl($this->tenant, '/leave-types'), [
            'name' => 'Annual again', 'code' => 'annual', 'default_days' => 16, 'accrual_type' => 'annual',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    });

    it('allows zero days for unpaid leave only', function () {
        $base = ['accrual_type' => 'annual', 'default_days' => 0];

        $this->postJson(tplUrl($this->tenant, '/leave-types'), [...$base, 'name' => 'Unpaid', 'code' => 'unpaid', 'is_paid' => false])
            ->assertCreated();
        $this->postJson(tplUrl($this->tenant, '/leave-types'), [...$base, 'name' => 'Nothing', 'code' => 'nothing', 'is_paid' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('default_days');
    });

    it('refuses to delete a department current employees work in', function () {
        $department = Department::factory()->create(['tenant_id' => $this->tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'department_id' => $department->id, 'status' => EmployeeStatus::CONFIRMED->value]);

        $this->deleteJson(tplUrl($this->tenant, "/organization/departments/{$department->public_id}"))
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://ethr.et/errors/org-unit-in-use');

        $employee->update(['status' => EmployeeStatus::TERMINATED->value]);

        $this->deleteJson(tplUrl($this->tenant, "/organization/departments/{$department->public_id}"))->assertNoContent();
    });

    it('keeps duplicating and usage behind shift permissions', function () {
        $shift = Shift::factory()->create(['tenant_id' => $this->tenant->id]);
        actingAsUser(['role' => UserRole::EMPLOYEE], $this->tenant);

        $this->postJson(tplUrl($this->tenant, "/shifts/{$shift->public_id}/duplicate"))->assertForbidden();
    });
});

describe('the seeded templates', function () {
    it('keep their public ids across re-seeding and write Sunday as 7', function () {
        $this->seed(OrganizationTemplateSeeder::class);
        $before = OrganizationTemplate::query()->pluck('public_id', 'slug')->all();

        $this->seed(OrganizationTemplateSeeder::class);

        expect(OrganizationTemplate::query()->pluck('public_id', 'slug')->all())->toBe($before);

        $days = collect(OrganizationTemplate::query()->where('slug', 'hospital')->firstOrFail()->template_data['shifts'])->pluck('days');
        expect($days->every(fn (string $d): bool => preg_match('/^[1-7](,[1-7])*$/', $d) === 1))->toBeTrue();
    });

    it('repairs shifts a template created with Sunday as 0', function () {
        $tenant = createTenant();
        $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
        DB::table('shifts')->where('id', $shift->id)->update(['working_days' => '1,2,3,4,5,6,0']);

        (require database_path('migrations/2026_10_14_000001_fix_sunday_in_template_shift_working_days.php'))->up();

        expect($shift->fresh()->working_days)->toBe('1,2,3,4,5,6,7');
    });
});
