<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostSharingStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Database\Factories\EmployeeCostSharingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One employee's Ethiopian higher-education cost-sharing obligation.
 *
 * Property types are declared rather than left to inference: Larastan reads
 * `status` as a plain string from the schema, which silently defeats every
 * `$obligation->status->isDeductible()` call in the payroll path.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int $total_obligation_cents
 * @property int $outstanding_cents
 * @property string $deduction_rate_percent
 * @property CostSharingStatus $status
 * @property Carbon $started_on
 * @property Carbon|null $completed_at
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Employee $employee
 */
class EmployeeCostSharing extends Model
{
    /** @use HasFactory<EmployeeCostSharingFactory> */
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    /**
     * Laravel would pluralise this to `employee_cost_sharings`. "Cost sharing"
     * is a mass noun — the scheme, not a countable thing — so the table keeps
     * the singular form and the mapping is stated here.
     */
    protected $table = 'employee_cost_sharing';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'total_obligation_cents',
        'outstanding_cents',
        'deduction_rate_percent',
        'status',
        'started_on',
        'completed_at',
        'notes',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'total_obligation_cents' => 'integer',
            'outstanding_cents' => 'integer',
            // Left as a string rather than 'float': it is used as a multiplier
            // against integer cents, and binary floats are the one thing
            // CLAUDE.md's integer-currency rule exists to keep out of that path.
            'deduction_rate_percent' => 'decimal:2',
            'status' => CostSharingStatus::class,
            'started_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
