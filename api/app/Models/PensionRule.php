<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pension contribution rule in force for a scheme over a period.
 *
 * Platform rows (tenant_id NULL) carry the law, seeded from
 * docs/payroll/STATUTORY-RULES-RESEARCH.md; they are written with DB::table, as
 * the tax ladder's are, because creating through this model stamps the current
 * tenant. An organisation's own row replaces the platform row for its scheme
 * on the dates it covers (PensionCalculator::rule).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $tenant_id
 * @property string $scheme
 * @property string $employee_rate
 * @property string $employer_rate
 * @property int|null $ceiling_cents
 * @property int|null $floor_cents
 * @property string $base
 * @property bool $exempt_non_nationals
 * @property int|null $min_service_days
 * @property string $legal_reference
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PensionRule extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'scheme',
        'employee_rate',
        'employer_rate',
        'ceiling_cents',
        'floor_cents',
        'base',
        'exempt_non_nationals',
        'min_service_days',
        'legal_reference',
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
            'employee_rate' => 'decimal:2',
            'employer_rate' => 'decimal:2',
            'ceiling_cents' => 'integer',
            'floor_cents' => 'integer',
            'exempt_non_nationals' => 'boolean',
            'min_service_days' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
