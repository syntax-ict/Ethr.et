<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Database\Factories\TenantPublicItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry inside a section — a service, a notice, a named office-holder.
 *
 * Scoped by tenant directly rather than only through its section. That is the
 * point of the redundant `tenant_id`: an item is resolvable by ULID from an
 * anonymous image route, and the query behind that route has to fail closed on
 * its own rather than inheriting safety from a join someone might later change.
 *
 * `image_path` never reaches a template. The public page asks for
 * `/media/section/{public_id}` and the controller streams the bytes, so a
 * storage path is not something an anonymous visitor can see or guess at.
 *
 * @property int $position
 * @property array<string, mixed>|null $meta
 * @property-read Tenant|null $tenant
 * @property-read TenantPublicSection|null $section
 */
class TenantPublicItem extends Model
{
    /** @use HasFactory<TenantPublicItemFactory> */
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'section_id',
        'position',
        'title',
        'title_am',
        'body',
        'body_am',
        'image_path',
        'image_alt',
        'image_alt_am',
        'icon',
        'link_url',
        'link_label',
        'link_label_am',
        'meta',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'section_id',
        // The storage path is an implementation detail of where this platform
        // keeps files. It is never API-facing and never page-facing; presence
        // is exposed instead, the same way the profile exposes hasLogo.
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<TenantPublicSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(TenantPublicSection::class, 'section_id');
    }

    public function hasImage(): bool
    {
        return filled($this->image_path);
    }

    /**
     * Whether this item would render as anything.
     *
     * An item with no title, body or image is a gap in a grid. The builder
     * lets an administrator save a partially filled row while they work, so
     * the page has to decline to render it rather than assume it is complete.
     */
    public function hasRenderableContent(): bool
    {
        return filled($this->title)
            || filled($this->body)
            || $this->hasImage();
    }
}
