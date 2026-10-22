<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExitClearanceItem;
use App\Models\Tenant;

/**
 * The lines an exit clearance starts with. An organisation may replace them
 * (`settings.exit_clearance.items`); until it does, these five apply.
 *
 * A built-in line is stored by `key` and shown in the reader's language from
 * `lang/{locale}/exit_clearance.php`. A line the organisation wrote, or a
 * built-in whose wording it changed, is stored as its own `label`.
 */
final class ExitClearanceTemplate
{
    public const MAX_ITEMS = 20;

    /** @var list<array{key: string, owner: string, kind: string}> */
    public const DEFAULTS = [
        ['key' => 'id_card', 'owner' => ExitClearanceItem::OWNER_HR, 'kind' => ExitClearanceItem::KIND_MANUAL],
        ['key' => 'property', 'owner' => ExitClearanceItem::OWNER_HR, 'kind' => ExitClearanceItem::KIND_MANUAL],
        ['key' => 'handover', 'owner' => ExitClearanceItem::OWNER_SUPERVISOR, 'kind' => ExitClearanceItem::KIND_MANUAL],
        ['key' => 'obligations', 'owner' => ExitClearanceItem::OWNER_FINANCE, 'kind' => ExitClearanceItem::KIND_OBLIGATIONS],
        ['key' => 'final_pay', 'owner' => ExitClearanceItem::OWNER_FINANCE, 'kind' => ExitClearanceItem::KIND_MANUAL],
    ];

    /**
     * The organisation's lines, or the defaults.
     *
     * @return list<array{key: string|null, label: string|null, owner: string, kind: string}>
     */
    public static function for(Tenant $tenant): array
    {
        $stored = self::stored($tenant);

        if ($stored === []) {
            return array_map(
                fn (array $line) => ['key' => $line['key'], 'label' => null, 'owner' => $line['owner'], 'kind' => $line['kind']],
                self::DEFAULTS,
            );
        }

        return array_values(array_map(fn (array $line) => [
            'key' => isset($line['key']) && self::isBuiltIn($line['key']) ? $line['key'] : null,
            'label' => $line['label'] ?? null,
            'owner' => $line['owner'],
            'kind' => $line['kind'] ?? ExitClearanceItem::KIND_MANUAL,
        ], $stored));
    }

    public static function isCustomised(Tenant $tenant): bool
    {
        return self::stored($tenant) !== [];
    }

    /**
     * Read through getAttribute(), as NotificationTemplates does: the column is
     * cast to an array, but a value written around the cast must not break an exit.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function stored(Tenant $tenant): array
    {
        $settings = $tenant->getAttribute('settings');
        $stored = is_array($settings) ? ($settings['exit_clearance']['items'] ?? null) : null;

        return is_array($stored) ? array_values(array_filter($stored, fn ($line) => is_array($line) && isset($line['owner']))) : [];
    }

    /**
     * What to store for lines sent from the settings screen. A line keeps its
     * built-in key only while its label is that key's wording in a language
     * the product speaks, so an edited line is never retranslated back.
     *
     * @param  array<int, array{key?: string|null, label: string, owner: string, kind?: string|null}>  $lines
     * @return list<array{key: string|null, label: string|null, owner: string, kind: string}>
     */
    public static function normalise(array $lines): array
    {
        // validated() rebuilds `items.*` in rule order, so a line that sent an
        // optional field can arrive ahead of an earlier one that did not.
        ksort($lines);

        return array_values(array_map(function (array $line): array {
            $key = $line['key'] ?? null;
            $label = trim($line['label']);
            $builtIn = $key !== null && self::isBuiltIn($key)
                && in_array($label, [self::label($key, 'en'), self::label($key, 'am')], true);

            return [
                'key' => $builtIn ? $key : null,
                'label' => $builtIn ? null : $label,
                'owner' => $line['owner'],
                'kind' => $line['kind'] ?? ExitClearanceItem::KIND_MANUAL,
            ];
        }, $lines));
    }

    public static function isBuiltIn(string $key): bool
    {
        return in_array($key, array_column(self::DEFAULTS, 'key'), true);
    }

    /** A line's wording: its own label, or its key's in `$locale` (the app's by default). */
    public static function label(?string $key, ?string $locale = null, ?string $label = null): string
    {
        if ($key !== null && self::isBuiltIn($key)) {
            return __("exit_clearance.items.{$key}", [], $locale);
        }

        return $label ?? '';
    }
}
