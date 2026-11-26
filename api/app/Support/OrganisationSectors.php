<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\OrganizationType;
use App\Services\Onboarding\IndustryCatalog;

/**
 * Which kinds of organisation in law a sector plausibly is.
 *
 * Two answers describe an organisation and they are independent columns: the
 * industry (`tenants.type`, an IndustryCatalog key or one of its bases) and
 * the kind in law (`tenants.organization_type`, a row of `organization_types`).
 * The mapping between them is not a function: a bank may be a private limited
 * company or a public enterprise, a university may be government or private.
 * So this is a suggestion with an escape, never a filter that makes a kind
 * unreachable, and the server records an unusual pair for review rather than
 * refusing it.
 *
 * The map is by group (`OrganizationType::GROUPS`), not by kind, so adding a
 * kind to a group needs no change here. A test pins its keys to
 * IndustryCatalog::bases() and its values to OrganizationType::GROUPS.
 */
final class OrganisationSectors
{
    /**
     * @var array<string, list<string>> base => the groups of kind it may be
     */
    public const GROUPS_BY_BASE = [
        'government' => ['public'],
        'ngo' => ['non_profit', 'international'],
        'university' => ['public', 'private', 'non_profit'],
        'hospital' => ['private', 'public', 'non_profit'],
        'bank' => ['private', 'public'],
        'manufacturing' => ['private', 'public'],
        'hotel' => ['private', 'public'],
        'general' => ['private', 'non_profit', 'public', 'international'],
    ];

    public function __construct(private readonly IndustryCatalog $industries) {}

    /**
     * The base an industry answer stands for: an industry key resolves to the
     * base it carries, a base to itself, anything else (or nothing) to null.
     */
    public function baseOf(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $industry = $this->industries->find($type);
        $base = $industry['base'] ?? $type;

        return isset(self::GROUPS_BY_BASE[$base]) ? $base : null;
    }

    /**
     * The groups of kind the sector suggests; every group when the answer is
     * missing or unknown, so a surprise never narrows anything.
     *
     * @return list<string>
     */
    public function groupsFor(?string $type): array
    {
        $base = $this->baseOf($type);

        return $base === null ? OrganizationType::GROUPS : self::GROUPS_BY_BASE[$base];
    }

    /**
     * The bases for which a group of kind is suggested: the `suggested_for`
     * of a kind in `GET /organization-types`.
     *
     * @return list<string>
     */
    public function basesSuggesting(string $group): array
    {
        $bases = [];

        foreach (self::GROUPS_BY_BASE as $base => $groups) {
            if (in_array($group, $groups, true)) {
                $bases[] = $base;
            }
        }

        return $bases;
    }

    /**
     * Whether a kind in law is among those the sector suggests. True when
     * either answer is missing or unknown: this flags a pair that is odd, not
     * one that is incomplete.
     */
    public function isPlausible(?string $type, ?string $kind): bool
    {
        if ($this->baseOf($type) === null || $kind === null || $kind === '') {
            return true;
        }

        $group = OrganizationType::query()->where('code', $kind)->value('group_code');

        if (! is_string($group) || $group === '') {
            return true;
        }

        return in_array($group, $this->groupsFor($type), true);
    }

    /**
     * The kind in law a client that did not ask for one is given, from the
     * industry: the civil service, a society, or a private employer. Left for
     * the administrator to confirm in Settings.
     */
    public function defaultKindFor(?string $type): string
    {
        return match ($this->baseOf($type)) {
            'government' => 'government',
            'ngo' => 'ngo',
            default => 'private',
        };
    }
}
