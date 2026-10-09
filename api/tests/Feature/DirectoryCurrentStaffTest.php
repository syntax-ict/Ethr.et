<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Storage;

/**
 * The staff directory is readable by every signed-in user and carries phone
 * and e-mail. It listed every employee row, so a terminated or resigned
 * person's contact details stayed published to the whole organisation; and it
 * returned the raw `photo_path` storage key beside the signed URLs that are the
 * only thing a client can render.
 */
function directoryViewerFor(Tenant $tenant): User
{
    $self = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Aaa Viewer']);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $self->id], $tenant);
    test()->actingAs($user);

    return $user;
}

/** @return list<string> */
function directoryNamesListed(Tenant $tenant, string $query = ''): array
{
    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/directory{$query}")
        ->assertOk();

    return collect($response->json('data'))->pluck('name')->sort()->values()->all();
}

it('lists hired, probation and confirmed staff and nobody who has left or is suspended', function () {
    $tenant = createTenant();
    directoryViewerFor($tenant);

    foreach (EmployeeStatus::cases() as $status) {
        Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Person '.$status->value,
            'status' => $status,
        ]);
    }

    expect(directoryNamesListed($tenant))->toBe([
        'Aaa Viewer',
        'Person confirmed',
        'Person hired',
        'Person probation',
    ]);
});

it('does not return a former employee from a search that matches them', function () {
    $tenant = createTenant();
    directoryViewerFor($tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Tigist Former',
        'email' => 'tigist.former@example.com',
        'status' => EmployeeStatus::TERMINATED,
    ]);
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Tigist Current',
        'status' => EmployeeStatus::CONFIRMED,
    ]);

    expect(directoryNamesListed($tenant, '?search=Tigist'))->toBe(['Tigist Current'])
        ->and(directoryNamesListed($tenant, '?search=tigist.former'))->toBe([]);
});

it('never lists another tenant\'s staff', function () {
    $tenantA = createTenant(['subdomain' => 'dira']);
    $tenantB = createTenant(['subdomain' => 'dirb']);
    Employee::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Other Tenant Person']);

    app(CurrentTenant::class)->set($tenantA);
    directoryViewerFor($tenantA);

    expect(directoryNamesListed($tenantA))->toBe(['Aaa Viewer']);
});

it('returns signed photo urls but not the raw storage path', function () {
    Storage::fake('local');

    $tenant = createTenant();
    directoryViewerFor($tenant);
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Zz Photo',
        'photo_path' => "tenants/{$tenant->public_id}/photos/abc.jpg",
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/directory")->assertOk();
    $row = collect($response->json('data'))->firstWhere('name', 'Zz Photo');

    expect($row)->not->toHaveKey('photo_path')
        ->and($row['photo_url'])->toBeString()
        ->and($row['photo_thumb_url'])->toContain('thumbs/abc_150.jpg');
});

it('requires authentication', function () {
    $tenant = createTenant();

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/directory")
        ->assertUnauthorized();
});
