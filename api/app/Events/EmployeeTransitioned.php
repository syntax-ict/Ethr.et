<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Employee;
use App\Models\EmployeeTransition;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmployeeTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Employee $employee,
        public readonly EmployeeTransition $transition,
    ) {}
}
