<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApproverKind;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $approval_workflow_id
 * @property int $position
 * @property ApproverKind $approver_kind
 * @property string|null $approver_ref
 * @property int $sla_hours
 * @property bool $can_return
 */
class ApprovalWorkflowStep extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'approval_workflow_id',
        'position',
        'approver_kind',
        'approver_ref',
        'sla_hours',
        'can_return',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'approval_workflow_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'approver_kind' => ApproverKind::class,
            'sla_hours' => 'integer',
            'can_return' => 'boolean',
        ];
    }

    /** @return BelongsTo<ApprovalWorkflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }
}
