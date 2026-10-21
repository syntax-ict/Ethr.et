<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An employment letter an employee asked HR for (see the
 * create_employment_letter_requests_table migration).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $employee_id
 * @property string $purpose
 * @property string|null $addressed_to
 * @property bool $include_salary
 * @property string $language
 * @property string $status
 * @property string|null $reference
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $rejection_reason
 * @property Carbon|null $created_at
 * @property-read Employee|null $employee
 * @property-read User|null $decider
 */
class EmploymentLetterRequest extends Model
{
    use BelongsToTenant, HasPublicId;

    public const PENDING = 'pending';

    public const ISSUED = 'issued';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'purpose',
        'addressed_to',
        'include_salary',
        'language',
        'status',
        'reference',
        'decided_by',
        'decided_at',
        'rejection_reason',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'include_salary' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
