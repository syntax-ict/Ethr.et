<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a job application stands. Every move is a validated transition, and
 * `hired` is reachable only through the hire endpoint, which creates the
 * employee record in the same transaction - never by setting the stage.
 */
enum ApplicationStage: string
{
    case APPLIED = 'applied';
    case SCREENING = 'screening';
    case INTERVIEW = 'interview';
    case OFFER = 'offer';
    case HIRED = 'hired';
    case REJECTED = 'rejected';
    case WITHDRAWN = 'withdrawn';

    public function isClosed(): bool
    {
        return in_array($this, [self::HIRED, self::REJECTED, self::WITHDRAWN], true);
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->allowedMoves(), true);
    }

    /**
     * Moves the stage endpoint accepts. `hired` is absent on purpose.
     *
     * @return self[]
     */
    public function allowedMoves(): array
    {
        return match ($this) {
            self::APPLIED => [self::SCREENING, self::REJECTED, self::WITHDRAWN],
            self::SCREENING => [self::INTERVIEW, self::REJECTED, self::WITHDRAWN],
            self::INTERVIEW => [self::OFFER, self::REJECTED, self::WITHDRAWN],
            self::OFFER => [self::REJECTED, self::WITHDRAWN],
            self::HIRED, self::REJECTED, self::WITHDRAWN => [],
        };
    }
}
