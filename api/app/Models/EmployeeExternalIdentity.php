<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single external identifier (device user id, card, import UUID, …) mapped to
 * one internal employee. See the migration and ONBOARDING_V2.md D4.
 */
class EmployeeExternalIdentity extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'source_type',
        'source_ref',
        'identifier_type',
        'identifier_value',
        'confidence',
        'verified_at',
        'metadata',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
