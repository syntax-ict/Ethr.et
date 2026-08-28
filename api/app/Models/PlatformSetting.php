<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row of platform-wide settings, managed by the super admin.
 *
 * Global by design — no tenant scope. See the migration for why, and
 * TenantIsolationTest::GLOBAL_MODELS for the allow-list entry that makes it
 * a reviewed decision.
 */
class PlatformSetting extends Model
{
    use HasPublicId;

    protected $fillable = [
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'payment_instructions',
        'payment_instructions_am',
    ];

    protected $hidden = [
        'id',
    ];

    /**
     * The one row, created on first read so callers never handle a null.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /**
     * True once an operator has filled in enough for a tenant to actually pay.
     *
     * The billing page uses this to distinguish "not configured yet" from a
     * configured account — showing half-empty payment fields on the screen that
     * tells customers where to send money is worse than showing none.
     */
    public function hasPaymentDetails(): bool
    {
        return filled($this->bank_name)
            && filled($this->bank_account_number)
            && filled($this->bank_account_name);
    }
}
