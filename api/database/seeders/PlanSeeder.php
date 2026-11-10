<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For a single office getting attendance and leave onto a system.',
                'description_am' => 'መገኘትንና ፈቃድን ወደ ሥርዓት ለማስገባት ለሚፈልግ አንድ ቢሮ።',
                'price_cents' => 0,
                'max_employees' => 10,
                'max_branches' => 1,
                'max_devices' => 2,
                'features' => ['attendance', 'leave', 'employee_management'],
                'marketing_features' => [
                    'Attendance and leave',
                    'Employee records',
                    'Works offline',
                ],
                'marketing_features_am' => [
                    'መገኘት እና ፈቃድ',
                    'የሠራተኛ መዝገብ',
                    'ከመስመር ውጭ ይሠራል',
                ],
                'description_om' => 'Waajjira tokko kan argamaa fi boqonnaa sirnatti galchuu barbaaduuf.',
                'marketing_features_om' => [
                    'Argamaa fi boqonnaa',
                    'Galmee hojjettootaa',
                    'Toora ala ni hojjeta',
                ],
                'sort_order' => 1,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'For an organisation running payroll across several branches.',
                'description_am' => 'በበርካታ ቅርንጫፎች ደመወዝ ለሚያስኬድ ድርጅት።',
                'price_cents' => 99900,
                'is_popular' => true,
                'max_employees' => 100,
                'max_branches' => 5,
                'max_devices' => 20,
                'features' => ['attendance', 'leave', 'employee_management', 'payroll', 'reports', 'notifications'],
                'marketing_features' => [
                    'Everything in Starter',
                    'Payroll with Ethiopian income tax and pension',
                    'Reports and notifications',
                ],
                'marketing_features_am' => [
                    'ሁሉም በStarter ውስጥ ያለው',
                    'ከኢትዮጵያ የገቢ ግብርና ጡረታ ጋር ደመወዝ',
                    'ሪፖርቶች እና ማሳወቂያዎች',
                ],
                'description_om' => 'Dhaabbata dameewwan hedduu keessatti kaffaltii mindaa raawwatuuf.',
                'marketing_features_om' => [
                    'Wanti Starter keessa jiru hundi',
                    'Kaffaltii mindaa gibira galii fi soorama Itoophiyaa waliin',
                    'Gabaasaalee fi beeksisoota',
                ],
                'sort_order' => 2,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'For an organisation that needs no ceiling and its own integrations.',
                'description_am' => 'ጣሪያ የሌለውና የራሱ ውህደቶች ለሚያስፈልገው ድርጅት።',
                'price_cents' => 899900,
                // null, not a 999999 sentinel: PlanLimitService:53 returns
                // null for "no ceiling" and every caller already honours it, so
                // the sentinel was never enforcement — just a number large
                // enough to look like infinity. It stopped being harmless when
                // the public pricing page began reading these columns and
                // advertised "Up to 999,999 employees".
                'max_employees' => null,
                'max_branches' => null,
                'max_devices' => null,
                'features' => ['attendance', 'leave', 'employee_management', 'payroll', 'reports', 'notifications', 'api_access', 'webhooks', 'custom_reports', 'audit_log', 'custom_domain'],
                'marketing_features' => [
                    'Everything in Professional',
                    'API access, webhooks and the audit log',
                ],
                'marketing_features_am' => [
                    'ሁሉም በProfessional ውስጥ ያለው',
                    'የAPI መዳረሻ፣ webhooks እና የኦዲት መዝገብ',
                ],
                'description_om' => 'Dhaabbata daangaa hin barbaannee fi walqabsiisa mataa isaa barbaaduuf.',
                'marketing_features_om' => [
                    'Wanti Professional keessa jiru hundi',
                    'Seensa API, webhooks fi galmee odiitii',
                ],
                'sort_order' => 3,
            ],
        ];

        // firstOrCreate, not updateOrCreate — changed deliberately when the
        // catalog became admin-editable.
        //
        // Every column here is now something a super admin can change at
        // /admin/plans: the price, the limits, the description, the bullets.
        // updateOrCreate keyed on slug would reset all of it to these literals
        // on any re-seed, so an operator's curated pricing would silently
        // revert to a developer's placeholder — and `db:seed` is exactly what
        // someone runs after a restore, when they are least likely to notice.
        //
        // This makes the seeder what it should have been: first-run content.
        // It establishes the catalog on a fresh install and never overwrites a
        // living one. The trade is that a seeded deployment does not pick up
        // later changes to this file, which is correct — those are a migration's
        // job when they need to reach existing rows.
        foreach ($plans as $plan) {
            $row = Plan::firstOrCreate(['slug' => $plan['slug']], $plan);
            self::backfillOromo($row, $plan);
        }
    }

    /**
     * The one exception to first-run-only: a plan created before the Oromo
     * columns existed gets the built-in Oromo, field by field, while that
     * field is empty and the English beside it is still the seeded English.
     * An operator who rewrote the English has a description the translation
     * no longer matches, so that field stays empty and shows their English.
     *
     * @param  array<string, mixed>  $seeded
     */
    private static function backfillOromo(Plan $row, array $seeded): void
    {
        foreach (['description', 'marketing_features'] as $field) {
            if ($row->getAttribute("{$field}_om") === null && $row->getAttribute($field) === $seeded[$field]) {
                $row->setAttribute("{$field}_om", $seeded["{$field}_om"]);
            }
        }

        if ($row->isDirty()) {
            $row->save();
        }
    }
}
