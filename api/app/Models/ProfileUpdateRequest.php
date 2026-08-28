<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProfileUpdateStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Database\Factories\ProfileUpdateRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ProfileUpdateStatus $status
 * @property string $field_name
 */
class ProfileUpdateRequest extends Model
{
    /** @use HasFactory<ProfileUpdateRequestFactory> */
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    /**
     * Profile fields an employee may propose but not apply on their own.
     *
     * Keyed by field name; the value names the table the approved value lands in,
     * which is not always `employees` — bank details live in their own table.
     */
    public const GATED_FIELDS = [
        'name' => 'employee',
        'name_am' => 'employee',
        'tin' => 'employee',
        'date_of_birth' => 'employee',
        'bank_name' => 'bank_detail',
        'bank_account_number' => 'bank_detail',
    ];

    /**
     * Profile fields an employee may change on their own, applied immediately.
     *
     * Together with GATED_FIELDS this is the whole employee-editable surface, and
     * `GET /profile` ships both lists so the UI never has to hardcode which side of
     * the line a field falls on.
     */
    public const SELF_FIELDS = [
        'phone',
        'marital_status',
        'nationality',
    ];

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'requested_by',
        'field_name',
        'old_value',
        'new_value',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'requested_by',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProfileUpdateStatus::class,
            // Gated fields are the sensitive ones (bank account, TIN), so the
            // staging values get the same at-rest protection as their destination.
            'old_value' => 'encrypted',
            'new_value' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
