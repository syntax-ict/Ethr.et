<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CurrentTenant;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * @property-read Branch|null $branch
 * @property Carbon|null $activated_at
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $last_activity_at
 */
class KioskSession extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'branch_id',
        'name',
        'token',
        'admin_pin',
        'device_identifier',
        'status',
        'last_activity_at',
        'activated_at',
        'deactivated_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'branch_id',
        'token',
        'admin_pin',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function verifyAdminPin(string $pin): bool
    {
        return Hash::check($pin, $this->admin_pin);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The active session a kiosk token names, with its tenant made current.
     *
     * Pre-authentication, and deliberately cross-tenant: the token is the
     * credential, and it names its own tenant. A shared kiosk terminal has no
     * user login, so on a single-host deployment nothing else names the
     * tenant. The tenant-scoped lookup failed closed there, and every valid
     * token answered "invalid" (audit N47). `kiosk_sessions.token` is
     * globally unique and 64 random hex characters (generateToken), which is
     * what makes a token-only lookup sound, as for `devices.webhook_token`.
     *
     * Null, which the caller reports as an invalid token, when no active
     * session holds the token; when the request already resolved a different
     * tenant (a kiosk token presented on another organisation's host); or when
     * the tenant is not active, which ResolveTenant would otherwise refuse.
     */
    public static function resolveActiveByToken(string $token): ?self
    {
        $session = self::withoutGlobalScope('tenant')
            ->where('token', $token)
            ->where('status', 'active')
            ->first();

        if ($session === null) {
            return null;
        }

        $current = app(CurrentTenant::class);
        if ($current->id() !== null && $current->id() !== $session->tenant_id) {
            return null;
        }

        $tenant = Tenant::query()->find($session->tenant_id);
        if ($tenant === null || ! $tenant->isActive()) {
            return null;
        }

        $current->set($tenant);

        return $session;
    }

    public function touchActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }
}
