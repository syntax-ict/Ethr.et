<?php

declare(strict_types=1);

use App\Support\Locales;

/**
 * Every backend message exists in Amharic and in Oromo, and is written in that
 * language (Amharic 2026-10-14, Oromo 2026-11-02).
 *
 * `am/validation.php` held 10 of 146 keys, so every framework message — "is
 * required", "must be a date" — reached an Amharic reader in English through
 * the fallback locale, and 20 more lines across six files were English text
 * copied into the `am` file. A key-count check passes on both; this compares
 * values. The few lines that are rightly identical are formats, listed here
 * with the reason.
 */
const LANG_SAME_IN_BOTH = [
    'approval.leave_summary', // ":type: :from - :to" — a format
    'approval.profile_update_summary', // ":field: :old → :new" — a format
    'validation.custom.attribute-name.rule-name', // Laravel's own example stub
];

/** Every translation the product ships besides English (`Locales::SHIPPED`). */
const LANG_TRANSLATIONS = ['am', 'om'];

function flattenLang(array $lines, string $prefix = ''): array
{
    $out = [];
    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        if (is_array($value)) {
            $out += flattenLang($value, $path);
        } else {
            $out[$path] = $value;
        }
    }

    return $out;
}

/** @return array<string, array{en: array<string, mixed>, translated: array<string, mixed>}> */
function langFiles(string $locale): array
{
    $files = [];
    foreach (glob(lang_path('en/*.php')) ?: [] as $path) {
        $name = basename($path, '.php');
        $translated = lang_path("{$locale}/{$name}.php");
        $files[$name] = [
            'en' => flattenLang(require $path),
            'translated' => is_file($translated) ? flattenLang(require $translated) : [],
        ];
    }

    return $files;
}

it('ships every translation the product offers', function () {
    expect(array_values(array_diff(Locales::SHIPPED, ['en'])))->toBe(LANG_TRANSLATIONS);
});

it('has every English message in each translation', function (string $locale) {
    $missing = [];
    foreach (langFiles($locale) as $name => $pair) {
        foreach (array_keys(array_diff_key($pair['en'], $pair['translated'])) as $key) {
            $missing[] = "{$name}.{$key}";
        }
    }

    expect($missing)->toBe([]);
})->with(LANG_TRANSLATIONS);

it('has no message in a translation that English lacks', function (string $locale) {
    $extra = [];
    foreach (langFiles($locale) as $name => $pair) {
        foreach (array_keys(array_diff_key($pair['translated'], $pair['en'])) as $key) {
            $extra[] = "{$name}.{$key}";
        }
    }

    expect($extra)->toBe([]);
})->with(LANG_TRANSLATIONS);

it('keeps every :placeholder of the English message', function (string $locale) {
    // The set of names, not their count: a plural line repeats `:count` in
    // each form, and a language with one form (Amharic) writes it once.
    $names = static function (string $line): array {
        preg_match_all('/:[a-z_]+/i', $line, $m);
        $unique = array_values(array_unique($m[0]));
        sort($unique);

        return $unique;
    };

    $dropped = [];
    foreach (langFiles($locale) as $name => $pair) {
        foreach ($pair['en'] as $key => $value) {
            $translated = $pair['translated'][$key] ?? null;
            if (is_string($value) && is_string($translated) && $names($value) !== $names($translated)) {
                $dropped[] = "{$name}.{$key}";
            }
        }
    }

    expect($dropped)->toBe([]);
})->with(LANG_TRANSLATIONS);

it('does not ship English text in a translation', function (string $locale) {
    $same = [];
    foreach (langFiles($locale) as $name => $pair) {
        foreach ($pair['en'] as $key => $value) {
            $path = "{$name}.{$key}";
            if (($pair['translated'][$key] ?? null) === $value && ! in_array($path, LANG_SAME_IN_BOTH, true)) {
                $same[] = $path;
            }
        }
    }

    expect($same)->toBe([]);
})->with(LANG_TRANSLATIONS);

it('answers a validation error in Amharic', function () {
    $this->withHeader('Accept-Language', 'am')
        ->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', fn (string $message) => str_contains($message, 'ያስፈልጋል'));
});

it('answers a validation error in Oromo', function () {
    $this->withHeader('Accept-Language', 'om')
        ->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', fn (string $message) => str_contains($message, 'barbaachisaadha'));
});
