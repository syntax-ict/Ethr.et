<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One discovered person inside a migration batch, with the resolver's verdict
 * and the reviewer's chosen action. See ONBOARDING_V2.md D6.
 */
class MigrationStagingRow extends Model
{
    use BelongsToTenant, HasPublicId;

    public const ACTION_MERGE = 'merge';

    public const ACTION_CREATE = 'create';

    public const ACTION_SKIP = 'skip';

    public const ACTION_DEFER = 'defer';

    public const ACTION_PENDING = 'pending';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'batch_id',
        'raw',
        'display_name',
        'external_identifier',
        'match_outcome',
        'match_confidence',
        'candidates',
        'resolved_employee_id',
        'action',
        'processed_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'batch_id',
        'resolved_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'candidates' => 'array',
            'match_confidence' => 'float',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MigrationBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(MigrationBatch::class, 'batch_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function resolvedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_employee_id');
    }
}
