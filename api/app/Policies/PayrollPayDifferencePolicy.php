<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PayrollPayDifference;
use App\Models\User;

/**
 * Seeing the differences needs what seeing payroll runs needs; settling or
 * dismissing one needs what approving a run needs, because approving is what
 * acknowledges them.
 */
class PayrollPayDifferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.viewAll');
    }

    public function settle(User $user, PayrollPayDifference $payDifference): bool
    {
        return $user->hasPermission('payroll.approve');
    }

    public function dismiss(User $user, PayrollPayDifference $payDifference): bool
    {
        return $user->hasPermission('payroll.approve');
    }
}
