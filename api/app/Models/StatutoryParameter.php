<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One statutory value in force over a period: a working-day divisor, the
 * overtime multipliers, a pension scheme's default, a fiscal year's first
 * month. Read through App\Services\Payroll\StatutoryRules, never directly.
 *
 * Platform rows (tenant_id NULL) carry the law and may be scoped to an
 * organisation type; they are written with DB::table, as the tax ladder's
 * are, because creating through this model stamps the current tenant. An
 * organisation's own row replaces the platform value only where the platform
 * row in force says `tenant_overridable`.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $tenant_id
 * @property string $code
 * @property string|null $organization_type
 * @property mixed $value
 * @property bool $tenant_overridable
 * @property string|null $legal_reference
 * @property bool $confirmed
 * @property string|null $note
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StatutoryParameter extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'code',
        'organization_type',
        'value',
        'tenant_overridable',
        'legal_reference',
        'confirmed',
        'note',
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
            'value' => 'json',
            'tenant_overridable' => 'boolean',
            'confirmed' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
