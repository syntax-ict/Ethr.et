<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccrualType;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property AccrualType $accrual_type */
class LeaveType extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'name',
        'name_am',
        'code',
        'kind',
        'default_days',
        'accrual_type',
        'carry_forward',
        'max_carry_days',
        'seniority_every_years',
        'seniority_extra_days',
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
            'seniority_every_years' => 'integer',
            'seniority_extra_days' => 'decimal:1',
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

    public const KIND_LEAVE = 'leave';

    public const KIND_DUTY = 'duty';

    public const KIND_EXCUSED = 'excused';

    /** @var list<string> */
    public const KINDS = [self::KIND_LEAVE, self::KIND_DUTY, self::KIND_EXCUSED];

    /** The column's default, so a type created in this request has its kind before it is reloaded. */
    protected $attributes = [
        'kind' => self::KIND_LEAVE,
    ];

    /** Time off, as opposed to a trip or an excused absence. Counted as leave taken. */
    public function isLeave(): bool
    {
        return ($this->kind ?? self::KIND_LEAVE) === self::KIND_LEAVE;
    }

    /**
     * Whether a request must fit in the employee's balance.
     *
     * Duty and excused days draw on nothing. Nor does an unpaid type that
     * grants no days: an unpaid day costs the organisation nothing, so there
     * is nothing to run out of. The catalog's `unpaid` type is exactly that
     * (0 days), and until 2026-10-27 every request for it was refused as
     * "insufficient balance" unless HR first adjusted the balance by hand. An
     * unpaid type someone gave a day count keeps it as a cap.
     */
    public function limitedByBalance(): bool
    {
        if (! $this->isLeave()) {
            return false;
        }

        return $this->is_paid || (float) $this->default_days > 0;
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
