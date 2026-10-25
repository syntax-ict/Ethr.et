<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A 64-bit difference hash (dHash) of an image: the same photo, recompressed,
 * resized or lightly brightened, hashes within a few bits of itself, while two
 * different photos differ in about half of them. Used to catch a selfie that
 * is a replay of an earlier one, or a photo of a colleague's photo.
 *
 * It describes the picture's light and shade, not a face: it cannot identify
 * anyone, and it is deleted with the photo it came from.
 */
final class ImageHash
{
    /** Two hashes this close (out of 64 bits) are the same picture. */
    public const SAME_PICTURE_BITS = 6;

    /** The hash as 16 hex digits, or null for bytes that are not an image. */
    public static function dHash(string $binary): ?string
    {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        // 9 x 8 greyscale: each row's 8 left-right comparisons are 8 bits.
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
        imagedestroy($source);

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= self::luma($small, $x, $y) < self::luma($small, $x + 1, $y) ? '1' : '0';
            }
        }
        imagedestroy($small);

        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex((int) bindec($nibble));
        }

        return $hex;
    }

    /** Differing bits between two hashes (0 = identical, 64 = opposite). */
    public static function distance(string $a, string $b): int
    {
        $distance = 0;
        foreach (str_split($a) as $i => $digit) {
            $distance += substr_count(decbin(hexdec($digit) ^ hexdec($b[$i] ?? '0')), '1');
        }

        return $distance;
    }

    private static function luma(\GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }
}
