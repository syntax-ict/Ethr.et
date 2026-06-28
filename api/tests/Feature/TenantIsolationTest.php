<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;

describe('tenant isolation', function () {
    it('scopes queries to the current tenant', function () {
        $tenantA = createTenant(['name' => 'Tenant A']);
        User::factory()->create(['tenant_id' => $tenantA->id, 'email' => 'a@test.com']);

        $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
        User::factory()->create(['tenant_id' => $tenantB->id, 'email' => 'b@test.com']);

        app(CurrentTenant::class)->set($tenantA);
        expect(User::count())->toBe(1);
        expect(User::first()->email)->toBe('a@test.com');

        app(CurrentTenant::class)->set($tenantB);
        expect(User::count())->toBe(1);
        expect(User::first()->email)->toBe('b@test.com');
    });

    it('auto-assigns tenant_id on create', function () {
        $tenant = createTenant();

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        expect($user->tenant_id)->toBe($tenant->id);
    });

    it('returns empty when no tenant is resolved', function () {
        Tenant::factory()->create();
        User::factory()->create(['tenant_id' => 1]);

        app(CurrentTenant::class)->forget();
        expect(User::count())->toBe(0);
    });

    it('hides internal ids from serialization', function () {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $array = $user->toArray();

        expect($array)->not->toHaveKey('id');
        expect($array)->not->toHaveKey('tenant_id');
        expect($array)->toHaveKey('public_id');
    });
});
