<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccrualType;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property AccrualType $accrual_type */
class LeaveType extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId, SoftDeletes;

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

    /**
     * A stable, visually distinct colour for calendar blocks and legends.
     *
     * There is no stored colour column on leave types, so the colour is
     * derived deterministically from the immutable `code` — the same type
     * always renders the same colour, and custom tenant types get a distinct
     * colour without any configuration.
     */
    public function calendarColor(): string
    {
        $palette = [
            '#0F4C75', // deep teal-blue
            '#059669', // green
            '#D97706', // amber
            '#DC2626', // red
            '#7C3AED', // violet
            '#0284C7', // info blue
            '#DB2777', // pink
            '#4B5563', // slate
        ];

        $key = (string) ($this->code ?? $this->name ?? '');

        return $palette[crc32($key) % count($palette)];
    }
}
