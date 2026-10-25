<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The phone an employee punches with: a passkey whose private key never
 * leaves the phone's secure hardware. One active device per employee, and a
 * device is never someone else's (AttendanceDeviceGate).
 *
 * `active` punches; `pending` waits for an approver (registered away from
 * work, or replacing a phone); `revoked` and `rejected` never punch again.
 *
 * @property Carbon|null $approved_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class AttendanceDevice extends Model
{
    use BelongsToTenant, HasPublicId;

    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    public const REVOKED = 'revoked';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'credential_id',
        'credential_id_hash',
        'public_key',
        'sign_count',
        'rp_id',
        'aaguid',
        'backup_eligible',
        'backup_state',
        'install_hash',
        'cookie_hash',
        'label',
        'status',
        'enrolled_inside_geofence',
        'approved_by',
        'approved_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'credential_id_hash',
        'public_key',
        'install_hash',
        'cookie_hash',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'backup_eligible' => 'boolean',
            'backup_state' => 'boolean',
            'enrolled_inside_geofence' => 'boolean',
            'approved_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The lookup key for a credential id as the browser sends it (base64url). */
    public static function hashCredential(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }
}
