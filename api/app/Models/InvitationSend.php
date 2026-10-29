<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to invite someone to sign in (InvitationDispatcher). A bulk send
 * shares a `batch`; the newest row per user is what the users list shows.
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
    ];

    protected $hidden = ['id', 'tenant_id', 'user_id', 'queued_by'];

    protected function casts(): array
    {
        return [
            'reminder' => 'boolean',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
