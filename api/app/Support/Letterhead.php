<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Services\FileStorageService;
use Throwable;

/**
 * What the top of every printed document says about the organisation, built
 * once for all of them: payslip, employment letter, statutory schedule and
 * report export.
 *
 * Everything is optional and a line is left out when it is empty, never printed
 * as a dash. The contact details are `settings.letterhead_*` on the tenant row;
 * the logo is the one the organisation uploaded, embedded as a data URI because
 * dompdf cannot reach the storage disk by address. The tenant is the one in
 * hand, so one organisation's letterhead cannot carry another's details.
 */
final class Letterhead
{
    public const SETTING_KEYS = ['letterhead_address', 'letterhead_phone', 'letterhead_email', 'letterhead_po_box'];

    /** Largest logo embedded, in bytes: a payslip is generated for every employee in a run. */
    public const MAX_LOGO_BYTES = 1_048_576;

    /**
     * @return array{name: string, logo: string|null, lines: list<string>}
     */
    public static function for(?Tenant $tenant, string $locale, bool $withTin = true, bool $withLogo = true): array
    {
        if ($tenant === null) {
            return ['name' => 'ETHR', 'logo' => null, 'lines' => []];
        }

        $label = static fn (string $key): string => (string) __('letterhead.'.$key, [], $locale);
        $setting = static fn (string $key): ?string => self::setting($tenant, $key);

        $lines = [];
        foreach ([
            $setting('letterhead_address'),
            self::labelled($label('po_box'), $setting('letterhead_po_box')),
            self::labelled($label('phone'), $setting('letterhead_phone')),
            self::labelled($label('email'), $setting('letterhead_email')),
            $withTin ? self::labelled($label('tin'), $setting('employer_tin')) : null,
        ] as $line) {
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return [
            'name' => (string) $tenant->name,
            'logo' => $withLogo ? self::logo($tenant) : null,
            'lines' => $lines,
        ];
    }

    /** A trimmed string from the tenant's settings, or null when unset or blank. */
    private static function setting(Tenant $tenant, string $key): ?string
    {
        $settings = $tenant->getAttribute('settings');
        $value = is_array($settings) ? ($settings[$key] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function labelled(string $label, ?string $value): ?string
    {
        return $value === null ? null : $label.': '.$value;
    }

    /**
     * The logo as a data URI, or null when there is none, it cannot be read, it
     * is not a PNG or JPEG (dompdf draws SVG poorly), or it is over the cap.
     */
    private static function logo(Tenant $tenant): ?string
    {
        $path = $tenant->logo_storage_path;
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $bytes = app(FileStorageService::class)->read($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_LOGO_BYTES) {
            return null;
        }

        $mime = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            default => null,
        };

        return $mime === null ? null : 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
