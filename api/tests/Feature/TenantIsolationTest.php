<?php

declare(strict_types=1);

use App\Models\FeatureFlag;
use App\Models\OrganizationTemplate;
use App\Models\OtpCode;
use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Models that are deliberately global — they hold no tenant-scoped data and must NOT
 * carry `tenant_id` or `BelongsToTenant`. Adding an entry here is a security decision:
 * anything listed is readable by every tenant.
 */
const GLOBAL_MODELS = [
    Tenant::class,               // the tenant record itself
    Plan::class,                 // global subscription catalogue
    FeatureFlag::class,          // global when tenant_id is null
    Permission::class,           // global permission catalogue
    OrganizationTemplate::class, // global industry template catalogue
    PersonalAccessToken::class,  // Sanctum's own table
    PlatformSetting::class,      // super-admin settings; every tenant reads the same row
    // Both hang off `user_id` and their tables carry no `tenant_id`. A user
    // belongs to exactly one tenant, so keying on the user is strictly narrower
    // than keying on the tenant — there is no query in either model's access path
    // that is not already bounded to a single user's own rows.
    TrustedDevice::class,        // MFA trust per browser, scoped by user_id
    OtpCode::class,              // one-time login codes, scoped by user_id
];

/**
 * Models carrying a nullable `tenant_id` where NULL is meaningful, so the strict
 * `where tenant_id = X` global scope would hide rows the feature depends on.
 *
 * `FeatureFlag` stores a global default as `tenant_id IS NULL` and a per-tenant
 * override as a real id; `FeatureFlag::enabled()` filters both branches explicitly
 * and the only other access is via `Tenant::featureFlags()`, which is already scoped.
 * Any new entry here needs the same proof: every read path filters by tenant itself.
 */
const NULLABLE_TENANT_MODELS = [
    FeatureFlag::class,
];

/**
 * @return list<class-string<Model>>
 */
function discoverModels(): array
{
    $models = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
            continue;
        }

        $models[] = $class;
    }

    sort($models);

    return $models;
}

function usesTenantTrait(string $class): bool
{
    return in_array(BelongsToTenant::class, class_uses_recursive($class), true);
}

describe('tenant isolation model sweep', function () {
    it('discovers every Eloquent model', function () {
        // Guards against the sweep finding nothing and passing vacuously.
        expect(discoverModels())->not->toBeEmpty();
    });

    it('pairs the tenant_id column and the BelongsToTenant trait on every model', function () {
        $offenders = [];

        foreach (discoverModels() as $class) {
            $table = (new $class)->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $hasColumn = Schema::hasColumn($table, 'tenant_id');
            $hasTrait = usesTenantTrait($class);

            if ($hasColumn && ! $hasTrait && ! in_array($class, NULLABLE_TENANT_MODELS, true)) {
                $offenders[] = "{$class} has a tenant_id column but is missing BelongsToTenant";
            }

            if ($hasTrait && ! $hasColumn) {
                $offenders[] = "{$class} uses BelongsToTenant but {$table} has no tenant_id column";
            }
        }

        expect($offenders)->toBe([]);
    });

    it('registers an active tenant global scope on every scoped model', function () {
        $offenders = [];

        foreach (discoverModels() as $class) {
            if (! usesTenantTrait($class)) {
                continue;
            }

            // The trait being present is not enough — confirm it actually registered its
            // scope, so no override can strip the protection while looking compliant.
            if (! array_key_exists('tenant', (new $class)->getGlobalScopes())) {
                $offenders[] = "{$class} carries BelongsToTenant but has no active 'tenant' global scope";
            }
        }

        expect($offenders)->toBe([]);
    });

    it('requires every unscoped model to be an explicit global decision', function () {
        $unscoped = [];

        foreach (discoverModels() as $class) {
            if (! usesTenantTrait($class) && ! in_array($class, GLOBAL_MODELS, true)) {
                $unscoped[] = $class;
            }
        }

        expect($unscoped)->toBe([]);
    });
});

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
        // Use the tenant's real id, not the literal 1. MySQL does not roll back
        // AUTO_INCREMENT, so after a few hundred tests the first tenant of a
        // test is id 1173, not 1, and the hardcoded value violated the foreign
        // key. SQLite's rowid effectively restarts once the transaction unwinds,
        // which is the only reason the literal ever worked.
        $tenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $tenant->id]);

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
