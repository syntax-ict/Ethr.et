<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The super admin bypasses every permission check and can read every
        // tenant, so it must never be created with a known password on a live
        // system. Printing "change in production!" afterwards is advisory; this
        // is not. Note the update() below re-asserts the password on every run,
        // so a seeder invoked against production would also silently reset a
        // password that had been changed.
        // `blank()`, not `=== null`: a present-but-empty `SUPER_ADMIN_PASSWORD=`
        // line in a .env file resolves to an empty STRING, which satisfied the
        // old null check and then flowed straight into the password field
        // below — so the guard passed and the super admin was created with an
        // empty password, the exact outcome it exists to prevent. This is the
        // trap App\Support\TenancyDomain exists for, on a different variable.
        $configured = env('SUPER_ADMIN_PASSWORD');

        if (app()->isProduction() && blank($configured)) {
            throw new RuntimeException(
                'SUPER_ADMIN_PASSWORD must be set to a non-empty value when seeding in production. '
                .'Refusing to create a platform super admin with the default password. '
                .'Note the production path is `db:seed --class=ProductionSeeder` plus '
                .'`php artisan ethr:create-admin`, which never runs this seeder at all.'
            );
        }

        $superAdminPassword = blank($configured) ? 'password' : (string) $configured;

        $this->call([
            PermissionSeeder::class,
            PlanSeeder::class,
            TaxBracketSeeder::class,
            OrganizationTemplateSeeder::class,
        ]);

        $superAdmin = User::withoutGlobalScopes()
            ->firstOrCreate(
                ['email' => 'superadmin@ethr.et', 'tenant_id' => null],
                [
                    'public_id' => (string) Str::ulid(),
                    'password' => $superAdminPassword,
                    'role' => UserRole::SUPER_ADMIN,
                    'status' => 'active',
                    'locale' => 'en',
                    'mfa_enabled' => false,
                ]
            );

        if (! $superAdmin->wasRecentlyCreated) {
            $superAdmin->update([
                'password' => $superAdminPassword,
                'role' => UserRole::SUPER_ADMIN,
                'status' => 'active',
            ]);
        }

        $this->command?->info('Super admin: superadmin@ethr.et / password (change in production!)');

        $this->call(DemoTenantSeeder::class);
    }
}
