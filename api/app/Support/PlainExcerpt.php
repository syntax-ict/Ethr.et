<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one way a story's text is shortened for a card, a list or a feed.
 *
 * Plain text only: whatever markup a body holds never reaches a page, and the
 * public feed, the news list and the in-app announcements list all shorten
 * through this, so the same story reads the same everywhere.
 */
final class PlainExcerpt
{
    public static function of(string $text, int $max): string
    {
        $plain = trim((string) preg_replace('/[ \t]+/', ' ', strip_tags($text)));

        return Str::limit($plain, $max);
    }
}
