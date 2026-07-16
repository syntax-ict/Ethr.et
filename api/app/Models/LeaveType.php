<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccrualType;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property AccrualType $accrual_type */
class LeaveType extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'name',
        'name_am',
        'code',
        'default_days',
        'accrual_type',
        'carry_forward',
        'max_carry_days',
        'requires_approval',
        'requires_attachment',
        'min_notice_days',
        'max_consecutive',
        'is_paid',
        'is_active',
        'gender_restriction',
        'sort_order',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'accrual_type' => AccrualType::class,
            'default_days' => 'decimal:1',
            'max_carry_days' => 'decimal:1',
            'carry_forward' => 'boolean',
            'requires_approval' => 'boolean',
            'requires_attachment' => 'boolean',
            'min_notice_days' => 'integer',
            'max_consecutive' => 'integer',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function isAvailableForGender(?string $gender): bool
    {
        if ($this->gender_restriction === null) {
            return true;
        }

        return $this->gender_restriction === $gender;
    }
}
