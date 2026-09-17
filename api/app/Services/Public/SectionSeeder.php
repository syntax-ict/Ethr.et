<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\PublicPagePreset;
use App\Enums\PublicSectionKind;
use App\Models\Tenant;
use App\Models\TenantPublicSection;
use Illuminate\Support\Facades\DB;

/**
 * What a tenant's page looks like the moment it opts in.
 *
 * "Loaded by default" is the requirement, and the trap inside it is publishing
 * placeholder text. A seeded `services` block with the heading "Our services"
 * and no services under it is worse than no block at all: it makes a real
 * organisation's public page look abandoned.
 *
 * So seeded sections come in two kinds:
 *
 *  - those whose content **already exists** — hero, about and contact read the
 *    profile the administrator has already filled in, and cta is a link to the
 *    staff sign-in — are created **visible**. A tenant that opts in and does
 *    nothing else still gets a complete, sensible page.
 *  - those that **need new writing** are created **hidden**, with the heading
 *    pre-filled in both languages so the work is obvious and small. The
 *    administrator fills one in and reveals it.
 *
 * Independently of that, the templates decline to render a section with no
 * content at all, so neither an empty block nor a lone heading can reach the
 * public even if a row says it is visible.
 */
final readonly class SectionSeeder
{
    /**
     * The default section set per preset, in order.
     *
     * The differences are the point. A government page leads with notices,
     * services and opening hours, because that is what someone visits a public
     * body's site to find; a hotel leads with a gallery; a university puts
     * about and notices near the top. Marketing copy goes last on a government
     * page and first on a commercial one.
     *
     * @return list<PublicSectionKind>
     */
    public static function defaultsFor(PublicPagePreset $preset): array
    {
        return match ($preset) {
            PublicPagePreset::GOVERNMENT => [
                PublicSectionKind::HERO,
                PublicSectionKind::NOTICES,
                PublicSectionKind::SERVICES,
                PublicSectionKind::ABOUT,
                PublicSectionKind::HOURS,
                PublicSectionKind::LEADERSHIP,
                PublicSectionKind::CONTACT,
            ],
            PublicPagePreset::UNIVERSITY => [
                PublicSectionKind::HERO,
                PublicSectionKind::ABOUT,
                PublicSectionKind::SERVICES,
                PublicSectionKind::NOTICES,
                PublicSectionKind::STATS,
                PublicSectionKind::LEADERSHIP,
                PublicSectionKind::CONTACT,
                PublicSectionKind::CTA,
            ],
            PublicPagePreset::HOSPITAL => [
                PublicSectionKind::HERO,
                PublicSectionKind::SERVICES,
                PublicSectionKind::HOURS,
                PublicSectionKind::ABOUT,
                PublicSectionKind::NOTICES,
                PublicSectionKind::CONTACT,
            ],
            PublicPagePreset::NGO => [
                PublicSectionKind::HERO,
                PublicSectionKind::ABOUT,
                PublicSectionKind::SERVICES,
                PublicSectionKind::STATS,
                PublicSectionKind::GALLERY,
                PublicSectionKind::CONTACT,
                PublicSectionKind::CTA,
            ],
            PublicPagePreset::BANK => [
                PublicSectionKind::HERO,
                PublicSectionKind::SERVICES,
                PublicSectionKind::ABOUT,
                PublicSectionKind::STATS,
                PublicSectionKind::HOURS,
                PublicSectionKind::FAQ,
                PublicSectionKind::CONTACT,
            ],
            PublicPagePreset::MANUFACTURING => [
                PublicSectionKind::HERO,
                PublicSectionKind::ABOUT,
                PublicSectionKind::SERVICES,
                PublicSectionKind::STATS,
                PublicSectionKind::GALLERY,
                PublicSectionKind::CONTACT,
                PublicSectionKind::CTA,
            ],
            PublicPagePreset::HOTEL => [
                PublicSectionKind::HERO,
                PublicSectionKind::GALLERY,
                PublicSectionKind::SERVICES,
                PublicSectionKind::ABOUT,
                PublicSectionKind::FAQ,
                PublicSectionKind::CONTACT,
                PublicSectionKind::CTA,
            ],
            PublicPagePreset::GENERAL => [
                PublicSectionKind::HERO,
                PublicSectionKind::ABOUT,
                PublicSectionKind::SERVICES,
                PublicSectionKind::CONTACT,
                PublicSectionKind::CTA,
            ],
        };
    }

    /**
     * Create the default sections for a tenant that has just opted in.
     *
     * Idempotent by omission: a kind the tenant already has is skipped, so
     * switching preset adds what the new layout needs without duplicating what
     * is already there or discarding anything written.
     *
     * @return int the number of sections created
     */
    public function seed(Tenant $tenant, PublicPagePreset $preset): int
    {
        $existing = TenantPublicSection::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('kind')
            ->map(static fn ($kind) => $kind instanceof PublicSectionKind ? $kind->value : (string) $kind)
            ->all();

        $created = 0;

        DB::transaction(function () use ($tenant, $preset, $existing, &$created): void {
            $position = (int) TenantPublicSection::query()->where('tenant_id', $tenant->id)->max('position');

            foreach (self::defaultsFor($preset) as $kind) {
                if (in_array($kind->value, $existing, true)) {
                    continue;
                }

                TenantPublicSection::create([
                    'tenant_id' => $tenant->id,
                    'kind' => $kind,
                    'position' => ++$position,
                    // The rule that keeps placeholder text off the internet:
                    // a block whose content already exists is visible, a block
                    // that needs writing waits for someone to write it.
                    'is_visible' => ! $kind->hasItems(),
                    'heading' => __('public.sections.'.$kind->value.'.heading', [], 'en'),
                    'heading_am' => __('public.sections.'.$kind->value.'.heading', [], 'am'),
                ]);

                $created++;
            }
        });

        return $created;
    }
}
