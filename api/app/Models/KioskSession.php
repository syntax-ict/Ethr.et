<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CurrentTenant;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property Carbon|null $expires_at
 * @property string|null $token The plaintext token, on the instance that was just given one; null on any instance read back.
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
        'expires_at',
        'admin_pin',
        'device_identifier',
        'status',
        'last_activity_at',
        'activated_at',
        'deactivated_at',
        'offline_public_key',
        'offline_private_key',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'branch_id',
        'token_hash',
        'admin_pin',
        'offline_public_key',
        'offline_private_key',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The token as it was just issued. Never stored: assigning `token` keeps
     * the plaintext on this instance, for the one response that shows it,
     * and stores only its hash.
     */
    private ?string $plainTextToken = null;

    /**
     * `token` is write-through to `token_hash`. Reading it gives the plaintext
     * only on the instance that was just given one, so a token is shown once,
     * when it is created or replaced, and never again.
     *
     * @return Attribute<string|null, string>
     */
    protected function token(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->plainTextToken,
            set: function (string $value): array {
                $this->plainTextToken = $value;

                return ['token_hash' => self::hashToken($value)];
            },
        );
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** When a session issued or renewed now stops working. */
    public static function freshExpiry(): Carbon
    {
        return now()->addDays((int) config('attendance.kiosk_session_days'));
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
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
     * token answered "invalid" (audit N47). A token is
     * 64 random hex characters (generateToken) and only its SHA-256 is
     * stored, in the globally unique `kiosk_sessions.token_hash`, which is
     * what makes a token-only lookup sound, as for `api_keys.key_hash`.
     *
     * Null, which the caller reports as an invalid token, when no active,
     * unexpired session holds the token; when the request already resolved a different
     * tenant (a kiosk token presented on another organisation's host); or when
     * the tenant is not active, which ResolveTenant would otherwise refuse.
     */
    public static function resolveActiveByToken(string $token): ?self
    {
        $session = self::withoutGlobalScope('tenant')
            ->where('token_hash', self::hashToken($token))
            ->where('status', 'active')
            ->where('expires_at', '>', now())
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
