<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RetirementCaseStatus;
use App\Enums\RetirementDecision;
use App\Enums\RetirementType;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetirementCase extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'retirement_type',
        'status',
        'service_years',
        'eligible_retirement_date',
        'reason',
        'notes',
        'initiated_by',
        'decision',
        'decision_notes',
        'decided_at',
        'decided_by',
        'finalized_at',
        'finalized_transition_id',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'retirement_type' => RetirementType::class,
            'status' => RetirementCaseStatus::class,
            'service_years' => 'decimal:2',
            'eligible_retirement_date' => 'date',
            'decision' => RetirementDecision::class,
            'decided_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<EmployeeTransition, $this> */
    public function finalizedTransition(): BelongsTo
    {
        return $this->belongsTo(EmployeeTransition::class, 'finalized_transition_id');
    }
}
