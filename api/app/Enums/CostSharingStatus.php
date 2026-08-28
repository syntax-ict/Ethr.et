<?php

declare(strict_types=1);

namespace App\Enums;

enum CostSharingStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * Only an ACTIVE obligation is deducted by the payroll engine. SUSPENDED
     * exists because a real obligation pauses without ending — unpaid leave,
     * a disputed balance, a secondment — and deleting or completing the row to
     * achieve that would destroy the outstanding balance.
     */
    public function isDeductible(): bool
    {
        return $this === self::ACTIVE;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * COMPLETED is reachable only from ACTIVE, and in practice the payroll
     * engine sets it when the balance reaches zero rather than an operator
     * choosing it. CANCELLED is the write-off path (obligation waived or
     * recorded in error) and, unlike COMPLETED, says nothing about the balance.
     *
     * @return self[]
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::ACTIVE => [self::SUSPENDED, self::COMPLETED, self::CANCELLED],
            self::SUSPENDED => [self::ACTIVE, self::CANCELLED],
            self::COMPLETED, self::CANCELLED => [],
        };
    }
}
