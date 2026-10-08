<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puts the `custom_domain` add-on on the Enterprise plan of a deployment whose
 * plans were seeded before it existed.
 *
 * The owner made a verified custom domain the Enterprise tier of an
 * organisation's address (2026-10-08). PlanSeeder now writes it, but with
 * firstOrCreate, so an existing `enterprise` row never gains it, and every
 * domain assignment there would be refused until someone edited the plan.
 *
 * Only the plan with slug `enterprise`, and only when it carries a feature
 * list. A NULL list means "unlimited" to every other feature; writing
 * `["custom_domain"]` into it would take all of those away.
 */
return new class extends Migration
{
    private const FEATURE = 'custom_domain';

    public function up(): void
    {
        $this->rewrite(function (array $features): array {
            return in_array(self::FEATURE, $features, true) ? $features : [...$features, self::FEATURE];
        });
    }

    public function down(): void
    {
        $this->rewrite(function (array $features): array {
            return array_values(array_filter($features, fn ($feature) => $feature !== self::FEATURE));
        });
    }

    /** @param  callable(list<string>): list<string>  $change */
    private function rewrite(callable $change): void
    {
        $plan = DB::table('plans')->where('slug', 'enterprise')->first(['id', 'features']);
        if ($plan === null || $plan->features === null) {
            return;
        }

        $features = json_decode((string) $plan->features, true);
        if (! is_array($features)) {
            return;
        }

        $updated = $change(array_values($features));
        if ($updated !== $features) {
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($updated)]);
        }
    }
};
