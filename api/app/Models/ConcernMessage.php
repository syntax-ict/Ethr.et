<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message on a concern: from its reporter, or from HR. A message from an
 * anonymous reporter names no user.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $concern_id
 * @property string $author
 * @property int|null $author_user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property-read User|null $authorUser
 */
class ConcernMessage extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['tenant_id', 'concern_id', 'author', 'author_user_id', 'body'];

    /** @return BelongsTo<User, $this> */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
