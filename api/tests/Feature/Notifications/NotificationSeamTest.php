<?php

declare(strict_types=1);

/**
 * One seam for notifications: a notification either honours the per-user
 * preference matrix through `RespectsNotificationPreferences`, or is named in
 * `always-delivered-notifications.php` with the reason it is not muteable; the
 * broadcast rule is read in one place.
 *
 * Tokenised, not text-matched, for the reason `ControllerValidationConventionTest`
 * gives: a comment that mentions a name must not count as a use of it.
 */
function seamPhpFiles(string $dir): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

/**
 * Concrete notification classes under app/Notifications, keyed by class basename:
 * whether each uses the preference trait, and whether its via() calls filterChannels.
 *
 * @return array<string, array{trait: bool, filters: bool}>
 */
function seamNotificationClasses(): array
{
    $found = [];

    foreach (seamPhpFiles(base_path('app/Notifications')) as $path) {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $count = count($tokens);

        $name = null;
        $extendsNotification = false;
        $classAt = null;

        for ($i = 0; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS && ($tokens[$i - 1][0] ?? null) !== T_DOUBLE_COLON) {
                $isAbstract = is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_ABSTRACT;
                $name = $tokens[$i + 1][1];
                $classAt = $i;

                for ($j = $i; $j < $count && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_EXTENDS) {
                        $extendsNotification = is_array($tokens[$j + 1]) && $tokens[$j + 1][1] === 'Notification';
                    }
                }

                if ($isAbstract) {
                    $name = null;
                }

                break;
            }
        }

        if ($name === null || ! $extendsNotification) {
            continue;
        }

        $usesTrait = false;
        $filters = false;
        $depth = 0;

        for ($i = $classAt; $i < $count; $i++) {
            $t = $tokens[$i];

            if ($t === '{') {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            }

            // A trait is a `use` at class depth 1, not an import above the class.
            if ($depth === 1 && is_array($t) && $t[0] === T_USE) {
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][1] === 'RespectsNotificationPreferences') {
                        $usesTrait = true;
                    }
                }
            }

            if (is_array($t) && $t[0] === T_FUNCTION && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][1] === 'via') {
                $bodyDepth = 0;
                for ($j = $i; $j < $count; $j++) {
                    if ($tokens[$j] === '{') {
                        $bodyDepth++;
                    } elseif ($tokens[$j] === '}') {
                        $bodyDepth--;
                        if ($bodyDepth === 0) {
                            break;
                        }
                    } elseif (is_array($tokens[$j]) && $tokens[$j][1] === 'filterChannels') {
                        $filters = true;
                    }
                }
            }
        }

        $found[$name] = ['trait' => $usesTrait, 'filters' => $filters];
    }

    ksort($found);

    return $found;
}

/** Files in app/ that contain the string token 'broadcasting.default', relative to app/. */
function seamBroadcastConfigReaders(): array
{
    $readers = [];
    $root = str_replace('\\', '/', base_path('app')).'/';

    foreach (seamPhpFiles(base_path('app')) as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && trim($t[1], '\'"') === 'broadcasting.default') {
                $readers[] = str_replace($root, '', $path);
                break;
            }
        }
    }

    return $readers;
}

it('scans the whole notification directory', function () {
    $classes = seamNotificationClasses();

    expect(count($classes))->toBeGreaterThan(40)
        ->and($classes)->toHaveKey('LeaveRequestedNotification');
});

it('files every notification under preferences or under the always-delivered list, never both', function () {
    $classes = seamNotificationClasses();
    $always = require __DIR__.'/always-delivered-notifications.php';

    $problems = [];

    foreach ($classes as $name => $info) {
        $listed = array_key_exists($name, $always);

        if ($info['trait'] && $listed) {
            $problems[] = "$name uses RespectsNotificationPreferences and is also listed as always delivered";
        } elseif (! $info['trait'] && ! $listed) {
            $problems[] = "$name neither uses RespectsNotificationPreferences nor is listed in always-delivered-notifications.php";
        }
    }

    foreach (array_keys($always) as $name) {
        if (! array_key_exists($name, $classes)) {
            $problems[] = "always-delivered-notifications.php names $name, which is not a notification class";
        }
    }

    expect($problems)->toBe([]);
});

it('calls filterChannels in via() of every notification that uses the preference trait', function () {
    $missing = array_keys(array_filter(
        seamNotificationClasses(),
        fn (array $info) => $info['trait'] && ! $info['filters'],
    ));

    expect($missing)->toBe([]);
});

it('reads broadcasting.default only where it must', function () {
    $pinned = [
        'Services/Admin/OperationsStatusService.php' => 'reports the configured connection on the operations page',
        'Services/Admin/SystemHealthService.php' => 'reports the configured connection in the health check',
        'Support/LiveUpdates.php' => 'the one broadcast predicate',
    ];

    expect(seamBroadcastConfigReaders())->toBe(array_keys($pinned));
});
