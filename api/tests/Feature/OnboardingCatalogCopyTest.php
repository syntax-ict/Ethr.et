<?php

declare(strict_types=1);

use App\Services\Onboarding\IndustryCatalog;
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
