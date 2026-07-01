<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            TaxBracketSeeder::class,
            OrganizationTemplateSeeder::class,
        ]);

        // Super admin account for platform administration
        User::updateOrCreate(
            ['email' => 'superadmin@ethr.et'],
            [
                'public_id' => (string) Str::ulid(),
                'tenant_id' => null,
                'password' => bcrypt(env('SUPER_ADMIN_PASSWORD', 'password')),
                'role' => UserRole::SUPER_ADMIN,
                'status' => 'active',
                'locale' => 'en',
                'mfa_enabled' => false,
            ]
        );

        $this->command?->info('Super admin: superadmin@ethr.et / password (change in production!)');
    }
}
