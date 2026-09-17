<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the agreed price onto the subscription.
 *
 * `BillingService::generateMonthlyInvoice()` resolved the amount live from the
 * plan — `$amount = $plan->price_cents ?? 0` — and `subscriptions` carried no
 * price of its own. The catalog price was therefore not a list price but the
 * amount every existing subscriber would be billed at their next renewal.
 *
 * That is fine while nobody can edit a plan. It stops being fine the moment the
 * platform admin can, which is the point of the work this migration unblocks:
 * editing a marketing number would silently re-price every subscriber on that
 * plan, with no notice and no grandfathering, and `HandleOverdueInvoicesJob`
 * escalates the resulting non-payment to tenant suspension at sixty days.
 *
 * With a price on the subscription, the catalog becomes what it should always
 * have been — what new customers are quoted and what the pricing page
 * advertises. Re-pricing someone who has already signed up becomes a separate,
 * deliberate, auditable act rather than a side effect of editing copy.
 *
 * NULLABLE, DELIBERATELY. A NOT NULL column defaulting to 0 would make a
 * forgotten assignment bill zero silently, which is the worse failure: nobody
 * reports an invoice that is too small. Null instead means "never captured",
 * and `Subscription::effectivePriceCents()` falls back to the plan — today's
 * behaviour — rather than to nothing. The backfill below leaves no null rows,
 * so that path exists for raw inserts, not for real data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->bigInteger('price_cents')->nullable()->after('plan_id');
        });

        // One UPDATE per plan rather than an UPDATE ... JOIN, which is written
        // differently in MySQL and SQLite and the suite runs on both. There are
        // three plans, so the loop is three statements, not N.
        foreach (DB::table('plans')->select('id', 'price_cents')->get() as $plan) {
            DB::table('subscriptions')
                ->where('plan_id', $plan->id)
                ->update(['price_cents' => $plan->price_cents]);
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('price_cents');
        });
    }
};
