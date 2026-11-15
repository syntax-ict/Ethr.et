<?php

declare(strict_types=1);

namespace App\Services\Device;

/**
 * What a kind of terminal takes as a person's number, and how long a name it
 * keeps. Only what the repository already establishes is written down here:
 * a type with no line says nothing, and the general badge rule applies
 * (StoreEmployeeRequest: at most 32 characters, letters, digits and hyphens).
 *
 * No database and no configuration: the rules are facts about the vendors'
 * firmware, and a wrong one would refuse people a terminal would have taken.
 */
final class DeviceIdRules
{
    /** The general badge rule, as StoreEmployeeRequest and EmployeeImportRow state it. */
    private const BADGE = '/^[A-Za-z0-9-]{1,32}$/D';

    /** Terminals keep short names unless a type says otherwise (the cut DeviceCommandQueue always made). */
    private const DEFAULT_NAME_LENGTH = 32;

    /**
     * Person numbers ETHR may write, by adapter type.
     *
     * zkteco and zkteco_adms: digits only, as docs/DEVICE_INTEGRATION.md says
     * of ZKTeco terminals. zkteco_adms may REPORT ids of up to 64 of
     * [A-Za-z0-9_-] (AdmsProtocol), which is what it may send, not what ETHR
     * may write.
     *
     * @var array<string, array{pattern: string, reason: string}>
     */
    private const NUMBER = [
        'zkteco' => ['pattern' => '/^[0-9]+$/D', 'reason' => 'digits_only'],
        'zkteco_adms' => ['pattern' => '/^[0-9]+$/D', 'reason' => 'digits_only'],
    ];

    /**
     * Name lengths by adapter type. hikvision: 32 characters, the cut
     * DeviceCommandQueue::upsert made before this class existed.
     *
     * @var array<string, int>
     */
    private const NAME = [
        'hikvision' => 32,
    ];

    /**
     * The adapter types that have a rule of their own for the person number.
     *
     * @return list<string>
     */
    public static function restrictedTypes(): array
    {
        return array_keys(self::NUMBER);
    }

    /** Whether this type has a rule of its own for the person number. */
    public static function restricts(string $adapterType): bool
    {
        return isset(self::NUMBER[$adapterType]);
    }

    /** Whether a terminal of this type takes the id as a person number. */
    public static function accepts(string $adapterType, string $id): bool
    {
        return self::reason($adapterType, $id) === null;
    }

    /**
     * Why a terminal of this type does not take the id, as a short code, or
     * null when it does. A type with no rule of its own is held to the
     * general badge rule.
     */
    public static function reason(string $adapterType, string $id): ?string
    {
        $rule = self::NUMBER[$adapterType] ?? null;

        if ($rule !== null) {
            return preg_match($rule['pattern'], $id) === 1 ? null : $rule['reason'];
        }

        return preg_match(self::BADGE, $id) === 1 ? null : 'badge_rule';
    }

    /** How many characters of a name a terminal of this type keeps. */
    public static function nameLength(string $adapterType): int
    {
        return self::NAME[$adapterType] ?? self::DEFAULT_NAME_LENGTH;
    }
}
