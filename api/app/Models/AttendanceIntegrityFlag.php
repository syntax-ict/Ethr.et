<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgScope;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something about a punch that cannot be trusted on its face, waiting for a
 * person to accept it or reject it. Rejecting voids the punch.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $attendance_record_id
 * @property int $employee_id
 * @property string $punch
 * @property string $type
 * @property string $severity
 * @property array<string, mixed>|null $details
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property-read AttendanceRecord|null $record
 * @property-read Employee|null $employee
 * @property-read User|null $reviewer
 */
class AttendanceIntegrityFlag extends Model
{
    use BelongsToTenant, HasPublicId;

    public const SEVERITIES = ['low', 'medium', 'high'];

    public const STATUSES = ['open', 'accepted', 'rejected'];

    /**
     * The punches a flag can be about whose rejection voids the day's record.
     * A flag filed against a `presence` check or a `pattern` is a finding about
     * conduct: the reviewer decides it, but no punch is thrown away for it.
     */
    public const VOIDING_PUNCHES = ['check_in', 'check_out', 'day'];

    protected $fillable = [
        'tenant_id',
        'attendance_record_id',
        'employee_id',
        'punch',
        'type',
        'severity',
        'details',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * The flags this user's org scope reaches: the queue, its summary and the
     * Next Steps count all read it, so the three cannot disagree.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->orgScope() !== OrgScope::ALL) {
            $query->whereHas('employee', fn ($q) => $user->scopeAccessibleEmployees($q));
        }

        return $query;
    }

    /**
     * The flags still waiting for a decision that this user's org scope
     * reaches. The queue's summary, the sidebar badge and the Next Steps count
     * all start here, so the three cannot disagree.
     *
     * @return Builder<self>
     */
    public static function openFor(User $user): Builder
    {
        return static::query()->visibleTo($user)->where('status', 'open');
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

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
