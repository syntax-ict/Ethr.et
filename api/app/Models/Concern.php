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
 * Something an employee raised - named, or anonymously. An anonymous concern
 * stores no reporter; its author follows it with a one-time code kept only
 * as a hash.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $reporter_user_id
 * @property bool $anonymous
 * @property string|null $tracking_hash
 * @property string $category
 * @property string $subject
 * @property string $description
 * @property string $status
 * @property string|null $resolution
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $reporter
 */
class Concern extends Model
{
    use BelongsToTenant, HasPublicId;

    public const CATEGORIES = [
        'safety', 'harassment', 'discrimination', 'manager', 'workload',
        'pay', 'facilities', 'ethics', 'wellbeing', 'other',
    ];

    public const STATUSES = ['new', 'in_review', 'resolved', 'closed'];

    protected $fillable = [
        'tenant_id', 'reporter_user_id', 'anonymous', 'tracking_hash', 'category',
        'subject', 'description', 'status', 'resolution', 'resolved_at',
    ];

    protected $hidden = ['tracking_hash'];

    protected function casts(): array
    {
        return [
            'anonymous' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return HasMany<ConcernMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConcernMessage::class)->orderBy('id');
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
    }
}
