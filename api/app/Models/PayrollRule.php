<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Database\Factories\PayrollRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A tenant-configurable payroll rule. For `category = allowance`, the rule
 * defines an earning added to gross salary during payroll processing:
 *   - type `fixed`      → formula: {"amount_cents": int}
 *   - type `percentage` → formula: {"percent": float}  (of basic salary)
 * `is_taxable` controls whether the allowance is included in taxable income.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string $type
 * @property string $category
 * @property array<string, mixed>|null $formula
 * @property bool $is_taxable
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PayrollRule extends Model
{
    use BelongsToTenant, HasAuditLog, HasPublicId;

    /** @use HasFactory<PayrollRuleFactory> */
    use HasFactory;

    protected $table = 'payroll_rules';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'name',
        'type',
        'category',
        'formula',
        'is_taxable',
        'is_active',
        'sort_order',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'formula' => 'array',
            'is_taxable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
