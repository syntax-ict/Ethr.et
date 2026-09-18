<?php

declare(strict_types=1);

/**
 * English and Amharic must carry the same keys.
 *
 * Convention 9 says both ship, and `scripts/i18n-check.js` enforces exactly
 * that — for `src/`. It does not read `api/lang/`, so the backend's translation
 * files have no automated drift protection at all. Blade strings on the public
 * page are the first user-facing backend text where a missing key is visible to
 * someone outside the organisation: Laravel renders `public.contact_heading`
 * literally rather than failing, so the page would simply display the key and
 * nothing would go red.
 *
 * Scoped to the files this feature owns. Widening it to every file under
 * api/lang/ would be a larger and probably worthwhile change, but it would mix
 * a pre-existing debt into this one's diff.
 */
function flattenLangKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $full = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $keys = array_merge($keys, flattenLangKeys($value, $full));

            continue;
        }

        $keys[] = $full;
    }

    sort($keys);

    return $keys;
}

it('ships the same public page keys in English and Amharic', function () {
    $en = flattenLangKeys(require lang_path('en/public.php'));
    $am = flattenLangKeys(require lang_path('am/public.php'));

    expect($am)->toBe($en, implode("\n", [
        'lang/en/public.php and lang/am/public.php have drifted.',
        'Missing from am: '.implode(', ', array_diff($en, $am)),
        'Missing from en: '.implode(', ', array_diff($am, $en)),
    ]));
});

it('leaves no public page string untranslated', function () {
    $am = require lang_path('am/public.php');
    $en = require lang_path('en/public.php');

    $untranslated = [];

    foreach (flattenLangKeys($en) as $key) {
        $english = data_get($en, $key);
        $amharic = data_get($am, $key);

        if (! is_string($amharic) || trim($amharic) === '') {
            $untranslated[] = $key;

            continue;
        }

        // A value identical to the English is usually a copy-paste placeholder.
        // Brand and platform names are the honest exception — "ETHR" and
        // "Facebook" are not translated in Amharic either — so only flag a
        // match that contains a space, which real prose always does.
        if ($amharic === $english && str_contains(trim($english), ' ')) {
            $untranslated[] = $key;
        }
    }

    expect($untranslated)->toBe([],
        'These public page strings are missing an Amharic translation: '
        .implode(', ', $untranslated)
    );
});

it('keeps placeholders consistent between the two languages', function () {
    $en = require lang_path('en/public.php');
    $am = require lang_path('am/public.php');

    $mismatched = [];

    foreach (flattenLangKeys($en) as $key) {
        $english = (string) data_get($en, $key);
        $amharic = (string) data_get($am, $key);

        preg_match_all('/:([a-z_]+)/', $english, $enPlaceholders);
        preg_match_all('/:([a-z_]+)/', $amharic, $amPlaceholders);

        sort($enPlaceholders[1]);
        sort($amPlaceholders[1]);

        // A `:organization` that survives in English and is dropped in Amharic
        // renders a heading with a hole in it, and only for Amharic readers —
        // the kind of defect that ships because nobody on the team reads the
        // page in the language most of its users will.
        if ($enPlaceholders[1] !== $amPlaceholders[1]) {
            $mismatched[] = $key;
        }
    }

    expect($mismatched)->toBe([],
        'Placeholder drift between en and am for: '.implode(', ', $mismatched)
    );
});
