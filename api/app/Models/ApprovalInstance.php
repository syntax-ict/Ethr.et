<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One request's journey through the workflow it was submitted under.
 *
 * `workflow_snapshot` is the workflow as it stood at submission; the current
 * step's approver is resolved on entry into `current_approver_user_id` or
 * `current_approver_role`, both null meaning anyone holding the permission.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property int|null $approval_workflow_id
 * @property array{workflow_public_id: string|null, name: string|null, steps: list<array{position: int, approver_kind: string, approver_ref: string|null, sla_hours: int, can_return: bool}>} $workflow_snapshot
 * @property int $current_position
 * @property int|null $current_approver_user_id
 * @property string|null $current_approver_role
 * @property string $status
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 */
class ApprovalInstance extends Model
{
    use BelongsToTenant, HasPublicId;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'approvable_type',
        'approvable_id',
        'approval_workflow_id',
        'workflow_snapshot',
        'current_position',
        'current_approver_user_id',
        'current_approver_role',
        'status',
        'submitted_by',
        'submitted_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'approvable_type',
        'approvable_id',
        'approval_workflow_id',
        'current_approver_user_id',
        'submitted_by',
    ];

    protected function casts(): array
    {
        return [
            'workflow_snapshot' => 'array',
            'current_position' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<ApprovalAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class)->orderBy('id');
    }

    public function stepCount(): int
    {
        return count($this->workflow_snapshot['steps']);
    }
}
