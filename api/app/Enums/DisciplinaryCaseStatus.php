<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The case's own lifecycle stage — distinct from `DisciplinaryDecision`
 * (guilty/not_guilty/inconclusive) and `DisciplinaryAppealStatus`
 * (pending/upheld/denied), which record *outcomes* within a stage rather than
 * the stage itself.
 */
enum DisciplinaryCaseStatus: string
{
    case REPORTED = 'reported';
    case INVESTIGATING = 'investigating';
    case DECIDED = 'decided';
    case APPEALED = 'appealed';
    case CLOSED = 'closed';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** @return self[] */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::REPORTED => [self::INVESTIGATING, self::DECIDED, self::CLOSED],
            self::INVESTIGATING => [self::DECIDED, self::CLOSED],
            // A case can close directly from DECIDED (no appeal filed, or the
            // appeal window passed) or go through APPEALED first.
            self::DECIDED => [self::APPEALED, self::CLOSED],
            self::APPEALED => [self::CLOSED],
            self::CLOSED => [],
        };
    }
}
