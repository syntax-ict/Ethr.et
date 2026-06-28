<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

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

    public function isWorkingDay(int $dayOfWeek): bool
    {
        return in_array($dayOfWeek, $this->workingDaysArray());
    }
}
