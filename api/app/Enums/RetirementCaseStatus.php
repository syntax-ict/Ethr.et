<?php

declare(strict_types=1);

namespace App\Enums;

enum RetirementCaseStatus: string
{
    case INITIATED = 'initiated';
    case UNDER_REVIEW = 'under_review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case FINALIZED = 'finalized';
    case CANCELLED = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** @return self[] */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::INITIATED => [self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::CANCELLED],
            self::UNDER_REVIEW => [self::APPROVED, self::REJECTED, self::CANCELLED],
            self::APPROVED => [self::FINALIZED, self::CANCELLED],
            self::REJECTED, self::FINALIZED, self::CANCELLED => [],
        };
    }
}
