<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An organisation's chain of approval steps for one request type. Requests
 * already in flight keep the snapshot they started with, so editing or
 * deleting a workflow never changes them.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $request_type
 * @property string $name
 * @property int $priority
 * @property bool $is_active
 * @property array{department_ids?: list<string>, branch_ids?: list<string>, leave_type_ids?: list<string>}|null $conditions
 */
class ApprovalWorkflow extends Model
{
    use BelongsToTenant, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'request_type',
        'name',
        'priority',
        'is_active',
        'conditions',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'conditions' => 'array',
        ];
    }

    /** @return HasMany<ApprovalWorkflowStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalWorkflowStep::class)->orderBy('position');
    }
}
