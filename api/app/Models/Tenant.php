<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * @property TenantStatus $status
 * @property string|null $custom_domain
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
        // Since 2026-10-06 an organisation is also reachable as ethr.et/{slug},
        // which shares ONE namespace with the frontend's own pages. Every
        // top-level route below is reserved so no tenant can shadow it, and
        // PathAndCustomDomainTenancyTest reads src/src/app to keep this list
        // complete. The locale codes are the marketing site's first segment.
        'analytics', 'announcements', 'approvals', 'attendance', 'billing',
        'contact', 'dashboard', 'devices', 'directory', 'employees', 'faq',
        'features', 'impersonate', 'kiosk', 'leave', 'login', 'logout',
        'notifications', 'offline', 'og.png', 'organization', 'payroll',
        'pricing', 'privacy', 'profile', 'register', 'reports', 'settings',
        'setup', 'shifts', 'terms',
        'en', 'am', 'om', 'ti', 'so', 'sid',
        'sanctum', 'up', 'storage', 'icons', 'auth', 'org', 'tenant',
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
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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

    /**
     * The query form of isActive(): tenants that are in use, which means active,
     * or on a trial that has not expired. Per-tenant sweeps select with this.
     * `where('status', 'active')` skipped every trial tenant, and sign-up puts
     * every new tenant on a six-month trial. TenantSweepScheduleTest checks
     * that the two forms agree.
     *
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', TenantStatus::ACTIVE)
                ->orWhere(function (Builder $query): void {
                    $query->where('status', TenantStatus::TRIAL)
                        ->where(function (Builder $query): void {
                            $query->whereNull('trial_ends_at')
                                ->orWhere('trial_ends_at', '>=', now());
                        });
                });
        });
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
            // ResolveTenant caches custom-domain lookups the same way, and a
            // domain moved or removed must stop resolving at once.
            foreach (array_filter([$tenant->getOriginal('custom_domain'), $tenant->custom_domain]) as $domain) {
                Cache::forget('tenant-domain:'.$domain);
            }
        });
    }

    /**
     * A custom domain is stored as a bare, lower-case hostname, because that is
     * what ResolveTenant compares the request host against. A scheme, port,
     * path or trailing dot typed into the field would otherwise make the domain
     * silently never match.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function customDomain(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::normaliseDomain($value),
        );
    }

    public static function normaliseDomain(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = explode('/', $value, 2)[0];
        $value = explode(':', $value, 2)[0];
        $value = rtrim($value, '.');

        return $value === '' ? null : $value;
    }
}
