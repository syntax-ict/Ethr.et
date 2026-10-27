<?php

declare(strict_types=1);

namespace App\Services\NextSteps\Providers;

use App\Enums\NextStepId;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Approval\DecidableApprovals;
use App\Services\NextSteps\NextStep;
use App\Services\NextSteps\StepProvider;

/**
 * Requests waiting on this person's decision.
 *
 * `DecidableApprovals` is the one definition of that, shared with the approvals
 * queue and the manager dashboard. It answers for anyone who may approve, not
 * only for someone who supervises: an HR admin with no direct reports has a
 * queue too.
 */
final class ApprovalsProvider implements StepProvider
{
    public function __construct(private readonly DecidableApprovals $approvals) {}

    public function steps(User $user, Tenant $tenant): array
    {
        $total = $this->approvals->counts($user)['total'];

        if ($total === 0) {
            return [];
        }

        return [new NextStep(NextStepId::Approvals, '/approvals', (string) $total, $total)];
    }
}
