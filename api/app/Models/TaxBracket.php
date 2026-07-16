<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class TaxBracket extends Model
{
    use BelongsToTenant, HasPublicId;

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
