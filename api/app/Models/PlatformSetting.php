<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Singleton row of platform-wide settings, managed by the super admin.
 *
 * Global by design — no tenant scope. See the migration for why, and
 * TenantIsolationTest::GLOBAL_MODELS for the allow-list entry that makes it
 * a reviewed decision.
 */
class PlatformSetting extends Model
{
    use HasPublicId;

    protected $fillable = [
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'payment_instructions',
        'payment_instructions_am',
        'platform_name',
        'platform_name_am',
        'tagline',
        'tagline_am',
        'logo_url',
        'contact_email',
        'contact_phone',
        'office_address',
        'office_address_am',
        'social_linkedin',
        'social_x',
        'social_facebook',
        'metric_organisations',
        'metric_employees',
        'metric_uptime_note',
        'metric_uptime_note_am',
    ];

    protected $hidden = [
        'id',
    ];

    /**
     * Cache key for the public site content.
     *
     * The first cache on this model, so it also sets the pattern. A fixed key
     * and an integer-second TTL, invalidated from the model's own `saved` hook
     * rather than from each writer — matching Tenant.php, and for the same
     * reason: any code path that writes this row invalidates it, including a
     * tinker session or a future admin screen nobody has written yet.
     *
     * Never Cache::tags(): Redis was removed for the shared-hosting target, and
     * the file and database stores do not support tagging.
     */
    public const PUBLIC_CACHE_KEY = 'platform.site_content';

    /** Seconds. Long enough to matter on a marketing page, short enough that an
     * operator editing contact details does not file a bug about it. */
    public const PUBLIC_CACHE_TTL = 300;

    protected static function booted(): void
    {
        // Covers update(), save(), firstOrCreate() and anything else that
        // persists the row. Forgetting rather than re-warming: the next public
        // request pays for one query, which is cheaper than being wrong.
        static::saved(function (): void {
            Cache::forget(self::PUBLIC_CACHE_KEY);
        });
    }

    /**
     * The one row, created on first read so callers never handle a null.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /**
     * True once an operator has filled in enough for a tenant to actually pay.
     *
     * The billing page uses this to distinguish "not configured yet" from a
     * configured account — showing half-empty payment fields on the screen that
     * tells customers where to send money is worse than showing none.
     */
    public function hasPaymentDetails(): bool
    {
        return filled($this->bank_name)
            && filled($this->bank_account_number)
            && filled($this->bank_account_name);
    }

    /**
     * True once an operator has published a headline figure.
     *
     * The landing page states "500+ organisations", "50,000+ employees" and
     * "99.9% uptime" today, and none of it can be substantiated — there is no
     * production deployment. These columns start null so the metrics band
     * renders nothing at all rather than a number somebody invented, and this
     * is what the page asks before rendering it.
     */
    public function hasPublishedMetrics(): bool
    {
        return $this->metric_organisations !== null
            || $this->metric_employees !== null
            || filled($this->metric_uptime_note);
    }
}
