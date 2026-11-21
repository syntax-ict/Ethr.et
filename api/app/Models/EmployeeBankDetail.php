<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ImpersonationRestricted;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `public_id` was added by a later migration that loops over a table list, which
 * Larastan's static migration reader cannot follow — hence the declaration.
 *
 * @property string $public_id
 * @property string|null $bank_code
 */
class EmployeeBankDetail extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'bank_name',
        'bank_code',
        'branch_name',
        'account_number',
        'is_primary',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * Where people are paid is written by the organisation, never by an
     * operator in a support session. Refused here whatever route got this
     * far; the raw `is_primary` flip that precedes a create is guarded where
     * it is (EmployeeRelatedRecords, BankDetailController).
     */
    protected static function booted(): void
    {
        static::saving(static fn () => ImpersonationRestricted::unlessOrganisation('EmployeeBankDetail::saving'));
        static::deleting(static fn () => ImpersonationRestricted::unlessOrganisation('EmployeeBankDetail::deleting'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
