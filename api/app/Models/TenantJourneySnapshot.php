<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property string $stage
 * @property Carbon|null $stage_reached_at
 * @property string $lifecycle
 * @property string $health_state
 * @property list<string> $reasons
 * @property array<string, string> $milestones
 * @property array<string, mixed> $metrics
 * @property int $readiness_score
 * @property string $readiness_level
 * @property list<array{id: string, category: string, label: string}> $readiness_gaps
 * @property Carbon|null $last_activity_at
 * @property Carbon $computed_at
 *
 * Where one organisation is on its journey (docs/operations/TENANT-JOURNEY.md).
 *
 * Deliberately without BelongsToTenant: this is the platform's own record
 * about a tenant, read only from the console (`admin.manage`), and always by
 * an explicit `tenant_id`. A tenant-scoped version would be empty for the one
 * reader it exists for.
 */
class TenantJourneySnapshot extends Model
{
    protected $table = 'tenant_journey_snapshots';

    protected $fillable = [
        'tenant_id',
        'stage',
        'stage_reached_at',
        'lifecycle',
        'health_state',
        'reasons',
        'milestones',
        'metrics',
        'readiness_score',
        'readiness_level',
        'readiness_gaps',
        'last_activity_at',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'milestones' => 'array',
            'metrics' => 'array',
            'readiness_gaps' => 'array',
            'readiness_score' => 'integer',
            'stage_reached_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
