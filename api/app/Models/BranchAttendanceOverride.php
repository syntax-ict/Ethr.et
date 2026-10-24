<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The attendance rules one branch sets differently from its organisation
 * (AttendancePolicy). `overrides` holds only the keys the branch changes.
 *
 * @property array<string, mixed> $overrides
 */
class BranchAttendanceOverride extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'overrides',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'branch_id',
    ];

    protected function casts(): array
    {
        return [
            'overrides' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
