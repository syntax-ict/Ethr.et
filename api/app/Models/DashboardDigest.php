<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring email summary of ExecutiveDashboardService::overview()/
 * complianceSnapshot() — see RunDashboardDigestsJob. `branch_id` null means
 * tenant-wide (a `dashboard.executive` holder's scope); a value scopes every
 * run the same way ExecutiveDashboardController forces a `dashboard.regional`
 * holder to their own branch.
 */
class DashboardDigest extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'created_by',
        'frequency',
        'recipients',
        'last_run_at',
        'next_run_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
