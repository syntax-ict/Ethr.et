<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use Carbon\CarbonInterface;

/**
 * What a punch came to, in the words the person at the screen needs: checked
 * in on time or late (and by how much), checked out or early (and by how
 * much), or "you are already in", with which method did it and when.
 *
 * Several ways of punching share one record a day. A second method reporting
 * the same arrival or departure changes nothing, and says so here instead of
 * looking like a fresh success. A screen reads `code`; `minutes`, `by` and
 * `at` fill in the sentence.
 */
final readonly class PunchOutcome
{
    public const CHECKED_IN = 'checked_in';

    public const CHECKED_IN_LATE = 'checked_in_late';

    /** A second session after the first was closed; kept beside it and flagged. */
    public const CHECKED_IN_AGAIN = 'checked_in_again';

    public const ALREADY_CHECKED_IN = 'already_checked_in';

    public const CHECKED_OUT = 'checked_out';

    public const CHECKED_OUT_EARLY = 'checked_out_early';

    public const ALREADY_CHECKED_OUT = 'already_checked_out';

    /**
     * @param  self::*  $code
     * @param  int  $minutes  minutes late or early, otherwise 0
     * @param  AttendanceSource|null  $by  the method behind the punch already on record
     * @param  CarbonInterface|null  $at  when that punch was made
     */
    public function __construct(
        public string $code,
        public int $minutes = 0,
        public ?AttendanceSource $by = null,
        public ?CarbonInterface $at = null,
    ) {}

    /**
     * The shape a punch answer carries, for an API resource, which has no
     * instance to hand when the server sent no outcome. Declared here because
     * the API contract is built from this signature.
     *
     * @return array{code: string, minutes: int, by: string|null, at: string|null}|null
     */
    public static function describe(?self $outcome): ?array
    {
        return $outcome?->toArray();
    }

    /**
     * @return array{code: string, minutes: int, by: string|null, at: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'minutes' => $this->minutes,
            'by' => $this->by?->value,
            'at' => $this->at?->toIso8601String(),
        ];
    }
}
