<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Someone in an organisation asked for help from inside ETHR. The
 * organisation's support people answer first; ETHR holds it once they pass
 * it on (`handled_by = ethr`).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $requester_user_id
 * @property string $category
 * @property string $body
 * @property string $status
 * @property string $handled_by
 * @property Carbon|null $escalated_at
 * @property int|null $escalated_by_user_id
 * @property string|null $escalation_note
 * @property array<string, mixed>|null $context
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $requester
 * @property-read User|null $escalatedBy
 */
class SupportRequest extends Model
{
    use BelongsToTenant, HasPublicId;

    public const CATEGORIES = [
        'sign_in', 'attendance', 'leave', 'payroll', 'employees', 'devices', 'billing', 'other',
    ];

    /** open: waiting for whoever holds it. answered: waiting for the requester. */
    public const STATUSES = ['open', 'answered', 'resolved'];

    public const HANDLED_BY_ORGANISATION = 'organisation';

    public const HANDLED_BY_ETHR = 'ethr';

    protected $fillable = [
        'tenant_id', 'requester_user_id', 'category', 'body', 'status', 'handled_by',
        'escalated_at', 'escalated_by_user_id', 'escalation_note', 'context', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by_user_id');
    }

    /** @return HasMany<SupportRequestMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportRequestMessage::class)->orderBy('id');
    }

    public function isWithEthr(): bool
    {
        return $this->handled_by === self::HANDLED_BY_ETHR;
    }
}
