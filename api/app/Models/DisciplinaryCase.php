<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisciplinaryAppealStatus;
use App\Enums\DisciplinaryCaseStatus;
use App\Enums\DisciplinaryCategory;
use App\Enums\DisciplinaryDecision;
use App\Enums\DisciplinarySanctionType;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One disciplinary case against an employee: offence → investigation →
 * decision → sanction → optional appeal. `investigation_notes` is an
 * append-only timeline (`{note, by, by_name, at}` per entry) rather than a
 * second table, mirroring `PersonnelAction.changes` — notes are always read
 * alongside their case, never queried independently.
 *
 * Larastan cannot introspect the JSON `investigation_notes` column, so
 * property types are declared here rather than inferred from the schema.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $employee_id
 * @property string|null $reference_number
 * @property DisciplinaryCategory $category
 * @property string $description
 * @property Carbon $incident_date
 * @property DisciplinaryCaseStatus $status
 * @property int|null $reported_by
 * @property array<int, array{note: string, by: int|null, by_name: string|null, at: string}> $investigation_notes
 * @property DisciplinaryDecision|null $decision
 * @property string|null $decision_notes
 * @property Carbon|null $decided_at
 * @property int|null $decided_by
 * @property DisciplinarySanctionType|null $sanction_type
 * @property string|null $sanction_details
 * @property Carbon|null $sanction_effective_date
 * @property DisciplinaryAppealStatus|null $appeal_status
 * @property string|null $appeal_grounds
 * @property Carbon|null $appeal_filed_at
 * @property string|null $appeal_decision_notes
 * @property Carbon|null $appeal_decided_at
 * @property int|null $appeal_decided_by
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Employee $employee
 * @property-read User|null $reportedBy
 * @property-read User|null $decidedBy
 * @property-read User|null $appealDecidedBy
 */
class DisciplinaryCase extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'reference_number',
        'category',
        'description',
        'incident_date',
        'status',
        'reported_by',
        'investigation_notes',
        'decision',
        'decision_notes',
        'decided_at',
        'decided_by',
        'sanction_type',
        'sanction_details',
        'sanction_effective_date',
        'appeal_status',
        'appeal_grounds',
        'appeal_filed_at',
        'appeal_decision_notes',
        'appeal_decided_at',
        'appeal_decided_by',
        'closed_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => DisciplinaryCategory::class,
            'incident_date' => 'date',
            'status' => DisciplinaryCaseStatus::class,
            'investigation_notes' => 'array',
            'decision' => DisciplinaryDecision::class,
            'decided_at' => 'date',
            'sanction_type' => DisciplinarySanctionType::class,
            'sanction_effective_date' => 'date',
            'appeal_status' => DisciplinaryAppealStatus::class,
            'appeal_filed_at' => 'date',
            'appeal_decided_at' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function appealDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appeal_decided_by');
    }
}
