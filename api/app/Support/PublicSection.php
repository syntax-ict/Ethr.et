<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PublicSectionKind;
use App\Models\Tenant;
use App\Models\TenantPublicSection;

/**
 * One block of the public page, as the template is allowed to see it.
 *
 * Holds its kind, its own headings and its items — and nothing that would let a
 * partial walk back to a model. The kind stays an enum rather than a string so
 * the layout can `match` on it without a stringly-typed comparison that would
 * silently stop matching if a value were renamed.
 */
final class PublicSection
{
    /** @param list<PublicSectionItem> $items */
    private function __construct(
        public readonly PublicSectionKind $kind,
        public readonly string $heading,
        public readonly string $intro,
        public readonly ?string $layout,
        public readonly array $items,
    ) {}

    public static function from(Tenant $tenant, TenantPublicSection $section, string $locale): self
    {
        $items = [];

        foreach ($section->items as $item) {
            $view = PublicSectionItem::from($tenant, $item, $locale);

            // An item with nothing in it is a gap in a grid. The builder lets
            // an administrator save a half-filled row while they work, so the
            // page declines to render it rather than assuming it is finished.
            if (! $view->hasContent()) {
                continue;
            }

            // A gallery entry without a picture is not an entry. The gallery
            // partial renders images and captions, so a titled entry with no
            // image contributes nothing — and a section of them renders as a
            // heading and an introduction over empty space, which is the exact
            // "looks abandoned" outcome the empty-section rule exists to stop.
            // Found in the browser: the tests counted items, and the page
            // counted pictures.
            if ($section->kind === PublicSectionKind::GALLERY && $view->imageUrl === null) {
                continue;
            }

            $items[] = $view;
        }

        return new self(
            kind: $section->kind,
            heading: self::localised($section->heading, $section->heading_am, $locale)
                // Falls back to the translated default for the kind, so a
                // section whose heading was cleared shows a sensible title
                // rather than an empty <h2>.
                ?: (string) __('public.sections.'.$section->kind->value.'.heading'),
            intro: self::localised($section->intro, $section->intro_am, $locale),
            layout: $section->layout,
            items: $items,
        );
    }

    private static function localised(?string $base, ?string $amharic, string $locale): string
    {
        if (str_starts_with($locale, 'am') && filled($amharic)) {
            return (string) $amharic;
        }

        return (string) ($base ?? '');
    }

    /**
     * The intro split into paragraphs, the same way every other prose field on
     * this page is split.
     *
     * @return list<string>
     */
    public function introParagraphs(): array
    {
        if ($this->intro === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\R{2,}/', trim($this->intro)) ?: [],
            static fn (string $p): bool => trim($p) !== '',
        ));
    }

    /**
     * Whether this block should appear at all.
     *
     * Visibility is a separate question the query already answered; this is
     * about content. A section marked visible whose items were all deleted
     * would otherwise render as a heading over nothing, which reads as broken
     * rather than unfinished — and on a page seeded with default sections that
     * is the difference between "loaded by default" and "looks abandoned".
     *
     * The kinds that read the profile answer for themselves: the template asks
     * the page object whether there is a description or a contact detail, so
     * they are never empty by surprise.
     */
    public function hasContent(): bool
    {
        if ($this->kind->readsProfile()) {
            return true;
        }

        if ($this->kind->hasItems()) {
            return $this->items !== [];
        }

        return $this->intro !== '';
    }

    /** A CSS modifier for the chosen layout variant, or the kind's default. */
    public function layoutClass(): string
    {
        return 'section--'.($this->layout ?? 'grid');
    }
}
