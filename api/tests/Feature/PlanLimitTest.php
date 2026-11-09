<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\PlanLimitService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * `Plan.max_employees`/`max_branches`/`max_devices` were read by nothing but
 * the pricing page — any tenant had unlimited headcount regardless of plan
 * tier. These cover `PlanLimitService`, wired into the three creation
 * endpoints plus the employee bulk-import commit path.
 */
function subscribeTo(Tenant $tenant, array $planAttributes): Subscription
{
    $plan = Plan::factory()->create($planAttributes);

    return Subscription::factory()->for($tenant)->create(['plan_id' => $plan->id]);
}

describe('employee limit', function () {
    it('blocks creating an employee once an active tenant is at its plan limit', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_employees' => 1]);
        Employee::factory()->create(['tenant_id' => $tenant->id]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Second Employee',
            'hire_date' => '2026-01-01',
        ])->assertForbidden();

        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('allows creating an employee while under the plan limit', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_employees' => 2]);
        Employee::factory()->create(['tenant_id' => $tenant->id]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Second Employee',
            'hire_date' => '2026-01-01',
        ])->assertCreated();
    });

    it('does not cap a trial tenant even past what its plan would allow', function () {
        $tenant = createTenant(['status' => 'trial']);
        subscribeTo($tenant, ['max_employees' => 1]);
        Employee::factory()->create(['tenant_id' => $tenant->id]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Second Employee',
            'hire_date' => '2026-01-01',
        ])->assertCreated();
    });

    it('does not cap an active tenant with no subscription on record', function () {
        $tenant = createTenant();
        Employee::factory()->count(5)->create(['tenant_id' => $tenant->id]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Sixth Employee',
            'hire_date' => '2026-01-01',
        ])->assertCreated();
    });

    it('leaves an already over-limit tenant free to update its existing employees', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_employees' => 1]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Original Name']);
        Employee::factory()->create(['tenant_id' => $tenant->id]); // already over the cap of 1
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->putJson("/api/v1/employees/{$employee->public_id}", [
            'name' => 'Updated Name',
        ])->assertOk();
    });
});

describe('branch limit', function () {
    it('blocks creating a branch once an active tenant is at its plan limit', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_branches' => 1]);
        $tenant->branches()->create(['public_id' => (string) Str::ulid(), 'name' => 'HQ']);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/organization/branches', ['name' => 'Second Branch'])
            ->assertForbidden();
    });

    it('allows creating a branch while under the plan limit', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_branches' => 2]);
        $tenant->branches()->create(['public_id' => (string) Str::ulid(), 'name' => 'HQ']);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/organization/branches', ['name' => 'Second Branch'])
            ->assertCreated();
    });
});

describe('device limit', function () {
    it('blocks registering a device once an active tenant is at its plan limit', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_devices' => 1]);
        $branch = $tenant->branches()->create(['public_id' => (string) Str::ulid(), 'name' => 'HQ']);
        $tenant->devices()->create([
            'public_id' => (string) Str::ulid(),
            'name' => 'Existing Terminal',
            'adapter_type' => 'mock',
            'branch_id' => $branch->id,
            'connection_config' => [],
            'webhook_token' => 'existing-token',
            'status' => 'pending',
        ]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/devices', [
            'name' => 'Second Terminal',
            'adapter_type' => 'mock',
            'branch_public_id' => $branch->public_id,
            // `required|array` on connection_config rejects an empty array, so
            // this needs a real (if unused-by-mock) key to reach the endpoint's
            // own logic rather than 422ing on FormRequest validation first.
            'connection_config' => ['note' => 'test'],
        ])->assertForbidden();
    });
});

describe('bulk import limit', function () {
    it('rejects an import batch that would push an active tenant past its plan limit', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_employees' => 5]);
        Employee::factory()->count(4)->create(['tenant_id' => $tenant->id]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'batch-1',
            'rows' => [
                ['name' => 'Row One', 'hire_date' => '2026-01-01', 'employee_code' => 'ROW-1'],
                ['name' => 'Row Two', 'hire_date' => '2026-01-01', 'employee_code' => 'ROW-2'],
            ],
            'create_logins' => false,
        ])->assertForbidden();

        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(4);
    });
});

/**
 * A count, then a create, with nothing between them: two import commits sent
 * together each counted the same headcount, each passed, and both landed past
 * the plan (review 2026-11-09). PHP in one test process cannot run two
 * requests at once, so these pin the two halves of the fix: the create runs
 * while the organisation's seat lock is held, and a writer that cannot get
 * the lock is refused rather than let through.
 */
describe('the seat lock', function () {
    it('holds the organisation\'s seat lock while an import commit counts and creates', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_employees' => 10]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $heldDuringCreate = [];
        Employee::creating(function () use ($tenant, &$heldDuringCreate): void {
            $probe = Cache::lock(PlanLimitService::lockKey($tenant, 'employees'), 5);
            $heldDuringCreate[] = ! $probe->get();
        });

        $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'locked-batch',
            'rows' => [
                ['name' => 'Row One', 'hire_date' => '2026-01-01', 'employee_code' => 'LOCK-1'],
                ['name' => 'Row Two', 'hire_date' => '2026-01-01', 'employee_code' => 'LOCK-2'],
            ],
            'create_logins' => false,
        ])->assertCreated();

        expect($heldDuringCreate)->toBe([true, true]);
    });

    it('holds the seat lock while a single employee is added', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_employees' => 10]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $heldDuringCreate = null;
        Employee::creating(function () use ($tenant, &$heldDuringCreate): void {
            $heldDuringCreate = ! Cache::lock(PlanLimitService::lockKey($tenant, 'employees'), 5)->get();
        });

        $this->postJson('/api/v1/employees', ['name' => 'Locked Add', 'hire_date' => '2026-01-01'])->assertCreated();

        expect($heldDuringCreate)->toBeTrue();
    });

    it('counts what the writer before it created', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_employees' => 3]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $commit = fn (string $key, array $codes) => $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => $key,
            'rows' => array_map(fn (string $code): array => ['name' => "Person {$code}", 'hire_date' => '2026-01-01', 'employee_code' => $code], $codes),
            'create_logins' => false,
        ]);

        $commit('first', ['A-1', 'A-2'])->assertCreated();
        $commit('second', ['B-1', 'B-2'])->assertForbidden();

        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(2);
    });

    it('refuses a commit that cannot get the seat lock, and creates no one', function () {
        $tenant = createTenant();
        subscribeTo($tenant, ['max_employees' => 10]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $other = Cache::lock(PlanLimitService::lockKey($tenant, 'employees'), 60);
        expect($other->get())->toBeTrue();
        app()->instance(PlanLimitService::class, new PlanLimitService(lockWaitSeconds: 0));

        $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'blocked-batch',
            'rows' => [['name' => 'Waits', 'hire_date' => '2026-01-01', 'employee_code' => 'WAIT-1']],
            'create_logins' => false,
        ])->assertStatus(409)
            ->assertJsonPath('detail', __('billing.limit_check_busy'));

        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(0);

        $other->release();
    });
});
