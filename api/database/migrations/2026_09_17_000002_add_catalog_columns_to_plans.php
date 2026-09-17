<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns a platform admin needs to own the plan catalog.
 *
 * Today the public pricing page renders `name`, `price_cents` and three limits,
 * and everything else it shows — the currency, the billing period, the sentence
 * under each plan name, which card is highlighted, the sales bullets — is
 * hardcoded in the component. That is the defect this branch exists to remove:
 * a fabricated figure in a component is nobody's job to correct, while an empty
 * column has an owner.
 *
 * Each column and why it is not something that already exists:
 *
 *   currency            Every price in the system is ETB by assumption and
 *                       nothing records it. Phase 2 deliberately did NOT add
 *                       this to `subscriptions`, because with no catalog
 *                       currency it could only be populated from a hardcoded
 *                       literal — inventing a fact to store. Here it has an
 *                       owner who can set it, which is what makes it real.
 *
 *   billing_interval    `GenerateMonthlyInvoicesJob` bills monthly and nothing
 *                       else exists, so this is descriptive, not yet
 *                       enforced — see the constraint note below.
 *
 *   description(_am)    One sentence under the plan name. Paired `_am` column
 *                       per the plan's D3, following `payment_instructions` /
 *                       `payment_instructions_am` on `platform_settings`, which
 *                       is this repo's established shape for bilingual
 *                       admin-edited content: typed, individually validatable,
 *                       already understood here.
 *
 *   is_public           NOT a synonym for `is_active`. Active means "may be
 *                       subscribed to"; public means "appears on the pricing
 *                       page". A grandfathered or negotiated plan is the first
 *                       and not the second, and conflating them would force an
 *                       operator to choose between advertising a bespoke price
 *                       and cancelling the customer on it.
 *
 *   is_popular          Which card gets the badge. Hardcoded to Professional
 *                       in the component today.
 *
 *   marketing_features  Sales copy, and deliberately NOT the existing
 *   (_am)               `features` column. That one holds `App\Enums\PlanFeature`
 *                       keys which `RequiresPlanFeature` middleware gates real
 *                       routes on — the enum's own docblock warns that renaming
 *                       one is a data migration. Letting an admin edit sales
 *                       copy through the same field would let them grant or
 *                       revoke a product capability by writing a bullet point.
 *
 * `billing_interval` carries no CHECK constraint because SQLite (the test
 * database) and MariaDB (production) disagree on how to alter one later, and
 * the suite runs on both. `StorePlanRequest` holds the `in:` rule instead, so
 * the enforcement is in one place rather than half in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('currency', 3)->default('ETB')->after('price_cents');
            $table->string('billing_interval', 20)->default('monthly')->after('currency');
            $table->string('description', 500)->nullable()->after('slug');
            $table->string('description_am', 500)->nullable()->after('description');
            $table->boolean('is_public')->default(true)->after('is_active');
            $table->boolean('is_popular')->default(false)->after('is_public');
            $table->json('marketing_features')->nullable()->after('features');
            $table->json('marketing_features_am')->nullable()->after('marketing_features');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'currency',
                'billing_interval',
                'description',
                'description_am',
                'is_public',
                'is_popular',
                'marketing_features',
                'marketing_features_am',
            ]);
        });
    }
};
