<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\TenantStatus;
use App\Models\Invoice;
use App\Models\Tenant;
use Carbon\Carbon;

final class PlatformAnalyticsService
{
    public function revenue(): array
    {
        $tenants = Tenant::withoutGlobalScopes()->get();

        $totalTenants = $tenants->count();
        $activeTenants = $tenants->where('status', TenantStatus::ACTIVE)->count();
        $trialTenants = $tenants->where('status', TenantStatus::TRIAL)->count();
        $suspendedTenants = $tenants->where('status', TenantStatus::SUSPENDED)->count();
        $cancelledTenants = $tenants->where('status', TenantStatus::CANCELLED)->count();

        $paidInvoices = Invoice::withoutGlobalScopes()
            ->whereNotNull('paid_at')
            ->whereMonth('paid_at', Carbon::now()->month)
            ->whereYear('paid_at', Carbon::now()->year)
            ->sum('total_cents');

        $monthlyTrend = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $revenue = Invoice::withoutGlobalScopes()
                ->whereNotNull('paid_at')
                ->whereMonth('paid_at', $month->month)
                ->whereYear('paid_at', $month->year)
                ->sum('total_cents');

            $monthlyTrend[] = [
                'month' => $month->format('Y-m'),
                'revenue_cents' => (int) $revenue,
            ];
        }

        $totalPaidTenants = $tenants->where('status', TenantStatus::ACTIVE)->count();
        $trialConverted = $tenants->where('status', TenantStatus::ACTIVE)
            ->whereNotNull('trial_ends_at')
            ->count();

        $conversionRate = $trialTenants + $trialConverted > 0
            ? round(($trialConverted / ($trialTenants + $trialConverted)) * 100, 1)
            : 0;

        return [
            'mrr_cents' => (int) $paidInvoices,
            'total_tenants' => $totalTenants,
            'active_tenants' => $activeTenants,
            'trial_tenants' => $trialTenants,
            'suspended_tenants' => $suspendedTenants,
            'cancelled_tenants' => $cancelledTenants,
            'conversion_rate' => $conversionRate,
            'monthly_trend' => $monthlyTrend,
        ];
    }
}
