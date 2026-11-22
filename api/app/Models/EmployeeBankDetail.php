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
 * @property string|null $account_number_hash
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
        'account_number_hash',
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
        static::saving(static function (self $detail): void {
            ImpersonationRestricted::unlessOrganisation('EmployeeBankDetail::saving');

            if ($detail->isDirty('account_number') || ($detail->account_number_hash === null && $detail->getRawOriginal('account_number') !== null)) {
                $detail->account_number_hash = self::hashAccountNumber($detail->account_number);
            }
        });
        static::deleting(static fn () => ImpersonationRestricted::unlessOrganisation('EmployeeBankDetail::deleting'));
    }

    /**
     * The account number as two records are compared on it, and never shown:
     * separators dropped and letters upper-cased, so "1000 1234-5678" and
     * "100012345678" are one account, keyed with the application key the way
     * Employee::hashNationalId() is. Null when there is no number. The bank
     * is left out on purpose: one bank written two ways ("CBE", "Commercial
     * Bank of Ethiopia") must not hide a shared account, and the screen names
     * each bank so a coincidence across banks is plain to see.
     */
    public static function hashAccountNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');

        return $normalized === '' ? null : hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
