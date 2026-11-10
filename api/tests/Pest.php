<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Enums\FeatureState;
use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Permission;
use App\Models\PlatformFeatureState;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(PermissionSeeder::class);
    })
    ->in('Feature', 'Performance');

// No RefreshDatabase: these start other processes, which only see rows that
// were committed (phpunit.concurrency.xml).
pest()->extend(TestCase::class)->in('Concurrency');

function createTenant(array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->create($attributes);
    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}

function createUser(array $attributes = [], ?Tenant $tenant = null): User
{
    $tenant ??= createTenant();

    return User::factory()->create([
        'tenant_id' => $tenant->id,
        ...$attributes,
    ]);
}

function actingAsUser(array $attributes = [], ?Tenant $tenant = null): User
{
    $user = createUser($attributes, $tenant);
    test()->actingAs($user);

    return $user;
}

/**
 * A user whose custom role holds `$permissions` over `$scope` (an OrgScope
 * value). The regional dashboard needs one scoped to a branch: no built-in
 * role reaches a branch.
 *
 * @param  list<string>  $permissions
 */
function createScopedUser(Tenant $tenant, ?int $employeeId, string $scope, array $permissions): User
{
    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => "Scoped {$scope} ".uniqid(), 'org_scope' => $scope]);
    $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

    return createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employeeId, 'custom_role_id' => $role->id], $tenant);
}

/** Signs in a branch-scoped holder of `dashboard.regional`. */
function actingAsRegional(Tenant $tenant, ?int $employeeId): User
{
    $user = createScopedUser($tenant, $employeeId, 'branch', ['dashboard.regional']);
    test()->actingAs($user);

    return $user;
}

/**
 * A JPEG data URL of the requested pixel size, as a browser canvas would send
 * for an attendance selfie. Filled with noise so the encoder cannot compress it
 * away — that is what makes byte-budget assertions meaningful.
 */
function selfieDataUrl(int $width = 400, int $height = 400): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x += 2) {
        for ($y = 0; $y < $height; $y += 2) {
            $colour = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, (int) $colour);
        }
    }

    ob_start();
    imagejpeg($image, null, 92);
    $binary = (string) ob_get_clean();
    imagedestroy($image);

    return 'data:image/jpeg;base64,'.base64_encode($binary);
}

/**
 * Sets a feature's platform state as the console would, and has the scoped
 * FeatureGate forget what it read, so the next check sees the change. Only the
 * gate: forgetting every scoped instance would forget the current tenant too.
 */
function switchFeature(Feature $feature, FeatureState $state = FeatureState::Disabled): void
{
    PlatformFeatureState::query()->updateOrCreate(['key' => $feature->value], ['state' => $state]);

    app(FeatureGate::class)->flush();
}
