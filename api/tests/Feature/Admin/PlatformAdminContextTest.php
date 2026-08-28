<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * The platform super admin belongs to no tenant. `hasPermission()` short-circuits
 * on the role, so every tenant-scoped `Gate::authorize()` passes for them — which
 * means tenant-scoped endpoints have to refuse on *context*, not on permission,
 * or they run with a null tenant id and blow up.
 */
describe('super admin with no tenant', function () {
    it('gets a 404 from onboarding progress instead of a 500', function () {
        // Reproduces the real defect: /onboarding/progress created a row with a
        // null tenant_id and threw a QueryException — on the dashboard a super
        // admin lands on straight after signing in.
        $superAdmin = User::factory()->create([
            'tenant_id' => null,
            'role' => UserRole::SUPER_ADMIN,
            'mfa_enabled' => true,
        ]);
        test()->actingAs($superAdmin);

        test()->getJson('http://admin.ethr.test/api/v1/onboarding/progress')
            ->assertNotFound()
            ->assertJsonPath('title', 'Not Found');

        expect(DB::table('onboarding_progress')->count())->toBe(0);
    });

    it('gets a 404 rather than a 500 from every other tenant-scoped onboarding action', function () {
        $superAdmin = User::factory()->create([
            'tenant_id' => null,
            'role' => UserRole::SUPER_ADMIN,
            'mfa_enabled' => true,
        ]);
        test()->actingAs($superAdmin);

        test()->putJson('http://admin.ethr.test/api/v1/onboarding/progress/1')
            ->assertNotFound();

        test()->postJson('http://admin.ethr.test/api/v1/onboarding/invite', [
            'emails' => ['someone@example.et'],
            'role' => 'employee',
        ])->assertNotFound();

        test()->postJson('http://admin.ethr.test/api/v1/onboarding/complete')
            ->assertNotFound();
    });
});

describe('cross-tenant counting', function () {
    it('reports each tenant real headcount, not zero', function () {
        // BelongsToTenant's global scope is `whereRaw('0 = 1')` when no tenant is
        // resolved — which is always, for a platform admin. `withoutGlobalScopes()`
        // on the outer Tenant query does not reach the withCount subquery, so the
        // console reported 0 employees for every tenant, including one with 150.
        $tenantA = createTenant();
        Employee::factory()->count(3)->create(['tenant_id' => $tenantA->id]);

        $tenantB = createTenant();
        Employee::factory()->count(1)->create(['tenant_id' => $tenantB->id]);

        $superAdmin = User::factory()->create([
            'tenant_id' => null,
            'role' => UserRole::SUPER_ADMIN,
            'mfa_enabled' => true,
        ]);
        test()->actingAs($superAdmin);

        app(CurrentTenant::class)->forget();

        $counts = collect(
            test()->getJson('http://admin.ethr.test/api/v1/admin/tenants')
                ->assertOk()
                ->json('data')
        )->pluck('employee_count', 'public_id');

        expect($counts[$tenantA->public_id])->toBe(3)
            ->and($counts[$tenantB->public_id])->toBe(1);
    });

    it('reports real headcount on the tenant detail view too', function () {
        $tenant = createTenant();
        Employee::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $superAdmin = User::factory()->create([
            'tenant_id' => null,
            'role' => UserRole::SUPER_ADMIN,
            'mfa_enabled' => true,
        ]);
        test()->actingAs($superAdmin);

        app(CurrentTenant::class)->forget();

        test()->getJson("http://admin.ethr.test/api/v1/admin/tenants/{$tenant->public_id}")
            ->assertOk()
            ->assertJsonPath('usage.employees', 2);
    });
});

describe('failed job retry', function () {
    it('re-dispatches a failed job and removes it from the list', function () {
        // Only the 404 and empty-retry-all paths were covered before; the action
        // an admin actually takes from the console was not.
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
        test()->actingAs($user);

        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob']),
            'exception' => 'Exception: something broke',
            'failed_at' => now(),
        ]);

        Queue::fake();

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs/{$uuid}/retry")
            ->assertOk();

        expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();
    });

    it('refuses a retry from a tenant admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob']),
            'exception' => 'Exception: something broke',
            'failed_at' => now(),
        ]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs/{$uuid}/retry")
            ->assertForbidden();

        expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
    });
});
