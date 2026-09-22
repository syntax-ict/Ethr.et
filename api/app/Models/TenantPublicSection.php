<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublicSectionKind;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Database\Factories\TenantPublicSectionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One block on a tenant's public landing page.
 *
 * Reachable by anonymous traffic, so `BelongsToTenant` matters here for the
 * same reason it does on the profile: with no tenant resolved the global scope
 * applies `whereRaw('0 = 1')` and this reads nothing rather than another
 * tenant's sections.
 *
 * Nothing on this model is private. Every column holds text an administrator
 * typed in order to publish it. If a field would ever need hiding from a
 * visitor, it does not belong in this table.
 *
 * @property PublicSectionKind $kind
 * @property bool $is_visible
 * @property int $position
 * @property array<string, mixed>|null $options
 * @property-read Tenant|null $tenant
 * @property-read Collection<int, TenantPublicItem> $items
 */
class TenantPublicSection extends Model
{
    /** @use HasFactory<TenantPublicSectionFactory> */
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'kind',
        'position',
        'is_visible',
        'heading',
        'heading_am',
        'intro',
        'intro_am',
        'layout',
        'options',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PublicSectionKind::class,
            'is_visible' => 'boolean',
            'position' => 'integer',
            'options' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<TenantPublicItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TenantPublicItem::class, 'section_id')->orderBy('position');
    }

    /**
     * Whether this section has anything worth putting on the page.
     *
     * A section that is visible but empty renders as a heading over nothing,
     * which looks broken rather than unfinished. Kinds that read the profile
     * answer for themselves in their partial — this covers the ones that own
     * their content.
     */
    public function hasRenderableContent(): bool
    {
        if ($this->kind->readsProfile()) {
            return true;
        }

        if ($this->kind->hasItems()) {
            return $this->items->isNotEmpty();
        }

        return filled($this->heading) || filled($this->intro);
    }
}
