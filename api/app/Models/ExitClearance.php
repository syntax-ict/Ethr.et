<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An employee's exit checklist (blueprint L10), started when they resign, are
 * terminated or retire, from the organisation's template at that moment.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int|null $employee_transition_id
 * @property string $exit_status
 * @property Carbon|null $leaving_date
 * @property string $status
 * @property int|null $started_by
 * @property Carbon|null $completed_at
 * @property-read Employee|null $employee
 */
class ExitClearance extends Model
{
    use BelongsToTenant, HasPublicId;

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'employee_transition_id',
        'exit_status',
        'leaving_date',
        'status',
        'started_by',
        'completed_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'employee_transition_id',
        'started_by',
    ];

    protected function casts(): array
    {
        return [
            'leaving_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<ExitClearanceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ExitClearanceItem::class)->orderBy('position');
    }
}
