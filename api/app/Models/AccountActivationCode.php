<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time code HR issued in person to an employee with no email and no
 * phone. Only a keyed hash of the code is kept; it is spent by one conditional
 * update (ActivationCodeService), so a replayed or raced redemption finds it
 * already used.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $employee_id
 * @property string $purpose
 * @property string $code_hash
 * @property int|null $issued_by
 * @property string $document_type
 * @property string|null $note
 * @property int $failed_attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccountActivationCode extends Model
{
    use BelongsToTenant;

    public const ACTIVATION = 'activation';

    public const RECOVERY = 'recovery';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'employee_id',
        'purpose',
        'code_hash',
        'issued_by',
        'document_type',
        'note',
        'failed_attempts',
        'expires_at',
        'consumed_at',
        'revoked_at',
    ];

    protected $hidden = ['id', 'tenant_id', 'user_id', 'employee_id', 'issued_by', 'code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
