<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

/**
 * One reading of every Undecided rule. The oracle answers under the baseline
 * and again under each single alternative; a field that changes between them
 * is Undecided, a field that does not is answered.
 */
final class Readings
{
    /** @param array<string, mixed> $flags */
    public function __construct(private readonly array $flags = []) {}

    /**
     * Every alternative reading, with the rule it belongs to.
     *
     * @return list<array{0: string, 1: self}>
     */
    public static function alternatives(): array
    {
        return [
            ['AR-CLOCK-06', new self(['minutes' => true])],
            ['AR-STATUS-07', new self(['grace_minutes' => true])],
            ['AR-CLOCK-07', new self(['cut_per_kind' => true])],
            ['AR-NIGHT-05', new self(['close_exclusive' => true])],
            ['AR-NIGHT-05', new self(['auto_at_end_open' => true])],
            ['AR-NIGHT-05', new self(['auto_noshift_closes' => true])],
            ['AR-ABS-08', new self(['leave_day_off' => true])],
            ['AR-OT-13', new self(['calendar_day_class' => true])],
            ['AR-OT-14', new self(['pairwise_overlap' => true])],
            ['AR-OT-14', new self(['longest' => 'worked'])],
            ['AR-OT-14', new self(['longest' => 'overtime'])],
            ['AR-MIN-08', new self(['break_once_per_day' => true])],
            ['AR-DAY-08', new self(['check_ins_only' => true])],
        ];
    }

    public function flag(string $name): mixed
    {
        return $this->flags[$name] ?? false;
    }

    public function longest(): string
    {
        return (string) ($this->flags['longest'] ?? 'span');
    }

    /** A window "at most N": to the second, or in whole minutes cut down (AR-CLOCK-06). */
    public function atMost(int $seconds, int $limitSeconds): bool
    {
        return $this->flag('minutes') ? intdiv($seconds, 60) <= intdiv($limitSeconds, 60) : $seconds <= $limitSeconds;
    }

    /** Inside a closing window of `$limitSeconds`; the exact boundary is AR-NIGHT-05. */
    public function inside(int $seconds, int $limitSeconds): bool
    {
        if (! $this->flag('close_exclusive')) {
            return $this->atMost($seconds, $limitSeconds);
        }

        return $this->flag('minutes') ? intdiv($seconds, 60) < intdiv($limitSeconds, 60) : $seconds < $limitSeconds;
    }
}
