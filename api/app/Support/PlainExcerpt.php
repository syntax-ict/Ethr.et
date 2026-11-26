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
        $plain = trim((string) preg_replace('/[ \t]+/', ' ', self::withoutMarkup($text)));

        return Str::limit($plain, $max);
    }

    /**
     * The same text on one line, for a meta description: every run of
     * whitespace, newlines included, becomes one space, and the cut ends in
     * $end.
     */
    public static function line(string $text, int $max, string $end = '...'): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', self::withoutMarkup($text))), $max, $end);
    }

    /**
     * Removes what is shaped like markup and nothing else: an HTML comment, or
     * a tag (`<`, an optional `/`, a letter, then anything but `<` or `>`,
     * then `>`). A `<` that opens neither, as in "under 2000" written "<2000",
     * stays as the text it is; `strip_tags` dropped everything after it up to
     * the next `>`. Repeated until nothing changes, so removing one tag cannot
     * leave another behind and cleaning cleaned text changes nothing.
     */
    private static function withoutMarkup(string $text): string
    {
        do {
            $before = $text;
            $text = (string) preg_replace(['#<!--.*?-->#su', '#</?[a-zA-Z][^<>]*>#u'], '', $text);
        } while ($text !== $before);

        return $text;
    }
}
