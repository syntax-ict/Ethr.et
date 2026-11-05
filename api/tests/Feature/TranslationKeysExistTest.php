<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;

// A-Z review E-05 (2026-11-04). `leave.min_notice`, `leave.max_consecutive`
// and `validation.custom.bank_account_number.digits` were called from the code
// but written in no language file, so people read the key itself. Every
// literal key the application passes to __() must exist in each language it
// ships. A key built at run time (one ending in `.` or `_` here) is not checked.

it('has every message the application asks for in English, Amharic and Oromo', function () {
    $missing = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/__\\('([a-z_]+\\.[a-z0-9_.]*[a-z0-9])'/", (string) file_get_contents($file->getPathname()), $matches);

        foreach (array_unique($matches[1]) as $key) {
            foreach (['en', 'am', 'om'] as $locale) {
                if (! Lang::has($key, $locale, false)) {
                    $missing[] = "{$locale}: {$key}";
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
