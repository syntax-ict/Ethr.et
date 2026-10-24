<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use App\Traits\NeverDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stop a field worker logged during the day. Evidence of work, never a
 * punch: it does not touch attendance records or worked hours.
 *
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $metadata
 */
class AttendanceVisit extends Model
{
    use BelongsToTenant, HasPublicId, NeverDelete;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'attendance_site_id',
        'latitude',
        'longitude',
        'accuracy',
        'occurred_at',
        'note',
        'photo_path',
        'idempotency_key',
        'metadata',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'attendance_site_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'float',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<AttendanceSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AttendanceSite::class, 'attendance_site_id');
    }
}
