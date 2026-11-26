<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Invitations\EmailInvitationCeiling;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to invite someone to sign in (InvitationDispatcher). A bulk send
 * shares a `batch`; the newest row per user is what the users list shows.
 *
 * A held invitation (the organisation's daily email ceiling, see
 * EmailInvitationCeiling) keeps the status `queued` and has `held_until` set,
 * in the future until its morning comes and in the past until the queue sends
 * it (scopeHeldAndUnsent). There is no separate status for it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string|null $batch
 * @property string $channel
 * @property string $status
 * @property bool $reminder
 * @property int $attempts
 * @property string|null $error_code
 * @property int|null $queued_by
 * @property Carbon $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $held_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InvitationSend extends Model
{
    use BelongsToTenant;

    public const EMAIL = 'email';

    public const SMS = 'sms';

    /** Not sent at all: the person has no email, and texts are off. HR issues a code. */
    public const IN_PERSON = 'in_person';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Not sent because there was no longer anyone to invite: activated, suspended, removed. */
    public const SKIPPED = 'skipped';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'batch',
        'channel',
        'status',
        'reminder',
        'attempts',
        'error_code',
        'queued_by',
        'queued_at',
        'sent_at',
        'held_until',
    ];

    protected $hidden = ['id', 'tenant_id', 'user_id', 'queued_by'];

    protected function casts(): array
    {
        return [
            'reminder' => 'boolean',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'held_until' => 'datetime',
        ];
    }

    /**
     * Rows that were held for a later day and have not been sent: still `queued`
     * with a `held_until`, from the day they were held until their job is long
     * gone (EmailInvitationCeiling::ABANDONED_AFTER_DAYS after it was due). A
     * held row that has fallen due is still in here until the queue reaches it.
     * Says nothing about the tenant; the caller states `tenant_id`.
     *
     * @param  Builder<InvitationSend>  $query
     */
    public function scopeHeldAndUnsent(Builder $query): void
    {
        $query->where('status', self::QUEUED)
            ->whereNotNull('held_until')
            ->where('held_until', '>', now()->subDays(EmailInvitationCeiling::ABANDONED_AFTER_DAYS));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
