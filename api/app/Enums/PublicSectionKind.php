<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of block a tenant may put on its public landing page.
 *
 * A fixed catalogue, not free-form blocks. Every kind here has a Blade partial
 * that knows how to render it safely, translated headings in both locales, and
 * a place in at least one preset's defaults — `SectionKindWiringTest` asserts
 * all three for every case, because the failure mode of adding a kind and
 * forgetting one is a blank section in production.
 *
 * What is deliberately absent is as important as what is here. There is no
 * `documents` kind: government bodies genuinely want to publish directives and
 * forms, and that is precisely why it is not bolted on — it means anonymous
 * file downloads, a new MIME class and a malware-distribution surface, which is
 * its own change with its own decisions. There is no `form` kind either, for
 * the same reason in reverse: it would be the first anonymous write endpoint on
 * the public surface.
 *
 * Every kind holds text an administrator typed. **No kind reads an HR table.**
 * Not `announcements`, not `employees`, not a headcount from a query. That is
 * the whole security posture of this feature, and `stats` is where the
 * temptation is strongest.
 */
enum PublicSectionKind: string
{
    /** The page's opening block. Reads the profile; stores nothing of its own. */
    case HERO = 'hero';

    /** Free prose about the organisation. Reads the profile's description. */
    case ABOUT = 'about';

    /** What the organisation does or offers, as repeatable cards. */
    case SERVICES = 'services';

    /** Figures the organisation chooses to publish. Typed, never queried. */
    case STATS = 'stats';

    /** Public notices and announcements — typed here, never read from the
     * `announcements` table, which is entirely internal HR content. */
    case NOTICES = 'notices';

    /**
     * News and updates the organisation publishes about itself.
     *
     * Distinct from NOTICES, and the distinction is editorial rather than
     * technical — worth stating because "we already have notices" is the first
     * thing a reviewer will think.
     *
     * A notice is a statement of record: dated, text-first, read by someone who
     * came looking for it. A relocation, a tender, a consultation period. It
     * belongs in a list, newest first, and a photograph would cheapen it.
     *
     * News is the opposite errand: read by someone who arrived for another
     * reason and stayed. A graduation, a new wing, a partnership. It is
     * image-led, carries an excerpt, and links out to the full story.
     *
     * The same table, the same caps and the same escaping. Only the partial and
     * the reading differ — which is exactly the kind of thing a section kind is
     * for, and exactly why it is not a `layout` variant of NOTICES: a tenant
     * publishing both should not have to choose.
     */
    case NEWS = 'news';

    /** Named office-holders an organisation publishes deliberately. */
    case LEADERSHIP = 'leadership';

    /** Photographs of premises or work. */
    case GALLERY = 'gallery';

    /** Questions the public actually asks. */
    case FAQ = 'faq';

    /** Opening hours, structured so they can also feed the JSON-LD. */
    case HOURS = 'hours';

    /** Phone, email and address. Reads the profile; stores nothing of its own. */
    case CONTACT = 'contact';

    /** The closing call to action. */
    case CTA = 'cta';

    /**
     * Whether this kind holds repeatable child items.
     *
     * The singular kinds are rendered from the section row and the profile
     * alone; the plural ones own rows in `tenant_public_items`. The settings
     * screen uses this to decide whether to offer an item editor at all.
     */
    public function hasItems(): bool
    {
        return match ($this) {
            self::SERVICES, self::STATS, self::NOTICES, self::NEWS,
            self::LEADERSHIP, self::GALLERY, self::FAQ, self::HOURS => true,
            self::HERO, self::ABOUT, self::CONTACT, self::CTA => false,
        };
    }

    /**
     * Whether this kind draws its content from the profile rather than storing
     * its own.
     *
     * One source of truth per field: `tenant_public_profiles` owns identity and
     * contact details, sections own the page body. A hero section that kept its
     * own copy of the headline would mean two places to edit it and one of them
     * would go stale.
     */
    public function readsProfile(): bool
    {
        return match ($this) {
            self::HERO, self::ABOUT, self::CONTACT => true,
            default => false,
        };
    }

    /**
     * Whether an item of this kind may carry an uploaded image.
     *
     * Restricted rather than universal: every image is a request an anonymous
     * visitor makes and a file this platform stores, so the kinds that gain
     * nothing from one do not get to have one.
     */
    public function allowsItemImages(): bool
    {
        return match ($this) {
            self::GALLERY, self::LEADERSHIP, self::SERVICES, self::NEWS => true,
            default => false,
        };
    }
}
