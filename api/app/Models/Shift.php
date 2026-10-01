<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId, SoftDeletes;

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
