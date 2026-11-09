<?php

declare(strict_types=1);

namespace App\Services\NextSteps;

/**
 * What `GET /next-steps` answers with: the steps this person should see, in
 * the order to show them, how far the guided setup has got for someone who
 * may run it, and how ready the organisation is for its first payroll.
 */
final class NextStepsResult
{
    /**
     * @param  list<NextStep>  $steps
     * @param  array{done: int, total: int, complete: bool}|null  $setup
     * @param  array{done: int, total: int, ready: bool}|null  $payroll
     */
    public function __construct(
        public readonly array $steps,
        public readonly ?array $setup,
        public readonly ?array $payroll = null,
    ) {}
}
