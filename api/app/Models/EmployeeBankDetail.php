<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `public_id` was added by a later migration that loops over a table list, which
 * Larastan's static migration reader cannot follow — hence the declaration.
 *
 * @property string $public_id
 */
class EmployeeBankDetail extends Model
{
    use BelongsToTenant, HasAuditLog, HasPublicId;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'bank_name',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
