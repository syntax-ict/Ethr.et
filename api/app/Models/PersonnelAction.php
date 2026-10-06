<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonnelActionType;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded personnel action in an employee's employment history.
 *
 * `changes` is a snapshot map keyed by dimension, each holding `{from, to}` with
 * human-readable labels for relational fields (position/grade/unit names) and
 * raw values for salary/step — so the history reads correctly even years later
 * when the referenced records have changed. Append-only by convention: history
 * is never edited, only added to.
 *
 * Larastan cannot introspect this table (the JSON `changes` column), so property
 * types are declared here rather than inferred from the schema.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $employee_id
 * @property PersonnelActionType $type
 * @property Carbon $effective_date
 * @property Carbon|null $end_date
 * @property string|null $reference_number
 * @property string|null $reason
 * @property string|null $remarks
 * @property array<string, array{from: mixed, to: mixed}> $changes
 * @property bool $is_temporary
 * @property int|null $recorded_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Employee $employee
 * @property-read User|null $recordedBy
 */
class PersonnelAction extends Model
{
    use BelongsToTenant, HasAuditLog, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'type',
        'effective_date',
        'end_date',
        'reference_number',
        'reason',
        'remarks',
        'changes',
        'is_temporary',
        'recorded_by',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => PersonnelActionType::class,
            'effective_date' => 'date',
            'end_date' => 'date',
            'changes' => 'array',
            'is_temporary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
