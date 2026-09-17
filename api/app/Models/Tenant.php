<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * Cast columns need an explicit @property or static analysis reads their type
 * from the database column instead. `settings` is json, so without this line it
 * analyses as `string|null` and every `is_array()` guard against it looks like
 * dead code — which is how a real guard gets deleted as "unreachable".
 *
 * @property TenantStatus $status
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $theme
 * @property \Illuminate\Support\Carbon|null $government_verified_at
 */
class Tenant extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    /**
     * Subdomains a tenant may never claim — the single source of truth.
     *
     * This lived in three places that had already drifted apart: ResolveTenant
     * refused one set, SubdomainCheckController advertised availability against
     * a different set, and RegisterTenantRequest enforced nothing at all. The
     * gaps were not cosmetic — `admin` passed registration, which would create
     * a tenant squatting the platform console's hostname that ResolveTenant
     * then refused to resolve: a tenant that exists and can never be reached.
     *
     * `admin` is the one that matters for security; the rest are infrastructure
     * names an operator will plausibly want, and reserving them now is far
     * cheaper than renaming a tenant later.
     *
     * `platform` is reserved without being served: it is the other name the
     * platform console is routinely called, so a tenant holding it could not be
     * moved later without breaking their URLs. Reserving a name costs nothing;
     * reclaiming one costs a migration.
     */
    public const RESERVED_SUBDOMAINS = [
        'admin', 'platform', 'api', 'app', 'www',
        'mail', 'smtp', 'ftp',
        'cdn', 'static', 'assets',
        'status', 'support', 'help', 'docs',
        'staging', 'dev', 'test',
    ];

    /**
     * The single hostname label platform administration is served from.
     *
     * Named rather than spelled `'admin'` at each site because two different
     * rules depend on it and mean different things: ResolveTenant refuses every
     * tenant selector on this host, while the rest of RESERVED_SUBDOMAINS merely
     * cannot *be* a tenant. Conflating them once meant an apex alias like `www`
     * would silently lose the login form's organisation field.
     */
    public const PLATFORM_SUBDOMAIN = 'admin';

    protected $fillable = [
        'public_id',
        'name',
        'subdomain',
        'custom_domain',
        'type',
        'status',
        'logo_path',
        'theme',
        'default_locale',
        'timezone',
        'ethiopian_calendar',
        'settings',
        'trial_ends_at',
        // `government_verified_at` is deliberately absent. It is what stops a
        // private company presenting itself with state-official branding on
        // *.ethr.et, so it is granted by a platform admin through the
        // admin.manage surface and can never be set by a tenant's own request.
        // See App\Rules\SelectablePreset.
    ];

    protected $hidden = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'theme' => 'array',
            'settings' => 'array',
            'ethiopian_calendar' => 'boolean',
            'trial_ends_at' => 'datetime',
            'government_verified_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The tenant's public landing page content, if it has ever been edited.
     *
     * Null is the normal state, not an error: a profile row is created lazily
     * the first time an administrator saves the public page. No profile means
     * no public page, which is the correct default for an HR product.
     *
     * @return HasOne<TenantPublicProfile, $this>
     */
    public function publicProfile(): HasOne
    {
        return $this->hasOne(TenantPublicProfile::class);
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function featureFlags(): HasMany
    {
        return $this->hasMany(FeatureFlag::class);
    }

    /**
     * Tenant-scoped flag, falling back to the global flag of the same key.
     * See FeatureFlag::enabled() — an unset flag (tenant or global) is off.
     */
    public function hasFeature(string $key): bool
    {
        return FeatureFlag::enabled($key, $this);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function attendanceSetting(): HasOne
    {
        return $this->hasOne(AttendanceSetting::class);
    }

    public function ssoSetting(): HasOne
    {
        return $this->hasOne(SsoSetting::class);
    }

    public function kioskSessions(): HasMany
    {
        return $this->hasMany(KioskSession::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function isTrialExpired(): bool
    {
        return $this->status === TenantStatus::TRIAL
            && $this->trial_ends_at
            && $this->trial_ends_at->isPast();
    }

    public function isActive(): bool
    {
        return in_array($this->status, [TenantStatus::TRIAL, TenantStatus::ACTIVE], true)
            && ! $this->isTrialExpired();
    }

    protected static function booted(): void
    {
        // ResolveTenant caches this model under `tenant:{subdomain}` for 5 minutes
        // to skip a DB hit per request. Without busting it here, a super admin
        // suspending a tenant (fraud, abuse, non-payment) leaves that tenant fully
        // functional for up to 5 more minutes — the opposite of what "suspend"
        // promises. Bust both the old and new subdomain in case it changed.
        static::saved(function (self $tenant): void {
            Cache::forget("tenant:{$tenant->getOriginal('subdomain')}");
            Cache::forget("tenant:{$tenant->subdomain}");
        });
    }
}
