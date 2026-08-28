<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use App\Traits\NeverDelete;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property AttendanceSource $source
 * @property AttendanceStatus $status
 * @property Carbon|null $check_in
 * @property Carbon|null $check_out
 */
class AttendanceRecord extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId, NeverDelete;

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

    /** @return BelongsTo<Employee, $this> */
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
        $window = $this->overtimeWindow();

        return $window === null ? 0 : (int) $window[0]->diffInMinutes($window[1]);
    }

    /**
     * The worked-past-shift-end interval that counts as overtime, or null when
     * there is none. Single source of truth for both the total OT minutes and
     * the per-type (day/night, holiday) classification done in payroll.
     *
     * @return array{0: Carbon, 1: Carbon}|null [start, end]
     */
    public function overtimeWindow(): ?array
    {
        if (! $this->shift || ! $this->check_in || ! $this->check_out) {
            return null;
        }

        $shiftEnd = $this->check_in->copy()->setTimeFromTimeString($this->shift->end_time);

        if ($this->shift->crosses_midnight && $shiftEnd->lte($this->check_in)) {
            $shiftEnd->addDay();
        }

        if ($this->check_out->gt($shiftEnd)) {
            return [$shiftEnd, $this->check_out];
        }

        return null;
    }
}
