<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The release this server runs, from `public/release.json`.
 *
 * The file is written by scripts/shared-hosting/build-release.sh and read by
 * scripts/release-status.sh. A development checkout has none, and then both
 * values are null. Read by the operations page and stamped on support
 * requests, so "which release was this?" is never a question.
 */
final class ReleaseStamp
{
    /**
     * @return array{commit: string|null, built_at: string|null}
     */
    public static function read(): array
    {
        $path = public_path('release.json');

        if (! is_file($path) || ! is_readable($path)) {
            return ['commit' => null, 'built_at' => null];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            return ['commit' => null, 'built_at' => null];
        }

        $commit = is_string($data['commit'] ?? null) && preg_match('/^[0-9a-f]{7,40}$/', $data['commit']) === 1 ? $data['commit'] : null;
        $builtAt = is_string($data['built_at'] ?? null) ? $data['built_at'] : null;

        return ['commit' => $commit, 'built_at' => $builtAt];
    }
}
