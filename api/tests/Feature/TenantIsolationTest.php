<?php

declare(strict_types=1);

use App\Models\BackupStorageSetting;
use App\Models\Lead;
use App\Models\MarketingItem;
use App\Models\MarketingSection;
use App\Models\OrganizationTemplate;
use App\Models\OrganizationType;
use App\Models\OtpCode;
use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\PlatformFeatureState;
use App\Models\PlatformSetting;
use App\Models\ProfileVersion;
use App\Models\SmsGateway;
use App\Models\SmsSend;
use App\Models\SmsSetting;
use App\Models\StatutoryLayout;
use App\Models\Tenant;
use App\Models\TenantJourneySnapshot;
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
    PlatformFeatureState::class, // the platform admin's feature switches, one row per feature
    Permission::class,           // global permission catalogue
    OrganizationTemplate::class, // global industry template catalogue
    OrganizationType::class,     // global catalogue of organisation types statutory rules are scoped by
    ProfileVersion::class,       // published batches of the law; labels platform statutory rows, no tenant data
    PersonalAccessToken::class,  // Sanctum's own table
    PlatformSetting::class,      // super-admin settings; every tenant reads the same row
    // SMS is bought and switched on once for the whole platform by the super
    // admin, so no tenant owns a gateway, the switch or the send ledger. The
    // ledger holds no number and no text — only which gateway and why.
    SmsGateway::class,           // console-configured HTTP gateways (credential encrypted)
    SmsSetting::class,           // the SMS switch, route and daily cap; one row
    SmsSend::class,              // accepted messages counted for the daily cap
    // The backup is of the whole platform, so its off-host storage is too.
    BackupStorageSetting::class, // off-host backup storage; one row, secret and key encrypted
    // Unlike every other entry here, this one is not a catalogue tenants read.
    // A lead comes from a prospect, so there is no tenant to own the row and no
    // tenant that should ever see it: the missing tenant_id is an absent owner,
    // not shared access. Nothing reads the table today; any future read path
    // belongs behind admin.manage on the platform host.
    Lead::class,                 // public contact-form enquiries; platform-only
    // Both hang off `user_id` and their tables carry no `tenant_id`. A user
    // belongs to exactly one tenant, so keying on the user is strictly narrower
    // than keying on the tenant — there is no query in either model's access path
    // that is not already bounded to a single user's own rows.
    TrustedDevice::class,        // MFA trust per browser, scoped by user_id
    OtpCode::class,              // one-time login codes, scoped by user_id
    // The layouts of the national statutory schedules (MoR, POESSA, PSSSA):
    // one set for every organisation, edited only from the platform console.
    StatutoryLayout::class,
    // The public landing page: one marketing site read by every visitor and
    // owned by the platform operator, like Plan and PlatformSetting. Nothing
    // in either table belongs to a tenant.
    MarketingSection::class,
    MarketingItem::class,
];

/**
 * Models carrying a nullable `tenant_id` where NULL is meaningful, so the strict
 * `where tenant_id = X` global scope would hide rows the feature depends on.
 *
 * Empty since 2026-10-28: `FeatureFlag`, its only entry, was replaced by the global
 * `PlatformFeatureState` and the tenant-scoped `TenantFeatureOverride`.
 * Any new entry here needs proof that every read path filters by tenant itself.
 */
const NULLABLE_TENANT_MODELS = [];

/**
 * Platform-side facts about a tenant: they carry a `tenant_id` because they are
 * about one organisation, and carry no BelongsToTenant scope because the only
 * reader is the platform console, which has no tenant resolved and would see
 * nothing through a fail-closed scope. Adding an entry here is a security
 * decision: it must be reachable only behind `admin.manage` on the platform
 * host, and every read must state `tenant_id`. The test below pins the files
 * that may mention each model, so a tenant-facing path cannot start reading it.
 *
 * @var array<class-string<Model>, list<string>> Model => the app/ files allowed to name it.
 */
const PLATFORM_OWNED_TENANT_MODELS = [
    TenantJourneySnapshot::class => [
        'Console/Commands/RefreshTenantJourneysCommand.php',
        'Http/Controllers/Api/V1/Admin/AdminTenantJourneyController.php',
        'Models/Tenant.php',
        'Models/TenantJourneySnapshot.php',
        'Services/Admin/TenantJourney/JourneyCalculator.php',
    ],
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

            if ($hasColumn && ! $hasTrait && ! in_array($class, NULLABLE_TENANT_MODELS, true) && ! array_key_exists($class, PLATFORM_OWNED_TENANT_MODELS)) {
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
            if (! usesTenantTrait($class) && ! in_array($class, GLOBAL_MODELS, true) && ! array_key_exists($class, PLATFORM_OWNED_TENANT_MODELS)) {
                $unscoped[] = $class;
            }
        }

        expect($unscoped)->toBe([]);
    });

    it('keeps platform-owned tenant models out of every file but the console\'s', function () {
        $offenders = [];

        foreach (PLATFORM_OWNED_TENANT_MODELS as $class => $allowed) {
            $short = class_basename($class);
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));

                if (! in_array($relative, $allowed, true) && str_contains((string) file_get_contents($file->getPathname()), $short)) {
                    $offenders[] = "{$relative} names {$short}, which only the platform console may read";
                }
            }
        }

        expect($offenders)->toBe([]);
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
