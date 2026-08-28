<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class TenantPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission('admin.manage');
    }

    public function manageSettings(User $user): bool
    {
        return $user->hasPermission('settings.manage');
    }

    public function manageBilling(User $user): bool
    {
        return $user->hasPermission('billing.manage');
    }

    public function viewExecutiveDashboard(User $user): bool
    {
        return $user->hasPermission('dashboard.executive');
    }

    public function generateReport(User $user): bool
    {
        return $user->hasPermission('report.generate');
    }
}
