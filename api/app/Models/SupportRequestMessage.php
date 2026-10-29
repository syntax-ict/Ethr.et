<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message on a support request: from the person who asked, from the
 * organisation's support people, or from ETHR.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $support_request_id
 * @property string $author
 * @property int|null $author_user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property-read User|null $authorUser
 */
class SupportRequestMessage extends Model
{
    use BelongsToTenant, HasPublicId;

    public const AUTHORS = ['requester', 'organisation', 'ethr'];

    protected $fillable = ['tenant_id', 'support_request_id', 'author', 'author_user_id', 'body'];

    /** @return BelongsTo<User, $this> */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
