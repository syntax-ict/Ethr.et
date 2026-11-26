<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An Ethiopic name written in Latin letters, the way a person would write it
 * on a form: ከበደ is "kebede", ሰብለወንጌል "seblewengel", ተስፋዬ "tesfaye".
 *
 * The syllabary is regular. Each consonant has a row of eight forms, one per
 * vowel order, and the Unicode block lays them out eight code points to a row.
 * So a table of one consonant per row and one vowel per order is enough.
 *
 * It is for comparing, never for display, and it errs the same way a person
 * does: the sixth order (a consonant with no vowel, or with a vowel that is
 * not written) is the bare consonant, and the letters Latin spellings fold
 * together (ሀ ሐ ኀ, ሰ ሠ, ጸ ፀ) come out as one. Whatever is not Ethiopic is
 * returned as it came.
 */
final class EthiopicLatin
{
    /** U+1200, the first row. */
    private const FIRST = 0x1200;

    /** U+1357, the last syllable of the last full row. */
    private const LAST = 0x1357;

    /**
     * The consonant of each row of eight, from U+1200. `~` marks a row whose
     * forms carry a w (ቈ, ኈ, ኰ, ዀ, ጐ, ቘ); `*` a row of the vowel carriers አ and ዐ.
     *
     * @var list<string>
     */
    private const ROWS = [
        'h', 'l', 'h', 'm', 's', 'r', 's', 'sh',
        'k', 'k~', 'k', 'k~', 'b', 'v', 't', 'ch',
        'h', 'h~', 'n', 'gn', '*', 'k', 'k~', 'h',
        'k~', 'w', '*', 'z', 'zh', 'y', 'd', 'd',
        'j', 'g', 'g~', 'g', 't', 'ch', 'p', 'ts',
        'ts', 'f', 'p',
    ];

    /** The vowel of each order, first to eighth, after a plain consonant. */
    private const VOWELS = ['e', 'u', 'i', 'a', 'e', '', 'o', 'wa'];

    /** The vowel of each order of a carrier row: አ is a, እ is e. */
    private const CARRIER = ['a', 'u', 'i', 'a', 'e', 'e', 'o', 'e'];

    public static function isEthiopic(string $text): bool
    {
        return preg_match('/\p{Ethiopic}/u', $text) === 1;
    }

    public static function toLatin(string $text): string
    {
        if (! self::isEthiopic($text)) {
            return $text;
        }

        $out = '';
        $letters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($letters === false ? [] : $letters as $letter) {
            $code = mb_ord($letter, 'UTF-8');

            $out .= $code >= self::FIRST && $code <= self::LAST
                ? self::syllable($code)
                : $letter;
        }

        return $out;
    }

    private static function syllable(int $code): string
    {
        $offset = $code - self::FIRST;
        $row = self::ROWS[intdiv($offset, 8)] ?? null;
        $order = $offset % 8;

        if ($row === null) {
            return '';
        }

        if ($row === '*') {
            return self::CARRIER[$order];
        }

        if (str_ends_with($row, '~')) {
            // A labialised form: ቋ is kwa, ቈ is kwe, ቍ is the bare kw.
            $vowel = $order === 5 ? '' : self::VOWELS[$order];

            return rtrim($row, '~').'w'.($vowel === 'wa' ? 'a' : $vowel);
        }

        return $row.self::VOWELS[$order];
    }
}
