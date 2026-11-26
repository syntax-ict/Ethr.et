<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A person's full name as two records are compared on it: trimmed, runs of
 * spaces made one, and lower case. "Semira  Aragaw Sisay " and "semira aragaw
 * sisay" are the same name.
 *
 * One rule, used wherever a name is compared whole: the identity resolver's
 * name similarity, the same-name check on a staged list (SameNameRoster) and
 * the possible-duplicates report.
 */
final class PersonName
{
    public static function normalised(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $collapsed = preg_replace('/\s+/', ' ', trim($name)) ?? '';

        return mb_strtolower($collapsed);
    }

    /**
     * The two names are written in the same script and have no word in common.
     *
     * Deliberately not a similarity score. A terminal holds "ABEBE", "Abebe K."
     * or "Abebe 2" for Abebe Kebede Tesfaye, and each of those scores below any
     * useful threshold while plainly being him; what marks a different person
     * is that nothing overlaps at all. An Amharic name against a record that
     * has only a Latin one cannot be compared, and is not called different.
     *
     * One rule for the identity resolver (a match that rests on a device
     * number alone) and for a staged row (a merge into someone it shares no
     * word with).
     *
     * @param  list<string|null>  $known  the names a record holds, in any script
     */
    public static function shareNothing(?string $incoming, array $known): bool
    {
        return self::overlap($incoming, $known) === false;
    }

    /**
     * Whether `$incoming` shares a word with a name of `$known` written in the
     * same script: true when it does, false when every such name shares none,
     * and null when there is nothing to compare (no words, or no name of the
     * same script).
     *
     * @param  list<string|null>  $known
     */
    public static function overlap(?string $incoming, array $known): ?bool
    {
        $words = self::words($incoming);
        if ($words === []) {
            return null;
        }

        $compared = false;

        foreach ($known as $name) {
            $theirs = self::words($name);
            if ($theirs === [] || self::isEthiopic($name) !== self::isEthiopic($incoming)) {
                continue;
            }

            $compared = true;

            foreach ($words as $a) {
                foreach ($theirs as $b) {
                    if (self::sameWord($a, $b)) {
                        return true;
                    }
                }
            }
        }

        return $compared ? false : null;
    }

    /** @return list<string> */
    private static function words(?string $name): array
    {
        if ($name === null) {
            return [];
        }

        $words = preg_split('/[^\p{L}\p{M}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    private static function isEthiopic(?string $text): bool
    {
        return $text !== null && preg_match('/\p{Ethiopic}/u', $text) === 1;
    }

    /**
     * A word as the spellings of Ethiopian names are compared loosely: an
     * Ethiopic word written in Latin letters (EthiopicLatin), then the common
     * variants folded to one form. "Mekonnen", "Mekonen" and "Makonnen" fold
     * to the same word, as do "Seblewongel" and "Seblewengel" once the one
     * letter between them is forgiven by `foldedWordsFit()`.
     *
     * The fold: ph is f, q and ck are k, kh is h, th is t, ou and oo are u, ee
     * is i, a doubled letter is one, and a, e and i are one vowel. It makes
     * "Haile" and "Hayile" closer, and "Ali" and "Ale" the same word; that is
     * the price of a forgiving comparison, so it is for suggestions a person
     * reads, never for anything that assigns.
     */
    public static function fold(string $word): string
    {
        $word = mb_strtolower(EthiopicLatin::toLatin($word));
        $word = strtr($word, ['ph' => 'f', 'ck' => 'k', 'q' => 'k', 'kh' => 'h', 'th' => 't', 'ou' => 'u', 'oo' => 'u', 'ee' => 'i']);
        $word = preg_replace('/(\p{L})\1+/u', '$1', $word) ?? $word;

        return strtr($word, ['a' => 'e', 'i' => 'e']);
    }

    /**
     * Two words already passed through `fold()` fit: equal, one the start of
     * the other, or within the closeness `sameWord()` allows.
     */
    public static function foldedWordsFit(string $a, string $b): bool
    {
        return self::sameWord($a, $b);
    }

    /** Equal, an initial or truncation of the other, or one transliteration apart. */
    private static function sameWord(string $a, string $b): bool
    {
        if ($a === $b || str_starts_with($a, $b) || str_starts_with($b, $a)) {
            return true;
        }

        if (mb_strlen($a) < 4 || mb_strlen($b) < 4) {
            return false;
        }

        // similar_text() scores over bytes, and its score cannot exceed twice
        // the shorter word over the two lengths. A pair too uneven to reach
        // 80% is refused without the cubic search.
        if (min(strlen($a), strlen($b)) * 2 < 0.8 * (strlen($a) + strlen($b))) {
            return false;
        }

        similar_text($a, $b, $percent);

        return $percent >= 80.0;
    }
}
