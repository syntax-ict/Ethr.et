<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRecord extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'shift_id',
        'date',
        'check_in',
        'check_out',
        'source',
        'confidence_score',
        'latitude',
        'longitude',
        'geofence_verified',
        'photo_path',
        'device_id',
        'status',
        'offline_token',
        'idempotency_key',
        'metadata',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'shift_id',
        'device_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => AttendanceSource::class,
            'status' => AttendanceStatus::class,
            'date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'confidence_score' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_verified' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function workedMinutes(): int
    {
        if (! $this->check_in || ! $this->check_out) {
            return 0;
        }

        $total = $this->check_in->diffInMinutes($this->check_out);
        $breakMinutes = $this->shift?->break_minutes ?? 0;

        return max(0, (int) $total - $breakMinutes);
    }

    public function overtimeMinutes(): int
    {
        if (! $this->shift || ! $this->check_out) {
            return 0;
        }

        $shiftEnd = $this->check_in->copy()->setTimeFromTimeString($this->shift->end_time);

        if ($this->shift->crosses_midnight && $shiftEnd->lte($this->check_in)) {
            $shiftEnd->addDay();
        }

        if ($this->check_out->gt($shiftEnd)) {
            return (int) $shiftEnd->diffInMinutes($this->check_out);
        }

        return 0;
    }
}
