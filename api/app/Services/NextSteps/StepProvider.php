<?php

declare(strict_types=1);

namespace App\Services\NextSteps;

use App\Models\Tenant;
use App\Models\User;

/**
 * One source of next steps. A provider guards itself: it checks the
 * permission, the org scope and the plan its own numbers need, and returns
 * nothing for someone who has no business seeing them. The endpoint has no
 * single permission because there is no single audience.
 */
interface StepProvider
{
    /** @return list<NextStep> */
    public function steps(User $user, Tenant $tenant): array;
}
