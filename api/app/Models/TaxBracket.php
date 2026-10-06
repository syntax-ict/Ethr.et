<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One band of an income-tax ladder, in integer cents. A `max_amount_cents` of
 * 0 marks the final, open-ended band (the column is NOT NULL). A null
 * `tenant_id` is a platform-wide band inherited by tenants that define none.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $tenant_id
 * @property int $min_amount_cents
 * @property int $max_amount_cents
 * @property numeric-string $rate
 * @property int $deduction_cents
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaxBracket extends Model
{
    use BelongsToTenant, HasAuditLog, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'min_amount_cents',
        'max_amount_cents',
        'rate',
        'deduction_cents',
        'effective_from',
        'effective_to',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'min_amount_cents' => 'integer',
            'max_amount_cents' => 'integer',
            'rate' => 'decimal:2',
            'deduction_cents' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
