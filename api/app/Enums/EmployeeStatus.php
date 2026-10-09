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

    /**
     * The statuses of someone who currently works here and is reachable as a
     * colleague.
     *
     * The same three the headcount figures already count
     * (`ExecutiveDashboardService::ACTIVE_STATUSES`, and the branch and
     * department analytics), so a list built from this agrees with the
     * headcount beside it. RESIGNED, TERMINATED and RETIRED have left and have
     * no onward transition. SUSPENDED is excluded too: it is where SCIM
     * deprovisioning puts an account (`ScimUserController`), so treating it as
     * current would keep publishing a deprovisioned person's contact details.
     *
     * @return list<self>
     */
    public static function current(): array
    {
        return [self::HIRED, self::PROBATION, self::CONFIRMED];
    }

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
