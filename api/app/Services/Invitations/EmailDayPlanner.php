<?php

declare(strict_types=1);

namespace App\Services\Invitations;

use Illuminate\Support\Carbon;

/**
 * Hands out one organisation's email invitation slots, one call per invitation.
 * Each call returns null for "send today" or the instant to send on a later
 * day: 08:00 in Addis Ababa plus a minute for every `perMinute` invitations
 * already planned that day, so a held day keeps the same spacing as a live one.
 *
 * Made by EmailInvitationCeiling::planner(); it reads nothing itself.
 */
final class EmailDayPlanner
{
    /**
     * @param  int|null  $limit  Invitations one day may hold, or null for no ceiling.
     * @param  array<string, int>  $used  Invitations already using each Addis Ababa day, by Y-m-d.
     */
    public function __construct(
        private readonly ?int $limit,
        private array $used,
        private readonly int $perMinute,
        private readonly bool $fromTomorrow = false,
    ) {}

    public function next(): ?Carbon
    {
        if ($this->limit === null) {
            return null;
        }

        $day = now(EmailInvitationCeiling::ZONE)->startOfDay();
        if ($this->fromTomorrow) {
            $day = $day->addDay();
        }

        while (true) {
            $key = $day->toDateString();
            $count = $this->used[$key] ?? 0;

            if ($count < $this->limit) {
                $this->used[$key] = $count + 1;

                return $day->isToday() ? null : $day->copy()
                    ->setTime(EmailInvitationCeiling::SEND_HOUR, 0)
                    ->addMinutes(intdiv($count, $this->perMinute))
                    ->setTimezone((string) config('app.timezone', 'UTC'));
            }

            $day = $day->addDay();
        }
    }
}
