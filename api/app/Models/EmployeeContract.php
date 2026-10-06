<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Larastan resolves enum-cast columns to their raw backing type (`string`)
 * rather than the enum here — the same behaviour as `Employee::$status`
 * (see `EmployeeTransitionController`'s baselined entries) — so property
 * types are declared explicitly instead of relying on cast inference.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $employee_id
 * @property string|null $reference_number
 * @property ContractType $contract_type
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property int|null $salary_cents
 * @property string|null $terms
 * @property ContractStatus $status
 * @property int|null $renewed_from_id
 * @property Carbon|null $ended_at
 * @property string|null $end_notes
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Employee $employee
 * @property-read self|null $renewedFrom
 * @property-read User|null $createdBy
 */
class EmployeeContract extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'reference_number',
        'contract_type',
        'start_date',
        'end_date',
        'salary_cents',
        'terms',
        'status',
        'renewed_from_id',
        'ended_at',
        'end_notes',
        'created_by',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'contract_type' => ContractType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'salary_cents' => 'integer',
            'status' => ContractStatus::class,
            'ended_at' => 'date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<self, $this> */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    /** @return HasMany<self, $this> */
    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'renewed_from_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
