<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of discovered people staged for review before migration.
 * See the migration and ONBOARDING_V2.md D6.
 */
class MigrationBatch extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'created_by',
        'source_type',
        'source_ref',
        'status',
        'totals',
        'committed_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    /** @return HasMany<MigrationStagingRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(MigrationStagingRow::class, 'batch_id');
    }

    public function isCommitted(): bool
    {
        return $this->status === 'committed';
    }
}
