<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The layout a tenant's public landing page is rendered with.
 *
 * These are not a new taxonomy. Each case is one of the eight
 * `OrganizationTemplate` slugs that onboarding already maintains, and
 * `IndustryCatalog` already aliases its twenty-seven industries onto exactly
 * this set through its `base` key. A ministry and a woreda administration both
 * resolve to `GOVERNMENT` because the catalog already says they share a base;
 * inventing a ninth preset here would mean two vocabularies for one idea.
 *
 * A preset decides default sections, structured-data type and skin. It never
 * decides colour: the tenant's own `theme` still reaches the page through the
 * `--tenant-*` custom properties on <body>.
 */
enum PublicPagePreset: string
{
    case GOVERNMENT = 'government';
    case UNIVERSITY = 'university';
    case HOSPITAL = 'hospital';
    case NGO = 'ngo';
    case BANK = 'bank';
    case MANUFACTURING = 'manufacturing';
    case HOTEL = 'hotel';
    case GENERAL = 'general';

    /**
     * The schema.org type a page of this kind should declare.
     *
     * This is the single most valuable piece of organisation-awareness in the
     * feature: a search engine that knows `habru.ethr.et` is a
     * `GovernmentOrganization` rather than a generic `Organization` can present
     * it as one. `Organization` is the correct parent for the two cases that
     * have no more specific type, not a fallback for a missing branch.
     *
     * @see https://schema.org/Organization
     */
    public function schemaType(): string
    {
        return match ($this) {
            self::GOVERNMENT => 'GovernmentOrganization',
            self::UNIVERSITY => 'CollegeOrUniversity',
            self::HOSPITAL => 'Hospital',
            self::NGO => 'NGO',
            self::BANK => 'BankOrCreditUnion',
            self::HOTEL => 'Hotel',
            self::MANUFACTURING, self::GENERAL => 'Organization',
        };
    }

    /**
     * The CSS class the layout puts on <body> to skin this preset.
     *
     * Every value here must have a matching `.preset--*` rule in
     * `public/assets/ethr-public.css`, or the page renders unstyled with no
     * error anywhere. `PresetWiringTest` asserts that, because a missing skin
     * is invisible in every other check.
     */
    public function bodyClass(): string
    {
        return 'preset--'.$this->value;
    }

    /**
     * Whether selecting this preset requires platform verification.
     *
     * Only government does. See App\Rules\SelectablePreset for why a
     * self-declared industry is not enough.
     */
    public function requiresVerification(): bool
    {
        return $this === self::GOVERNMENT;
    }

    /**
     * The preset for a stored value that no longer maps to a case.
     *
     * A column can hold a value this enum has lost — a rollback, a hand-edited
     * row, a preset renamed in a later migration. On an anonymous page the only
     * acceptable outcome is a page, so an unknown value degrades to GENERAL
     * rather than throwing a 500 at a visitor.
     */
    public static function fromStorage(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value) ?? self::GENERAL;
    }
}
