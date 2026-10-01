<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PayrollRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payroll run was approved: its payslips are final and may be released to
 * the employees on it.
 */
class PayrollApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PayrollRun $payrollRun,
    ) {}
}
