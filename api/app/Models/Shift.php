<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Shift extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'name',
        'name_am',
        'start_time',
        'end_time',
        'crosses_midnight',
        'grace_minutes',
        'early_departure_minutes',
        'break_minutes',
        'working_days',
        'is_default',
        'is_active',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'grace_minutes' => 'integer',
            'early_departure_minutes' => 'integer',
            'break_minutes' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * `crosses_midnight` is read from the times, never trusted as typed. The
     * flag was an independent input, so a 22:00-06:00 shift saved without it
     * was read as ending at 06:00 the same morning by the engine and payroll
     * while MyShiftService, which also looked at the times, disagreed.
     */
    protected static function booted(): void
    {
        static::saving(function (Shift $shift): void {
            $shift->crosses_midnight = $shift->crossesMidnight();
        });
    }

    /** An end at or before the start is on the next day. */
    public function crossesMidnight(): bool
    {
        return substr((string) $this->end_time, 0, 5) <= substr((string) $this->start_time, 0, 5);
    }

    /**
     * The shift's start and end when worked on $date (Y-m-d), as instants in
     * $zone. The one place a shift's times become moments: lateness, early
     * departure, overtime and the punch assistant all ask this, so they agree
     * about the day a night shift ends.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowOn(string $date, string $zone): array
    {
        $start = Carbon::parse($date.' '.substr((string) $this->start_time, 0, 5), $zone);
        $end = Carbon::parse($date.' '.substr((string) $this->end_time, 0, 5), $zone);

        if ($this->crossesMidnight()) {
            $end->addDay();
        }

        return [$start, $end];
    }

    /** @return HasMany<ShiftAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function workingDaysArray(): array
    {
        return array_map('intval', explode(',', $this->working_days));
    }

    /**
     * `working_days` holds ISO day numbers (1 = Monday … 7 = Sunday), as the
     * shift forms write them. This took a bare int and had no caller; Carbon's
     * `dayOfWeek` is 0-6 with Sunday 0, so passing it would never have matched
     * Sunday. It takes the date now, and payroll uses it to find rest days.
     */
    public function isWorkingDay(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->workingDaysArray(), true);
    }
}
