<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ProductionSeeder;

test('production seeder populates the system catalog without demo data or a default admin', function () {
    $this->seed(ProductionSeeder::class);

    // System catalog is present.
    expect(Permission::count())->toBe(79);
    expect(Plan::count())->toBeGreaterThan(0);

    // No demo tenant and no known-credential super admin were created — those
    // are provisioned separately (ethr:create-admin) so production never ships
    // with predictable logins.
    expect(Tenant::withoutGlobalScopes()->count())->toBe(0);
    expect(User::withoutGlobalScopes()->count())->toBe(0);
});

test('production seeder is idempotent', function () {
    $this->seed(ProductionSeeder::class);
    $this->seed(ProductionSeeder::class);

    expect(Permission::count())->toBe(79);
});
