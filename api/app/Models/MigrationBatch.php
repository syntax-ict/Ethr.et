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

    /**
     * Days a batch is kept after anyone last touched it or one of its rows
     * (CleanupExpiredDataJob). It was seven days from the day it was staged,
     * whatever its state: a list of a few hundred people still being reviewed
     * was deleted, decisions and all, a week after it was pasted.
     */
    public const RETENTION_DAYS = 30;

    /**
     * `status` is `staging` (rows stored, still being matched), `reviewing`,
     * `committing` (some of it added) or `committed`.
     */
    public const STAGING = 'staging';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'created_by',
        'source_type',
        'source_ref',
        'status',
        'totals',
        'commit_cursor',
        'committed_at',
        'options',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'commit_cursor' => 'integer',
            'committed_at' => 'datetime',
            'options' => 'array',
        ];
    }

    /** @return HasMany<MigrationStagingRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(MigrationStagingRow::class, 'batch_id');
    }

    /**
     * The status the list's new people arrive with, chosen when it began to
     * be added. Null when nothing was chosen: they arrive as `hired`.
     */
    public function arrivalStatus(): ?string
    {
        $options = $this->getAttribute('options');
        $status = is_array($options) ? ($options['status'] ?? null) : null;

        return is_string($status) ? $status : null;
    }

    public function isStaging(): bool
    {
        return $this->status === self::STAGING;
    }

    public function isCommitted(): bool
    {
        return $this->status === 'committed';
    }

    /**
     * Still open for review. Once the first slice of a commit has run the
     * list is `committing`, and a decision changed after that would apply to
     * some rows and not to others.
     */
    public function isReviewing(): bool
    {
        return $this->status === 'reviewing';
    }
}
