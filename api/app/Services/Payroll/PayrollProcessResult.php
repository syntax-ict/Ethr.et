<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRun;

final readonly class PayrollProcessResult
{
    public function __construct(
        public PayrollRun $run,
        public bool $wasDuplicate = false,
    ) {}
}
