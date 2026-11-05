<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Event;

// A-Z review I (2026-11-04). On a 1,000-employee organisation the employee
// list of 100 rows took 708 ms in the application and made 113 queries, 101
// of them the same cache read: each row asked whether the viewer may see pay,
// and each ask read the role's permissions from the cache, which on shared
// hosting is a database table. The user's permissions are now resolved once
// per request.

it('resolves the viewer\'s permissions once however many rows a list shows', function () {
    $tenant = createTenant();
    Employee::factory()->count(40)->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $reads = 0;
    Event::listen([CacheHit::class, CacheMissed::class], function ($event) use (&$reads): void {
        if (str_starts_with($event->key, 'role_permissions:')) {
            $reads++;
        }
    });

    $this->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees?per_page=40")->assertOk();

    expect($reads)->toBeLessThanOrEqual(2);
});
