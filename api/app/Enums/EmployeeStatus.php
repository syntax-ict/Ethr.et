<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeStatus: string
{
    case HIRED = 'hired';
    case PROBATION = 'probation';
    case CONFIRMED = 'confirmed';
    case SUSPENDED = 'suspended';
    case RESIGNED = 'resigned';
    case TERMINATED = 'terminated';
    case RETIRED = 'retired';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** @return self[] */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::HIRED => [self::PROBATION, self::CONFIRMED],
            self::PROBATION => [self::CONFIRMED, self::TERMINATED],
            self::CONFIRMED => [self::SUSPENDED, self::RESIGNED, self::TERMINATED, self::RETIRED],
            self::SUSPENDED => [self::CONFIRMED, self::TERMINATED],
            self::RESIGNED, self::TERMINATED, self::RETIRED => [],
        };
    }
}
