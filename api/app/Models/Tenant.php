<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantAddressMode;
use App\Enums\TenantStatus;
use App\Support\TenancyDomain;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property TenantStatus $status
 * @property string $organization_type
 * @property bool $organization_type_needs_review
 * @property string|null $custom_domain
 * @property string|null $custom_domain_token
 * @property Carbon|null $custom_domain_verified_at
 * @property TenantAddressMode|null $address_mode
 * @property Carbon|null $showcase_consented_at
 * @property Carbon|null $showcase_hidden_at
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
        'news', 'notifications', 'offline', 'og.png', 'organization', 'payroll',
        'pricing', 'privacy', 'profile', 'register', 'reports', 'settings',
        'setup', 'shifts', 'terms', 'vacancies', 'voice',
        'en', 'am', 'om', 'ti', 'so', 'sid',
        'sanctum', 'up', 'storage', 'icons', 'auth', 'org', 'tenant',
        // ZKTeco ADMS: the path is fixed in device firmware (AdmsController).
        'iclock',
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

    /** What every address must look like: lower-case letters, digits and inner hyphens. */
    public const SUBDOMAIN_REGEX = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    protected $fillable = [
        'public_id',
        'name',
        'subdomain',
        'custom_domain',
        'address_mode',
        'type',
        'organization_type',
        'organization_type_needs_review',
        'status',
        'logo_path',
        'logo_storage_path',
        'logo_version',
        'brand_assets',
        'theme',
        'landing',
        'default_locale',
        'timezone',
        'ethiopian_calendar',
        'settings',
        'trial_ends_at',
    ];

    protected $hidden = [
        'id',
        'custom_domain_token',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'theme' => 'array',
            'brand_assets' => 'array',
            'landing' => 'array',
            'settings' => 'array',
            'ethiopian_calendar' => 'boolean',
            'organization_type_needs_review' => 'boolean',
            'trial_ends_at' => 'datetime',
            'custom_domain_verified_at' => 'datetime',
            'address_mode' => TenantAddressMode::class,
            'showcase_consented_at' => 'datetime',
            'showcase_hidden_at' => 'datetime',
        ];
    }

    /**
     * The cache key of the public "Trusted by" list. Cleared by every write
     * that can change it; the TTL bounds a status change made elsewhere.
     */
    public const SHOWCASE_CACHE_KEY = 'public.trusted-by';

    public const SHOWCASE_CACHE_TTL = 600;

    /**
     * Organisations ETHR names on its public site: operational ones (active,
     * or in a free trial that has not ended; owner decision 2026-10-18) that
     * have not switched it off, and that the platform has not hidden. Every
     * organisation is listed from creation (owner decision 2026-10-26), so a
     * null `showcase_consented_at` now means it opted out. An expired trial, a
     * suspension or a cancellation takes the name down.
     *
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    public function scopeShowcased(Builder $query): Builder
    {
        return $query->operational()
            ->whereNotNull('showcase_consented_at')
            ->whereNull('showcase_hidden_at');
    }

    /** The row-level twin of scopeOperational(): active, or a trial that has not ended. */
    public function isOperational(): bool
    {
        return $this->status === TenantStatus::ACTIVE
            || ($this->status === TenantStatus::TRIAL && ($this->trial_ends_at === null || Carbon::parse($this->trial_ends_at)->gte(now())));
    }

    /**
     * A bare hostname: labels of letters, digits and hyphens, a dotted path of
     * them, and a top-level label that starts with a letter. One pattern for
     * every request that accepts a custom domain, so the console and the
     * organisation's own settings cannot disagree about what a domain is.
     */
    public const CUSTOM_DOMAIN_REGEX = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    /**
     * The address the organisation chose, or, while it has not chosen, the
     * one FrontendUrl always gave it: a verified custom domain, else the
     * subdomain where this deployment serves them, else the path.
     */
    public function addressMode(): TenantAddressMode
    {
        if ($this->address_mode instanceof TenantAddressMode) {
            return $this->address_mode;
        }

        if ($this->hasVerifiedCustomDomain()) {
            return TenantAddressMode::CUSTOM_DOMAIN;
        }

        return self::subdomainsServed() ? TenantAddressMode::SUBDOMAIN : TenantAddressMode::PATH;
    }

    /**
     * The address links can actually use right now. A chosen custom domain
     * counts only once verified; a chosen subdomain only where the deployment
     * serves them. Anything else falls back the way an unchosen organisation
     * would: a link must never point at a host that does not answer.
     */
    public function effectiveAddressMode(): TenantAddressMode
    {
        $chosen = $this->addressMode();

        if ($chosen === TenantAddressMode::CUSTOM_DOMAIN && $this->hasVerifiedCustomDomain()) {
            return TenantAddressMode::CUSTOM_DOMAIN;
        }

        if ($chosen === TenantAddressMode::SUBDOMAIN && self::subdomainsServed()) {
            return TenantAddressMode::SUBDOMAIN;
        }

        if ($chosen === TenantAddressMode::PATH) {
            return TenantAddressMode::PATH;
        }

        // The choice cannot be honoured yet (a pending domain, a subdomain on
        // a host that has none): the deployment's own default instead.
        return self::subdomainsServed() ? TenantAddressMode::SUBDOMAIN : TenantAddressMode::PATH;
    }

    /** Whether this deployment serves `{slug}.{root}` at all (TENANCY_SUBDOMAINS, after M3). */
    public static function subdomainsServed(): bool
    {
        return TenancyDomain::root() !== null && (bool) config('tenancy.subdomains');
    }

    /**
     * The organisation an address names: the one using it now, else the one
     * that used to (a retired name, TenantSubdomainAlias). Every lookup that
     * turns a slug into an organisation goes through here, so a renamed
     * organisation is still reached by the links, bookmarks, stored X-Tenant
     * values and SSO addresses that carry its old name. A deleted
     * organisation is found by neither.
     */
    public static function findBySlug(string $slug): ?self
    {
        $current = static::query()->where('subdomain', $slug)->first();
        if ($current !== null) {
            return $current;
        }

        $owner = self::aliasOwner($slug);

        return $owner === null ? null : static::query()->whereKey($owner)->first();
    }

    /**
     * Whether `$slug` can become `$tenant`'s address, or a new organisation's
     * when `$tenant` is null. Not when it is reserved, another organisation's
     * address (a deleted one's included: deleting never releases a name), or
     * a name another organisation used to have. An organisation may always
     * take back a name it retired itself. Format is the caller's rule.
     */
    public static function slugAvailableFor(string $slug, ?self $tenant = null): bool
    {
        if (in_array($slug, self::RESERVED_SUBDOMAINS, true)) {
            return false;
        }

        $holder = static::withTrashed()->where('subdomain', $slug)->value('id');
        if ($holder !== null && (int) $holder !== $tenant?->id) {
            return false;
        }

        $owner = self::aliasOwner($slug);

        return $owner === null || $owner === $tenant?->id;
    }

    /**
     * The addresses this organisation used to have, oldest first. They keep
     * forwarding here and stay reserved for it.
     *
     * @return list<string>
     */
    public function retiredSubdomains(): array
    {
        // The console asks about organisations other than the one resolved,
        // so the scope is dropped and this organisation is stated instead.
        return TenantSubdomainAlias::withoutGlobalScopes()
            ->where('tenant_id', $this->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('subdomain')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }

    /**
     * The organisation that used to be reached at `$slug`. Pre-resolution,
     * like the subdomain lookup in findBySlug: the slug is the selector, so
     * there is no tenant to state yet, and only the tenant id comes back.
     */
    private static function aliasOwner(string $slug): ?int
    {
        $owner = TenantSubdomainAlias::withoutGlobalScopes()
            ->where('subdomain', $slug)
            ->value('tenant_id');

        return $owner === null ? null : (int) $owner;
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

    /** @return HasOne<AttendanceSetting, $this> */
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

    /**
     * The uploaded logo's public URL, or null when none is uploaded.
     *
     * Same-origin and relative on purpose: the API answers on every host the
     * organisation is reached at (the shared address, its subdomain, its own
     * domain), so the image never crosses origins and the CSP's `img-src
     * 'self'` covers it.
     */
    public function uploadedLogoUrl(): ?string
    {
        if (! is_string($this->logo_storage_path) || ! is_string($this->logo_version)) {
            return null;
        }

        return route('organisation.logo', [$this->public_id, $this->logo_version], absolute: false);
    }

    /**
     * The logo to show: the uploaded one, else none. One answer for the
     * portal, the dashboard and the kiosk.
     *
     * Until 2026-10-15 a linked https URL in `logo_path` stood in when nothing
     * was uploaded. The portal shows only images ETHR re-encoded and serves
     * itself, so the link is retired: the column stays (nullable, written by
     * nothing) and is no longer read. No live organisation used it.
     */
    public function logoUrl(): ?string
    {
        return $this->uploadedLogoUrl();
    }

    /**
     * The other brand images an organisation may upload beside its logo,
     * keyed as they are stored in `brand_assets`: a logo for dark
     * backgrounds, a square mark, a cover photo.
     */
    public const BRAND_ASSET_KINDS = ['logo_dark', 'mark', 'cover'];

    /**
     * One stored brand image, or null when none of that kind is uploaded or
     * the entry is not well formed. Read raw for the reason `TenantLanding`
     * reads `landing` raw: a value edited by hand must not put a half entry
     * on a public page.
     *
     * @return array{path: string, version: string, width: int, height: int, variants?: array<int, string>, focal?: array{x: float|int, y: float|int}}|null
     */
    public function brandAsset(string $kind): ?array
    {
        $raw = $this->getAttribute('brand_assets');
        $asset = is_array($raw) ? ($raw[$kind] ?? null) : null;

        if (! is_array($asset)
            || ! is_string($asset['path'] ?? null)
            || ! is_string($asset['version'] ?? null)
            || ! is_int($asset['width'] ?? null)
            || ! is_int($asset['height'] ?? null)) {
            return null;
        }

        /** @var array{path: string, version: string, width: int, height: int, variants?: array<int, string>, focal?: array{x: float|int, y: float|int}} $asset */
        return $asset;
    }

    /**
     * The public URL of a stored brand image, or of one of its narrower
     * variants (`$variant` is the width, as the cover stores them). Relative
     * and same-origin, like {@see uploadedLogoUrl()}.
     */
    public function brandAssetUrl(string $kind, ?int $variant = null): ?string
    {
        $asset = $this->brandAsset($kind);
        if ($asset === null || ($variant !== null && ! isset($asset['variants'][$variant]))) {
            return null;
        }

        $segment = str_replace('_', '-', $kind).($variant === null ? '' : '-'.$variant);

        return route('organisation.asset', [$this->public_id, $segment, $asset['version']], absolute: false);
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

    /**
     * A custom domain is an address only once its owner has proved control of
     * it (CustomDomainVerifier). Until then it is pending: it resolves nothing
     * and no link is built on it.
     */
    public function hasVerifiedCustomDomain(): bool
    {
        return $this->custom_domain !== null && $this->custom_domain_verified_at !== null;
    }

    protected static function booted(): void
    {
        // Named under "Trusted by" from the start (owner decision 2026-10-26):
        // `showcase_consented_at` is when it was listed, set here for every
        // new organisation whatever creates it. An administrator can switch it
        // off in its settings and the platform can hide it, as before.
        static::creating(function (self $tenant): void {
            $tenant->showcase_consented_at ??= now();
        });
        static::created(fn () => Cache::forget(self::SHOWCASE_CACHE_KEY));

        // Any change of domain starts verification over: a fresh token, and
        // pending until the new name is checked. Here rather than in the
        // controller so no path (console, tinker, a seeder) can carry one
        // domain's verification over to another. A save that sets the
        // verification itself, in the same write, is left alone.
        static::saving(function (self $tenant): void {
            if (! $tenant->isDirty('custom_domain') || $tenant->isDirty('custom_domain_verified_at')) {
                return;
            }

            $tenant->custom_domain_verified_at = null;
            $tenant->custom_domain_token = $tenant->custom_domain === null ? null : bin2hex(random_bytes(16));
        });

        // ResolveTenant caches this model under `tenant:{subdomain}` for 5 minutes
        // to skip a DB hit per request. Without busting it here, a super admin
        // suspending a tenant (fraud, abuse, non-payment) leaves that tenant fully
        // functional for up to 5 more minutes — the opposite of what "suspend"
        // promises. Bust both the old and new subdomain in case it changed.
        static::saved(fn (self $tenant) => $tenant->forgetResolverCache());

        // A soft delete writes `deleted_at` without a save event, so it busts
        // the same caches itself: a deleted organisation must stop resolving
        // at once, not five minutes later. A restore saves, and is covered.
        static::deleted(fn (self $tenant) => $tenant->forgetResolverCache());
    }

    private function forgetResolverCache(): void
    {
        Cache::forget("tenant:{$this->getOriginal('subdomain')}");
        Cache::forget("tenant:{$this->subdomain}");
        // ResolveTenant caches custom-domain lookups the same way, and a
        // domain moved or removed must stop resolving at once.
        foreach (array_filter([$this->getOriginal('custom_domain'), $this->custom_domain]) as $domain) {
            Cache::forget('tenant-domain:'.$domain);
        }
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
