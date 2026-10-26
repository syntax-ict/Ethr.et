<?php

declare(strict_types=1);

namespace App\Services\NextSteps;

/**
 * What `GET /next-steps` answers with: the steps this person should see, in
 * the order to show them, and how far the guided setup has got for someone who
 * may run it.
 */
final class NextStepsResult
{
    /**
     * @param  list<NextStep>  $steps
     * @param  array{done: int, total: int, complete: bool}|null  $setup
     */
    public function __construct(
        public readonly array $steps,
        public readonly ?array $setup,
    ) {}
}
