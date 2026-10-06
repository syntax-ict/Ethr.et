<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.viewAll');
    }

    public function view(User $user, PayrollRun $payrollRun): bool
    {
        return $user->hasPermission('payroll.viewAll');
    }

    public function viewOwnPayslip(User $user): bool
    {
        return $user->hasPermission('payroll.viewOwnPayslip');
    }

    public function process(User $user): bool
    {
        return $user->hasPermission('payroll.process');
    }

    public function approve(User $user, PayrollRun $payrollRun): bool
    {
        return $user->hasPermission('payroll.approve');
    }

    public function void(User $user, PayrollRun $payrollRun): bool
    {
        return $user->hasPermission('payroll.void');
    }

    public function reprocess(User $user, PayrollRun $payrollRun): bool
    {
        return $user->hasPermission('payroll.reprocess');
    }

    public function manageLoan(User $user): bool
    {
        return $user->hasPermission('payroll.manageLoan');
    }
}
