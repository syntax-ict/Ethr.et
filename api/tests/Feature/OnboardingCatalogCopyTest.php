<?php

declare(strict_types=1);

use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\LeaveTypeCatalog;
use App\Services\Onboarding\PolicyProfileCatalog;
use App\Services\Onboarding\WorkforceProfileCatalog;

/*
 * The setup wizard shows these catalogues' labels and descriptions as they
 * are, in English and Amharic. The frontend's copy-style gate reads the
 * frontend dictionaries only, so two em dashes in the policy names reached
 * production unseen (QA 2026-11-14, D-2). House style: no em dash, no
 * exclamation mark at the end of a sentence.
 */
it('keeps the wizard catalogues free of em dashes and exclamation marks', function (string $catalogue) {
    $strings = [];
    $entries = app($catalogue)->all();
    array_walk_recursive($entries, function (mixed $value) use (&$strings): void {
        if (is_string($value)) {
            $strings[] = $value;
        }
    });

    expect($strings)->not->toBeEmpty();
    foreach ($strings as $text) {
        expect($text)->not->toContain('—')
            ->and($text)->not->toMatch('/!\s*$/u');
    }
})->with([
    'industries' => IndustryCatalog::class,
    'workforce profiles' => WorkforceProfileCatalog::class,
    'policy profiles' => PolicyProfileCatalog::class,
]);

/*
 * Afaan Oromoo reaches the wizard through `*_om` fields beside every `*_am`
 * one (convention 9). A catalogue entry that gains Amharic text and no Oromo
 * text would show an Afaan Oromoo reader English with nothing to say so.
 * Nothing in the catalogues is exempt: a proper noun that really is the same
 * in both languages would be listed here with its reason, and none is.
 */
it('gives every Amharic catalogue text an Afaan Oromoo one that is not the English', function () {
    $leave = new ReflectionClassConstant(LeaveTypeCatalog::class, 'DEFAULTS');
    $leaveCatalogue = app(LeaveTypeCatalog::class);

    $entries = [];
    $industries = app(IndustryCatalog::class);
    foreach ($industries->keys() as $key) {
        $entries[] = $industries->find($key);
    }
    $workforce = app(WorkforceProfileCatalog::class);
    foreach ($workforce->keys() as $key) {
        $entries[] = $workforce->find($key);
    }
    $policies = app(PolicyProfileCatalog::class);
    foreach ($policies->keys() as $key) {
        $entries[] = $policies->find($key);
    }
    foreach (array_keys($leave->getValue()) as $code) {
        $entries[] = $leaveCatalogue->resolve($code);
    }

    $checked = 0;
    $walk = function (mixed $node, string $path) use (&$walk, &$checked): void {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $field => $value) {
            if (is_string($field) && str_ends_with($field, '_am') && is_string($value) && $value !== '') {
                $base = substr($field, 0, -3);
                $om = $node[$base.'_om'] ?? null;

                expect($om)->toBeString("{$path}.{$base}_om is missing")
                    ->and($om)->not->toBe('', "{$path}.{$base}_om is empty")
                    ->and($om)->not->toBe($node[$base] ?? null, "{$path}.{$base}_om repeats the English")
                    ->and($om)->not->toBe($value, "{$path}.{$base}_om repeats the Amharic");
                $checked++;
            }

            $walk($value, "{$path}.{$field}");
        }
    };

    foreach ($entries as $index => $entry) {
        $walk($entry, (string) ($entry['key'] ?? $entry['code'] ?? $index));
    }

    // Every pair the catalogues carry, so a walk that stopped seeing them cannot pass.
    expect($checked)->toBeGreaterThan(100);
});
