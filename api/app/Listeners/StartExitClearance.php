<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\EmployeeStatus;
use App\Events\EmployeeTransitioned;
use App\Services\Offboarding\ExitClearanceService;
use Throwable;

/**
 * A resignation, termination or retirement starts the leaver's exit
 * clearance (blueprint L10).
 *
 * Not queued, like CloseLeaverLogin beside it: the checklist should be there
 * when the request that recorded the exit returns. A failure is reported and
 * never fails the exit itself, which matters between a deploy and its
 * `migrate`, when the table does not exist yet.
 */
final class StartExitClearance
{
    public function __construct(private readonly ExitClearanceService $clearances) {}

    public function handle(EmployeeTransitioned $event): void
    {
        if (! in_array($event->transition->to_status, EmployeeStatus::leaving(), true)) {
            return;
        }

        try {
            $this->clearances->start($event->employee, $event->transition, $event->transition->approved_by);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
