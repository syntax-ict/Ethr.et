<?php

declare(strict_types=1);

namespace App\Services\NextSteps;

use App\Enums\NextStepGroup;
use App\Enums\NextStepId;
use App\Enums\NextStepSeverity;

/**
 * One row of the Next Steps list: an id and the numbers behind it, never the
 * words. The frontend owns the copy in both languages, as it does for
 * `AttendanceHealth`.
 */
final class NextStep
{
    /**
     * @param  string  $signature  What the step looked like when it was built, in a
     *                             form that changes when the state behind it changes.
     *                             A dismissal is remembered against it.
     * @param  list<string>|null  $items  The parts a checklist step is made of that
     *                                    still need doing (the profile fields missing).
     * @param  string|null  $subject  A label the copy needs, such as a payroll period.
     */
    public function __construct(
        public readonly NextStepId $id,
        public readonly string $href,
        public readonly string $signature,
        public readonly int $count = 1,
        public readonly ?int $total = null,
        public readonly ?array $items = null,
        public readonly ?string $subject = null,
    ) {}

    public function group(): NextStepGroup
    {
        return $this->id->group();
    }

    public function severity(): NextStepSeverity
    {
        return $this->id->severity();
    }
}
