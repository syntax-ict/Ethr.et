<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds the global system catalog required for a production install:
 * permissions, subscription plans, Ethiopian tax brackets, and organization
 * templates.
 *
 * Unlike DatabaseSeeder, this seeder does NOT create a demo tenant or a
 * default-password super admin. The super admin is provisioned separately and
 * interactively via `php artisan ethr:create-admin` so no known credentials
 * ever exist in a production database.
 *
 * Every underlying seeder is idempotent (updateOrCreate / firstOrCreate), so
 * this is safe to re-run after upgrades to pick up new permissions or plans.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            PlanSeeder::class,
            TaxBracketSeeder::class,
            OrganizationTemplateSeeder::class,
        ]);

        $this->command?->info('Production system data seeded (permissions, plans, tax brackets, templates).');
        $this->command?->info('Next: create the super admin with `php artisan ethr:create-admin`.');
    }
}
