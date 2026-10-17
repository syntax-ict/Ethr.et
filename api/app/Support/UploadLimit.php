<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The largest file this server will actually accept in one upload, in bytes.
 *
 * PHP refuses an upload above `upload_max_filesize`, and discards a whole
 * request above `post_max_size`, before the application sees either, so the
 * application's own limits (5 MB for a photo, 10 MB for a document) mean
 * nothing above them. Measured on the production host 2026-10-17: 2M and 8M,
 * the stock values, with the shipped `.user.ini` not applied. The app reports
 * the real figure so a form can refuse an oversize file with a clear message
 * instead of failing at the server.
 */
final class UploadLimit
{
    /** Room for the other form fields that travel with the file. */
    private const FORM_OVERHEAD = 64 * 1024;

    public static function bytes(): int
    {
        $upload = self::parse((string) ini_get('upload_max_filesize'));
        $post = self::parse((string) ini_get('post_max_size'));
        $post = $post === null ? null : max(0, $post - self::FORM_OVERHEAD);

        $limits = array_filter([$upload, $post], static fn (?int $v): bool => $v !== null);

        // Neither set (or both "unlimited"): report a generous figure rather
        // than zero, so no form refuses everything.
        return $limits === [] ? 1024 * 1024 * 1024 : min($limits);
    }

    /**
     * A php.ini size ("2M", "512K", "1G", "8388608") in bytes; null for
     * unlimited ("0" or "-1") or unreadable.
     */
    public static function parse(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^(-?\d+)\s*([KMG]?)$/i', $value, $m)) {
            return null;
        }
        $number = (int) $m[1];
        if ($number <= 0) {
            return null;
        }

        return match (strtoupper($m[2])) {
            'K' => $number * 1024,
            'M' => $number * 1024 * 1024,
            'G' => $number * 1024 * 1024 * 1024,
            default => $number,
        };
    }
}
