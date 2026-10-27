<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One decision on an approval instance. Append-only, like `audit_log`: the
 * history of who approved which step is the thing a dispute turns on.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $approval_instance_id
 * @property int $position
 * @property int|null $actor_user_id
 * @property int|null $on_behalf_of_user_id
 * @property string $action
 * @property string|null $comment
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
class ApprovalAction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    /** Filed for someone else; the actor is the filer, who may not approve it. */
    public const FILED_FOR = 'filed_for';

    protected $fillable = [
        'tenant_id',
        'approval_instance_id',
        'position',
        'actor_user_id',
        'on_behalf_of_user_id',
        'action',
        'comment',
        'metadata',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'approval_instance_id',
        'actor_user_id',
        'on_behalf_of_user_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function update(array $attributes = [], array $options = []): never
    {
        throw new \LogicException('Approval actions cannot be updated.');
    }

    public function delete(): never
    {
        throw new \LogicException('Approval actions cannot be deleted.');
    }
}
