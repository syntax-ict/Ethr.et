<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Database\Factories\TenantPublicProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant's public landing page content.
 *
 * The @property block is not decoration: `casts()` is invisible to static
 * analysis, so without it `published_at` reads as a string and
 * `->toIso8601String()` on it is an error. Same for `$tenant`, which resolves
 * to a bare Model through the un-generic BelongsTo and loses `isActive()`.
 *
 * @property bool $is_published
 * @property bool $is_indexable
 * @property array<string, string>|null $social_links
 * @property Carbon|null $published_at
 * @property-read Tenant|null $tenant
 *
 * Carries `BelongsToTenant` for the same reason every other scoped model does,
 * and the consequence is worth stating because this is the one model an
 * unauthenticated visitor can reach: with no tenant resolved the global scope
 * applies `whereRaw('0 = 1')`, so a request that fails to name a tenant reads
 * *nothing* rather than the first row it finds. The public route's 404 for an
 * unknown host is therefore produced by the same mechanism that protects
 * payroll, not by a separate check that could be forgotten.
 *
 * Nothing on this model is private. That is the invariant: if a field would
 * ever need hiding from a visitor, it does not belong in this table.
 */
class TenantPublicProfile extends Model
{
    /** @use HasFactory<TenantPublicProfileFactory> */
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    /** Social platforms a tenant may link to, and the host each link must be on. */
    public const SOCIAL_PLATFORMS = [
        'facebook' => ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.com'],
        'instagram' => ['instagram.com', 'www.instagram.com'],
        'linkedin' => ['linkedin.com', 'www.linkedin.com'],
        'x' => ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'],
        'youtube' => ['youtube.com', 'www.youtube.com', 'youtu.be'],
        'telegram' => ['t.me', 'telegram.me'],
        'tiktok' => ['tiktok.com', 'www.tiktok.com'],
    ];

    protected $fillable = [
        'public_id',
        'tenant_id',
        'is_published',
        'is_indexable',
        'preset',
        'headline',
        'description',
        'hero_image_path',
        'contact_email',
        'contact_phone',
        'address_line',
        'city',
        'region',
        'website_url',
        'social_links',
        'meta_description',
        'published_at',
        // `suspended_at` is deliberately absent. It is the platform's takedown
        // switch, written only by the admin.manage surface, and a tenant
        // request must never be able to clear its own suspension by including
        // the field in a settings payload.
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_indexable' => 'boolean',
            'social_links' => 'array',
            'published_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Whether this profile may be served to an anonymous visitor.
     *
     * Asks the tenant as well as the profile. A suspended or expired tenant
     * stops answering at `/` for the same reason it stops answering the API —
     * `ResolveTenant` already refuses it upstream, and this is the second half
     * of that so a caller holding a profile directly cannot skip the check.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->is_published
            && $this->suspended_at === null
            && (bool) $this->tenant?->isActive();
    }

    /**
     * Whether the platform has taken this page down.
     *
     * Separate from `is_published` on purpose: a takedown must not destroy the
     * tenant's own publication state, so restoring is one column write rather
     * than a guess about what they had wanted. The public route treats a
     * suspended page exactly like an unpublished one — same 404, same body —
     * so suspension does not become a new way to probe which tenants exist.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}
