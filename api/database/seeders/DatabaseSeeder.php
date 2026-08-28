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
        if (app()->isProduction() && env('SUPER_ADMIN_PASSWORD') === null) {
            throw new RuntimeException(
                'SUPER_ADMIN_PASSWORD must be set when seeding in production. '
                .'Refusing to create a platform super admin with the default password.'
            );
        }

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
                    'password' => env('SUPER_ADMIN_PASSWORD', 'password'),
                    'role' => UserRole::SUPER_ADMIN,
                    'status' => 'active',
                    'locale' => 'en',
                    'mfa_enabled' => false,
                ]
            );

        if (! $superAdmin->wasRecentlyCreated) {
            $superAdmin->update([
                'password' => env('SUPER_ADMIN_PASSWORD', 'password'),
                'role' => UserRole::SUPER_ADMIN,
                'status' => 'active',
            ]);
        }

        $this->command?->info('Super admin: superadmin@ethr.et / password (change in production!)');

        $this->call(DemoTenantSeeder::class);
    }
}
