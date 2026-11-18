<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A "are you at work?" push sent to one person during one shift, and what
 * they answered. Its times are derived by PresenceChecks; a row exists only
 * once the push has gone out.
 *
 * Status: `sent` (waiting), `confirmed` (answered from a workplace),
 * `outside` (answered from somewhere else), `unverified` (answered from a
 * phone the organisation does not trust), `missed` (not answered in time).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int $attendance_record_id
 * @property int $slot
 * @property Carbon $sent_at
 * @property Carbon $expires_at
 * @property Carbon|null $answered_at
 * @property string $status
 * @property string|null $latitude
 * @property string|null $longitude
 * @property float|null $accuracy
 * @property int|null $distance_meters
 * @property string|null $device_verdict
 * @property string|null $idempotency_key
 * @property-read AttendanceRecord|null $record
 * @property-read Employee|null $employee
 */
class AttendancePresenceCheck extends Model
{
    use BelongsToTenant, HasPublicId;

    public const SENT = 'sent';

    public const CONFIRMED = 'confirmed';

    public const OUTSIDE = 'outside';

    public const UNVERIFIED = 'unverified';

    public const MISSED = 'missed';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'attendance_record_id',
        'slot',
        'sent_at',
        'expires_at',
        'answered_at',
        'status',
        'latitude',
        'longitude',
        'accuracy',
        'distance_meters',
        'device_verdict',
        'idempotency_key',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'attendance_record_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'answered_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'float',
            'distance_meters' => 'integer',
        ];
    }

    /** @return BelongsTo<AttendanceRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
