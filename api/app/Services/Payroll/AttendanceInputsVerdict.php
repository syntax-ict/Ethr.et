<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * What AttendanceInputsCheck found: how many people's pay-moving attendance
 * figures differ from the ones their entry logged, and whether the request's
 * time ran out before every person who might differ was checked.
 */
final readonly class AttendanceInputsVerdict
{
    public function __construct(
        public int $people,
        public bool $timedOut,
    ) {}
}
