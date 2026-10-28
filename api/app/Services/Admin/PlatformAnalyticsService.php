<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\Carbon;

final class PlatformAnalyticsService
{
    /**
     * Subscription states that are still a customer paying, or owing, the
     * period's price. A trial pays nothing yet, and a paused or cancelled
     * subscription bills nothing.
     */
    private const BILLING_STATUSES = [SubscriptionStatus::ACTIVE->value, SubscriptionStatus::PAST_DUE->value];

    public function revenue(): array
    {
        // A deleted organisation is no longer a customer of any kind.
        $tenants = Tenant::withoutGlobalScopes()->whereNull('deleted_at')->get();

        $totalTenants = $tenants->count();
        $activeTenants = $tenants->where('status', TenantStatus::ACTIVE)->count();
        $trialTenants = $tenants->where('status', TenantStatus::TRIAL)->count();
        $suspendedTenants = $tenants->where('status', TenantStatus::SUSPENDED)->count();
        $cancelledTenants = $tenants->where('status', TenantStatus::CANCELLED)->count();

        $billed = $this->billedPrices($tenants->where('status', TenantStatus::ACTIVE)->pluck('id')->all());

        $collected = $this->collectedByMonth(11);
        $monthlyTrend = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->format('Y-m');
            $monthlyTrend[] = [
                'month' => $month,
                'revenue_cents' => (int) ($collected[$month] ?? 0),
            ];
        }

        $trialConverted = $tenants->where('status', TenantStatus::ACTIVE)
            ->whereNotNull('trial_ends_at')
            ->count();

        $conversionRate = $trialTenants + $trialConverted > 0
            ? round(($trialConverted / ($trialTenants + $trialConverted)) * 100, 1)
            : 0;

        return [
            'mrr_cents' => array_sum($billed),
            'collected_this_month_cents' => (int) ($collected[Carbon::now()->format('Y-m')] ?? 0),
            'total_tenants' => $totalTenants,
            'active_tenants' => $activeTenants,
            'paying_tenants' => count(array_filter($billed, fn (int $cents): bool => $cents > 0)),
            'trial_tenants' => $trialTenants,
            'suspended_tenants' => $suspendedTenants,
            'cancelled_tenants' => $cancelledTenants,
            'conversion_rate' => $conversionRate,
            'monthly_trend' => $monthlyTrend,
        ];
    }

    /**
     * What each active organisation is billed per month, keyed by tenant id.
     *
     * `mrr_cents` was this month's paid invoices until 2026-10-28, which is cash
     * collected, not recurring revenue: production read 0 ETB beside one active
     * customer, and a month in which nobody happened to pay read the same as a
     * month with no customers. The recurring figure is the price each
     * subscription carries, the same `effectivePriceCents()` an invoice is cut
     * at. An organisation on a free plan is active but not paying, which is why
     * `paying_tenants` counts prices above zero rather than active statuses.
     *
     * The platform view has no tenant resolved, so the scope would fail closed
     * and count nobody. The predicate is the active tenants' ids, stated on the
     * same chain.
     *
     * @param  list<int>  $tenantIds
     * @return array<int, int>
     */
    private function billedPrices(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $latest = Subscription::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->with('plan')
            ->orderBy('id')
            ->get()
            ->keyBy('tenant_id');

        $prices = [];
        foreach ($latest as $tenantId => $subscription) {
            // The stored value, not the enum cast: Larastan types cast
            // properties as their backing column, the limitation
            // `Subscription::effectivePriceCents()` records.
            if (in_array($subscription->getRawOriginal('status'), self::BILLING_STATUSES, true)) {
                $prices[(int) $tenantId] = $subscription->effectivePriceCents();
            }
        }

        return $prices;
    }

    /**
     * Paid invoice totals by `Y-m`, from the start of the month `$monthsBack`
     * months ago. One query, grouped here, where it was twelve plus one for the
     * current month; the month is formatted in PHP so SQLite and MariaDB agree.
     *
     * @return array<string, int>
     */
    private function collectedByMonth(int $monthsBack): array
    {
        $totals = [];

        Invoice::withoutGlobalScopes()
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', Carbon::now()->subMonths($monthsBack)->startOfMonth())
            ->get(['paid_at', 'total_cents'])
            ->each(function (Invoice $invoice) use (&$totals): void {
                $month = Carbon::parse($invoice->getAttribute('paid_at'))->format('Y-m');
                $totals[$month] = ($totals[$month] ?? 0) + (int) $invoice->getAttribute('total_cents');
            });

        return $totals;
    }
}
