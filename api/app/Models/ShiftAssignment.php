<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CurrentTenant;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property Carbon|null $anchor_date
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 */
class ShiftAssignment extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $fillable = [
        'tenant_id',
        'shift_id',
        'shift_rotation_id',
        'assignable_type',
        'assignable_id',
        'effective_from',
        'effective_to',
        'anchor_date',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'anchor_date' => 'date',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<ShiftRotation, $this> */
    public function rotation(): BelongsTo
    {
        return $this->belongsTo(ShiftRotation::class, 'shift_rotation_id');
    }

    /**
     * A row holds either a fixed shift or a rotation, never both.
     */
    public function isRotation(): bool
    {
        return $this->shift_rotation_id !== null;
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Everything ShiftAssignmentResource reads, for every place that returns
     * one. Before this, `schedule()` loaded `shift` but not `rotation`, so a
     * rotation assignment arrived with neither and the pages showed
     * "Unknown Shift".
     *
     * Trashed rows are included on purpose. An assignment that has ended is
     * history, and the shift, rotation, employee, department or branch it
     * named may since have been deleted; the row should still say what it was
     * rather than go blank. Each relation still carries the tenant scope —
     * `withTrashed()` removes only the soft-delete scope. On the morph it is
     * buffered and replayed against each of the three assignable models, all
     * of which soft-delete.
     *
     * @return array<string, \Closure>
     */
    public static function resourceRelations(): array
    {
        return [
            'shift' => fn ($query) => $query->withTrashed(),
            'rotation' => fn ($query) => $query->withTrashed(),
            'assignable' => fn ($query) => $query->withTrashed(),
        ];
    }

    /**
     * In force on $date or starting after it: no end date, or one on or after
     * $date. `effective_to` is the last day the assignment applies.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForceOnOrAfter(Builder $query, string $date): Builder
    {
        return $query->where(function (Builder $q) use ($date) {
            $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date);
        });
    }

    /**
     * Today's date in the current tenant's timezone, as `Y-m-d`.
     *
     * `effective_from` and `effective_to` are calendar dates in the tenant's
     * day, and the app clock is UTC: for the first three hours of an Addis
     * Ababa day, UTC is still on yesterday.
     */
    public static function tenantToday(): string
    {
        $timezone = app(CurrentTenant::class)->get()?->timezone;

        return Carbon::now(is_string($timezone) && $timezone !== '' ? $timezone : 'Africa/Addis_Ababa')
            ->toDateString();
    }
}
