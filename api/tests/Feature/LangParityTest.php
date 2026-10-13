<?php

declare(strict_types=1);

/**
 * Every backend message exists in Amharic, and is Amharic (2026-10-14).
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

/** @return array<string, array{en: array<string, mixed>, am: array<string, mixed>}> */
function langFiles(): array
{
    $files = [];
    foreach (glob(lang_path('en/*.php')) ?: [] as $path) {
        $name = basename($path, '.php');
        $am = lang_path("am/{$name}.php");
        $files[$name] = [
            'en' => flattenLang(require $path),
            'am' => is_file($am) ? flattenLang(require $am) : [],
        ];
    }

    return $files;
}

it('has every English message in Amharic', function () {
    $missing = [];
    foreach (langFiles() as $name => $pair) {
        foreach (array_keys(array_diff_key($pair['en'], $pair['am'])) as $key) {
            $missing[] = "{$name}.{$key}";
        }
    }

    expect($missing)->toBe([]);
});

it('does not ship English text in the Amharic files', function () {
    $same = [];
    foreach (langFiles() as $name => $pair) {
        foreach ($pair['en'] as $key => $value) {
            $path = "{$name}.{$key}";
            if (($pair['am'][$key] ?? null) === $value && ! in_array($path, LANG_SAME_IN_BOTH, true)) {
                $same[] = $path;
            }
        }
    }

    expect($same)->toBe([]);
});

it('answers a validation error in Amharic', function () {
    $this->withHeader('Accept-Language', 'am')
        ->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', fn (string $message) => str_contains($message, 'ያስፈልጋል'));
});
